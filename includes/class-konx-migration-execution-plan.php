<?php
/**
 * Execution plan snapshot — table interface and snapshot creation service.
 *
 * DUAL ROLE:
 *   1. Static CRUD interface for wp_konx_migration_execution_plan records.
 *   2. Snapshot creation service: converts the current Final Migration Plan
 *      into an immutable, frozen execution session + plan + ledger.
 *
 * WHY A FROZEN SNAPSHOT?
 *   The executor must run exactly what was reviewed and approved — not re-derive
 *   decisions from live data. The snapshot freezes:
 *     - The per-record action (create / link_wp / link_ca / invalid / skip)
 *     - The WP user ID that was matched (for link_wp / link_ca)
 *     - The Coupon Affiliate record ID (for link_ca)
 *     - The affiliate type and referral code (team_name)
 *     - A minimal sanitized payload for audit purposes
 *
 *   After the snapshot is frozen, the executor reads from this table only.
 *   It never re-runs email matching, never re-runs CA reconciliation, never
 *   re-interprets the source CSV.
 *
 * PHASE 24C-6B CONSTRAINT:
 *   create_snapshot() creates session + plan + ledger rows for foundation
 *   purposes only. The resulting session is NOT wired to any execution
 *   button. No WP users are created. No affiliates are created.
 *
 * @package KonxAffiliateDashboard
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Migration_Execution_Plan
 */
class Konx_Migration_Execution_Plan {

	/**
	 * Table name (without prefix).
	 */
	const TABLE = 'konx_migration_execution_plan';

	/**
	 * Source system identifier for PowerOf10 records.
	 */
	const SOURCE_SYSTEM = 'powerof10';

	/**
	 * Valid action values in the plan table.
	 *
	 * @var array
	 */
	private static $valid_actions = array(
		'create',
		'link_wp',
		'link_ca',
		'link_konx',
		'review',
		'invalid',
		'skip',
	);

	// ------------------------------------------------------------------
	// Snapshot Creation Service
	// ------------------------------------------------------------------

	/**
	 * Create an immutable execution snapshot from the current FMP.
	 *
	 * This is the primary entry point for Phase 24C-6B. It:
	 *   1. Validates the FMP decisions array and expected counts.
	 *   2. Computes the source hash and plan hash.
	 *   3. Creates an exec_session row (draft).
	 *   4. Inserts all FMP records into the plan snapshot table.
	 *   5. Inserts pending ledger rows for actionable records.
	 *   6. Freezes the session (transitions draft → frozen).
	 *   7. Returns the session UUID and summary.
	 *
	 * SAFETY: Does NOT create WP users. Does NOT create KonX affiliates.
	 * Does NOT trigger the executor. Does NOT modify production data.
	 * Only inserts rows into the three new migration foundation tables.
	 *
	 * @param array $decisions   FMP decisions array (from state['final_migration_plan']).
	 * @param array $options {
	 *     Optional parameters.
	 *
	 *     @type string $source_filename      Original CSV filename.
	 *     @type string $source_hash          Pre-computed SHA-256 of the source file.
	 *     @type string $validation_timestamp Datetime of last validation run.
	 *     @type string $dry_run_timestamp    Datetime of last dry run.
	 *     @type string $backup_reference     Backup ID reference.
	 *     @type int    $created_by           WordPress user ID.
	 * }
	 * @return array|WP_Error {
	 *     @type string $session_uuid       UUID of the created execution session.
	 *     @type int    $session_id         Row ID of the exec session.
	 *     @type string $final_plan_hash    SHA-256 of the FMP.
	 *     @type int    $plan_records       Count of plan records inserted.
	 *     @type int    $ledger_records     Count of ledger records inserted.
	 *     @type array  $decision_counts    Counts by action type.
	 * }
	 */
	public static function create_snapshot( array $decisions, array $options = array() ) {
		global $wpdb;

		// 1. Validate decisions.
		if ( empty( $decisions ) ) {
			return new \WP_Error(
				'no_decisions',
				__( 'Cannot create snapshot: Final Migration Plan has no decisions.', 'konx-affiliate-dashboard' )
			);
		}

		// 2. Compute counts and hashes.
		$decision_counts = Konx_Migration_Plan_Hasher::count_decisions( $decisions );
		$final_plan_hash = Konx_Migration_Plan_Hasher::hash_final_plan( $decisions );

		$source_hash = ! empty( $options['source_hash'] )
			? sanitize_text_field( $options['source_hash'] )
			: null;

		// 3. Create exec session (draft).
		$session_result = Konx_Migration_Exec_Session::create(
			array(
				'source_type'             => 'csv',
				'source_filename'         => $options['source_filename'] ?? null,
				'source_hash'             => $source_hash,
				'final_plan_hash'         => $final_plan_hash,
				'final_plan_record_count' => $decision_counts['total'],
				'decision_create_count'   => $decision_counts['create'],
				'decision_link_wp_count'  => $decision_counts['link_wp'],
				'decision_link_ca_count'  => $decision_counts['link_ca'],
				'decision_invalid_count'  => $decision_counts['invalid'],
				'decision_review_count'   => $decision_counts['review'],
				'created_by'              => absint( $options['created_by'] ?? 0 ) ?: null,
				'validation_timestamp'    => $options['validation_timestamp'] ?? null,
				'dry_run_timestamp'       => $options['dry_run_timestamp'] ?? null,
				'backup_reference'        => $options['backup_reference'] ?? null,
			)
		);

		if ( is_wp_error( $session_result ) ) {
			return $session_result;
		}

		$session_uuid = $session_result['session_uuid'];
		$session_id   = $session_result['id'];

		// 4. Insert plan snapshot records (batch insert for efficiency).
		$plan_table    = $wpdb->prefix . self::TABLE;
		$ledger_table  = $wpdb->prefix . Konx_Migration_Execution_Ledger::TABLE;
		$plan_records  = 0;
		$ledger_records = 0;

		// Actionable actions that get ledger rows.
		$actionable_actions = array( 'create', 'link_wp', 'link_ca', 'link_konx' );

		foreach ( $decisions as $d ) {
			$action = (string) ( $d['decision'] ?? '' );

			// Sanitize before snapshot storage. Do not store raw PII beyond what's needed.
			$source_email = strtolower( trim( sanitize_email( (string) ( $d['email'] ?? '' ) ) ) );
			$po10_id      = (int) ( $d['po10_id'] ?? 0 );
			$team_name    = sanitize_text_field( (string) ( $d['team_name'] ?? '' ) );
			$sponsor_tn   = sanitize_text_field( (string) ( $d['sponsor'] ?? '' ) );
			$aff_type     = sanitize_text_field( (string) ( $d['affiliate_type'] ?? 'sales_agent' ) );
			$wp_user_id   = isset( $d['wp_user_id'] ) && null !== $d['wp_user_id']
				? absint( $d['wp_user_id'] )
				: null;
			$ca_id        = isset( $d['ca_id'] ) && null !== $d['ca_id']
				? absint( $d['ca_id'] )
				: null;

			// Decision payload — the core decision fields, sanitized.
			// Intentionally excludes PII beyond email and excludes secrets.
			$decision_payload = wp_json_encode(
				array(
					'decision'      => $action,
					'match_method'  => (string) ( $d['match_method'] ?? '' ),
					'confidence'    => (string) ( $d['confidence'] ?? '' ),
					'sponsor_status' => (string) ( $d['sponsor_status'] ?? '' ),
					'val_status'    => (string) ( $d['val_status'] ?? '' ),
				)
			);

			// Source payload — minimal source data for audit traceability.
			// Excludes raw passwords, tokens, secrets. Includes only reference fields.
			$source_payload = wp_json_encode(
				array(
					'po10_id'    => $po10_id,
					'team_name'  => $team_name,
					'first_name' => sanitize_text_field( (string) ( $d['first_name'] ?? '' ) ),
					'last_name'  => sanitize_text_field( (string) ( $d['last_name'] ?? '' ) ),
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert(
				$plan_table,
				array(
					'session_id'           => $session_id,
					'source_system'        => self::SOURCE_SYSTEM,
					'source_record_id'     => $po10_id,
					'source_email'         => $source_email,
					'action'               => $action,
					'wp_user_id'           => $wp_user_id,
					'coupon_affiliate_id'  => $ca_id,
					'affiliate_type'       => $aff_type,
					'team_name'            => $team_name ?: null,
					'sponsor_team_name'    => $sponsor_tn ?: null,
					'source_payload'       => $source_payload,
					'decision_payload'     => $decision_payload,
					'created_at'           => current_time( 'mysql', true ),
				),
				array( '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false === $inserted ) {
				// Roll back session and any partial plan records on insert failure.
				self::cleanup_failed_snapshot( $session_uuid, $session_id );
				return new \WP_Error(
					'plan_insert_failed',
					sprintf(
						/* translators: %d: PO10 record ID */
						__( 'Failed to insert plan record for PO10 ID %d.', 'konx-affiliate-dashboard' ),
						$po10_id
					)
				);
			}

			$plan_record_id = (int) $wpdb->insert_id;
			$plan_records++;

			// 5. Insert pending ledger rows for actionable records only.
			if ( in_array( $action, $actionable_actions, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$ledger_inserted = $wpdb->insert(
					$ledger_table,
					array(
						'session_id'       => $session_id,
						'plan_id'          => $plan_record_id,
						'source_system'    => self::SOURCE_SYSTEM,
						'source_record_id' => $po10_id,
						'approved_action'  => $action,
						'status'           => 'pending',
						'attempt_count'    => 0,
						'created_at'       => current_time( 'mysql', true ),
					),
					array( '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s' )
				);

				if ( false === $ledger_inserted ) {
					// A missing ledger row for an actionable record is fatal: the executor
					// needs both the plan row and the ledger row to operate correctly.
					self::cleanup_failed_snapshot( $session_uuid, $session_id );
					return new \WP_Error(
						'ledger_insert_failed',
						sprintf(
							/* translators: %d: PO10 record ID */
							__( 'Failed to insert ledger record for PO10 ID %d.', 'konx-affiliate-dashboard' ),
							$po10_id
						)
					);
				}

				$ledger_records++;
			}
		}

		// 6. Freeze the session (draft → frozen).
		$freeze_result = Konx_Migration_Exec_Session::freeze( $session_uuid );
		if ( is_wp_error( $freeze_result ) ) {
			// A successful snapshot MUST be frozen. If freeze fails, clean up and
			// surface the error so the caller knows the snapshot was not committed.
			self::cleanup_failed_snapshot( $session_uuid, $session_id );
			return new \WP_Error(
				'snapshot_freeze_failed',
				sprintf(
					/* translators: %s: upstream error message */
					__( 'Snapshot creation failed: could not freeze session. %s', 'konx-affiliate-dashboard' ),
					$freeze_result->get_error_message()
				)
			);
		}

		return array(
			'session_uuid'    => $session_uuid,
			'session_id'      => $session_id,
			'final_plan_hash' => $final_plan_hash,
			'plan_records'    => $plan_records,
			'ledger_records'  => $ledger_records,
			'decision_counts' => $decision_counts,
		);
	}

	// ------------------------------------------------------------------
	// Plan Record CRUD
	// ------------------------------------------------------------------

	/**
	 * Get all plan records for a session.
	 *
	 * @param int $session_id Exec session row ID.
	 * @return array Array of plan record objects.
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
	 * Get plan records for a session filtered by action.
	 *
	 * @param int    $session_id Exec session row ID.
	 * @param string $action     Action type (create, link_wp, etc.).
	 * @return array Array of plan record objects.
	 */
	public static function get_by_session_and_action( $session_id, $action ) {
		global $wpdb;

		if ( ! in_array( $action, self::$valid_actions, true ) ) {
			return array();
		}

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_id = %d AND action = %s ORDER BY source_record_id ASC",
				absint( $session_id ),
				$action
			)
		);
	}

	/**
	 * Get a single plan record by session + source record ID.
	 *
	 * @param int $session_id       Exec session row ID.
	 * @param int $source_record_id PO10 ID.
	 * @return object|null Plan record or null.
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
	 * Count plan records per action for a session.
	 *
	 * @param int $session_id Exec session row ID.
	 * @return array { action => count }.
	 */
	public static function count_by_action( $session_id ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action, COUNT(*) AS cnt FROM {$table} WHERE session_id = %d GROUP BY action",
				absint( $session_id )
			)
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->action ] = (int) $row->cnt;
		}

		return $counts;
	}

	// ------------------------------------------------------------------
	// Cleanup
	// ------------------------------------------------------------------

	/**
	 * Clean up a failed snapshot by removing plan + ledger rows and
	 * invalidating the session. Used only on snapshot creation failure.
	 *
	 * @param string $session_uuid UUID of the failed session.
	 * @param int    $session_id   Row ID of the failed session.
	 */
	private static function cleanup_failed_snapshot( $session_uuid, $session_id ) {
		global $wpdb;

		// Remove any plan records already inserted.
		$plan_table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $plan_table, array( 'session_id' => $session_id ), array( '%d' ) );

		// Remove any ledger records already inserted.
		$ledger_table = $wpdb->prefix . Konx_Migration_Execution_Ledger::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $ledger_table, array( 'session_id' => $session_id ), array( '%d' ) );

		// Invalidate the session.
		Konx_Migration_Exec_Session::invalidate( $session_uuid );
	}

	/**
	 * Get valid action values.
	 *
	 * @return array
	 */
	public static function get_valid_actions() {
		return self::$valid_actions;
	}
}
