<?php
/**
 * Admin notification center.
 *
 * Shows actionable notifications in the admin bar and a dedicated
 * page for pending items that need admin attention.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Konx_Notification_Center {

	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_admin_bar_node' ), 100 );
		// Menu registered by Konx_Tools_Page.
	}

	public static function register_menu() {
		$count = self::get_notification_count();
		$title = __( 'Notifications', 'konx-affiliate-dashboard' );
		if ( $count > 0 ) {
			$title .= sprintf( ' <span class="awaiting-mod">%d</span>', $count );
		}

		add_submenu_page(
			'konx-affiliate-dashboard',
			__( 'Notifications', 'konx-affiliate-dashboard' ),
			$title,
			'manage_konx_settings',
			'konx-notifications',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Add notification count to WordPress admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public static function add_admin_bar_node( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			return;
		}

		$count = self::get_notification_count();
		if ( $count === 0 ) {
			return;
		}

		$wp_admin_bar->add_node( array(
			'id'    => 'konx-notifications',
			'title' => sprintf(
				'<span class="ab-icon dashicons dashicons-bell" style="margin-top:2px;"></span><span class="ab-label">%d</span>',
				$count
			),
			'href'  => admin_url( 'admin.php?page=konx-notifications' ),
			'meta'  => array( 'title' => sprintf( __( '%d items need attention', 'konx-affiliate-dashboard' ), $count ) ),
		) );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Notifications', 'konx-affiliate-dashboard' ) . '</h1>';
		self::render_content();
		echo '</div>';
	}

	/** Render inner content (used by Tools page). */
	public static function render_content() {
		$notifications = self::get_notifications();

		?>
			<?php if ( empty( $notifications ) ) : ?>
				<div class="konx-empty-state">
					<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
					<p><?php esc_html_e( 'All clear! No pending items need your attention.', 'konx-affiliate-dashboard' ); ?></p>
				</div>
			<?php else : ?>
				<div style="max-width:800px;">
					<?php foreach ( $notifications as $n ) : ?>
						<div class="konx-card" style="margin-bottom:12px;display:flex;gap:14px;align-items:flex-start;">
							<span class="dashicons <?php echo esc_attr( $n['icon'] ); ?>" style="color:<?php echo esc_attr( $n['color'] ); ?>;font-size:24px;width:24px;height:24px;flex-shrink:0;margin-top:2px;"></span>
							<div style="flex:1;">
								<strong><?php echo esc_html( $n['title'] ); ?></strong>
								<p style="margin:4px 0 0;font-size:13px;color:#646970;"><?php echo esc_html( $n['description'] ); ?></p>
							</div>
							<?php if ( $n['url'] ) : ?>
								<a href="<?php echo esc_url( $n['url'] ); ?>" class="button button-small"><?php esc_html_e( 'View', 'konx-affiliate-dashboard' ); ?></a>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		<?php
	}

	/**
	 * Get the count of actionable notifications.
	 *
	 * @return int
	 */
	public static function get_notification_count() {
		global $wpdb;

		$count = 0;

		// Pending withdrawals.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count += (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_withdrawals WHERE status IN (%s, %s)",
			'pending', 'approved'
		) );

		// Pending affiliates.
		$count += (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_affiliates WHERE status = %s",
			'pending'
		) );

		// Overdue fees.
		$count += (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_admin_fees WHERE status = %s",
			'overdue'
		) );
		// phpcs:enable

		return $count;
	}

	/**
	 * Get all current notifications.
	 *
	 * @return array
	 */
	private static function get_notifications() {
		global $wpdb;
		$notifications = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Pending withdrawals.
		$pending_wd = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_withdrawals WHERE status = %s", 'pending'
		) );
		if ( $pending_wd > 0 ) {
			$notifications[] = array(
				'icon'        => 'dashicons-migrate',
				'color'       => '#dba617',
				'title'       => sprintf( __( '%d Pending Withdrawal(s)', 'konx-affiliate-dashboard' ), $pending_wd ),
				'description' => __( 'Withdrawal requests are waiting for your review.', 'konx-affiliate-dashboard' ),
				'url'         => admin_url( 'admin.php?page=konx-withdrawals&status=pending' ),
			);
		}

		// Pending affiliates (Business awaiting approval).
		$pending_aff = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_affiliates WHERE status = %s", 'pending'
		) );
		if ( $pending_aff > 0 ) {
			$notifications[] = array(
				'icon'        => 'dashicons-admin-users',
				'color'       => '#2271b1',
				'title'       => sprintf( __( '%d Business Affiliate(s) Awaiting Approval', 'konx-affiliate-dashboard' ), $pending_aff ),
				'description' => __( 'Business affiliates need pack purchase verification and activation.', 'konx-affiliate-dashboard' ),
				'url'         => admin_url( 'admin.php?page=konx-affiliates&status=pending' ),
			);
		}

		// Overdue admin fees.
		$overdue = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_admin_fees WHERE status = %s", 'overdue'
		) );
		if ( $overdue > 0 ) {
			$notifications[] = array(
				'icon'        => 'dashicons-warning',
				'color'       => '#d63638',
				'title'       => sprintf( __( '%d Overdue Admin Fee(s)', 'konx-affiliate-dashboard' ), $overdue ),
				'description' => __( 'Affiliates with overdue fees have their commissions blocked.', 'konx-affiliate-dashboard' ),
				'url'         => admin_url( 'admin.php?page=konx-admin-fees&status=overdue' ),
			);
		}

		// Missing product mappings.
		$mapped = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}konx_product_map" );
		if ( $mapped === 0 ) {
			$notifications[] = array(
				'icon'        => 'dashicons-warning',
				'color'       => '#dba617',
				'title'       => __( 'No Products Mapped', 'konx-affiliate-dashboard' ),
				'description' => __( 'Map WooCommerce products to commission categories so affiliates can earn commissions.', 'konx-affiliate-dashboard' ),
				'url'         => admin_url( 'admin.php?page=konx-settings&tab=product-mapping' ),
			);
		}

		// phpcs:enable

		return $notifications;
	}
}
