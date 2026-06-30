<?php
/**
 * API key management and request logging.
 *
 * Provides key generation, hashing, validation, and request logging
 * for the KonX Affiliates REST API. Keys are stored as SHA-256
 * hashes — plaintext keys are never persisted.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Api_Helper
 */
class Konx_Api_Helper {

	/**
	 * Prefix for generated API keys.
	 */
	const KEY_PREFIX = 'konx_';

	/**
	 * Length of the random portion of the key (excluding prefix).
	 */
	const KEY_LENGTH = 40;

	// ------------------------------------------------------------------
	// Key Generation
	// ------------------------------------------------------------------

	/**
	 * Generate a new API key and store its hash.
	 *
	 * Returns the plaintext key exactly once — it cannot be retrieved
	 * after this call because only the hash is stored.
	 *
	 * @param string $name       A human-readable label for the key.
	 * @param int    $created_by The WordPress user ID creating the key.
	 * @return array|WP_Error { 'key' => plaintext, 'id' => row ID, 'prefix' => first 8 chars } or WP_Error.
	 */
	public static function generate_key( $name, $created_by ) {
		global $wpdb;

		$name = sanitize_text_field( $name );
		if ( empty( $name ) ) {
			return new \WP_Error( 'invalid_name', __( 'API key name is required.', 'konx-affiliate-dashboard' ) );
		}

		// Generate a cryptographically secure random key.
		$random    = wp_generate_password( self::KEY_LENGTH, false, false );
		$plaintext = self::KEY_PREFIX . $random;
		$hash      = self::hash_key( $plaintext );
		$prefix    = substr( $plaintext, 0, 8 );

		$table = $wpdb->prefix . 'konx_api_keys';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'key_name'   => $name,
				'key_hash'   => $hash,
				'key_prefix' => $prefix,
				'permissions' => 'read_write',
				'created_by' => absint( $created_by ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'db_error', __( 'Failed to store API key.', 'konx-affiliate-dashboard' ) );
		}

		return array(
			'key'    => $plaintext,
			'id'     => (int) $wpdb->insert_id,
			'prefix' => $prefix,
		);
	}

	// ------------------------------------------------------------------
	// Key Validation
	// ------------------------------------------------------------------

	/**
	 * Validate an API key from a request.
	 *
	 * Hashes the provided key and looks it up in the database.
	 * Updates last_used_at on successful validation.
	 *
	 * @param string $plaintext_key The API key from the request header.
	 * @return object|false The API key row on success, false on failure.
	 */
	public static function validate_key( $plaintext_key ) {
		global $wpdb;

		$plaintext_key = sanitize_text_field( $plaintext_key );
		if ( empty( $plaintext_key ) ) {
			return false;
		}

		$hash  = self::hash_key( $plaintext_key );
		$table = $wpdb->prefix . 'konx_api_keys';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$key_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE key_hash = %s AND revoked_at IS NULL",
				$hash
			)
		);

		if ( ! $key_row ) {
			return false;
		}

		// Update last_used_at.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'last_used_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $key_row->id ),
			array( '%s' ),
			array( '%d' )
		);

		return $key_row;
	}

	/**
	 * Revoke an API key by ID.
	 *
	 * @param int $key_id The API key row ID.
	 * @return bool True on success, false on failure.
	 */
	public static function revoke_key( $key_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_api_keys';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array( 'id' => absint( $key_id ) ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get all API keys (for admin display).
	 *
	 * Returns metadata only — never the hash or plaintext.
	 *
	 * @return array Array of key row objects.
	 */
	public static function get_all_keys() {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_api_keys';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			"SELECT id, key_name, key_prefix, permissions, created_by, last_used_at, created_at, revoked_at FROM {$table} ORDER BY created_at DESC"
		);
	}

	// ------------------------------------------------------------------
	// Request Logging
	// ------------------------------------------------------------------

	/**
	 * Log an API request.
	 *
	 * @param int|null $api_key_id    The API key ID (null if unauthenticated).
	 * @param string   $endpoint      The REST route path.
	 * @param string   $method        HTTP method (GET, POST, etc.).
	 * @param int      $response_code HTTP response code.
	 * @param string   $error_message Error message if request failed, null otherwise.
	 */
	public static function log_request( $api_key_id, $endpoint, $method, $response_code, $error_message = null ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_api_log';

		// Hash the IP for privacy.
		$ip_hash = null;
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$salt    = get_option( 'konx_ip_hash_salt', '' );
			$ip      = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			$ip_hash = hash( 'sha256', $ip . $salt );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'api_key_id'      => $api_key_id ? absint( $api_key_id ) : null,
				'endpoint'        => sanitize_text_field( $endpoint ),
				'request_method'  => strtoupper( sanitize_text_field( $method ) ),
				'request_ip_hash' => $ip_hash,
				'response_code'   => absint( $response_code ),
				'error_message'   => $error_message ? sanitize_text_field( mb_substr( $error_message, 0, 500 ) ) : null,
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Hash an API key using SHA-256.
	 *
	 * @param string $plaintext_key The plaintext API key.
	 * @return string 64-character hex hash.
	 */
	public static function hash_key( $plaintext_key ) {
		return hash( 'sha256', $plaintext_key );
	}

	/**
	 * Extract the API key from a WP_REST_Request.
	 *
	 * Checks the X-KONX-API-Key header.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return string The API key, or empty string if not present.
	 */
	public static function get_key_from_request( $request ) {
		$key = $request->get_header( 'X-KONX-API-Key' );
		return $key ? sanitize_text_field( $key ) : '';
	}
}
