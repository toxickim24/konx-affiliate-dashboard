<?php
/**
 * Admin fee management.
 *
 * Handles creation, status tracking, and eligibility checks for
 * monthly admin fees that affiliates must pay to maintain commission
 * earning status.
 *
 * If an affiliate has any unpaid or overdue fees, their commissions
 * are blocked until the fees are marked as paid.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Admin_Fees
 */
class Konx_Admin_Fees {

	/**
	 * Fee statuses.
	 */
	const STATUS_UNPAID  = 'unpaid';
	const STATUS_OVERDUE = 'overdue';
	const STATUS_PAID    = 'paid';
	const STATUS_WAIVED  = 'waived';

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'konx_daily_overdue_fee_check';

	// ------------------------------------------------------------------
	// Commission Eligibility (used by commission engines)
	// ------------------------------------------------------------------

	/**
	 * Check whether an affiliate can earn commissions.
	 *
	 * Returns false if the affiliate has any unpaid or overdue fees.
	 * This is the single source of truth — commission engines delegate here.
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 * @return bool True if the affiliate can earn, false if blocked.
	 */
	public static function can_affiliate_earn( $affiliate_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_admin_fees';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE affiliate_id = %d AND status IN (%s, %s)",
				absint( $affiliate_id ),
				self::STATUS_UNPAID,
				self::STATUS_OVERDUE
			)
		);

		return 0 === $count;
	}

	// ------------------------------------------------------------------
	// Fee Amount
	// ------------------------------------------------------------------

	/**
	 * Get the monthly admin fee amount for an affiliate.
	 *
	 * Reads from plugin settings. Falls back to a configurable default.
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 * @return string Fee amount as a decimal string (e.g. '10.00').
	 */
	public static function get_fee_amount( $affiliate_id ) {
		$affiliate = Konx_Affiliate_Manager::get_affiliate( $affiliate_id );
		if ( ! $affiliate ) {
			return '0.00';
		}

		$settings = get_option( 'konx_admin_fee_settings', array() );

		// Per-type fee if configured.
		$type = $affiliate->affiliate_type;
		if ( ! empty( $settings[ $type ] ) ) {
			return number_format( (float) $settings[ $type ], 2, '.', '' );
		}

		// Global default.
		$default = ! empty( $settings['default'] ) ? $settings['default'] : '10.00';
		return number_format( (float) $default, 2, '.', '' );
	}

	// ------------------------------------------------------------------
	// Fee Creation
	// ------------------------------------------------------------------

	/**
	 * Create a monthly admin fee record for an affiliate.
	 *
	 * Idempotent — skips if a fee for the same affiliate and period exists.
	 *
	 * @param int    $affiliate_id The affiliate table ID.
	 * @param string $fee_period   Period label (e.g. '2026-07').
	 * @param string $due_date     Due date in Y-m-d format.
	 * @param string $fee_amount   Optional. Override the calculated fee amount.
	 * @return int|WP_Error Fee record ID, or WP_Error.
	 */
	public static function create_monthly_fee( $affiliate_id, $fee_period, $due_date, $fee_amount = '' ) {
		global $wpdb;

		$affiliate_id = absint( $affiliate_id );
		$table        = $wpdb->prefix . 'konx_admin_fees';

		// Idempotency: one fee per affiliate per period.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE affiliate_id = %d AND fee_period = %s",
				$affiliate_id,
				sanitize_text_field( $fee_period )
			)
		);

		if ( $existing ) {
			return (int) $existing->id;
		}

		if ( empty( $fee_amount ) ) {
			$fee_amount = self::get_fee_amount( $affiliate_id );
		}
		$fee_amount = number_format( (float) $fee_amount, 2, '.', '' );

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'affiliate_id' => $affiliate_id,
				'fee_amount'   => $fee_amount,
				'fee_period'   => sanitize_text_field( $fee_period ),
				'due_date'     => sanitize_text_field( $due_date ),
				'status'       => self::STATUS_UNPAID,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'db_insert_failed', __( 'Failed to create fee record.', 'konx-affiliate-dashboard' ) );
		}

		$fee_id = (int) $wpdb->insert_id;

		self::log_audit(
			'admin_fee_created',
			'admin_fee',
			$fee_id,
			null,
			$fee_amount,
			sprintf( 'Admin fee of %s created for period %s.', $fee_amount, $fee_period )
		);

		return $fee_id;
	}

	// ------------------------------------------------------------------
	// Status Changes
	// ------------------------------------------------------------------

	/**
	 * Mark a fee as paid.
	 *
	 * @param int    $fee_id The fee record ID.
	 * @param string $notes  Optional admin notes.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function mark_paid( $fee_id, $notes = '' ) {
		return self::update_status( $fee_id, self::STATUS_PAID, $notes );
	}

	/**
	 * Mark a fee as overdue.
	 *
	 * @param int    $fee_id The fee record ID.
	 * @param string $notes  Optional admin notes.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function mark_overdue( $fee_id, $notes = '' ) {
		return self::update_status( $fee_id, self::STATUS_OVERDUE, $notes );
	}

	/**
	 * Mark a fee as waived.
	 *
	 * @param int    $fee_id The fee record ID.
	 * @param string $notes  Optional admin notes.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function mark_waived( $fee_id, $notes = '' ) {
		return self::update_status( $fee_id, self::STATUS_WAIVED, $notes );
	}

	/**
	 * Update a fee's status with audit logging.
	 *
	 * @param int    $fee_id     The fee record ID.
	 * @param string $new_status The new status.
	 * @param string $notes      Optional admin notes.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private static function update_status( $fee_id, $new_status, $notes = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_admin_fees';
		$fee   = self::get_fee( $fee_id );

		if ( ! $fee ) {
			return new \WP_Error( 'not_found', __( 'Fee record not found.', 'konx-affiliate-dashboard' ) );
		}

		$old_status = $fee->status;
		if ( $old_status === $new_status ) {
			return true;
		}

		$data = array(
			'status'     => $new_status,
			'updated_at' => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s' );

		if ( self::STATUS_PAID === $new_status || self::STATUS_WAIVED === $new_status ) {
			$data['paid_date']        = current_time( 'Y-m-d', true );
			$data['paid_by_admin_id'] = get_current_user_id();
			$formats[]                = '%s';
			$formats[]                = '%d';
		}

		if ( ! empty( $notes ) ) {
			$data['notes'] = sanitize_textarea_field( $notes );
			$formats[]     = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update( $table, $data, array( 'id' => $fee_id ), $formats, array( '%d' ) );

		if ( false === $updated ) {
			return new \WP_Error( 'db_update_failed', __( 'Failed to update fee status.', 'konx-affiliate-dashboard' ) );
		}

		self::log_audit(
			'admin_fee_' . $new_status,
			'admin_fee',
			$fee_id,
			$old_status,
			$new_status,
			sprintf( 'Fee #%d status changed from %s to %s.', $fee_id, $old_status, $new_status )
		);

		return true;
	}

	// ------------------------------------------------------------------
	// Queries
	// ------------------------------------------------------------------

	/**
	 * Get a single fee record.
	 *
	 * @param int $fee_id The fee record ID.
	 * @return object|null Fee row, or null.
	 */
	public static function get_fee( $fee_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_admin_fees';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $fee_id ) )
		);
	}

	/**
	 * Check if the current period's fee is paid for an affiliate.
	 *
	 * @param int    $affiliate_id The affiliate table ID.
	 * @param string $period       Period label (defaults to current month).
	 * @return bool True if paid or waived for that period.
	 */
	public static function is_paid_current( $affiliate_id, $period = '' ) {
		global $wpdb;

		if ( empty( $period ) ) {
			$period = current_time( 'Y-m' );
		}

		$table = $wpdb->prefix . 'konx_admin_fees';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$table} WHERE affiliate_id = %d AND fee_period = %s",
				absint( $affiliate_id ),
				sanitize_text_field( $period )
			)
		);

		return in_array( $status, array( self::STATUS_PAID, self::STATUS_WAIVED ), true );
	}

	/**
	 * Get the current fee status summary for an affiliate.
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 * @return array {
	 *     @type bool   $can_earn         Whether commissions are enabled.
	 *     @type int    $unpaid_count     Number of unpaid fees.
	 *     @type int    $overdue_count    Number of overdue fees.
	 *     @type string $total_outstanding Total unpaid+overdue amount.
	 * }
	 */
	public static function get_fee_status( $affiliate_id ) {
		global $wpdb;

		$table        = $wpdb->prefix . 'konx_admin_fees';
		$affiliate_id = absint( $affiliate_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, fee_amount FROM {$table} WHERE affiliate_id = %d AND status IN (%s, %s)",
				$affiliate_id,
				self::STATUS_UNPAID,
				self::STATUS_OVERDUE
			)
		);

		$unpaid  = 0;
		$overdue = 0;
		$total   = '0.00';

		foreach ( $rows as $row ) {
			if ( self::STATUS_UNPAID === $row->status ) {
				$unpaid++;
			} else {
				$overdue++;
			}
			if ( function_exists( 'bcadd' ) ) {
				$total = bcadd( $total, $row->fee_amount, 2 );
			} else {
				$total = number_format( (float) $total + (float) $row->fee_amount, 2, '.', '' );
			}
		}

		return array(
			'can_earn'          => ( 0 === $unpaid + $overdue ),
			'unpaid_count'      => $unpaid,
			'overdue_count'     => $overdue,
			'total_outstanding' => $total,
		);
	}

	/**
	 * Get paginated fee history for an affiliate.
	 *
	 * @param int    $affiliate_id The affiliate table ID.
	 * @param int    $page         Page number.
	 * @param int    $per_page     Items per page.
	 * @param string $status       Optional status filter.
	 * @return array { entries, total, pages }
	 */
	public static function get_fee_history( $affiliate_id, $page = 1, $per_page = 20, $status = '' ) {
		global $wpdb;

		$table        = $wpdb->prefix . 'konx_admin_fees';
		$affiliate_id = absint( $affiliate_id );
		$page         = max( 1, (int) $page );
		$per_page     = max( 1, min( 100, (int) $per_page ) );
		$offset       = ( $page - 1 ) * $per_page;

		$where  = 'WHERE affiliate_id = %d';
		$params = array( $affiliate_id );

		if ( ! empty( $status ) ) {
			$where   .= ' AND status = %s';
			$params[] = sanitize_text_field( $status );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			)
		);

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where} ORDER BY due_date DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			)
		);

		return array(
			'entries' => $entries ? $entries : array(),
			'total'   => $total,
			'pages'   => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Get all fees across affiliates for admin listing.
	 *
	 * @param string $status   Optional status filter.
	 * @param string $search   Optional search term (affiliate ID or user display name).
	 * @param int    $page     Page number.
	 * @param int    $per_page Items per page.
	 * @return array { entries, total, pages }
	 */
	public static function get_all_fees( $status = '', $search = '', $page = 1, $per_page = 20 ) {
		global $wpdb;

		$table     = $wpdb->prefix . 'konx_admin_fees';
		$aff_table = $wpdb->prefix . 'konx_affiliates';
		$page      = max( 1, (int) $page );
		$per_page  = max( 1, min( 100, (int) $per_page ) );
		$offset    = ( $page - 1 ) * $per_page;

		$where  = 'WHERE 1=1';
		$params = array();

		if ( ! empty( $status ) ) {
			$where   .= ' AND f.status = %s';
			$params[] = sanitize_text_field( $status );
		}

		if ( ! empty( $search ) ) {
			$search_term = '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%';
			$where      .= ' AND (a.referral_code LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';
			$params[]    = $search_term;
			$params[]    = $search_term;
			$params[]    = $search_term;
		}

		$count_sql = "SELECT COUNT(*)
			FROM {$table} f
			INNER JOIN {$aff_table} a ON f.affiliate_id = a.id
			INNER JOIN {$wpdb->users} u ON a.user_id = u.ID
			{$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = empty( $params )
			? (int) $wpdb->get_var( $count_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		$select_params   = $params;
		$select_params[] = $per_page;
		$select_params[] = $offset;

		$select_sql = "SELECT f.*, a.referral_code, a.affiliate_type, a.user_id, u.display_name, u.user_email
			FROM {$table} f
			INNER JOIN {$aff_table} a ON f.affiliate_id = a.id
			INNER JOIN {$wpdb->users} u ON a.user_id = u.ID
			{$where}
			ORDER BY f.due_date DESC
			LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$entries = empty( $select_params )
			? $wpdb->get_results( $select_sql )
			: $wpdb->get_results( $wpdb->prepare( $select_sql, $select_params ) );

		return array(
			'entries' => $entries ? $entries : array(),
			'total'   => $total,
			'pages'   => (int) ceil( $total / $per_page ),
		);
	}

	// ------------------------------------------------------------------
	// Daily Overdue Check (Cron)
	// ------------------------------------------------------------------

	/**
	 * Run the daily overdue fee check.
	 *
	 * Finds all unpaid fees with a due_date in the past and marks them overdue.
	 */
	public static function run_daily_overdue_check() {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_admin_fees';
		$today = current_time( 'Y-m-d', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$overdue_fees = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = %s AND due_date < %s",
				self::STATUS_UNPAID,
				$today
			)
		);

		if ( empty( $overdue_fees ) ) {
			return;
		}

		foreach ( $overdue_fees as $fee ) {
			self::mark_overdue( (int) $fee->id, 'Auto-marked overdue by daily check.' );
		}
	}

	/**
	 * Schedule the daily overdue cron event.
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the daily overdue cron event.
	 */
	public static function clear_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Register the cron callback.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily_overdue_check' ) );
	}

	// ------------------------------------------------------------------
	// Audit Helper
	// ------------------------------------------------------------------

	/**
	 * Insert an audit log entry.
	 *
	 * @param string      $event_type  Event type.
	 * @param string      $object_type Object type.
	 * @param int         $object_id   Object ID.
	 * @param string|null $old_value   Previous value.
	 * @param string|null $new_value   New value.
	 * @param string      $description Description.
	 */
	private static function log_audit( $event_type, $object_type, $object_id, $old_value, $new_value, $description ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_audit_log';

		$ip = null;
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'event_type'  => sanitize_text_field( $event_type ),
				'object_type' => sanitize_text_field( $object_type ),
				'object_id'   => absint( $object_id ),
				'actor_id'    => get_current_user_id() ?: null,
				'old_value'   => $old_value,
				'new_value'   => $new_value,
				'description' => sanitize_text_field( $description ),
				'ip_address'  => $ip,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
