<?php
/**
 * Immutable execution session management.
 *
 * An execution session (wp_konx_migration_exec_sessions) is a frozen
 * snapshot of intent: which plugin version, which DB schema, which source
 * file, and which Final Migration Plan hash were present at the moment
 * a snapshot was committed.
 *
 * LIFECYCLE:
 *   draft       — created but plan not yet committed
 *   frozen      — plan snapshot committed; session is now immutable.
 *                 When KONX_MIGRATION_TEST_EXECUTION_ENABLED is true, frozen
 *                 sessions may be executed by the single-record executor in
 *                 test environments. No status change is required.
 *   approved    — (future) admin has explicitly approved for production execution
 *   running     — (future) batch execution is in progress
 *   paused      — (future) execution was paused mid-run
 *   completed   — (future) all records processed successfully
 *   failed      — (future) execution ended with errors
 *   rolled_back — (future) execution was reversed
 *   invalidated — snapshot is stale (e.g. source/schema changed after freeze)
 *
 * STATUS MUTATION CONTRACT (Phase 24C-6F):
 *   update_status() is now private — it is the internal DB persistence helper
 *   and must not be called by external code. All external lifecycle mutations
 *   must go through transition(), freeze(), or invalidate(). These methods
 *   enforce the allowed-transitions map and canonical-session protection before
 *   delegating to update_status().
 *
 * IMMUTABILITY CONTRACT:
 *   Once a session reaches 'frozen', its core identity fields (plan hash,
 *   record count, decision counts, plugin version, schema version, source hash)
 *   MUST NOT be modified. Only status, timestamps, and attribution fields
 *   (approved_by, executed_by, rollback_by) may change after freezing.
 *
 *   If the underlying Final Migration Plan changes, a NEW session must be
 *   created. The old session should be invalidated.
 *
 * This class is separate from Konx_Migration_Session (wp_konx_migration_sessions),
 * which handles the legacy wizard-based planning sessions. The exec session
 * is the authoritative record for what was approved and executed.
 *
 * @package KonxAffiliateDashboard
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Migration_Exec_Session
 */
class Konx_Migration_Exec_Session {

	/**
	 * Table name (without prefix).
	 */
	const TABLE = 'konx_migration_exec_sessions';

	/**
	 * Valid session statuses.
	 *
	 * Phase 24C-6E: 'test_execution' was removed. Test execution runs directly
	 * against 'frozen' sessions when KONX_MIGRATION_TEST_EXECUTION_ENABLED is true.
	 *
	 * @var array
	 */
	private static $valid_statuses = array(
		'draft',
		'frozen',
		'approved',
		'running',
		'paused',
		'completed',
		'failed',
		'rolled_back',
		'invalidated',
	);

	/**
	 * Fields that become immutable once the session is frozen.
	 *
	 * Any attempt to update these fields on a frozen session via
	 * update_identity_fields() will be blocked.
	 *
	 * @var array
	 */
	private static $frozen_fields = array(
		'source_type',
		'source_filename',
		'source_hash',
		'source_hash_algorithm',
		'final_plan_hash',
		'final_plan_record_count',
		'decision_create_count',
		'decision_link_wp_count',
		'decision_link_ca_count',
		'decision_invalid_count',
		'decision_review_count',
		'plugin_version',
		'database_schema_version',
	);

	// ------------------------------------------------------------------
	// Session Creation
	// ------------------------------------------------------------------

	/**
	 * Create a new execution session in 'draft' status.
	 *
	 * The session is created before the plan snapshot is committed.
	 * Call freeze() after the plan snapshot is complete.
	 *
	 * @param array $args {
	 *     Session parameters.
	 *
	 *     @type string   $source_type              'csv' or 'database'.
	 *     @type string   $source_filename          Original CSV filename (optional).
	 *     @type string   $source_hash              SHA-256 of source file (optional).
	 *     @type string   $final_plan_hash          SHA-256 of canonical FMP.
	 *     @type int      $final_plan_record_count  Total records in FMP.
	 *     @type int      $decision_create_count    Count of 'create' decisions.
	 *     @type int      $decision_link_wp_count   Count of 'link_wp' decisions.
	 *     @type int      $decision_link_ca_count   Count of 'link_ca' decisions.
	 *     @type int      $decision_invalid_count   Count of 'invalid' decisions.
	 *     @type int      $decision_review_count    Count of 'review' decisions.
	 *     @type int      $created_by               WordPress user ID.
	 *     @type string   $validation_timestamp     ISO datetime of last validation.
	 *     @type string   $dry_run_timestamp        ISO datetime of last dry run.
	 *     @type string   $backup_reference         Backup ID reference.
	 * }
	 * @return array|WP_Error { session_uuid, id } on success, WP_Error on failure.
	 */
	public static function create( array $args ) {
		global $wpdb;

		$final_plan_hash = isset( $args['final_plan_hash'] )
			? sanitize_text_field( $args['final_plan_hash'] )
			: '';

		if ( empty( $final_plan_hash ) || 64 !== strlen( $final_plan_hash ) ) {
			return new \WP_Error(
				'invalid_plan_hash',
				__( 'A valid 64-character SHA-256 plan hash is required.', 'konx-affiliate-dashboard' )
			);
		}

		$final_plan_record_count = isset( $args['final_plan_record_count'] )
			? absint( $args['final_plan_record_count'] )
			: 0;

		if ( 0 === $final_plan_record_count ) {
			return new \WP_Error(
				'invalid_record_count',
				__( 'final_plan_record_count must be greater than zero.', 'konx-affiliate-dashboard' )
			);
		}

		$session_uuid = self::generate_session_uuid();
		$table        = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'session_uuid'               => $session_uuid,
				'source_type'                => sanitize_text_field( $args['source_type'] ?? 'csv' ),
				'source_filename'            => sanitize_file_name( $args['source_filename'] ?? '' ) ?: null,
				'source_hash'                => sanitize_text_field( $args['source_hash'] ?? '' ) ?: null,
				'source_hash_algorithm'      => 'sha256',
				'final_plan_hash'            => $final_plan_hash,
				'final_plan_record_count'    => $final_plan_record_count,
				'decision_create_count'      => absint( $args['decision_create_count'] ?? 0 ),
				'decision_link_wp_count'     => absint( $args['decision_link_wp_count'] ?? 0 ),
				'decision_link_ca_count'     => absint( $args['decision_link_ca_count'] ?? 0 ),
				'decision_invalid_count'     => absint( $args['decision_invalid_count'] ?? 0 ),
				'decision_review_count'      => absint( $args['decision_review_count'] ?? 0 ),
				'plugin_version'             => KONX_AFFILIATE_VERSION,
				'database_schema_version'    => KONX_AFFILIATE_DB_VERSION,
				'status'                     => 'draft',
				'created_by'                 => absint( $args['created_by'] ?? 0 ) ?: null,
				'validation_timestamp'       => ! empty( $args['validation_timestamp'] )
					? sanitize_text_field( $args['validation_timestamp'] )
					: null,
				'dry_run_timestamp'          => ! empty( $args['dry_run_timestamp'] )
					? sanitize_text_field( $args['dry_run_timestamp'] )
					: null,
				'backup_reference'           => ! empty( $args['backup_reference'] )
					? sanitize_text_field( $args['backup_reference'] )
					: null,
				'created_at'                 => current_time( 'mysql', true ),
			),
			array(
				'%s', '%s', '%s', '%s', '%s', '%s', '%d',
				'%d', '%d', '%d', '%d', '%d',
				'%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s',
			)
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'db_error',
				__( 'Failed to create execution session.', 'konx-affiliate-dashboard' )
			);
		}

		return array(
			'session_uuid' => $session_uuid,
			'id'           => (int) $wpdb->insert_id,
		);
	}

	// ------------------------------------------------------------------
	// Session Retrieval
	// ------------------------------------------------------------------

	/**
	 * Get a session by its UUID.
	 *
	 * @param string $session_uuid The session UUID.
	 * @return object|null Session row or null.
	 */
	public static function get( $session_uuid ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE session_uuid = %s", $session_uuid )
		);
	}

	/**
	 * Get a session by its numeric row ID.
	 *
	 * @param int $id Row ID.
	 * @return object|null Session row or null.
	 */
	public static function get_by_id( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) )
		);
	}

	/**
	 * Get all sessions with a given plan hash.
	 *
	 * Used to detect whether a plan was already snapshotted.
	 *
	 * @param string $plan_hash SHA-256 plan hash.
	 * @return array Array of session rows.
	 */
	public static function get_by_plan_hash( $plan_hash ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE final_plan_hash = %s ORDER BY id DESC",
				$plan_hash
			)
		);
	}

	/**
	 * Get the most recent non-invalidated session.
	 *
	 * @return object|null Session row or null.
	 */
	public static function get_latest_active() {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			"SELECT * FROM {$table} WHERE status NOT IN ('invalidated') ORDER BY id DESC LIMIT 1"
		);
	}

	/**
	 * Get all sessions.
	 *
	 * @return array Array of session row objects.
	 */
	public static function get_all() {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
	}

	// ------------------------------------------------------------------
	// Status Transitions
	// ------------------------------------------------------------------

	/**
	 * Transition a session to 'frozen' status.
	 *
	 * Delegates to transition() which enforces the allowed-transitions map
	 * (draft → frozen), canonical-session protection, and non-existent-session
	 * detection. The old guard logic (get() + draft check) is fully handled
	 * by transition().
	 *
	 * @param string $session_uuid The session UUID.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function freeze( $session_uuid ) {
		return self::transition( $session_uuid, 'frozen' );
	}

	/**
	 * Invalidate a session.
	 *
	 * Delegates to transition() which enforces the allowed-transitions map
	 * (draft → invalidated, frozen → invalidated), canonical-session protection,
	 * and non-existent-session detection. The old guard logic is fully handled
	 * by transition().
	 *
	 * Use when the underlying FMP changes after a session was frozen.
	 * Does not delete the session — historical record is preserved.
	 *
	 * @param string $session_uuid The session UUID.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function invalidate( $session_uuid ) {
		return self::transition( $session_uuid, 'invalidated' );
	}

	/**
	 * Formal session status transition with allowed-transitions enforcement.
	 *
	 * Allowed transitions (Phase 24C-6E):
	 *   draft     → frozen, invalidated
	 *   frozen    → invalidated
	 *   All other statuses → terminal (no further transitions permitted here).
	 *
	 * The canonical session UUID is always rejected.
	 *
	 * Intentionally omitted:
	 *   frozen → approved  (reserved for future production enablement)
	 *   approved → running (reserved for future production execution lifecycle)
	 *
	 * @param string $session_uuid The session UUID.
	 * @param string $to_status    Target status.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function transition( $session_uuid, $to_status ) {
		// Canonical protection.
		if ( '395e2b79-1e0a-49e8-9ea6-1ae146c9a54d' === $session_uuid ) {
			return new \WP_Error(
				'canonical_protected',
				__( 'The canonical session cannot be transitioned.', 'konx-affiliate-dashboard' )
			);
		}

		$session = self::get( $session_uuid );
		if ( ! $session ) {
			return new \WP_Error( 'not_found', __( 'Execution session not found.', 'konx-affiliate-dashboard' ) );
		}

		// Allowed transitions map.
		$allowed_transitions = array(
			'draft'  => array( 'frozen', 'invalidated' ),
			'frozen' => array( 'invalidated' ),
		);

		$from_status = $session->status;
		if ( ! isset( $allowed_transitions[ $from_status ] ) || ! in_array( $to_status, $allowed_transitions[ $from_status ], true ) ) {
			return new \WP_Error(
				'invalid_transition',
				sprintf(
					/* translators: 1: current status, 2: target status */
					__( 'Cannot transition session from "%1$s" to "%2$s".', 'konx-affiliate-dashboard' ),
					$from_status,
					$to_status
				)
			);
		}

		return self::update_status( $session_uuid, $to_status );
	}

	/**
	 * Internal persistence helper — update session status in the database.
	 *
	 * This is a private method. All external lifecycle mutations must go through
	 * transition(), freeze(), or invalidate(). Those methods enforce the
	 * allowed-transitions map and canonical-session protection before delegating
	 * here (Phase 24C-6F: C4).
	 *
	 * @param string $session_uuid The session UUID.
	 * @param string $status       New status.
	 * @return true|WP_Error True on success.
	 */
	private static function update_status( $session_uuid, $status ) {
		global $wpdb;

		if ( ! in_array( $status, self::$valid_statuses, true ) ) {
			return new \WP_Error(
				'invalid_status',
				sprintf(
					/* translators: %s: invalid status */
					__( 'Invalid status "%s".', 'konx-affiliate-dashboard' ),
					$status
				)
			);
		}

		$table = $wpdb->prefix . self::TABLE;
		$data  = array( 'status' => $status );

		if ( in_array( $status, array( 'completed', 'failed', 'rolled_back' ), true ) ) {
			$data['completed_at'] = current_time( 'mysql', true );
		} elseif ( 'running' === $status ) {
			$data['started_at'] = current_time( 'mysql', true );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$data,
			array( 'session_uuid' => $session_uuid ),
			null,
			array( '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Failed to update session status.', 'konx-affiliate-dashboard' ) );
		}

		return true;
	}

	// ------------------------------------------------------------------
	// Immutability Enforcement
	// ------------------------------------------------------------------

	/**
	 * Check whether a session's identity fields are frozen (immutable).
	 *
	 * A session is considered immutable once it leaves 'draft' status.
	 *
	 * @param string $session_uuid The session UUID.
	 * @return bool True if the session is immutable.
	 */
	public static function is_frozen( $session_uuid ) {
		$session = self::get( $session_uuid );

		if ( ! $session ) {
			return false;
		}

		return 'draft' !== $session->status;
	}

	/**
	 * Get the list of fields that are immutable after freezing.
	 *
	 * @return array
	 */
	public static function get_frozen_fields() {
		return self::$frozen_fields;
	}

	/**
	 * Persist the result of a revalidation run to the session row.
	 *
	 * Updates the four revalidation tracking columns added in DB 1.4.0.
	 * Only updates — does NOT change the session status. A frozen session
	 * remains frozen regardless of revalidation outcome.
	 *
	 * @param string $session_uuid        UUID of the session.
	 * @param array  $revalidation_result Result from Konx_Migration_Revalidator::revalidate().
	 * @return true|WP_Error True on success.
	 */
	public static function persist_revalidation_result( $session_uuid, array $revalidation_result ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		$status = sanitize_text_field( $revalidation_result['revalidation_status'] ?? 'critical' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array(
				'last_revalidated_at'          => current_time( 'mysql', true ),
				'revalidation_status'          => $status,
				'revalidation_stale_count'     => absint( $revalidation_result['records_stale'] ?? 0 ),
				'revalidation_conflict_count'  => absint( $revalidation_result['records_conflict'] ?? 0 ),
			),
			array( 'session_uuid' => $session_uuid ),
			array( '%s', '%s', '%d', '%d' ),
			array( '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Failed to persist revalidation result.', 'konx-affiliate-dashboard' ) );
		}

		return true;
	}

	/**
	 * Get valid session statuses.
	 *
	 * @return array
	 */
	public static function get_valid_statuses() {
		return self::$valid_statuses;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Generate a unique UUID v4 session identifier.
	 *
	 * Format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
	 *
	 * @return string 36-character UUID v4.
	 */
	private static function generate_session_uuid() {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 ); // Version 4.
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 ); // Variant bits.

		return vsprintf(
			'%s%s-%s-%s-%s-%s%s%s',
			str_split( bin2hex( $bytes ), 4 )
		);
	}
}
