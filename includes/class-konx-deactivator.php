<?php
/**
 * Plugin deactivation handler.
 *
 * Removes custom roles and clears scheduled events.
 * Does NOT delete database tables, options, or user data.
 * Destructive cleanup is handled in uninstall.php only.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Deactivator
 */
class Konx_Deactivator {

	/**
	 * Run the deactivation routine.
	 */
	public static function deactivate() {
		Konx_Roles::remove_roles();
		self::clear_scheduled_events();
	}

	/**
	 * Unschedule all plugin cron events.
	 */
	private static function clear_scheduled_events() {
		$hooks = array(
			'konx_daily_overdue_fee_check',
			'konx_click_data_cleanup',
		);

		foreach ( $hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}
}
