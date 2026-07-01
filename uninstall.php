<?php
/**
 * Fired when the plugin is deleted via WordPress admin.
 *
 * DEFAULT behavior (safe):
 *   - Removes custom roles and capabilities
 *   - Clears scheduled cron events
 *   - Does NOT delete database tables
 *   - Does NOT delete financial records
 *   - Does NOT delete plugin options or IP hash salt
 *   - Does NOT delete user meta or order meta
 *
 * DESTRUCTIVE behavior (requires explicit opt-in):
 *   - Only runs if KONX_REMOVE_ALL_DATA is defined as boolean true
 *     in wp-config.php: define( 'KONX_REMOVE_ALL_DATA', true );
 *   - Drops all 13 custom database tables
 *   - Deletes all plugin options and migration state
 *   - Deletes all transients with konx_ prefix
 *   - Deletes all user meta with konx_ prefix
 *   - Deletes all order meta with _konx_ prefix
 *   - Removes plugin-created pages (Registration & Dashboard)
 *   - Flushes rewrite rules
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// -----------------------------------------------------------------------
// ALWAYS: Remove custom roles (safe — users retain slug in usermeta,
// capabilities restored on reinstall when add_role() is called again).
// -----------------------------------------------------------------------
$roles = array(
	'konx_business_affiliate',
	'konx_team_agent',
	'konx_marketing_agent',
	'konx_sales_agent',
);
foreach ( $roles as $role ) {
	remove_role( $role );
}

// Remove custom capabilities from administrator.
$admin = get_role( 'administrator' );
if ( $admin ) {
	$caps = array(
		'view_konx_dashboard',
		'request_withdrawal',
		'view_commissions',
		'view_wallet',
		'manage_konx_affiliates',
		'manage_konx_commissions',
		'manage_konx_withdrawals',
		'manage_konx_settings',
	);
	foreach ( $caps as $cap ) {
		$admin->remove_cap( $cap );
	}
}

// -----------------------------------------------------------------------
// ALWAYS: Clear scheduled cron events.
// -----------------------------------------------------------------------
$cron_hooks = array( 'konx_daily_overdue_fee_check', 'konx_click_data_cleanup' );
foreach ( $cron_hooks as $hook ) {
	$ts = wp_next_scheduled( $hook );
	if ( $ts ) {
		wp_unschedule_event( $ts, $hook );
	}
}

// -----------------------------------------------------------------------
// DESTRUCTIVE: Only runs if either:
//   1. KONX_REMOVE_ALL_DATA constant is true in wp-config.php, OR
//   2. Admin checked "Delete all data" in Settings page
// -----------------------------------------------------------------------
$remove_via_constant = ( defined( 'KONX_REMOVE_ALL_DATA' ) && true === KONX_REMOVE_ALL_DATA );
$remove_via_setting  = (bool) get_option( 'konx_remove_all_data', false );

if ( $remove_via_constant || $remove_via_setting ) {

	// Delete plugin-created pages (before removing the options that store their IDs).
	$page_options = array( 'konx_registration_page_id', 'konx_dashboard_page_id' );
	foreach ( $page_options as $opt ) {
		$page_id = (int) get_option( $opt, 0 );
		if ( $page_id > 0 && get_post( $page_id ) ) {
			wp_delete_post( $page_id, true );
		}
	}

	// Delete plugin options.
	$options = array(
		'konx_affiliate_version',
		'konx_affiliate_db_version',
		'konx_ip_hash_salt',
		'konx_affiliate_settings',
		'konx_admin_fee_settings',
		'konx_referral_settings',
		'konx_recurring_commission_rate',
		'konx_registration_page_id',
		'konx_dashboard_page_id',
		'konx_remove_all_data',
		'konx_migration_state',
	);
	foreach ( $options as $opt ) {
		delete_option( $opt );
	}

	// Delete all transients with konx_ prefix (covers dynamic keys like konx_dash_feedback_*, konx_new_api_key_*).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_konx\_%' OR option_name LIKE '\_transient\_timeout\_konx\_%'" );

	// Drop custom tables.
	$tables = array(
		'konx_affiliates',
		'konx_referral_clicks',
		'konx_referral_conversions',
		'konx_commissions',
		'konx_wallet_ledger',
		'konx_withdrawals',
		'konx_admin_fees',
		'konx_milestones',
		'konx_commission_rules',
		'konx_product_map',
		'konx_audit_log',
		'konx_api_keys',
		'konx_api_log',
	);
	foreach ( $tables as $table ) {
		$full = $wpdb->prefix . $table;
		$wpdb->query( "DROP TABLE IF EXISTS {$full}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	// Remove user meta.
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'konx\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Remove order meta (classic post-based storage).
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_konx\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Remove order meta (WooCommerce HPOS storage, if table exists).
	$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_meta ) ) === $hpos_meta ) {
		$wpdb->query( "DELETE FROM {$hpos_meta} WHERE meta_key LIKE '\_konx\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// Flush rewrite rules to remove the affiliate-dashboard endpoint.
	flush_rewrite_rules();
}
