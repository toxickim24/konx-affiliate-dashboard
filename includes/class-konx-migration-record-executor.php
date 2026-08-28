<?php
/**
 * Single-record migration executor — Phase 24C-6D/6E.
 *
 * Executes a single frozen execution-plan record for a session in
 * 'frozen' status when KONX_MIGRATION_TEST_EXECUTION_ENABLED is true.
 * This is LOCAL/TEST ONLY scaffolding.
 *
 * KEY CONSTRAINTS:
 *   - Reads ONLY from wp_konx_migration_execution_plan (never decision_matrix).
 *   - Canonical session UUID '395e2b79-1e0a-49e8-9ea6-1ae146c9a54d' is blocked.
 *   - Only sessions in 'frozen' status are accepted when KONX_MIGRATION_TEST_EXECUTION_ENABLED is true.
 *   - Atomic ledger claim via UPDATE WHERE status='pending' / affected_rows check.
 *   - Per-record revalidation before any business mutation.
 *   - Terminal ledger writes are checked for DB failure; 'ledger_persist_failed'
 *     is returned when business succeeds but the ledger write does not.
 *   - Compensation rollback verifies ownership via konx_source/konx_migrated_po10_id meta.
 *   - Cross-session idempotency via ledger.status='completed' check.
 *   - No Execute button, REST endpoint, or AJAX endpoint is exposed.
 *
 * @package KonxAffiliateDashboard
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Phase 24C-6E: KONX_EXEC_ALLOWED_STATUSES_TEST (Phase 24C-6D constant) removed.
// Sessions in 'frozen' status are executed directly when KONX_MIGRATION_TEST_EXECUTION_ENABLED === true.

/**
 * Class Konx_Migration_Record_Executor
 */
class Konx_Migration_Record_Executor {

	/**
	 * The canonical production session UUID — NEVER execute this.
	 */
	const KONX_CANONICAL_SESSION_UUID = '395e2b79-1e0a-49e8-9ea6-1ae146c9a54d';

	/**
	 * Source system identifier for all records.
	 */
	const SOURCE_SYSTEM = 'powerof10';

	// ------------------------------------------------------------------
	// Test-only injection hooks (Phase 24C-6D scaffolding).
	// Only settable when KONX_MIGRATION_TEST_EXECUTION_ENABLED === true.
	// Never accessible in production — the constant gate is the first check.
	// ------------------------------------------------------------------

	/** @var bool When true, insert_konx_affiliate() returns a test-injected WP_Error. */
	private static $test_affiliate_insert_fail = false;

	/** @var bool When true, compensate_delete_wp_user() returns false (simulates unsafe compensation). */
	private static $test_block_compensation = false;

	/** @var bool When true, mark_ledger_completed() returns a simulated WP_Error (Phase 24C-6E). */
	private static $test_completed_update_fail = false;

	/** @var bool When true, mark_ledger_failed() returns a simulated WP_Error (Phase 24C-6E). */
	private static $test_failed_update_fail = false;

	/** @var bool When true, mark_ledger_partial() returns a simulated WP_Error (Phase 24C-6E). */
	private static $test_partial_update_fail = false;

	/** @var bool When true, execute_create_record() skips migration ownership meta writes (Phase 24C-6E). */
	private static $test_skip_ownership_meta = false;

	/**
	 * Enable or disable test-only affiliate insert failure injection.
	 * Only callable when KONX_MIGRATION_TEST_EXECUTION_ENABLED is true.
	 *
	 * @param bool $fail True to inject failure; false to restore normal behavior.
	 */
	public static function set_test_affiliate_fail( $fail ) {
		if ( ! defined( 'KONX_MIGRATION_TEST_EXECUTION_ENABLED' ) || ! KONX_MIGRATION_TEST_EXECUTION_ENABLED ) {
			return;
		}
		self::$test_affiliate_insert_fail = (bool) $fail;
	}

	/**
	 * Enable or disable test-only compensation blocking.
	 * Only callable when KONX_MIGRATION_TEST_EXECUTION_ENABLED is true.
	 *
	 * @param bool $block True to block compensation; false to restore normal behavior.
	 */
	public static function set_test_block_compensation( $block ) {
		if ( ! defined( 'KONX_MIGRATION_TEST_EXECUTION_ENABLED' ) || ! KONX_MIGRATION_TEST_EXECUTION_ENABLED ) {
			return;
		}
		self::$test_block_compensation = (bool) $block;
	}

	/**
	 * Enable or disable test-only completed-ledger write failure injection.
	 * Only callable when KONX_MIGRATION_TEST_EXECUTION_ENABLED is true.
	 *
	 * @param bool $fail True to inject failure.
	 */
	public static function set_test_completed_update_fail( $fail ) {
		if ( ! defined( 'KONX_MIGRATION_TEST_EXECUTION_ENABLED' ) || ! KONX_MIGRATION_TEST_EXECUTION_ENABLED ) {
			return;
		}
		self::$test_completed_update_fail = (bool) $fail;
	}

	/**
	 * Enable or disable test-only failed-ledger write failure injection.
	 * Only callable when KONX_MIGRATION_TEST_EXECUTION_ENABLED is true.
	 *
	 * @param bool $fail True to inject failure.
	 */
	public static function set_test_failed_update_fail( $fail ) {
		if ( ! defined( 'KONX_MIGRATION_TEST_EXECUTION_ENABLED' ) || ! KONX_MIGRATION_TEST_EXECUTION_ENABLED ) {
			return;
		}
		self::$test_failed_update_fail = (bool) $fail;
	}

	/**
	 * Enable or disable test-only partial-ledger write failure injection.
	 * Only callable when KONX_MIGRATION_TEST_EXECUTION_ENABLED is true.
	 *
	 * @param bool $fail True to inject failure.
	 */
	public static function set_test_partial_update_fail( $fail ) {
		if ( ! defined( 'KONX_MIGRATION_TEST_EXECUTION_ENABLED' ) || ! KONX_MIGRATION_TEST_EXECUTION_ENABLED ) {
			return;
		}
		self::$test_partial_update_fail = (bool) $fail;
	}

	/**
	 * Enable or disable test-only ownership meta skip.
	 * When true, execute_create_record() skips writing konx_source and
	 * konx_migrated_po10_id meta, so compensate_delete_wp_user() will find
	 * no ownership proof and refuse deletion (→ partial state).
	 * Only callable when KONX_MIGRATION_TEST_EXECUTION_ENABLED is true.
	 *
	 * @param bool $skip True to skip ownership meta.
	 */
	public static function set_test_skip_ownership_meta( $skip ) {
		if ( ! defined( 'KONX_MIGRATION_TEST_EXECUTION_ENABLED' ) || ! KONX_MIGRATION_TEST_EXECUTION_ENABLED ) {
			return;
		}
		self::$test_skip_ownership_meta = (bool) $skip;
	}

	/**
	 * Reset all test-only injection hooks to defaults.
	 * Only callable when KONX_MIGRATION_TEST_EXECUTION_ENABLED is true.
	 */
	public static function reset_test_hooks() {
		if ( ! defined( 'KONX_MIGRATION_TEST_EXECUTION_ENABLED' ) || ! KONX_MIGRATION_TEST_EXECUTION_ENABLED ) {
			return;
		}
		self::$test_affiliate_insert_fail  = false;
		self::$test_block_compensation     = false;
		self::$test_completed_update_fail  = false;
		self::$test_failed_update_fail     = false;
		self::$test_partial_update_fail    = false;
		self::$test_skip_ownership_meta    = false;
	}

	/**
	 * Coupon Affiliates table name (without WP prefix).
	 */
	const CA_TABLE = 'wcusage_register';

	/**
	 * KonX affiliates table name (without WP prefix).
	 */
	const KONX_AFF_TABLE = 'konx_affiliates';

	// ------------------------------------------------------------------
	// Public Entry Point
	// ------------------------------------------------------------------

	/**
	 * Execute a single migration plan record.
	 *
	 * All action data is loaded from wp_konx_migration_execution_plan.
	 * The caller may NOT supply action, email, wp_user_id, or any
	 * business data — only the session UUID and plan record ID.
	 *
	 * @param string $session_uuid   UUID of a 'frozen' session (KONX_MIGRATION_TEST_EXECUTION_ENABLED must be true).
	 * @param int    $plan_record_id Primary key of the plan row to execute.
	 * @return array {
	 *     @type string      $session_uuid     Session UUID.
	 *     @type int         $plan_record_id   Plan table primary key.
	 *     @type int         $source_record_id PO10 source ID.
	 *     @type string      $action           Frozen action type.
	 *     @type string      $status           completed|failed|partial|ledger_persist_failed|skipped|conflict|already_migrated.
	 *     @type int|null    $wp_user_id       WP user ID if set.
	 *     @type bool        $wp_user_created  True if new WP user was created.
	 *     @type int|null    $affiliate_id     KonX affiliate ID if set.
	 *     @type bool        $affiliate_created True if new affiliate was created.
	 *     @type string|null $error_code       Machine-readable error code.
	 *     @type string|null $error_message    Sanitized error message.
	 *     @type int         $attempt_count    Attempt count from ledger.
	 *     @type string|null $started_at       Ledger started_at timestamp.
	 *     @type string|null $completed_at     Ledger completed_at timestamp.
	 * }
	 */
	public static function execute_record( $session_uuid, $plan_record_id ) {
		global $wpdb;

		$session_uuid   = sanitize_text_field( (string) $session_uuid );
		$plan_record_id = (int) $plan_record_id;

		// ------------------------------------------------------------------
		// CANONICAL PROTECTION — absolute first check.
		// ------------------------------------------------------------------
		if ( $session_uuid === self::KONX_CANONICAL_SESSION_UUID ) {
			return self::error_result(
				$session_uuid,
				0,
				'',
				'canonical_protected',
				'Canonical session cannot be executed in Phase 24C-6D.'
			);
		}

		// ------------------------------------------------------------------
		// ENVIRONMENT GATE — second check (Fix 4).
		//
		// Konx_Migration_Record_Executor is in includes/ and is autoloaded
		// by the production plugin whenever the class name is referenced.
		// This gate prevents accidental execution in production environments
		// where the test constant is not explicitly defined.
		//
		// KONX_MIGRATION_TEST_EXECUTION_ENABLED must be defined and === true.
		// It defaults to undefined (falsy). Tests set it in bootstrap.
		// Do NOT define it in wp-config.php or any production file.
		// ------------------------------------------------------------------
		if ( ! defined( 'KONX_MIGRATION_TEST_EXECUTION_ENABLED' ) || ! KONX_MIGRATION_TEST_EXECUTION_ENABLED ) {
			return self::error_result(
				$session_uuid,
				$plan_record_id,
				'',
				'execution_not_enabled',
				'KONX_MIGRATION_TEST_EXECUTION_ENABLED is not defined. Single-record execution is disabled in this environment.'
			);
		}

		// ------------------------------------------------------------------
		// PRECONDITION 1: Session exists.
		// ------------------------------------------------------------------
		$session = Konx_Migration_Exec_Session::get( $session_uuid );
		if ( ! $session ) {
			return self::error_result(
				$session_uuid,
				$plan_record_id,
				'',
				'session_not_found',
				'Execution session not found.'
			);
		}

		// ------------------------------------------------------------------
		// PRECONDITION 2: Session must be in 'frozen' status.
		// Phase 24C-6E: test_execution status removed. Sessions remain frozen.
		// ------------------------------------------------------------------
		if ( 'frozen' !== $session->status ) {
			return self::error_result(
				$session_uuid,
				$plan_record_id,
				'',
				'invalid_session_status',
				sprintf(
					'Session status "%s" is not allowed for execution. Required: frozen.',
					$session->status
				)
			);
		}

		// ------------------------------------------------------------------
		// PRECONDITION 3 & 4: Plan record exists and belongs to this session.
		// ------------------------------------------------------------------
		$plan_table = $wpdb->prefix . Konx_Migration_Execution_Plan::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$plan_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$plan_table} WHERE id = %d LIMIT 1",
				$plan_record_id
			)
		);

		if ( ! $plan_row ) {
			return self::error_result(
				$session_uuid,
				$plan_record_id,
				'',
				'plan_record_not_found',
				sprintf( 'Plan record #%d not found.', $plan_record_id )
			);
		}

		if ( (int) $plan_row->session_id !== (int) $session->id ) {
			return self::error_result(
				$session_uuid,
				$plan_record_id,
				(string) $plan_row->source_record_id,
				'plan_record_session_mismatch',
				sprintf(
					'Plan record #%d belongs to session_id=%d, not session_id=%d.',
					$plan_record_id,
					$plan_row->session_id,
					$session->id
				)
			);
		}

		// Double-check UUID matches (belt-and-suspenders after session_id check).
		if ( $plan_row->session_id != $session->id || $session->session_uuid !== $session_uuid ) {
			return self::error_result(
				$session_uuid,
				$plan_record_id,
				(string) $plan_row->source_record_id,
				'plan_record_session_mismatch',
				'Session UUID mismatch on double-check.'
			);
		}

		// ------------------------------------------------------------------
		// PRECONDITION 5: Plugin version.
		// ------------------------------------------------------------------
		if ( defined( 'KONX_AFFILIATE_VERSION' ) && $session->plugin_version !== KONX_AFFILIATE_VERSION ) {
			return self::error_result(
				$session_uuid,
				$plan_record_id,
				(string) $plan_row->source_record_id,
				'plugin_version_mismatch',
				sprintf(
					'Plugin version mismatch. Session=%s Current=%s.',
					$session->plugin_version,
					KONX_AFFILIATE_VERSION
				)
			);
		}

		// ------------------------------------------------------------------
		// PRECONDITION 6: DB schema version.
		// ------------------------------------------------------------------
		if ( defined( 'KONX_AFFILIATE_DB_VERSION' ) ) {
			if ( version_compare( KONX_AFFILIATE_DB_VERSION, $session->database_schema_version, '<' ) ) {
				return self::error_result(
					$session_uuid,
					$plan_record_id,
					(string) $plan_row->source_record_id,
					'schema_version_downgrade',
					sprintf(
						'DB schema downgrade detected. Session=%s Current=%s.',
						$session->database_schema_version,
						KONX_AFFILIATE_DB_VERSION
					)
				);
			}
		}

		// ------------------------------------------------------------------
		// PRECONDITION 7: Plan hash matches.
		// ------------------------------------------------------------------
		$plan_records  = Konx_Migration_Execution_Plan::get_by_session( (int) $session->id );
		$computed_hash = Konx_Migration_Plan_Hasher::hash_from_plan_records( $plan_records );
		if ( ! hash_equals( (string) $session->final_plan_hash, $computed_hash ) ) {
			return self::error_result(
				$session_uuid,
				$plan_record_id,
				(string) $plan_row->source_record_id,
				'plan_hash_mismatch',
				'Frozen plan hash does not match computed hash. Snapshot integrity failed.'
			);
		}

		// ------------------------------------------------------------------
		// EARLY DISPATCH for non-actionable actions.
		// 'invalid', 'skip', 'review' records have no ledger rows (they were
		// never inserted into the ledger during snapshot creation). Return
		// immediately without attempting the ledger claim or revalidation.
		// ------------------------------------------------------------------
		$action_early = (string) $plan_row->action;
		if ( in_array( $action_early, array( 'invalid', 'skip', 'review' ), true ) ) {
			$source_record_id = (int) $plan_row->source_record_id;
			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => $action_early,
				'status'            => 'skipped',
				'wp_user_id'        => null,
				'wp_user_created'   => false,
				'affiliate_id'      => null,
				'affiliate_created' => false,
				'error_code'        => 'non_actionable',
				'error_message'     => sprintf( 'Action "%s" is non-actionable — no business writes performed.', $action_early ),
				'attempt_count'     => 0,
				'started_at'        => null,
				'completed_at'      => null,
			);
		}

		// ------------------------------------------------------------------
		// PRECONDITION 9: Cross-session idempotency.
		// Runs BEFORE revalidation to avoid triggering the revalidator's own
		// cross-session idempotency check, which would return 'critical' and
		// mask the idempotency result as a generic failure.
		// ------------------------------------------------------------------
		$source_record_id = (int) $plan_row->source_record_id;
		$already_done     = Konx_Migration_Execution_Ledger::find_completed(
			$source_record_id,
			self::SOURCE_SYSTEM
		);
		if ( $already_done ) {
			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => (string) $plan_row->action,
				'status'            => 'already_migrated',
				'wp_user_id'        => null,
				'wp_user_created'   => false,
				'affiliate_id'      => null,
				'affiliate_created' => false,
				'error_code'        => 'already_migrated',
				'error_message'     => sprintf(
					'Source record %d already completed in session_id=%d (ledger_id=%d).',
					$source_record_id,
					$already_done->session_id,
					$already_done->id
				),
				'attempt_count'     => 0,
				'started_at'        => null,
				'completed_at'      => null,
			);
		}

		// ------------------------------------------------------------------
		// PRECONDITION 8: Revalidation must pass RIGHT NOW.
		//
		// For test_execution sessions, we bypass the FMP parity check by
		// calling revalidate() with an option override so the session-level
		// hash check passes (the test session hash won't match production FMP).
		// Revalidation runs AFTER early dispatch and cross-session idempotency
		// so that those fast-path returns don't trigger revalidation overheads.
		// ------------------------------------------------------------------
		$revalidation = self::run_revalidation_for_session( $session_uuid, $plan_records );
		if ( 'critical' === $revalidation['revalidation_status'] ) {
			return self::error_result(
				$session_uuid,
				$plan_record_id,
				(string) $plan_row->source_record_id,
				'revalidation_critical',
				'Session-level revalidation critical failure: ' . self::extract_first_critical_message( $revalidation )
			);
		}

		// ------------------------------------------------------------------
		// ATOMIC LEDGER CLAIM
		// Update WHERE status='pending' — affected_rows must be exactly 1.
		// Non-actionable actions already returned above.
		// ------------------------------------------------------------------
		$ledger_table = $wpdb->prefix . Konx_Migration_Execution_Ledger::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$ledger_table}
				 SET status = 'processing',
				     attempt_count = attempt_count + 1,
				     started_at = NOW()
				 WHERE plan_id = %d
				   AND session_id = %d
				   AND status = 'pending'",
				$plan_record_id,
				(int) $session->id
			)
		);

		$affected = $wpdb->last_error ? 0 : (int) self::get_affected_rows( $wpdb );

		if ( 1 !== $affected ) {
			// Claim failed — either not pending, or already processing.
			// Retrieve current ledger state for context.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$current_ledger = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$ledger_table} WHERE plan_id = %d AND session_id = %d LIMIT 1",
					$plan_record_id,
					(int) $session->id
				)
			);

			$current_status = $current_ledger ? $current_ledger->status : 'not_found';

			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => (string) $plan_row->action,
				'status'            => 'conflict',
				'wp_user_id'        => null,
				'wp_user_created'   => false,
				'affiliate_id'      => null,
				'affiliate_created' => false,
				'error_code'        => 'ledger_claim_failed',
				'error_message'     => sprintf(
					'Could not claim ledger row (plan_id=%d). Current status: %s.',
					$plan_record_id,
					$current_status
				),
				'attempt_count'     => $current_ledger ? (int) $current_ledger->attempt_count : 0,
				'started_at'        => $current_ledger ? $current_ledger->started_at : null,
				'completed_at'      => $current_ledger ? $current_ledger->completed_at : null,
			);
		}

		// Reload ledger row with updated values.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ledger_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$ledger_table} WHERE plan_id = %d AND session_id = %d LIMIT 1",
				$plan_record_id,
				(int) $session->id
			)
		);

		// ------------------------------------------------------------------
		// PER-RECORD REVALIDATION (after claim, before mutation).
		// Check the specific plan record for stale/conflict conditions.
		// ------------------------------------------------------------------
		$record_check = self::check_single_record( $plan_row );
		if ( 'pass' !== $record_check['status'] ) {
			// Mark ledger as failed and return early.
			self::mark_ledger_failed(
				$ledger_row->id,
				'stale_or_conflict',
				'Per-record revalidation failed: ' . $record_check['message']
			);

			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => (string) $plan_row->action,
				'status'            => 'failed',
				'wp_user_id'        => null,
				'wp_user_created'   => false,
				'affiliate_id'      => null,
				'affiliate_created' => false,
				'error_code'        => 'stale_or_conflict',
				'error_message'     => 'Per-record revalidation failed: ' . $record_check['message'],
				'attempt_count'     => (int) $ledger_row->attempt_count,
				'started_at'        => $ledger_row->started_at,
				'completed_at'      => null,
			);
		}

		// ------------------------------------------------------------------
		// ACTION DISPATCH
		// ------------------------------------------------------------------
		$action = (string) $plan_row->action;

		switch ( $action ) {
			case 'create':
				return self::execute_create_record( $session_uuid, $plan_row, $ledger_row );

			case 'link_wp':
				return self::execute_link_wp_record( $session_uuid, $plan_row, $ledger_row );

			case 'link_ca':
				return self::execute_link_ca_record( $session_uuid, $plan_row, $ledger_row );

			// NOTE: 'invalid', 'skip', 'review' are handled by early dispatch before
			// the ledger claim (above). They never reach this switch. The default case
			// below catches any unexpected actions fail-closed.

			default:
				self::mark_ledger_failed( $ledger_row->id, 'invalid_action', sprintf( 'Unknown action: %s', $action ) );

				return array(
					'session_uuid'      => $session_uuid,
					'plan_record_id'    => $plan_record_id,
					'source_record_id'  => $source_record_id,
					'action'            => $action,
					'status'            => 'failed',
					'wp_user_id'        => null,
					'wp_user_created'   => false,
					'affiliate_id'      => null,
					'affiliate_created' => false,
					'error_code'        => 'invalid_action',
					'error_message'     => sprintf( 'Unknown action "%s" in plan record.', $action ),
					'attempt_count'     => (int) $ledger_row->attempt_count,
					'started_at'        => $ledger_row->started_at,
					'completed_at'      => null,
				);
		}
	}

	// ------------------------------------------------------------------
	// Action Executors
	// ------------------------------------------------------------------

	/**
	 * Execute a 'create' record: new WP user + new KonX affiliate.
	 *
	 * @param string $session_uuid UUID of the session.
	 * @param object $plan_row     Plan record from DB.
	 * @param object $ledger_row   Ledger record from DB.
	 * @return array Result.
	 */
	private static function execute_create_record( $session_uuid, $plan_row, $ledger_row ) {
		$plan_record_id   = (int) $plan_row->id;
		$source_record_id = (int) $plan_row->source_record_id;
		$email            = strtolower( trim( (string) $plan_row->source_email ) );
		$affiliate_type   = sanitize_text_field( (string) $plan_row->affiliate_type ) ?: 'sales_agent';
		$team_name        = sanitize_text_field( (string) ( $plan_row->team_name ?? '' ) );
		$sponsor_team     = sanitize_text_field( (string) ( $plan_row->sponsor_team_name ?? '' ) );

		// Decode source_payload for name fields.
		$source_payload = array();
		if ( ! empty( $plan_row->source_payload ) ) {
			$decoded = json_decode( $plan_row->source_payload, true );
			if ( is_array( $decoded ) ) {
				$source_payload = $decoded;
			}
		}
		$first_name = sanitize_text_field( (string) ( $source_payload['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $source_payload['last_name'] ?? '' ) );

		// Sub-check: email_exists() — if true, return stale.
		if ( self::wp_email_exists( $email ) ) {
			self::mark_ledger_failed( $ledger_row->id, 'stale_email_exists', sprintf( 'Email %s already exists in wp_users.', $email ) );

			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => 'create',
				'status'            => 'failed',
				'wp_user_id'        => null,
				'wp_user_created'   => false,
				'affiliate_id'      => null,
				'affiliate_created' => false,
				'error_code'        => 'stale_email_exists',
				'error_message'     => sprintf( 'Email %s already exists. Cannot create new WP user.', $email ),
				'attempt_count'     => (int) $ledger_row->attempt_count,
				'started_at'        => $ledger_row->started_at,
				'completed_at'      => null,
			);
		}

		// Generate username from email prefix.
		$login_base = self::sanitize_username( strstr( $email, '@', true ) );
		if ( empty( $login_base ) ) {
			$login_base = 'user';
		}

		$login  = $login_base;
		$suffix = 1;
		while ( self::wp_username_exists( $login ) ) {
			$login = $login_base . $suffix;
			$suffix++;
			if ( $suffix > 999 ) {
				$login = $login_base . self::wp_random_int( 1000, 99999 );
				break;
			}
		}

		// Generate strong random password.
		$password = self::wp_generate_password_safe( 24, true, true );

		// Suppress notifications before creating user.
		self::suppress_user_notifications( true );

		// Create WP user.
		$wp_user_id = self::create_wp_user_direct( $login, $password, $email );
		$wp_created = false;

		if ( is_wp_error( $wp_user_id ) || false === $wp_user_id ) {
			self::suppress_user_notifications( false );
			$err_msg = is_wp_error( $wp_user_id ) ? $wp_user_id->get_error_message() : 'wp_create_user returned false';
			self::mark_ledger_failed( $ledger_row->id, 'wp_user_creation_failed', $err_msg );

			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => 'create',
				'status'            => 'failed',
				'wp_user_id'        => null,
				'wp_user_created'   => false,
				'affiliate_id'      => null,
				'affiliate_created' => false,
				'error_code'        => 'wp_user_creation_failed',
				'error_message'     => sanitize_text_field( mb_substr( $err_msg, 0, 500 ) ),
				'attempt_count'     => (int) $ledger_row->attempt_count,
				'started_at'        => $ledger_row->started_at,
				'completed_at'      => null,
			);
		}

		$wp_created    = true;
		$wp_user_id    = (int) $wp_user_id;

		self::suppress_user_notifications( false );

		// Write user meta: first_name, last_name.
		if ( $first_name || $last_name ) {
			self::update_user_meta_direct( $wp_user_id, 'first_name', $first_name );
			self::update_user_meta_direct( $wp_user_id, 'last_name', $last_name );
		}

		// Store migration source meta (ownership proof for compensation).
		// Test-only injection: skip_ownership_meta bypasses this to allow ownership-failure testing.
		if ( ! self::$test_skip_ownership_meta ) {
			self::update_user_meta_direct( $wp_user_id, 'konx_source', 'migration' );
			self::update_user_meta_direct( $wp_user_id, 'konx_migrated_po10_id', (string) $source_record_id );
		}

		// Insert KonX affiliate.
		$aff_args = array(
			'external_id' => 'po10_' . $source_record_id,
		);
		if ( ! empty( $team_name ) ) {
			$aff_args['referral_code'] = $team_name;
		}
		$aff_args['notes'] = sprintf( 'Migrated from PO10 (ID: %d) via Phase 24C-6D.', $source_record_id );

		// Attempt to resolve parent affiliate.
		$parent_id = self::resolve_parent_affiliate( $sponsor_team );
		if ( $parent_id ) {
			$aff_args['parent_affiliate_id'] = $parent_id;
		}

		$affiliate_id  = self::insert_konx_affiliate( $wp_user_id, $affiliate_type, $aff_args );
		$aff_created   = false;

		if ( is_wp_error( $affiliate_id ) || false === $affiliate_id ) {
			// COMPENSATION: delete the WP user we just created.
			// compensate_delete_wp_user() verifies ownership via migration meta
			// before deleting. Only the exact user created by this attempt is deleted.
			$err_msg = is_wp_error( $affiliate_id ) ? $affiliate_id->get_error_message() : 'insert_konx_affiliate failed';

			$compensated = self::compensate_delete_wp_user( $wp_user_id, $source_record_id );

			if ( $compensated ) {
				// Full compensation — WP user deleted; report as failed (clean state).
				$ledger_write = self::mark_ledger_failed(
					$ledger_row->id,
					'affiliate_insert_failed',
					'Affiliate insert failed; WP user deleted (compensated). ' . $err_msg
				);
				if ( is_wp_error( $ledger_write ) ) {
					// WP user deleted but ledger is stuck at 'processing'.
					return array(
						'session_uuid'      => $session_uuid,
						'plan_record_id'    => $plan_record_id,
						'source_record_id'  => $source_record_id,
						'action'            => 'create',
						'status'            => 'ledger_persist_failed',
						'wp_user_id'        => null,
						'wp_user_created'   => false,
						'affiliate_id'      => null,
						'affiliate_created' => false,
						'error_code'        => 'ledger_persist_failed',
						'error_message'     => 'Affiliate failed, WP user compensated, but ledger write failed: ' . sanitize_text_field( mb_substr( $ledger_write->get_error_message(), 0, 400 ) ),
						'attempt_count'     => (int) $ledger_row->attempt_count,
						'started_at'        => $ledger_row->started_at,
						'completed_at'      => null,
					);
				}

				return array(
					'session_uuid'      => $session_uuid,
					'plan_record_id'    => $plan_record_id,
					'source_record_id'  => $source_record_id,
					'action'            => 'create',
					'status'            => 'failed',
					'wp_user_id'        => null,
					'wp_user_created'   => false,
					'affiliate_id'      => null,
					'affiliate_created' => false,
					'error_code'        => 'affiliate_insert_failed',
					'error_message'     => sanitize_text_field( mb_substr( $err_msg, 0, 400 ) ) . ' (WP user compensated)',
					'attempt_count'     => (int) $ledger_row->attempt_count,
					'started_at'        => $ledger_row->started_at,
					'completed_at'      => null,
				);
			} else {
				// Compensation refused — WP user exists but affiliate failed (partial state).
				// This occurs when compensation cannot prove ownership, or when the
				// affiliate attachment race guard fires.
				$ledger_write = self::mark_ledger_partial(
					$ledger_row->id,
					$wp_user_id,
					true,
					null,
					false,
					'affiliate_insert_failed',
					'Affiliate insert failed; WP user exists (partial). ' . $err_msg
				);
				if ( is_wp_error( $ledger_write ) ) {
					// WP user exists (partial) but ledger is stuck at 'processing'.
					return array(
						'session_uuid'      => $session_uuid,
						'plan_record_id'    => $plan_record_id,
						'source_record_id'  => $source_record_id,
						'action'            => 'create',
						'status'            => 'ledger_persist_failed',
						'wp_user_id'        => $wp_user_id,
						'wp_user_created'   => true,
						'affiliate_id'      => null,
						'affiliate_created' => false,
						'error_code'        => 'ledger_persist_failed',
						'error_message'     => 'Partial state (WP user kept): ledger write failed: ' . sanitize_text_field( mb_substr( $ledger_write->get_error_message(), 0, 400 ) ),
						'attempt_count'     => (int) $ledger_row->attempt_count,
						'started_at'        => $ledger_row->started_at,
						'completed_at'      => null,
					);
				}

				return array(
					'session_uuid'      => $session_uuid,
					'plan_record_id'    => $plan_record_id,
					'source_record_id'  => $source_record_id,
					'action'            => 'create',
					'status'            => 'partial',
					'wp_user_id'        => $wp_user_id,
					'wp_user_created'   => true,
					'affiliate_id'      => null,
					'affiliate_created' => false,
					'error_code'        => 'affiliate_insert_failed',
					'error_message'     => sanitize_text_field( mb_substr( $err_msg, 0, 400 ) ) . ' (WP user kept)',
					'attempt_count'     => (int) $ledger_row->attempt_count,
					'started_at'        => $ledger_row->started_at,
					'completed_at'      => null,
				);
			}
		}

		$affiliate_id = (int) $affiliate_id;
		$aff_created  = true;

		// SUCCESS — mark ledger completed.
		$ledger_result = self::mark_ledger_completed( $ledger_row->id, $wp_user_id, true, $affiliate_id, true );
		if ( is_wp_error( $ledger_result ) ) {
			// Business mutation succeeded (WP user + affiliate created) but the
			// ledger write failed. The record is stuck at 'processing' in the DB.
			// Return ledger_persist_failed so callers can surface the inconsistency.
			// Do NOT claim 'completed' — it was not persisted.
			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => 'create',
				'status'            => 'ledger_persist_failed',
				'wp_user_id'        => $wp_user_id,
				'wp_user_created'   => true,
				'affiliate_id'      => $affiliate_id,
				'affiliate_created' => true,
				'error_code'        => 'ledger_persist_failed',
				'error_message'     => sanitize_text_field( mb_substr( $ledger_result->get_error_message(), 0, 500 ) ),
				'attempt_count'     => (int) $ledger_row->attempt_count,
				'started_at'        => $ledger_row->started_at,
				'completed_at'      => null,
			);
		}

		return array(
			'session_uuid'      => $session_uuid,
			'plan_record_id'    => $plan_record_id,
			'source_record_id'  => $source_record_id,
			'action'            => 'create',
			'status'            => 'completed',
			'wp_user_id'        => $wp_user_id,
			'wp_user_created'   => true,
			'affiliate_id'      => $affiliate_id,
			'affiliate_created' => true,
			'error_code'        => null,
			'error_message'     => null,
			'attempt_count'     => (int) $ledger_row->attempt_count,
			'started_at'        => $ledger_row->started_at,
			'completed_at'      => $ledger_result['completed_at'],
		);
	}

	/**
	 * Execute a 'link_wp' record: create KonX affiliate for existing WP user.
	 *
	 * @param string $session_uuid UUID of the session.
	 * @param object $plan_row     Plan record from DB.
	 * @param object $ledger_row   Ledger record from DB.
	 * @return array Result.
	 */
	private static function execute_link_wp_record( $session_uuid, $plan_row, $ledger_row ) {
		global $wpdb;

		$plan_record_id   = (int) $plan_row->id;
		$source_record_id = (int) $plan_row->source_record_id;
		$wp_user_id       = (int) $plan_row->wp_user_id;
		$affiliate_type   = sanitize_text_field( (string) $plan_row->affiliate_type ) ?: 'sales_agent';
		$team_name        = sanitize_text_field( (string) ( $plan_row->team_name ?? '' ) );
		$sponsor_team     = sanitize_text_field( (string) ( $plan_row->sponsor_team_name ?? '' ) );

		// Verify WP user still exists.
		if ( ! self::wp_user_exists( $wp_user_id ) ) {
			self::mark_ledger_failed(
				$ledger_row->id,
				'stale_wp_user_not_found',
				sprintf( 'WP user #%d no longer exists.', $wp_user_id )
			);

			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => 'link_wp',
				'status'            => 'failed',
				'wp_user_id'        => null,
				'wp_user_created'   => false,
				'affiliate_id'      => null,
				'affiliate_created' => false,
				'error_code'        => 'stale_wp_user_not_found',
				'error_message'     => sprintf( 'WP user #%d no longer exists (stale plan record).', $wp_user_id ),
				'attempt_count'     => (int) $ledger_row->attempt_count,
				'started_at'        => $ledger_row->started_at,
				'completed_at'      => null,
			);
		}

		// Verify no existing KonX affiliate for this user.
		$existing_aff = self::konx_affiliate_for_user( $wp_user_id );
		if ( $existing_aff ) {
			self::mark_ledger_failed(
				$ledger_row->id,
				'user_already_has_affiliate',
				sprintf( 'WP user #%d already has KonX affiliate #%d.', $wp_user_id, $existing_aff )
			);

			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => 'link_wp',
				'status'            => 'failed',
				'wp_user_id'        => $wp_user_id,
				'wp_user_created'   => false,
				'affiliate_id'      => null,
				'affiliate_created' => false,
				'error_code'        => 'user_already_has_affiliate',
				'error_message'     => sprintf( 'WP user #%d already has KonX affiliate #%d.', $wp_user_id, $existing_aff ),
				'attempt_count'     => (int) $ledger_row->attempt_count,
				'started_at'        => $ledger_row->started_at,
				'completed_at'      => null,
			);
		}

		// Insert KonX affiliate.
		$aff_args = array(
			'external_id' => 'po10_' . $source_record_id,
		);
		if ( ! empty( $team_name ) ) {
			$aff_args['referral_code'] = $team_name;
		}
		$aff_args['notes'] = sprintf( 'Migrated from PO10 (ID: %d) via Phase 24C-6D (link_wp).', $source_record_id );

		$parent_id = self::resolve_parent_affiliate( $sponsor_team );
		if ( $parent_id ) {
			$aff_args['parent_affiliate_id'] = $parent_id;
		}

		$affiliate_id = self::insert_konx_affiliate( $wp_user_id, $affiliate_type, $aff_args );

		if ( is_wp_error( $affiliate_id ) || false === $affiliate_id ) {
			$err_msg = is_wp_error( $affiliate_id ) ? $affiliate_id->get_error_message() : 'insert_konx_affiliate failed';
			self::mark_ledger_failed( $ledger_row->id, 'affiliate_insert_failed', $err_msg );
			// Do NOT delete the pre-existing WP user.

			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => 'link_wp',
				'status'            => 'failed',
				'wp_user_id'        => $wp_user_id,
				'wp_user_created'   => false,
				'affiliate_id'      => null,
				'affiliate_created' => false,
				'error_code'        => 'affiliate_insert_failed',
				'error_message'     => sanitize_text_field( mb_substr( $err_msg, 0, 500 ) ),
				'attempt_count'     => (int) $ledger_row->attempt_count,
				'started_at'        => $ledger_row->started_at,
				'completed_at'      => null,
			);
		}

		$affiliate_id  = (int) $affiliate_id;
		$ledger_result = self::mark_ledger_completed( $ledger_row->id, $wp_user_id, false, $affiliate_id, true );
		if ( is_wp_error( $ledger_result ) ) {
			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => 'link_wp',
				'status'            => 'ledger_persist_failed',
				'wp_user_id'        => $wp_user_id,
				'wp_user_created'   => false,
				'affiliate_id'      => $affiliate_id,
				'affiliate_created' => true,
				'error_code'        => 'ledger_persist_failed',
				'error_message'     => sanitize_text_field( mb_substr( $ledger_result->get_error_message(), 0, 500 ) ),
				'attempt_count'     => (int) $ledger_row->attempt_count,
				'started_at'        => $ledger_row->started_at,
				'completed_at'      => null,
			);
		}

		return array(
			'session_uuid'      => $session_uuid,
			'plan_record_id'    => $plan_record_id,
			'source_record_id'  => $source_record_id,
			'action'            => 'link_wp',
			'status'            => 'completed',
			'wp_user_id'        => $wp_user_id,
			'wp_user_created'   => false,
			'affiliate_id'      => $affiliate_id,
			'affiliate_created' => true,
			'error_code'        => null,
			'error_message'     => null,
			'attempt_count'     => (int) $ledger_row->attempt_count,
			'started_at'        => $ledger_row->started_at,
			'completed_at'      => $ledger_result['completed_at'],
		);
	}

	/**
	 * Execute a 'link_ca' record: create KonX affiliate for existing CA user.
	 *
	 * @param string $session_uuid UUID of the session.
	 * @param object $plan_row     Plan record from DB.
	 * @param object $ledger_row   Ledger record from DB.
	 * @return array Result.
	 */
	private static function execute_link_ca_record( $session_uuid, $plan_row, $ledger_row ) {
		global $wpdb;

		$plan_record_id       = (int) $plan_row->id;
		$source_record_id     = (int) $plan_row->source_record_id;
		$coupon_affiliate_id  = (int) $plan_row->coupon_affiliate_id;
		$wp_user_id_frozen    = (int) $plan_row->wp_user_id;
		$affiliate_type       = sanitize_text_field( (string) $plan_row->affiliate_type ) ?: 'sales_agent';
		$team_name            = sanitize_text_field( (string) ( $plan_row->team_name ?? '' ) );
		$sponsor_team         = sanitize_text_field( (string) ( $plan_row->sponsor_team_name ?? '' ) );
		$ca_table             = $wpdb->prefix . self::CA_TABLE;

		// Load the CA row (SELECT only — never modify).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ca_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, userid FROM {$ca_table} WHERE id = %d LIMIT 1",
				$coupon_affiliate_id
			)
		);

		if ( ! $ca_row ) {
			self::mark_ledger_failed(
				$ledger_row->id,
				'stale_ca_row_not_found',
				sprintf( 'CA row #%d no longer exists.', $coupon_affiliate_id )
			);

			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => 'link_ca',
				'status'            => 'failed',
				'wp_user_id'        => null,
				'wp_user_created'   => false,
				'affiliate_id'      => null,
				'affiliate_created' => false,
				'error_code'        => 'stale_ca_row_not_found',
				'error_message'     => sprintf( 'Coupon Affiliate row #%d no longer exists.', $coupon_affiliate_id ),
				'attempt_count'     => (int) $ledger_row->attempt_count,
				'started_at'        => $ledger_row->started_at,
				'completed_at'      => null,
			);
		}

		$ca_userid = (int) $ca_row->userid;

		// Verify CA userid still matches frozen wp_user_id.
		if ( $wp_user_id_frozen > 0 && $ca_userid !== $wp_user_id_frozen ) {
			self::mark_ledger_failed(
				$ledger_row->id,
				'ca_user_mismatch',
				sprintf( 'CA#%d userid changed. Frozen=%d Actual=%d.', $coupon_affiliate_id, $wp_user_id_frozen, $ca_userid )
			);

			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => 'link_ca',
				'status'            => 'failed',
				'wp_user_id'        => null,
				'wp_user_created'   => false,
				'affiliate_id'      => null,
				'affiliate_created' => false,
				'error_code'        => 'ca_user_mismatch',
				'error_message'     => sprintf(
					'CA#%d userid changed after snapshot. Expected=%d Got=%d.',
					$coupon_affiliate_id,
					$wp_user_id_frozen,
					$ca_userid
				),
				'attempt_count'     => (int) $ledger_row->attempt_count,
				'started_at'        => $ledger_row->started_at,
				'completed_at'      => null,
			);
		}

		$wp_user_id = $wp_user_id_frozen > 0 ? $wp_user_id_frozen : $ca_userid;

		// Verify WP user exists.
		if ( ! self::wp_user_exists( $wp_user_id ) ) {
			self::mark_ledger_failed(
				$ledger_row->id,
				'stale_wp_user_not_found',
				sprintf( 'WP user #%d no longer exists.', $wp_user_id )
			);

			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => 'link_ca',
				'status'            => 'failed',
				'wp_user_id'        => null,
				'wp_user_created'   => false,
				'affiliate_id'      => null,
				'affiliate_created' => false,
				'error_code'        => 'stale_wp_user_not_found',
				'error_message'     => sprintf( 'WP user #%d no longer exists (CA: #%d).', $wp_user_id, $coupon_affiliate_id ),
				'attempt_count'     => (int) $ledger_row->attempt_count,
				'started_at'        => $ledger_row->started_at,
				'completed_at'      => null,
			);
		}

		// Verify no existing KonX affiliate.
		$existing_aff = self::konx_affiliate_for_user( $wp_user_id );
		if ( $existing_aff ) {
			self::mark_ledger_failed(
				$ledger_row->id,
				'user_already_has_affiliate',
				sprintf( 'WP user #%d already has KonX affiliate #%d.', $wp_user_id, $existing_aff )
			);

			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => 'link_ca',
				'status'            => 'failed',
				'wp_user_id'        => $wp_user_id,
				'wp_user_created'   => false,
				'affiliate_id'      => null,
				'affiliate_created' => false,
				'error_code'        => 'user_already_has_affiliate',
				'error_message'     => sprintf( 'WP user #%d already has KonX affiliate #%d.', $wp_user_id, $existing_aff ),
				'attempt_count'     => (int) $ledger_row->attempt_count,
				'started_at'        => $ledger_row->started_at,
				'completed_at'      => null,
			);
		}

		// Insert KonX affiliate.
		$aff_args = array(
			'external_id' => 'po10_' . $source_record_id,
		);
		if ( ! empty( $team_name ) ) {
			$aff_args['referral_code'] = $team_name;
		}
		$aff_args['notes'] = sprintf( 'Migrated from PO10 (ID: %d) via Phase 24C-6D (link_ca, CA#%d).', $source_record_id, $coupon_affiliate_id );

		$parent_id = self::resolve_parent_affiliate( $sponsor_team );
		if ( $parent_id ) {
			$aff_args['parent_affiliate_id'] = $parent_id;
		}

		$affiliate_id = self::insert_konx_affiliate( $wp_user_id, $affiliate_type, $aff_args );

		if ( is_wp_error( $affiliate_id ) || false === $affiliate_id ) {
			$err_msg = is_wp_error( $affiliate_id ) ? $affiliate_id->get_error_message() : 'insert_konx_affiliate failed';
			self::mark_ledger_failed( $ledger_row->id, 'affiliate_insert_failed', $err_msg );
			// CA table: SELECT only — never modify.

			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => 'link_ca',
				'status'            => 'failed',
				'wp_user_id'        => $wp_user_id,
				'wp_user_created'   => false,
				'affiliate_id'      => null,
				'affiliate_created' => false,
				'error_code'        => 'affiliate_insert_failed',
				'error_message'     => sanitize_text_field( mb_substr( $err_msg, 0, 500 ) ),
				'attempt_count'     => (int) $ledger_row->attempt_count,
				'started_at'        => $ledger_row->started_at,
				'completed_at'      => null,
			);
		}

		$affiliate_id  = (int) $affiliate_id;
		$ledger_result = self::mark_ledger_completed( $ledger_row->id, $wp_user_id, false, $affiliate_id, true );
		if ( is_wp_error( $ledger_result ) ) {
			return array(
				'session_uuid'      => $session_uuid,
				'plan_record_id'    => $plan_record_id,
				'source_record_id'  => $source_record_id,
				'action'            => 'link_ca',
				'status'            => 'ledger_persist_failed',
				'wp_user_id'        => $wp_user_id,
				'wp_user_created'   => false,
				'affiliate_id'      => $affiliate_id,
				'affiliate_created' => true,
				'error_code'        => 'ledger_persist_failed',
				'error_message'     => sanitize_text_field( mb_substr( $ledger_result->get_error_message(), 0, 500 ) ),
				'attempt_count'     => (int) $ledger_row->attempt_count,
				'started_at'        => $ledger_row->started_at,
				'completed_at'      => null,
			);
		}

		return array(
			'session_uuid'      => $session_uuid,
			'plan_record_id'    => $plan_record_id,
			'source_record_id'  => $source_record_id,
			'action'            => 'link_ca',
			'status'            => 'completed',
			'wp_user_id'        => $wp_user_id,
			'wp_user_created'   => false,
			'affiliate_id'      => $affiliate_id,
			'affiliate_created' => true,
			'error_code'        => null,
			'error_message'     => null,
			'attempt_count'     => (int) $ledger_row->attempt_count,
			'started_at'        => $ledger_row->started_at,
			'completed_at'      => $ledger_result['completed_at'],
		);
	}

	// ------------------------------------------------------------------
	// Ledger State Transitions
	// ------------------------------------------------------------------

	/**
	 * Mark ledger row as completed.
	 *
	 * @param int  $ledger_row_id   Ledger table primary key.
	 * @param int  $wp_user_id      WP user ID.
	 * @param bool $wp_user_created True if WP user was created this attempt.
	 * @param int  $affiliate_id    KonX affiliate ID.
	 * @param bool $affiliate_created True if affiliate was created this attempt.
	 * @return array{ok:true,completed_at:string}|WP_Error Result on success; WP_Error if DB write failed.
	 */
	private static function mark_ledger_completed( $ledger_row_id, $wp_user_id, $wp_user_created, $affiliate_id, $affiliate_created ) {
		global $wpdb;

		// Test-only injection: simulate completed-write DB failure.
		if ( self::$test_completed_update_fail ) {
			return new \WP_Error( 'test_injected_ledger_fail', 'Test-injected completed ledger write failure (Phase 24C-6E).' );
		}

		$table = $wpdb->prefix . Konx_Migration_Execution_Ledger::TABLE;
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$table,
			array(
				'status'            => 'completed',
				'wp_user_id'        => $wp_user_id ? absint( $wp_user_id ) : null,
				'wp_user_created'   => $wp_user_created ? 1 : 0,
				'affiliate_id'      => $affiliate_id ? absint( $affiliate_id ) : null,
				'affiliate_created' => $affiliate_created ? 1 : 0,
				'completed_at'      => $now,
				'updated_at'        => $now,
			),
			array( 'id' => absint( $ledger_row_id ) ),
			null,
			array( '%d' )
		);

		if ( false === $result || 0 === (int) $result ) {
			return new \WP_Error(
				'ledger_persist_failed',
				sprintf(
					'Failed to mark ledger row #%d as completed (rows_affected=%s).',
					absint( $ledger_row_id ),
					json_encode( $result )
				)
			);
		}

		return array( 'ok' => true, 'completed_at' => $now );
	}

	/**
	 * Mark ledger row as failed.
	 *
	 * @param int    $ledger_row_id Ledger table primary key.
	 * @param string $error_code    Machine-readable error code.
	 * @param string $error_message Human-readable error message.
	 * @return true|WP_Error True on success; WP_Error if DB write failed.
	 */
	private static function mark_ledger_failed( $ledger_row_id, $error_code, $error_message ) {
		global $wpdb;

		// Test-only injection: simulate failed-write DB failure.
		if ( self::$test_failed_update_fail ) {
			return new \WP_Error( 'test_injected_ledger_fail', 'Test-injected failed ledger write failure (Phase 24C-6E).' );
		}

		$table = $wpdb->prefix . Konx_Migration_Execution_Ledger::TABLE;
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$table,
			array(
				'status'        => 'failed',
				'error_code'    => sanitize_text_field( mb_substr( (string) $error_code, 0, 50 ) ),
				'error_message' => sanitize_text_field( mb_substr( (string) $error_message, 0, 500 ) ),
				'completed_at'  => $now,
				'updated_at'    => $now,
			),
			array( 'id' => absint( $ledger_row_id ) ),
			null,
			array( '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error(
				'ledger_persist_failed',
				sprintf( 'Failed to mark ledger row #%d as failed.', absint( $ledger_row_id ) )
			);
		}

		return true;
	}

	/**
	 * Mark ledger row as partial (WP user created, affiliate failed, compensation refused).
	 *
	 * @param int    $ledger_row_id   Ledger table primary key.
	 * @param int    $wp_user_id      WP user ID that was created.
	 * @param bool   $wp_user_created True.
	 * @param int    $affiliate_id    null (affiliate was not created).
	 * @param bool   $affiliate_created False.
	 * @param string $error_code      Machine-readable error code.
	 * @param string $error_message   Human-readable error message.
	 * @return true|WP_Error True on success; WP_Error if DB write failed.
	 */
	private static function mark_ledger_partial( $ledger_row_id, $wp_user_id, $wp_user_created, $affiliate_id, $affiliate_created, $error_code, $error_message ) {
		global $wpdb;

		// Test-only injection: simulate partial-write DB failure.
		if ( self::$test_partial_update_fail ) {
			return new \WP_Error( 'test_injected_ledger_fail', 'Test-injected partial ledger write failure (Phase 24C-6E).' );
		}

		$table = $wpdb->prefix . Konx_Migration_Execution_Ledger::TABLE;
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$table,
			array(
				'status'            => 'partial',
				'wp_user_id'        => $wp_user_id ? absint( $wp_user_id ) : null,
				'wp_user_created'   => $wp_user_created ? 1 : 0,
				'affiliate_id'      => $affiliate_id ? absint( $affiliate_id ) : null,
				'affiliate_created' => $affiliate_created ? 1 : 0,
				'error_code'        => sanitize_text_field( mb_substr( (string) $error_code, 0, 50 ) ),
				'error_message'     => sanitize_text_field( mb_substr( (string) $error_message, 0, 500 ) ),
				'completed_at'      => $now,
				'updated_at'        => $now,
			),
			array( 'id' => absint( $ledger_row_id ) ),
			null,
			array( '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error(
				'ledger_persist_failed',
				sprintf( 'Failed to mark ledger row #%d as partial.', absint( $ledger_row_id ) )
			);
		}

		return true;
	}

	/**
	 * Mark ledger row as skipped.
	 *
	 * @param int    $ledger_row_id Ledger table primary key.
	 * @param string $error_code    Reason code.
	 */
	private static function mark_ledger_skipped( $ledger_row_id, $error_code = 'non_actionable' ) {
		global $wpdb;

		$table = $wpdb->prefix . Konx_Migration_Execution_Ledger::TABLE;
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$table,
			array(
				'status'       => 'skipped',
				'error_code'   => sanitize_text_field( mb_substr( (string) $error_code, 0, 50 ) ),
				'completed_at' => $now,
				'updated_at'   => $now,
			),
			array( 'id' => absint( $ledger_row_id ) ),
			null,
			array( '%d' )
		);
	}

	// ------------------------------------------------------------------
	// Revalidation Helpers
	// ------------------------------------------------------------------

	/**
	 * Run revalidation for a test execution session (frozen status).
	 *
	 * The FMP parity check needs an override because the test session hash
	 * won't match the production FMP in wp_options. We provide the test session's
	 * own plan records as the "live FMP" so the parity check passes and
	 * per-record checks can proceed.
	 *
	 * @param string $session_uuid The session UUID.
	 * @param array  $plan_records Already-loaded plan records.
	 * @return array Revalidation result.
	 */
	private static function run_revalidation_for_session( $session_uuid, $plan_records ) {
		// Build a minimal decisions array from plan records for FMP override.
		$decisions = array();
		foreach ( $plan_records as $r ) {
			$source_payload = ! empty( $r->source_payload ) ? (array) json_decode( $r->source_payload, true ) : array();
			$decisions[] = array(
				'decision'      => $r->action,
				'po10_id'       => (int) $r->source_record_id,
				'email'         => $r->source_email,
				'team_name'     => $r->team_name ?? '',
				'affiliate_type' => $r->affiliate_type ?? 'sales_agent',
				'wp_user_id'    => isset( $r->wp_user_id ) && $r->wp_user_id ? (int) $r->wp_user_id : null,
				'ca_id'         => isset( $r->coupon_affiliate_id ) && $r->coupon_affiliate_id ? (int) $r->coupon_affiliate_id : null,
				'sponsor'       => $r->sponsor_team_name ?? '',
				'first_name'    => $source_payload['first_name'] ?? '',
				'last_name'     => $source_payload['last_name'] ?? '',
				'match_method'  => '',
				'confidence'    => '',
				'sponsor_status' => '',
				'val_status'    => '',
			);
		}

		// Set FMP override so the revalidator sees our test decisions as live FMP.
		if ( function_exists( 'test_set_option_override' ) ) {
			test_set_option_override(
				'konx_migration_state',
				array( 'final_migration_plan' => array( 'decisions' => $decisions ) )
			);
		}

		$result = Konx_Migration_Revalidator::revalidate( $session_uuid );

		if ( function_exists( 'test_clear_option_override' ) ) {
			test_clear_option_override( 'konx_migration_state' );
		}

		return $result;
	}

	/**
	 * Check a single plan record against live state.
	 *
	 * Minimal per-record revalidation inline (mirrors Revalidator checks).
	 *
	 * @param object $plan_row Plan record.
	 * @return array { status => 'pass'|'stale'|'conflict', message => string }.
	 */
	private static function check_single_record( $plan_row ) {
		global $wpdb;

		$action     = (string) $plan_row->action;
		$po10_id    = (int) $plan_row->source_record_id;
		$email      = strtolower( trim( (string) $plan_row->source_email ) );
		$konx_table = $wpdb->prefix . self::KONX_AFF_TABLE;

		switch ( $action ) {
			case 'create':
				// Check if email now exists.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$uid = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->users} WHERE LOWER(user_email) = %s LIMIT 1",
						$email
					)
				);
				if ( $uid ) {
					// Check if that user has an affiliate.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$aff = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$konx_table} WHERE user_id = %d LIMIT 1", $uid ) );
					if ( $aff ) {
						return array( 'status' => 'conflict', 'message' => "Email {$email} (WP#{$uid}) has affiliate #{$aff}." );
					}
					return array( 'status' => 'stale', 'message' => "Email {$email} appeared as WP user #{$uid} after snapshot." );
				}
				// Check referral code.
				$team_name = trim( (string) ( $plan_row->team_name ?? '' ) );
				if ( '' !== $team_name ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$code_owner = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$konx_table} WHERE referral_code = %s LIMIT 1", $team_name ) );
					if ( $code_owner ) {
						return array( 'status' => 'conflict', 'message' => "Referral code '{$team_name}' taken by affiliate #{$code_owner}." );
					}
				}
				return array( 'status' => 'pass', 'message' => 'OK' );

			case 'link_wp':
				$wp_user_id = (int) $plan_row->wp_user_id;
				if ( ! $wp_user_id ) {
					return array( 'status' => 'conflict', 'message' => 'link_wp record has null wp_user_id.' );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d LIMIT 1", $wp_user_id ) );
				if ( ! $exists ) {
					return array( 'status' => 'stale', 'message' => "WP user #{$wp_user_id} deleted after snapshot." );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$aff = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$konx_table} WHERE user_id = %d LIMIT 1", $wp_user_id ) );
				if ( $aff ) {
					return array( 'status' => 'conflict', 'message' => "WP user #{$wp_user_id} gained affiliate #{$aff}." );
				}
				return array( 'status' => 'pass', 'message' => 'OK' );

			case 'link_ca':
				$ca_id      = (int) $plan_row->coupon_affiliate_id;
				$wp_user_id = (int) $plan_row->wp_user_id;
				$ca_table   = $wpdb->prefix . self::CA_TABLE;
				if ( ! $ca_id ) {
					return array( 'status' => 'conflict', 'message' => 'link_ca record has null coupon_affiliate_id.' );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ca_row = $wpdb->get_row( $wpdb->prepare( "SELECT id, userid FROM {$ca_table} WHERE id = %d LIMIT 1", $ca_id ) );
				if ( ! $ca_row ) {
					return array( 'status' => 'stale', 'message' => "CA row #{$ca_id} deleted after snapshot." );
				}
				if ( $wp_user_id && (int) $ca_row->userid !== $wp_user_id ) {
					return array( 'status' => 'conflict', 'message' => "CA#{$ca_id} userid changed. Expected={$wp_user_id} Got={$ca_row->userid}." );
				}
				$eff_uid = $wp_user_id ?: (int) $ca_row->userid;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$user_exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d LIMIT 1", $eff_uid ) );
				if ( ! $user_exists ) {
					return array( 'status' => 'stale', 'message' => "WP user #{$eff_uid} deleted after snapshot." );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$aff = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$konx_table} WHERE user_id = %d LIMIT 1", $eff_uid ) );
				if ( $aff ) {
					return array( 'status' => 'conflict', 'message' => "WP user #{$eff_uid} gained affiliate #{$aff}." );
				}
				return array( 'status' => 'pass', 'message' => 'OK' );

			default:
				return array( 'status' => 'pass', 'message' => 'Non-actionable action — no live-state check.' );
		}
	}

	/**
	 * Extract the first critical message from a revalidation result.
	 *
	 * @param array $revalidation Revalidation result.
	 * @return string First critical message or generic fallback.
	 */
	private static function extract_first_critical_message( $revalidation ) {
		if ( ! empty( $revalidation['session_checks'] ) ) {
			foreach ( $revalidation['session_checks'] as $check ) {
				if ( ! empty( $check['critical'] ) && 'critical' === $check['status'] ) {
					return (string) ( $check['message'] ?? 'Unknown critical failure.' );
				}
			}
		}
		return 'Session-level critical failure.';
	}

	// ------------------------------------------------------------------
	// WP API Abstraction Layer
	// ------------------------------------------------------------------
	// These methods call standard WordPress functions. In the test environment
	// they call the stubs defined in bootstrap-integration.php.

	/**
	 * Check if email exists in wp_users.
	 *
	 * @param string $email Email address.
	 * @return bool
	 */
	private static function wp_email_exists( $email ) {
		global $wpdb;
		if ( function_exists( 'email_exists' ) ) {
			return (bool) email_exists( $email );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE LOWER(user_email) = %s LIMIT 1", strtolower( $email ) )
		);
	}

	/**
	 * Check if username exists in wp_users.
	 *
	 * @param string $username Username.
	 * @return bool
	 */
	private static function wp_username_exists( $username ) {
		global $wpdb;
		if ( function_exists( 'username_exists' ) ) {
			return (bool) username_exists( $username );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login = %s LIMIT 1", $username )
		);
	}

	/**
	 * Sanitize a username string.
	 *
	 * @param string $username Raw username.
	 * @return string Sanitized username.
	 */
	private static function sanitize_username( $username ) {
		if ( function_exists( 'sanitize_user' ) ) {
			return sanitize_user( $username, true );
		}
		return preg_replace( '/[^a-zA-Z0-9_\-\.]/', '', strtolower( (string) $username ) );
	}

	/**
	 * Generate a random integer.
	 *
	 * @param int $min Minimum value.
	 * @param int $max Maximum value.
	 * @return int Random integer.
	 */
	private static function wp_random_int( $min, $max ) {
		if ( function_exists( 'wp_rand' ) ) {
			return wp_rand( $min, $max );
		}
		return random_int( $min, $max );
	}

	/**
	 * Generate a strong random password.
	 *
	 * @param int  $length  Password length.
	 * @param bool $special Include special chars.
	 * @param bool $extra   Include extra special chars.
	 * @return string Password.
	 */
	private static function wp_generate_password_safe( $length = 24, $special = true, $extra = true ) {
		if ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( $length, $special, $extra );
		}
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		if ( $special ) {
			$chars .= '!@#$%^&*';
		}
		if ( $extra ) {
			$chars .= '()-_';
		}
		$pw = '';
		$max = strlen( $chars ) - 1;
		for ( $i = 0; $i < $length; $i++ ) {
			$pw .= $chars[ random_int( 0, $max ) ];
		}
		return $pw;
	}

	/**
	 * Check if a WP user exists by ID.
	 *
	 * @param int $user_id WP user ID.
	 * @return bool
	 */
	private static function wp_user_exists( $user_id ) {
		global $wpdb;
		if ( function_exists( 'get_user_by' ) ) {
			return (bool) get_user_by( 'id', $user_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d LIMIT 1", $user_id )
		);
	}

	/**
	 * Get the KonX affiliate ID for a WP user, or null.
	 *
	 * @param int $user_id WP user ID.
	 * @return int|null Affiliate ID or null.
	 */
	private static function konx_affiliate_for_user( $user_id ) {
		global $wpdb;
		$konx_table = $wpdb->prefix . self::KONX_AFF_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$aff_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$konx_table} WHERE user_id = %d LIMIT 1", $user_id ) );
		return $aff_id ? (int) $aff_id : null;
	}

	/**
	 * Create a WP user directly via $wpdb in the test environment.
	 *
	 * In real WordPress: delegates to wp_create_user().
	 * In test env: delegates to wp_create_user() stub.
	 *
	 * @param string $login    Username.
	 * @param string $password Password.
	 * @param string $email    Email.
	 * @return int|WP_Error User ID or WP_Error.
	 */
	private static function create_wp_user_direct( $login, $password, $email ) {
		if ( function_exists( 'wp_create_user' ) ) {
			return wp_create_user( $login, $password, $email );
		}
		// Should not reach here in production (wp_create_user always available).
		return new \WP_Error( 'no_wp_create_user', 'wp_create_user() is not available.' );
	}

	/**
	 * Update user meta.
	 *
	 * @param int    $user_id    WP user ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	private static function update_user_meta_direct( $user_id, $meta_key, $meta_value ) {
		if ( function_exists( 'update_user_meta' ) ) {
			update_user_meta( $user_id, $meta_key, $meta_value );
			return;
		}
		// Test environment: use $wpdb directly.
		global $wpdb;
		// Check if meta already exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
			$user_id, $meta_key
		) );
		if ( $existing ) {
			$wpdb->update( $wpdb->usermeta, array( 'meta_value' => (string) $meta_value ), array( 'user_id' => $user_id, 'meta_key' => $meta_key ) );
		} else {
			$wpdb->insert( $wpdb->usermeta, array( 'user_id' => $user_id, 'meta_key' => $meta_key, 'meta_value' => (string) $meta_value ) );
		}
	}

	/**
	 * Insert a KonX affiliate record.
	 *
	 * Delegates to Konx_Affiliate_Manager::create_affiliate_profile() which
	 * handles role assignment, referral code generation, and user meta writes.
	 *
	 * @param int    $user_id        WP user ID.
	 * @param string $affiliate_type Affiliate type.
	 * @param array  $args           Additional args.
	 * @return int|WP_Error Affiliate ID or WP_Error.
	 */
	private static function insert_konx_affiliate( $user_id, $affiliate_type, $args ) {
		// Test-only injection: return failure immediately (compensation/partial test).
		// Only active when KONX_MIGRATION_TEST_EXECUTION_ENABLED is true.
		if ( self::$test_affiliate_insert_fail ) {
			return new \WP_Error( 'test_injected_fail', 'Test-injected affiliate insert failure (Phase 24C-6D).' );
		}

		if ( class_exists( 'Konx_Affiliate_Manager' ) ) {
			return Konx_Affiliate_Manager::create_affiliate_profile( $user_id, $affiliate_type, $args );
		}

		// Fallback: direct insert (test environment without full plugin loaded).
		global $wpdb;
		$table = $wpdb->prefix . self::KONX_AFF_TABLE;
		$now   = current_time( 'mysql', true );

		$referral_code = '';
		if ( ! empty( $args['referral_code'] ) ) {
			$referral_code = sanitize_text_field( $args['referral_code'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$taken = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE referral_code = %s LIMIT 1", $referral_code ) );
			if ( $taken ) {
				return new \WP_Error( 'duplicate_code', sprintf( 'Referral code "%s" already in use.', $referral_code ) );
			}
		} else {
			$referral_code = self::generate_referral_code_direct();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d LIMIT 1", $user_id ) );
		if ( $existing ) {
			return new \WP_Error( 'duplicate_profile', 'User already has an affiliate profile.' );
		}

		$data = array(
			'user_id'         => (int) $user_id,
			'affiliate_type'  => sanitize_text_field( $affiliate_type ),
			'referral_code'   => $referral_code,
			'status'          => 'active',
			'completed_sales' => 0,
			'cached_balance'  => 0.00,
			'registered_at'   => $now,
			'updated_at'      => $now,
		);

		if ( ! empty( $args['parent_affiliate_id'] ) ) {
			$data['parent_affiliate_id'] = absint( $args['parent_affiliate_id'] );
		}
		if ( ! empty( $args['external_id'] ) ) {
			$data['external_id'] = sanitize_text_field( mb_substr( $args['external_id'], 0, 50 ) );
		}
		if ( ! empty( $args['notes'] ) ) {
			$data['notes'] = (string) $args['notes'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( $table, $data );
		if ( false === $ok ) {
			return new \WP_Error( 'db_insert_failed', 'Failed to insert affiliate: ' . $wpdb->last_error );
		}

		$affiliate_id = (int) $wpdb->insert_id;

		// Write user meta for lookup.
		self::update_user_meta_direct( $user_id, 'konx_affiliate_id', (string) $affiliate_id );
		self::update_user_meta_direct( $user_id, 'konx_affiliate_type', $affiliate_type );
		self::update_user_meta_direct( $user_id, 'konx_referral_code', $referral_code );

		return $affiliate_id;
	}

	/**
	 * Generate an 8-character referral code (test fallback when Affiliate Manager unavailable).
	 *
	 * @return string
	 */
	private static function generate_referral_code_direct() {
		global $wpdb;

		$table      = $wpdb->prefix . self::KONX_AFF_TABLE;
		$characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$length     = 8;

		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$code  = '';
			$bytes = random_bytes( $length );
			for ( $j = 0; $j < $length; $j++ ) {
				$code .= $characters[ ord( $bytes[ $j ] ) % strlen( $characters ) ];
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE referral_code = %s", $code ) );
			if ( '0' === (string) $exists ) {
				return $code;
			}
		}

		return $code . substr( (string) time(), -4 );
	}

	/**
	 * Attempt compensating delete of a WP user created this attempt.
	 *
	 * SAFETY CONTRACT: Only deletes when ALL of the following are true:
	 *   1. The user still exists in wp_users.
	 *   2. No KonX affiliate is attached (race condition guard).
	 *   3. konx_source user meta == 'migration' (ownership claim present).
	 *   4. konx_migrated_po10_id user meta matches $source_record_id (exact record match).
	 *
	 * Conditions 3 and 4 prevent accidental deletion of a pre-existing WP user
	 * that shares the same user_id via an ID-recycle race. The meta keys are
	 * written in execute_create_record() immediately after wp_create_user() and
	 * before affiliate creation, so they are always present when compensation runs.
	 *
	 * Test-only injection: set_test_skip_ownership_meta(true) prevents the meta
	 * writes, making ownership verification fail here and forcing partial state.
	 *
	 * @param int $user_id          WP user ID to delete.
	 * @param int $source_record_id PO10 source record ID (ownership proof).
	 * @return bool True if compensated (deleted), false if kept (partial state).
	 */
	private static function compensate_delete_wp_user( $user_id, $source_record_id ) {
		global $wpdb;

		$user_id          = (int) $user_id;
		$source_record_id = (int) $source_record_id;

		if ( ! $user_id ) {
			return false;
		}

		// Test-only injection: block compensation to force partial state.
		// Only active when KONX_MIGRATION_TEST_EXECUTION_ENABLED is true.
		if ( self::$test_block_compensation ) {
			return false;
		}

		// Verify user still exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d LIMIT 1", $user_id ) );
		if ( ! $exists ) {
			return true; // Already gone — compensation is a no-op success.
		}

		// Do not delete if an affiliate somehow got attached (race condition guard).
		$aff = self::konx_affiliate_for_user( $user_id );
		if ( $aff ) {
			return false; // Keep — partial state.
		}

		// OWNERSHIP VERIFICATION: confirm this executor created the user.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_source = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'konx_source' LIMIT 1",
			$user_id
		) );
		if ( 'migration' !== (string) $meta_source ) {
			// No migration ownership meta — not safe to delete.
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_po10_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'konx_migrated_po10_id' LIMIT 1",
			$user_id
		) );
		if ( (string) $source_record_id !== (string) $meta_po10_id ) {
			// PO10 ID mismatch — not our record.
			return false;
		}

		// All safety checks passed. Delete user rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $user_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$del_user = $wpdb->delete( $wpdb->users, array( 'ID' => $user_id ), array( '%d' ) );

		return false !== $del_user;
	}

	/**
	 * Resolve parent affiliate from sponsor team name.
	 *
	 * @param string $sponsor_team_name Sponsor referral code.
	 * @return int|null Parent affiliate ID or null.
	 */
	private static function resolve_parent_affiliate( $sponsor_team_name ) {
		$sponsor_team_name = trim( (string) $sponsor_team_name );
		if ( '' === $sponsor_team_name ) {
			return null;
		}

		global $wpdb;
		$konx_table = $wpdb->prefix . self::KONX_AFF_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$parent_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$konx_table} WHERE referral_code = %s LIMIT 1", $sponsor_team_name )
		);

		return $parent_id ? (int) $parent_id : null;
	}

	/**
	 * Suppress or restore WP new-user notifications.
	 *
	 * @param bool $suppress True to suppress, false to restore.
	 */
	private static function suppress_user_notifications( $suppress ) {
		if ( function_exists( 'add_filter' ) && function_exists( 'remove_filter' ) ) {
			if ( $suppress ) {
				add_filter( 'wp_send_new_user_notification_to_user',  '__return_false' );
				add_filter( 'wp_send_new_user_notification_to_admin', '__return_false' );
			} else {
				remove_filter( 'wp_send_new_user_notification_to_user',  '__return_false' );
				remove_filter( 'wp_send_new_user_notification_to_admin', '__return_false' );
			}
		}
		// In test environment: add_filter/remove_filter are no-ops, which is correct.
	}

	/**
	 * Get affected rows from the last query.
	 *
	 * @param object $wpdb $wpdb instance.
	 * @return int Affected rows count.
	 */
	private static function get_affected_rows( $wpdb ) {
		// Konx_Test_WPDB exposes affected_rows via the query result, but
		// $wpdb->update() already returns affected_rows. For the raw UPDATE query
		// we ran via $wpdb->query(), we need to get it from the connection.
		// The Konx_Test_WPDB class stores affected_rows in its update() method
		// return value. For our raw query(), we call this helper.
		//
		// In real WordPress, $wpdb->rows_affected holds the count.
		if ( property_exists( $wpdb, 'rows_affected' ) ) {
			return (int) $wpdb->rows_affected;
		}
		// For our Konx_Test_WPDB: we need a way to get it.
		// The query() method just returns true/false. We need to check via
		// the mysqli connection, which isn't directly accessible.
		// Workaround: check the specific method available on Konx_Test_WPDB.
		if ( method_exists( $wpdb, 'get_affected_rows' ) ) {
			return $wpdb->get_affected_rows();
		}
		// If none available, return null (caller must handle).
		return -1;
	}

	// ------------------------------------------------------------------
	// Error Result Builder
	// ------------------------------------------------------------------

	/**
	 * Build a structured error result array.
	 *
	 * @param string $session_uuid   Session UUID.
	 * @param int    $plan_record_id Plan record ID.
	 * @param string $source_record_id Source record ID (may be empty).
	 * @param string $error_code     Machine-readable error code.
	 * @param string $error_message  Sanitized error message.
	 * @return array
	 */
	private static function error_result( $session_uuid, $plan_record_id, $source_record_id, $error_code, $error_message ) {
		return array(
			'session_uuid'      => $session_uuid,
			'plan_record_id'    => (int) $plan_record_id,
			'source_record_id'  => (int) $source_record_id,
			'action'            => '',
			'status'            => 'failed',
			'wp_user_id'        => null,
			'wp_user_created'   => false,
			'affiliate_id'      => null,
			'affiliate_created' => false,
			'error_code'        => sanitize_text_field( mb_substr( (string) $error_code, 0, 50 ) ),
			'error_message'     => sanitize_text_field( mb_substr( (string) $error_message, 0, 500 ) ),
			'attempt_count'     => 0,
			'started_at'        => null,
			'completed_at'      => null,
		);
	}
}
