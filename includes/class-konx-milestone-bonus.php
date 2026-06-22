<?php
/**
 * Milestone bonus engine.
 *
 * Awards a bonus equal to the total approved commission earned from
 * each 100-sale block. Triggers after every commission wallet credit
 * by checking the affiliate's completed_sales count.
 *
 * Block boundaries use sale_sequence:
 *   Milestone 1: sequence 1–100
 *   Milestone 2: sequence 101–200
 *   Milestone 3: sequence 201–300
 *   ...
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Milestone_Bonus
 */
class Konx_Milestone_Bonus {

	/**
	 * Number of sales per milestone block.
	 */
	const BLOCK_SIZE = 100;

	// ------------------------------------------------------------------
	// Main Entry Point
	// ------------------------------------------------------------------

	/**
	 * Check for any unawarded milestones and award them.
	 *
	 * Scans from milestone 1 up to the highest eligible milestone
	 * based on completed_sales, awarding any that were missed.
	 * This handles the case where the Nth sale was blocked and
	 * the milestone wasn't triggered at the exact boundary.
	 *
	 * Called by commission engines after a successful wallet credit.
	 * Safe to call multiple times — idempotent via unique index.
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 */
	public static function maybe_award_bonus( $affiliate_id ) {
		$affiliate_id = absint( $affiliate_id );

		$affiliate = Konx_Affiliate_Manager::get_affiliate( $affiliate_id );
		if ( ! $affiliate ) {
			return;
		}

		$sales = (int) $affiliate->completed_sales;
		if ( $sales < self::BLOCK_SIZE ) {
			return; // Not yet at the first milestone.
		}

		// The highest milestone number this affiliate is eligible for.
		$max_milestone = (int) floor( $sales / self::BLOCK_SIZE );

		// Check admin fee eligibility once for all milestones in this call.
		$can_earn = Konx_Admin_Fees::can_affiliate_earn( $affiliate_id );

		// Walk from milestone 1 to max, awarding any that are missing.
		for ( $m = 1; $m <= $max_milestone; $m++ ) {
			// Idempotency: skip if already awarded.
			if ( self::has_bonus_for_milestone( $affiliate_id, $m ) ) {
				continue;
			}

			self::award_single_milestone( $affiliate_id, $m, $sales, $can_earn );
		}
	}

	/**
	 * Award a single milestone bonus.
	 *
	 * @param int  $affiliate_id    Affiliate ID.
	 * @param int  $milestone_number Milestone number to award.
	 * @param int  $current_sales   Current completed_sales count.
	 * @param bool $can_earn        Whether admin fees are paid.
	 */
	private static function award_single_milestone( $affiliate_id, $milestone_number, $current_sales, $can_earn ) {
		$block        = self::get_milestone_block( $milestone_number );
		$bonus_amount = self::calculate_bonus_amount( $affiliate_id, $block['start'], $block['end'] );

		if ( '0.00' === $bonus_amount ) {
			self::log_audit(
				'milestone_skipped',
				'affiliate',
				$affiliate_id,
				null,
				(string) $milestone_number,
				sprintf( 'Milestone #%d skipped: no approved commissions in block %d–%d.', $milestone_number, $block['start'], $block['end'] )
			);
			return;
		}

		$status = $can_earn ? 'approved' : 'blocked';

		$milestone_id = self::create_bonus_record(
			$affiliate_id,
			$milestone_number,
			$current_sales,
			$block['start'],
			$block['end'],
			$bonus_amount,
			$status
		);

		if ( is_wp_error( $milestone_id ) ) {
			return;
		}

		if ( 'approved' === $status ) {
			self::credit_wallet( $affiliate_id, $milestone_id, $bonus_amount, $milestone_number );
		}

		self::log_audit(
			'milestone_bonus_awarded',
			'milestone',
			$milestone_id,
			null,
			$bonus_amount,
			sprintf(
				'Milestone #%d awarded to affiliate #%d. Block %d–%d, bonus: %s, status: %s.',
				$milestone_number,
				$affiliate_id,
				$block['start'],
				$block['end'],
				$bonus_amount,
				$status
			)
		);
	}

	// ------------------------------------------------------------------
	// Block Calculations
	// ------------------------------------------------------------------

	/**
	 * Get the current milestone number for an affiliate.
	 *
	 * Returns the highest milestone that should exist given the
	 * completed sales count (whether or not it has been awarded yet).
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 * @return int The milestone number (0 if under 100 sales).
	 */
	public static function get_current_milestone( $affiliate_id ) {
		$affiliate = Konx_Affiliate_Manager::get_affiliate( absint( $affiliate_id ) );
		if ( ! $affiliate ) {
			return 0;
		}
		return (int) floor( (int) $affiliate->completed_sales / self::BLOCK_SIZE );
	}

	/**
	 * Get the sale_sequence block boundaries for a milestone number.
	 *
	 * @param int $milestone_number The milestone number (1, 2, 3, ...).
	 * @return array { start: int, end: int }
	 */
	public static function get_milestone_block( $milestone_number ) {
		$milestone_number = max( 1, (int) $milestone_number );
		$end   = $milestone_number * self::BLOCK_SIZE;
		$start = $end - self::BLOCK_SIZE + 1;

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Sum approved commission amounts in a sale_sequence range.
	 *
	 * Only includes commissions with status 'approved'. Blocked,
	 * reversed, pending, and cancelled commissions are excluded.
	 *
	 * @param int $affiliate_id   The affiliate table ID.
	 * @param int $start_sequence First sale_sequence in the block.
	 * @param int $end_sequence   Last sale_sequence in the block.
	 * @return string Total commission as a decimal string.
	 */
	public static function calculate_bonus_amount( $affiliate_id, $start_sequence, $end_sequence ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_commissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(commission_amount), 0) FROM {$table}
				WHERE affiliate_id = %d
				  AND sale_sequence BETWEEN %d AND %d
				  AND status = 'approved'",
				absint( $affiliate_id ),
				absint( $start_sequence ),
				absint( $end_sequence )
			)
		);

		return number_format( (float) $total, 2, '.', '' );
	}

	/**
	 * Get progress toward the next milestone.
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 * @return array {
	 *     @type int    $completed_sales     Total completed sales.
	 *     @type int    $next_milestone_at   Sales count for next milestone.
	 *     @type int    $sales_remaining     Sales needed to reach next milestone.
	 *     @type int    $sales_in_block      Sales completed in current block.
	 *     @type float  $percent_complete    Percentage of current block completed.
	 *     @type int    $milestones_achieved Total milestones awarded so far.
	 * }
	 */
	public static function get_progress_to_next_milestone( $affiliate_id ) {
		$affiliate = Konx_Affiliate_Manager::get_affiliate( absint( $affiliate_id ) );
		$sales     = $affiliate ? (int) $affiliate->completed_sales : 0;

		$current_milestone  = (int) floor( $sales / self::BLOCK_SIZE );
		$next_milestone_at  = ( $current_milestone + 1 ) * self::BLOCK_SIZE;
		$sales_in_block     = $sales - ( $current_milestone * self::BLOCK_SIZE );
		$sales_remaining    = $next_milestone_at - $sales;
		$percent            = self::BLOCK_SIZE > 0 ? round( ( $sales_in_block / self::BLOCK_SIZE ) * 100, 1 ) : 0;

		return array(
			'completed_sales'     => $sales,
			'next_milestone_at'   => $next_milestone_at,
			'sales_remaining'     => $sales_remaining,
			'sales_in_block'      => $sales_in_block,
			'percent_complete'    => $percent,
			'milestones_achieved' => $current_milestone,
		);
	}

	// ------------------------------------------------------------------
	// Record Creation
	// ------------------------------------------------------------------

	/**
	 * Create a milestone bonus record.
	 *
	 * Idempotent via UNIQUE KEY uq_affiliate_milestone.
	 *
	 * @param int    $affiliate_id    Affiliate ID.
	 * @param int    $milestone_number Milestone number (1, 2, ...).
	 * @param int    $sale_count      Sale count that triggered the milestone.
	 * @param int    $block_start     First sale_sequence in the block.
	 * @param int    $block_end       Last sale_sequence in the block.
	 * @param string $bonus_amount    Bonus amount as decimal string.
	 * @param string $status          'approved' or 'blocked'.
	 * @return int|WP_Error Milestone record ID, or WP_Error.
	 */
	private static function create_bonus_record( $affiliate_id, $milestone_number, $sale_count, $block_start, $block_end, $bonus_amount, $status ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_milestones';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'affiliate_id'              => absint( $affiliate_id ),
				'milestone_number'          => absint( $milestone_number ),
				'sale_count_at_trigger'     => absint( $sale_count ),
				'sale_block_start'          => absint( $block_start ),
				'sale_block_end'            => absint( $block_end ),
				'total_commissions_in_block' => $bonus_amount,
				'bonus_amount'              => $bonus_amount,
				'status'                    => $status,
				'created_at'                => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'db_insert_failed', __( 'Failed to create milestone record.', 'konx-affiliate-dashboard' ) );
		}

		return (int) $wpdb->insert_id;
	}

	// ------------------------------------------------------------------
	// Wallet Credit
	// ------------------------------------------------------------------

	/**
	 * Credit the wallet for an approved milestone bonus.
	 *
	 * @param int    $affiliate_id    Affiliate ID.
	 * @param int    $milestone_id    Milestone record ID.
	 * @param string $bonus_amount    Bonus as decimal string.
	 * @param int    $milestone_number Milestone number for description.
	 */
	private static function credit_wallet( $affiliate_id, $milestone_id, $bonus_amount, $milestone_number ) {
		global $wpdb;

		$result = Konx_Wallet::credit(
			$affiliate_id,
			$bonus_amount,
			Konx_Wallet::TYPE_MILESTONE_BONUS,
			Konx_Wallet::REF_MILESTONE,
			$milestone_id,
			sprintf( 'Milestone #%d bonus (100-sale block)', $milestone_number )
		);

		if ( is_wp_error( $result ) ) {
			self::log_audit(
				'wallet_credit_failed',
				'milestone',
				$milestone_id,
				null,
				$result->get_error_message(),
				sprintf( 'Milestone bonus wallet credit failed: %s', $result->get_error_message() )
			);
			return;
		}

		// Store ledger entry ID on milestone record.
		$table = $wpdb->prefix . 'konx_milestones';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'ledger_entry_id' => (int) $result ),
			array( 'id' => $milestone_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	// ------------------------------------------------------------------
	// Queries
	// ------------------------------------------------------------------

	/**
	 * Check if a milestone bonus already exists.
	 *
	 * @param int $affiliate_id    Affiliate ID.
	 * @param int $milestone_number Milestone number.
	 * @return bool
	 */
	public static function has_bonus_for_milestone( $affiliate_id, $milestone_number ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_milestones';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE affiliate_id = %d AND milestone_number = %d",
				absint( $affiliate_id ),
				absint( $milestone_number )
			)
		);

		return $count > 0;
	}

	/**
	 * Get paginated bonus history for an affiliate.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 * @param int $page         Page number.
	 * @param int $per_page     Per page.
	 * @return array { entries, total, pages }
	 */
	public static function get_bonus_history( $affiliate_id, $page = 1, $per_page = 20 ) {
		global $wpdb;

		$table        = $wpdb->prefix . 'konx_milestones';
		$affiliate_id = absint( $affiliate_id );
		$page         = max( 1, (int) $page );
		$per_page     = max( 1, min( 100, (int) $per_page ) );
		$offset       = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE affiliate_id = %d",
				$affiliate_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE affiliate_id = %d ORDER BY milestone_number DESC LIMIT %d OFFSET %d",
				$affiliate_id,
				$per_page,
				$offset
			)
		);

		return array(
			'entries' => $entries ? $entries : array(),
			'total'   => $total,
			'pages'   => (int) ceil( $total / $per_page ),
		);
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
