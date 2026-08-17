<?php
/**
 * Execution ledger — per-record execution state tracking.
 *
 * The execution ledger (wp_konx_migration_execution_ledger) is the
 * source of truth for the current execution state of each record
 * during (and after) a migration run.
 *
 * IDEMPOTENCY DESIGN:
 *   Two levels of identity protection are implemented:
 *
 *   1. SESSION-LEVEL: UNIQUE KEY uq_session_source (session_id, source_record_id)
 *      Prevents the same source record from being inserted twice into the
 *      same session's ledger. If a snapshot is created twice for the same
 *      session (e.g. due to a retry), the second insert fails safely.
 *
 *   2. CROSS-SESSION (execution-time, future):
 *      The revalidation engine (Phase 24C-6C) will check the ledger for
 *      'completed' entries across ALL sessions for the same source_record_id
 *      before executing. This prevents re-migrating a record that was already
 *      successfully processed in a previous session.
 *
 *      A hard cross-session UNIQUE constraint on source_record_id alone is
 *      intentionally NOT used here because:
 *      - A failed or test session must not block a legitimate future session.
 *      - Only records with status='completed' represent an actual migration.
 *      - The revalidation engine applies the correct lifecycle-aware check.
 *
 * EXECUTION STATES:
 *   pending     — inserted during snapshot; not yet processed
 *   processing  — batch has started working on this record
 *   completed   — record processed successfully (WP user and/or affiliate created)
 *   skipped     — record was skipped at execution time (idempotency guard triggered)
 *   failed      — execution attempt failed; record may be retried
 *   partial     — partial success (e.g. WP user created but affiliate failed)
 *   rolled_back — execution was reversed
 *
 * PHASE 24C-6B CONSTRAINT:
 *   This class provides the schema and CRUD methods only. No status
 *   transitions beyond 'pending' are performed in this phase. The executor
 *   is NOT wired to this ledger yet (Phase 24C-6C/6D responsibility).
 *
 * @package KonxAffiliateDashboard
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Migration_Execution_Ledger
 */
class Konx_Migration_Execution_Ledger {

	/**
	 * Table name (without prefix).
	 */
	const TABLE = 'konx_migration_execution_ledger';

	/**
	 * Valid execution states.
	 *
	 * @var array
	 */
	private static $valid_statuses = array(
		'pending',
		'processing',
		'completed',
		'skipped',
		'failed',
		'partial',
		'rolled_back',
	);

	// ------------------------------------------------------------------
	// Ledger Record Retrieval
	// ------------------------------------------------------------------

	/**
	 * Get all ledger records for a session.
	 *
	 * @param int $session_id Exec session row ID.
	 * @return array Array of ledger record objects.
	 */
	public static function get_by_session( $session_id ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_id = %d ORDER BY source_record_id ASC",
				absint( $session_id )
			)
		);
	}

	/**
	 * Get ledger records for a session filtered by status.
	 *
	 * @param int    $session_id Exec session row ID.
	 * @param string $status     Status to filter by.
	 * @return array Array of ledger record objects.
	 */
	public static function get_by_session_and_status( $session_id, $status ) {
		global $wpdb;

		if ( ! in_array( $status, self::$valid_statuses, true ) ) {
			return array();
		}

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_id = %d AND status = %s ORDER BY source_record_id ASC",
				absint( $session_id ),
				$status
			)
		);
	}

	/**
	 * Get a single ledger record by session + source record ID.
	 *
	 * @param int $session_id       Exec session row ID.
	 * @param int $source_record_id PO10 ID.
	 * @return object|null Ledger record or null.
	 */
	public static function get_record( $session_id, $source_record_id ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_id = %d AND source_record_id = %d",
				absint( $session_id ),
				absint( $source_record_id )
			)
		);
	}

	/**
	 * Check whether a source record was already successfully migrated in any session.
	 *
	 * This is the cross-session idempotency check used by the revalidation engine.
	 * Returns the completed ledger entry if one exists, null otherwise.
	 *
	 * NOTE: Only 'completed' status records indicate an actual successful migration.
	 * Records with 'failed', 'rolled_back', or 'pending' do NOT block future sessions.
	 *
	 * @param int    $source_record_id PO10 ID.
	 * @param string $source_system    Source system identifier.
	 * @return object|null The completed ledger row, or null.
	 */
	public static function find_completed( $source_record_id, $source_system = 'powerof10' ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE source_record_id = %d
				   AND source_system = %s
				   AND status = 'completed'
				 ORDER BY id DESC
				 LIMIT 1",
				absint( $source_record_id ),
				sanitize_text_field( $source_system )
			)
		);
	}

	/**
	 * Count ledger records per status for a session.
	 *
	 * @param int $session_id Exec session row ID.
	 * @return array { status => count }.
	 */
	public static function count_by_status( $session_id ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS cnt FROM {$table} WHERE session_id = %d GROUP BY status",
				absint( $session_id )
			)
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->status ] = (int) $row->cnt;
		}

		return $counts;
	}

	// ------------------------------------------------------------------
	// Status Updates (Phase 24C-6C / 6D responsibility)
	// ------------------------------------------------------------------

	/**
	 * Update the status of a ledger record.
	 *
	 * This method is provided for completeness and future use by the
	 * execution engine. In Phase 24C-6B, no records progress beyond 'pending'.
	 *
	 * @param int    $session_id       Exec session row ID.
	 * @param int    $source_record_id PO10 ID.
	 * @param string $status           New status.
	 * @param array  $extra_fields     Optional extra fields to update.
	 * @return true|WP_Error True on success.
	 */
	public static function update_status( $session_id, $source_record_id, $status, array $extra_fields = array() ) {
		global $wpdb;

		if ( ! in_array( $status, self::$valid_statuses, true ) ) {
			return new \WP_Error(
				'invalid_status',
				sprintf(
					/* translators: %s: invalid status */
					__( 'Invalid ledger status "%s".', 'konx-affiliate-dashboard' ),
					$status
				)
			);
		}

		$table = $wpdb->prefix . self::TABLE;

		$data = array_merge(
			array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ),
			self::sanitize_extra_fields( $extra_fields )
		);

		if ( in_array( $status, array( 'completed', 'failed', 'partial', 'skipped' ), true ) ) {
			$data['completed_at'] = current_time( 'mysql', true );
		} elseif ( 'processing' === $status ) {
			$data['started_at']     = current_time( 'mysql', true );
			$data['attempt_count']  = null; // Will be incremented via separate query.
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$data,
			array(
				'session_id'       => absint( $session_id ),
				'source_record_id' => absint( $source_record_id ),
			),
			null,
			array( '%d', '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Failed to update ledger record status.', 'konx-affiliate-dashboard' ) );
		}

		// Increment attempt_count separately.
		if ( 'processing' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET attempt_count = attempt_count + 1
					 WHERE session_id = %d AND source_record_id = %d",
					absint( $session_id ),
					absint( $source_record_id )
				)
			);
		}

		return true;
	}

	/**
	 * Get valid execution statuses.
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
	 * Sanitize allowed extra fields for ledger updates.
	 *
	 * Only whitelisted fields may be set via extra_fields to prevent
	 * accidental overwrites of protected columns.
	 *
	 * @param array $extra_fields Raw extra field data.
	 * @return array Sanitized, whitelisted fields.
	 */
	private static function sanitize_extra_fields( array $extra_fields ) {
		$allowed = array(
			'wp_user_id'       => '%d',
			'wp_user_created'  => '%d',
			'affiliate_id'     => '%d',
			'affiliate_created' => '%d',
			'error_code'       => '%s',
			'error_message'    => '%s',
			'rollback_status'  => '%s',
			'rolled_back_at'   => '%s',
			'rolled_back_by'   => '%d',
		);

		$clean = array();
		foreach ( $allowed as $field => $type ) {
			if ( ! isset( $extra_fields[ $field ] ) ) {
				continue;
			}

			$val = $extra_fields[ $field ];

			if ( '%d' === $type ) {
				$clean[ $field ] = null !== $val ? absint( $val ) : null;
			} else {
				$str = sanitize_text_field( (string) $val );
				if ( 'error_message' === $field ) {
					$str = mb_substr( $str, 0, 500 );
				}
				$clean[ $field ] = '' === $str ? null : $str;
			}
		}

		return $clean;
	}
}
