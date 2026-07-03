<?php
/**
 * Migration execution engine.
 *
 * Processes approved execution plans in batches, creating WordPress
 * users and KonX affiliate profiles. Requires all safety gates to
 * pass before execution begins. Records every action in the
 * migration log with full rollback metadata.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Migration_Executor
 */
class Konx_Migration_Executor {

	/**
	 * Migration lock transient name.
	 */
	const LOCK_TRANSIENT = 'konx_migration_lock';

	/**
	 * Lock duration in seconds (30 minutes).
	 */
	const LOCK_DURATION = 1800;

	// ------------------------------------------------------------------
	// Safety Gate Validation
	// ------------------------------------------------------------------

	/**
	 * Validate all safety gates before execution.
	 *
	 * @param array $state Migration state.
	 * @return true|WP_Error True if all gates pass, WP_Error otherwise.
	 */
	public static function validate_gates( $state ) {
		// 1. User permission.
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			return new \WP_Error( 'unauthorized', __( 'Insufficient permissions.', 'konx-affiliate-dashboard' ) );
		}

		// 2. Preflight passed.
		if ( empty( $state['preflight']['passed'] ) ) {
			return new \WP_Error( 'preflight_failed', __( 'Preflight checks have not passed.', 'konx-affiliate-dashboard' ) );
		}

		// 3. Backup created and verified.
		if ( empty( $state['backup']['verified'] ) ) {
			return new \WP_Error( 'no_backup', __( 'Backup has not been created or verified.', 'konx-affiliate-dashboard' ) );
		}

		// 4. Execution verification passed.
		if ( empty( $state['execution_verification']['can_execute'] ) ) {
			return new \WP_Error( 'verification_failed', __( 'Execution verification has not passed.', 'konx-affiliate-dashboard' ) );
		}

		// 5. Approved.
		if ( empty( $state['approved'] ) ) {
			return new \WP_Error( 'not_approved', __( 'Migration plan has not been approved.', 'konx-affiliate-dashboard' ) );
		}

		// 6. Decisions exist.
		if ( empty( $state['decision_matrix']['decisions'] ) ) {
			return new \WP_Error( 'no_decisions', __( 'No decision matrix found.', 'konx-affiliate-dashboard' ) );
		}

		// 7. No concurrent lock.
		$lock = get_transient( self::LOCK_TRANSIENT );
		if ( $lock ) {
			return new \WP_Error( 'locked', sprintf(
				__( 'Migration lock active (session: %s).', 'konx-affiliate-dashboard' ),
				$lock
			) );
		}

		return true;
	}

	// ------------------------------------------------------------------
	// Lock Management
	// ------------------------------------------------------------------

	/**
	 * Acquire the migration lock.
	 *
	 * @param string $session_id Migration session ID.
	 * @return bool True if lock acquired, false if already locked.
	 */
	public static function acquire_lock( $session_id ) {
		$existing = get_transient( self::LOCK_TRANSIENT );
		if ( $existing ) {
			return false;
		}
		return set_transient( self::LOCK_TRANSIENT, $session_id, self::LOCK_DURATION );
	}

	/**
	 * Release the migration lock.
	 *
	 * @return bool True on success.
	 */
	public static function release_lock() {
		return delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Refresh the lock timer (heartbeat).
	 *
	 * @param string $session_id Session that holds the lock.
	 * @return bool True if refreshed.
	 */
	public static function refresh_lock( $session_id ) {
		$current = get_transient( self::LOCK_TRANSIENT );
		if ( $current !== $session_id ) {
			return false;
		}
		return set_transient( self::LOCK_TRANSIENT, $session_id, self::LOCK_DURATION );
	}

	// ------------------------------------------------------------------
	// Batch Execution
	// ------------------------------------------------------------------

	/**
	 * Execute a single batch of migration records.
	 *
	 * Processes records from the execution plan within a database
	 * transaction. On failure, rolls back the batch. Records every
	 * action in wp_konx_migration_log.
	 *
	 * @param string $session_id  Migration session ID.
	 * @param array  $batch_items Array of plan items to process.
	 * @param array  $state       Migration state (for sponsor resolution).
	 * @return array {
	 *     @type int   $processed Number of records processed.
	 *     @type int   $succeeded Number of successful actions.
	 *     @type int   $failed    Number of failed actions.
	 *     @type int   $skipped   Number of skipped records.
	 *     @type array $results   Per-record results.
	 *     @type bool  $rolled_back Whether the batch was rolled back.
	 * }
	 */
	public static function execute_batch( $session_id, $batch_items, $state ) {
		global $wpdb;

		// Suppress new user notification emails for entire batch.
		add_filter( 'wp_send_new_user_notification_to_user', '__return_false' );
		add_filter( 'wp_send_new_user_notification_to_admin', '__return_false' );

		$results   = array();
		$succeeded = 0;
		$failed    = 0;
		$skipped   = 0;

		// Start transaction.
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		$batch_ok = true;

		foreach ( $batch_items as $item ) {
			$action = $item['planned_action'];

			// Skip non-actionable items.
			if ( in_array( $action, array( 'skip', 'invalid' ), true ) ) {
				$skipped++;
				self::log_action( $session_id, $item, 'skipped', null, null, implode( '; ', $item['reasons'] ) );
				$results[] = array(
					'po10_id' => $item['po10_id'],
					'email'   => $item['email'],
					'action'  => $action,
					'status'  => 'skipped',
					'reason'  => implode( '; ', $item['reasons'] ),
				);
				continue;
			}

			// Execute the action.
			$result = self::execute_item( $item, $state );

			if ( $result['success'] ) {
				$succeeded++;
				self::log_action(
					$session_id, $item, 'completed',
					$result['user_id'], $result['affiliate_id'], null
				);
			} else {
				$failed++;
				$batch_ok = false;
				self::log_action(
					$session_id, $item, 'failed',
					null, null, $result['error']
				);
			}

			$results[] = $result;
		}

		// Commit or rollback.
		if ( $batch_ok ) {
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		} else {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}

		// Restore email notifications.
		remove_filter( 'wp_send_new_user_notification_to_user', '__return_false' );
		remove_filter( 'wp_send_new_user_notification_to_admin', '__return_false' );

		// Refresh lock heartbeat.
		self::refresh_lock( $session_id );

		return array(
			'processed'   => count( $batch_items ),
			'succeeded'   => $succeeded,
			'failed'      => $failed,
			'skipped'     => $skipped,
			'results'     => $results,
			'rolled_back' => ! $batch_ok,
		);
	}

	// ------------------------------------------------------------------
	// Per-Item Execution
	// ------------------------------------------------------------------

	/**
	 * Execute a single plan item.
	 *
	 * @param array $item  Plan item.
	 * @param array $state Migration state.
	 * @return array { success, action, po10_id, email, user_id, affiliate_id, error }.
	 */
	private static function execute_item( $item, $state ) {
		$action = $item['planned_action'];
		$email  = sanitize_email( $item['email'] );
		$result = array(
			'success'      => false,
			'action'       => $action,
			'po10_id'      => $item['po10_id'],
			'email'        => $email,
			'user_id'      => null,
			'affiliate_id' => null,
			'error'        => null,
		);

		switch ( $action ) {
			case 'create':
				return self::action_create( $item, $state );

			case 'link_wp':
				return self::action_link_wp( $item, $state );

			case 'link_ca':
				return self::action_link_ca( $item, $state );

			default:
				$result['error'] = sprintf( __( 'Unknown action: %s', 'konx-affiliate-dashboard' ), $action );
				return $result;
		}
	}

	/**
	 * Create action: new WP user + new KonX affiliate.
	 *
	 * @param array $item  Plan item.
	 * @param array $state Migration state.
	 * @return array Result.
	 */
	private static function action_create( $item, $state ) {
		$email      = sanitize_email( $item['email'] );
		$team_name  = sanitize_text_field( $item['team_name'] );
		$po10_id    = $item['po10_id'];

		$result = array(
			'success'      => false,
			'action'       => 'create',
			'po10_id'      => $po10_id,
			'email'        => $email,
			'user_id'      => null,
			'affiliate_id' => null,
			'error'        => null,
		);

		// Safety: check email doesn't already have a KonX affiliate.
		$existing_wp = get_user_by( 'email', $email );
		if ( $existing_wp ) {
			$existing_aff = Konx_Affiliate_Manager::get_affiliate_by_user( $existing_wp->ID );
			if ( $existing_aff ) {
				$result['error'] = sprintf( __( 'Email %s already has affiliate #%d.', 'konx-affiliate-dashboard' ), $email, $existing_aff->id );
				return $result;
			}
		}

		// Safety: check referral code.
		if ( ! empty( $team_name ) && Konx_Affiliate_Manager::get_affiliate_by_referral_code( $team_name ) ) {
			$result['error'] = sprintf( __( 'Referral code "%s" already in use.', 'konx-affiliate-dashboard' ), $team_name );
			return $result;
		}

		// Create WP user (or use existing one).
		if ( $existing_wp ) {
			$user_id = $existing_wp->ID;
		} else {
			$user_id = self::create_wp_user( $item );
			if ( is_wp_error( $user_id ) ) {
				$result['error'] = $user_id->get_error_message();
				return $result;
			}
		}

		// Create KonX affiliate.
		$affiliate_id = self::create_affiliate( $user_id, $item, $state );
		if ( is_wp_error( $affiliate_id ) ) {
			$result['error'] = $affiliate_id->get_error_message();
			return $result;
		}

		$result['success']      = true;
		$result['user_id']      = $user_id;
		$result['affiliate_id'] = $affiliate_id;
		return $result;
	}

	/**
	 * Link WP action: create KonX affiliate for existing WP user.
	 *
	 * @param array $item  Plan item.
	 * @param array $state Migration state.
	 * @return array Result.
	 */
	private static function action_link_wp( $item, $state ) {
		$email = sanitize_email( $item['email'] );

		$result = array(
			'success'      => false,
			'action'       => 'link_wp',
			'po10_id'      => $item['po10_id'],
			'email'        => $email,
			'user_id'      => null,
			'affiliate_id' => null,
			'error'        => null,
		);

		$wp_user = get_user_by( 'email', $email );
		if ( ! $wp_user ) {
			$result['error'] = sprintf( __( 'WP user not found for %s.', 'konx-affiliate-dashboard' ), $email );
			return $result;
		}

		// Do not overwrite existing affiliate.
		$existing = Konx_Affiliate_Manager::get_affiliate_by_user( $wp_user->ID );
		if ( $existing ) {
			$result['error'] = sprintf( __( 'WP user #%d already has affiliate #%d.', 'konx-affiliate-dashboard' ), $wp_user->ID, $existing->id );
			return $result;
		}

		$affiliate_id = self::create_affiliate( $wp_user->ID, $item, $state );
		if ( is_wp_error( $affiliate_id ) ) {
			$result['error'] = $affiliate_id->get_error_message();
			return $result;
		}

		$result['success']      = true;
		$result['user_id']      = $wp_user->ID;
		$result['affiliate_id'] = $affiliate_id;
		return $result;
	}

	/**
	 * Link CA action: create KonX affiliate for existing Coupon Affiliate user.
	 *
	 * @param array $item  Plan item.
	 * @param array $state Migration state.
	 * @return array Result.
	 */
	private static function action_link_ca( $item, $state ) {
		$email = sanitize_email( $item['email'] );

		$result = array(
			'success'      => false,
			'action'       => 'link_ca',
			'po10_id'      => $item['po10_id'],
			'email'        => $email,
			'user_id'      => null,
			'affiliate_id' => null,
			'error'        => null,
		);

		$wp_user = get_user_by( 'email', $email );
		if ( ! $wp_user ) {
			$result['error'] = sprintf( __( 'WP user not found for %s.', 'konx-affiliate-dashboard' ), $email );
			return $result;
		}

		// Do not overwrite existing KonX affiliate.
		$existing = Konx_Affiliate_Manager::get_affiliate_by_user( $wp_user->ID );
		if ( $existing ) {
			$result['error'] = sprintf( __( 'WP user #%d already has affiliate #%d.', 'konx-affiliate-dashboard' ), $wp_user->ID, $existing->id );
			return $result;
		}

		$affiliate_id = self::create_affiliate( $wp_user->ID, $item, $state );
		if ( is_wp_error( $affiliate_id ) ) {
			$result['error'] = $affiliate_id->get_error_message();
			return $result;
		}

		$result['success']      = true;
		$result['user_id']      = $wp_user->ID;
		$result['affiliate_id'] = $affiliate_id;
		return $result;
	}

	// ------------------------------------------------------------------
	// Record Creation Helpers
	// ------------------------------------------------------------------

	/**
	 * Create a WordPress user for migration.
	 *
	 * Suppresses emails, generates safe login, uses random password.
	 * Does NOT modify existing user passwords.
	 *
	 * @param array $item Plan item with email, team_name, etc.
	 * @return int|WP_Error User ID or error.
	 */
	private static function create_wp_user( $item ) {
		$email = sanitize_email( $item['email'] );

		// Generate safe login from email prefix.
		$login_base = sanitize_user( strstr( $email, '@', true ), true );
		if ( empty( $login_base ) ) {
			$login_base = 'user';
		}

		$login  = $login_base;
		$suffix = 1;
		while ( username_exists( $login ) ) {
			$login = $login_base . $suffix;
			$suffix++;
			if ( $suffix > 999 ) {
				$login = $login_base . wp_rand( 1000, 99999 );
				break;
			}
		}

		// Always generate a random password for migrated users.
		$password = wp_generate_password( 16, true, true );

		$user_id = wp_create_user( $login, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Set first/last name from source data if available via migration engine.
		$first_name = '';
		$last_name  = '';

		// The plan item may carry names from the engine's build_record().
		if ( ! empty( $item['first_name'] ) ) {
			$first_name = sanitize_text_field( $item['first_name'] );
		}
		if ( ! empty( $item['last_name'] ) ) {
			$last_name = sanitize_text_field( $item['last_name'] );
		}

		if ( $first_name || $last_name ) {
			wp_update_user( array(
				'ID'           => $user_id,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim( $first_name . ' ' . $last_name ),
			) );
		}

		// Store migration source meta.
		update_user_meta( $user_id, 'konx_source', 'migration' );
		update_user_meta( $user_id, 'konx_migrated_po10_id', $item['po10_id'] );

		return $user_id;
	}

	/**
	 * Create a KonX affiliate profile for a migrated user.
	 *
	 * @param int   $user_id WordPress user ID.
	 * @param array $item    Plan item.
	 * @param array $state   Migration state (for sponsor resolution).
	 * @return int|WP_Error Affiliate ID or error.
	 */
	private static function create_affiliate( $user_id, $item, $state ) {
		$team_name = sanitize_text_field( $item['team_name'] );

		// Determine affiliate type from the plan item or engine default.
		$affiliate_type = 'sales_agent';
		if ( ! empty( $item['affiliate_type'] ) ) {
			$affiliate_type = $item['affiliate_type'];
		}

		$args = array(
			'external_id' => 'po10_' . $item['po10_id'],
		);

		if ( ! empty( $team_name ) ) {
			$args['referral_code'] = $team_name;
		}

		if ( ! empty( $item['phone'] ) ) {
			$args['phone'] = sanitize_text_field( $item['phone'] );
		}

		// Resolve parent affiliate.
		$parent_id = self::resolve_parent( $item, $state );
		if ( $parent_id ) {
			$args['parent_affiliate_id'] = $parent_id;
		}

		$args['notes'] = sprintf( 'Migrated from PO10 (ID: %d) on %s', $item['po10_id'], current_time( 'mysql', true ) );

		return Konx_Affiliate_Manager::create_affiliate_profile( $user_id, $affiliate_type, $args );
	}

	/**
	 * Resolve parent affiliate from sponsor data.
	 *
	 * @param array $item  Plan item.
	 * @param array $state Migration state.
	 * @return int|null Parent affiliate ID or null.
	 */
	private static function resolve_parent( $item, $state ) {
		$sponsor_status = isset( $item['sponsor_status'] ) ? $item['sponsor_status'] : 'none';

		if ( 'none' === $sponsor_status || 'orphan' === $sponsor_status ) {
			return null;
		}

		// For 'resolved' status, the sponsor's team_name is in the migration set.
		// Look up by referral_code in KonX (may have been created in an earlier batch).
		if ( ! empty( $item['sponsor_team_name'] ) ) {
			$parent = Konx_Affiliate_Manager::get_affiliate_by_referral_code( $item['sponsor_team_name'] );
			if ( $parent ) {
				return (int) $parent->id;
			}
		}

		// Try the decision's sponsor field.
		if ( ! empty( $item['sponsor'] ) ) {
			$parent = Konx_Affiliate_Manager::get_affiliate_by_referral_code( $item['sponsor'] );
			if ( $parent ) {
				return (int) $parent->id;
			}
		}

		// Check manual resolutions.
		if ( ! empty( $state['sponsor_resolutions'] ) ) {
			$sponsor_tn = isset( $item['sponsor'] ) ? strtolower( trim( $item['sponsor'] ) ) : '';
			if ( '' !== $sponsor_tn && isset( $state['sponsor_resolutions'][ $sponsor_tn ] ) ) {
				$resolution = $state['sponsor_resolutions'][ $sponsor_tn ];
				if ( 'ignore' !== $resolution ) {
					$parent = Konx_Affiliate_Manager::get_affiliate_by_referral_code( $resolution );
					if ( $parent ) {
						return (int) $parent->id;
					}
				}
			}
		}

		return null;
	}

	// ------------------------------------------------------------------
	// Migration Log
	// ------------------------------------------------------------------

	/**
	 * Record an action in the migration log.
	 *
	 * @param string   $session_id    Migration session ID.
	 * @param array    $item          Plan item.
	 * @param string   $state         Execution state (completed, failed, skipped).
	 * @param int|null $user_id       Created WP user ID.
	 * @param int|null $affiliate_id  Created affiliate ID.
	 * @param string|null $error      Error message.
	 */
	private static function log_action( $session_id, $item, $state, $user_id, $affiliate_id, $error ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_migration_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'migration_id'        => $session_id,
				'external_id'         => 'po10_' . $item['po10_id'],
				'email'               => sanitize_email( $item['email'] ),
				'planned_action'      => $item['planned_action'],
				'execution_state'     => $state,
				'created_user_id'     => $user_id ? absint( $user_id ) : null,
				'created_affiliate_id' => $affiliate_id ? absint( $affiliate_id ) : null,
				'rollback_action'     => isset( $item['rollback_action'] ) ? $item['rollback_action'] : null,
				'error_message'       => $error ? sanitize_text_field( mb_substr( $error, 0, 500 ) ) : null,
				'created_at'          => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}
}
