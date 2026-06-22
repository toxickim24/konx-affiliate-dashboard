<?php
/**
 * Withdrawal request management.
 *
 * Handles the lifecycle of affiliate withdrawal requests from creation
 * through admin review to completion. Wallet is debited only when the
 * admin marks a withdrawal as completed (after paying via Wise).
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Withdrawals
 */
class Konx_Withdrawals {

	const STATUS_PENDING   = 'pending';
	const STATUS_APPROVED  = 'approved';
	const STATUS_REJECTED  = 'rejected';
	const STATUS_CANCELLED = 'cancelled';
	const STATUS_COMPLETED = 'completed';

	/**
	 * Default minimum withdrawal amount.
	 */
	const MIN_AMOUNT = '50.00';

	// ------------------------------------------------------------------
	// Request Creation
	// ------------------------------------------------------------------

	/**
	 * Create a new withdrawal request.
	 *
	 * Validates balance, minimum amount, and duplicate pending requests.
	 * Does NOT debit the wallet — that happens only on completion.
	 *
	 * @param int    $affiliate_id    Affiliate table ID.
	 * @param string $amount          Withdrawal amount as decimal string.
	 * @param string $payment_email   Wise payout email.
	 * @param string $account_holder  Account holder name for Wise.
	 * @param string $currency        Currency code (default USD).
	 * @param string $notes           Optional notes from the affiliate.
	 * @return int|WP_Error Withdrawal request ID, or WP_Error.
	 */
	public static function create_request( $affiliate_id, $amount, $payment_email, $account_holder = '', $currency = 'USD', $notes = '' ) {
		global $wpdb;

		$affiliate_id = absint( $affiliate_id );
		$amount       = number_format( (float) $amount, 2, '.', '' );

		// Validate affiliate exists.
		$affiliate = Konx_Affiliate_Manager::get_affiliate( $affiliate_id );
		if ( ! $affiliate ) {
			return new \WP_Error( 'invalid_affiliate', __( 'Affiliate not found.', 'konx-affiliate-dashboard' ) );
		}

		// Validate minimum amount.
		$min = self::get_minimum_amount();
		if ( self::compare( $amount, $min ) < 0 ) {
			return new \WP_Error(
				'below_minimum',
				sprintf(
					/* translators: %s: minimum withdrawal amount */
					__( 'Minimum withdrawal amount is $%s.', 'konx-affiliate-dashboard' ),
					$min
				)
			);
		}

		// Validate available balance.
		$balance = Konx_Wallet::get_available_balance( $affiliate_id );
		if ( self::compare( $amount, $balance ) > 0 ) {
			return new \WP_Error(
				'insufficient_balance',
				sprintf(
					/* translators: 1: requested amount, 2: available balance */
					__( 'Insufficient balance. Requested: $%1$s, Available: $%2$s.', 'konx-affiliate-dashboard' ),
					$amount,
					$balance
				)
			);
		}

		// Check for existing pending/approved request.
		$can = self::can_request_withdrawal( $affiliate_id );
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		// Validate Wise details.
		$validation = self::validate_wise_details( $payment_email );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$table = $wpdb->prefix . 'konx_withdrawals';
		$now   = current_time( 'mysql', true );

		// Build payment_email field: include account holder if provided.
		$wise_email = sanitize_email( $payment_email );

		// Store account holder, currency, and notes in admin_note as structured data
		// since the table doesn't have separate columns for these.
		$structured_notes = '';
		if ( ! empty( $account_holder ) ) {
			$structured_notes .= 'Account Holder: ' . sanitize_text_field( $account_holder ) . "\n";
		}
		if ( ! empty( $currency ) ) {
			$structured_notes .= 'Currency: ' . sanitize_text_field( $currency ) . "\n";
		}
		if ( ! empty( $notes ) ) {
			$structured_notes .= 'Notes: ' . sanitize_textarea_field( $notes );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'affiliate_id'   => $affiliate_id,
				'amount'         => $amount,
				'payment_method' => 'wise',
				'payment_email'  => $wise_email,
				'status'         => self::STATUS_PENDING,
				'admin_note'     => ! empty( $structured_notes ) ? $structured_notes : null,
				'requested_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'db_insert_failed', __( 'Failed to create withdrawal request.', 'konx-affiliate-dashboard' ) );
		}

		$request_id = (int) $wpdb->insert_id;

		self::log_audit(
			'withdrawal_created',
			'withdrawal',
			$request_id,
			null,
			$amount,
			sprintf( 'Withdrawal request of $%s created by affiliate #%d.', $amount, $affiliate_id )
		);

		return $request_id;
	}

	// ------------------------------------------------------------------
	// Status Transitions
	// ------------------------------------------------------------------

	/**
	 * Approve a pending withdrawal request.
	 *
	 * Does NOT debit the wallet. This signals the admin will pay via Wise.
	 *
	 * @param int    $request_id Withdrawal request ID.
	 * @param string $admin_note Optional admin note.
	 * @return bool|WP_Error
	 */
	public static function approve_request( $request_id, $admin_note = '' ) {
		return self::transition_status( $request_id, self::STATUS_PENDING, self::STATUS_APPROVED, $admin_note );
	}

	/**
	 * Reject a pending or approved withdrawal request.
	 *
	 * @param int    $request_id Withdrawal request ID.
	 * @param string $reason     Rejection reason (shown to affiliate).
	 * @return bool|WP_Error
	 */
	public static function reject_request( $request_id, $reason = '' ) {
		if ( empty( $reason ) ) {
			return new \WP_Error( 'reason_required', __( 'A rejection reason is required.', 'konx-affiliate-dashboard' ) );
		}
		return self::transition_status( $request_id, null, self::STATUS_REJECTED, $reason );
	}

	/**
	 * Cancel a pending or approved withdrawal request.
	 *
	 * @param int    $request_id Withdrawal request ID.
	 * @param string $admin_note Optional note.
	 * @return bool|WP_Error
	 */
	public static function cancel_request( $request_id, $admin_note = '' ) {
		return self::transition_status( $request_id, null, self::STATUS_CANCELLED, $admin_note );
	}

	/**
	 * Complete a withdrawal request.
	 *
	 * Re-validates the affiliate's balance before debiting the wallet.
	 * If balance is insufficient, blocks completion and returns WP_Error.
	 *
	 * @param int    $request_id           Withdrawal request ID.
	 * @param string $transaction_reference Wise transaction reference.
	 * @param string $admin_note           Optional admin note.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function complete_request( $request_id, $transaction_reference = '', $admin_note = '' ) {
		global $wpdb;

		$request = self::get_request( $request_id );
		if ( ! $request ) {
			return new \WP_Error( 'not_found', __( 'Withdrawal request not found.', 'konx-affiliate-dashboard' ) );
		}

		// Only pending or approved requests can be completed.
		if ( ! in_array( $request->status, array( self::STATUS_PENDING, self::STATUS_APPROVED ), true ) ) {
			return new \WP_Error( 'invalid_status', __( 'This request cannot be completed in its current status.', 'konx-affiliate-dashboard' ) );
		}

		// Re-validate balance before debiting.
		$balance = Konx_Wallet::get_available_balance( (int) $request->affiliate_id );
		$amount  = number_format( (float) $request->amount, 2, '.', '' );

		if ( self::compare( $amount, $balance ) > 0 ) {
			self::log_audit(
				'withdrawal_completion_failed',
				'withdrawal',
				$request_id,
				$balance,
				$amount,
				sprintf( 'Completion blocked: balance $%s < withdrawal $%s.', $balance, $amount )
			);

			return new \WP_Error(
				'insufficient_balance',
				sprintf(
					/* translators: 1: current balance, 2: withdrawal amount */
					__( 'Cannot complete. Balance is $%1$s, withdrawal is $%2$s.', 'konx-affiliate-dashboard' ),
					$balance,
					$amount
				)
			);
		}

		// Debit the wallet.
		$ledger_result = Konx_Wallet::debit(
			(int) $request->affiliate_id,
			$amount,
			Konx_Wallet::TYPE_WITHDRAWAL,
			Konx_Wallet::REF_WITHDRAWAL,
			$request_id,
			sprintf( 'Withdrawal #%d completed via Wise.', $request_id )
		);

		if ( is_wp_error( $ledger_result ) ) {
			self::log_audit(
				'withdrawal_debit_failed',
				'withdrawal',
				$request_id,
				null,
				$ledger_result->get_error_message(),
				sprintf( 'Wallet debit failed for withdrawal #%d: %s', $request_id, $ledger_result->get_error_message() )
			);
			return $ledger_result;
		}

		// Update the withdrawal record.
		$table = $wpdb->prefix . 'konx_withdrawals';
		$now   = current_time( 'mysql', true );

		$update_data = array(
			'status'         => self::STATUS_COMPLETED,
			'admin_user_id'  => get_current_user_id(),
			'ledger_entry_id' => (int) $ledger_result,
			'processed_at'   => $now,
		);
		$update_formats = array( '%s', '%d', '%d', '%s' );

		if ( ! empty( $transaction_reference ) ) {
			$update_data['transaction_reference'] = sanitize_text_field( $transaction_reference );
			$update_formats[]                     = '%s';
		}

		if ( ! empty( $admin_note ) ) {
			$existing_note = $request->admin_note ? $request->admin_note . "\n" : '';
			$update_data['admin_note'] = $existing_note . 'Completed: ' . sanitize_textarea_field( $admin_note );
			$update_formats[]          = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, $update_data, array( 'id' => $request_id ), $update_formats, array( '%d' ) );

		self::log_audit(
			'withdrawal_completed',
			'withdrawal',
			$request_id,
			$request->status,
			self::STATUS_COMPLETED,
			sprintf( 'Withdrawal #%d completed. Amount: $%s. Wise ref: %s.', $request_id, $amount, $transaction_reference ?: 'N/A' )
		);

		return true;
	}

	// ------------------------------------------------------------------
	// Queries
	// ------------------------------------------------------------------

	/**
	 * Get a single withdrawal request.
	 *
	 * @param int $request_id Request ID.
	 * @return object|null
	 */
	public static function get_request( $request_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_withdrawals';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $request_id ) )
		);
	}

	/**
	 * Get paginated withdrawal requests with optional filters.
	 *
	 * @param array $args {
	 *     @type int    $affiliate_id Filter by affiliate.
	 *     @type string $status       Filter by status.
	 *     @type string $search       Search term.
	 *     @type int    $page         Page number.
	 *     @type int    $per_page     Items per page.
	 * }
	 * @return array { entries, total, pages }
	 */
	public static function get_requests( $args = array() ) {
		global $wpdb;

		$table     = $wpdb->prefix . 'konx_withdrawals';
		$aff_table = $wpdb->prefix . 'konx_affiliates';

		$defaults = array(
			'affiliate_id' => 0,
			'status'       => '',
			'search'       => '',
			'page'         => 1,
			'per_page'     => 20,
		);
		$args = wp_parse_args( $args, $defaults );

		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = 'WHERE 1=1';
		$params = array();

		if ( $args['affiliate_id'] ) {
			$where   .= ' AND w.affiliate_id = %d';
			$params[] = absint( $args['affiliate_id'] );
		}

		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND w.status = %s';
			$params[] = sanitize_text_field( $args['status'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where   .= ' AND (a.referral_code LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$count_sql = "SELECT COUNT(*)
			FROM {$table} w
			INNER JOIN {$aff_table} a ON w.affiliate_id = a.id
			INNER JOIN {$wpdb->users} u ON a.user_id = u.ID
			{$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = empty( $params )
			? (int) $wpdb->get_var( $count_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		$select_params   = $params;
		$select_params[] = $per_page;
		$select_params[] = $offset;

		$select_sql = "SELECT w.*, a.referral_code, a.affiliate_type, a.user_id AS aff_user_id,
				a.cached_balance, u.display_name, u.user_email
			FROM {$table} w
			INNER JOIN {$aff_table} a ON w.affiliate_id = a.id
			INNER JOIN {$wpdb->users} u ON a.user_id = u.ID
			{$where}
			ORDER BY w.requested_at DESC
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

	/**
	 * Check if an affiliate can submit a new withdrawal request.
	 *
	 * Returns WP_Error if a pending or approved request already exists.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 * @return true|WP_Error
	 */
	public static function can_request_withdrawal( $affiliate_id ) {
		$existing = self::get_user_pending_request( $affiliate_id );
		if ( $existing ) {
			return new \WP_Error(
				'existing_request',
				__( 'You already have a pending or approved withdrawal request. Please wait until it is processed.', 'konx-affiliate-dashboard' )
			);
		}
		return true;
	}

	/**
	 * Get the affiliate's pending or approved withdrawal request.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 * @return object|null The request row, or null.
	 */
	public static function get_user_pending_request( $affiliate_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_withdrawals';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE affiliate_id = %d AND status IN (%s, %s) LIMIT 1",
				absint( $affiliate_id ),
				self::STATUS_PENDING,
				self::STATUS_APPROVED
			)
		);
	}

	// ------------------------------------------------------------------
	// Validation
	// ------------------------------------------------------------------

	/**
	 * Validate Wise payment details.
	 *
	 * @param string $email Wise email address.
	 * @return true|WP_Error
	 */
	public static function validate_wise_details( $email ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'A valid Wise email address is required.', 'konx-affiliate-dashboard' ) );
		}
		return true;
	}

	/**
	 * Get the minimum withdrawal amount.
	 *
	 * @return string Minimum as decimal string.
	 */
	public static function get_minimum_amount() {
		$settings = get_option( 'konx_affiliate_settings', array() );
		$min      = ! empty( $settings['min_withdrawal'] ) ? $settings['min_withdrawal'] : self::MIN_AMOUNT;
		return number_format( (float) $min, 2, '.', '' );
	}

	// ------------------------------------------------------------------
	// Private Helpers
	// ------------------------------------------------------------------

	/**
	 * Transition a request to a new status.
	 *
	 * @param int         $request_id  Request ID.
	 * @param string|null $from_status Required current status (null = any active).
	 * @param string      $to_status   Target status.
	 * @param string      $admin_note  Admin note.
	 * @return bool|WP_Error
	 */
	private static function transition_status( $request_id, $from_status, $to_status, $admin_note = '' ) {
		global $wpdb;

		$request = self::get_request( $request_id );
		if ( ! $request ) {
			return new \WP_Error( 'not_found', __( 'Withdrawal request not found.', 'konx-affiliate-dashboard' ) );
		}

		// Validate current status.
		$active_statuses = array( self::STATUS_PENDING, self::STATUS_APPROVED );
		if ( ! in_array( $request->status, $active_statuses, true ) ) {
			return new \WP_Error( 'invalid_status', __( 'This request is already resolved.', 'konx-affiliate-dashboard' ) );
		}

		if ( $from_status && $request->status !== $from_status ) {
			return new \WP_Error(
				'wrong_status',
				sprintf(
					/* translators: 1: expected status, 2: actual status */
					__( 'Expected status "%1$s" but found "%2$s".', 'konx-affiliate-dashboard' ),
					$from_status,
					$request->status
				)
			);
		}

		$old_status = $request->status;
		$table      = $wpdb->prefix . 'konx_withdrawals';
		$now        = current_time( 'mysql', true );

		$data    = array(
			'status'        => $to_status,
			'admin_user_id' => get_current_user_id(),
			'processed_at'  => $now,
		);
		$formats = array( '%s', '%d', '%s' );

		if ( ! empty( $admin_note ) ) {
			$existing_note     = $request->admin_note ? $request->admin_note . "\n" : '';
			$data['admin_note'] = $existing_note . ucfirst( $to_status ) . ': ' . sanitize_textarea_field( $admin_note );
			$formats[]         = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update( $table, $data, array( 'id' => $request_id ), $formats, array( '%d' ) );

		if ( false === $updated ) {
			return new \WP_Error( 'db_update_failed', __( 'Failed to update withdrawal status.', 'konx-affiliate-dashboard' ) );
		}

		self::log_audit(
			'withdrawal_' . $to_status,
			'withdrawal',
			$request_id,
			$old_status,
			$to_status,
			sprintf( 'Withdrawal #%d: %s -> %s.', $request_id, $old_status, $to_status )
		);

		return true;
	}

	/**
	 * Compare two decimal strings.
	 *
	 * @param string $a First value.
	 * @param string $b Second value.
	 * @return int -1, 0, or 1.
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
