<?php
/**
 * Migration rollback engine.
 *
 * Reverses a completed or failed migration session by reading the
 * migration log and undoing each action in reverse order. Created
 * affiliates are deleted; created WordPress users are deleted;
 * linked users have migration meta removed but accounts preserved.
 *
 * Does not touch WooCommerce orders or Coupon Affiliates data.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Migration_Rollback
 */
class Konx_Migration_Rollback {

	/**
	 * Rollback lock transient name.
	 */
	const LOCK_TRANSIENT = 'konx_rollback_lock';

	/**
	 * Lock duration in seconds (30 minutes).
	 */
	const LOCK_DURATION = 1800;

	/**
	 * Session statuses that allow rollback.
	 *
	 * @var array
	 */
	private static $rollbackable_statuses = array( 'completed', 'failed' );

	// ------------------------------------------------------------------
	// Safety Gates
	// ------------------------------------------------------------------

	/**
	 * Validate all safety gates before rollback.
	 *
	 * @param string $session_id Migration session ID.
	 * @return true|WP_Error True if all gates pass.
	 */
	public static function validate_gates( $session_id ) {
		// 1. Permission.
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			return new \WP_Error( 'unauthorized', __( 'Insufficient permissions.', 'konx-affiliate-dashboard' ) );
		}

		// 2. Session exists.
		$session = Konx_Migration_Session::get( $session_id );
		if ( ! $session ) {
			return new \WP_Error( 'no_session', __( 'Migration session not found.', 'konx-affiliate-dashboard' ) );
		}

		// 3. Session is in a rollbackable state.
		if ( ! in_array( $session->status, self::$rollbackable_statuses, true ) ) {
			return new \WP_Error( 'invalid_status', sprintf(
				__( 'Session status "%s" cannot be rolled back. Must be completed or failed.', 'konx-affiliate-dashboard' ),
				$session->status
			) );
		}

		// 4. No concurrent rollback lock.
		$lock = get_transient( self::LOCK_TRANSIENT );
		if ( $lock ) {
			return new \WP_Error( 'locked', sprintf(
				__( 'Rollback lock active (session: %s).', 'konx-affiliate-dashboard' ),
				$lock
			) );
		}

		// 5. Migration log entries exist.
		$log_count = self::get_log_count( $session_id );
		if ( 0 === $log_count ) {
			return new \WP_Error( 'no_logs', __( 'No migration log entries found for this session.', 'konx-affiliate-dashboard' ) );
		}

		return true;
	}

	// ------------------------------------------------------------------
	// Lock Management
	// ------------------------------------------------------------------

	/**
	 * Acquire the rollback lock.
	 *
	 * @param string $session_id Session ID.
	 * @return bool True if acquired.
	 */
	public static function acquire_lock( $session_id ) {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}
		return set_transient( self::LOCK_TRANSIENT, $session_id, self::LOCK_DURATION );
	}

	/**
	 * Release the rollback lock.
	 *
	 * @return bool True on success.
	 */
	public static function release_lock() {
		return delete_transient( self::LOCK_TRANSIENT );
	}

	// ------------------------------------------------------------------
	// Dry-Run Preview
	// ------------------------------------------------------------------

	/**
	 * Preview what a rollback would do without making changes.
	 *
	 * @param string $session_id Migration session ID.
	 * @return array|WP_Error Rollback preview or error.
	 */
	public static function preview( $session_id ) {
		$session = Konx_Migration_Session::get( $session_id );
		if ( ! $session ) {
			return new \WP_Error( 'no_session', __( 'Session not found.', 'konx-affiliate-dashboard' ) );
		}

		$log_entries = self::get_completed_log_entries( $session_id );

		$preview = array(
			'session_id'         => $session_id,
			'session_status'     => $session->status,
			'total_log_entries'  => count( $log_entries ),
			'affiliates_to_delete' => 0,
			'users_to_delete'    => 0,
			'users_to_clean'     => 0,
			'entries_to_skip'    => 0,
			'actions'            => array(),
		);

		foreach ( $log_entries as $entry ) {
			$action = self::plan_rollback_action( $entry );
			$preview['actions'][] = $action;

			switch ( $action['rollback_type'] ) {
				case 'delete_user_and_affiliate':
					$preview['affiliates_to_delete']++;
					$preview['users_to_delete']++;
					break;
				case 'delete_affiliate_only':
					$preview['affiliates_to_delete']++;
					$preview['users_to_clean']++;
					break;
				case 'skip':
					$preview['entries_to_skip']++;
					break;
			}
		}

		return $preview;
	}

	/**
	 * Plan the rollback action for a single log entry.
	 *
	 * @param object $entry Migration log row.
	 * @return array Planned rollback action.
	 */
	private static function plan_rollback_action( $entry ) {
		$result = array(
			'log_id'         => (int) $entry->id,
			'external_id'    => $entry->external_id,
			'email'          => $entry->email,
			'planned_action' => $entry->planned_action,
			'rollback_type'  => 'skip',
			'description'    => '',
			'user_id'        => $entry->created_user_id ? (int) $entry->created_user_id : null,
			'affiliate_id'   => $entry->created_affiliate_id ? (int) $entry->created_affiliate_id : null,
		);

		// Only roll back completed entries.
		if ( 'completed' !== $entry->execution_state ) {
			$result['description'] = __( 'Not completed — skip.', 'konx-affiliate-dashboard' );
			return $result;
		}

		if ( 'create' === $entry->planned_action ) {
			// Created both user and affiliate — delete both.
			$result['rollback_type'] = 'delete_user_and_affiliate';
			$result['description']   = sprintf(
				__( 'Delete WP user #%d and affiliate #%d.', 'konx-affiliate-dashboard' ),
				$entry->created_user_id,
				$entry->created_affiliate_id
			);
		} elseif ( in_array( $entry->planned_action, array( 'link_wp', 'link_ca' ), true ) ) {
			// Linked existing user — delete affiliate, clean meta, preserve user.
			$result['rollback_type'] = 'delete_affiliate_only';
			$result['description']   = sprintf(
				__( 'Delete affiliate #%d, clean migration meta for WP user #%d (user preserved).', 'konx-affiliate-dashboard' ),
				$entry->created_affiliate_id,
				$entry->created_user_id
			);
		} else {
			$result['description'] = __( 'No rollback needed.', 'konx-affiliate-dashboard' );
		}

		return $result;
	}

	// ------------------------------------------------------------------
	// Execute Rollback
	// ------------------------------------------------------------------

	/**
	 * Execute the rollback for a migration session.
	 *
	 * Processes completed log entries in reverse order.
	 *
	 * @param string $session_id Migration session ID.
	 * @return array|WP_Error Rollback results or error.
	 */
	public static function execute( $session_id ) {
		global $wpdb;

		// Validate gates.
		$gates = self::validate_gates( $session_id );
		if ( is_wp_error( $gates ) ) {
			return $gates;
		}

		// Acquire lock.
		if ( ! self::acquire_lock( $session_id ) ) {
			return new \WP_Error( 'lock_failed', __( 'Could not acquire rollback lock.', 'konx-affiliate-dashboard' ) );
		}

		$log_entries = self::get_completed_log_entries( $session_id );

		// Process in reverse order.
		$log_entries = array_reverse( $log_entries );

		$results = array(
			'session_id'         => $session_id,
			'total'              => count( $log_entries ),
			'affiliates_deleted' => 0,
			'users_deleted'      => 0,
			'users_cleaned'      => 0,
			'skipped'            => 0,
			'errors'             => 0,
			'error_details'      => array(),
			'actions'            => array(),
		);

		foreach ( $log_entries as $entry ) {
			$action = self::rollback_entry( $entry );
			$results['actions'][] = $action;

			if ( $action['success'] ) {
				switch ( $action['rollback_type'] ) {
					case 'delete_user_and_affiliate':
						$results['affiliates_deleted']++;
						$results['users_deleted']++;
						break;
					case 'delete_affiliate_only':
						$results['affiliates_deleted']++;
						$results['users_cleaned']++;
						break;
					case 'skip':
						$results['skipped']++;
						break;
				}

				// Update log entry state.
				self::update_log_state( $entry->id, 'rolled_back' );
			} else {
				$results['errors']++;
				$results['error_details'][] = $action['error'];

				// Update log with error.
				self::update_log_state( $entry->id, 'rollback_failed', $action['error'] );
			}
		}

		// Update session status.
		Konx_Migration_Session::update_status( $session_id, 'rolled_back' );

		// Release lock.
		self::release_lock();

		return $results;
	}

	/**
	 * Roll back a single log entry.
	 *
	 * @param object $entry Migration log row.
	 * @return array { success, rollback_type, log_id, error }.
	 */
	private static function rollback_entry( $entry ) {
		$result = array(
			'success'       => true,
			'rollback_type' => 'skip',
			'log_id'        => (int) $entry->id,
			'email'         => $entry->email,
			'error'         => null,
		);

		// Only roll back completed entries.
		if ( 'completed' !== $entry->execution_state ) {
			return $result;
		}

		if ( 'create' === $entry->planned_action ) {
			return self::rollback_create( $entry );
		}

		if ( in_array( $entry->planned_action, array( 'link_wp', 'link_ca' ), true ) ) {
			return self::rollback_link( $entry );
		}

		return $result;
	}

	/**
	 * Roll back a 'create' action: delete affiliate + user.
	 *
	 * @param object $entry Migration log row.
	 * @return array Result.
	 */
	private static function rollback_create( $entry ) {
		global $wpdb;

		$result = array(
			'success'       => false,
			'rollback_type' => 'delete_user_and_affiliate',
			'log_id'        => (int) $entry->id,
			'email'         => $entry->email,
			'error'         => null,
		);

		$affiliate_id = $entry->created_affiliate_id ? (int) $entry->created_affiliate_id : null;
		$user_id      = $entry->created_user_id ? (int) $entry->created_user_id : null;

		// Delete affiliate first.
		if ( $affiliate_id ) {
			$aff_table = $wpdb->prefix . 'konx_affiliates';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->delete( $aff_table, array( 'id' => $affiliate_id ), array( '%d' ) );
			if ( false === $deleted ) {
				$result['error'] = sprintf( __( 'Failed to delete affiliate #%d.', 'konx-affiliate-dashboard' ), $affiliate_id );
				return $result;
			}
		}

		// Delete WP user (only if created by migration).
		if ( $user_id ) {
			$is_migration_user = get_user_meta( $user_id, 'konx_source', true );
			if ( 'migration' === $is_migration_user ) {
				// Clean usermeta first.
				self::clean_migration_meta( $user_id );

				// Delete the user. Do not reassign posts.
				if ( ! function_exists( 'wp_delete_user' ) ) {
					require_once ABSPATH . 'wp-admin/includes/user.php';
				}
				$deleted = wp_delete_user( $user_id );
				if ( ! $deleted ) {
					$result['error'] = sprintf( __( 'Failed to delete WP user #%d.', 'konx-affiliate-dashboard' ), $user_id );
					return $result;
				}
			} else {
				// User existed before migration — clean meta only.
				self::clean_migration_meta( $user_id );
			}
		}

		$result['success'] = true;
		return $result;
	}

	/**
	 * Roll back a 'link_wp' or 'link_ca' action: delete affiliate, clean meta.
	 *
	 * Preserves the existing WordPress user and password.
	 *
	 * @param object $entry Migration log row.
	 * @return array Result.
	 */
	private static function rollback_link( $entry ) {
		global $wpdb;

		$result = array(
			'success'       => false,
			'rollback_type' => 'delete_affiliate_only',
			'log_id'        => (int) $entry->id,
			'email'         => $entry->email,
			'error'         => null,
		);

		$affiliate_id = $entry->created_affiliate_id ? (int) $entry->created_affiliate_id : null;
		$user_id      = $entry->created_user_id ? (int) $entry->created_user_id : null;

		// Delete the KonX affiliate profile.
		if ( $affiliate_id ) {
			$aff_table = $wpdb->prefix . 'konx_affiliates';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->delete( $aff_table, array( 'id' => $affiliate_id ), array( '%d' ) );
			if ( false === $deleted ) {
				$result['error'] = sprintf( __( 'Failed to delete affiliate #%d.', 'konx-affiliate-dashboard' ), $affiliate_id );
				return $result;
			}
		}

		// Clean migration-related meta from the preserved user.
		if ( $user_id ) {
			self::clean_migration_meta( $user_id );
		}

		$result['success'] = true;
		return $result;
	}

	// ------------------------------------------------------------------
	// Meta Cleanup
	// ------------------------------------------------------------------

	/**
	 * Remove migration-specific usermeta from a WordPress user.
	 *
	 * Preserves the user account, password, and all non-migration meta.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	private static function clean_migration_meta( $user_id ) {
		$migration_meta_keys = array(
			'konx_source',
			'konx_migrated_po10_id',
		);

		foreach ( $migration_meta_keys as $key ) {
			delete_user_meta( $user_id, $key );
		}
	}

	// ------------------------------------------------------------------
	// Log Helpers
	// ------------------------------------------------------------------

	/**
	 * Get completed log entries for a session.
	 *
	 * @param string $session_id Migration session ID.
	 * @return array Array of log row objects.
	 */
	private static function get_completed_log_entries( $session_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_migration_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE migration_id = %s AND execution_state IN ('completed','failed') ORDER BY id ASC",
				$session_id
			)
		);
	}

	/**
	 * Get total log entry count for a session.
	 *
	 * @param string $session_id Migration session ID.
	 * @return int Count.
	 */
	private static function get_log_count( $session_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_migration_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE migration_id = %s", $session_id )
		);
	}

	/**
	 * Update a log entry's execution state.
	 *
	 * @param int         $log_id Log entry ID.
	 * @param string      $state  New state (rolled_back, rollback_failed).
	 * @param string|null $error  Error message.
	 */
	private static function update_log_state( $log_id, $state, $error = null ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_migration_log';
		$data  = array( 'execution_state' => $state );

		if ( $error ) {
			$data['error_message'] = sanitize_text_field( mb_substr( $error, 0, 500 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, $data, array( 'id' => absint( $log_id ) ) );
	}
}
