<?php
/**
 * Unified Tools page with tabs.
 *
 * Consolidates Activity Log, Notifications, Financial Audit,
 * System Status, and Help Center into a single tabbed page.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Konx_Tools_Page {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	public static function register_menu() {
		$notif_count = Konx_Notification_Center::get_notification_count();
		$title       = __( 'Tools', 'konx-affiliate-dashboard' );
		if ( $notif_count > 0 ) {
			$title .= sprintf( ' <span class="awaiting-mod">%d</span>', $notif_count );
		}

		add_submenu_page(
			'konx-affiliate-dashboard',
			__( 'Tools', 'konx-affiliate-dashboard' ),
			$title,
			'manage_konx_settings',
			'konx-tools',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'notifications';

		$tabs = array(
			'notifications'   => __( 'Notifications', 'konx-affiliate-dashboard' ),
			'activity-log'    => __( 'Activity Log', 'konx-affiliate-dashboard' ),
			'financial-audit' => __( 'Financial Audit', 'konx-affiliate-dashboard' ),
			'api-keys'        => __( 'API Keys', 'konx-affiliate-dashboard' ),
			'system-status'   => __( 'System Status', 'konx-affiliate-dashboard' ),
			'help'            => __( 'Help Center', 'konx-affiliate-dashboard' ),
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tools', 'konx-affiliate-dashboard' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-tools&tab=' . $slug ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
						<?php if ( 'notifications' === $slug ) :
							$count = Konx_Notification_Center::get_notification_count();
							if ( $count > 0 ) : ?>
								<span class="awaiting-mod" style="margin-left:4px;"><?php echo esc_html( $count ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div style="margin-top:20px;">
				<?php
				switch ( $active_tab ) {
					case 'activity-log':
						Konx_Activity_Log_Page::render_content();
						break;
					case 'financial-audit':
						Konx_Financial_Audit::render_content();
						break;
					case 'api-keys':
						Konx_Api_Keys_Page::render_content();
						break;
					case 'system-status':
						Konx_System_Status::render_content();
						break;
					case 'help':
						Konx_Help_Center::render_content();
						break;
					case 'notifications':
					default:
						Konx_Notification_Center::render_content();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}
}
