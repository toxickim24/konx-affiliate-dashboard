<?php
/**
 * Migration preflight checks.
 *
 * Validates that all preconditions are met before migration execution
 * can begin. Each check returns pass/fail with a human-readable message.
 * Read-only — no data is modified.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Migration_Preflight
 */
class Konx_Migration_Preflight {

	/**
	 * Run all preflight checks.
	 *
	 * @param array $state Migration state from wp_options.
	 * @return array {
	 *     @type bool   $passed  True if all required checks passed.
	 *     @type int    $total   Total checks run.
	 *     @type int    $pass    Number passed.
	 *     @type int    $fail    Number failed.
	 *     @type int    $warn    Number of warnings.
	 *     @type array  $checks  Individual check results.
	 * }
	 */
	public static function run( $state = null ) {
		if ( null === $state ) {
			$state = get_option( 'konx_migration_state', array() );
		}

		$checks = array(
			self::check_db_version(),
			self::check_sessions_table(),
			self::check_log_table(),
			self::check_product_mapping(),
			self::check_csv_state( $state ),
			self::check_decision_matrix( $state ),
			self::check_sponsor_resolution( $state ),
			self::check_integrity_audit( $state ),
			self::check_user_permission(),
			self::check_migration_lock(),
			self::check_dry_run( $state ),
			self::check_approval( $state ),
		);

		$pass = 0;
		$fail = 0;
		$warn = 0;

		foreach ( $checks as $check ) {
			if ( 'pass' === $check['status'] ) {
				$pass++;
			} elseif ( 'fail' === $check['status'] ) {
				$fail++;
			} else {
				$warn++;
			}
		}

		$required_passed = ( 0 === $fail );

		return array(
			'passed' => $required_passed,
			'total'  => count( $checks ),
			'pass'   => $pass,
			'fail'   => $fail,
			'warn'   => $warn,
			'checks' => $checks,
		);
	}

	// ------------------------------------------------------------------
	// Individual Checks
	// ------------------------------------------------------------------

	/**
	 * Check that DB version is 1.2.0 or higher.
	 *
	 * @return array Check result.
	 */
	private static function check_db_version() {
		$db_ver  = get_option( 'konx_affiliate_db_version', '0' );
		$passed  = version_compare( $db_ver, '1.2.0', '>=' );

		return array(
			'id'      => 'db_version',
			'label'   => __( 'Database Version', 'konx-affiliate-dashboard' ),
			'status'  => $passed ? 'pass' : 'fail',
			'message' => $passed
				? sprintf( __( 'DB version %s meets minimum 1.2.0.', 'konx-affiliate-dashboard' ), $db_ver )
				: sprintf( __( 'DB version %s is below required 1.2.0. Re-activate the plugin.', 'konx-affiliate-dashboard' ), $db_ver ),
		);
	}

	/**
	 * Check that migration_sessions table exists.
	 *
	 * @return array Check result.
	 */
	private static function check_sessions_table() {
		global $wpdb;
		$table  = $wpdb->prefix . 'konx_migration_sessions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

		return array(
			'id'      => 'sessions_table',
			'label'   => __( 'Migration Sessions Table', 'konx-affiliate-dashboard' ),
			'status'  => $exists ? 'pass' : 'fail',
			'message' => $exists
				? __( 'wp_konx_migration_sessions table exists.', 'konx-affiliate-dashboard' )
				: __( 'wp_konx_migration_sessions table is missing. Re-activate the plugin.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check that migration_log table exists.
	 *
	 * @return array Check result.
	 */
	private static function check_log_table() {
		global $wpdb;
		$table  = $wpdb->prefix . 'konx_migration_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

		return array(
			'id'      => 'log_table',
			'label'   => __( 'Migration Log Table', 'konx-affiliate-dashboard' ),
			'status'  => $exists ? 'pass' : 'fail',
			'message' => $exists
				? __( 'wp_konx_migration_log table exists.', 'konx-affiliate-dashboard' )
				: __( 'wp_konx_migration_log table is missing. Re-activate the plugin.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check that Product Mapping is configured.
	 *
	 * @return array Check result.
	 */
	private static function check_product_mapping() {
		global $wpdb;
		$table = $wpdb->prefix . 'konx_product_map';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_active = 1" );

		return array(
			'id'      => 'product_mapping',
			'label'   => __( 'Product Mapping', 'konx-affiliate-dashboard' ),
			'status'  => $count > 0 ? 'pass' : 'warn',
			'message' => $count > 0
				? sprintf( __( '%d active product mappings configured.', 'konx-affiliate-dashboard' ), $count )
				: __( 'No product mappings configured. Commissions cannot be calculated without mappings.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check that CSV data has been loaded and scanned.
	 *
	 * @param array $state Migration state.
	 * @return array Check result.
	 */
	private static function check_csv_state( $state ) {
		$has_scan = ! empty( $state['scan'] );
		$has_csv  = ! empty( $state['csv_info'] );

		$passed = $has_scan && $has_csv;

		return array(
			'id'      => 'csv_state',
			'label'   => __( 'CSV Data Loaded', 'konx-affiliate-dashboard' ),
			'status'  => $passed ? 'pass' : 'fail',
			'message' => $passed
				? sprintf( __( 'CSV loaded: %s', 'konx-affiliate-dashboard' ), $state['csv_info']['filename'] )
				: __( 'No CSV data found. Upload a CSV in the Data Source step.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check that the Decision Matrix has been generated.
	 *
	 * @param array $state Migration state.
	 * @return array Check result.
	 */
	private static function check_decision_matrix( $state ) {
		$has_dm = ! empty( $state['decision_matrix']['decisions'] );

		return array(
			'id'      => 'decision_matrix',
			'label'   => __( 'Decision Matrix', 'konx-affiliate-dashboard' ),
			'status'  => $has_dm ? 'pass' : 'fail',
			'message' => $has_dm
				? sprintf( __( '%d decisions generated.', 'konx-affiliate-dashboard' ), count( $state['decision_matrix']['decisions'] ) )
				: __( 'Decision Matrix not found. Complete the Decision Matrix step.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check that Sponsor Resolution has been reviewed.
	 *
	 * @param array $state Migration state.
	 * @return array Check result.
	 */
	private static function check_sponsor_resolution( $state ) {
		$reviewed = isset( $state['sponsor_resolutions'] );

		return array(
			'id'      => 'sponsor_resolution',
			'label'   => __( 'Sponsor Resolution', 'konx-affiliate-dashboard' ),
			'status'  => $reviewed ? 'pass' : 'warn',
			'message' => $reviewed
				? sprintf( __( '%d manual resolutions recorded.', 'konx-affiliate-dashboard' ), count( $state['sponsor_resolutions'] ) )
				: __( 'Sponsor Resolution not reviewed. Unresolved sponsors will have NULL parent.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check that Integrity Audit has been completed.
	 *
	 * @param array $state Migration state.
	 * @return array Check result.
	 */
	private static function check_integrity_audit( $state ) {
		$has_audit = ! empty( $state['integrity_audit'] );

		return array(
			'id'      => 'integrity_audit',
			'label'   => __( 'Integrity Audit', 'konx-affiliate-dashboard' ),
			'status'  => $has_audit ? 'pass' : 'fail',
			'message' => $has_audit
				? __( 'Integrity Audit completed.', 'konx-affiliate-dashboard' )
				: __( 'Integrity Audit not run. Complete the Integrity Audit step.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check that the current user has migration permission.
	 *
	 * @return array Check result.
	 */
	private static function check_user_permission() {
		$can = current_user_can( 'manage_konx_settings' );

		return array(
			'id'      => 'user_permission',
			'label'   => __( 'User Permission', 'konx-affiliate-dashboard' ),
			'status'  => $can ? 'pass' : 'fail',
			'message' => $can
				? __( 'Current user has manage_konx_settings capability.', 'konx-affiliate-dashboard' )
				: __( 'Current user lacks manage_konx_settings capability.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check that no migration is currently in progress.
	 *
	 * @return array Check result.
	 */
	private static function check_migration_lock() {
		$lock = get_transient( 'konx_migration_lock' );

		return array(
			'id'      => 'migration_lock',
			'label'   => __( 'Migration Lock', 'konx-affiliate-dashboard' ),
			'status'  => ! $lock ? 'pass' : 'fail',
			'message' => ! $lock
				? __( 'No active migration lock. Safe to proceed.', 'konx-affiliate-dashboard' )
				: sprintf(
					__( 'Migration lock active (session: %s). Another migration may be in progress.', 'konx-affiliate-dashboard' ),
					$lock
				),
		);
	}

	/**
	 * Check that Dry Run has been completed.
	 *
	 * @param array $state Migration state.
	 * @return array Check result.
	 */
	private static function check_dry_run( $state ) {
		$has_dr = ! empty( $state['dry_run'] );

		return array(
			'id'      => 'dry_run',
			'label'   => __( 'Dry Run', 'konx-affiliate-dashboard' ),
			'status'  => $has_dr ? 'pass' : 'fail',
			'message' => $has_dr
				? sprintf( __( 'Dry run completed at %s.', 'konx-affiliate-dashboard' ), $state['dry_run_at'] )
				: __( 'Dry run not completed. Run the Dry Run step before proceeding.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check that the migration plan is approved.
	 *
	 * @param array $state Migration state.
	 * @return array Check result.
	 */
	private static function check_approval( $state ) {
		$approved = ! empty( $state['approved'] );

		return array(
			'id'      => 'approval',
			'label'   => __( 'Migration Approved', 'konx-affiliate-dashboard' ),
			'status'  => $approved ? 'pass' : 'fail',
			'message' => $approved
				? sprintf(
					__( 'Approved by user #%d at %s.', 'konx-affiliate-dashboard' ),
					$state['approved_by'],
					$state['approved_at']
				)
				: __( 'Migration not approved. Complete the Approval step.', 'konx-affiliate-dashboard' ),
		);
	}
}
