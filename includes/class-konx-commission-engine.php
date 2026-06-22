<?php
/**
 * One-time commission engine.
 *
 * Listens for completed WooCommerce orders, reads affiliate attribution
 * from order meta, calculates commissions per eligible line item, creates
 * commission records, assigns sale_sequence numbers, and credits the
 * affiliate wallet.
 *
 * Commission base is the product subtotal BEFORE discounts, coupons,
 * taxes, and payment gateway fees ($item->get_subtotal()).
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Commission_Engine
 */
class Konx_Commission_Engine {

	/**
	 * Commission types.
	 */
	const TYPE_ONE_TIME  = 'one_time';
	const TYPE_RECURRING = 'recurring';

	/**
	 * Commission statuses.
	 */
	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_BLOCKED  = 'blocked';
	const STATUS_REVERSED = 'reversed';

	/**
	 * Product types eligible for one-time commission.
	 *
	 * @var array
	 */
	private static $one_time_product_types = array(
		'starter_pack',
		'pro_pack',
		'ecard_pack',
	);

	/**
	 * Register WooCommerce hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'process_order' ), 10, 1 );
	}

	// ------------------------------------------------------------------
	// Order Processing
	// ------------------------------------------------------------------

	/**
	 * Process a completed WooCommerce order for commissions.
	 *
	 * Idempotent — if commissions already exist for this order, skips.
	 *
	 * @param int $order_id The WooCommerce order ID.
	 */
	public static function process_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Read affiliate attribution (HPOS-compatible).
		$affiliate_id = (int) $order->get_meta( Konx_Order_Attribution::META_REFERRER_ID );
		if ( ! $affiliate_id ) {
			return; // Organic order — no referral.
		}

		// Idempotency: check if commissions already exist for this order.
		if ( self::has_commissions_for_order( $order_id ) ) {
			self::log_audit(
				'commission_skipped_duplicate',
				'order',
				$order_id,
				null,
				null,
				sprintf( 'Order #%d already has commissions. Skipped (re-trigger).', $order_id )
			);
			return;
		}

		// Validate affiliate.
		$affiliate = Konx_Affiliate_Manager::get_affiliate( $affiliate_id );
		if ( ! $affiliate ) {
			self::log_audit( 'commission_skipped', 'order', $order_id, null, null, 'Affiliate not found.' );
			return;
		}
		if ( 'active' !== $affiliate->status ) {
			self::log_audit(
				'commission_skipped',
				'order',
				$order_id,
				null,
				$affiliate->status,
				sprintf( 'Affiliate #%d status is "%s". Commissions skipped.', $affiliate_id, $affiliate->status )
			);
			return;
		}

		// Check admin fee status ONCE for the entire order.
		$is_fee_blocked = self::has_unpaid_admin_fee( $affiliate_id );

		// Look up the conversion record for this order.
		$conversion = self::get_conversion_by_order( $order_id );
		$conversion_id = $conversion ? (int) $conversion->id : 0;

		// Process each line item.
		$items_processed = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			$result = self::process_order_item( $order, $item_id, $item, $affiliate, $conversion_id, $is_fee_blocked );
			if ( $result ) {
				$items_processed++;
			}
		}

		if ( $items_processed > 0 ) {
			self::log_audit(
				'commission_created',
				'order',
				$order_id,
				null,
				(string) $items_processed,
				sprintf( 'Created %d commission(s) for order #%d, affiliate #%d.', $items_processed, $order_id, $affiliate_id )
			);
		}
	}

	// ------------------------------------------------------------------
	// Line Item Processing
	// ------------------------------------------------------------------

	/**
	 * Process a single order line item for commission.
	 *
	 * @param WC_Order          $order          The order.
	 * @param int               $item_id        WooCommerce order item ID.
	 * @param WC_Order_Item     $item           The line item.
	 * @param object            $affiliate      The affiliate row.
	 * @param int               $conversion_id  The conversion record ID (0 if not found).
	 * @param bool              $is_fee_blocked Whether admin fees are unpaid.
	 * @return bool True if a commission was created.
	 */
	private static function process_order_item( $order, $item_id, $item, $affiliate, $conversion_id, $is_fee_blocked ) {
		// Only process product line items.
		if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
			return false;
		}

		// Idempotency: skip if commission already exists for this line item.
		if ( self::has_commission_for_order_item( $order->get_id(), $item_id ) ) {
			return false;
		}

		// Look up product mapping: check variation first, then parent.
		$product_id   = $item->get_product_id();
		$variation_id = $item->get_variation_id();
		$mapping      = Konx_Product_Mapper::get_product_category( $product_id, $variation_id );

		if ( ! $mapping ) {
			return false; // Product not mapped — no commission.
		}

		// Only process one-time product types in this engine.
		if ( ! in_array( $mapping->product_type, self::$one_time_product_types, true ) ) {
			return false;
		}

		// Calculate commission.
		$commission_data = self::calculate_commission( $item, $affiliate, $mapping );
		if ( ! $commission_data ) {
			return false;
		}

		// Determine status based on admin fee.
		$status         = $is_fee_blocked ? self::STATUS_BLOCKED : self::STATUS_APPROVED;
		$blocked_reason = $is_fee_blocked ? 'unpaid_admin_fee' : null;

		// Create commission record (with sale_sequence assignment).
		$commission_id = self::create_commission_record(
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

		// Credit wallet only if approved (not blocked).
		if ( self::STATUS_APPROVED === $status ) {
			self::credit_wallet( $affiliate, $commission_id, $commission_data );
		}

		return true;
	}

	// ------------------------------------------------------------------
	// Commission Calculation
	// ------------------------------------------------------------------

	/**
	 * Calculate the commission amount for a line item.
	 *
	 * Uses get_subtotal() (before discounts/coupons/taxes) as the base.
	 *
	 * @param WC_Order_Item_Product $item      The line item.
	 * @param object                $affiliate The affiliate row.
	 * @param object                $mapping   The product mapping row.
	 * @return array|false Commission data array, or false if no rate found.
	 */
	public static function calculate_commission( $item, $affiliate, $mapping ) {
		$rate = self::get_commission_rate( $affiliate->affiliate_type, $mapping->product_type );
		if ( null === $rate ) {
			return false;
		}

		// Commission base: full product subtotal before discounts/coupons/taxes.
		$product_price = $item->get_subtotal();
		$product_price = number_format( (float) $product_price, 2, '.', '' );

		// Calculate commission using string arithmetic.
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

	/**
	 * Get the commission rate for an affiliate type and product type.
	 *
	 * Reads from wp_konx_commission_rules table (admin-configurable).
	 *
	 * @param string $affiliate_type The affiliate type (business, referral, etc.).
	 * @param string $product_type   The internal product type (starter_pack, etc.).
	 * @return string|null The rate as a decimal string (e.g. '0.4000'), or null.
	 */
	public static function get_commission_rate( $affiliate_type, $product_type ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_commission_rules';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rate = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT rate FROM {$table} WHERE affiliate_type = %s AND product_type = %s AND commission_type = %s AND is_active = 1",
				$affiliate_type,
				$product_type,
				self::TYPE_ONE_TIME
			)
		);

		return $rate;
	}

	// ------------------------------------------------------------------
	// Commission Record Creation
	// ------------------------------------------------------------------

	/**
	 * Create a commission record with an atomically assigned sale_sequence.
	 *
	 * Runs inside a database transaction with a FOR UPDATE lock on the
	 * affiliate row to prevent duplicate sequence numbers.
	 *
	 * @param object   $affiliate      The affiliate row.
	 * @param WC_Order $order          The WooCommerce order.
	 * @param int      $item_id        WooCommerce order item ID.
	 * @param object   $mapping        The product mapping row.
	 * @param array    $commission_data Calculated commission data.
	 * @param int      $conversion_id  The conversion record ID.
	 * @param string   $status         Commission status (approved or blocked).
	 * @param string   $blocked_reason Reason if blocked, null otherwise.
	 * @return int|WP_Error Commission ID, or WP_Error.
	 */
	private static function create_commission_record( $affiliate, $order, $item_id, $mapping, $commission_data, $conversion_id, $status, $blocked_reason ) {
		global $wpdb;

		$comm_table = $wpdb->prefix . 'konx_commissions';
		$aff_table  = $wpdb->prefix . 'konx_affiliates';
		$now        = current_time( 'mysql', true );

		// Begin transaction.
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		// Lock the affiliate row.
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
		$sale_sequence = self::get_next_sale_sequence( (int) $affiliate->id );

		// Insert commission record.
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
			'commission_type'       => self::TYPE_ONE_TIME,
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
			return new \WP_Error( 'db_insert_failed', __( 'Failed to create commission record.', 'konx-affiliate-dashboard' ) );
		}

		$commission_id = (int) $wpdb->insert_id;

		// Update completed_sales to match sale_sequence.
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
	 * Get the next sale_sequence number for an affiliate.
	 *
	 * Must be called inside a transaction with the affiliate row locked.
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 * @return int The next sequence number.
	 */
	private static function get_next_sale_sequence( $affiliate_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_commissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$max = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(MAX(sale_sequence), 0) FROM {$table} WHERE affiliate_id = %d",
				$affiliate_id
			)
		);

		return (int) $max + 1;
	}

	// ------------------------------------------------------------------
	// Wallet Credit
	// ------------------------------------------------------------------

	/**
	 * Credit the affiliate wallet for an approved commission.
	 *
	 * Uses the commission ID as the reference to prevent double-crediting.
	 *
	 * @param object $affiliate       The affiliate row.
	 * @param int    $commission_id   The commission record ID.
	 * @param array  $commission_data Calculated commission data.
	 */
	private static function credit_wallet( $affiliate, $commission_id, $commission_data ) {
		global $wpdb;

		$result = Konx_Wallet::credit(
			(int) $affiliate->id,
			$commission_data['commission_amount'],
			Konx_Wallet::TYPE_COMMISSION,
			Konx_Wallet::REF_COMMISSION,
			$commission_id,
			sprintf(
				'Commission on %s: %s × %s%%',
				$commission_data['product_price'],
				$commission_data['product_price'],
				number_format( (float) $commission_data['commission_rate'] * 100, 0 )
			)
		);

		if ( is_wp_error( $result ) ) {
			self::log_audit(
				'wallet_credit_failed',
				'commission',
				$commission_id,
				null,
				$result->get_error_message(),
				sprintf( 'Wallet credit failed for commission #%d: %s', $commission_id, $result->get_error_message() )
			);
			return;
		}

		// Store the ledger entry ID on the commission record.
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
	 * Check if commissions already exist for an order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return bool True if at least one commission exists.
	 */
	public static function has_commissions_for_order( $order_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_commissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id = %d", absint( $order_id ) )
		);

		return $count > 0;
	}

	/**
	 * Check if a commission already exists for a specific order item.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @param int $item_id  WooCommerce order item ID.
	 * @return bool True if a commission exists.
	 */
	public static function has_commission_for_order_item( $order_id, $item_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_commissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND order_item_id = %d",
				absint( $order_id ),
				absint( $item_id )
			)
		);

		return $count > 0;
	}

	/**
	 * Check if the affiliate has unpaid admin fees.
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 * @return bool True if there are unpaid or overdue fees.
	 */
	private static function has_unpaid_admin_fee( $affiliate_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_admin_fees';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE affiliate_id = %d AND status IN ('unpaid', 'overdue')",
				absint( $affiliate_id )
			)
		);

		return $count > 0;
	}

	/**
	 * Get the conversion record for an order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return object|null The conversion row, or null.
	 */
	private static function get_conversion_by_order( $order_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_referral_conversions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", absint( $order_id ) )
		);
	}

	/**
	 * Get all commissions for an order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array Array of commission row objects.
	 */
	public static function get_commissions_for_order( $order_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_commissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", absint( $order_id ) )
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
