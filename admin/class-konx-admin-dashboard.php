<?php
/**
 * Admin overview dashboard with Chart.js charts.
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

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 5 );
	}

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

		add_submenu_page(
			'konx-affiliate-dashboard',
			__( 'Overview', 'konx-affiliate-dashboard' ),
			__( 'Overview', 'konx-affiliate-dashboard' ),
			'manage_konx_settings',
			'konx-affiliate-dashboard',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'konx-affiliate-dashboard' ) );
		}

		$stats  = self::get_overview_stats();
		$recent = self::get_recent_activity();
		$chart  = self::get_chart_data();

		wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', array(), '4.4.4', true );

		?>
		<div class="wrap">
			<div class="konx-page-header">
				<h1><?php esc_html_e( 'KonX Affiliates — Overview', 'konx-affiliate-dashboard' ); ?></h1>
			</div>

			<!-- Stats -->
			<div class="konx-stats-grid">
				<?php foreach ( $stats as $stat ) : ?>
					<div class="konx-stat-card">
						<span class="konx-stat-value"><?php echo esc_html( $stat['value'] ); ?></span>
						<span class="konx-stat-label"><?php echo esc_html( $stat['label'] ); ?>
							<?php if ( ! empty( $stat['tip'] ) ) { echo Konx_Tooltip_Helper::get( $stat['tip'] ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</span>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Charts -->
			<div class="konx-grid-2">
				<div class="konx-chart-wrap">
					<h3><?php esc_html_e( 'Monthly Commissions', 'konx-affiliate-dashboard' ); ?></h3>
					<canvas id="konx-chart-commissions"></canvas>
				</div>
				<div class="konx-chart-wrap">
					<h3><?php esc_html_e( 'Withdrawal Volume', 'konx-affiliate-dashboard' ); ?></h3>
					<canvas id="konx-chart-withdrawals"></canvas>
				</div>
			</div>

			<!-- Recent Activity -->
			<div class="konx-grid-2">
				<div class="konx-card">
					<h2><?php esc_html_e( 'Recent Commissions', 'konx-affiliate-dashboard' ); ?></h2>
					<?php if ( empty( $recent['commissions'] ) ) : ?>
						<div class="konx-empty-state">
							<span class="dashicons dashicons-chart-bar"></span>
							<p><?php esc_html_e( 'No commissions yet. Commissions appear when referred orders are completed.', 'konx-affiliate-dashboard' ); ?></p>
						</div>
					<?php else : ?>
						<div class="konx-table-wrap">
							<table class="widefat fixed striped">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Date', 'konx-affiliate-dashboard' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Affiliate', 'konx-affiliate-dashboard' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Product', 'konx-affiliate-dashboard' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Amount', 'konx-affiliate-dashboard' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $recent['commissions'] as $row ) : ?>
										<tr>
											<td><?php echo esc_html( date_i18n( 'M j', strtotime( $row->created_at ) ) ); ?></td>
											<td><?php echo esc_html( $row->display_name ); ?></td>
											<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $row->product_type ) ) ); ?></td>
											<td><strong>$<?php echo esc_html( $row->commission_amount ); ?></strong></td>
											<td><span class="konx-badge konx-badge-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( ucfirst( $row->status ) ); ?></span></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>

				<div class="konx-card">
					<h2><?php esc_html_e( 'Recent Withdrawals', 'konx-affiliate-dashboard' ); ?></h2>
					<?php if ( empty( $recent['withdrawals'] ) ) : ?>
						<div class="konx-empty-state">
							<span class="dashicons dashicons-money-alt"></span>
							<p><?php esc_html_e( 'No withdrawals yet. Affiliates can request withdrawals from their dashboard.', 'konx-affiliate-dashboard' ); ?></p>
						</div>
					<?php else : ?>
						<div class="konx-table-wrap">
							<table class="widefat fixed striped">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Date', 'konx-affiliate-dashboard' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Affiliate', 'konx-affiliate-dashboard' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Amount', 'konx-affiliate-dashboard' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $recent['withdrawals'] as $row ) : ?>
										<tr>
											<td><?php echo esc_html( date_i18n( 'M j', strtotime( $row->requested_at ) ) ); ?></td>
											<td><?php echo esc_html( $row->display_name ); ?></td>
											<td><strong>$<?php echo esc_html( $row->amount ); ?></strong></td>
											<td><span class="konx-badge konx-badge-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( ucfirst( $row->status ) ); ?></span></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Utility cards removed — exports are in Reports page,
			     System Status and Help Center accessible via menu -->
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function(){
			if (typeof Chart === 'undefined') return;

			// Monthly Commissions Line Chart
			var commData = <?php echo wp_json_encode( $chart['commissions'] ); ?>;
			new Chart(document.getElementById('konx-chart-commissions'), {
				type: 'line',
				data: {
					labels: commData.labels,
					datasets: [{
						label: '<?php echo esc_js( __( 'Commissions', 'konx-affiliate-dashboard' ) ); ?>',
						data: commData.values,
						borderColor: '#2271b1',
						backgroundColor: 'rgba(34,113,177,0.08)',
						fill: true, tension: 0.3, pointRadius: 4
					}]
				},
				options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: function(v){ return '$'+v; } } } } }
			});

			// Withdrawal Volume Bar Chart
			var wdData = <?php echo wp_json_encode( $chart['withdrawals'] ); ?>;
			new Chart(document.getElementById('konx-chart-withdrawals'), {
				type: 'bar',
				data: {
					labels: wdData.labels,
					datasets: [{
						label: '<?php echo esc_js( __( 'Withdrawals', 'konx-affiliate-dashboard' ) ); ?>',
						data: wdData.values,
						backgroundColor: '#00a32a', borderRadius: 4
					}]
				},
				options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: function(v){ return '$'+v; } } } } }
			});
		});
		</script>
		<?php
	}

	private static function get_overview_stats() {
		global $wpdb;
		$aff = $wpdb->prefix . 'konx_affiliates';
		$comm = $wpdb->prefix . 'konx_commissions';
		$mile = $wpdb->prefix . 'konx_milestones';
		$wd = $wpdb->prefix . 'konx_withdrawals';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array(
			array( 'value' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$aff}" ), 'label' => __( 'Total Affiliates', 'konx-affiliate-dashboard' ) ),
			array( 'value' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$aff} WHERE status = %s", 'active' ) ), 'label' => __( 'Active Affiliates', 'konx-affiliate-dashboard' ), 'tip' => '' ),
			array( 'value' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wd} WHERE status IN (%s,%s)", 'pending', 'approved' ) ), 'label' => __( 'Pending Withdrawals', 'konx-affiliate-dashboard' ), 'tip' => 'pending_withdrawals' ),
			array( 'value' => '$' . number_format( (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(commission_amount),0) FROM {$comm} WHERE status = %s AND commission_type = %s", 'approved', 'one_time' ) ), 2 ), 'label' => __( 'Pack Commissions', 'konx-affiliate-dashboard' ), 'tip' => 'pack_commissions' ),
			array( 'value' => '$' . number_format( (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(commission_amount),0) FROM {$comm} WHERE status = %s AND commission_type = %s", 'approved', 'recurring' ) ), 2 ), 'label' => __( 'Subscription Commissions', 'konx-affiliate-dashboard' ), 'tip' => 'sub_commissions' ),
			array( 'value' => '$' . number_format( (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(bonus_amount),0) FROM {$mile} WHERE status = %s", 'approved' ) ), 2 ), 'label' => __( 'Milestone Bonuses', 'konx-affiliate-dashboard' ), 'tip' => 'milestone_bonus' ),
			array( 'value' => '$' . number_format( (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$wd} WHERE status = %s", 'completed' ) ), 2 ), 'label' => __( 'Total Paid to Affiliates', 'konx-affiliate-dashboard' ), 'tip' => '' ),
			array( 'value' => '$' . number_format( (float) $wpdb->get_var( "SELECT COALESCE(SUM(cached_balance),0) FROM {$aff}" ), 2 ), 'label' => __( 'Unpaid Balances', 'konx-affiliate-dashboard' ), 'tip' => 'unpaid_balances' ),
		);
		// phpcs:enable
	}

	private static function get_recent_activity() {
		global $wpdb;
		$comm = $wpdb->prefix . 'konx_commissions';
		$wd = $wpdb->prefix . 'konx_withdrawals';
		$aff = $wpdb->prefix . 'konx_affiliates';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$commissions = $wpdb->get_results( "SELECT c.*, u.display_name FROM {$comm} c INNER JOIN {$aff} a ON c.affiliate_id = a.id INNER JOIN {$wpdb->users} u ON a.user_id = u.ID ORDER BY c.created_at DESC LIMIT 10" );
		$withdrawals = $wpdb->get_results( "SELECT w.*, u.display_name FROM {$wd} w INNER JOIN {$aff} a ON w.affiliate_id = a.id INNER JOIN {$wpdb->users} u ON a.user_id = u.ID ORDER BY w.requested_at DESC LIMIT 10" );
		// phpcs:enable
		return array( 'commissions' => $commissions ?: array(), 'withdrawals' => $withdrawals ?: array() );
	}

	private static function get_chart_data() {
		global $wpdb;
		$comm = $wpdb->prefix . 'konx_commissions';
		$wd = $wpdb->prefix . 'konx_withdrawals';
		$labels = array();
		$cv = array();
		$wv = array();
		for ( $i = 5; $i >= 0; $i-- ) {
			$ms = gmdate( 'Y-m-01', strtotime( "-{$i} months" ) );
			$me = gmdate( 'Y-m-t', strtotime( "-{$i} months" ) );
			$labels[] = gmdate( 'M', strtotime( $ms ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$cv[] = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(commission_amount),0) FROM {$comm} WHERE status='approved' AND created_at BETWEEN %s AND %s", $ms . ' 00:00:00', $me . ' 23:59:59' ) );
			$wv[] = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$wd} WHERE status='completed' AND processed_at BETWEEN %s AND %s", $ms . ' 00:00:00', $me . ' 23:59:59' ) );
			// phpcs:enable
		}
		return array(
			'commissions' => array( 'labels' => $labels, 'values' => $cv ),
			'withdrawals' => array( 'labels' => $labels, 'values' => $wv ),
		);
	}
}
