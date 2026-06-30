<?php
/**
 * Dashboard health status engine.
 *
 * Centralised read-only checks for system health, configuration
 * readiness, and migration status. Used by the Settings Dashboard
 * and Operations Dashboard to display status badges without
 * duplicating query logic.
 *
 * All methods are SELECT-only — no writes.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Health_Engine
 */
class Konx_Health_Engine {

	/**
	 * Get all dashboard health data in a single call.
	 *
	 * @return array Keyed health data for each section.
	 */
	public static function get_all() {
		return array(
			'system'     => self::system_health(),
			'api'        => self::api_health(),
			'products'   => self::product_health(),
			'migration'  => self::migration_health(),
			'affiliates' => self::affiliate_health(),
			'commission' => self::commission_health(),
		);
	}

	/**
	 * Compute the overall status from all sections.
	 *
	 * @param array $health Output of get_all().
	 * @return string 'healthy', 'warning', or 'error'.
	 */
	public static function overall_status( $health ) {
		foreach ( $health as $section ) {
			if ( 'error' === $section['status'] ) {
				return 'error';
			}
		}
		foreach ( $health as $section ) {
			if ( 'warning' === $section['status'] ) {
				return 'warning';
			}
		}
		return 'healthy';
	}

	/**
	 * Compute a health percentage.
	 *
	 * @param array $health Output of get_all().
	 * @return int Percentage 0-100.
	 */
	public static function percentage( $health ) {
		$total = count( $health );
		if ( 0 === $total ) {
			return 100;
		}
		$ok = 0;
		foreach ( $health as $section ) {
			if ( in_array( $section['status'], array( 'ok', 'info', 'healthy' ), true ) ) {
				$ok++;
			}
		}
		return (int) round( ( $ok / $total ) * 100 );
	}

	// ------------------------------------------------------------------
	// Individual Health Checks
	// ------------------------------------------------------------------

	/**
	 * System health: tables, WooCommerce, schema version.
	 *
	 * @return array { status, items[] }
	 */
	public static function system_health() {
		global $wpdb;

		$items = array();

		// WooCommerce.
		$wc = konx_affiliate_is_woocommerce_active();
		$items[] = array(
			'label'  => __( 'WooCommerce', 'konx-affiliate-dashboard' ),
			'value'  => $wc ? ( defined( 'WC_VERSION' ) ? WC_VERSION : __( 'Active', 'konx-affiliate-dashboard' ) ) : __( 'Not Active', 'konx-affiliate-dashboard' ),
			'status' => $wc ? 'ok' : 'error',
		);

		// Required tables.
		$required = array(
			'konx_affiliates', 'konx_commissions', 'konx_wallet_ledger',
			'konx_withdrawals', 'konx_commission_rules', 'konx_product_map',
			'konx_referral_clicks', 'konx_referral_conversions',
			'konx_admin_fees', 'konx_milestones', 'konx_audit_log',
		);

		// Include API tables if they exist in schema.
		if ( defined( 'KONX_AFFILIATE_DB_VERSION' ) && version_compare( KONX_AFFILIATE_DB_VERSION, '1.1.0', '>=' ) ) {
			$required[] = 'konx_api_keys';
			$required[] = 'konx_api_log';
		}

		$missing = array();
		foreach ( $required as $t ) {
			$full = $wpdb->prefix . $t;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) {
				$missing[] = $t;
			}
		}
		$items[] = array(
			'label'  => sprintf( __( 'Database Tables (%d)', 'konx-affiliate-dashboard' ), count( $required ) ),
			'value'  => empty( $missing ) ? __( 'All present', 'konx-affiliate-dashboard' ) : sprintf( __( 'Missing: %s', 'konx-affiliate-dashboard' ), implode( ', ', $missing ) ),
			'status' => empty( $missing ) ? 'ok' : 'error',
		);

		// Schema version.
		$db_ver = get_option( 'konx_affiliate_db_version', '0' );
		$items[] = array(
			'label'  => __( 'Schema Version', 'konx-affiliate-dashboard' ),
			'value'  => $db_ver,
			'status' => version_compare( $db_ver, KONX_AFFILIATE_DB_VERSION, '>=' ) ? 'ok' : 'warning',
		);

		// Plugin version.
		$items[] = array(
			'label'  => __( 'Plugin Version', 'konx-affiliate-dashboard' ),
			'value'  => KONX_AFFILIATE_VERSION,
			'status' => 'ok',
		);

		// Required pages.
		$dash_page = self::find_page_with_shortcode( 'konx_affiliate_dashboard' );
		$reg_page  = self::find_page_with_shortcode( 'konx_affiliate_register' );
		$items[] = array(
			'label'  => __( 'Required Pages', 'konx-affiliate-dashboard' ),
			'value'  => ( $dash_page && $reg_page ) ? __( 'Dashboard & Registration found', 'konx-affiliate-dashboard' ) : __( 'Pages missing', 'konx-affiliate-dashboard' ),
			'status' => ( $dash_page && $reg_page ) ? 'ok' : 'warning',
		);

		$worst = 'ok';
		foreach ( $items as $i ) {
			if ( 'error' === $i['status'] ) { $worst = 'error'; break; }
			if ( 'warning' === $i['status'] ) { $worst = 'warning'; }
		}

		return array( 'status' => $worst, 'items' => $items );
	}

	/**
	 * API key health.
	 *
	 * @return array { status, active, revoked, last_created }
	 */
	public static function api_health() {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_api_keys';

		// Table may not exist in older schema versions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array( 'status' => 'info', 'active' => 0, 'revoked' => 0, 'last_created' => null );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE revoked_at IS NULL" );
		$revoked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE revoked_at IS NOT NULL" );
		$last    = $wpdb->get_var( "SELECT MAX(created_at) FROM {$table}" );
		// phpcs:enable

		return array(
			'status'       => $active > 0 ? 'ok' : 'warning',
			'active'       => $active,
			'revoked'      => $revoked,
			'last_created' => $last,
		);
	}

	/**
	 * Product mapping health.
	 *
	 * @return array { status, mapped }
	 */
	public static function product_health() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$mapped = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}konx_product_map WHERE is_active = 1" );

		return array(
			'status' => $mapped > 0 ? 'ok' : 'warning',
			'mapped' => $mapped,
		);
	}

	/**
	 * Migration readiness health.
	 *
	 * @return array { status, source, scanned, scan_data, conflicts, approved }
	 */
	public static function migration_health() {
		$state    = get_option( 'konx_migration_state', array() );
		$source   = isset( $state['source'] ) ? $state['source'] : null;
		$scan     = isset( $state['scan'] ) ? $state['scan'] : null;
		$dr       = isset( $state['dry_run'] ) ? $state['dry_run'] : null;
		$approved = ! empty( $state['approved'] );

		$conflicts = 0;
		if ( $dr && isset( $dr['errors'] ) ) {
			$conflicts = count( $dr['errors'] );
		}

		$status = 'info';
		if ( $approved ) {
			$status = 'ok';
		} elseif ( $scan ) {
			$status = 'warning';
		}

		return array(
			'status'    => $status,
			'source'    => $source,
			'scanned'   => ! empty( $scan ),
			'scan_data' => $scan,
			'conflicts' => $conflicts,
			'approved'  => $approved,
		);
	}

	/**
	 * Affiliate health.
	 *
	 * @return array { status, total, active, pending, suspended, inactive }
	 */
	public static function affiliate_health() {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_affiliates';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$active    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'active' ) );
		$pending   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'pending' ) );
		$suspended = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'suspended' ) );
		$inactive  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'inactive' ) );
		// phpcs:enable

		return array(
			'status'    => $total > 0 ? 'ok' : 'info',
			'total'     => $total,
			'active'    => $active,
			'pending'   => $pending,
			'suspended' => $suspended,
			'inactive'  => $inactive,
		);
	}

	/**
	 * Commission rules health.
	 *
	 * @return array { status, active_rules, total_rules }
	 */
	public static function commission_health() {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_commission_rules';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_active = 1" );
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable

		return array(
			'status'       => $active > 0 ? 'ok' : 'warning',
			'active_rules' => $active,
			'total_rules'  => $total,
		);
	}

	/**
	 * Find a published page containing a shortcode.
	 *
	 * @param string $shortcode The shortcode name (without brackets).
	 * @return int|null Page ID or null.
	 */
	private static function find_page_with_shortcode( $shortcode ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s LIMIT 1",
			'%[' . $wpdb->esc_like( $shortcode ) . ']%'
		) );

		return $id ? (int) $id : null;
	}
}
