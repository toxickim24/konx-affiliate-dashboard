<?php
/**
 * Execution revalidation engine.
 *
 * Verifies that a frozen execution snapshot is still valid against live
 * WordPress state before execution approval is granted.
 *
 * ARCHITECTURAL CONSTRAINT:
 *   This class is strictly read-only with respect to business data.
 *   It MUST NOT create, modify, or delete WordPress users, KonX affiliates,
 *   Coupon Affiliate records, or any other production data. The only writes
 *   it performs are to the exec session's four revalidation metadata columns
 *   (last_revalidated_at, revalidation_status, stale_count, conflict_count).
 *
 * DESIGN PRINCIPLE:
 *   The revalidator verifies the frozen decision — it does NOT reinterpret it.
 *   It must not redo email matching, make new matching decisions, change
 *   approved action types, independently discover a different WP user, or
 *   silently replace CA mappings. It checks whether the assumptions baked
 *   into the frozen snapshot still hold against live state.
 *
 * FAILURE CLASSIFICATION:
 *
 *   session_critical — Session-level integrity failure. Revalidation aborts
 *                      after session checks. No per-record checks are run.
 *                      Causes: session not found, not frozen, hash mismatch,
 *                      count mismatch, schema downgrade, cross-session collision.
 *
 *   stale            — A resource the snapshot EXPECTED TO EXIST is now gone.
 *                      Examples: WP user deleted, CA row deleted, email that
 *                      was supposed to be fresh now maps to a WP user.
 *                      Execution would produce a different outcome — review required.
 *
 *   conflict         — A resource the snapshot expected to be ABSENT now exists.
 *                      Examples: user gained a KonX affiliate, referral code taken,
 *                      CA userid changed.
 *                      Execution would FAIL — execution must be blocked.
 *
 * SESSION-LEVEL CHECKS (9 total):
 *   1. session_exists       — session UUID found in DB
 *   2. session_frozen       — session.status === 'frozen'
 *   3. plugin_version       — current plugin version (warn if changed; non-critical)
 *   4. schema_version       — current DB schema >= snapshot schema (critical if <)
 *   5. plan_hash_integrity  — recomputed hash from plan records matches stored hash
 *   6. record_count         — plan record count matches session.final_plan_record_count
 *   7. decision_counts      — per-action counts match session.decision_*_count fields
 *   8. no_review_records    — no plan records with action='review'
 *   9. cross_session_idempotency — no actionable records completed in another session
 *
 * PER-RECORD CHECKS (by action type):
 *   create   — email not in wp_users (or if it is, no affiliate exists); code available
 *   link_wp  — WP user still exists; still has no KonX affiliate
 *   link_ca  — CA row exists; userid matches; WP user exists; no KonX affiliate
 *   link_konx — deferred (no records in current dataset; pass through with note)
 *   invalid / skip / review — non-actionable; pass through without live-state checks
 *
 * @package KonxAffiliateDashboard
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Migration_Revalidator
 */
class Konx_Migration_Revalidator {

	/**
	 * Coupon Affiliates table name (without WP prefix).
	 */
	const CA_TABLE = 'wcusage_register';

	/**
	 * KonX affiliates table name (without WP prefix).
	 */
	const KONX_AFF_TABLE = 'konx_affiliates';

	/**
	 * Revalidation status: all checks passed.
	 */
	const STATUS_PASS = 'pass';

	/**
	 * Revalidation status: one or more records are stale.
	 * Execution would produce different outcome than the frozen plan.
	 */
	const STATUS_STALE = 'stale';

	/**
	 * Revalidation status: one or more records have live-state conflicts.
	 * Execution would fail for these records.
	 */
	const STATUS_CONFLICT = 'conflict';

	/**
	 * Revalidation status: session-level integrity failure.
	 * Per-record checks were not run.
	 */
	const STATUS_CRITICAL = 'critical';

	// ------------------------------------------------------------------
	// Public Entry Point
	// ------------------------------------------------------------------

	/**
	 * Run full revalidation for a frozen execution session.
	 *
	 * Performs session-level integrity checks, then per-record live-state
	 * checks against WordPress and Coupon Affiliate data. Persists the
	 * result to the session row's four revalidation columns.
	 *
	 * SAFETY: This method is read-only with respect to all business data.
	 * No users, affiliates, or CA records are created or modified.
	 *
	 * @param string $session_uuid UUID of the frozen session to revalidate.
	 * @return array {
	 *     @type string $session_uuid        UUID of the revalidated session.
	 *     @type bool   $session_valid        True if all session-level checks pass.
	 *     @type array  $session_checks       Array of session check results.
	 *     @type int    $records_checked      Total plan records examined.
	 *     @type int    $records_pass         Records that passed all live-state checks.
	 *     @type int    $records_stale        Records with a stale state.
	 *     @type int    $records_conflict     Records with a live conflict.
	 *     @type array  $issues               Per-record issue details (stale + conflict).
	 *     @type string $revalidation_status  Overall: 'pass', 'stale', 'conflict', 'critical'.
	 *     @type string $revalidated_at       Datetime of this revalidation run.
	 * }
	 */
	public static function revalidate( $session_uuid ) {
		$session_uuid = sanitize_text_field( (string) $session_uuid );

		// 1. Load session.
		$session = Konx_Migration_Exec_Session::get( $session_uuid );

		// 2. Run 9 session-level checks.
		$session_checks = self::run_session_checks( $session, $session_uuid );
		$session_valid  = self::all_session_checks_pass( $session_checks );

		if ( ! $session_valid ) {
			$result = array(
				'session_uuid'        => $session_uuid,
				'session_valid'       => false,
				'session_checks'      => $session_checks,
				'records_checked'     => 0,
				'records_pass'        => 0,
				'records_stale'       => 0,
				'records_conflict'    => 0,
				'issues'              => array(),
				'revalidation_status' => self::STATUS_CRITICAL,
				'revalidated_at'      => current_time( 'mysql', true ),
			);

			if ( $session ) {
				Konx_Migration_Exec_Session::persist_revalidation_result( $session_uuid, $result );
			}

			return $result;
		}

		// 3. Load all plan records for this session.
		$plan_records = Konx_Migration_Execution_Plan::get_by_session( (int) $session->id );

		// 4. Run per-record live-state checks.
		$records_pass     = 0;
		$records_stale    = 0;
		$records_conflict = 0;
		$issues           = array();

		foreach ( $plan_records as $record ) {
			$check = self::check_record( $record );

			if ( self::STATUS_PASS === $check['status'] ) {
				$records_pass++;
			} elseif ( self::STATUS_STALE === $check['status'] ) {
				$records_stale++;
				$issues[] = $check;
			} else {
				// conflict or unexpected status.
				$records_conflict++;
				$issues[] = $check;
			}
		}

		// 5. Derive overall revalidation status (conflict > stale > pass).
		if ( $records_conflict > 0 ) {
			$overall_status = self::STATUS_CONFLICT;
		} elseif ( $records_stale > 0 ) {
			$overall_status = self::STATUS_STALE;
		} else {
			$overall_status = self::STATUS_PASS;
		}

		$result = array(
			'session_uuid'        => $session_uuid,
			'session_valid'       => true,
			'session_checks'      => $session_checks,
			'records_checked'     => count( $plan_records ),
			'records_pass'        => $records_pass,
			'records_stale'       => $records_stale,
			'records_conflict'    => $records_conflict,
			'issues'              => $issues,
			'revalidation_status' => $overall_status,
			'revalidated_at'      => current_time( 'mysql', true ),
		);

		// 6. Persist result to session metadata.
		Konx_Migration_Exec_Session::persist_revalidation_result( $session_uuid, $result );

		return $result;
	}

	// ------------------------------------------------------------------
	// Session-Level Checks
	// ------------------------------------------------------------------

	/**
	 * Run all 9 session-level integrity checks.
	 *
	 * @param object|null $session      Session row from DB (or null if not found).
	 * @param string      $session_uuid UUID of the session.
	 * @return array Array of check result arrays.
	 */
	private static function run_session_checks( $session, $session_uuid ) {
		$checks = array();

		// Check 1: Session exists.
		$checks[] = self::check_session_exists( $session );
		if ( ! $session ) {
			return $checks; // Cannot proceed — no session object.
		}

		// Check 2: Session is frozen.
		$checks[] = self::check_session_frozen( $session );

		// Check 3: Plugin version (non-critical — surfaces for audit).
		$checks[] = self::check_plugin_version( $session );

		// Check 4: Schema version (critical if current < snapshot).
		$checks[] = self::check_schema_version( $session );

		// Checks 5-7 need the plan records.
		$plan_records = Konx_Migration_Execution_Plan::get_by_session( (int) $session->id );

		// Check 5: Plan hash integrity — recompute from DB records.
		$checks[] = self::check_plan_hash( $session, $plan_records );

		// Check 6: Record count.
		$checks[] = self::check_record_count( $session, $plan_records );

		// Check 7: Decision counts by action type.
		$checks[] = self::check_decision_counts( $session, $plan_records );

		// Check 8: No review records (execution gate).
		$checks[] = self::check_no_review_records( $plan_records );

		// Check 9: Cross-session idempotency.
		$checks[] = self::check_cross_session_idempotency( $plan_records );

		return $checks;
	}

	/**
	 * Check 1: Session exists.
	 *
	 * @param object|null $session Session row or null.
	 * @return array Check result.
	 */
	private static function check_session_exists( $session ) {
		$pass = null !== $session;

		return array(
			'id'       => 'session_exists',
			'status'   => $pass ? self::STATUS_PASS : self::STATUS_CRITICAL,
			'critical' => true,
			'message'  => $pass
				? __( 'Session record found in database.', 'konx-affiliate-dashboard' )
				: __( 'Execution session not found. The UUID may be invalid or the record was deleted.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Check 2: Session is frozen.
	 *
	 * Only frozen sessions may be revalidated (and subsequently approved).
	 * Draft sessions are incomplete; running/completed sessions are immutable
	 * for a different reason.
	 *
	 * @param object $session Session row.
	 * @return array Check result.
	 */
	private static function check_session_frozen( $session ) {
		$pass = 'frozen' === $session->status;

		return array(
			'id'       => 'session_frozen',
			'status'   => $pass ? self::STATUS_PASS : self::STATUS_CRITICAL,
			'critical' => true,
			'message'  => $pass
				? __( 'Session is frozen (immutable snapshot committed).', 'konx-affiliate-dashboard' )
				: sprintf(
					/* translators: %s: current session status */
					__( 'Session status is "%s". Only frozen sessions can be revalidated.', 'konx-affiliate-dashboard' ),
					$session->status
				),
		);
	}

	/**
	 * Check 3: Plugin version.
	 *
	 * The executing plugin version must exactly match the version recorded
	 * in the frozen snapshot. Any version change — upgrade or downgrade —
	 * is treated as a critical failure because behaviour changes in the
	 * executor code may invalidate the assumptions baked into the snapshot.
	 *
	 * Version match  → status='pass',     critical=false
	 * Version differ → status='critical', critical=true
	 *
	 * This causes all_session_checks_pass() to return false on mismatch,
	 * blocking per-record checks and execution approval.
	 *
	 * Do NOT automatically update the frozen version to the current version.
	 *
	 * @param object $session Session row.
	 * @return array Check result.
	 */
	private static function check_plugin_version( $session ) {
		$snapshot_ver = (string) $session->plugin_version;
		$current_ver  = KONX_AFFILIATE_VERSION;
		$match        = version_compare( $snapshot_ver, $current_ver, '==' );

		return array(
			'id'       => 'plugin_version',
			'status'   => $match ? self::STATUS_PASS : self::STATUS_CRITICAL,
			'critical' => ! $match,
			'message'  => $match
				? sprintf(
					/* translators: %s: version string */
					__( 'Plugin version matches snapshot: %s.', 'konx-affiliate-dashboard' ),
					$current_ver
				)
				: sprintf(
					/* translators: 1: snapshot version, 2: current version */
					__( 'Plugin version MISMATCH. Snapshot=%1$s, Current=%2$s. Re-create snapshot with the current plugin version before approving execution.', 'konx-affiliate-dashboard' ),
					$snapshot_ver,
					$current_ver
				),
		);
	}

	/**
	 * Check 4: Database schema version.
	 *
	 * The current DB schema must be at least as new as when the snapshot
	 * was created. An upgrade after snapshot creation (current > snapshot)
	 * is safe. A downgrade (current < snapshot) means the environment
	 * changed in a way that may break execution.
	 *
	 * @param object $session Session row.
	 * @return array Check result.
	 */
	private static function check_schema_version( $session ) {
		$snapshot_schema = (string) $session->database_schema_version;
		$current_schema  = KONX_AFFILIATE_DB_VERSION;
		$ok              = version_compare( $current_schema, $snapshot_schema, '>=' );

		return array(
			'id'       => 'schema_version',
			'status'   => $ok ? self::STATUS_PASS : self::STATUS_CRITICAL,
			'critical' => ! $ok,
			'message'  => $ok
				? sprintf(
					/* translators: 1: snapshot schema version, 2: current schema version */
					__( 'DB schema compatible. Snapshot=%1$s, Current=%2$s.', 'konx-affiliate-dashboard' ),
					$snapshot_schema,
					$current_schema
				)
				: sprintf(
					/* translators: 1: snapshot schema version, 2: current schema version */
					__( 'DB schema downgrade detected. Snapshot=%1$s, Current=%2$s. Cannot execute — re-activate plugin to upgrade schema.', 'konx-affiliate-dashboard' ),
					$snapshot_schema,
					$current_schema
				),
		);
	}

	/**
	 * Check 5: Plan hash integrity.
	 *
	 * Two-part check:
	 *
	 * PART A — Internal snapshot integrity:
	 *   Recomputes the canonical SHA-256 hash directly from the frozen plan
	 *   records in the database and compares it against the hash stored in
	 *   the session row. A mismatch indicates that the plan records were
	 *   modified after the snapshot was frozen.
	 *
	 * PART B — FMP parity (live FMP vs frozen snapshot):
	 *   Loads the live Final Migration Plan from wp_options (key:
	 *   konx_migration_state, field: final_migration_plan.decisions) and
	 *   recomputes its hash. Compares to the stored plan_hash.
	 *
	 *   The canonical source is final_migration_plan.decisions — the
	 *   post-validation artifact produced by build_final_migration_plan().
	 *   decision_matrix.decisions is a pre-validation intermediate that is
	 *   NOT equivalent: validation errors can promote 'review' entries to
	 *   'invalid', changing both the composition and the hash. The two
	 *   structures may legitimately differ; only the FMP is authoritative.
	 *
	 *   A mismatch means the FMP was edited after the snapshot was taken.
	 *   Because there is NO application-level write guard preventing FMP
	 *   saves while a frozen session exists (the session immutability only
	 *   protects the session row itself, not the wp_options FMP), this
	 *   check is the primary safeguard against FMP drift.
	 *
	 * LIMITATION: The source_hash (CSV file hash) stored on the session
	 *   reflects the state of the source file at snapshot time. If the
	 *   source CSV is replaced with a different file, the source_hash will
	 *   not match, but this revalidator does NOT check source_hash because
	 *   the plan records themselves are the canonical source of truth.
	 *   The source_hash is informational only.
	 *
	 * @param object $session      Session row.
	 * @param array  $plan_records Plan records from get_by_session().
	 * @return array Check result.
	 */
	private static function check_plan_hash( $session, $plan_records ) {
		$stored_hash   = (string) $session->final_plan_hash;
		$computed_hash = Konx_Migration_Plan_Hasher::hash_from_plan_records( $plan_records );
		$internal_match = hash_equals( $stored_hash, $computed_hash );

		// Part A: Internal snapshot integrity check.
		if ( ! $internal_match ) {
			return array(
				'id'       => 'plan_hash_integrity',
				'status'   => self::STATUS_CRITICAL,
				'critical' => true,
				'message'  => sprintf(
					/* translators: 1: stored hash, 2: recomputed hash */
					__( 'Plan hash MISMATCH. Stored=%1$s Computed=%2$s. Snapshot records may have been tampered with.', 'konx-affiliate-dashboard' ),
					$stored_hash,
					$computed_hash
				),
			);
		}

		// Part B: FMP parity check — compare live FMP against frozen snapshot hash.
		//
		// FAIL-CLOSED: any state where the live FMP cannot be read or verified is
		// treated as a CRITICAL failure. An absent or malformed FMP is indistinguishable
		// from a tampered or deleted FMP — both must block execution.
		//
		// NOTE: There is no application-level write guard preventing the FMP from
		// being modified (via the wizard/AJAX) while a frozen session exists.
		// The session's own immutability contract protects only the session row and
		// its plan records. The live FMP in wp_options can still be overwritten.
		// This check is therefore the primary safeguard against FMP drift.

		// 1. get_option() must be available (WordPress or test stub).
		if ( ! function_exists( 'get_option' ) ) {
			return array(
				'id'       => 'plan_hash_integrity',
				'status'   => self::STATUS_CRITICAL,
				'critical' => true,
				'type'     => 'fmp_unverifiable',
				'message'  => __( 'Cannot verify FMP parity: get_option() is not available in this environment.', 'konx-affiliate-dashboard' ),
			);
		}

		// 2. konx_migration_state option must exist and be an array.
		// Use a sentinel default so we can distinguish a missing option from
		// an option that explicitly stored an empty array.
		$migration_state = get_option( 'konx_migration_state', '__MISSING__' );

		if ( '__MISSING__' === $migration_state || ! is_array( $migration_state ) ) {
			return array(
				'id'       => 'plan_hash_integrity',
				'status'   => self::STATUS_CRITICAL,
				'critical' => true,
				'type'     => 'fmp_unverifiable',
				'message'  => __( 'Cannot verify FMP parity: konx_migration_state option is missing or invalid. Re-create the snapshot after loading the migration plan.', 'konx-affiliate-dashboard' ),
			);
		}

		// 3. final_migration_plan key must exist and be an array.
		//
		// Do NOT fall back to decision_matrix.decisions. decision_matrix is a
		// pre-validation intermediate artifact; final_migration_plan is the
		// post-validation canonical FMP. They differ whenever validation errors
		// override preliminary decisions (e.g. 'review' → 'invalid'). Hashing
		// the wrong artifact produces a permanent, silent hash mismatch.
		if ( empty( $migration_state['final_migration_plan'] ) || ! is_array( $migration_state['final_migration_plan'] ) ) {
			return array(
				'id'       => 'plan_hash_integrity',
				'status'   => self::STATUS_CRITICAL,
				'critical' => true,
				'type'     => 'fmp_unverifiable',
				'message'  => __( 'Cannot verify FMP parity: final_migration_plan is missing from konx_migration_state. Complete the migration wizard through the Final Migration Plan step before creating a snapshot.', 'konx-affiliate-dashboard' ),
			);
		}

		// 4. decisions array must exist and be a non-empty array.
		$live_decisions = $migration_state['final_migration_plan']['decisions'] ?? null;

		if ( ! is_array( $live_decisions ) || empty( $live_decisions ) ) {
			return array(
				'id'       => 'plan_hash_integrity',
				'status'   => self::STATUS_CRITICAL,
				'critical' => true,
				'type'     => 'fmp_unverifiable',
				'message'  => __( 'Cannot verify FMP parity: decisions array is missing or empty in konx_migration_state.final_migration_plan.', 'konx-affiliate-dashboard' ),
			);
		}

		// 5. Hash the live decisions and compare against the frozen plan hash.
		$live_fmp_hash = Konx_Migration_Plan_Hasher::hash_final_plan( $live_decisions );
		$fmp_match     = hash_equals( $stored_hash, $live_fmp_hash );

		if ( ! $fmp_match ) {
			return array(
				'id'       => 'plan_hash_integrity',
				'status'   => self::STATUS_CRITICAL,
				'critical' => true,
				'type'     => 'fmp_modified_post_snapshot',
				'message'  => sprintf(
					/* translators: 1: stored (frozen) hash, 2: live FMP hash */
					__( 'FMP PARITY MISMATCH. The live Final Migration Plan was modified after the snapshot was frozen. Frozen hash=%1$s Live FMP hash=%2$s. Re-create the snapshot to proceed.', 'konx-affiliate-dashboard' ),
					$stored_hash,
					$live_fmp_hash
				),
			);
		}

		return array(
			'id'       => 'plan_hash_integrity',
			'status'   => self::STATUS_PASS,
			'critical' => false,
			'message'  => sprintf(
				/* translators: %s: SHA-256 hash */
				__( 'Plan hash verified: %s', 'konx-affiliate-dashboard' ),
				$stored_hash
			),
		);
	}

	/**
	 * Check 6: Plan record count.
	 *
	 * @param object $session      Session row.
	 * @param array  $plan_records Plan records from get_by_session().
	 * @return array Check result.
	 */
	private static function check_record_count( $session, $plan_records ) {
		$expected = (int) $session->final_plan_record_count;
		$actual   = count( $plan_records );
		$match    = $expected === $actual;

		return array(
			'id'       => 'record_count',
			'status'   => $match ? self::STATUS_PASS : self::STATUS_CRITICAL,
			'critical' => true,
			'message'  => $match
				? sprintf(
					/* translators: %d: record count */
					__( 'Record count verified: %d records.', 'konx-affiliate-dashboard' ),
					$actual
				)
				: sprintf(
					/* translators: 1: expected count, 2: actual count */
					__( 'Record count MISMATCH. Expected=%1$d Actual=%2$d.', 'konx-affiliate-dashboard' ),
					$expected,
					$actual
				),
		);
	}

	/**
	 * Check 7: Decision counts by action type.
	 *
	 * Compares live action-type counts from plan records against the
	 * stored decision_*_count fields in the session row.
	 *
	 * @param object $session      Session row.
	 * @param array  $plan_records Plan records from get_by_session().
	 * @return array Check result.
	 */
	private static function check_decision_counts( $session, $plan_records ) {
		$live_counts = array(
			'create'  => 0,
			'link_wp' => 0,
			'link_ca' => 0,
			'review'  => 0,
			'invalid' => 0,
			'skip'    => 0,
		);

		foreach ( $plan_records as $r ) {
			$action = (string) $r->action;
			if ( array_key_exists( $action, $live_counts ) ) {
				$live_counts[ $action ]++;
			}
		}

		// Session columns that track each action type.
		$field_map = array(
			'create'  => 'decision_create_count',
			'link_wp' => 'decision_link_wp_count',
			'link_ca' => 'decision_link_ca_count',
			'review'  => 'decision_review_count',
			'invalid' => 'decision_invalid_count',
		);

		$mismatches = array();
		foreach ( $field_map as $action => $field ) {
			$expected = (int) $session->$field;
			$actual   = $live_counts[ $action ];
			if ( $expected !== $actual ) {
				$mismatches[] = sprintf( '%s: expected=%d actual=%d', $action, $expected, $actual );
			}
		}

		$pass = empty( $mismatches );

		return array(
			'id'       => 'decision_counts',
			'status'   => $pass ? self::STATUS_PASS : self::STATUS_CRITICAL,
			'critical' => true,
			'message'  => $pass
				? sprintf(
					/* translators: 1: create count, 2: link_wp count, 3: link_ca count, 4: invalid count */
					__( 'All decision counts verified. create=%1$d link_wp=%2$d link_ca=%3$d invalid=%4$d', 'konx-affiliate-dashboard' ),
					$live_counts['create'],
					$live_counts['link_wp'],
					$live_counts['link_ca'],
					$live_counts['invalid']
				)
				: sprintf(
					/* translators: %s: comma-separated list of mismatches */
					__( 'Decision count MISMATCH: %s', 'konx-affiliate-dashboard' ),
					implode( '; ', $mismatches )
				),
		);
	}

	/**
	 * Check 8: No review records.
	 *
	 * Records with action='review' require human review before execution.
	 * If any remain in the snapshot, execution must be blocked.
	 *
	 * @param array $plan_records Plan records from get_by_session().
	 * @return array Check result.
	 */
	private static function check_no_review_records( $plan_records ) {
		$review_count = 0;
		foreach ( $plan_records as $r ) {
			if ( 'review' === (string) $r->action ) {
				$review_count++;
			}
		}

		$pass = 0 === $review_count;

		return array(
			'id'       => 'no_review_records',
			'status'   => $pass ? self::STATUS_PASS : self::STATUS_CRITICAL,
			'critical' => true,
			'message'  => $pass
				? __( 'No review records present. Execution gate clear.', 'konx-affiliate-dashboard' )
				: sprintf(
					/* translators: %d: number of review records */
					__( '%d record(s) require human review. Execution blocked until all review records are resolved.', 'konx-affiliate-dashboard' ),
					$review_count
				),
		);
	}

	/**
	 * Check 9: Cross-session idempotency.
	 *
	 * Queries the execution ledger across ALL sessions to detect whether
	 * any actionable record was already successfully completed in a previous
	 * session. A 'completed' entry means the record was already migrated.
	 *
	 * Only 'completed' status counts. 'failed', 'pending', 'rolled_back'
	 * records do NOT block re-execution (a prior failure is safe to retry).
	 *
	 * @param array $plan_records Plan records from get_by_session().
	 * @return array Check result.
	 */
	private static function check_cross_session_idempotency( $plan_records ) {
		$actionable_actions = array( 'create', 'link_wp', 'link_ca', 'link_konx' );
		$already_completed  = array();

		foreach ( $plan_records as $r ) {
			if ( ! in_array( (string) $r->action, $actionable_actions, true ) ) {
				continue;
			}

			$completed = Konx_Migration_Execution_Ledger::find_completed(
				(int) $r->source_record_id,
				(string) $r->source_system
			);

			if ( $completed ) {
				$already_completed[] = sprintf(
					'PO10#%d (session_id=%d ledger_id=%d)',
					$r->source_record_id,
					$completed->session_id,
					$completed->id
				);
			}
		}

		$pass  = empty( $already_completed );
		$count = count( $already_completed );

		return array(
			'id'       => 'cross_session_idempotency',
			'status'   => $pass ? self::STATUS_PASS : self::STATUS_CRITICAL,
			'critical' => true,
			'message'  => $pass
				? __( 'No records previously completed in any other session.', 'konx-affiliate-dashboard' )
				: sprintf(
					/* translators: 1: count, 2: truncated list of IDs */
					__( '%1$d record(s) already completed in a prior session: %2$s', 'konx-affiliate-dashboard' ),
					$count,
					implode( ', ', array_slice( $already_completed, 0, 5 ) )
						. ( $count > 5 ? sprintf( ' (and %d more)', $count - 5 ) : '' )
				),
		);
	}

	/**
	 * Determine whether all critical session checks passed.
	 *
	 * Non-critical checks (e.g. plugin_version 'warn') do not block
	 * revalidation. Only checks with critical=true and status=critical
	 * cause this to return false.
	 *
	 * @param array $checks Array of session check results.
	 * @return bool True if all critical checks passed.
	 */
	private static function all_session_checks_pass( array $checks ) {
		foreach ( $checks as $check ) {
			if ( ! empty( $check['critical'] ) && self::STATUS_CRITICAL === $check['status'] ) {
				return false;
			}
		}
		return true;
	}

	// ------------------------------------------------------------------
	// Per-Record Checks
	// ------------------------------------------------------------------

	/**
	 * Check a single plan record against live WordPress state.
	 *
	 * Dispatches to the appropriate action-specific check method.
	 * Non-actionable records (invalid, skip, review) pass through
	 * without any database queries.
	 *
	 * @param object $record Plan record from wp_konx_migration_execution_plan.
	 * @return array {
	 *     @type int         $po10_id  PO10 source record ID.
	 *     @type string      $action   Frozen action type.
	 *     @type string      $status   'pass', 'stale', or 'conflict'.
	 *     @type string|null $type     Issue type identifier (null if pass).
	 *     @type string      $message  Human-readable result message.
	 * }
	 */
	private static function check_record( $record ) {
		$action  = (string) $record->action;
		$po10_id = (int) $record->source_record_id;

		switch ( $action ) {
			case 'create':
				return self::check_create( $record );

			case 'link_wp':
				return self::check_link_wp( $record );

			case 'link_ca':
				return self::check_link_ca( $record );

			case 'link_konx':
				// Semantics of link_konx are deferred for a future phase.
				// No records of this type exist in the current dataset.
				return array(
					'po10_id' => $po10_id,
					'action'  => $action,
					'status'  => self::STATUS_PASS,
					'type'    => null,
					'message' => __( 'link_konx: live-state checks deferred (action semantics not yet fully specified).', 'konx-affiliate-dashboard' ),
				);

			case 'invalid':
			case 'skip':
			case 'review':
				return array(
					'po10_id' => $po10_id,
					'action'  => $action,
					'status'  => self::STATUS_PASS,
					'type'    => null,
					'message' => sprintf(
						/* translators: %s: action type (invalid/skip/review) */
						__( 'Action "%s" is non-actionable — no live-state check required.', 'konx-affiliate-dashboard' ),
						$action
					),
				);

			default:
				return array(
					'po10_id' => $po10_id,
					'action'  => $action,
					'status'  => self::STATUS_CONFLICT,
					'type'    => 'unknown_action',
					'message' => sprintf(
						/* translators: %s: unknown action string */
						__( 'Unknown action "%s" found in plan record. Snapshot may be corrupt.', 'konx-affiliate-dashboard' ),
						$action
					),
				);
		}
	}

	/**
	 * Revalidate a 'create' record.
	 *
	 * Frozen assumption: no WP user with this email existed at snapshot time.
	 * Live checks:
	 *   A. Email not in wp_users — stale if a user appeared after snapshot.
	 *   B. If email IS in wp_users — conflict if that user has a KonX affiliate.
	 *   C. Referral code (team_name) not already taken by a KonX affiliate — conflict.
	 *
	 * @param object $record Plan record.
	 * @return array Check result.
	 */
	private static function check_create( $record ) {
		global $wpdb;

		$po10_id    = (int) $record->source_record_id;
		$email      = strtolower( trim( (string) $record->source_email ) );
		$team_name  = trim( (string) ( $record->team_name ?? '' ) );
		$konx_table = $wpdb->prefix . self::KONX_AFF_TABLE;

		// Sub-check A: Is the email now in wp_users?
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_uid = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE LOWER(user_email) = %s LIMIT 1",
				$email
			)
		);

		if ( $existing_uid > 0 ) {
			// Sub-check B: Does that user already have a KonX affiliate?
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$aff_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$konx_table} WHERE user_id = %d LIMIT 1",
					$existing_uid
				)
			);

			if ( $aff_id ) {
				// Conflict: execution would fail — cannot create affiliate for a user
				// who already has one.
				return array(
					'po10_id' => $po10_id,
					'action'  => 'create',
					'status'  => self::STATUS_CONFLICT,
					'type'    => 'email_has_affiliate',
					'message' => sprintf(
						/* translators: 1: email, 2: WP user ID, 3: KonX affiliate ID */
						__( 'CONFLICT: Email %1$s (WP#%2$d) already has KonX affiliate #%3$d. Execution would fail.', 'konx-affiliate-dashboard' ),
						$email,
						$existing_uid,
						$aff_id
					),
				);
			}

			// Stale: a WP user appeared after snapshot. Executor will reuse the
			// existing user (not create a new one) — outcome differs from frozen plan.
			return array(
				'po10_id' => $po10_id,
				'action'  => 'create',
				'status'  => self::STATUS_STALE,
				'type'    => 'email_appeared',
				'message' => sprintf(
					/* translators: 1: email, 2: WP user ID */
					__( 'STALE: Email %1$s now maps to WP#%2$d (user appeared after snapshot). Executor will reuse existing user — verify this is acceptable.', 'konx-affiliate-dashboard' ),
					$email,
					$existing_uid
				),
			);
		}

		// Sub-check C: Is the referral code already taken?
		if ( '' !== $team_name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$code_owner = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$konx_table} WHERE referral_code = %s LIMIT 1",
					$team_name
				)
			);

			if ( $code_owner ) {
				return array(
					'po10_id' => $po10_id,
					'action'  => 'create',
					'status'  => self::STATUS_CONFLICT,
					'type'    => 'referral_code_taken',
					'message' => sprintf(
						/* translators: 1: referral code, 2: KonX affiliate ID that owns it */
						__( 'CONFLICT: Referral code "%1$s" already taken by KonX affiliate #%2$d. Execution would fail.', 'konx-affiliate-dashboard' ),
						$team_name,
						$code_owner
					),
				);
			}
		}

		return array(
			'po10_id' => $po10_id,
			'action'  => 'create',
			'status'  => self::STATUS_PASS,
			'type'    => null,
			'message' => sprintf(
				/* translators: %s: email address */
				__( 'OK: Email %s is not in wp_users. Referral code is available.', 'konx-affiliate-dashboard' ),
				$email
			),
		);
	}

	/**
	 * Revalidate a 'link_wp' record.
	 *
	 * Frozen assumption: a WP user with wp_user_id existed and had no
	 * KonX affiliate at snapshot time.
	 * Live checks:
	 *   A. WP user with snapshot's wp_user_id still exists — stale if deleted.
	 *   B. That user still has no KonX affiliate — conflict if they gained one.
	 *
	 * @param object $record Plan record.
	 * @return array Check result.
	 */
	private static function check_link_wp( $record ) {
		global $wpdb;

		$po10_id    = (int) $record->source_record_id;
		$wp_user_id = (int) $record->wp_user_id;
		$konx_table = $wpdb->prefix . self::KONX_AFF_TABLE;

		if ( 0 === $wp_user_id ) {
			// Malformed snapshot — wp_user_id is null for a link_wp record.
			return array(
				'po10_id' => $po10_id,
				'action'  => 'link_wp',
				'status'  => self::STATUS_CRITICAL,
				'type'    => 'missing_wp_user_id',
				'message' => sprintf(
					/* translators: %d: PO10 ID */
					__( 'CRITICAL: Plan record for PO10#%d has action=link_wp but wp_user_id is null. Snapshot is malformed.', 'konx-affiliate-dashboard' ),
					$po10_id
				),
			);
		}

		// Sub-check A: WP user still exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE ID = %d LIMIT 1",
				$wp_user_id
			)
		);

		if ( ! $user_exists ) {
			return array(
				'po10_id' => $po10_id,
				'action'  => 'link_wp',
				'status'  => self::STATUS_STALE,
				'type'    => 'user_deleted',
				'message' => sprintf(
					/* translators: 1: PO10 ID, 2: WP user ID */
					__( 'STALE: WP user #%2$d (PO10#%1$d) no longer exists — user was deleted after snapshot.', 'konx-affiliate-dashboard' ),
					$po10_id,
					$wp_user_id
				),
			);
		}

		// Sub-check B: User still has no KonX affiliate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$aff_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$konx_table} WHERE user_id = %d LIMIT 1",
				$wp_user_id
			)
		);

		if ( $aff_id ) {
			return array(
				'po10_id' => $po10_id,
				'action'  => 'link_wp',
				'status'  => self::STATUS_CONFLICT,
				'type'    => 'user_gained_affiliate',
				'message' => sprintf(
					/* translators: 1: PO10 ID, 2: WP user ID, 3: KonX affiliate ID */
					__( 'CONFLICT: WP user #%2$d (PO10#%1$d) now has KonX affiliate #%3$d. Execution would fail.', 'konx-affiliate-dashboard' ),
					$po10_id,
					$wp_user_id,
					$aff_id
				),
			);
		}

		return array(
			'po10_id' => $po10_id,
			'action'  => 'link_wp',
			'status'  => self::STATUS_PASS,
			'type'    => null,
			'message' => sprintf(
				/* translators: 1: PO10 ID, 2: WP user ID */
				__( 'OK: WP user #%2$d (PO10#%1$d) exists and has no KonX affiliate.', 'konx-affiliate-dashboard' ),
				$po10_id,
				$wp_user_id
			),
		);
	}

	/**
	 * Revalidate a 'link_ca' record.
	 *
	 * Frozen assumption: a CA row with coupon_affiliate_id existed, its userid
	 * matched wp_user_id, the WP user existed, and had no KonX affiliate.
	 * Live checks:
	 *   A. CA row with snapshot's coupon_affiliate_id still exists — stale if deleted.
	 *   B. CA row's userid still matches snapshot's wp_user_id — conflict if changed.
	 *   C. WP user still exists — stale if deleted.
	 *   D. WP user still has no KonX affiliate — conflict if gained one.
	 *
	 * @param object $record Plan record.
	 * @return array Check result.
	 */
	private static function check_link_ca( $record ) {
		global $wpdb;

		$po10_id    = (int) $record->source_record_id;
		$wp_user_id = (int) $record->wp_user_id;
		$ca_id      = (int) $record->coupon_affiliate_id;
		$ca_table   = $wpdb->prefix . self::CA_TABLE;
		$konx_table = $wpdb->prefix . self::KONX_AFF_TABLE;

		if ( 0 === $ca_id ) {
			// Malformed snapshot — coupon_affiliate_id is null for a link_ca record.
			return array(
				'po10_id' => $po10_id,
				'action'  => 'link_ca',
				'status'  => self::STATUS_CRITICAL,
				'type'    => 'missing_ca_id',
				'message' => sprintf(
					/* translators: %d: PO10 ID */
					__( 'CRITICAL: Plan record for PO10#%d has action=link_ca but coupon_affiliate_id is null. Snapshot is malformed.', 'konx-affiliate-dashboard' ),
					$po10_id
				),
			);
		}

		// Sub-check A: CA row still exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ca_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, userid FROM {$ca_table} WHERE id = %d LIMIT 1",
				$ca_id
			)
		);

		if ( ! $ca_row ) {
			return array(
				'po10_id' => $po10_id,
				'action'  => 'link_ca',
				'status'  => self::STATUS_STALE,
				'type'    => 'ca_row_deleted',
				'message' => sprintf(
					/* translators: 1: PO10 ID, 2: CA row ID */
					__( 'STALE: Coupon Affiliate row #%2$d (PO10#%1$d) no longer exists — CA record was deleted after snapshot.', 'konx-affiliate-dashboard' ),
					$po10_id,
					$ca_id
				),
			);
		}

		// Sub-check B: CA userid still matches snapshot's wp_user_id.
		$ca_userid = (int) $ca_row->userid;

		if ( 0 !== $wp_user_id && $ca_userid !== $wp_user_id ) {
			return array(
				'po10_id' => $po10_id,
				'action'  => 'link_ca',
				'status'  => self::STATUS_CONFLICT,
				'type'    => 'ca_user_mismatch',
				'message' => sprintf(
					/* translators: 1: PO10 ID, 2: CA row ID, 3: expected WP user ID, 4: actual CA userid */
					__( 'CONFLICT: CA#%2$d (PO10#%1$d) userid changed. Expected=%3$d Actual=%4$d. CA data modified after snapshot.', 'konx-affiliate-dashboard' ),
					$po10_id,
					$ca_id,
					$wp_user_id,
					$ca_userid
				),
			);
		}

		// Use the CA-stored userid as ground truth for remaining checks.
		$effective_uid = $wp_user_id > 0 ? $wp_user_id : $ca_userid;

		// Sub-check C: WP user still exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE ID = %d LIMIT 1",
				$effective_uid
			)
		);

		if ( ! $user_exists ) {
			return array(
				'po10_id' => $po10_id,
				'action'  => 'link_ca',
				'status'  => self::STATUS_STALE,
				'type'    => 'ca_user_deleted',
				'message' => sprintf(
					/* translators: 1: PO10 ID, 2: CA row ID, 3: WP user ID */
					__( 'STALE: WP user #%3$d (CA#%2$d, PO10#%1$d) no longer exists — user was deleted after snapshot.', 'konx-affiliate-dashboard' ),
					$po10_id,
					$ca_id,
					$effective_uid
				),
			);
		}

		// Sub-check D: WP user still has no KonX affiliate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$aff_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$konx_table} WHERE user_id = %d LIMIT 1",
				$effective_uid
			)
		);

		if ( $aff_id ) {
			return array(
				'po10_id' => $po10_id,
				'action'  => 'link_ca',
				'status'  => self::STATUS_CONFLICT,
				'type'    => 'ca_user_has_affiliate',
				'message' => sprintf(
					/* translators: 1: PO10 ID, 2: CA row ID, 3: WP user ID, 4: KonX affiliate ID */
					__( 'CONFLICT: WP user #%3$d (CA#%2$d, PO10#%1$d) already has KonX affiliate #%4$d. Execution would fail.', 'konx-affiliate-dashboard' ),
					$po10_id,
					$ca_id,
					$effective_uid,
					$aff_id
				),
			);
		}

		return array(
			'po10_id' => $po10_id,
			'action'  => 'link_ca',
			'status'  => self::STATUS_PASS,
			'type'    => null,
			'message' => sprintf(
				/* translators: 1: PO10 ID, 2: CA row ID, 3: WP user ID */
				__( 'OK: CA#%2$d (PO10#%1$d) exists, userid=%3$d matches, user has no KonX affiliate.', 'konx-affiliate-dashboard' ),
				$po10_id,
				$ca_id,
				$effective_uid
			),
		);
	}
}
