<?php
/**
 * Export manager — CSV export framework.
 *
 * Provides CSV export for affiliates, commissions, withdrawals,
 * milestones, and reports. PDF support is planned for a future phase.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Konx_Export_Manager {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_post_konx_export_csv', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Handle CSV export request.
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_konx_commissions' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		check_admin_referer( 'konx_export_csv' );

		$type = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';

		$data     = array();
		$filename = 'konx-export.csv';

		switch ( $type ) {
			case 'affiliates':
				$data     = self::get_affiliates_data();
				$filename = 'konx-affiliates-' . gmdate( 'Y-m-d' ) . '.csv';
				break;
			case 'commissions':
				$data     = self::get_commissions_data();
				$filename = 'konx-commissions-' . gmdate( 'Y-m-d' ) . '.csv';
				break;
			case 'withdrawals':
				$data     = self::get_withdrawals_data();
				$filename = 'konx-withdrawals-' . gmdate( 'Y-m-d' ) . '.csv';
				break;
			case 'milestones':
				$data     = self::get_milestones_data();
				$filename = 'konx-milestones-' . gmdate( 'Y-m-d' ) . '.csv';
				break;
			default:
				wp_die( esc_html__( 'Invalid export type.', 'konx-affiliate-dashboard' ) );
		}

		self::send_csv( $data, $filename );
	}

	/**
	 * Generate a CSV export URL with nonce.
	 *
	 * @param string $type Export type (affiliates, commissions, etc.).
	 * @return string Nonced URL.
	 */
	public static function get_export_url( $type ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'konx_export_csv',
					'type'   => $type,
				),
				admin_url( 'admin-post.php' )
			),
			'konx_export_csv'
		);
	}

	/**
	 * Send CSV content as a download.
	 *
	 * @param array  $data     Array of associative arrays (rows).
	 * @param string $filename Download filename.
	 */
	private static function send_csv( $data, $filename ) {
		if ( empty( $data ) ) {
			wp_die( esc_html__( 'No data to export.', 'konx-affiliate-dashboard' ) );
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		// Header row.
		fputcsv( $output, array_keys( $data[0] ) );

		// Data rows.
		foreach ( $data as $row ) {
			fputcsv( $output, $row );
		}

		fclose( $output );
		exit;
	}

	private static function get_affiliates_data() {
		global $wpdb;
		$table = $wpdb->prefix . 'konx_affiliates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT a.id, u.display_name AS name, u.user_email AS email, a.affiliate_type, a.status, a.referral_code, a.completed_sales, a.cached_balance, a.registered_at
			 FROM {$table} a INNER JOIN {$wpdb->users} u ON a.user_id = u.ID ORDER BY a.id",
			ARRAY_A
		);
		return $rows ?: array();
	}

	private static function get_commissions_data() {
		global $wpdb;
		$table = $wpdb->prefix . 'konx_commissions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT c.id, u.display_name AS affiliate, c.order_id, c.product_type, c.affiliate_type_at_sale, c.product_price, c.commission_rate, c.commission_amount, c.commission_type, c.sale_sequence, c.status, c.created_at
			 FROM {$table} c INNER JOIN {$wpdb->prefix}konx_affiliates a ON c.affiliate_id = a.id INNER JOIN {$wpdb->users} u ON a.user_id = u.ID ORDER BY c.id DESC",
			ARRAY_A
		);
		return $rows ?: array();
	}

	private static function get_withdrawals_data() {
		global $wpdb;
		$table = $wpdb->prefix . 'konx_withdrawals';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT w.id, u.display_name AS affiliate, w.amount, w.payment_method, w.payment_email, w.status, w.transaction_reference, w.requested_at, w.processed_at
			 FROM {$table} w INNER JOIN {$wpdb->prefix}konx_affiliates a ON w.affiliate_id = a.id INNER JOIN {$wpdb->users} u ON a.user_id = u.ID ORDER BY w.id DESC",
			ARRAY_A
		);
		return $rows ?: array();
	}

	private static function get_milestones_data() {
		global $wpdb;
		$table = $wpdb->prefix . 'konx_milestones';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT m.id, u.display_name AS affiliate, m.milestone_number, m.sale_count_at_trigger, m.sale_block_start, m.sale_block_end, m.bonus_amount, m.status, m.created_at
			 FROM {$table} m INNER JOIN {$wpdb->prefix}konx_affiliates a ON m.affiliate_id = a.id INNER JOIN {$wpdb->users} u ON a.user_id = u.ID ORDER BY m.id DESC",
			ARRAY_A
		);
		return $rows ?: array();
	}
}
