<?php
/**
 * Admin overview dashboard.
 *
 * Registers the top-level "KonX Affiliates" admin menu and renders
 * the overview page with key metrics and recent activity.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Admin_Dashboard
 */
class Konx_Admin_Dashboard {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 5 );
	}

	/**
	 * Register the top-level menu and the Overview submenu.
	 *
	 * Priority 5 ensures this runs before other submenu registrations.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'KonX Affiliates', 'konx-affiliate-dashboard' ),
			__( 'KonX Affiliates', 'konx-affiliate-dashboard' ),
			'manage_konx_settings',
			'konx-affiliate-dashboard',
			array( __CLASS__, 'render_page' ),
			'dashicons-groups',
			58
		);

		// Replace the auto-created submenu item with "Overview".
		add_submenu_page(
			'konx-affiliate-dashboard',
			__( 'Overview', 'konx-affiliate-dashboard' ),
			__( 'Overview', 'konx-affiliate-dashboard' ),
			'manage_konx_settings',
			'konx-affiliate-dashboard',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the overview page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'konx-affiliate-dashboard' ) );
		}

		$stats  = self::get_overview_stats();
		$recent = self::get_recent_activity();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'KonX Affiliates — Overview', 'konx-affiliate-dashboard' ); ?></h1>

			<!-- Stats Cards -->
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin:20px 0;">
				<?php foreach ( $stats as $stat ) : ?>
					<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;text-align:center;">
						<div style="font-size:28px;font-weight:700;color:#1d2327;"><?php echo esc_html( $stat['value'] ); ?></div>
						<div style="font-size:13px;color:#646970;margin-top:4px;"><?php echo esc_html( $stat['label'] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Recent Commissions -->
			<h2><?php esc_html_e( 'Recent Commissions', 'konx-affiliate-dashboard' ); ?></h2>
			<?php if ( empty( $recent['commissions'] ) ) : ?>
				<p><?php esc_html_e( 'No commissions yet.', 'konx-affiliate-dashboard' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Affiliate', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Product', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Type', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent['commissions'] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->created_at ); ?></td>
								<td><?php echo esc_html( $row->display_name ); ?></td>
								<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $row->product_type ) ) ); ?></td>
								<td><?php echo esc_html( 'recurring' === $row->commission_type ? 'Recurring' : 'One-Time' ); ?></td>
								<td>$<?php echo esc_html( $row->commission_amount ); ?></td>
								<td><?php echo esc_html( ucfirst( $row->status ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<!-- Recent Withdrawals -->
			<h2 style="margin-top:24px;"><?php esc_html_e( 'Recent Withdrawals', 'konx-affiliate-dashboard' ); ?></h2>
			<?php if ( empty( $recent['withdrawals'] ) ) : ?>
				<p><?php esc_html_e( 'No withdrawals yet.', 'konx-affiliate-dashboard' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Affiliate', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent['withdrawals'] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->requested_at ); ?></td>
								<td><?php echo esc_html( $row->display_name ); ?></td>
								<td>$<?php echo esc_html( $row->amount ); ?></td>
								<td><?php echo esc_html( ucfirst( $row->status ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Data Queries
	// ------------------------------------------------------------------

	/**
	 * Get overview statistics.
	 *
	 * @return array Array of { value, label } pairs.
	 */
	private static function get_overview_stats() {
		global $wpdb;

		$aff   = $wpdb->prefix . 'konx_affiliates';
		$comm  = $wpdb->prefix . 'konx_commissions';
		$mile  = $wpdb->prefix . 'konx_milestones';
		$wd    = $wpdb->prefix . 'konx_withdrawals';
		$ledg  = $wpdb->prefix . 'konx_wallet_ledger';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$total_affiliates  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$aff}" );
		$active_affiliates = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$aff} WHERE status = %s", 'active' ) );

		$pending_wd = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wd} WHERE status IN (%s, %s)",
			'pending',
			'approved'
		) );

		$approved_commissions = $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(commission_amount), 0) FROM {$comm} WHERE status = %s AND commission_type = %s",
			'approved',
			'one_time'
		) );

		$recurring_commissions = $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(commission_amount), 0) FROM {$comm} WHERE status = %s AND commission_type = %s",
			'approved',
			'recurring'
		) );

		$total_bonuses = $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(bonus_amount), 0) FROM {$mile} WHERE status = %s",
			'approved'
		) );

		$completed_withdrawals = $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount), 0) FROM {$wd} WHERE status = %s",
			'completed'
		) );

		$total_balance = $wpdb->get_var( "SELECT COALESCE(SUM(cached_balance), 0) FROM {$aff}" );

		// phpcs:enable

		return array(
			array( 'value' => $total_affiliates, 'label' => __( 'Total Affiliates', 'konx-affiliate-dashboard' ) ),
			array( 'value' => $active_affiliates, 'label' => __( 'Active Affiliates', 'konx-affiliate-dashboard' ) ),
			array( 'value' => $pending_wd, 'label' => __( 'Pending Withdrawals', 'konx-affiliate-dashboard' ) ),
			array( 'value' => '$' . number_format( (float) $approved_commissions, 2 ), 'label' => __( 'One-Time Commissions', 'konx-affiliate-dashboard' ) ),
			array( 'value' => '$' . number_format( (float) $recurring_commissions, 2 ), 'label' => __( 'Recurring Commissions', 'konx-affiliate-dashboard' ) ),
			array( 'value' => '$' . number_format( (float) $total_bonuses, 2 ), 'label' => __( 'Milestone Bonuses', 'konx-affiliate-dashboard' ) ),
			array( 'value' => '$' . number_format( (float) $completed_withdrawals, 2 ), 'label' => __( 'Withdrawals Paid', 'konx-affiliate-dashboard' ) ),
			array( 'value' => '$' . number_format( (float) $total_balance, 2 ), 'label' => __( 'Total Wallet Balance', 'konx-affiliate-dashboard' ) ),
		);
	}

	/**
	 * Get recent commissions and withdrawals.
	 *
	 * @return array { commissions: array, withdrawals: array }
	 */
	private static function get_recent_activity() {
		global $wpdb;

		$comm      = $wpdb->prefix . 'konx_commissions';
		$wd        = $wpdb->prefix . 'konx_withdrawals';
		$aff       = $wpdb->prefix . 'konx_affiliates';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$commissions = $wpdb->get_results(
			"SELECT c.*, u.display_name
			 FROM {$comm} c
			 INNER JOIN {$aff} a ON c.affiliate_id = a.id
			 INNER JOIN {$wpdb->users} u ON a.user_id = u.ID
			 ORDER BY c.created_at DESC
			 LIMIT 10"
		);

		$withdrawals = $wpdb->get_results(
			"SELECT w.*, u.display_name
			 FROM {$wd} w
			 INNER JOIN {$aff} a ON w.affiliate_id = a.id
			 INNER JOIN {$wpdb->users} u ON a.user_id = u.ID
			 ORDER BY w.requested_at DESC
			 LIMIT 10"
		);

		// phpcs:enable

		return array(
			'commissions' => $commissions ? $commissions : array(),
			'withdrawals' => $withdrawals ? $withdrawals : array(),
		);
	}
}
