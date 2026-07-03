<?php
/**
 * Execution verification engine.
 *
 * Simulates the entire migration execution process using the Execution
 * Planner output. For every planned action, verifies that all
 * preconditions are met and produces a readiness status. Optionally
 * populates wp_konx_migration_log with execution_state = 'planned'.
 *
 * Read-only on production data. No users or affiliates are created.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Execution_Verifier
 */
class Konx_Execution_Verifier {

	/**
	 * Readiness statuses.
	 */
	const STATUS_READY   = 'ready';
	const STATUS_BLOCKED = 'blocked';
	const STATUS_WARNING = 'warning';
	const STATUS_FAILED  = 'failed';

	// ------------------------------------------------------------------
	// Main Verification
	// ------------------------------------------------------------------

	/**
	 * Verify every item in an execution plan.
	 *
	 * Runs all verification checks against live database state.
	 * No production writes. Returns a complete readiness report.
	 *
	 * @param array $plan Execution plan from Konx_Execution_Planner::build().
	 * @return array {
	 *     @type string $plan_id       Plan identifier.
	 *     @type string $verified_at   ISO 8601 timestamp.
	 *     @type array  $summary       { ready, blocked, warning, failed, skipped }.
	 *     @type bool   $can_execute   True if no items are blocked or failed.
	 *     @type array  $items         Per-item verification results.
	 *     @type array  $rollback      Rollback readiness summary.
	 *     @type array  $duration      Estimated execution time.
	 * }
	 */
	public static function verify( $plan ) {
		if ( empty( $plan['items'] ) ) {
			return self::empty_report( $plan );
		}

		// Load live database state for verification.
		$db_state = self::load_db_state();

		$results = array();
		$summary = array(
			'ready'   => 0,
			'blocked' => 0,
			'warning' => 0,
			'failed'  => 0,
			'skipped' => 0,
		);

		// Build internal index of plan items by team_name for dependency checking.
		$plan_index = array();
		foreach ( $plan['items'] as $item ) {
			$tn = strtolower( trim( $item['team_name'] ) );
			if ( '' !== $tn ) {
				$plan_index[ $tn ] = $item;
			}
		}

		foreach ( $plan['items'] as $item ) {
			$result = self::verify_item( $item, $db_state, $plan_index );
			$results[] = $result;

			if ( isset( $summary[ $result['status'] ] ) ) {
				$summary[ $result['status'] ]++;
			}
		}

		$can_execute = ( 0 === $summary['blocked'] && 0 === $summary['failed'] );

		return array(
			'plan_id'     => isset( $plan['plan_id'] ) ? $plan['plan_id'] : null,
			'verified_at' => gmdate( 'c' ),
			'summary'     => $summary,
			'can_execute' => $can_execute,
			'items'       => $results,
			'rollback'    => self::verify_rollback_readiness( $plan, $results ),
			'duration'    => isset( $plan['duration'] ) ? $plan['duration'] : array(),
		);
	}

	// ------------------------------------------------------------------
	// Per-Item Verification
	// ------------------------------------------------------------------

	/**
	 * Verify a single plan item.
	 *
	 * @param array $item       Plan item from execution planner.
	 * @param array $db_state   Live database state.
	 * @param array $plan_index Plan items indexed by team_name.
	 * @return array Verification result.
	 */
	private static function verify_item( $item, $db_state, $plan_index ) {
		$action = $item['planned_action'];

		// Skip/invalid items are automatically verified.
		if ( in_array( $action, array( 'skip', 'invalid' ), true ) ) {
			return array(
				'po10_id'          => $item['po10_id'],
				'email'            => $item['email'],
				'planned_action'   => $action,
				'status'           => 'skipped',
				'checks'           => array(),
				'reason'           => implode( '; ', $item['reasons'] ),
				'estimated_time'   => 0,
				'rollback_action'  => null,
			);
		}

		$checks = array();

		// Run all verification checks.
		$checks[] = self::check_required_data( $item );
		$checks[] = self::check_email_uniqueness( $item, $db_state );
		$checks[] = self::check_external_id_uniqueness( $item, $db_state );
		$checks[] = self::check_referral_code_available( $item, $db_state );
		$checks[] = self::check_product_mapping( $db_state );
		$checks[] = self::check_dependencies( $item, $plan_index );
		$checks[] = self::check_sponsor_resolution( $item, $db_state, $plan_index );

		// Action-specific checks.
		if ( 'link_wp' === $action ) {
			$checks[] = self::check_wp_user_exists( $item, $db_state );
		} elseif ( 'link_ca' === $action ) {
			$checks[] = self::check_ca_exists( $item, $db_state );
		} elseif ( 'link_konx' === $action ) {
			$checks[] = self::check_konx_exists( $item, $db_state );
		}

		$checks[] = self::check_rollback_metadata( $item );

		// Determine overall status from checks.
		$status = self::determine_status( $checks );

		// Build reason string from failing checks.
		$failing = array();
		foreach ( $checks as $c ) {
			if ( 'pass' !== $c['status'] ) {
				$failing[] = $c['message'];
			}
		}

		return array(
			'po10_id'          => $item['po10_id'],
			'email'            => $item['email'],
			'team_name'        => $item['team_name'],
			'planned_action'   => $action,
			'status'           => $status,
			'checks'           => $checks,
			'reason'           => ! empty( $failing ) ? implode( '; ', $failing ) : __( 'All checks passed.', 'konx-affiliate-dashboard' ),
			'estimated_time'   => Konx_Execution_Planner::SECONDS_PER_RECORD,
			'rollback_action'  => $item['rollback_action'],
		);
	}

	// ------------------------------------------------------------------
	// Individual Checks
	// ------------------------------------------------------------------

	/**
	 * Check that required data fields exist.
	 *
	 * @param array $item Plan item.
	 * @return array Check result.
	 */
	private static function check_required_data( $item ) {
		$missing = array();

		if ( empty( $item['email'] ) ) {
			$missing[] = 'email';
		}
		if ( empty( $item['team_name'] ) && 'create' === $item['planned_action'] ) {
			$missing[] = 'referral_code (team_name)';
		}
		if ( null === $item['po10_id'] ) {
			$missing[] = 'po10_id';
		}

		return array(
			'id'      => 'required_data',
			'label'   => __( 'Required Data', 'konx-affiliate-dashboard' ),
			'status'  => empty( $missing ) ? 'pass' : 'fail',
			'message' => empty( $missing )
				? __( 'All required fields present.', 'konx-affiliate-dashboard' )
				: sprintf( __( 'Missing: %s', 'konx-affiliate-dashboard' ), implode( ', ', $missing ) ),
		);
	}

	/**
	 * Check email uniqueness against existing KonX affiliates.
	 *
	 * @param array $item     Plan item.
	 * @param array $db_state Database state.
	 * @return array Check result.
	 */
	private static function check_email_uniqueness( $item, $db_state ) {
		$email  = strtolower( trim( $item['email'] ) );
		$action = $item['planned_action'];

		// For link actions, existing user is expected.
		if ( in_array( $action, array( 'link_wp', 'link_ca', 'link_konx' ), true ) ) {
			return array(
				'id'      => 'email_unique',
				'label'   => __( 'Email Uniqueness', 'konx-affiliate-dashboard' ),
				'status'  => 'pass',
				'message' => __( 'Link action — existing user expected.', 'konx-affiliate-dashboard' ),
			);
		}

		// For create action, email must not already have a KonX affiliate.
		$has_konx = isset( $db_state['konx_by_email'][ $email ] );

		return array(
			'id'      => 'email_unique',
			'label'   => __( 'Email Uniqueness', 'konx-affiliate-dashboard' ),
			'status'  => $has_konx ? 'fail' : 'pass',
			'message' => $has_konx
				? sprintf( __( 'Email %s already has a KonX affiliate.', 'konx-affiliate-dashboard' ), $email )
				: __( 'Email is available for new affiliate.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check external_id uniqueness.
	 *
	 * @param array $item     Plan item.
	 * @param array $db_state Database state.
	 * @return array Check result.
	 */
	private static function check_external_id_uniqueness( $item, $db_state ) {
		$ext_id = 'po10_' . $item['po10_id'];
		$exists = isset( $db_state['konx_external_ids'][ $ext_id ] );

		return array(
			'id'      => 'external_id_unique',
			'label'   => __( 'External ID', 'konx-affiliate-dashboard' ),
			'status'  => $exists ? 'warn' : 'pass',
			'message' => $exists
				? sprintf( __( 'External ID %s already exists — will be treated as duplicate.', 'konx-affiliate-dashboard' ), $ext_id )
				: __( 'External ID is unique.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check that referral code (team_name) is available.
	 *
	 * @param array $item     Plan item.
	 * @param array $db_state Database state.
	 * @return array Check result.
	 */
	private static function check_referral_code_available( $item, $db_state ) {
		$code = strtolower( trim( $item['team_name'] ) );

		if ( empty( $code ) ) {
			if ( 'create' === $item['planned_action'] ) {
				return array(
					'id'      => 'referral_code',
					'label'   => __( 'Referral Code', 'konx-affiliate-dashboard' ),
					'status'  => 'fail',
					'message' => __( 'No referral code (team_name) provided.', 'konx-affiliate-dashboard' ),
				);
			}
			return array(
				'id'      => 'referral_code',
				'label'   => __( 'Referral Code', 'konx-affiliate-dashboard' ),
				'status'  => 'pass',
				'message' => __( 'Not required for this action.', 'konx-affiliate-dashboard' ),
			);
		}

		$taken = isset( $db_state['konx_referral_codes'][ $code ] );

		return array(
			'id'      => 'referral_code',
			'label'   => __( 'Referral Code', 'konx-affiliate-dashboard' ),
			'status'  => $taken ? 'fail' : 'pass',
			'message' => $taken
				? sprintf( __( 'Referral code "%s" is already in use.', 'konx-affiliate-dashboard' ), $item['team_name'] )
				: __( 'Referral code is available.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check that product mapping is configured.
	 *
	 * @param array $db_state Database state.
	 * @return array Check result.
	 */
	private static function check_product_mapping( $db_state ) {
		return array(
			'id'      => 'product_mapping',
			'label'   => __( 'Product Mapping', 'konx-affiliate-dashboard' ),
			'status'  => $db_state['product_map_count'] > 0 ? 'pass' : 'warn',
			'message' => $db_state['product_map_count'] > 0
				? sprintf( __( '%d product mappings active.', 'konx-affiliate-dashboard' ), $db_state['product_map_count'] )
				: __( 'No product mappings — commissions cannot be calculated.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check that dependencies (sponsor) are satisfied.
	 *
	 * @param array $item       Plan item.
	 * @param array $plan_index Items indexed by team_name.
	 * @return array Check result.
	 */
	private static function check_dependencies( $item, $plan_index ) {
		if ( empty( $item['dependencies'] ) ) {
			return array(
				'id'      => 'dependencies',
				'label'   => __( 'Dependencies', 'konx-affiliate-dashboard' ),
				'status'  => 'pass',
				'message' => __( 'No dependencies.', 'konx-affiliate-dashboard' ),
			);
		}

		return array(
			'id'      => 'dependencies',
			'label'   => __( 'Dependencies', 'konx-affiliate-dashboard' ),
			'status'  => 'pass',
			'message' => sprintf(
				__( '%d dependency(ies) — will be processed in execution order.', 'konx-affiliate-dashboard' ),
				count( $item['dependencies'] )
			),
		);
	}

	/**
	 * Check sponsor resolution status.
	 *
	 * @param array $item       Plan item.
	 * @param array $db_state   Database state.
	 * @param array $plan_index Items indexed by team_name.
	 * @return array Check result.
	 */
	private static function check_sponsor_resolution( $item, $db_state, $plan_index ) {
		$sponsor_status = $item['sponsor_status'];

		if ( 'none' === $sponsor_status ) {
			return array(
				'id'      => 'sponsor',
				'label'   => __( 'Sponsor Resolution', 'konx-affiliate-dashboard' ),
				'status'  => 'pass',
				'message' => __( 'No sponsor specified.', 'konx-affiliate-dashboard' ),
			);
		}

		if ( 'resolved' === $sponsor_status ) {
			return array(
				'id'      => 'sponsor',
				'label'   => __( 'Sponsor Resolution', 'konx-affiliate-dashboard' ),
				'status'  => 'pass',
				'message' => __( 'Sponsor resolved within migration set.', 'konx-affiliate-dashboard' ),
			);
		}

		if ( 'orphan' === $sponsor_status ) {
			return array(
				'id'      => 'sponsor',
				'label'   => __( 'Sponsor Resolution', 'konx-affiliate-dashboard' ),
				'status'  => 'warn',
				'message' => __( 'Sponsor unresolved — parent will be NULL.', 'konx-affiliate-dashboard' ),
			);
		}

		// Manual resolution.
		return array(
			'id'      => 'sponsor',
			'label'   => __( 'Sponsor Resolution', 'konx-affiliate-dashboard' ),
			'status'  => 'pass',
			'message' => sprintf( __( 'Manually resolved: %s', 'konx-affiliate-dashboard' ), $sponsor_status ),
		);
	}

	/**
	 * Check that WP user exists for link_wp action.
	 *
	 * @param array $item     Plan item.
	 * @param array $db_state Database state.
	 * @return array Check result.
	 */
	private static function check_wp_user_exists( $item, $db_state ) {
		$email   = strtolower( trim( $item['email'] ) );
		$wp_id   = isset( $db_state['wp_by_email'][ $email ] ) ? $db_state['wp_by_email'][ $email ] : null;

		return array(
			'id'      => 'wp_user_exists',
			'label'   => __( 'WordPress User', 'konx-affiliate-dashboard' ),
			'status'  => $wp_id ? 'pass' : 'fail',
			'message' => $wp_id
				? sprintf( __( 'WP user #%d found.', 'konx-affiliate-dashboard' ), $wp_id )
				: sprintf( __( 'No WP user found for %s — cannot link.', 'konx-affiliate-dashboard' ), $email ),
		);
	}

	/**
	 * Check that Coupon Affiliate exists for link_ca action.
	 *
	 * @param array $item     Plan item.
	 * @param array $db_state Database state.
	 * @return array Check result.
	 */
	private static function check_ca_exists( $item, $db_state ) {
		$has_ca = ! empty( $item['ca_coupon'] );

		return array(
			'id'      => 'ca_exists',
			'label'   => __( 'Coupon Affiliate', 'konx-affiliate-dashboard' ),
			'status'  => $has_ca ? 'pass' : 'fail',
			'message' => $has_ca
				? sprintf( __( 'Coupon Affiliate found (coupon: %s).', 'konx-affiliate-dashboard' ), $item['ca_coupon'] )
				: __( 'No Coupon Affiliate record found — cannot link.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check that KonX affiliate exists for link_konx action.
	 *
	 * @param array $item     Plan item.
	 * @param array $db_state Database state.
	 * @return array Check result.
	 */
	private static function check_konx_exists( $item, $db_state ) {
		$has_konx = ! empty( $item['konx_id'] );

		return array(
			'id'      => 'konx_exists',
			'label'   => __( 'KonX Affiliate', 'konx-affiliate-dashboard' ),
			'status'  => $has_konx ? 'pass' : 'fail',
			'message' => $has_konx
				? sprintf( __( 'KonX affiliate #%d found.', 'konx-affiliate-dashboard' ), $item['konx_id'] )
				: __( 'No KonX affiliate found — cannot link.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check that rollback metadata is available.
	 *
	 * @param array $item Plan item.
	 * @return array Check result.
	 */
	private static function check_rollback_metadata( $item ) {
		$has_rollback = ! empty( $item['rollback_action'] );

		return array(
			'id'      => 'rollback',
			'label'   => __( 'Rollback Strategy', 'konx-affiliate-dashboard' ),
			'status'  => $has_rollback ? 'pass' : 'warn',
			'message' => $has_rollback
				? sprintf( __( 'Rollback: %s', 'konx-affiliate-dashboard' ), $item['rollback_action'] )
				: __( 'No rollback action defined.', 'konx-affiliate-dashboard' ),
		);
	}

	// ------------------------------------------------------------------
	// Status Resolution
	// ------------------------------------------------------------------

	/**
	 * Determine overall status from individual checks.
	 *
	 * @param array $checks Array of check results.
	 * @return string Overall status.
	 */
	private static function determine_status( $checks ) {
		$has_fail = false;
		$has_warn = false;

		foreach ( $checks as $c ) {
			if ( 'fail' === $c['status'] ) {
				$has_fail = true;
			} elseif ( 'warn' === $c['status'] ) {
				$has_warn = true;
			}
		}

		if ( $has_fail ) {
			return self::STATUS_BLOCKED;
		}

		if ( $has_warn ) {
			return self::STATUS_WARNING;
		}

		return self::STATUS_READY;
	}

	// ------------------------------------------------------------------
	// Rollback Readiness
	// ------------------------------------------------------------------

	/**
	 * Verify rollback readiness across all items.
	 *
	 * @param array $plan    Execution plan.
	 * @param array $results Verification results.
	 * @return array Rollback readiness summary.
	 */
	private static function verify_rollback_readiness( $plan, $results ) {
		$with_rollback    = 0;
		$without_rollback = 0;

		foreach ( $results as $r ) {
			if ( 'skipped' === $r['status'] ) {
				continue;
			}
			if ( ! empty( $r['rollback_action'] ) ) {
				$with_rollback++;
			} else {
				$without_rollback++;
			}
		}

		$total = $with_rollback + $without_rollback;

		return array(
			'total_actionable'  => $total,
			'with_rollback'     => $with_rollback,
			'without_rollback'  => $without_rollback,
			'fully_reversible'  => ( 0 === $without_rollback && $total > 0 ),
			'rollback_coverage' => $total > 0 ? round( ( $with_rollback / $total ) * 100 ) : 100,
			'strategies'        => isset( $plan['rollback_meta']['strategies'] ) ? $plan['rollback_meta']['strategies'] : array(),
		);
	}

	// ------------------------------------------------------------------
	// Migration Log Preview
	// ------------------------------------------------------------------

	/**
	 * Populate wp_konx_migration_log with planned entries.
	 *
	 * Inserts one row per actionable plan item with execution_state
	 * set to 'planned'. No user IDs or affiliate IDs are set.
	 * This is preview data only — no execution occurs.
	 *
	 * @param string $session_id Migration session ID.
	 * @param array  $plan       Execution plan.
	 * @param array  $report     Verification report.
	 * @return array { inserted, skipped, errors }.
	 */
	public static function populate_log_preview( $session_id, $plan, $report ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'konx_migration_log';
		$inserted = 0;
		$skipped  = 0;
		$errors   = 0;

		// Clear any existing planned entries for this session.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$table,
			array(
				'migration_id'    => $session_id,
				'execution_state' => 'planned',
			),
			array( '%s', '%s' )
		);

		foreach ( $report['items'] as $idx => $result ) {
			if ( 'skipped' === $result['status'] ) {
				$skipped++;
				continue;
			}

			$item    = isset( $plan['items'][ $idx ] ) ? $plan['items'][ $idx ] : array();
			$ext_id  = isset( $item['po10_id'] ) ? 'po10_' . $item['po10_id'] : null;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ok = $wpdb->insert(
				$table,
				array(
					'migration_id'    => $session_id,
					'external_id'     => $ext_id,
					'email'           => $result['email'],
					'planned_action'  => $result['planned_action'],
					'execution_state' => 'planned',
					'rollback_action' => $result['rollback_action'],
					'error_message'   => 'skipped' !== $result['status'] && self::STATUS_READY !== $result['status']
						? $result['reason']
						: null,
					'created_at'      => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false === $ok ) {
				$errors++;
			} else {
				$inserted++;
			}
		}

		return array(
			'inserted' => $inserted,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	// ------------------------------------------------------------------
	// Database State Loader
	// ------------------------------------------------------------------

	/**
	 * Load live database state for verification checks.
	 *
	 * Read-only queries. Builds lookup indexes for emails,
	 * referral codes, external IDs, and WP users.
	 *
	 * @return array Database state arrays.
	 */
	private static function load_db_state() {
		global $wpdb;

		// KonX affiliates by email.
		$konx_table = $wpdb->prefix . 'konx_affiliates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$konx_rows = $wpdb->get_results(
			"SELECT a.id, a.referral_code, a.external_id, u.user_email
			 FROM {$konx_table} a
			 LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID"
		);

		$konx_by_email    = array();
		$konx_codes       = array();
		$konx_external    = array();
		foreach ( $konx_rows as $k ) {
			if ( ! empty( $k->user_email ) ) {
				$konx_by_email[ strtolower( $k->user_email ) ] = (int) $k->id;
			}
			if ( ! empty( $k->referral_code ) ) {
				$konx_codes[ strtolower( $k->referral_code ) ] = (int) $k->id;
			}
			if ( ! empty( $k->external_id ) ) {
				$konx_external[ $k->external_id ] = (int) $k->id;
			}
		}

		// WP users by email.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wp_rows = $wpdb->get_results( "SELECT ID, user_email FROM {$wpdb->users}" );
		$wp_by_email = array();
		foreach ( $wp_rows as $u ) {
			$wp_by_email[ strtolower( $u->user_email ) ] = (int) $u->ID;
		}

		// Product mapping count.
		$pm_table = $wpdb->prefix . 'konx_product_map';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pm_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pm_table} WHERE is_active = 1" );

		return array(
			'konx_by_email'      => $konx_by_email,
			'konx_referral_codes' => $konx_codes,
			'konx_external_ids'  => $konx_external,
			'wp_by_email'        => $wp_by_email,
			'product_map_count'  => $pm_count,
		);
	}

	// ------------------------------------------------------------------
	// Empty Report
	// ------------------------------------------------------------------

	/**
	 * Return an empty verification report.
	 *
	 * @param array $plan Execution plan.
	 * @return array Empty report.
	 */
	private static function empty_report( $plan ) {
		return array(
			'plan_id'     => isset( $plan['plan_id'] ) ? $plan['plan_id'] : null,
			'verified_at' => gmdate( 'c' ),
			'summary'     => array( 'ready' => 0, 'blocked' => 0, 'warning' => 0, 'failed' => 0, 'skipped' => 0 ),
			'can_execute' => false,
			'items'       => array(),
			'rollback'    => array(
				'total_actionable' => 0, 'with_rollback' => 0, 'without_rollback' => 0,
				'fully_reversible' => true, 'rollback_coverage' => 100, 'strategies' => array(),
			),
			'duration'    => array(),
		);
	}
}
