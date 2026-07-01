<?php
/**
 * Admin Help Center page.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Konx_Help_Center {

	public static function init() {
		// Help Center renders as a tab inside Tools — no separate menu needed.
	}

	public static function register_menu() {
		add_submenu_page(
			'konx-affiliate-dashboard',
			__( 'Help Center', 'konx-affiliate-dashboard' ),
			__( 'Help Center', 'konx-affiliate-dashboard' ),
			'manage_konx_settings',
			'konx-help',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Help Center', 'konx-affiliate-dashboard' ) . '</h1>';
		self::render_content();
		echo '</div>';
	}

	/** Render inner content (used by Tools page). */
	public static function render_content() {
		?>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-top:20px;">

				<?php self::card( 'dashicons-welcome-learn-more', __( 'Getting Started', 'konx-affiliate-dashboard' ), __( '1. Activate the plugin<br>2. Map WooCommerce products<br>3. Configure commission rates<br>4. Create registration &amp; dashboard pages<br>5. Start accepting affiliates', 'konx-affiliate-dashboard' ) ); ?>

				<?php self::card( 'dashicons-shortcode', __( 'Shortcodes', 'konx-affiliate-dashboard' ), '<code>[konx_affiliate_register]</code><br>' . esc_html__( 'Affiliate registration form.', 'konx-affiliate-dashboard' ) . '<br><br><code>[konx_affiliate_dashboard]</code><br>' . esc_html__( 'Affiliate dashboard with stats, commissions, withdrawals.', 'konx-affiliate-dashboard' ) ); ?>

				<?php self::card( 'dashicons-editor-help', __( 'FAQ', 'konx-affiliate-dashboard' ), '<strong>' . esc_html__( 'How are commissions calculated?', 'konx-affiliate-dashboard' ) . '</strong><br>' . esc_html__( 'From the full product price before discounts, coupons, and taxes.', 'konx-affiliate-dashboard' ) . '<br><br><strong>' . esc_html__( 'What is the milestone bonus?', 'konx-affiliate-dashboard' ) . '</strong><br>' . esc_html__( 'Every 100 sales, you earn a bonus equal to the total commissions from that block.', 'konx-affiliate-dashboard' ) . '<br><br><strong>' . esc_html__( 'Why are commissions blocked?', 'konx-affiliate-dashboard' ) . '</strong><br>' . esc_html__( 'Unpaid admin fees pause commission earnings until fees are resolved.', 'konx-affiliate-dashboard' ) ); ?>

				<?php self::card( 'dashicons-sos', __( 'Troubleshooting', 'konx-affiliate-dashboard' ), '<strong>' . esc_html__( 'Commissions not appearing?', 'konx-affiliate-dashboard' ) . '</strong><br>' . esc_html__( 'Check: product is mapped, affiliate is active, admin fees are paid, order is completed.', 'konx-affiliate-dashboard' ) . '<br><br><strong>' . esc_html__( 'Referral not tracking?', 'konx-affiliate-dashboard' ) . '</strong><br>' . esc_html__( 'Ensure the ?ref= URL parameter matches a valid, active affiliate code.', 'konx-affiliate-dashboard' ) ); ?>

				<?php self::card( 'dashicons-admin-tools', __( 'System Status', 'konx-affiliate-dashboard' ), sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=konx-settings&tab=system-status' ) ), esc_html__( 'View System Status', 'konx-affiliate-dashboard' ) ) . '<br>' . esc_html__( 'Check plugin health, database tables, cron status, and requirements.', 'konx-affiliate-dashboard' ) ); ?>

				<?php self::card( 'dashicons-info-outline', __( 'Version', 'konx-affiliate-dashboard' ), sprintf( esc_html__( 'Plugin Version: %s', 'konx-affiliate-dashboard' ), '<strong>' . KONX_AFFILIATE_VERSION . '</strong>' ) . '<br>' . sprintf( esc_html__( 'Database Version: %s', 'konx-affiliate-dashboard' ), '<strong>' . KONX_AFFILIATE_DB_VERSION . '</strong>' ) . '<br><br>' . sprintf( '<a href="%s" target="_blank">%s</a>', 'https://github.com/toxickim24/konx-affiliate-dashboard', esc_html__( 'GitHub Repository', 'konx-affiliate-dashboard' ) ) ); ?>

				<?php self::card( 'dashicons-email', __( 'Support', 'konx-affiliate-dashboard' ), esc_html__( 'For technical support, contact:', 'konx-affiliate-dashboard' ) . '<br><a href="mailto:support@konx.world">support@konx.world</a><br><br>' . sprintf( '<a href="%s" target="_blank">%s</a>', 'https://github.com/toxickim24/konx-affiliate-dashboard/issues', esc_html__( 'Report an Issue', 'konx-affiliate-dashboard' ) ) ); ?>
			</div>
		<?php
	}

	private static function card( $icon, $title, $content ) {
		printf(
			'<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;">'
			. '<h3 style="margin:0 0 12px;display:flex;align-items:center;gap:8px;font-size:15px;">'
			. '<span class="dashicons %s" style="color:#2271b1;"></span>%s</h3>'
			. '<div style="font-size:13px;line-height:1.7;color:#1d2327;">%s</div></div>',
			esc_attr( $icon ),
			esc_html( $title ),
			wp_kses_post( $content )
		);
	}
}
