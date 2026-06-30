<?php
/**
 * WooCommerce My Account integration.
 *
 * Adds an "Affiliate Dashboard" tab for approved affiliates and a
 * "Become an Affiliate" tab for non-affiliates to the WooCommerce
 * My Account navigation. Pending affiliates see a review notice
 * instead of the Apply Now CTA.
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
	 * Endpoint slug for the Affiliate Dashboard.
	 *
	 * @var string
	 */
	const ENDPOINT = 'affiliate-dashboard';

	/**
	 * Menu item key for the "Become an Affiliate" link.
	 *
	 * Not a WooCommerce endpoint — it links to the registration page.
	 *
	 * @var string
	 */
	const BECOME_AFFILIATE_KEY = 'become-an-affiliate';

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Register the rewrite endpoint (must run on init, before rewrite rules are matched).
		add_action( 'init', array( __CLASS__, 'register_endpoint' ) );

		// Add menu items (affiliate dashboard OR become-an-affiliate).
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ), 10, 1 );

		// Override URL for the "Become an Affiliate" menu item to point to registration page.
		add_filter( 'woocommerce_get_endpoint_url', array( __CLASS__, 'override_become_affiliate_url' ), 10, 4 );

		// Render the affiliate dashboard endpoint content.
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );

		// Dashboard CTA for non-affiliates / pending notice.
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'render_dashboard_cta' ) );

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

	// ------------------------------------------------------------------
	// Menu Items
	// ------------------------------------------------------------------

	/**
	 * Add affiliate-related menu items to WooCommerce My Account.
	 *
	 * - Approved/active affiliates see "Affiliate Dashboard".
	 * - Non-affiliates see "Become an Affiliate" (if registration page exists).
	 * - Pending affiliates see "Affiliate Dashboard" (endpoint shows status).
	 *
	 * @param array $items Existing menu items.
	 * @return array Modified menu items.
	 */
	public static function add_menu_item( $items ) {
		if ( ! is_user_logged_in() ) {
			return $items;
		}

		$affiliate = Konx_Affiliate_Manager::get_affiliate_by_user( get_current_user_id() );

		if ( $affiliate ) {
			// Affiliate exists (any status) — show "Affiliate Dashboard".
			return self::insert_before_logout( $items, self::ENDPOINT, __( 'Affiliate Dashboard', 'konx-affiliate-dashboard' ) );
		}

		// Non-affiliate — show "Become an Affiliate" if a registration page exists.
		$reg_url = self::get_registration_page_url();
		if ( $reg_url ) {
			return self::insert_before_logout( $items, self::BECOME_AFFILIATE_KEY, __( 'Become an Affiliate', 'konx-affiliate-dashboard' ) );
		}

		return $items;
	}

	/**
	 * Override the URL for the "Become an Affiliate" menu item.
	 *
	 * WooCommerce builds endpoint URLs from slugs. Since "become-an-affiliate"
	 * is not a real endpoint, we redirect its URL to the registration page.
	 *
	 * @param string $url       The endpoint URL.
	 * @param string $endpoint  The endpoint slug.
	 * @param string $value     The endpoint value.
	 * @param string $permalink The base permalink.
	 * @return string Modified URL.
	 */
	public static function override_become_affiliate_url( $url, $endpoint, $value, $permalink ) {
		if ( self::BECOME_AFFILIATE_KEY === $endpoint ) {
			$reg_url = self::get_registration_page_url();
			if ( $reg_url ) {
				return $reg_url;
			}
		}
		return $url;
	}

	// ------------------------------------------------------------------
	// Dashboard CTA
	// ------------------------------------------------------------------

	/**
	 * Render a CTA card on the My Account dashboard.
	 *
	 * - Non-affiliates: "Become a KonX Affiliate" card with Apply Now button.
	 * - Pending affiliates: "Application under review" notice.
	 * - Active affiliates: nothing (they use the Affiliate Dashboard tab).
	 */
	public static function render_dashboard_cta() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$affiliate = Konx_Affiliate_Manager::get_affiliate_by_user( get_current_user_id() );

		if ( $affiliate && 'pending' === $affiliate->status ) {
			self::render_pending_notice();
			return;
		}

		if ( $affiliate ) {
			// Active/inactive/suspended — the dashboard tab handles everything.
			return;
		}

		// Non-affiliate — show "Become an Affiliate" CTA.
		self::render_become_affiliate_card();
	}

	/**
	 * Render the "Become a KonX Affiliate" CTA card.
	 */
	private static function render_become_affiliate_card() {
		$reg_url = self::get_registration_page_url();
		if ( ! $reg_url ) {
			return;
		}

		printf(
			'<div style="background:#f0f6fc;border:1px solid #72aee6;border-radius:6px;padding:20px 24px;margin-bottom:20px;">'
			. '<h3 style="margin:0 0 8px;font-size:16px;color:#1d2327;">%s</h3>'
			. '<p style="margin:0 0 14px;color:#50575e;">%s</p>'
			. '<a href="%s" style="display:inline-block;background:#2271b1;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;font-weight:600;font-size:14px;">%s</a>'
			. '</div>',
			esc_html__( 'Become a KonX Affiliate', 'konx-affiliate-dashboard' ),
			esc_html__( 'Earn commissions by sharing your referral link and helping others discover KonX.', 'konx-affiliate-dashboard' ),
			esc_url( $reg_url ),
			esc_html__( 'Apply Now', 'konx-affiliate-dashboard' )
		);
	}

	/**
	 * Render the "Application under review" notice for pending affiliates.
	 */
	private static function render_pending_notice() {
		printf(
			'<div style="background:#fcf9e8;border:1px solid #dba617;border-radius:6px;padding:20px 24px;margin-bottom:20px;">'
			. '<h3 style="margin:0 0 8px;font-size:16px;color:#1d2327;">%s</h3>'
			. '<p style="margin:0;color:#50575e;">%s</p>'
			. '</div>',
			esc_html__( 'Your affiliate application is under review.', 'konx-affiliate-dashboard' ),
			esc_html__( 'We will notify you once your application has been reviewed.', 'konx-affiliate-dashboard' )
		);
	}

	// ------------------------------------------------------------------
	// Endpoint Content
	// ------------------------------------------------------------------

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

	// ------------------------------------------------------------------
	// Page Title
	// ------------------------------------------------------------------

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

	// ------------------------------------------------------------------
	// Assets
	// ------------------------------------------------------------------

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

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Insert a menu item before "Logout" in the My Account menu.
	 *
	 * @param array  $items Existing menu items.
	 * @param string $key   The menu item key.
	 * @param string $label The menu item label.
	 * @return array Modified menu items.
	 */
	private static function insert_before_logout( $items, $key, $label ) {
		$new_items = array();
		foreach ( $items as $item_key => $item_label ) {
			if ( 'customer-logout' === $item_key ) {
				$new_items[ $key ] = $label;
			}
			$new_items[ $item_key ] = $item_label;
		}

		// Fallback: if "customer-logout" wasn't in the array, append.
		if ( ! isset( $new_items[ $key ] ) ) {
			$new_items[ $key ] = $label;
		}

		return $new_items;
	}

	/**
	 * Get the URL of the affiliate registration page.
	 *
	 * Finds the published page containing the [konx_affiliate_register] shortcode.
	 *
	 * @return string|false Registration page URL, or false if not found.
	 */
	private static function get_registration_page_url() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$page_id = $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%[konx_affiliate_register]%' LIMIT 1"
		);

		if ( ! $page_id ) {
			return false;
		}

		$url = get_permalink( (int) $page_id );
		return $url ? $url : false;
	}
}
