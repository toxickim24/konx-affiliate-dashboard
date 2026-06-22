<?php
/**
 * Recurring subscription commission engine.
 *
 * Listens for YITH WooCommerce Subscription renewal payments and
 * awards a flat 10% recurring commission to the affiliate who
 * originally referred the subscriber.
 *
 * Attribution is persistent: the original referrer is credited for
 * every renewal of the same subscription, regardless of cookie state.
 *
 * Commission base is the renewal line item subtotal BEFORE discounts,
 * coupons, taxes, and payment gateway fees ($item->get_subtotal()).
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Recurring_Commission_Engine
 */
class Konx_Recurring_Commission_Engine {

	/**
	 * Default recurring commission rate (used if settings not configured).
	 */
	const DEFAULT_RECURRING_RATE = '0.1000';

	/**
	 * Register hooks only if YITH Subscription is active.
	 */
	public static function init() {
		if ( ! konx_affiliate_is_yith_active() ) {
			return;
		}

		// YITH fires this when a renewal order's payment succeeds.
		add_action( 'ywsbs_renew_order_payed', array( __CLASS__, 'process_renewal_order' ), 10, 2 );
	}

	// ------------------------------------------------------------------
	// Renewal Processing
	// ------------------------------------------------------------------

	/**
	 * Process a YITH subscription renewal order for recurring commission.
	 *
	 * @param int $renewal_order_id The renewal WooCommerce order ID.
	 * @param int $subscription_id  The YITH subscription ID.
	 */
	public static function process_renewal_order( $renewal_order_id, $subscription_id = 0 ) {
		$renewal_order = wc_get_order( $renewal_order_id );
		if ( ! $renewal_order ) {
			return;
		}

		// Try to get the subscription ID from the parameter or order meta.
		if ( ! $subscription_id ) {
			$subscription_id = self::get_subscription_id_from_order( $renewal_order );
		}

		// Locate the original referring affiliate.
		$affiliate = self::get_original_affiliate( $renewal_order, $subscription_id );
		if ( ! $affiliate ) {
			return; // No referral attribution on the original order.
		}

		if ( 'active' !== $affiliate->status ) {
			self::log_audit(
				'recurring_commission_skipped',
				'order',
				$renewal_order_id,
				null,
				$affiliate->status,
				sprintf( 'Affiliate #%d status is "%s". Recurring commission skipped.', $affiliate->id, $affiliate->status )
			);
			return;
		}

		// Check admin fee status once for entire renewal order.
		$is_fee_blocked = ! Konx_Admin_Fees::can_affiliate_earn( (int) $affiliate->id );

		// Create conversion record for this renewal (idempotent).
		$conversion_id = self::create_renewal_conversion( $renewal_order, $affiliate, $subscription_id );

		// Copy attribution meta to renewal order for traceability.
		if ( ! $renewal_order->get_meta( Konx_Order_Attribution::META_REFERRER_ID ) ) {
			$renewal_order->update_meta_data( Konx_Order_Attribution::META_REFERRER_ID, (int) $affiliate->id );
			$renewal_order->update_meta_data( Konx_Order_Attribution::META_REFERRAL_CODE, $affiliate->referral_code );
			$renewal_order->save();
		}

		// Process each line item.
		$items_processed = 0;
		foreach ( $renewal_order->get_items() as $item_id => $item ) {
			$result = self::process_renewal_item( $renewal_order, $item_id, $item, $affiliate, $conversion_id, $is_fee_blocked );
			if ( $result ) {
				$items_processed++;
			}
		}

		if ( $items_processed > 0 ) {
			self::log_audit(
				'recurring_commission_created',
				'order',
				$renewal_order_id,
				null,
				(string) $items_processed,
				sprintf( 'Created %d recurring commission(s) for renewal order #%d, affiliate #%d.', $items_processed, $renewal_order_id, (int) $affiliate->id )
			);
		}
	}

	// ------------------------------------------------------------------
	// Line Item Processing
	// ------------------------------------------------------------------

	/**
	 * Process a single renewal line item for recurring commission.
	 *
	 * @param WC_Order            $order          The renewal order.
	 * @param int                 $item_id        Order item ID.
	 * @param WC_Order_Item       $item           The line item.
	 * @param object              $affiliate      The affiliate row.
	 * @param int                 $conversion_id  Conversion record ID.
	 * @param bool                $is_fee_blocked Whether admin fees are unpaid.
	 * @return bool True if a commission was created.
	 */
	private static function process_renewal_item( $order, $item_id, $item, $affiliate, $conversion_id, $is_fee_blocked ) {
		if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
			return false;
		}

		// Idempotency: skip if recurring commission already exists for this item.
		if ( self::has_recurring_commission( $order->get_id(), $item_id ) ) {
			return false;
		}

		// Look up product mapping to verify it's a recognized subscription product.
		$product_id   = $item->get_product_id();
		$variation_id = $item->get_variation_id();
		$mapping      = Konx_Product_Mapper::get_product_category( $product_id, $variation_id );

		if ( ! $mapping ) {
			return false; // Product not mapped.
		}

		// Calculate recurring commission.
		$commission_data = self::calculate_recurring_commission( $item, $mapping );
		if ( ! $commission_data ) {
			return false;
		}

		// Determine status.
		$status         = $is_fee_blocked ? Konx_Commission_Engine::STATUS_BLOCKED : Konx_Commission_Engine::STATUS_APPROVED;
		$blocked_reason = $is_fee_blocked ? 'unpaid_admin_fee' : null;

		// Create commission record.
		$commission_id = self::create_recurring_commission_record(
			$affiliate,
			$order,
			$item_id,
			$mapping,
			$commission_data,
			$conversion_id,
			$status,
			$blocked_reason
		);

		if ( is_wp_error( $commission_id ) ) {
			return false;
		}

		// Credit wallet only if approved.
		if ( Konx_Commission_Engine::STATUS_APPROVED === $status ) {
			self::credit_wallet( $affiliate, $commission_id, $commission_data );

			// Check for 100-sale milestone bonus.
			Konx_Milestone_Bonus::maybe_award_bonus( (int) $affiliate->id );
		}

		return true;
	}

	// ------------------------------------------------------------------
	// Commission Calculation
	// ------------------------------------------------------------------

	/**
	 * Calculate the recurring commission for a renewal line item.
	 *
	 * Uses a flat 10% rate for all affiliate types.
	 * Base is $item->get_subtotal() (before discounts/taxes).
	 *
	 * @param WC_Order_Item_Product $item    The line item.
	 * @param object                $mapping The product mapping row.
	 * @return array|false Commission data, or false if amount is zero.
	 */
	public static function calculate_recurring_commission( $item, $mapping ) {
		$product_price = $item->get_subtotal();
		$product_price = number_format( (float) $product_price, 2, '.', '' );

		if ( '0.00' === $product_price ) {
			return false;
		}

		$rate = Konx_Settings_Page::get_recurring_rate();

		if ( function_exists( 'bcmul' ) ) {
			$commission_amount = bcmul( $product_price, $rate, 2 );
		} else {
			$commission_amount = number_format( (float) $product_price * (float) $rate, 2, '.', '' );
		}

		return array(
			'product_price'     => $product_price,
			'commission_rate'   => $rate,
			'commission_amount' => $commission_amount,
			'product_id'        => $item->get_variation_id() ?: $item->get_product_id(),
		);
	}

	// ------------------------------------------------------------------
	// Attribution Lookup
	// ------------------------------------------------------------------

	/**
	 * Locate the original referring affiliate for a renewal order.
	 *
	 * Traverses: renewal order meta -> parent/original order meta -> conversion record.
	 *
	 * @param WC_Order $renewal_order  The renewal order.
	 * @param int      $subscription_id The YITH subscription ID.
	 * @return object|null The affiliate row, or null if no attribution found.
	 */
	public static function get_original_affiliate( $renewal_order, $subscription_id ) {
		// 1. Check if the renewal order itself has attribution.
		$affiliate_id = (int) $renewal_order->get_meta( Konx_Order_Attribution::META_REFERRER_ID );
		if ( $affiliate_id ) {
			return Konx_Affiliate_Manager::get_affiliate( $affiliate_id );
		}

		// 2. Try to find the parent/original order via YITH subscription.
		$parent_order_id = self::get_parent_order_id( $subscription_id );
		if ( $parent_order_id ) {
			$parent_order = wc_get_order( $parent_order_id );
			if ( $parent_order ) {
				$affiliate_id = (int) $parent_order->get_meta( Konx_Order_Attribution::META_REFERRER_ID );
				if ( $affiliate_id ) {
					return Konx_Affiliate_Manager::get_affiliate( $affiliate_id );
				}
			}
		}

		// 3. Fall back to conversion table lookup by subscription ID.
		if ( $subscription_id ) {
			$affiliate = self::get_affiliate_from_conversion_by_subscription( $subscription_id );
			if ( $affiliate ) {
				return $affiliate;
			}
		}

		return null;
	}

	// ------------------------------------------------------------------
	// Record Creation
	// ------------------------------------------------------------------

	/**
	 * Create a recurring commission record with sale_sequence.
	 *
	 * Follows the same transaction + FOR UPDATE pattern as the one-time engine.
	 *
	 * @param object   $affiliate       Affiliate row.
	 * @param WC_Order $order           Renewal order.
	 * @param int      $item_id         Order item ID.
	 * @param object   $mapping         Product mapping row.
	 * @param array    $commission_data  Calculated commission data.
	 * @param int      $conversion_id   Conversion record ID.
	 * @param string   $status          Commission status.
	 * @param string   $blocked_reason  Block reason or null.
	 * @return int|WP_Error Commission ID or WP_Error.
	 */
	private static function create_recurring_commission_record( $affiliate, $order, $item_id, $mapping, $commission_data, $conversion_id, $status, $blocked_reason ) {
		global $wpdb;

		$comm_table = $wpdb->prefix . 'konx_commissions';
		$aff_table  = $wpdb->prefix . 'konx_affiliates';
		$now        = current_time( 'mysql', true );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$locked_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, completed_sales FROM {$aff_table} WHERE id = %d FOR UPDATE",
				(int) $affiliate->id
			)
		);

		if ( ! $locked_row ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new \WP_Error( 'lock_failed', __( 'Failed to lock affiliate row.', 'konx-affiliate-dashboard' ) );
		}

		// Get next sale_sequence under the lock.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$max_seq = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(MAX(sale_sequence), 0) FROM {$comm_table} WHERE affiliate_id = %d",
				(int) $affiliate->id
			)
		);
		$sale_sequence = $max_seq + 1;

		$data = array(
			'affiliate_id'          => (int) $affiliate->id,
			'conversion_id'         => $conversion_id ? $conversion_id : 0,
			'order_id'              => $order->get_id(),
			'order_item_id'         => (int) $item_id,
			'product_id'            => (int) $commission_data['product_id'],
			'product_type'          => $mapping->product_type,
			'affiliate_type_at_sale' => $affiliate->affiliate_type,
			'product_price'         => $commission_data['product_price'],
			'commission_rate'       => $commission_data['commission_rate'],
			'commission_amount'     => $commission_data['commission_amount'],
			'commission_type'       => Konx_Commission_Engine::TYPE_RECURRING,
			'sale_sequence'         => $sale_sequence,
			'status'                => $status,
			'created_at'            => $now,
			'updated_at'            => $now,
		);
		$formats = array(
			'%d', '%d', '%d', '%d', '%d', '%s', '%s',
			'%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s',
		);

		if ( $blocked_reason ) {
			$data['blocked_reason'] = $blocked_reason;
			$formats[]              = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( $comm_table, $data, $formats );

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new \WP_Error( 'db_insert_failed', __( 'Failed to create recurring commission record.', 'konx-affiliate-dashboard' ) );
		}

		$commission_id = (int) $wpdb->insert_id;

		// Update completed_sales.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$aff_table} SET completed_sales = %d, updated_at = %s WHERE id = %d",
				$sale_sequence,
				$now,
				(int) $affiliate->id
			)
		);

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		return $commission_id;
	}

	/**
	 * Create a conversion record for the renewal order (idempotent).
	 *
	 * @param WC_Order $renewal_order  The renewal order.
	 * @param object   $affiliate      The affiliate row.
	 * @param int      $subscription_id YITH subscription ID.
	 * @return int The conversion record ID (existing or newly created).
	 */
	private static function create_renewal_conversion( $renewal_order, $affiliate, $subscription_id ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'konx_referral_conversions';
		$order_id = $renewal_order->get_id();

		// Idempotency: check for existing conversion.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d", $order_id )
		);

		if ( $existing ) {
			return (int) $existing->id;
		}

		$data = array(
			'affiliate_id'            => (int) $affiliate->id,
			'order_id'                => $order_id,
			'referral_code'           => $affiliate->referral_code,
			'order_total'             => $renewal_order->get_total(),
			'is_subscription_renewal' => 1,
			'converted_at'            => current_time( 'mysql', true ),
		);
		$formats = array( '%d', '%d', '%s', '%s', '%d', '%s' );

		$customer_id = $renewal_order->get_customer_id();
		if ( $customer_id ) {
			$data['customer_user_id'] = $customer_id;
			$formats[]                = '%d';
		}

		if ( $subscription_id ) {
			$data['subscription_id'] = (int) $subscription_id;
			$formats[]               = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $data, $formats );

		return (int) $wpdb->insert_id;
	}

	// ------------------------------------------------------------------
	// Wallet Credit
	// ------------------------------------------------------------------

	/**
	 * Credit the wallet for an approved recurring commission.
	 *
	 * @param object $affiliate       Affiliate row.
	 * @param int    $commission_id   Commission record ID.
	 * @param array  $commission_data Calculated commission data.
	 */
	private static function credit_wallet( $affiliate, $commission_id, $commission_data ) {
		global $wpdb;

		$result = Konx_Wallet::credit(
			(int) $affiliate->id,
			$commission_data['commission_amount'],
			Konx_Wallet::TYPE_RECURRING_COMMISSION,
			Konx_Wallet::REF_COMMISSION,
			$commission_id,
			sprintf(
				'Recurring commission: %s × 10%%',
				$commission_data['product_price']
			)
		);

		if ( is_wp_error( $result ) ) {
			self::log_audit(
				'wallet_credit_failed',
				'commission',
				$commission_id,
				null,
				$result->get_error_message(),
				sprintf( 'Recurring wallet credit failed for commission #%d: %s', $commission_id, $result->get_error_message() )
			);
			return;
		}

		// Store ledger entry ID on commission record.
		$comm_table = $wpdb->prefix . 'konx_commissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$comm_table,
			array( 'ledger_entry_id' => (int) $result ),
			array( 'id' => $commission_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	// ------------------------------------------------------------------
	// Queries
	// ------------------------------------------------------------------

	/**
	 * Check if a recurring commission already exists for a renewal item.
	 *
	 * @param int $order_id Order ID.
	 * @param int $item_id  Order item ID.
	 * @return bool
	 */
	public static function has_recurring_commission( $order_id, $item_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_commissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND order_item_id = %d AND commission_type = %s",
				absint( $order_id ),
				absint( $item_id ),
				Konx_Commission_Engine::TYPE_RECURRING
			)
		);

		return $count > 0;
	}

	// ------------------------------------------------------------------
	// YITH Helpers
	// ------------------------------------------------------------------

	/**
	 * Get the subscription ID from a renewal order.
	 *
	 * @param WC_Order $order The renewal order.
	 * @return int Subscription ID, or 0.
	 */
	private static function get_subscription_id_from_order( $order ) {
		// YITH stores subscription info as order meta.
		$sub_id = $order->get_meta( 'ywsbs_subscription' );
		if ( $sub_id ) {
			return (int) $sub_id;
		}

		// Alternative meta key used by some YITH versions.
		$sub_id = $order->get_meta( '_ywsbs_subscription' );
		if ( $sub_id ) {
			return (int) $sub_id;
		}

		return 0;
	}

	/**
	 * Get the parent (original) order ID from a YITH subscription.
	 *
	 * @param int $subscription_id YITH subscription ID.
	 * @return int Parent order ID, or 0.
	 */
	private static function get_parent_order_id( $subscription_id ) {
		if ( ! $subscription_id ) {
			return 0;
		}

		// YITH subscriptions are stored as custom post types.
		$parent_order_id = get_post_meta( $subscription_id, 'order_id', true );
		if ( $parent_order_id ) {
			return (int) $parent_order_id;
		}

		// Alternative: some versions use '_order_id'.
		$parent_order_id = get_post_meta( $subscription_id, '_order_id', true );
		return $parent_order_id ? (int) $parent_order_id : 0;
	}

	/**
	 * Look up the affiliate from a conversion record by subscription ID.
	 *
	 * @param int $subscription_id YITH subscription ID.
	 * @return object|null The affiliate row, or null.
	 */
	private static function get_affiliate_from_conversion_by_subscription( $subscription_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_referral_conversions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$conversion = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT affiliate_id FROM {$table} WHERE subscription_id = %d ORDER BY id ASC LIMIT 1",
				absint( $subscription_id )
			)
		);

		if ( $conversion && $conversion->affiliate_id ) {
			return Konx_Affiliate_Manager::get_affiliate( (int) $conversion->affiliate_id );
		}

		return null;
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
