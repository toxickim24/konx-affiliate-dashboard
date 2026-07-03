<?php
/**
 * Migration session management.
 *
 * Creates and tracks migration sessions. Each session represents a
 * single migration attempt with its own unique ID, CSV fingerprint,
 * and status lifecycle. No migration execution occurs here — sessions
 * are metadata containers only.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Migration_Session
 */
class Konx_Migration_Session {

	/**
	 * Valid session statuses.
	 *
	 * @var array
	 */
	private static $valid_statuses = array(
		'planning',
		'approved',
		'queued',
		'executing',
		'paused',
		'completed',
		'failed',
		'rolled_back',
	);

	// ------------------------------------------------------------------
	// Session Creation
	// ------------------------------------------------------------------

	/**
	 * Create a new migration session.
	 *
	 * Generates a unique session ID and stores session metadata in
	 * the wp_konx_migration_sessions table. Does NOT start execution.
	 *
	 * @param array $args {
	 *     Session parameters.
	 *
	 *     @type string $csv_filename   Original CSV filename.
	 *     @type string $csv_hash       SHA-256 hash of CSV content.
	 *     @type int    $total_records  Total records in CSV.
	 *     @type int    $initiated_by   WordPress user ID.
	 * }
	 * @return array|WP_Error { 'session_id' => string, 'id' => int } or WP_Error.
	 */
	public static function create( $args ) {
		global $wpdb;

		$csv_filename  = isset( $args['csv_filename'] ) ? sanitize_file_name( $args['csv_filename'] ) : '';
		$csv_hash      = isset( $args['csv_hash'] ) ? sanitize_text_field( $args['csv_hash'] ) : '';
		$total_records = isset( $args['total_records'] ) ? absint( $args['total_records'] ) : 0;
		$initiated_by  = isset( $args['initiated_by'] ) ? absint( $args['initiated_by'] ) : 0;

		if ( empty( $csv_filename ) || empty( $csv_hash ) ) {
			return new \WP_Error( 'invalid_args', __( 'CSV filename and hash are required.', 'konx-affiliate-dashboard' ) );
		}

		if ( 0 === $total_records ) {
			return new \WP_Error( 'invalid_args', __( 'Total records must be greater than zero.', 'konx-affiliate-dashboard' ) );
		}

		$session_id = self::generate_session_id();

		$table = $wpdb->prefix . 'konx_migration_sessions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'session_id'    => $session_id,
				'status'        => 'planning',
				'csv_filename'  => $csv_filename,
				'csv_hash'      => $csv_hash,
				'total_records' => $total_records,
				'initiated_by'  => $initiated_by,
				'started_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'db_error', __( 'Failed to create migration session.', 'konx-affiliate-dashboard' ) );
		}

		return array(
			'session_id' => $session_id,
			'id'         => (int) $wpdb->insert_id,
		);
	}

	// ------------------------------------------------------------------
	// Session Retrieval
	// ------------------------------------------------------------------

	/**
	 * Get a session by its unique session ID.
	 *
	 * @param string $session_id The session ID.
	 * @return object|null The session row or null.
	 */
	public static function get( $session_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_migration_sessions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s", $session_id )
		);
	}

	/**
	 * Get the most recent session.
	 *
	 * @return object|null The session row or null.
	 */
	public static function get_latest() {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_migration_sessions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1" );
	}

	/**
	 * Get all sessions.
	 *
	 * @return array Array of session row objects.
	 */
	public static function get_all() {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_migration_sessions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
	}

	// ------------------------------------------------------------------
	// Status Management
	// ------------------------------------------------------------------

	/**
	 * Update session status.
	 *
	 * @param string $session_id The session ID.
	 * @param string $status     The new status.
	 * @return bool True on success, false on failure.
	 */
	public static function update_status( $session_id, $status ) {
		global $wpdb;

		if ( ! in_array( $status, self::$valid_statuses, true ) ) {
			return false;
		}

		$table = $wpdb->prefix . 'konx_migration_sessions';
		$data  = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);

		if ( 'completed' === $status || 'failed' === $status || 'rolled_back' === $status ) {
			$data['completed_at'] = current_time( 'mysql', true );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$data,
			array( 'session_id' => $session_id ),
			null,
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Update session progress counters.
	 *
	 * @param string $session_id The session ID.
	 * @param array  $counters   { processed, succeeded, failed, skipped }.
	 * @return bool True on success.
	 */
	public static function update_progress( $session_id, $counters ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_migration_sessions';
		$data  = array( 'updated_at' => current_time( 'mysql', true ) );

		foreach ( array( 'processed', 'succeeded', 'failed', 'skipped' ) as $key ) {
			if ( isset( $counters[ $key ] ) ) {
				$data[ $key ] = absint( $counters[ $key ] );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$data,
			array( 'session_id' => $session_id ),
			null,
			array( '%s' )
		);

		return false !== $result;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Generate a unique session ID.
	 *
	 * Format: mig_{YYYYMMDD}_{random8}
	 * Example: mig_20260703_a1b2c3d4
	 *
	 * @return string Unique session identifier.
	 */
	private static function generate_session_id() {
		$date   = gmdate( 'Ymd' );
		$random = substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
		return 'mig_' . $date . '_' . $random;
	}

	/**
	 * Check whether a session with the same CSV hash already exists.
	 *
	 * @param string $csv_hash SHA-256 hash.
	 * @return object|null Existing session or null.
	 */
	public static function find_by_csv_hash( $csv_hash ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_migration_sessions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE csv_hash = %s AND status NOT IN ('failed','rolled_back') ORDER BY id DESC LIMIT 1",
				$csv_hash
			)
		);
	}

	/**
	 * Get valid session statuses.
	 *
	 * @return array Array of valid status strings.
	 */
	public static function get_valid_statuses() {
		return self::$valid_statuses;
	}
}
