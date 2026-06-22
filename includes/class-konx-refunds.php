<?php
/**
 * Refund and commission reversal handling.
 *
 * Detects WooCommerce refunds, cancellations, and failures, then
 * reverses the associated commission wallet credits. Commission
 * records are marked as reversed but never deleted.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Refunds
 */
class Konx_Refunds {

	/**
	 * Register WooCommerce hooks.
	 */
	public static function init() {
		// Full or partial refund.
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'process_refund' ), 10, 2 );

		// Order status changes to cancelled/failed after commissions were created.
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'process_cancelled_order' ) );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'process_cancelled_order' ) );
	}

	// ------------------------------------------------------------------
	// Refund Processing
	// ------------------------------------------------------------------

	/**
	 * Handle a WooCommerce refund event.
	 *
	 * Fires on `woocommerce_order_refunded`. For full refunds, reverses
	 * all approved commissions. For partial refunds, reverses proportionally
	 * based on the refund amount relative to the order subtotal.
	 *
	 * @param int $order_id  The original order ID.
	 * @param int $refund_id The refund order ID.
	 */
	public static function process_refund( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Only process if this order has commissions.
		$commissions = Konx_Commission_Engine::get_commissions_for_order( $order_id );
		if ( empty( $commissions ) ) {
			return;
		}

		$refund = wc_get_order( $refund_id );
		if ( ! $refund ) {
			return;
		}

		// Determine if full or partial.
		$order_subtotal  = (float) $order->get_subtotal();
		$refund_amount   = abs( (float) $refund->get_total() );
		$remaining_total = (float) $order->get_total();

		// Full refund: remaining order total is 0 or refund >= subtotal.
		$is_full_refund = ( $remaining_total <= 0.005 ) || ( $refund_amount >= $order_subtotal - 0.005 );

		if ( $is_full_refund ) {
			self::reverse_order_commissions( $commissions, 'Full refund on order #' . $order_id );
		} else {
			self::process_partial_refund( $commissions, $order, $refund );
		}

		self::log_audit(
			'refund_processed',
			'order',
			$order_id,
			null,
			number_format( $refund_amount, 2, '.', '' ),
			sprintf(
				'%s refund of $%s processed for order #%d. Refund #%d.',
				$is_full_refund ? 'Full' : 'Partial',
				number_format( $refund_amount, 2 ),
				$order_id,
				$refund_id
			)
		);
	}

	/**
	 * Handle a cancelled or failed order.
	 *
	 * Reverses all approved commissions. Blocked commissions are
	 * marked as reversed without wallet entries.
	 *
	 * @param int $order_id The order ID.
	 */
	public static function process_cancelled_order( $order_id ) {
		$commissions = Konx_Commission_Engine::get_commissions_for_order( $order_id );
		if ( empty( $commissions ) ) {
			return;
		}

		self::reverse_order_commissions(
			$commissions,
			sprintf( 'Order #%d cancelled/failed.', $order_id )
		);

		self::log_audit(
			'order_cancelled_reversal',
			'order',
			$order_id,
			null,
			null,
			sprintf( 'Commissions reversed for cancelled/failed order #%d.', $order_id )
		);
	}

	// ------------------------------------------------------------------
	// Reversal Logic
	// ------------------------------------------------------------------

	/**
	 * Reverse all eligible commissions for an order.
	 *
	 * @param array  $commissions Array of commission row objects.
	 * @param string $reason      Human-readable reason for the reversal.
	 */
	public static function reverse_order_commissions( $commissions, $reason ) {
		foreach ( $commissions as $commission ) {
			self::reverse_commission( $commission, $reason );
		}
	}

	/**
	 * Reverse a single commission.
	 *
	 * - If status is 'approved': mark reversed + create wallet reversal entry.
	 * - If status is 'blocked': mark reversed only (never credited, no wallet entry).
	 * - If status is 'reversed' or 'pending': skip (idempotent).
	 *
	 * @param object $commission The commission row.
	 * @param string $reason     Human-readable reason.
	 * @return bool True if reversed, false if skipped.
	 */
	public static function reverse_commission( $commission, $reason ) {
		// Idempotency: skip already-reversed commissions.
		if ( Konx_Commission_Engine::STATUS_REVERSED === $commission->status ) {
			return false;
		}

		// Skip pending commissions (order never completed, no credit issued).
		if ( Konx_Commission_Engine::STATUS_PENDING === $commission->status ) {
			self::mark_commission_reversed( (int) $commission->id );
			return true;
		}

		$was_approved = ( Konx_Commission_Engine::STATUS_APPROVED === $commission->status );
		$was_blocked  = ( Konx_Commission_Engine::STATUS_BLOCKED === $commission->status );

		// Mark the commission record as reversed.
		self::mark_commission_reversed( (int) $commission->id );

		// Create wallet reversal only if the commission was actually credited.
		if ( $was_approved && $commission->ledger_entry_id ) {
			$amount = number_format( (float) $commission->commission_amount, 2, '.', '' );

			$result = Konx_Wallet::reverse(
				(int) $commission->affiliate_id,
				$amount,
				(int) $commission->id,
				$reason
			);

			if ( is_wp_error( $result ) ) {
				self::log_audit(
					'reversal_wallet_failed',
					'commission',
					(int) $commission->id,
					null,
					$result->get_error_message(),
					sprintf( 'Wallet reversal failed for commission #%d: %s', $commission->id, $result->get_error_message() )
				);
			} else {
				self::log_audit(
					'commission_reversed',
					'commission',
					(int) $commission->id,
					$commission->status,
					Konx_Commission_Engine::STATUS_REVERSED,
					sprintf( 'Commission #%d reversed ($%s). %s', $commission->id, $amount, $reason )
				);
			}
		} elseif ( $was_blocked ) {
			self::log_audit(
				'blocked_commission_cancelled',
				'commission',
				(int) $commission->id,
				$commission->status,
				Konx_Commission_Engine::STATUS_REVERSED,
				sprintf( 'Blocked commission #%d cancelled (no wallet entry). %s', $commission->id, $reason )
			);
		}

		return true;
	}

	/**
	 * Mark a commission record as reversed in the database.
	 *
	 * @param int $commission_id The commission ID.
	 */
	public static function mark_commission_reversed( $commission_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_commissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'     => Konx_Commission_Engine::STATUS_REVERSED,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $commission_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	// ------------------------------------------------------------------
	// Partial Refund
	// ------------------------------------------------------------------

	/**
	 * Process a partial refund.
	 *
	 * Attempts item-level matching first: if the refund contains specific
	 * refunded items, reverses only those commissions. If item-level
	 * matching fails, falls back to proportional reversal.
	 *
	 * @param array    $commissions All commissions for the order.
	 * @param WC_Order $order       The original order.
	 * @param WC_Order $refund      The refund order.
	 */
	private static function process_partial_refund( $commissions, $order, $refund ) {
		// Try item-level matching.
		$refund_items    = $refund->get_items();
		$matched_any     = false;

		if ( ! empty( $refund_items ) ) {
			foreach ( $refund_items as $refund_item ) {
				$product_id = $refund_item->get_product_id();
				$qty_refunded = abs( $refund_item->get_quantity() );

				if ( $qty_refunded <= 0 ) {
					continue;
				}

				// Find matching commissions by product_id.
				foreach ( $commissions as $commission ) {
					if ( Konx_Commission_Engine::STATUS_REVERSED === $commission->status ) {
						continue;
					}

					if ( (int) $commission->product_id === $product_id ||
						 (int) $commission->product_id === $refund_item->get_variation_id() ) {
						self::reverse_commission(
							$commission,
							sprintf( 'Partial refund: product #%d refunded.', $product_id )
						);
						$matched_any = true;
						break; // One commission per refunded product match.
					}
				}
			}
		}

		// Fallback: proportional reversal if no item-level match.
		if ( ! $matched_any ) {
			self::process_proportional_reversal( $commissions, $order, $refund );
		}
	}

	/**
	 * Proportional reversal fallback for partial refunds.
	 *
	 * Calculates the refund ratio (refund_amount / order_subtotal) and
	 * applies it to each approved commission that hasn't been reversed.
	 *
	 * This creates a partial wallet reversal for each commission.
	 *
	 * @param array    $commissions All commissions for the order.
	 * @param WC_Order $order       The original order.
	 * @param WC_Order $refund      The refund order.
	 */
	private static function process_proportional_reversal( $commissions, $order, $refund ) {
		$order_subtotal = (float) $order->get_subtotal();
		$refund_amount  = abs( (float) $refund->get_total() );

		if ( $order_subtotal <= 0 ) {
			return;
		}

		// Ratio of refund to order subtotal.
		$ratio = $refund_amount / $order_subtotal;
		if ( $ratio > 1.0 ) {
			$ratio = 1.0;
		}

		foreach ( $commissions as $commission ) {
			if ( Konx_Commission_Engine::STATUS_REVERSED === $commission->status ) {
				continue;
			}
			if ( Konx_Commission_Engine::STATUS_APPROVED !== $commission->status ) {
				continue;
			}
			if ( ! $commission->ledger_entry_id ) {
				continue;
			}

			// Check idempotency — don't reverse if already reversed for this commission.
			if ( self::has_reversal( (int) $commission->id ) ) {
				continue;
			}

			$partial_amount = self::calculate_partial_reversal_amount(
				$commission->commission_amount,
				$ratio
			);

			if ( '0.00' === $partial_amount ) {
				continue;
			}

			// Create a partial wallet reversal (don't fully mark commission as reversed).
			$result = Konx_Wallet::reverse(
				(int) $commission->affiliate_id,
				$partial_amount,
				(int) $commission->id,
				sprintf( 'Proportional reversal (%.0f%% of order refunded).', $ratio * 100 )
			);

			if ( ! is_wp_error( $result ) ) {
				self::log_audit(
					'partial_reversal',
					'commission',
					(int) $commission->id,
					$commission->commission_amount,
					$partial_amount,
					sprintf(
						'Partial reversal of $%s (%.0f%%) on commission #%d.',
						$partial_amount,
						$ratio * 100,
						$commission->id
					)
				);
			}
		}
	}

	/**
	 * Calculate the proportional reversal amount.
	 *
	 * @param string $commission_amount The original commission amount.
	 * @param float  $ratio             The refund ratio (0.0 to 1.0).
	 * @return string The reversal amount as a decimal string.
	 */
	public static function calculate_partial_reversal_amount( $commission_amount, $ratio ) {
		if ( function_exists( 'bcmul' ) ) {
			$result = bcmul( $commission_amount, number_format( $ratio, 4, '.', '' ), 2 );
		} else {
			$result = number_format( (float) $commission_amount * $ratio, 2, '.', '' );
		}
		return $result;
	}

	// ------------------------------------------------------------------
	// Queries
	// ------------------------------------------------------------------

	/**
	 * Check if a reversal ledger entry exists for a commission.
	 *
	 * @param int $commission_id The commission ID.
	 * @return bool True if a reversal entry exists.
	 */
	public static function has_reversal( $commission_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_wallet_ledger';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE entry_type = %s AND reference_type = %s AND reference_id = %d",
				Konx_Wallet::TYPE_REVERSAL,
				Konx_Wallet::REF_COMMISSION,
				absint( $commission_id )
			)
		);

		return $count > 0;
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
