<?php
/**
 * Setup wizard shown after first activation.
 *
 * Guides the admin through initial configuration: creating pages,
 * mapping products, configuring rates, and running a health check.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Konx_Setup_Wizard {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
	}

	/**
	 * Hidden page (not in menu) for the setup wizard.
	 */
	public static function register_page() {
		add_submenu_page(
			null, // Hidden from menu.
			__( 'Setup Wizard', 'konx-affiliate-dashboard' ),
			'',
			'manage_konx_settings',
			'konx-setup-wizard',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Redirect to wizard on first activation.
	 */
	public static function maybe_redirect() {
		if ( get_transient( 'konx_activation_redirect' ) ) {
			delete_transient( 'konx_activation_redirect' );

			if ( ! is_network_admin() && ! isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				wp_safe_redirect( admin_url( 'admin.php?page=konx-setup-wizard' ) );
				exit;
			}
		}
	}

	/**
	 * Set the redirect flag during activation.
	 */
	public static function set_activation_redirect() {
		set_transient( 'konx_activation_redirect', 1, 30 );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		$steps = self::get_steps();
		$completed = 0;
		foreach ( $steps as $s ) {
			if ( $s['done'] ) $completed++;
		}
		$percent = count( $steps ) > 0 ? round( ( $completed / count( $steps ) ) * 100, 0 ) : 0;

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Welcome to KonX Affiliate Dashboard', 'konx-affiliate-dashboard' ); ?></h1>
			<p><?php esc_html_e( 'Complete these steps to get your affiliate program running.', 'konx-affiliate-dashboard' ); ?></p>

			<div style="max-width:700px;margin:20px 0;">
				<div style="background:#e0e0e0;border-radius:10px;height:20px;overflow:hidden;margin-bottom:8px;">
					<div style="background:linear-gradient(90deg,#2271b1,#4fa3d1);height:100%;border-radius:10px;width:<?php echo esc_attr( $percent ); ?>%;transition:width 0.5s;"></div>
				</div>
				<p style="font-size:14px;color:#646970;"><?php printf( esc_html__( '%d of %d steps completed', 'konx-affiliate-dashboard' ), $completed, count( $steps ) ); ?></p>
			</div>

			<div style="max-width:700px;">
				<?php foreach ( $steps as $i => $step ) : ?>
					<div class="konx-card" style="margin-bottom:12px;display:flex;gap:14px;align-items:flex-start;">
						<div style="width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700;font-size:14px;<?php echo $step['done'] ? 'background:#edfaef;color:#00a32a;' : 'background:#f0f0f1;color:#646970;'; ?>">
							<?php echo $step['done'] ? '&#10003;' : esc_html( $i + 1 ); ?>
						</div>
						<div style="flex:1;">
							<strong><?php echo esc_html( $step['title'] ); ?></strong>
							<p style="margin:4px 0 0;font-size:13px;color:#646970;"><?php echo esc_html( $step['description'] ); ?></p>
						</div>
						<?php if ( ! $step['done'] && $step['url'] ) : ?>
							<a href="<?php echo esc_url( $step['url'] ); ?>" class="button button-primary button-small"><?php echo esc_html( $step['action'] ); ?></a>
						<?php elseif ( $step['done'] ) : ?>
							<span class="konx-badge konx-badge-approved"><?php esc_html_e( 'Done', 'konx-affiliate-dashboard' ); ?></span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( $percent >= 100 ) : ?>
				<div style="margin-top:20px;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-affiliate-dashboard' ) ); ?>" class="button button-primary button-hero"><?php esc_html_e( 'Go to Dashboard', 'konx-affiliate-dashboard' ); ?></a>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function get_steps() {
		global $wpdb;

		$reg_page  = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_content LIKE '%[konx_affiliate_register]%' LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$dash_page = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_content LIKE '%[konx_affiliate_dashboard]%' LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$mappings  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}konx_product_map" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rules     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}konx_commission_rules" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$reg_url  = $reg_page ? get_permalink( $reg_page ) : '';
		$dash_url = $dash_page ? get_permalink( $dash_page ) : '';

		return array(
			array(
				'title'       => __( 'Registration Page', 'konx-affiliate-dashboard' ),
				'description' => $reg_page
					? sprintf( __( 'Created automatically: %s', 'konx-affiliate-dashboard' ), $reg_url )
					: __( 'A registration page will be created automatically on activation.', 'konx-affiliate-dashboard' ),
				'done'        => (bool) $reg_page,
				'url'         => $reg_url ?: admin_url( 'post-new.php?post_type=page' ),
				'action'      => $reg_page ? __( 'View Page', 'konx-affiliate-dashboard' ) : __( 'Create Page', 'konx-affiliate-dashboard' ),
			),
			array(
				'title'       => __( 'Dashboard Page', 'konx-affiliate-dashboard' ),
				'description' => $dash_page
					? sprintf( __( 'Created automatically: %s', 'konx-affiliate-dashboard' ), $dash_url )
					: __( 'A dashboard page will be created automatically on activation.', 'konx-affiliate-dashboard' ),
				'done'        => (bool) $dash_page,
				'url'         => $dash_url ?: admin_url( 'post-new.php?post_type=page' ),
				'action'      => $dash_page ? __( 'View Page', 'konx-affiliate-dashboard' ) : __( 'Create Page', 'konx-affiliate-dashboard' ),
			),
			array(
				'title'       => __( 'Map WooCommerce Products', 'konx-affiliate-dashboard' ),
				'description' => __( 'Map your products to commission categories (Starter Pack, Pro Pack, etc.).', 'konx-affiliate-dashboard' ),
				'done'        => $mappings > 0,
				'url'         => admin_url( 'admin.php?page=konx-product-mapping' ),
				'action'      => __( 'Map Products', 'konx-affiliate-dashboard' ),
			),
			array(
				'title'       => __( 'Configure Commission Rates', 'konx-affiliate-dashboard' ),
				'description' => __( 'Review and adjust commission rates for each affiliate type.', 'konx-affiliate-dashboard' ),
				'done'        => $rules >= 20,
				'url'         => admin_url( 'admin.php?page=konx-settings' ),
				'action'      => __( 'Configure', 'konx-affiliate-dashboard' ),
			),
			array(
				'title'       => __( 'Run System Check', 'konx-affiliate-dashboard' ),
				'description' => __( 'Verify all database tables, roles, and requirements are in place.', 'konx-affiliate-dashboard' ),
				'done'        => true, // Always done since activation creates everything.
				'url'         => admin_url( 'admin.php?page=konx-system-status' ),
				'action'      => __( 'Check', 'konx-affiliate-dashboard' ),
			),
		);
	}
}
