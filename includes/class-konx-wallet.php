<?php
/**
 * Wallet ledger system.
 *
 * Provides an append-only financial ledger for affiliate wallets.
 * Every credit and debit is a row in wp_konx_wallet_ledger.
 * Balance is derived from SUM(amount); the cached_balance column
 * on wp_konx_affiliates is a denormalized performance optimization.
 *
 * All monetary calculations use bcmath string arithmetic (or
 * number_format with string comparison) to avoid floating-point
 * precision errors.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Wallet
 */
class Konx_Wallet {

	// ------------------------------------------------------------------
	// Entry type constants
	// ------------------------------------------------------------------
	const TYPE_COMMISSION           = 'commission';
	const TYPE_RECURRING_COMMISSION = 'recurring_commission';
	const TYPE_MILESTONE_BONUS      = 'milestone_bonus';
	const TYPE_WITHDRAWAL           = 'withdrawal';
	const TYPE_REVERSAL             = 'reversal';
	const TYPE_ADJUSTMENT           = 'adjustment';

	// ------------------------------------------------------------------
	// Reference type constants
	// ------------------------------------------------------------------
	const REF_COMMISSION  = 'commission';
	const REF_WITHDRAWAL  = 'withdrawal';
	const REF_MILESTONE   = 'milestone';
	const REF_ADMIN       = 'admin';

	// ------------------------------------------------------------------
	// Credit
	// ------------------------------------------------------------------

	/**
	 * Add a credit (positive) entry to the ledger.
	 *
	 * Uses a database transaction with a FOR UPDATE lock on the
	 * affiliate row to prevent concurrent writes from corrupting
	 * the running balance or cached balance.
	 *
	 * @param int    $affiliate_id  The affiliate table ID.
	 * @param string $amount        The credit amount as a decimal string (e.g. '40.00').
	 * @param string $entry_type    One of the TYPE_* constants (credit types).
	 * @param string $reference_type One of the REF_* constants.
	 * @param int    $reference_id  The ID of the source record (commission, milestone, etc.).
	 * @param string $description   Human-readable description.
	 * @return int|WP_Error The ledger entry ID, or WP_Error on failure.
	 */
	public static function credit( $affiliate_id, $amount, $entry_type, $reference_type, $reference_id, $description ) {
		$amount = self::normalize_amount( $amount );
		if ( self::compare( $amount, '0.00' ) <= 0 ) {
			return new \WP_Error( 'invalid_amount', __( 'Credit amount must be positive.', 'konx-affiliate-dashboard' ) );
		}

		return self::insert_entry( $affiliate_id, $amount, $entry_type, $reference_type, $reference_id, $description );
	}

	// ------------------------------------------------------------------
	// Debit
	// ------------------------------------------------------------------

	/**
	 * Add a debit (negative) entry to the ledger.
	 *
	 * Re-validates the affiliate's current balance before debiting.
	 * Returns WP_Error if the debit would make the balance negative
	 * (unless $force is true, for admin manual adjustments).
	 *
	 * @param int    $affiliate_id  The affiliate table ID.
	 * @param string $amount        The debit amount as a positive decimal string (will be stored negative).
	 * @param string $entry_type    One of the TYPE_* constants (debit types).
	 * @param string $reference_type One of the REF_* constants.
	 * @param int    $reference_id  The ID of the source record (withdrawal, etc.).
	 * @param string $description   Human-readable description.
	 * @param bool   $force         If true, allow negative balance (admin adjustments).
	 * @return int|WP_Error The ledger entry ID, or WP_Error on failure.
	 */
	public static function debit( $affiliate_id, $amount, $entry_type, $reference_type, $reference_id, $description, $force = false ) {
		$amount = self::normalize_amount( $amount );
		if ( self::compare( $amount, '0.00' ) <= 0 ) {
			return new \WP_Error( 'invalid_amount', __( 'Debit amount must be positive.', 'konx-affiliate-dashboard' ) );
		}

		// Balance validation happens inside insert_entry under the lock.
		$negative_amount = '-' . $amount;

		return self::insert_entry( $affiliate_id, $negative_amount, $entry_type, $reference_type, $reference_id, $description, $force );
	}

	// ------------------------------------------------------------------
	// Reversal
	// ------------------------------------------------------------------

	/**
	 * Reverse a previous credit by inserting a negative entry.
	 *
	 * Used when an order is refunded and the commission must be clawed back.
	 * The original ledger entry is never modified — a new reversal entry
	 * is appended.
	 *
	 * @param int    $affiliate_id       The affiliate table ID.
	 * @param string $amount             The amount to reverse (positive string).
	 * @param int    $original_reference_id The commission/milestone ID being reversed.
	 * @param string $description        Human-readable reason.
	 * @return int|WP_Error The reversal ledger entry ID, or WP_Error on failure.
	 */
	public static function reverse( $affiliate_id, $amount, $original_reference_id, $description ) {
		$amount = self::normalize_amount( $amount );
		if ( self::compare( $amount, '0.00' ) <= 0 ) {
			return new \WP_Error( 'invalid_amount', __( 'Reversal amount must be positive.', 'konx-affiliate-dashboard' ) );
		}

		$negative_amount = '-' . $amount;

		// Reversals are allowed to make balance negative (the commission
		// was already paid out). Force = true so the reversal always records.
		return self::insert_entry(
			$affiliate_id,
			$negative_amount,
			self::TYPE_REVERSAL,
			self::REF_COMMISSION,
			$original_reference_id,
			$description,
			true
		);
	}

	// ------------------------------------------------------------------
	// Balance Queries
	// ------------------------------------------------------------------

	/**
	 * Get the authoritative available balance from the ledger SUM.
	 *
	 * This is the source of truth. The cached_balance on wp_konx_affiliates
	 * is a performance optimization that should match this value.
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 * @return string The balance as a decimal string (e.g. '150.00').
	 */
	public static function get_available_balance( $affiliate_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_wallet_ledger';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$balance = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE affiliate_id = %d",
				absint( $affiliate_id )
			)
		);

		return self::normalize_amount( $balance );
	}

	/**
	 * Get lifetime earnings (sum of all positive entries).
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 * @return string Total lifetime earnings as a decimal string.
	 */
	public static function get_lifetime_earnings( $affiliate_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_wallet_ledger';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE affiliate_id = %d AND amount > 0",
				absint( $affiliate_id )
			)
		);

		return self::normalize_amount( $total );
	}

	/**
	 * Get total withdrawals (sum of all withdrawal debits, returned as positive).
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 * @return string Total withdrawals as a positive decimal string.
	 */
	public static function get_total_withdrawals( $affiliate_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_wallet_ledger';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(ABS(SUM(amount)), 0) FROM {$table} WHERE affiliate_id = %d AND entry_type = %s",
				absint( $affiliate_id ),
				self::TYPE_WITHDRAWAL
			)
		);

		return self::normalize_amount( $total );
	}

	/**
	 * Get a full balance summary for an affiliate.
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 * @return array {
	 *     @type string $available_balance  Current spendable balance.
	 *     @type string $lifetime_earnings  Sum of all credits.
	 *     @type string $total_withdrawals  Sum of all withdrawal debits (positive).
	 *     @type string $total_reversals    Sum of all reversal debits (positive).
	 *     @type string $cached_balance     The cached_balance from wp_konx_affiliates.
	 *     @type bool   $in_sync            Whether cached_balance matches available_balance.
	 * }
	 */
	public static function get_affiliate_balance_summary( $affiliate_id ) {
		$available  = self::get_available_balance( $affiliate_id );
		$earnings   = self::get_lifetime_earnings( $affiliate_id );
		$withdrawals = self::get_total_withdrawals( $affiliate_id );
		$reversals  = self::get_total_reversals( $affiliate_id );

		$affiliate     = Konx_Affiliate_Manager::get_affiliate( $affiliate_id );
		$cached        = $affiliate ? self::normalize_amount( $affiliate->cached_balance ) : '0.00';
		$in_sync       = ( self::compare( $available, $cached ) === 0 );

		return array(
			'available_balance' => $available,
			'lifetime_earnings' => $earnings,
			'total_withdrawals' => $withdrawals,
			'total_reversals'   => $reversals,
			'cached_balance'    => $cached,
			'in_sync'           => $in_sync,
		);
	}

	/**
	 * Get total reversals (sum of all reversal debits, returned as positive).
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 * @return string Total reversals as a positive decimal string.
	 */
	public static function get_total_reversals( $affiliate_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_wallet_ledger';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(ABS(SUM(amount)), 0) FROM {$table} WHERE affiliate_id = %d AND entry_type = %s",
				absint( $affiliate_id ),
				self::TYPE_REVERSAL
			)
		);

		return self::normalize_amount( $total );
	}

	// ------------------------------------------------------------------
	// Ledger History
	// ------------------------------------------------------------------

	/**
	 * Get paginated ledger history for an affiliate.
	 *
	 * @param int    $affiliate_id The affiliate table ID.
	 * @param int    $page         Page number (1-based).
	 * @param int    $per_page     Entries per page.
	 * @param string $entry_type   Optional. Filter by entry type.
	 * @return array {
	 *     @type array  $entries    Array of ledger row objects.
	 *     @type int    $total      Total matching entries.
	 *     @type int    $pages      Total pages.
	 * }
	 */
	public static function get_ledger_history( $affiliate_id, $page = 1, $per_page = 20, $entry_type = '' ) {
		global $wpdb;

		$table        = $wpdb->prefix . 'konx_wallet_ledger';
		$affiliate_id = absint( $affiliate_id );
		$page         = max( 1, (int) $page );
		$per_page     = max( 1, min( 100, (int) $per_page ) );
		$offset       = ( $page - 1 ) * $per_page;

		$where  = 'WHERE affiliate_id = %d';
		$params = array( $affiliate_id );

		if ( ! empty( $entry_type ) ) {
			$where   .= ' AND entry_type = %s';
			$params[] = sanitize_text_field( $entry_type );
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
				"SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			)
		);

		return array(
			'entries' => $entries ? $entries : array(),
			'total'   => $total,
			'pages'   => (int) ceil( $total / $per_page ),
		);
	}

	// ------------------------------------------------------------------
	// Idempotency Check
	// ------------------------------------------------------------------

	/**
	 * Check if a ledger entry already exists for a given source.
	 *
	 * Prevents double-crediting the same commission, milestone, etc.
	 *
	 * @param int    $affiliate_id   The affiliate table ID.
	 * @param string $entry_type     The ledger entry type.
	 * @param string $reference_type The reference type.
	 * @param int    $reference_id   The source record ID.
	 * @return bool True if an entry already exists.
	 */
	public static function entry_exists( $affiliate_id, $entry_type, $reference_type, $reference_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_wallet_ledger';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE affiliate_id = %d AND entry_type = %s AND reference_type = %s AND reference_id = %d",
				absint( $affiliate_id ),
				sanitize_text_field( $entry_type ),
				sanitize_text_field( $reference_type ),
				absint( $reference_id )
			)
		);

		return $count > 0;
	}

	// ------------------------------------------------------------------
	// Reconciliation
	// ------------------------------------------------------------------

	/**
	 * Reconcile cached_balance against the ledger SUM.
	 *
	 * If they differ, updates cached_balance to match the SUM
	 * and logs the correction to the audit log.
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 * @return array {
	 *     @type bool   $was_in_sync   True if no correction was needed.
	 *     @type string $ledger_balance The balance from SUM.
	 *     @type string $cached_balance The balance that was cached.
	 *     @type string $drift          The difference (may be negative).
	 * }
	 */
	public static function reconcile( $affiliate_id ) {
		$affiliate_id  = absint( $affiliate_id );
		$ledger_balance = self::get_available_balance( $affiliate_id );

		$affiliate = Konx_Affiliate_Manager::get_affiliate( $affiliate_id );
		if ( ! $affiliate ) {
			return array(
				'was_in_sync'    => true,
				'ledger_balance' => $ledger_balance,
				'cached_balance' => '0.00',
				'drift'          => '0.00',
			);
		}

		$cached = self::normalize_amount( $affiliate->cached_balance );
		$drift  = self::subtract( $ledger_balance, $cached );

		if ( self::compare( $drift, '0.00' ) === 0 ) {
			return array(
				'was_in_sync'    => true,
				'ledger_balance' => $ledger_balance,
				'cached_balance' => $cached,
				'drift'          => '0.00',
			);
		}

		// Correct the cached balance.
		Konx_Affiliate_Manager::update_cached_balance( $affiliate_id, $ledger_balance );

		// Log the correction.
		self::log_audit(
			'balance_reconciled',
			'affiliate',
			$affiliate_id,
			$cached,
			$ledger_balance,
			sprintf( 'Balance reconciled. Cached: %s, Ledger: %s, Drift: %s', $cached, $ledger_balance, $drift )
		);

		return array(
			'was_in_sync'    => false,
			'ledger_balance' => $ledger_balance,
			'cached_balance' => $cached,
			'drift'          => $drift,
		);
	}

	// ------------------------------------------------------------------
	// Core Insert (Private)
	// ------------------------------------------------------------------

	/**
	 * Insert a ledger entry inside a database transaction.
	 *
	 * Acquires a FOR UPDATE lock on the affiliate row to serialize
	 * concurrent wallet operations. Computes running_balance and
	 * updates cached_balance atomically.
	 *
	 * @param int    $affiliate_id  The affiliate table ID.
	 * @param string $amount        Signed decimal string (positive=credit, negative=debit).
	 * @param string $entry_type    Entry type constant.
	 * @param string $reference_type Reference type constant.
	 * @param int    $reference_id  Source record ID.
	 * @param string $description   Human-readable description.
	 * @param bool   $force         If true, skip negative balance check.
	 * @return int|WP_Error The ledger entry ID, or WP_Error.
	 */
	private static function insert_entry( $affiliate_id, $amount, $entry_type, $reference_type, $reference_id, $description, $force = false ) {
		global $wpdb;

		$affiliate_id = absint( $affiliate_id );
		$amount       = self::normalize_amount( $amount );
		$table        = $wpdb->prefix . 'konx_wallet_ledger';
		$aff_table    = $wpdb->prefix . 'konx_affiliates';

		// Idempotency: check for duplicate entry.
		if ( $reference_id && self::entry_exists( $affiliate_id, $entry_type, $reference_type, $reference_id ) ) {
			return new \WP_Error(
				'duplicate_entry',
				__( 'A ledger entry already exists for this source.', 'konx-affiliate-dashboard' )
			);
		}

		// Begin transaction.
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		// Lock the affiliate row to serialize concurrent writes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affiliate_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, cached_balance FROM {$aff_table} WHERE id = %d FOR UPDATE",
				$affiliate_id
			)
		);

		if ( ! $affiliate_row ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new \WP_Error( 'affiliate_not_found', __( 'Affiliate not found.', 'konx-affiliate-dashboard' ) );
		}

		// Get the current authoritative balance under the lock.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$current_balance = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE affiliate_id = %d",
				$affiliate_id
			)
		);
		$current_balance = self::normalize_amount( $current_balance );

		// For debits: check that balance won't go negative.
		if ( ! $force && self::compare( $amount, '0.00' ) < 0 ) {
			$new_balance = self::add( $current_balance, $amount );
			if ( self::compare( $new_balance, '0.00' ) < 0 ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

				self::log_audit(
					'debit_failed',
					'affiliate',
					$affiliate_id,
					$current_balance,
					$amount,
					sprintf( 'Debit of %s rejected. Current balance: %s', $amount, $current_balance )
				);

				return new \WP_Error(
					'insufficient_balance',
					sprintf(
						/* translators: 1: current balance, 2: requested debit */
						__( 'Insufficient balance. Current: %1$s, Requested debit: %2$s', 'konx-affiliate-dashboard' ),
						$current_balance,
						ltrim( $amount, '-' )
					)
				);
			}
		}

		$running_balance = self::add( $current_balance, $amount );

		// Insert ledger entry.
		$entry_data = array(
			'affiliate_id'   => $affiliate_id,
			'entry_type'     => sanitize_text_field( $entry_type ),
			'amount'         => $amount,
			'running_balance' => $running_balance,
			'reference_type' => sanitize_text_field( $reference_type ),
			'description'    => sanitize_text_field( $description ),
			'created_by'     => get_current_user_id() ?: null,
			'created_at'     => current_time( 'mysql', true ),
		);
		$entry_formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

		if ( $reference_id ) {
			$entry_data['reference_id'] = absint( $reference_id );
			$entry_formats[]            = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( $table, $entry_data, $entry_formats );

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new \WP_Error( 'db_insert_failed', __( 'Failed to insert ledger entry.', 'konx-affiliate-dashboard' ) );
		}

		$entry_id = (int) $wpdb->insert_id;

		// Update cached balance atomically.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$aff_table} SET cached_balance = %s, updated_at = %s WHERE id = %d",
				$running_balance,
				current_time( 'mysql', true ),
				$affiliate_id
			)
		);

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		return $entry_id;
	}

	// ------------------------------------------------------------------
	// Decimal Arithmetic Helpers (String-Based)
	// ------------------------------------------------------------------

	/**
	 * Normalize a value to a 2-decimal string.
	 *
	 * @param mixed $value Numeric value.
	 * @return string Formatted as 'X.XX' or '-X.XX'.
	 */
	private static function normalize_amount( $value ) {
		if ( null === $value || '' === $value ) {
			return '0.00';
		}

		// Handle negative values.
		$is_negative = ( strpos( (string) $value, '-' ) === 0 );
		$abs_value   = ltrim( (string) $value, '-' );

		// Use number_format for consistent 2-decimal output.
		$formatted = number_format( (float) $abs_value, 2, '.', '' );

		return $is_negative ? '-' . $formatted : $formatted;
	}

	/**
	 * Add two decimal strings.
	 *
	 * Uses bcmath if available, otherwise falls back to float arithmetic
	 * with immediate normalization to minimize precision loss.
	 *
	 * @param string $a First value.
	 * @param string $b Second value.
	 * @return string Result as decimal string.
	 */
	private static function add( $a, $b ) {
		if ( function_exists( 'bcadd' ) ) {
			return bcadd( $a, $b, 2 );
		}
		return self::normalize_amount( (float) $a + (float) $b );
	}

	/**
	 * Subtract two decimal strings (a - b).
	 *
	 * @param string $a Minuend.
	 * @param string $b Subtrahend.
	 * @return string Result as decimal string.
	 */
	private static function subtract( $a, $b ) {
		if ( function_exists( 'bcsub' ) ) {
			return bcsub( $a, $b, 2 );
		}
		return self::normalize_amount( (float) $a - (float) $b );
	}

	/**
	 * Compare two decimal strings.
	 *
	 * @param string $a First value.
	 * @param string $b Second value.
	 * @return int -1 if a < b, 0 if equal, 1 if a > b.
	 */
	private static function compare( $a, $b ) {
		if ( function_exists( 'bccomp' ) ) {
			return bccomp( $a, $b, 2 );
		}
		$fa = (float) $a;
		$fb = (float) $b;
		if ( abs( $fa - $fb ) < 0.005 ) {
			return 0;
		}
		return $fa < $fb ? -1 : 1;
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
