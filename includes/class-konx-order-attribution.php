<?php
/**
 * WooCommerce order attribution.
 *
 * Reads the referral code from the cookie or the localStorage-backed
 * hidden checkout field, validates the affiliate, attaches the
 * affiliate ID to the order via HPOS-compatible meta methods, and
 * creates a conversion record in wp_konx_referral_conversions.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Order_Attribution
 */
class Konx_Order_Attribution {

	/**
	 * Order meta key for the referring affiliate's ID.
	 */
	const META_REFERRER_ID = '_konx_referrer_id';

	/**
	 * Order meta key for the referral code used.
	 */
	const META_REFERRAL_CODE = '_konx_referral_code';

	/**
	 * Register WooCommerce hooks.
	 */
	public static function init() {
		// Inject hidden field into checkout form.
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render_hidden_field' ) );

		// Attach referral meta before the order is saved.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach_referral_meta' ), 10, 2 );

		// Create conversion record after the order is saved (has an ID).
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'create_conversion_record' ) );

		// Clear referral data on thank-you page.
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'clear_referral_data' ) );
	}

	// ------------------------------------------------------------------
	// Checkout Form
	// ------------------------------------------------------------------

	/**
	 * Output a hidden input inside the checkout form.
	 *
	 * The value is populated by konx-referral-tracking.js from localStorage,
	 * providing a fallback when the HttpOnly cookie is unavailable.
	 */
	public static function render_hidden_field() {
		echo '<input type="hidden" id="konx_referral_code" name="konx_referral_code" value="">';
	}

	// ------------------------------------------------------------------
	// Order Meta (Before Save)
	// ------------------------------------------------------------------

	/**
	 * Read the referral code and attach affiliate meta to the order.
	 *
	 * Runs on `woocommerce_checkout_create_order` (before save).
	 * Uses `$order->update_meta_data()` for HPOS compatibility.
	 *
	 * @param WC_Order $order The order being created.
	 * @param array    $data  Checkout posted data.
	 */
	public static function attach_referral_meta( $order, $data ) {
		// Idempotency: skip if already attributed.
		if ( $order->get_meta( self::META_REFERRER_ID ) ) {
			return;
		}

		$code = self::resolve_referral_code();
		if ( empty( $code ) ) {
			return;
		}

		$affiliate = Konx_Affiliate_Manager::get_affiliate_by_referral_code( $code );
		if ( ! $affiliate || 'active' !== $affiliate->status ) {
			return;
		}

		// Self-referral prevention.
		$customer_id = $order->get_customer_id();
		if ( $customer_id && (int) $affiliate->user_id === $customer_id ) {
			return;
		}

		$order->update_meta_data( self::META_REFERRER_ID, (int) $affiliate->id );
		$order->update_meta_data( self::META_REFERRAL_CODE, $code );
	}

	// ------------------------------------------------------------------
	// Conversion Record (After Save)
	// ------------------------------------------------------------------

	/**
	 * Create a row in wp_konx_referral_conversions.
	 *
	 * Runs on `woocommerce_checkout_order_created` (after save).
	 * Idempotent — skips if a conversion for this order already exists
	 * (enforced by UNIQUE KEY uq_order_id).
	 *
	 * @param WC_Order $order The newly created order.
	 */
	public static function create_conversion_record( $order ) {
		global $wpdb;

		$affiliate_id = (int) $order->get_meta( self::META_REFERRER_ID );
		if ( ! $affiliate_id ) {
			return;
		}

		$table    = $wpdb->prefix . 'konx_referral_conversions';
		$order_id = $order->get_id();

		// Idempotency: one conversion per order.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id = %d", $order_id )
		);

		if ( $exists > 0 ) {
			return;
		}

		$data = array(
			'affiliate_id'  => $affiliate_id,
			'order_id'      => $order_id,
			'referral_code' => sanitize_text_field( $order->get_meta( self::META_REFERRAL_CODE ) ),
			'order_total'   => $order->get_total(),
			'converted_at'  => current_time( 'mysql', true ),
		);
		$formats = array( '%d', '%d', '%s', '%s', '%s' );

		// Store customer ID only for logged-in users (NULL for guests).
		$customer_id = $order->get_customer_id();
		if ( $customer_id ) {
			$data['customer_user_id'] = $customer_id;
			$formats[]               = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $data, $formats );

		// Clear the cookie now that attribution is recorded.
		Konx_Referral_Tracker::clear_cookie();
	}

	// ------------------------------------------------------------------
	// Cleanup
	// ------------------------------------------------------------------

	/**
	 * Clear referral data on the thank-you page.
	 *
	 * Outputs a small inline script that removes the localStorage
	 * entry. The cookie was already cleared in create_conversion_record().
	 *
	 * @param int $order_id The order ID.
	 */
	public static function clear_referral_data( $order_id ) {
		?>
		<script>
		if ( window.konxReferral && typeof window.konxReferral.clear === 'function' ) {
			window.konxReferral.clear();
		} else {
			try { localStorage.removeItem( 'konx_ref' ); } catch(e) {}
		}
		</script>
		<?php
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Resolve the referral code from available sources.
	 *
	 * Priority: cookie (primary) > POST hidden field (localStorage fallback).
	 *
	 * @return string The referral code, or empty string if none found.
	 */
	private static function resolve_referral_code() {
		// 1. HttpOnly cookie (set by Konx_Referral_Tracker).
		$code = Konx_Referral_Tracker::get_referral_code();
		if ( ! empty( $code ) ) {
			return $code;
		}

		// 2. Hidden field populated by JS from localStorage.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['konx_referral_code'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$code = strtoupper( sanitize_text_field( wp_unslash( $_POST['konx_referral_code'] ) ) );
			if ( ! empty( $code ) && strlen( $code ) <= 50 ) {
				return $code;
			}
		}

		return '';
	}
}
