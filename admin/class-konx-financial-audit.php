<?php
/**
 * Financial audit page.
 *
 * Runs integrity checks on wallet balances, commissions, withdrawals,
 * milestones, and admin fees. Provides reconciliation controls.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Konx_Financial_Audit {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'konx-affiliate-dashboard',
			__( 'Financial Audit', 'konx-affiliate-dashboard' ),
			__( 'Financial Audit', 'konx-affiliate-dashboard' ),
			'manage_konx_commissions',
			'konx-financial-audit',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_commissions' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		$checks = self::run_audit();
		$errors   = 0;
		$warnings = 0;
		foreach ( $checks as $c ) {
			if ( 'error' === $c['status'] ) $errors++;
			if ( 'warning' === $c['status'] ) $warnings++;
		}

		?>
		<div class="wrap">
			<div class="konx-page-header">
				<h1><?php esc_html_e( 'Financial Audit', 'konx-affiliate-dashboard' ); ?></h1>
			</div>

			<!-- Summary -->
			<div class="konx-stats-grid" style="margin-bottom:20px;">
				<div class="konx-stat-card">
					<span class="konx-stat-value"><?php echo esc_html( count( $checks ) ); ?></span>
					<span class="konx-stat-label"><?php esc_html_e( 'Checks Run', 'konx-affiliate-dashboard' ); ?></span>
				</div>
				<div class="konx-stat-card">
					<span class="konx-stat-value" style="color:#00a32a;"><?php echo esc_html( count( $checks ) - $errors - $warnings ); ?></span>
					<span class="konx-stat-label"><?php esc_html_e( 'Passed', 'konx-affiliate-dashboard' ); ?></span>
				</div>
				<div class="konx-stat-card">
					<span class="konx-stat-value" style="color:#dba617;"><?php echo esc_html( $warnings ); ?></span>
					<span class="konx-stat-label"><?php esc_html_e( 'Warnings', 'konx-affiliate-dashboard' ); ?></span>
				</div>
				<div class="konx-stat-card">
					<span class="konx-stat-value" style="color:#d63638;"><?php echo esc_html( $errors ); ?></span>
					<span class="konx-stat-label"><?php esc_html_e( 'Errors', 'konx-affiliate-dashboard' ); ?></span>
				</div>
			</div>

			<!-- Results -->
			<div class="konx-table-wrap">
				<table class="widefat fixed striped" style="max-width:900px;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Check', 'konx-affiliate-dashboard' ); ?></th>
							<th scope="col" style="width:100px;"><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Detail', 'konx-affiliate-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $checks as $check ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
								<td><span class="konx-badge konx-badge-<?php echo esc_attr( 'ok' === $check['status'] ? 'approved' : ( 'warning' === $check['status'] ? 'pending' : 'rejected' ) ); ?>"><?php echo esc_html( ucfirst( $check['status'] ) ); ?></span></td>
								<td><?php echo esc_html( $check['detail'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	private static function run_audit() {
		global $wpdb;

		$aff   = $wpdb->prefix . 'konx_affiliates';
		$ledger = $wpdb->prefix . 'konx_wallet_ledger';
		$comm  = $wpdb->prefix . 'konx_commissions';
		$wd    = $wpdb->prefix . 'konx_withdrawals';
		$miles = $wpdb->prefix . 'konx_milestones';

		$checks = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// 1. Wallet balance integrity.
		$affiliates = $wpdb->get_results( "SELECT id, cached_balance FROM {$aff}" );
		$drift_count = 0;
		foreach ( $affiliates as $a ) {
			$ledger_sum = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$ledger} WHERE affiliate_id = %d", $a->id ) );
			if ( number_format( (float) $ledger_sum, 2, '.', '' ) !== number_format( (float) $a->cached_balance, 2, '.', '' ) ) {
				$drift_count++;
			}
		}
		$checks[] = array(
			'label'  => __( 'Wallet Balance Integrity', 'konx-affiliate-dashboard' ),
			'status' => $drift_count === 0 ? 'ok' : 'error',
			'detail' => $drift_count === 0
				? sprintf( __( 'All %d affiliates have matching cached and ledger balances.', 'konx-affiliate-dashboard' ), count( $affiliates ) )
				: sprintf( __( '%d affiliate(s) have balance drift between cached_balance and ledger SUM.', 'konx-affiliate-dashboard' ), $drift_count ),
		);

		// 2. Commission-wallet linkage.
		$orphan_comms = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$comm} WHERE status = 'approved' AND ledger_entry_id IS NULL" );
		$checks[] = array(
			'label'  => __( 'Commission-Wallet Linkage', 'konx-affiliate-dashboard' ),
			'status' => $orphan_comms === 0 ? 'ok' : 'warning',
			'detail' => $orphan_comms === 0
				? __( 'All approved commissions have a linked wallet ledger entry.', 'konx-affiliate-dashboard' )
				: sprintf( __( '%d approved commission(s) missing ledger_entry_id.', 'konx-affiliate-dashboard' ), $orphan_comms ),
		);

		// 3. Withdrawal-wallet linkage.
		$orphan_wd = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wd} WHERE status = 'completed' AND ledger_entry_id IS NULL" );
		$checks[] = array(
			'label'  => __( 'Withdrawal-Wallet Linkage', 'konx-affiliate-dashboard' ),
			'status' => $orphan_wd === 0 ? 'ok' : 'error',
			'detail' => $orphan_wd === 0
				? __( 'All completed withdrawals have a linked wallet ledger entry.', 'konx-affiliate-dashboard' )
				: sprintf( __( '%d completed withdrawal(s) missing ledger_entry_id.', 'konx-affiliate-dashboard' ), $orphan_wd ),
		);

		// 4. Milestone-wallet linkage.
		$orphan_ms = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$miles} WHERE status = 'approved' AND ledger_entry_id IS NULL" );
		$checks[] = array(
			'label'  => __( 'Milestone-Wallet Linkage', 'konx-affiliate-dashboard' ),
			'status' => $orphan_ms === 0 ? 'ok' : 'warning',
			'detail' => $orphan_ms === 0
				? __( 'All approved milestones have a linked wallet ledger entry.', 'konx-affiliate-dashboard' )
				: sprintf( __( '%d approved milestone(s) missing ledger_entry_id.', 'konx-affiliate-dashboard' ), $orphan_ms ),
		);

		// 5. Sale sequence integrity.
		$dup_seq = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT affiliate_id, sale_sequence, COUNT(*) c FROM {$comm} GROUP BY affiliate_id, sale_sequence HAVING c > 1) t" );
		$checks[] = array(
			'label'  => __( 'Sale Sequence Integrity', 'konx-affiliate-dashboard' ),
			'status' => $dup_seq === 0 ? 'ok' : 'error',
			'detail' => $dup_seq === 0
				? __( 'No duplicate sale sequence numbers found.', 'konx-affiliate-dashboard' )
				: sprintf( __( '%d duplicate sale sequence(s) detected.', 'konx-affiliate-dashboard' ), $dup_seq ),
		);

		// 6. Completed sales vs MAX(sale_sequence).
		$sales_drift = 0;
		foreach ( $affiliates as $a ) {
			$max_seq = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sale_sequence),0) FROM {$comm} WHERE affiliate_id = %d", $a->id ) );
			$stored  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT completed_sales FROM {$aff} WHERE id = %d", $a->id ) );
			if ( $max_seq !== $stored ) {
				$sales_drift++;
			}
		}
		$checks[] = array(
			'label'  => __( 'Completed Sales Counter', 'konx-affiliate-dashboard' ),
			'status' => $sales_drift === 0 ? 'ok' : 'warning',
			'detail' => $sales_drift === 0
				? __( 'All completed_sales counters match MAX(sale_sequence).', 'konx-affiliate-dashboard' )
				: sprintf( __( '%d affiliate(s) have completed_sales drift.', 'konx-affiliate-dashboard' ), $sales_drift ),
		);

		// 7. Negative balances.
		$negative = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$aff} WHERE cached_balance < 0" );
		$checks[] = array(
			'label'  => __( 'Negative Balances', 'konx-affiliate-dashboard' ),
			'status' => $negative === 0 ? 'ok' : 'warning',
			'detail' => $negative === 0
				? __( 'No affiliates have negative wallet balances.', 'konx-affiliate-dashboard' )
				: sprintf( __( '%d affiliate(s) have negative balances (may be due to refund reversals).', 'konx-affiliate-dashboard' ), $negative ),
		);

		// phpcs:enable

		return $checks;
	}
}
