<?php
/**
 * Plugin Name:       KonX Affiliate Dashboard
 * Plugin URI:        https://github.com/toxickim24/konx-affiliate-dashboard
 * Description:       A custom affiliate dashboard for WooCommerce.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            toxickim24
 * Author URI:        https://github.com/toxickim24
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       konx-affiliate-dashboard
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   8.0
 *
 * @package KonxAffiliateDashboard
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'KONX_AFFILIATE_VERSION', '1.0.0' );
define( 'KONX_AFFILIATE_PLUGIN_FILE', __FILE__ );
define( 'KONX_AFFILIATE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KONX_AFFILIATE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KONX_AFFILIATE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check if WooCommerce is active.
 *
 * @return bool
 */
function konx_affiliate_is_woocommerce_active() {
	return in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true );
}

/**
 * Display admin notice if WooCommerce is not active.
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
 * Initialize the plugin.
 */
function konx_affiliate_init() {
	if ( ! konx_affiliate_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'konx_affiliate_woocommerce_missing_notice' );
		return;
	}

	// Plugin initialization will go here.
}
add_action( 'plugins_loaded', 'konx_affiliate_init' );
