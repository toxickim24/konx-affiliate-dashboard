<?php
/**
 * Admin reports page.
 *
 * Provides aggregate reports for the affiliate program: sales by
 * product, commissions by type/affiliate, milestone/withdrawal
 * summaries, and top-affiliate leaderboards with date range filters.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Reports_Page
 */
class Konx_Reports_Page {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * Register the submenu page.
	 */
	public static function register_menu() {
		add_submenu_page(
			'konx-affiliate-dashboard',
			__( 'Reports', 'konx-affiliate-dashboard' ),
			__( 'Reports', 'konx-affiliate-dashboard' ),
			'manage_konx_commissions',
			'konx-reports',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the reports page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_commissions' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'konx-affiliate-dashboard' ) );
		}

		// Date range filters.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y-m-01' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : gmdate( 'Y-m-d' );

		$reports = self::get_all_reports( $date_from, $date_to );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reports', 'konx-affiliate-dashboard' ); ?></h1>

			<!-- Date Range Filter -->
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom:20px;">
				<input type="hidden" name="page" value="konx-reports">
				<label><?php esc_html_e( 'From:', 'konx-affiliate-dashboard' ); ?>
					<input type="date" name="from" value="<?php echo esc_attr( $date_from ); ?>">
				</label>
				<label style="margin-left:8px;"><?php esc_html_e( 'To:', 'konx-affiliate-dashboard' ); ?>
					<input type="date" name="to" value="<?php echo esc_attr( $date_to ); ?>">
				</label>
				<?php submit_button( __( 'Filter', 'konx-affiliate-dashboard' ), 'secondary', '', false ); ?>
			</form>

			<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

				<!-- Sales by Product Category -->
				<div>
					<h2><?php esc_html_e( 'Sales by Product Category', 'konx-affiliate-dashboard' ); ?></h2>
					<?php self::render_table( $reports['by_product'], array(
						'product_type' => __( 'Product', 'konx-affiliate-dashboard' ),
						'total_sales'  => __( 'Sales', 'konx-affiliate-dashboard' ),
						'total_amount' => __( 'Commission', 'konx-affiliate-dashboard' ),
					), 'product_type', '$' ); ?>
				</div>

				<!-- Commissions by Affiliate Type -->
				<div>
					<h2><?php esc_html_e( 'Commissions by Affiliate Type', 'konx-affiliate-dashboard' ); ?></h2>
					<?php self::render_table( $reports['by_affiliate_type'], array(
						'affiliate_type' => __( 'Type', 'konx-affiliate-dashboard' ),
						'total_sales'    => __( 'Sales', 'konx-affiliate-dashboard' ),
						'total_amount'   => __( 'Commission', 'konx-affiliate-dashboard' ),
					), 'affiliate_type', '$' ); ?>
				</div>

				<!-- One-Time vs Recurring -->
				<div>
					<h2><?php esc_html_e( 'One-Time vs Recurring', 'konx-affiliate-dashboard' ); ?></h2>
					<?php self::render_table( $reports['by_commission_type'], array(
						'commission_type' => __( 'Type', 'konx-affiliate-dashboard' ),
						'total_sales'     => __( 'Sales', 'konx-affiliate-dashboard' ),
						'total_amount'    => __( 'Commission', 'konx-affiliate-dashboard' ),
					), 'commission_type', '$' ); ?>
				</div>

				<!-- Milestone Bonuses -->
				<div>
					<h2><?php esc_html_e( 'Milestone Bonuses', 'konx-affiliate-dashboard' ); ?></h2>
					<?php self::render_table( $reports['milestones'], array(
						'status'       => __( 'Status', 'konx-affiliate-dashboard' ),
						'total_count'  => __( 'Count', 'konx-affiliate-dashboard' ),
						'total_amount' => __( 'Amount', 'konx-affiliate-dashboard' ),
					), 'status', '$' ); ?>
				</div>

				<!-- Withdrawal Summary -->
				<div>
					<h2><?php esc_html_e( 'Withdrawals by Status', 'konx-affiliate-dashboard' ); ?></h2>
					<?php self::render_table( $reports['withdrawals'], array(
						'status'       => __( 'Status', 'konx-affiliate-dashboard' ),
						'total_count'  => __( 'Count', 'konx-affiliate-dashboard' ),
						'total_amount' => __( 'Amount', 'konx-affiliate-dashboard' ),
					), 'status', '$' ); ?>
				</div>

				<!-- Admin Fee Summary -->
				<div>
					<h2><?php esc_html_e( 'Admin Fee Status', 'konx-affiliate-dashboard' ); ?></h2>
					<?php self::render_table( $reports['admin_fees'], array(
						'status'       => __( 'Status', 'konx-affiliate-dashboard' ),
						'total_count'  => __( 'Count', 'konx-affiliate-dashboard' ),
						'total_amount' => __( 'Amount', 'konx-affiliate-dashboard' ),
					), 'status', '$' ); ?>
				</div>
			</div>

			<!-- Top Affiliates -->
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;">
				<div>
					<h2><?php esc_html_e( 'Top Affiliates by Sales', 'konx-affiliate-dashboard' ); ?></h2>
					<?php self::render_leaderboard( $reports['top_by_sales'], 'total_sales' ); ?>
				</div>
				<div>
					<h2><?php esc_html_e( 'Top Affiliates by Earnings', 'konx-affiliate-dashboard' ); ?></h2>
					<?php self::render_leaderboard( $reports['top_by_earnings'], 'total_earnings', '$' ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Report Data
	// ------------------------------------------------------------------

	/**
	 * Get all report data for a date range.
	 *
	 * @param string $from Start date (Y-m-d).
	 * @param string $to   End date (Y-m-d).
	 * @return array
	 */
	private static function get_all_reports( $from, $to ) {
		return array(
			'by_product'         => self::report_by_product( $from, $to ),
			'by_affiliate_type'  => self::report_by_affiliate_type( $from, $to ),
			'by_commission_type' => self::report_by_commission_type( $from, $to ),
			'milestones'         => self::report_milestones( $from, $to ),
			'withdrawals'        => self::report_withdrawals( $from, $to ),
			'admin_fees'         => self::report_admin_fees(),
			'top_by_sales'       => self::report_top_by_sales( $from, $to ),
			'top_by_earnings'    => self::report_top_by_earnings( $from, $to ),
		);
	}

	/**
	 * Commissions grouped by product_type.
	 */
	private static function report_by_product( $from, $to ) {
		global $wpdb;
		$table = $wpdb->prefix . 'konx_commissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT product_type, COUNT(*) AS total_sales, COALESCE(SUM(commission_amount), 0) AS total_amount
			 FROM {$table}
			 WHERE status = 'approved' AND created_at BETWEEN %s AND %s
			 GROUP BY product_type
			 ORDER BY total_amount DESC",
			$from . ' 00:00:00',
			$to . ' 23:59:59'
		) );
	}

	/**
	 * Commissions grouped by affiliate_type_at_sale.
	 */
	private static function report_by_affiliate_type( $from, $to ) {
		global $wpdb;
		$table = $wpdb->prefix . 'konx_commissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT affiliate_type_at_sale AS affiliate_type, COUNT(*) AS total_sales, COALESCE(SUM(commission_amount), 0) AS total_amount
			 FROM {$table}
			 WHERE status = 'approved' AND created_at BETWEEN %s AND %s
			 GROUP BY affiliate_type_at_sale
			 ORDER BY total_amount DESC",
			$from . ' 00:00:00',
			$to . ' 23:59:59'
		) );
	}

	/**
	 * Commissions grouped by one_time vs recurring.
	 */
	private static function report_by_commission_type( $from, $to ) {
		global $wpdb;
		$table = $wpdb->prefix . 'konx_commissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT commission_type, COUNT(*) AS total_sales, COALESCE(SUM(commission_amount), 0) AS total_amount
			 FROM {$table}
			 WHERE status = 'approved' AND created_at BETWEEN %s AND %s
			 GROUP BY commission_type
			 ORDER BY total_amount DESC",
			$from . ' 00:00:00',
			$to . ' 23:59:59'
		) );
	}

	/**
	 * Milestone bonuses grouped by status.
	 */
	private static function report_milestones( $from, $to ) {
		global $wpdb;
		$table = $wpdb->prefix . 'konx_milestones';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT status, COUNT(*) AS total_count, COALESCE(SUM(bonus_amount), 0) AS total_amount
			 FROM {$table}
			 WHERE created_at BETWEEN %s AND %s
			 GROUP BY status",
			$from . ' 00:00:00',
			$to . ' 23:59:59'
		) );
	}

	/**
	 * Withdrawals grouped by status.
	 */
	private static function report_withdrawals( $from, $to ) {
		global $wpdb;
		$table = $wpdb->prefix . 'konx_withdrawals';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT status, COUNT(*) AS total_count, COALESCE(SUM(amount), 0) AS total_amount
			 FROM {$table}
			 WHERE requested_at BETWEEN %s AND %s
			 GROUP BY status",
			$from . ' 00:00:00',
			$to . ' 23:59:59'
		) );
	}

	/**
	 * Admin fees grouped by status (no date filter — current snapshot).
	 */
	private static function report_admin_fees() {
		global $wpdb;
		$table = $wpdb->prefix . 'konx_admin_fees';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			"SELECT status, COUNT(*) AS total_count, COALESCE(SUM(fee_amount), 0) AS total_amount
			 FROM {$table}
			 GROUP BY status"
		);
	}

	/**
	 * Top 10 affiliates by approved commission count.
	 */
	private static function report_top_by_sales( $from, $to ) {
		global $wpdb;
		$comm = $wpdb->prefix . 'konx_commissions';
		$aff  = $wpdb->prefix . 'konx_affiliates';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT u.display_name, a.affiliate_type, COUNT(*) AS total_sales,
			        COALESCE(SUM(c.commission_amount), 0) AS total_earnings
			 FROM {$comm} c
			 INNER JOIN {$aff} a ON c.affiliate_id = a.id
			 INNER JOIN {$wpdb->users} u ON a.user_id = u.ID
			 WHERE c.status = 'approved' AND c.created_at BETWEEN %s AND %s
			 GROUP BY c.affiliate_id
			 ORDER BY total_sales DESC
			 LIMIT 10",
			$from . ' 00:00:00',
			$to . ' 23:59:59'
		) );
	}

	/**
	 * Top 10 affiliates by total approved commission amount.
	 */
	private static function report_top_by_earnings( $from, $to ) {
		global $wpdb;
		$comm = $wpdb->prefix . 'konx_commissions';
		$aff  = $wpdb->prefix . 'konx_affiliates';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT u.display_name, a.affiliate_type, COUNT(*) AS total_sales,
			        COALESCE(SUM(c.commission_amount), 0) AS total_earnings
			 FROM {$comm} c
			 INNER JOIN {$aff} a ON c.affiliate_id = a.id
			 INNER JOIN {$wpdb->users} u ON a.user_id = u.ID
			 WHERE c.status = 'approved' AND c.created_at BETWEEN %s AND %s
			 GROUP BY c.affiliate_id
			 ORDER BY total_earnings DESC
			 LIMIT 10",
			$from . ' 00:00:00',
			$to . ' 23:59:59'
		) );
	}

	// ------------------------------------------------------------------
	// Rendering Helpers
	// ------------------------------------------------------------------

	/**
	 * Render a simple summary table.
	 *
	 * @param array  $rows       Query results.
	 * @param array  $columns    column_key => label.
	 * @param string $label_col  Which column to format as label.
	 * @param string $prefix     Value prefix (e.g. '$') for amount columns.
	 */
	private static function render_table( $rows, $columns, $label_col, $prefix = '' ) {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No data for this period.', 'konx-affiliate-dashboard' ) . '</p>';
			return;
		}

		echo '<table class="widefat fixed striped"><thead><tr>';
		foreach ( $columns as $key => $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $columns as $key => $label ) {
				$val = isset( $row->$key ) ? $row->$key : '';
				if ( $key === $label_col ) {
					$val = ucwords( str_replace( '_', ' ', $val ) );
				}
				$display = ( $prefix && $key !== $label_col && ! is_numeric( $val ) === false ) ? $prefix . number_format( (float) $val, 2 ) : $val;
				if ( $prefix && $key !== $label_col && is_numeric( $val ) && strpos( $key, 'sales' ) === false && strpos( $key, 'count' ) === false ) {
					$display = $prefix . number_format( (float) $val, 2 );
				} else {
					$display = ( $key === $label_col ) ? ucwords( str_replace( '_', ' ', $val ) ) : $val;
				}
				echo '<td>' . esc_html( $display ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render a leaderboard table.
	 *
	 * @param array  $rows      Query results.
	 * @param string $value_col Column to highlight.
	 * @param string $prefix    Value prefix.
	 */
	private static function render_leaderboard( $rows, $value_col, $prefix = '' ) {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No data for this period.', 'konx-affiliate-dashboard' ) . '</p>';
			return;
		}

		echo '<table class="widefat fixed striped"><thead><tr>';
		echo '<th>#</th>';
		echo '<th>' . esc_html__( 'Affiliate', 'konx-affiliate-dashboard' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'konx-affiliate-dashboard' ) . '</th>';
		echo '<th>' . esc_html( ucwords( str_replace( '_', ' ', $value_col ) ) ) . '</th>';
		echo '</tr></thead><tbody>';

		$rank = 1;
		foreach ( $rows as $row ) {
			$val = isset( $row->$value_col ) ? $row->$value_col : 0;
			$display = $prefix ? $prefix . number_format( (float) $val, 2 ) : $val;

			echo '<tr>';
			echo '<td>' . esc_html( $rank ) . '</td>';
			echo '<td>' . esc_html( $row->display_name ) . '</td>';
			echo '<td>' . esc_html( ucwords( str_replace( '_', ' ', $row->affiliate_type ) ) ) . '</td>';
			echo '<td><strong>' . esc_html( $display ) . '</strong></td>';
			echo '</tr>';
			$rank++;
		}
		echo '</tbody></table>';
	}
}
