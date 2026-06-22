<?php
/**
 * GitHub update framework.
 *
 * Checks the GitHub repository for newer releases and displays
 * an admin notice when an update is available. Does NOT auto-install.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Konx_Updater {

	const REPO_URL     = 'https://api.github.com/repos/toxickim24/konx-affiliate-dashboard/releases/latest';
	const CACHE_KEY    = 'konx_update_check';
	const CACHE_EXPIRY = 43200; // 12 hours.

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_update_notice' ) );
	}

	/**
	 * Show an admin notice if a newer version is available.
	 */
	public static function maybe_show_update_notice() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			return;
		}

		$remote_version = self::get_remote_version();
		if ( ! $remote_version ) {
			return;
		}

		if ( version_compare( KONX_AFFILIATE_VERSION, $remote_version, '>=' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s <a href="%s" target="_blank">%s</a></p></div>',
			sprintf(
				esc_html__( 'KonX Affiliate Dashboard v%s is available. You are running v%s.', 'konx-affiliate-dashboard' ),
				esc_html( $remote_version ),
				esc_html( KONX_AFFILIATE_VERSION )
			),
			'https://github.com/toxickim24/konx-affiliate-dashboard/releases/latest',
			esc_html__( 'View release on GitHub', 'konx-affiliate-dashboard' )
		);
	}

	/**
	 * Get the latest release version from GitHub.
	 *
	 * Caches the result for 12 hours.
	 *
	 * @return string|false Version string, or false on failure.
	 */
	private static function get_remote_version() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get( self::REPO_URL, array(
			'timeout' => 5,
			'headers' => array( 'Accept' => 'application/vnd.github.v3+json' ),
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, '', self::CACHE_EXPIRY );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, '', self::CACHE_EXPIRY );
			return false;
		}

		$version = ltrim( $body['tag_name'], 'v' );
		set_transient( self::CACHE_KEY, $version, self::CACHE_EXPIRY );

		return $version;
	}
}
