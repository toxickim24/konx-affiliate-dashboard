<?php
/**
 * System status / health check page.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Konx_System_Status {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'konx-affiliate-dashboard',
			__( 'System Status', 'konx-affiliate-dashboard' ),
			__( 'System Status', 'konx-affiliate-dashboard' ),
			'manage_konx_settings',
			'konx-system-status',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		$checks = self::run_checks();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'System Status', 'konx-affiliate-dashboard' ); ?></h1>

			<table class="widefat fixed striped" style="max-width:800px;margin-top:20px;">
				<thead>
					<tr>
						<th scope="col" style="width:40%;"><?php esc_html_e( 'Check', 'konx-affiliate-dashboard' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Value', 'konx-affiliate-dashboard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $checks as $check ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
							<td><?php echo wp_kses_post( self::status_badge( $check['status'] ) ); ?></td>
							<td><?php echo esc_html( $check['value'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Run all health checks.
	 *
	 * @return array Array of { label, status, value }.
	 */
	private static function run_checks() {
		global $wpdb;

		$checks = array();

		// Plugin version.
		$checks[] = array(
			'label'  => __( 'Plugin Version', 'konx-affiliate-dashboard' ),
			'status' => 'ok',
			'value'  => KONX_AFFILIATE_VERSION,
		);

		// WordPress.
		$checks[] = array(
			'label'  => __( 'WordPress Version', 'konx-affiliate-dashboard' ),
			'status' => version_compare( get_bloginfo( 'version' ), '5.8', '>=' ) ? 'ok' : 'error',
			'value'  => get_bloginfo( 'version' ),
		);

		// PHP.
		$checks[] = array(
			'label'  => __( 'PHP Version', 'konx-affiliate-dashboard' ),
			'status' => version_compare( PHP_VERSION, '7.4', '>=' ) ? 'ok' : 'error',
			'value'  => PHP_VERSION,
		);

		// WooCommerce.
		$wc_active = konx_affiliate_is_woocommerce_active();
		$checks[]  = array(
			'label'  => __( 'WooCommerce', 'konx-affiliate-dashboard' ),
			'status' => $wc_active ? 'ok' : 'error',
			'value'  => $wc_active ? ( defined( 'WC_VERSION' ) ? WC_VERSION : __( 'Active', 'konx-affiliate-dashboard' ) ) : __( 'Not Active', 'konx-affiliate-dashboard' ),
		);

		// YITH.
		$yith = konx_affiliate_is_yith_active();
		$checks[] = array(
			'label'  => __( 'YITH Subscription', 'konx-affiliate-dashboard' ),
			'status' => $yith ? 'ok' : 'warning',
			'value'  => $yith ? __( 'Active', 'konx-affiliate-dashboard' ) : __( 'Not Active (recurring commissions disabled)', 'konx-affiliate-dashboard' ),
		);

		// Database tables.
		$tables = array(
			'konx_affiliates', 'konx_referral_clicks', 'konx_referral_conversions',
			'konx_commissions', 'konx_wallet_ledger', 'konx_withdrawals',
			'konx_admin_fees', 'konx_milestones', 'konx_commission_rules',
			'konx_product_map', 'konx_audit_log',
		);
		$missing = array();
		foreach ( $tables as $t ) {
			$full = $wpdb->prefix . $t;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$missing[] = $t;
			}
		}
		$checks[] = array(
			'label'  => __( 'Database Tables (11)', 'konx-affiliate-dashboard' ),
			'status' => empty( $missing ) ? 'ok' : 'error',
			'value'  => empty( $missing ) ? __( 'All present', 'konx-affiliate-dashboard' ) : __( 'Missing: ', 'konx-affiliate-dashboard' ) . implode( ', ', $missing ),
		);

		// Roles.
		$role_slugs = Konx_Roles::get_affiliate_role_slugs();
		$missing_roles = array();
		foreach ( $role_slugs as $r ) {
			if ( ! get_role( $r ) ) {
				$missing_roles[] = $r;
			}
		}
		$checks[] = array(
			'label'  => __( 'Affiliate Roles (5)', 'konx-affiliate-dashboard' ),
			'status' => empty( $missing_roles ) ? 'ok' : 'error',
			'value'  => empty( $missing_roles ) ? __( 'All registered', 'konx-affiliate-dashboard' ) : __( 'Missing: ', 'konx-affiliate-dashboard' ) . implode( ', ', $missing_roles ),
		);

		// Commission rules.
		$rule_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}konx_commission_rules" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$checks[] = array(
			'label'  => __( 'Commission Rules', 'konx-affiliate-dashboard' ),
			'status' => $rule_count >= 20 ? 'ok' : 'warning',
			'value'  => sprintf( '%d rules', $rule_count ),
		);

		// IP Hash Salt.
		$checks[] = array(
			'label'  => __( 'IP Hash Salt', 'konx-affiliate-dashboard' ),
			'status' => get_option( 'konx_ip_hash_salt' ) ? 'ok' : 'error',
			'value'  => get_option( 'konx_ip_hash_salt' ) ? __( 'Set', 'konx-affiliate-dashboard' ) : __( 'Missing', 'konx-affiliate-dashboard' ),
		);

		// Cron.
		$cron = wp_next_scheduled( 'konx_daily_overdue_fee_check' );
		$checks[] = array(
			'label'  => __( 'Daily Fee Cron', 'konx-affiliate-dashboard' ),
			'status' => $cron ? 'ok' : 'warning',
			'value'  => $cron ? sprintf( __( 'Next: %s', 'konx-affiliate-dashboard' ), date_i18n( 'Y-m-d H:i:s', $cron ) ) : __( 'Not scheduled', 'konx-affiliate-dashboard' ),
		);

		// HPOS.
		$hpos = 'unknown';
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			$hpos = \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'custom_order_tables' ) ? 'enabled' : 'disabled';
		}
		$checks[] = array(
			'label'  => __( 'WooCommerce HPOS', 'konx-affiliate-dashboard' ),
			'status' => 'ok',
			'value'  => ucfirst( $hpos ),
		);

		// Required pages.
		$dash_page = self::find_page_with_shortcode( 'konx_affiliate_dashboard' );
		$reg_page  = self::find_page_with_shortcode( 'konx_affiliate_register' );

		$checks[] = array(
			'label'  => __( 'Dashboard Page', 'konx-affiliate-dashboard' ),
			'status' => $dash_page ? 'ok' : 'warning',
			'value'  => $dash_page ? get_the_title( $dash_page ) . ' (#' . $dash_page . ')' : __( 'Not found — create a page with [konx_affiliate_dashboard]', 'konx-affiliate-dashboard' ),
		);

		$checks[] = array(
			'label'  => __( 'Registration Page', 'konx-affiliate-dashboard' ),
			'status' => $reg_page ? 'ok' : 'warning',
			'value'  => $reg_page ? get_the_title( $reg_page ) . ' (#' . $reg_page . ')' : __( 'Not found — create a page with [konx_affiliate_register]', 'konx-affiliate-dashboard' ),
		);

		// bcmath.
		$checks[] = array(
			'label'  => __( 'Financial Precision', 'konx-affiliate-dashboard' ),
			'status' => function_exists( 'bcadd' ) ? 'ok' : 'warning',
			'value'  => function_exists( 'bcadd' ) ? __( 'High precision (bcmath enabled)', 'konx-affiliate-dashboard' ) : __( 'Standard precision. Ask your hosting provider to enable the PHP bcmath extension for exact calculations.', 'konx-affiliate-dashboard' ),
		);

		return $checks;
	}

	/**
	 * Find a published page containing a shortcode.
	 *
	 * @param string $shortcode The shortcode name (without brackets).
	 * @return int|null Page ID or null.
	 */
	private static function find_page_with_shortcode( $shortcode ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s LIMIT 1",
			'%[' . $wpdb->esc_like( $shortcode ) . ']%'
		) );

		return $id ? (int) $id : null;
	}

	/**
	 * Render a colored status badge.
	 *
	 * @param string $status 'ok', 'warning', or 'error'.
	 * @return string HTML.
	 */
	private static function status_badge( $status ) {
		$colors = array(
			'ok'      => array( '#edfaef', '#00a32a' ),
			'warning' => array( '#fcf6e3', '#946800' ),
			'error'   => array( '#fcf0f1', '#d63638' ),
		);
		$labels = array(
			'ok'      => __( 'Healthy', 'konx-affiliate-dashboard' ),
			'warning' => __( 'Warning', 'konx-affiliate-dashboard' ),
			'error'   => __( 'Error', 'konx-affiliate-dashboard' ),
		);

		$c = isset( $colors[ $status ] ) ? $colors[ $status ] : $colors['warning'];
		$l = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;

		return sprintf(
			'<span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;background:%s;color:%s;">%s</span>',
			esc_attr( $c[0] ),
			esc_attr( $c[1] ),
			esc_html( $l )
		);
	}
}
