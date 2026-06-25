<?php
/**
 * WooCommerce My Account integration.
 *
 * Adds an "Affiliate Dashboard" tab to the WooCommerce My Account
 * navigation for approved affiliates. Non-affiliates never see
 * the menu item.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_My_Account
 */
class Konx_My_Account {

	/**
	 * Endpoint slug used in WooCommerce My Account URLs.
	 *
	 * @var string
	 */
	const ENDPOINT = 'affiliate-dashboard';

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Register the rewrite endpoint (must run on init, before rewrite rules are matched).
		add_action( 'init', array( __CLASS__, 'register_endpoint' ) );

		// Add the menu item (affiliates only).
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ), 10, 1 );

		// Render the endpoint content.
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );

		// Set the page title when viewing the endpoint.
		add_filter( 'the_title', array( __CLASS__, 'endpoint_title' ), 10, 2 );

		// Enqueue assets when on the endpoint.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );

		// Flush rewrite rules once after activation (via transient).
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 99 );
	}

	/**
	 * Register the WooCommerce endpoint.
	 *
	 * This must run for all users so the rewrite rules exist,
	 * but the menu item is hidden from non-affiliates.
	 */
	public static function register_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Flush rewrite rules once after activation.
	 *
	 * Uses a transient so the flush only happens once.
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( get_transient( 'konx_flush_my_account_endpoint' ) ) {
			delete_transient( 'konx_flush_my_account_endpoint' );
			flush_rewrite_rules();
		}
	}

	/**
	 * Schedule a rewrite rule flush.
	 *
	 * Call this from plugin activation.
	 */
	public static function schedule_flush() {
		set_transient( 'konx_flush_my_account_endpoint', '1', 60 );
	}

	/**
	 * Add "Affiliate Dashboard" to the WooCommerce My Account menu.
	 *
	 * Only visible to users who have an active affiliate record.
	 * Placed just before "Logout" in the navigation.
	 *
	 * @param array $items Existing menu items.
	 * @return array Modified menu items.
	 */
	public static function add_menu_item( $items ) {
		if ( ! is_user_logged_in() ) {
			return $items;
		}

		$affiliate = Konx_Affiliate_Manager::get_affiliate_by_user( get_current_user_id() );
		if ( ! $affiliate ) {
			return $items;
		}

		// Insert "Affiliate Dashboard" before "Logout".
		$new_items = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$new_items[ self::ENDPOINT ] = __( 'Affiliate Dashboard', 'konx-affiliate-dashboard' );
			}
			$new_items[ $key ] = $label;
		}

		// Fallback: if "customer-logout" wasn't in the array, append.
		if ( ! isset( $new_items[ self::ENDPOINT ] ) ) {
			$new_items[ self::ENDPOINT ] = __( 'Affiliate Dashboard', 'konx-affiliate-dashboard' );
		}

		return $new_items;
	}

	/**
	 * Render the Affiliate Dashboard content inside My Account.
	 *
	 * Reuses the same data preparation and template as the
	 * [konx_affiliate_dashboard] shortcode.
	 */
	public static function render_endpoint() {
		if ( ! is_user_logged_in() ) {
			echo '<div class="konx-dash-notice">'
				. esc_html__( 'Please log in to access your affiliate dashboard.', 'konx-affiliate-dashboard' )
				. '</div>';
			return;
		}

		$user_id   = get_current_user_id();
		$affiliate = Konx_Affiliate_Manager::get_affiliate_by_user( $user_id );

		if ( ! $affiliate ) {
			echo '<div class="konx-dash-notice">'
				. esc_html__( 'You do not have an affiliate account. Contact the administrator to get started.', 'konx-affiliate-dashboard' )
				. '</div>';
			return;
		}

		// Use the shortcode renderer which handles data prep and template inclusion.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Konx_Dashboard::render_shortcode();
	}

	/**
	 * Set the page title for the endpoint.
	 *
	 * @param string $title Original title.
	 * @param int    $id    Post ID.
	 * @return string Modified title.
	 */
	public static function endpoint_title( $title, $id = 0 ) {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return $title;
		}

		if ( is_main_query() && in_the_loop() && is_account_page()
			&& (int) $id === wc_get_page_id( 'myaccount' ) ) {
			global $wp_query;

			if ( isset( $wp_query->query_vars[ self::ENDPOINT ] ) ) {
				$title = __( 'Affiliate Dashboard', 'konx-affiliate-dashboard' );
				// Remove filter after first run to avoid doubling.
				remove_filter( 'the_title', array( __CLASS__, 'endpoint_title' ), 10 );
			}
		}

		return $title;
	}

	/**
	 * Enqueue dashboard CSS/JS when viewing the affiliate-dashboard endpoint.
	 */
	public static function maybe_enqueue_assets() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		global $wp_query;
		if ( ! isset( $wp_query->query_vars[ self::ENDPOINT ] ) ) {
			return;
		}

		wp_enqueue_style(
			'konx-frontend',
			KONX_AFFILIATE_PLUGIN_URL . 'assets/css/konx-frontend.css',
			array(),
			KONX_AFFILIATE_VERSION
		);

		wp_enqueue_style(
			'konx-dashboard',
			KONX_AFFILIATE_PLUGIN_URL . 'assets/css/konx-dashboard.css',
			array( 'konx-frontend' ),
			KONX_AFFILIATE_VERSION
		);

		wp_enqueue_style(
			'konx-tooltips',
			KONX_AFFILIATE_PLUGIN_URL . 'assets/css/konx-tooltips.css',
			array(),
			KONX_AFFILIATE_VERSION
		);

		wp_enqueue_script(
			'konx-tooltips',
			KONX_AFFILIATE_PLUGIN_URL . 'assets/js/konx-tooltips.js',
			array(),
			KONX_AFFILIATE_VERSION,
			true
		);
	}
}
