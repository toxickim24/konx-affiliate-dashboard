<?php
/**
 * Admin Operations Dashboard with KPI cards, health checks, and quick actions.
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

		$setup   = self::get_setup_status();
		$kpis    = self::get_kpi_data();
		$actions = self::get_action_required();
		$health  = self::get_platform_health();
		$chart   = self::get_chart_data();
		$recent  = self::get_recent_activity();

		wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', array(), '4.4.4', true );

		?>
		<div class="wrap konx-ops-dashboard">
			<div class="konx-page-header">
				<h1><?php esc_html_e( 'KonX Affiliates — Operations Dashboard', 'konx-affiliate-dashboard' ); ?></h1>
			</div>

			<?php self::render_setup_checklist( $setup ); ?>

			<?php self::render_kpi_section( $kpis ); ?>

			<?php self::render_action_required( $actions ); ?>

			<div class="konx-grid-2 konx-ops-row">
				<div>
					<?php self::render_platform_health( $health ); ?>
				</div>
				<div>
					<?php self::render_quick_actions(); ?>
				</div>
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

	// ------------------------------------------------------------------
	// Section 1 — Operations Summary (KPI Cards)
	// ------------------------------------------------------------------

	private static function get_kpi_data() {
		global $wpdb;
		$aff  = $wpdb->prefix . 'konx_affiliates';
		$comm = $wpdb->prefix . 'konx_commissions';
		$wd   = $wpdb->prefix . 'konx_withdrawals';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_affiliates   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$aff}" );
		$approved_affiliates = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$aff} WHERE status = %s", 'active' ) );
		$pending_apps       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$aff} WHERE status = %s", 'pending' ) );
		$pending_withdrawals = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wd} WHERE status IN (%s,%s)", 'pending', 'approved' ) );

		$month_start = gmdate( 'Y-m-01 00:00:00' );
		$month_end   = gmdate( 'Y-m-t 23:59:59' );
		$monthly_commissions = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(commission_amount),0) FROM {$comm} WHERE status = %s AND created_at BETWEEN %s AND %s",
			'approved', $month_start, $month_end
		) );

		$wallet_balance = (float) $wpdb->get_var( "SELECT COALESCE(SUM(cached_balance),0) FROM {$aff}" );
		// phpcs:enable

		return array(
			array(
				'value' => $total_affiliates,
				'label' => __( 'Total Affiliates', 'konx-affiliate-dashboard' ),
				'icon'  => 'dashicons-groups',
				'url'   => admin_url( 'admin.php?page=konx-affiliates' ),
				'tip'   => '',
			),
			array(
				'value' => $approved_affiliates,
				'label' => __( 'Approved Affiliates', 'konx-affiliate-dashboard' ),
				'icon'  => 'dashicons-yes-alt',
				'url'   => admin_url( 'admin.php?page=konx-affiliates&status=active' ),
				'tip'   => '',
			),
			array(
				'value' => $pending_apps,
				'label' => __( 'Pending Applications', 'konx-affiliate-dashboard' ),
				'icon'  => 'dashicons-clock',
				'url'   => admin_url( 'admin.php?page=konx-affiliates&status=pending' ),
				'tip'   => '',
			),
			array(
				'value' => $pending_withdrawals,
				'label' => __( 'Pending Withdrawals', 'konx-affiliate-dashboard' ),
				'icon'  => 'dashicons-money-alt',
				'url'   => admin_url( 'admin.php?page=konx-withdrawals&status=pending' ),
				'tip'   => 'pending_withdrawals',
			),
			array(
				'value' => '$' . number_format( $monthly_commissions, 2 ),
				'label' => __( 'Monthly Commissions', 'konx-affiliate-dashboard' ),
				'icon'  => 'dashicons-chart-line',
				'url'   => admin_url( 'admin.php?page=konx-reports' ),
				'tip'   => '',
			),
			array(
				'value' => '$' . number_format( $wallet_balance, 2 ),
				'label' => __( 'Available Wallet Balance', 'konx-affiliate-dashboard' ),
				'icon'  => 'dashicons-portfolio',
				'url'   => admin_url( 'admin.php?page=konx-reports' ),
				'tip'   => 'unpaid_balances',
			),
		);
	}

	private static function render_kpi_section( $kpis ) {
		?>
		<div class="konx-ops-section">
			<h2 class="konx-ops-section-title">
				<span class="dashicons dashicons-chart-area"></span>
				<?php esc_html_e( 'Operations Summary', 'konx-affiliate-dashboard' ); ?>
			</h2>
			<div class="konx-kpi-grid">
				<?php foreach ( $kpis as $kpi ) : ?>
					<a href="<?php echo esc_url( $kpi['url'] ); ?>" class="konx-kpi-card">
						<span class="konx-kpi-icon"><span class="dashicons <?php echo esc_attr( $kpi['icon'] ); ?>"></span></span>
						<span class="konx-kpi-value"><?php echo esc_html( $kpi['value'] ); ?></span>
						<span class="konx-kpi-label"><?php echo esc_html( $kpi['label'] ); ?>
							<?php if ( ! empty( $kpi['tip'] ) ) { echo Konx_Tooltip_Helper::get( $kpi['tip'] ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Section 2 — Action Required
	// ------------------------------------------------------------------

	private static function get_action_required() {
		global $wpdb;
		$aff  = $wpdb->prefix . 'konx_affiliates';
		$wd   = $wpdb->prefix . 'konx_withdrawals';
		$fees = $wpdb->prefix . 'konx_admin_fees';

		$items = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Pending affiliate applications.
		$pending_apps = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$aff} WHERE status = %s", 'pending'
		) );
		if ( $pending_apps > 0 ) {
			$items[] = array(
				'icon'   => 'dashicons-businessman',
				'label'  => sprintf(
					_n( '%d pending affiliate application', '%d pending affiliate applications', $pending_apps, 'konx-affiliate-dashboard' ),
					$pending_apps
				),
				'url'    => admin_url( 'admin.php?page=konx-affiliates&status=pending' ),
				'action' => __( 'Review', 'konx-affiliate-dashboard' ),
				'type'   => 'warning',
			);
		}

		// Pending withdrawal requests.
		$pending_wd = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wd} WHERE status IN (%s,%s)", 'pending', 'approved'
		) );
		if ( $pending_wd > 0 ) {
			$items[] = array(
				'icon'   => 'dashicons-money-alt',
				'label'  => sprintf(
					_n( '%d pending withdrawal request', '%d pending withdrawal requests', $pending_wd, 'konx-affiliate-dashboard' ),
					$pending_wd
				),
				'url'    => admin_url( 'admin.php?page=konx-withdrawals&status=pending' ),
				'action' => __( 'Process', 'konx-affiliate-dashboard' ),
				'type'   => 'warning',
			);
		}

		// Overdue admin fees.
		$overdue_fees = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$fees} WHERE status = %s", 'overdue'
		) );
		if ( $overdue_fees > 0 ) {
			$items[] = array(
				'icon'   => 'dashicons-warning',
				'label'  => sprintf(
					_n( '%d overdue admin fee', '%d overdue admin fees', $overdue_fees, 'konx-affiliate-dashboard' ),
					$overdue_fees
				),
				'url'    => admin_url( 'admin.php?page=konx-admin-fees&status=overdue' ),
				'action' => __( 'Resolve', 'konx-affiliate-dashboard' ),
				'type'   => 'danger',
			);
		}

		// phpcs:enable

		// Migration warnings (if preview exists but not completed).
		$migration_status = get_option( 'konx_migration_status', '' );
		if ( in_array( $migration_status, array( 'previewed', 'in_progress' ), true ) ) {
			$items[] = array(
				'icon'   => 'dashicons-database-import',
				'label'  => __( 'Data migration is in progress and requires attention', 'konx-affiliate-dashboard' ),
				'url'    => admin_url( 'admin.php?page=konx-affiliate-dashboard' ),
				'action' => __( 'Continue', 'konx-affiliate-dashboard' ),
				'type'   => 'info',
			);
		}

		return $items;
	}

	private static function render_action_required( $items ) {
		?>
		<div class="konx-ops-section">
			<h2 class="konx-ops-section-title">
				<span class="dashicons dashicons-flag"></span>
				<?php esc_html_e( 'Action Required', 'konx-affiliate-dashboard' ); ?>
			</h2>
			<?php if ( empty( $items ) ) : ?>
				<div class="konx-ops-all-clear">
					<span class="dashicons dashicons-yes-alt"></span>
					<div>
						<strong><?php esc_html_e( 'No action required', 'konx-affiliate-dashboard' ); ?></strong>
						<p><?php esc_html_e( 'Everything looks good. There are no pending items that need your attention.', 'konx-affiliate-dashboard' ); ?></p>
					</div>
				</div>
			<?php else : ?>
				<div class="konx-action-list">
					<?php foreach ( $items as $item ) : ?>
						<div class="konx-action-item konx-action-item-<?php echo esc_attr( $item['type'] ); ?>">
							<span class="konx-action-icon">
								<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>"></span>
							</span>
							<span class="konx-action-label"><?php echo esc_html( $item['label'] ); ?></span>
							<a href="<?php echo esc_url( $item['url'] ); ?>" class="button button-small">
								<?php echo esc_html( $item['action'] ); ?>
							</a>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Section 3 — Platform Health
	// ------------------------------------------------------------------

	private static function get_platform_health() {
		global $wpdb;

		$checks = array();

		// WooCommerce.
		$wc_active = konx_affiliate_is_woocommerce_active();
		$checks[] = array(
			'label'  => __( 'WooCommerce', 'konx-affiliate-dashboard' ),
			'status' => $wc_active ? 'ok' : 'error',
			'detail' => $wc_active ? ( defined( 'WC_VERSION' ) ? 'v' . WC_VERSION : __( 'Active', 'konx-affiliate-dashboard' ) ) : __( 'Not Active', 'konx-affiliate-dashboard' ),
		);

		// Database Tables.
		$required_tables = array(
			'konx_affiliates', 'konx_referral_clicks', 'konx_referral_conversions',
			'konx_commissions', 'konx_wallet_ledger', 'konx_withdrawals',
			'konx_admin_fees', 'konx_milestones', 'konx_commission_rules',
			'konx_product_map', 'konx_audit_log',
		);
		$missing_tables = 0;
		foreach ( $required_tables as $t ) {
			$full = $wpdb->prefix . $t;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) {
				$missing_tables++;
			}
		}
		$checks[] = array(
			'label'  => __( 'Database Tables', 'konx-affiliate-dashboard' ),
			'status' => 0 === $missing_tables ? 'ok' : 'error',
			'detail' => 0 === $missing_tables
				? sprintf( __( '%d / %d present', 'konx-affiliate-dashboard' ), count( $required_tables ), count( $required_tables ) )
				: sprintf( __( '%d missing', 'konx-affiliate-dashboard' ), $missing_tables ),
		);

		// Required Pages.
		$dash_page = self::find_page_with_shortcode( 'konx_affiliate_dashboard' );
		$reg_page  = self::find_page_with_shortcode( 'konx_affiliate_register' );
		$pages_ok  = ( $dash_page && $reg_page );
		$checks[] = array(
			'label'  => __( 'Required Pages', 'konx-affiliate-dashboard' ),
			'status' => $pages_ok ? 'ok' : 'warning',
			'detail' => $pages_ok ? __( 'Dashboard & Registration found', 'konx-affiliate-dashboard' ) : __( 'Pages missing', 'konx-affiliate-dashboard' ),
		);

		// Product Mapping.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$mapping_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_product_map WHERE is_active = 1"
		);
		$checks[] = array(
			'label'  => __( 'Product Mapping', 'konx-affiliate-dashboard' ),
			'status' => $mapping_count > 0 ? 'ok' : 'warning',
			'detail' => $mapping_count > 0
				? sprintf( _n( '%d active', '%d active', $mapping_count, 'konx-affiliate-dashboard' ), $mapping_count )
				: __( 'None configured', 'konx-affiliate-dashboard' ),
		);

		// Commission Rules.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rule_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_commission_rules WHERE is_active = 1"
		);
		$checks[] = array(
			'label'  => __( 'Commission Rules', 'konx-affiliate-dashboard' ),
			'status' => $rule_count > 0 ? 'ok' : 'warning',
			'detail' => $rule_count > 0
				? sprintf( _n( '%d active rule', '%d active rules', $rule_count, 'konx-affiliate-dashboard' ), $rule_count )
				: __( 'None configured', 'konx-affiliate-dashboard' ),
		);

		// Migration.
		$migration_status = get_option( 'konx_migration_status', '' );
		if ( 'completed' === $migration_status ) {
			$mig_status = 'ok';
			$mig_detail = __( 'Completed', 'konx-affiliate-dashboard' );
		} elseif ( in_array( $migration_status, array( 'previewed', 'in_progress' ), true ) ) {
			$mig_status = 'warning';
			$mig_detail = __( 'In Progress', 'konx-affiliate-dashboard' );
		} else {
			$mig_status = 'ok';
			$mig_detail = __( 'Not required', 'konx-affiliate-dashboard' );
		}
		$checks[] = array(
			'label'  => __( 'Migration', 'konx-affiliate-dashboard' ),
			'status' => $mig_status,
			'detail' => $mig_detail,
		);

		return $checks;
	}

	private static function render_platform_health( $checks ) {
		$ok_count    = 0;
		$total_count = count( $checks );
		foreach ( $checks as $c ) {
			if ( 'ok' === $c['status'] ) {
				$ok_count++;
			}
		}
		$pct = $total_count > 0 ? round( ( $ok_count / $total_count ) * 100 ) : 0;

		if ( $pct >= 100 ) {
			$health_class = 'konx-health-excellent';
		} elseif ( $pct >= 75 ) {
			$health_class = 'konx-health-good';
		} else {
			$health_class = 'konx-health-needs-attention';
		}
		?>
		<div class="konx-card konx-health-card">
			<h2>
				<span class="dashicons dashicons-heart"></span>
				<?php esc_html_e( 'Platform Health', 'konx-affiliate-dashboard' ); ?>
			</h2>

			<div class="konx-health-score <?php echo esc_attr( $health_class ); ?>">
				<span class="konx-health-pct"><?php echo esc_html( $pct ); ?>%</span>
				<span class="konx-health-label"><?php esc_html_e( 'Healthy', 'konx-affiliate-dashboard' ); ?></span>
			</div>

			<div class="konx-health-checks">
				<?php foreach ( $checks as $check ) : ?>
					<div class="konx-health-row">
						<span class="konx-health-indicator konx-health-indicator-<?php echo esc_attr( $check['status'] ); ?>"></span>
						<span class="konx-health-check-label"><?php echo esc_html( $check['label'] ); ?></span>
						<span class="konx-health-check-detail"><?php echo esc_html( $check['detail'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="konx-health-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-system-status' ) ); ?>" class="button button-small">
					<?php esc_html_e( 'Full System Status', 'konx-affiliate-dashboard' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Section 4 — Quick Actions
	// ------------------------------------------------------------------

	private static function render_quick_actions() {
		$actions = array(
			array(
				'icon'  => 'dashicons-groups',
				'title' => __( 'Manage Affiliates', 'konx-affiliate-dashboard' ),
				'desc'  => __( 'View, approve, and manage affiliate accounts', 'konx-affiliate-dashboard' ),
				'url'   => admin_url( 'admin.php?page=konx-affiliates' ),
			),
			array(
				'icon'  => 'dashicons-money-alt',
				'title' => __( 'Review Withdrawals', 'konx-affiliate-dashboard' ),
				'desc'  => __( 'Process pending withdrawal requests', 'konx-affiliate-dashboard' ),
				'url'   => admin_url( 'admin.php?page=konx-withdrawals' ),
			),
			array(
				'icon'  => 'dashicons-products',
				'title' => __( 'Product Mapping', 'konx-affiliate-dashboard' ),
				'desc'  => __( 'Map WooCommerce products to commission types', 'konx-affiliate-dashboard' ),
				'url'   => admin_url( 'admin.php?page=konx-product-mapping' ),
			),
			array(
				'icon'  => 'dashicons-admin-settings',
				'title' => __( 'Commission Rules', 'konx-affiliate-dashboard' ),
				'desc'  => __( 'Configure rates, tiers, and payout rules', 'konx-affiliate-dashboard' ),
				'url'   => admin_url( 'admin.php?page=konx-settings' ),
			),
			array(
				'icon'  => 'dashicons-database-import',
				'title' => __( 'Migration Wizard', 'konx-affiliate-dashboard' ),
				'desc'  => __( 'Import data from PowerOf10 or other sources', 'konx-affiliate-dashboard' ),
				'url'   => admin_url( 'admin.php?page=konx-setup-wizard' ),
			),
			array(
				'icon'  => 'dashicons-editor-help',
				'title' => __( 'Help Center', 'konx-affiliate-dashboard' ),
				'desc'  => __( 'Documentation and getting started guides', 'konx-affiliate-dashboard' ),
				'url'   => admin_url( 'admin.php?page=konx-help-center' ),
			),
		);
		?>
		<div class="konx-card konx-quick-actions-card">
			<h2>
				<span class="dashicons dashicons-admin-links"></span>
				<?php esc_html_e( 'Quick Actions', 'konx-affiliate-dashboard' ); ?>
			</h2>
			<div class="konx-quick-actions-grid">
				<?php foreach ( $actions as $action ) : ?>
					<a href="<?php echo esc_url( $action['url'] ); ?>" class="konx-quick-action">
						<span class="dashicons <?php echo esc_attr( $action['icon'] ); ?>"></span>
						<strong><?php echo esc_html( $action['title'] ); ?></strong>
						<span><?php echo esc_html( $action['desc'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Setup Progress Checklist (preserved from previous implementation)
	// ------------------------------------------------------------------

	private static function get_setup_status() {
		global $wpdb;

		$items = array();

		// 1. System Status — tables exist + WooCommerce active.
		$required_tables = array(
			'konx_affiliates', 'konx_referral_clicks', 'konx_referral_conversions',
			'konx_commissions', 'konx_wallet_ledger', 'konx_withdrawals',
			'konx_admin_fees', 'konx_milestones', 'konx_commission_rules',
			'konx_product_map', 'konx_audit_log',
		);
		$missing_tables = 0;
		foreach ( $required_tables as $t ) {
			$full = $wpdb->prefix . $t;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) {
				$missing_tables++;
			}
		}
		$wc_active     = konx_affiliate_is_woocommerce_active();
		$system_ok     = ( 0 === $missing_tables && $wc_active );
		$system_detail = $system_ok
			? __( 'Healthy', 'konx-affiliate-dashboard' )
			: ( ! $wc_active ? __( 'WooCommerce not active', 'konx-affiliate-dashboard' ) : sprintf( __( '%d tables missing', 'konx-affiliate-dashboard' ), $missing_tables ) );
		$items[] = array(
			'key'      => 'system_status',
			'label'    => __( 'System Status', 'konx-affiliate-dashboard' ),
			'status'   => $system_ok ? 'complete' : 'attention',
			'detail'   => $system_detail,
			'url'      => admin_url( 'admin.php?page=konx-system-status' ),
			'required' => true,
		);

		// 2. Product Mapping — at least one active mapping.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$mapping_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_product_map WHERE is_active = 1"
		);
		$items[] = array(
			'key'      => 'product_mapping',
			'label'    => __( 'Product Mapping', 'konx-affiliate-dashboard' ),
			'status'   => $mapping_count > 0 ? 'complete' : 'attention',
			'detail'   => $mapping_count > 0
				? sprintf( _n( '%d Product Mapped', '%d Products Mapped', $mapping_count, 'konx-affiliate-dashboard' ), $mapping_count )
				: __( 'No products mapped', 'konx-affiliate-dashboard' ),
			'url'      => admin_url( 'admin.php?page=konx-product-mapping' ),
			'required' => true,
		);

		// 3. Commission Rules — at least one active rule.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rule_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_commission_rules WHERE is_active = 1"
		);
		$items[] = array(
			'key'      => 'commission_rules',
			'label'    => __( 'Commission Rules', 'konx-affiliate-dashboard' ),
			'status'   => $rule_count > 0 ? 'complete' : 'attention',
			'detail'   => $rule_count > 0
				? sprintf( _n( '%d Active Rule', '%d Active Rules', $rule_count, 'konx-affiliate-dashboard' ), $rule_count )
				: __( 'Needs Attention', 'konx-affiliate-dashboard' ),
			'url'      => admin_url( 'admin.php?page=konx-settings' ),
			'required' => true,
		);

		// 4. Required Pages — dashboard + registration pages.
		$dash_page = self::find_page_with_shortcode( 'konx_affiliate_dashboard' );
		$reg_page  = self::find_page_with_shortcode( 'konx_affiliate_register' );
		$pages_ok  = ( $dash_page && $reg_page );
		if ( $pages_ok ) {
			$pages_detail = __( 'Dashboard & Registration pages found', 'konx-affiliate-dashboard' );
		} elseif ( ! $dash_page && ! $reg_page ) {
			$pages_detail = __( 'Dashboard & Registration pages missing', 'konx-affiliate-dashboard' );
		} elseif ( ! $dash_page ) {
			$pages_detail = __( 'Dashboard page missing', 'konx-affiliate-dashboard' );
		} else {
			$pages_detail = __( 'Registration page missing', 'konx-affiliate-dashboard' );
		}
		$items[] = array(
			'key'      => 'required_pages',
			'label'    => __( 'Required Pages', 'konx-affiliate-dashboard' ),
			'status'   => $pages_ok ? 'complete' : 'attention',
			'detail'   => $pages_detail,
			'url'      => admin_url( 'admin.php?page=konx-system-status' ),
			'required' => true,
		);

		// 5. Optional: Data Migration.
		$migration_status  = get_option( 'konx_migration_status', '' );
		if ( 'completed' === $migration_status ) {
			$mig_state  = 'complete';
			$mig_detail = __( 'Completed', 'konx-affiliate-dashboard' );
		} elseif ( in_array( $migration_status, array( 'previewed', 'in_progress' ), true ) ) {
			$mig_state  = 'attention';
			$mig_detail = __( 'In Progress', 'konx-affiliate-dashboard' );
		} else {
			$mig_state  = 'optional';
			$mig_detail = __( 'Optional', 'konx-affiliate-dashboard' );
		}
		$items[] = array(
			'key'      => 'data_migration',
			'label'    => __( 'Data Migration', 'konx-affiliate-dashboard' ),
			'status'   => $mig_state,
			'detail'   => $mig_detail,
			'url'      => admin_url( 'admin.php?page=konx-affiliate-dashboard' ), // fallback
			'required' => false,
		);

		// Calculate required completion.
		$completed = 0;
		$total     = 0;
		foreach ( $items as $item ) {
			if ( $item['required'] ) {
				$total++;
				if ( 'complete' === $item['status'] ) {
					$completed++;
				}
			}
		}

		// Find first incomplete required item.
		$first_incomplete_url = '';
		foreach ( $items as $item ) {
			if ( $item['required'] && 'complete' !== $item['status'] ) {
				$first_incomplete_url = $item['url'];
				break;
			}
		}

		return array(
			'items'                => $items,
			'completed'            => $completed,
			'total'                => $total,
			'is_ready'             => ( $completed === $total ),
			'first_incomplete_url' => $first_incomplete_url,
		);
	}

	private static function render_setup_checklist( $setup ) {
		$pct = $setup['total'] > 0 ? round( ( $setup['completed'] / $setup['total'] ) * 100 ) : 0;
		?>
		<!-- Setup Progress -->
		<div class="konx-setup-card">
			<div class="konx-setup-header">
				<div>
					<h2><?php esc_html_e( 'KonX Setup Progress', 'konx-affiliate-dashboard' ); ?></h2>
					<span class="konx-setup-counter">
						<?php printf( esc_html__( '%1$d / %2$d Complete', 'konx-affiliate-dashboard' ), $setup['completed'], $setup['total'] ); ?>
					</span>
				</div>
				<?php if ( $setup['is_ready'] ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-affiliate-dashboard' ) ); ?>" class="button button-primary button-hero">
						<?php esc_html_e( 'Go to Dashboard', 'konx-affiliate-dashboard' ); ?>
					</a>
				<?php elseif ( $setup['first_incomplete_url'] ) : ?>
					<a href="<?php echo esc_url( $setup['first_incomplete_url'] ); ?>" class="button button-primary button-hero">
						<?php esc_html_e( 'Complete Setup', 'konx-affiliate-dashboard' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<div class="konx-setup-progress">
				<div class="konx-setup-progress-fill" style="width:<?php echo esc_attr( $pct ); ?>%;"></div>
			</div>

			<?php if ( $setup['is_ready'] ) : ?>
				<div class="konx-setup-ready">
					<span class="dashicons dashicons-yes-alt"></span>
					<div>
						<strong><?php esc_html_e( 'KonX is Ready', 'konx-affiliate-dashboard' ); ?></strong>
						<p><?php esc_html_e( 'Your affiliate platform is fully configured.', 'konx-affiliate-dashboard' ); ?></p>
					</div>
				</div>
			<?php endif; ?>

			<div class="konx-setup-checklist">
				<?php foreach ( $setup['items'] as $item ) : ?>
					<div class="konx-setup-item konx-setup-item-<?php echo esc_attr( $item['status'] ); ?>">
						<span class="konx-setup-icon">
							<?php if ( 'complete' === $item['status'] ) : ?>
								<span class="dashicons dashicons-yes-alt" style="color:var(--konx-success);"></span>
							<?php elseif ( 'attention' === $item['status'] ) : ?>
								<span class="dashicons dashicons-warning" style="color:var(--konx-warning);"></span>
							<?php else : ?>
								<span class="dashicons dashicons-marker" style="color:var(--konx-muted,#787c82);"></span>
							<?php endif; ?>
						</span>
						<div class="konx-setup-item-body">
							<strong><?php echo esc_html( $item['label'] ); ?></strong>
							<span class="konx-setup-detail"><?php echo esc_html( $item['detail'] ); ?></span>
						</div>
						<a href="<?php echo esc_url( $item['url'] ); ?>" class="button button-small">
							<?php echo 'complete' === $item['status']
								? esc_html__( 'Open', 'konx-affiliate-dashboard' )
								: ( $item['required'] ? esc_html__( 'Configure', 'konx-affiliate-dashboard' ) : esc_html__( 'Review', 'konx-affiliate-dashboard' ) ); ?>
						</a>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private static function find_page_with_shortcode( $shortcode ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s LIMIT 1",
			'%[' . $wpdb->esc_like( $shortcode ) . ']%'
		) );

		return $id ? (int) $id : null;
	}

	// ------------------------------------------------------------------
	// Data Methods (preserved)
	// ------------------------------------------------------------------

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
