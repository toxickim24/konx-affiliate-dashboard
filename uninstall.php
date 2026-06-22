<?php
/**
 * Fired when the plugin is deleted via WordPress admin.
 *
 * Drops all custom tables, removes plugin options, cleans user meta,
 * and removes custom roles/capabilities.
 *
 * Safety gate: define KONX_REMOVE_ALL_DATA as true in wp-config.php
 * to enable destructive cleanup. Without it, only options and roles
 * are removed; tables and user meta are preserved.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove plugin options.
delete_option( 'konx_affiliate_version' );
delete_option( 'konx_affiliate_db_version' );
delete_option( 'konx_ip_hash_salt' );
delete_option( 'konx_affiliate_settings' );
delete_option( 'konx_admin_fee_settings' );
delete_option( 'konx_referral_settings' );
delete_option( 'konx_recurring_commission_rate' );

// Remove custom roles.
$roles = array(
	'konx_business_affiliate',
	'konx_referral_affiliate',
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

// Clear scheduled events.
$cron_hooks = array( 'konx_daily_overdue_fee_check', 'konx_click_data_cleanup' );
foreach ( $cron_hooks as $hook ) {
	$ts = wp_next_scheduled( $hook );
	if ( $ts ) {
		wp_unschedule_event( $ts, $hook );
	}
}

// Destructive cleanup: tables and user meta.
// Only runs if KONX_REMOVE_ALL_DATA is explicitly defined as true.
if ( defined( 'KONX_REMOVE_ALL_DATA' ) && KONX_REMOVE_ALL_DATA ) {

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
	);
	foreach ( $tables as $table ) {
		$full = $wpdb->prefix . $table;
		$wpdb->query( "DROP TABLE IF EXISTS {$full}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	// Remove user meta.
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'konx\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Remove order meta (WooCommerce HPOS-compatible fallback).
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_konx\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// If WooCommerce HPOS orders meta table exists, clean it too.
	$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_meta ) ) === $hpos_meta ) {
		$wpdb->query( "DELETE FROM {$hpos_meta} WHERE meta_key LIKE '\_konx\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
