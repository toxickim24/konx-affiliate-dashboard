<?php
/**
 * Referral link tracking.
 *
 * Detects the ?ref= query parameter, validates the affiliate,
 * sets a first-party HttpOnly cookie, logs the click to the
 * database with a salted IP hash, and enqueues a small JS file
 * that stores the referral code in localStorage as a fallback.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Referral_Tracker
 */
class Konx_Referral_Tracker {

	/**
	 * Cookie name for the referral code.
	 */
	const COOKIE_NAME = 'konx_ref';

	/**
	 * Default cookie lifetime in days (used if settings not configured).
	 */
	const DEFAULT_COOKIE_DAYS = 30;

	/**
	 * Default duplicate-click suppression window in seconds (used if settings not configured).
	 */
	const DEFAULT_DEDUP_WINDOW = 86400;

	/**
	 * Register WordPress hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'track_referral' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	// ------------------------------------------------------------------
	// Referral Detection
	// ------------------------------------------------------------------

	/**
	 * Detect ?ref= parameter, validate, set cookie, log click.
	 *
	 * Runs on the `init` hook. Skips admin requests.
	 */
	public static function track_referral() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$ref_param = Konx_Settings_Page::get_ref_param();

		if ( ! isset( $_GET[ $ref_param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code = strtoupper( sanitize_text_field( wp_unslash( $_GET[ $ref_param ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $code ) || strlen( $code ) > 12 ) {
			return;
		}

		// Validate affiliate exists and is active.
		$affiliate = Konx_Affiliate_Manager::get_affiliate_by_referral_code( $code );
		if ( ! $affiliate || 'active' !== $affiliate->status ) {
			return;
		}

		// Self-referral prevention.
		if ( is_user_logged_in() && (int) $affiliate->user_id === get_current_user_id() ) {
			return;
		}

		// Set cookie (overwrites any existing — last-click attribution).
		self::set_cookie( $code );

		// Log click with deduplication.
		self::log_click( $affiliate, $code );
	}

	// ------------------------------------------------------------------
	// Cookie Management
	// ------------------------------------------------------------------

	/**
	 * Set the referral tracking cookie.
	 *
	 * @param string $code The referral code.
	 */
	private static function set_cookie( $code ) {
		if ( headers_sent() ) {
			return;
		}

		$cookie_days = Konx_Settings_Page::get_cookie_days();
		$expiry      = time() + ( $cookie_days * DAY_IN_SECONDS );

		setcookie(
			self::COOKIE_NAME,
			$code,
			array(
				'expires'  => $expiry,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly'  => true,
				'samesite' => 'Lax',
			)
		);

		// Make available in the current request immediately.
		$_COOKIE[ self::COOKIE_NAME ] = $code;
	}

	/**
	 * Read the referral code from the cookie.
	 *
	 * @return string The referral code, or empty string if not set.
	 */
	public static function get_referral_code() {
		if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return strtoupper( sanitize_text_field( $_COOKIE[ self::COOKIE_NAME ] ) );
		}
		return '';
	}

	/**
	 * Clear the referral cookie.
	 */
	public static function clear_cookie() {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE_NAME,
			'',
			array(
				'expires'  => time() - 3600,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly'  => true,
				'samesite' => 'Lax',
			)
		);

		unset( $_COOKIE[ self::COOKIE_NAME ] );
	}

	// ------------------------------------------------------------------
	// Click Logging
	// ------------------------------------------------------------------

	/**
	 * Log a referral click to the database.
	 *
	 * Skips the insert if the same IP + affiliate was logged within
	 * the deduplication window (24 hours).
	 *
	 * @param object $affiliate The affiliate row object.
	 * @param string $code      The referral code.
	 */
	private static function log_click( $affiliate, $code ) {
		global $wpdb;

		$ip_hash = self::hash_ip();
		$table   = $wpdb->prefix . 'konx_referral_clicks';
		$dedup_window = Konx_Settings_Page::get_dedup_window();
		$cutoff       = gmdate( 'Y-m-d H:i:s', time() - $dedup_window );

		// Duplicate suppression: same IP + affiliate within 24 hours.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE ip_hash = %s AND affiliate_id = %d AND clicked_at > %s",
				$ip_hash,
				(int) $affiliate->id,
				$cutoff
			)
		);

		if ( $exists > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'affiliate_id'  => (int) $affiliate->id,
				'referral_code' => $code,
				'ip_hash'       => $ip_hash,
				'user_agent'    => self::get_user_agent(),
				'landing_url'   => self::get_landing_url(),
				'referrer_url'  => self::get_referrer_url(),
				'clicked_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	// ------------------------------------------------------------------
	// Frontend Scripts
	// ------------------------------------------------------------------

	/**
	 * Enqueue the referral tracking JavaScript on all frontend pages.
	 *
	 * The script captures ?ref= into localStorage (parallel to the
	 * server-set HttpOnly cookie) and populates a hidden field on
	 * the WooCommerce checkout page.
	 */
	public static function enqueue_scripts() {
		wp_enqueue_script(
			'konx-referral-tracking',
			KONX_AFFILIATE_PLUGIN_URL . 'assets/js/konx-referral-tracking.js',
			array(),
			KONX_AFFILIATE_VERSION,
			array( 'in_footer' => true )
		);
	}

	// ------------------------------------------------------------------
	// Private Helpers
	// ------------------------------------------------------------------

	/**
	 * Hash the visitor IP with the stored salt.
	 *
	 * @return string 64-character hex SHA-256 hash.
	 */
	private static function hash_ip() {
		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		$salt = get_option( 'konx_ip_hash_salt', '' );
		return hash( 'sha256', $ip . $salt );
	}

	/**
	 * Get the truncated user-agent string.
	 *
	 * @return string|null User-agent (max 500 chars), or null.
	 */
	private static function get_user_agent() {
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 );
		}
		return null;
	}

	/**
	 * Get the current request URL (landing page).
	 *
	 * @return string|null The landing URL (max 2048 chars), or null.
	 */
	private static function get_landing_url() {
		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$scheme = is_ssl() ? 'https' : 'http';
			$host   = ! empty( $_SERVER['HTTP_HOST'] )
				? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
				: wp_parse_url( home_url(), PHP_URL_HOST );
			$uri    = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			return mb_substr( $scheme . '://' . $host . $uri, 0, 2048 );
		}
		return null;
	}

	/**
	 * Get the HTTP Referer header.
	 *
	 * @return string|null The referrer URL (max 2048 chars), or null.
	 */
	private static function get_referrer_url() {
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			return mb_substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 2048 );
		}
		return null;
	}
}
