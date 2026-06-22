<?php
/**
 * Plugin Name:       KonX Affiliate Dashboard
 * Plugin URI:        https://github.com/toxickim24/konx-affiliate-dashboard
 * Description:       A custom affiliate dashboard for WooCommerce.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            toxickim24
 * Author URI:        https://github.com/toxickim24
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       konx-affiliate-dashboard
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   9.0
 *
 * @package KonxAffiliateDashboard
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define( 'KONX_AFFILIATE_VERSION', '1.2.0' );
define( 'KONX_AFFILIATE_DB_VERSION', '1.0.0' );
define( 'KONX_AFFILIATE_PLUGIN_FILE', __FILE__ );
define( 'KONX_AFFILIATE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KONX_AFFILIATE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KONX_AFFILIATE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// ---------------------------------------------------------------------------
// Autoloader
// ---------------------------------------------------------------------------
require_once KONX_AFFILIATE_PLUGIN_DIR . 'includes/class-konx-autoloader.php';
Konx_Autoloader::register();

// ---------------------------------------------------------------------------
// WooCommerce HPOS Compatibility
// ---------------------------------------------------------------------------
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
} );

// ---------------------------------------------------------------------------
// Dependency Checks
// ---------------------------------------------------------------------------

/**
 * Check if WooCommerce is active (multisite-aware).
 *
 * @return bool
 */
function konx_affiliate_is_woocommerce_active() {
	$active = apply_filters( 'active_plugins', get_option( 'active_plugins' ) );

	if ( in_array( 'woocommerce/woocommerce.php', $active, true ) ) {
		return true;
	}

	if ( is_multisite() ) {
		$network = get_site_option( 'active_sitewide_plugins' );
		if ( isset( $network['woocommerce/woocommerce.php'] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Check if YITH WooCommerce Subscription is active (multisite-aware).
 *
 * Matches both free and premium editions by checking for
 * 'yith-woocommerce-subscription' anywhere in the plugin path.
 *
 * @return bool
 */
function konx_affiliate_is_yith_active() {
	$active = apply_filters( 'active_plugins', get_option( 'active_plugins' ) );

	foreach ( $active as $plugin ) {
		if ( false !== strpos( $plugin, 'yith-woocommerce-subscription' ) ) {
			return true;
		}
	}

	if ( is_multisite() ) {
		$network = get_site_option( 'active_sitewide_plugins' );
		if ( is_array( $network ) ) {
			foreach ( array_keys( $network ) as $plugin ) {
				if ( false !== strpos( $plugin, 'yith-woocommerce-subscription' ) ) {
					return true;
				}
			}
		}
	}

	return false;
}

// ---------------------------------------------------------------------------
// Admin Notices
// ---------------------------------------------------------------------------

/**
 * Admin notice: WooCommerce is required.
 */
function konx_affiliate_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'KonX Affiliate Dashboard requires WooCommerce to be installed and activated.',
				'konx-affiliate-dashboard'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Admin notice: YITH Subscription is recommended.
 */
function konx_affiliate_yith_missing_notice() {
	?>
	<div class="notice notice-warning is-dismissible">
		<p>
			<?php
			esc_html_e(
				'KonX Affiliate Dashboard: Recurring commissions require YITH WooCommerce Subscription. One-time commissions are unaffected.',
				'konx-affiliate-dashboard'
			);
			?>
		</p>
	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Lifecycle Hooks
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, array( 'Konx_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Konx_Deactivator', 'deactivate' ) );

// ---------------------------------------------------------------------------
// Plugin Initialization
// ---------------------------------------------------------------------------

/**
 * Initialize the plugin on plugins_loaded.
 *
 * Checks WooCommerce dependency, shows YITH notice if missing,
 * and runs database upgrade check.
 */
function konx_affiliate_init() {
	// Hard dependency: WooCommerce must be active.
	if ( ! konx_affiliate_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'konx_affiliate_woocommerce_missing_notice' );
		return;
	}

	// Soft dependency: YITH Subscription is recommended.
	if ( ! konx_affiliate_is_yith_active() ) {
		add_action( 'admin_notices', 'konx_affiliate_yith_missing_notice' );
	}

	// Run database upgrade if the stored version differs.
	$installed_db_version = get_option( 'konx_affiliate_db_version' );
	if ( $installed_db_version !== KONX_AFFILIATE_DB_VERSION ) {
		Konx_Install::maybe_upgrade( $installed_db_version );
	}

	// Referral tracking and order attribution (frontend + AJAX).
	Konx_Referral_Tracker::init();
	Konx_Order_Attribution::init();

	// Frontend shortcodes.
	Konx_Dashboard::init();
	Konx_Registration::init();

	// Commission engines and refund handling.
	Konx_Commission_Engine::init();
	Konx_Recurring_Commission_Engine::init();
	Konx_Refunds::init();

	// Admin fee cron handler.
	Konx_Admin_Fees::init();

	// GitHub update checker.
	Konx_Updater::init();

	// Initialize admin pages.
	if ( is_admin() ) {
		add_action( 'admin_enqueue_scripts', 'konx_enqueue_admin_assets' );

		// Main menu pages.
		Konx_Admin_Dashboard::init();
		Konx_Affiliates_Page::init();
		Konx_Admin_Fees_Page::init();
		Konx_Withdrawals_Page::init();
		Konx_Reports_Page::init();
		Konx_Settings_Page::init();
		Konx_Admin_Product_Mapping::init();

		// Tools page (tabs: notifications, activity log, financial audit, system status, help).
		Konx_Tools_Page::init();
		Konx_Notification_Center::init();

		// Utilities (no menu, handlers only).
		Konx_Export_Manager::init();
		Konx_Setup_Wizard::init();
	}
}
add_action( 'plugins_loaded', 'konx_affiliate_init' );

/**
 * Enqueue admin CSS on KonX admin pages.
 *
 * @param string $hook The admin page hook suffix.
 */
function konx_enqueue_admin_assets( $hook ) {
	// Only load on our pages.
	if ( strpos( $hook, 'konx-' ) === false && strpos( $hook, 'konx_' ) === false
		&& 'toplevel_page_konx-affiliate-dashboard' !== $hook ) {
		return;
	}

	wp_enqueue_style(
		'konx-admin',
		KONX_AFFILIATE_PLUGIN_URL . 'assets/css/konx-admin.css',
		array(),
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
