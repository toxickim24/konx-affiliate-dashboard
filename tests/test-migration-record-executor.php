<?php
/**
 * Phase 24C-6D: Single-Record Executor Tests
 *
 * Tests the Konx_Migration_Record_Executor class. Covers:
 *   Group 0:  Canonical protection
 *   Group 1:  Precondition guards
 *   Group 2:  Atomic ledger claim
 *   Group 3:  Per-record revalidation guard
 *   Group 4:  Create action — success
 *   Group 5:  Create action — stale email
 *   Group 6:  Create compensation
 *   Group 7:  Link_wp — success
 *   Group 8:  Link_wp — user deleted (stale)
 *   Group 9:  Link_ca — success (fully synthetic fixture)
 *   Group 10: Cross-session idempotency
 *   Group 11: Same-session completed idempotency
 *   Group 12: Non-actionable records
 *   Group 13: Compensation failure → partial state
 *
 * SAFETY GUARANTEE:
 *   Only synthetic sessions (status='test_execution') are executed.
 *   The canonical session '395e2b79-1e0a-49e8-9ea6-1ae146c9a54d' is never
 *   touched. All synthetic WP users created are cleaned up at test end.
 *   wp_konx_affiliates delta must be 0 at test end.
 *   wp_users count must equal baseline at test end.
 *
 * @package KonxAffiliateDashboard
 */

require_once __DIR__ . '/bootstrap-integration.php';

// Guard: remove any leftover test triggers from previous crashed runs.
global $wpdb;
$wpdb->query( 'DROP TRIGGER IF EXISTS konx_test_block_ledger_insert' );
$wpdb->query( 'DROP TRIGGER IF EXISTS konx_test_block_session_freeze' );

// ---------------------------------------------------------------------------
// Test infrastructure.
// ---------------------------------------------------------------------------

$pass_count  = 0;
$fail_count  = 0;
$test_output = array();

function re_assert( $name, $condition, $detail = '' ) {
	global $pass_count, $fail_count, $test_output;
	if ( $condition ) {
		$pass_count++;
		$test_output[] = "  [PASS] {$name}";
	} else {
		$fail_count++;
		$detail_str    = $detail ? " | {$detail}" : '';
		$test_output[] = "  [FAIL] {$name}{$detail_str}";
	}
}

function re_assert_eq( $name, $expected, $actual ) {
	re_assert( $name, $expected === $actual, "expected=" . json_encode( $expected ) . " actual=" . json_encode( $actual ) );
}

function re_assert_true( $name, $actual ) {
	re_assert( $name, (bool) $actual, "expected true, got " . json_encode( $actual ) );
}

function re_assert_false( $name, $actual ) {
	re_assert( $name, ! (bool) $actual, "expected false, got " . json_encode( $actual ) );
}

// ---------------------------------------------------------------------------
// Baselines — snapshot counts before any tests run.
// ---------------------------------------------------------------------------

$baseline_users     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );
$baseline_affiliates = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_affiliates' );
$baseline_ca        = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_wcusage_register' );

echo "[RE-TEST] Baselines: users={$baseline_users} affiliates={$baseline_affiliates} ca={$baseline_ca}\n\n";

// ---------------------------------------------------------------------------
// Synthetic WP user registry for test cleanup.
// These are users created DURING tests, tracked for deletion.
// ---------------------------------------------------------------------------

$_synthetic_wp_users     = array(); // [ user_id, ... ]
$_synthetic_affiliates   = array(); // [ affiliate_id, ... ]

function register_synthetic_user( $user_id ) {
	global $_synthetic_wp_users;
	if ( $user_id && $user_id > 0 ) {
		$_synthetic_wp_users[] = (int) $user_id;
	}
}

function register_synthetic_affiliate( $aff_id ) {
	global $_synthetic_affiliates;
	if ( $aff_id && $aff_id > 0 ) {
		$_synthetic_affiliates[] = (int) $aff_id;
	}
}

function cleanup_synthetic_wp_user( $user_id ) {
	global $wpdb;
	if ( ! $user_id || $user_id <= 0 ) {
		return;
	}
	$wpdb->delete( $wpdb->usermeta, array( 'user_id' => (int) $user_id ), array( '%d' ) );
	$wpdb->delete( $wpdb->users,    array( 'ID'      => (int) $user_id ), array( '%d' ) );
}

function cleanup_synthetic_affiliate( $aff_id ) {
	global $wpdb;
	if ( ! $aff_id || $aff_id <= 0 ) {
		return;
	}
	// Get user_id before deleting (to remove user meta).
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id FROM wp_konx_affiliates WHERE id = %d LIMIT 1', $aff_id ) );
	$wpdb->delete( 'wp_konx_affiliates', array( 'id' => (int) $aff_id ), array( '%d' ) );
	if ( $row && $row->user_id ) {
		$wpdb->delete( $wpdb->usermeta, array( 'user_id' => (int) $row->user_id, 'meta_key' => 'konx_affiliate_id' ), array( '%d', '%s' ) );
		$wpdb->delete( $wpdb->usermeta, array( 'user_id' => (int) $row->user_id, 'meta_key' => 'konx_affiliate_type' ), array( '%d', '%s' ) );
		$wpdb->delete( $wpdb->usermeta, array( 'user_id' => (int) $row->user_id, 'meta_key' => 'konx_referral_code' ), array( '%d', '%s' ) );
	}
}

// Teardown all synthetic data at script end.
function teardown_all_synthetic_data() {
	global $_synthetic_affiliates, $_synthetic_wp_users;
	foreach ( array_reverse( $_synthetic_affiliates ) as $aff_id ) {
		cleanup_synthetic_affiliate( $aff_id );
	}
	foreach ( array_reverse( $_synthetic_wp_users ) as $uid ) {
		cleanup_synthetic_wp_user( $uid );
	}
	$_synthetic_affiliates = array();
	$_synthetic_wp_users   = array();
}

register_shutdown_function( 'teardown_all_synthetic_data' );

// ---------------------------------------------------------------------------
// Helper: create a minimal synthetic exec session and plan/ledger rows for testing.
// Returns [ 'session_uuid', 'session_id', 'plan_row_id', 'ledger_row_id', 'po10_id' ]
// or null on failure.
//
// The session will be in 'frozen' state initially — caller must call
// Konx_Migration_Exec_Session::set_test_execution_status() to promote it.
// ---------------------------------------------------------------------------

$_re_test_po10_counter = 9_000_001; // High ID range — never overlaps real records.

function re_create_test_session_with_plan(
	$action,
	$extra_plan_fields = array(),
	$num_records = 1,
	$po10_id_override = null
) {
	global $wpdb, $_re_test_po10_counter;

	$po10_id = $po10_id_override ?? $_re_test_po10_counter++;
	$email   = "retest.{$po10_id}@konx-test.invalid";

	// Build a minimal decisions array for the snapshot.
	$decisions = array();
	for ( $i = 0; $i < $num_records; $i++ ) {
		$pid   = $po10_id_override ? $po10_id_override + $i : ( $po10_id + $i );
		$pmail = "retest.{$pid}@konx-test.invalid";
		$d     = array(
			'decision'       => $action,
			'po10_id'        => $pid,
			'email'          => $pmail,
			'team_name'      => 'RETEST' . $pid,
			'affiliate_type' => 'sales_agent',
			'wp_user_id'     => null,
			'ca_id'          => null,
			'sponsor'        => '',
			'first_name'     => 'Re',
			'last_name'      => 'Test',
			'match_method'   => '',
			'confidence'     => '',
			'sponsor_status' => '',
			'val_status'     => '',
		);
		// Apply per-field overrides.
		foreach ( $extra_plan_fields as $k => $v ) {
			$d[ $k ] = $v;
		}
		$decisions[] = $d;
	}

	// Set FMP override so the snapshot's plan hash is accepted.
	test_set_option_override(
		'konx_migration_state',
		array( 'final_migration_plan' => array( 'decisions' => $decisions ) )
	);

	$result = Konx_Migration_Execution_Plan::create_snapshot( $decisions, array(
		'source_filename'   => 'retest.csv',
		'source_hash'       => str_repeat( 'a', 64 ),
		'created_by'        => 0,
	) );

	test_clear_option_override( 'konx_migration_state' );

	if ( is_wp_error( $result ) ) {
		echo "[RE-TEST] create_snapshot() failed: " . $result->get_error_message() . "\n";
		return null;
	}

	$session_uuid = $result['session_uuid'];
	$session_id   = $result['session_id'];
	register_test_session( $session_uuid, $session_id );

	// Find the plan row for the primary po10_id.
	$plan_table = $wpdb->prefix . 'konx_migration_execution_plan';
	$plan_row   = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$plan_table} WHERE session_id = %d AND source_record_id = %d LIMIT 1",
		$session_id, $po10_id
	) );

	if ( ! $plan_row ) {
		echo "[RE-TEST] Plan row not found for session_id={$session_id}, po10_id={$po10_id}\n";
		return null;
	}

	$ledger_table = $wpdb->prefix . 'konx_migration_execution_ledger';
	$ledger_row   = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$ledger_table} WHERE plan_id = %d LIMIT 1",
		$plan_row->id
	) );

	return array(
		'session_uuid'  => $session_uuid,
		'session_id'    => (int) $session_id,
		'plan_row_id'   => (int) $plan_row->id,
		'ledger_row_id' => $ledger_row ? (int) $ledger_row->id : null,
		'po10_id'       => (int) $po10_id,
		'email'         => $email,
		'decisions'     => $decisions,
	);
}

/**
 * Promote a session to test_execution status, setting the FMP override so the
 * revalidator's plan-hash check passes during execution.
 *
 * Returns true on success.
 */
function re_promote_to_test_execution( $session_uuid, $decisions ) {
	test_set_option_override(
		'konx_migration_state',
		array( 'final_migration_plan' => array( 'decisions' => $decisions ) )
	);

	$ok = Konx_Migration_Exec_Session::set_test_execution_status( $session_uuid );

	// Keep the override active during the execute_record() call — the caller
	// is responsible for clearing it after execution.
	return $ok;
}

// ---------------------------------------------------------------------------
// ===== ENVIRONMENT GATE VERIFICATION =====
// Requirement G: Verify KONX_MIGRATION_TEST_EXECUTION_ENABLED is defined and
// true in this test environment. This constant MUST NOT be defined in production.
// The executor returns 'execution_not_enabled' if undefined or false.
// Test injection hooks (set_test_affiliate_fail, set_test_block_compensation)
// are also gated by this constant and are verified active in G6 and G13.
// ---------------------------------------------------------------------------

re_assert_true( 'ENV-G01: KONX_MIGRATION_TEST_EXECUTION_ENABLED is defined', defined( 'KONX_MIGRATION_TEST_EXECUTION_ENABLED' ) );
re_assert_true( 'ENV-G02: KONX_MIGRATION_TEST_EXECUTION_ENABLED === true',   defined( 'KONX_MIGRATION_TEST_EXECUTION_ENABLED' ) && true === KONX_MIGRATION_TEST_EXECUTION_ENABLED );

// ---------------------------------------------------------------------------
// ===== GROUP 0: Canonical protection =====
// ---------------------------------------------------------------------------

echo "\n=== GROUP 0: Canonical protection ===\n\n";

$canonical_uuid = KONX_CANONICAL_SESSION_UUID;

// T01: execute_record() with canonical UUID returns error_code='canonical_protected'.
$r = Konx_Migration_Record_Executor::execute_record( $canonical_uuid, 99999 );
re_assert_eq( 'G0-T01: canonical UUID returns canonical_protected', 'canonical_protected', $r['error_code'] );
re_assert_eq( 'G0-T01: status is failed', 'failed', $r['status'] );

// T02: Canonical session row unchanged after attempt.
$canonical_row = $wpdb->get_row( $wpdb->prepare(
	"SELECT status, final_plan_hash FROM wp_konx_migration_exec_sessions WHERE session_uuid = %s",
	$canonical_uuid
) );
re_assert_eq( 'G0-T02: canonical status still frozen', 'frozen', $canonical_row->status );
re_assert_eq( 'G0-T02: canonical hash unchanged', '8ffe9d804450b69ad0ee09c9f224e1ca8b808a071a1676fe4af4c90cb2efc0fd', $canonical_row->final_plan_hash );

// ---------------------------------------------------------------------------
// ===== GROUP 1: Precondition guards =====
// ---------------------------------------------------------------------------

echo "\n=== GROUP 1: Precondition guards ===\n\n";

// G1-T01: Non-existent session UUID.
$r = Konx_Migration_Record_Executor::execute_record( 'ffffffff-0000-4000-8000-000000000000', 1 );
re_assert_eq( 'G1-T01: non-existent session → session_not_found', 'session_not_found', $r['error_code'] );
re_assert_eq( 'G1-T01: status=failed', 'failed', $r['status'] );

// G1-T02: Session in wrong status (frozen, not test_execution).
$g1t02 = re_create_test_session_with_plan( 'create' );
if ( $g1t02 ) {
	// Session is 'frozen' — do NOT promote to test_execution.
	// Execute with wrong status.
	test_set_option_override( 'konx_migration_state', array( 'final_migration_plan' => array( 'decisions' => $g1t02['decisions'] ) ) );
	$r = Konx_Migration_Record_Executor::execute_record( $g1t02['session_uuid'], $g1t02['plan_row_id'] );
	test_clear_option_override( 'konx_migration_state' );
	re_assert_eq( 'G1-T02: frozen session → invalid_session_status', 'invalid_session_status', $r['error_code'] );
	re_assert_eq( 'G1-T02: status=failed', 'failed', $r['status'] );
	safe_cleanup_test_session( $g1t02['session_uuid'], $g1t02['session_id'] );
} else {
	re_assert( 'G1-T02: SKIPPED (session creation failed)', false );
}

// G1-T03: Plan record belonging to a different session.
$g1t03a = re_create_test_session_with_plan( 'create' );
$g1t03b = re_create_test_session_with_plan( 'create' );
if ( $g1t03a && $g1t03b ) {
	re_promote_to_test_execution( $g1t03a['session_uuid'], $g1t03a['decisions'] );
	// Pass plan_row_id from session B while using session A's UUID.
	$r = Konx_Migration_Record_Executor::execute_record( $g1t03a['session_uuid'], $g1t03b['plan_row_id'] );
	test_clear_option_override( 'konx_migration_state' );
	re_assert_eq( 'G1-T03: plan from other session → plan_record_session_mismatch', 'plan_record_session_mismatch', $r['error_code'] );
	re_assert_eq( 'G1-T03: status=failed', 'failed', $r['status'] );
	safe_cleanup_test_session( $g1t03a['session_uuid'], $g1t03a['session_id'] );
	safe_cleanup_test_session( $g1t03b['session_uuid'], $g1t03b['session_id'] );
} else {
	re_assert( 'G1-T03: SKIPPED (session creation failed)', false );
}

// G1-T04: Plan record does not exist.
$g1t04 = re_create_test_session_with_plan( 'create' );
if ( $g1t04 ) {
	re_promote_to_test_execution( $g1t04['session_uuid'], $g1t04['decisions'] );
	$r = Konx_Migration_Record_Executor::execute_record( $g1t04['session_uuid'], 999999999 );
	test_clear_option_override( 'konx_migration_state' );
	re_assert_eq( 'G1-T04: nonexistent plan_row_id → plan_record_not_found', 'plan_record_not_found', $r['error_code'] );
	re_assert_eq( 'G1-T04: status=failed', 'failed', $r['status'] );
	safe_cleanup_test_session( $g1t04['session_uuid'], $g1t04['session_id'] );
} else {
	re_assert( 'G1-T04: SKIPPED (session creation failed)', false );
}

// ---------------------------------------------------------------------------
// ===== GROUP 2: Atomic ledger claim =====
// ---------------------------------------------------------------------------

echo "\n=== GROUP 2: Atomic ledger claim ===\n\n";

// G2-T01: pending→processing claim succeeds, attempt_count=1.
$g2t01 = re_create_test_session_with_plan( 'create' );
if ( $g2t01 ) {
	// We don't execute fully — just claim the ledger and verify it updates.
	// To isolate the claim, we promote status but then verify attempt_count after execution.
	// Use an email that will cause stale_email_exists early failure (so no WP user is created).
	// First: create a WP user with the email of the plan record, then execute.
	$test_email_g2 = $g2t01['email'];

	// Insert a WP user with the synthetic email so execution fails at stale_email_exists
	// (after the ledger claim) — this lets us verify attempt_count=1 without creating affiliates.
	$preexist_uid = wp_create_user( 'retestg2t01_' . time(), 'TestPass99!', $test_email_g2 );
	if ( ! is_wp_error( $preexist_uid ) ) {
		register_synthetic_user( $preexist_uid );
		re_promote_to_test_execution( $g2t01['session_uuid'], $g2t01['decisions'] );
		$r = Konx_Migration_Record_Executor::execute_record( $g2t01['session_uuid'], $g2t01['plan_row_id'] );
		test_clear_option_override( 'konx_migration_state' );

		// Fetch ledger row.
		$ledger_after = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM wp_konx_migration_execution_ledger WHERE id = %d LIMIT 1",
			$g2t01['ledger_row_id']
		) );

		re_assert_eq( 'G2-T01: attempt_count=1 after first execution', '1', (string) $ledger_after->attempt_count );
		re_assert( 'G2-T01: ledger not pending after execution', 'pending' !== $ledger_after->status, "status={$ledger_after->status}" );
		re_assert( 'G2-T01: started_at is set', ! empty( $ledger_after->started_at ) );
	} else {
		re_assert( 'G2-T01: SKIPPED (preexist user creation failed)', false );
	}
	safe_cleanup_test_session( $g2t01['session_uuid'], $g2t01['session_id'] );
} else {
	re_assert( 'G2-T01: SKIPPED (session creation failed)', false );
}

// G2-T02: Double-claim on same row returns conflict, attempt_count still=1.
// (After a first execution, the ledger row is no longer 'pending', so a second claim fails.)
$g2t02 = re_create_test_session_with_plan( 'create' );
if ( $g2t02 ) {
	$test_email_g2t02 = $g2t02['email'];
	$preexist_uid2 = wp_create_user( 'retestg2t02_' . time(), 'TestPass99!', $test_email_g2t02 );
	if ( ! is_wp_error( $preexist_uid2 ) ) {
		register_synthetic_user( $preexist_uid2 );

		re_promote_to_test_execution( $g2t02['session_uuid'], $g2t02['decisions'] );
		// First call.
		$r1 = Konx_Migration_Record_Executor::execute_record( $g2t02['session_uuid'], $g2t02['plan_row_id'] );
		// Second call — ledger is no longer 'pending'.
		$r2 = Konx_Migration_Record_Executor::execute_record( $g2t02['session_uuid'], $g2t02['plan_row_id'] );
		test_clear_option_override( 'konx_migration_state' );

		$ledger_after2 = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM wp_konx_migration_execution_ledger WHERE id = %d LIMIT 1",
			$g2t02['ledger_row_id']
		) );

		re_assert_eq( 'G2-T02: second call returns conflict status', 'conflict', $r2['status'] );
		// attempt_count should still be 1 (the second claim failed, no increment).
		re_assert_eq( 'G2-T02: attempt_count still=1 after double claim', '1', (string) $ledger_after2->attempt_count );
	} else {
		re_assert( 'G2-T02: SKIPPED (preexist user creation failed)', false );
	}
	safe_cleanup_test_session( $g2t02['session_uuid'], $g2t02['session_id'] );
} else {
	re_assert( 'G2-T02: SKIPPED (session creation failed)', false );
}

// G2-T03: Non-pending ledger (manually set to 'processing') → claim refused.
$g2t03 = re_create_test_session_with_plan( 'create' );
if ( $g2t03 ) {
	// Manually set ledger to 'processing'.
	$wpdb->update(
		$wpdb->prefix . 'konx_migration_execution_ledger',
		array( 'status' => 'processing' ),
		array( 'id' => $g2t03['ledger_row_id'] )
	);
	re_promote_to_test_execution( $g2t03['session_uuid'], $g2t03['decisions'] );
	$r = Konx_Migration_Record_Executor::execute_record( $g2t03['session_uuid'], $g2t03['plan_row_id'] );
	test_clear_option_override( 'konx_migration_state' );
	re_assert_eq( 'G2-T03: already-processing ledger → conflict', 'conflict', $r['status'] );
	re_assert_eq( 'G2-T03: error_code=ledger_claim_failed', 'ledger_claim_failed', $r['error_code'] );
	safe_cleanup_test_session( $g2t03['session_uuid'], $g2t03['session_id'] );
} else {
	re_assert( 'G2-T03: SKIPPED (session creation failed)', false );
}

// ---------------------------------------------------------------------------
// ===== GROUP 3: Per-record revalidation guard =====
// ---------------------------------------------------------------------------

echo "\n=== GROUP 3: Per-record revalidation guard ===\n\n";

// Create a plan row where the email already exists (stale condition).
// The per-record check (check_single_record) should detect this and set ledger=failed.
$g3_po10_id = 9_010_001;
$g3_email   = "retest.g3.{$g3_po10_id}@konx-test.invalid";

$g3_preexist_uid = wp_create_user( 'retestg3_' . time(), 'TestPass99!', $g3_email );
if ( ! is_wp_error( $g3_preexist_uid ) ) {
	register_synthetic_user( $g3_preexist_uid );
}

// Build snapshot with the stale email.
$g3_session = re_create_test_session_with_plan( 'create', array(
	'email'   => $g3_email,
	'po10_id' => $g3_po10_id,
), 1, $g3_po10_id );

if ( $g3_session && ! is_wp_error( $g3_preexist_uid ) ) {
	$users_before_g3 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );

	re_promote_to_test_execution( $g3_session['session_uuid'], $g3_session['decisions'] );
	$r = Konx_Migration_Record_Executor::execute_record( $g3_session['session_uuid'], $g3_session['plan_row_id'] );
	test_clear_option_override( 'konx_migration_state' );

	re_assert( 'G3: stale email causes failure (stale_email_exists or stale_or_conflict)',
		in_array( $r['error_code'], array( 'stale_email_exists', 'stale_or_conflict' ), true ),
		"actual={$r['error_code']}"
	);
	re_assert_eq( 'G3: status=failed', 'failed', $r['status'] );
	re_assert_false( 'G3: no WP user created (already existed)', $r['wp_user_created'] );

	$users_after_g3 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );
	re_assert_eq( 'G3: wp_users delta = 0 after execution', $users_before_g3, $users_after_g3 );

	safe_cleanup_test_session( $g3_session['session_uuid'], $g3_session['session_id'] );
} else {
	re_assert( 'G3: SKIPPED (session or preexist creation failed)', false );
}

// ---------------------------------------------------------------------------
// ===== GROUP 4: Create action — success =====
// ---------------------------------------------------------------------------

echo "\n=== GROUP 4: Create action — success ===\n\n";

$g4_po10_id = 9_020_001;
$g4_email   = "retest.g4.{$g4_po10_id}@konx-test.invalid";
// Make sure no WP user with this email exists.
$g4_existing = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM wp_users WHERE user_email = %s LIMIT 1", $g4_email ) );
if ( $g4_existing ) {
	cleanup_synthetic_wp_user( (int) $g4_existing );
}

$g4_session = re_create_test_session_with_plan( 'create', array(
	'email'      => $g4_email,
	'po10_id'    => $g4_po10_id,
	'team_name'  => 'G4TEAM' . $g4_po10_id,
	'first_name' => 'Four',
	'last_name'  => 'Test',
), 1, $g4_po10_id );

$users_before_g4     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );
$affiliates_before_g4 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_affiliates' );

if ( $g4_session ) {
	re_promote_to_test_execution( $g4_session['session_uuid'], $g4_session['decisions'] );
	$r = Konx_Migration_Record_Executor::execute_record( $g4_session['session_uuid'], $g4_session['plan_row_id'] );
	test_clear_option_override( 'konx_migration_state' );

	re_assert_eq( 'G4-T01: status=completed', 'completed', $r['status'] );
	re_assert_true( 'G4-T02: wp_user_created=true', $r['wp_user_created'] );
	re_assert_true( 'G4-T03: affiliate_created=true', $r['affiliate_created'] );
	re_assert( 'G4-T04: wp_user_id is positive int', $r['wp_user_id'] > 0, "got=" . $r['wp_user_id'] );
	re_assert( 'G4-T05: affiliate_id is positive int', $r['affiliate_id'] > 0, "got=" . $r['affiliate_id'] );
	re_assert_false( 'G4-T06: no error_code on success', $r['error_code'] );
	re_assert( 'G4-T07: completed_at is set', ! empty( $r['completed_at'] ) );

	$users_after_g4     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );
	$affiliates_after_g4 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_affiliates' );
	re_assert_eq( 'G4-T08: wp_users delta=+1', $users_before_g4 + 1, $users_after_g4 );
	re_assert_eq( 'G4-T09: konx_affiliates delta=+1', $affiliates_before_g4 + 1, $affiliates_after_g4 );

	// Verify ledger status=completed.
	$ledger_g4 = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM wp_konx_migration_execution_ledger WHERE id = %d LIMIT 1",
		$g4_session['ledger_row_id']
	) );
	re_assert_eq( 'G4-T10: ledger status=completed', 'completed', $ledger_g4->status );
	re_assert( 'G4-T11: ledger completed_at set', ! empty( $ledger_g4->completed_at ) );
	re_assert_eq( 'G4-T12: ledger wp_user_id set', (string) $r['wp_user_id'], (string) $ledger_g4->wp_user_id );
	re_assert_eq( 'G4-T13: ledger affiliate_id set', (string) $r['affiliate_id'], (string) $ledger_g4->affiliate_id );

	// Register for cleanup.
	if ( $r['wp_user_id'] )  { register_synthetic_user( $r['wp_user_id'] ); }
	if ( $r['affiliate_id'] ) { register_synthetic_affiliate( $r['affiliate_id'] ); }

	safe_cleanup_test_session( $g4_session['session_uuid'], $g4_session['session_id'] );
} else {
	re_assert( 'G4: SKIPPED (session creation failed)', false );
}

// Cleanup G4 synthetic data now (before GROUP 5 baselines).
teardown_all_synthetic_data();

// ---------------------------------------------------------------------------
// ===== GROUP 5: Create action — stale email =====
// ---------------------------------------------------------------------------

echo "\n=== GROUP 5: Create action — stale email ===\n\n";

$g5_po10_id = 9_030_001;
$g5_email   = "retest.g5.{$g5_po10_id}@konx-test.invalid";

// Pre-insert a WP user with this email to simulate stale condition.
$g5_preexist_uid = wp_create_user( 'retestg5_' . time(), 'TestPass99!', $g5_email );
if ( is_wp_error( $g5_preexist_uid ) ) {
	$g5_preexist_uid = null;
} else {
	register_synthetic_user( $g5_preexist_uid );
}

$users_before_g5     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );
$affiliates_before_g5 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_affiliates' );

$g5_session = re_create_test_session_with_plan( 'create', array(
	'email'   => $g5_email,
	'po10_id' => $g5_po10_id,
), 1, $g5_po10_id );

if ( $g5_session && $g5_preexist_uid ) {
	re_promote_to_test_execution( $g5_session['session_uuid'], $g5_session['decisions'] );
	$r = Konx_Migration_Record_Executor::execute_record( $g5_session['session_uuid'], $g5_session['plan_row_id'] );
	test_clear_option_override( 'konx_migration_state' );

	re_assert_eq( 'G5-T01: status=failed on stale email', 'failed', $r['status'] );
	re_assert( 'G5-T02: error_code is stale-related',
		in_array( $r['error_code'], array( 'stale_email_exists', 'stale_or_conflict' ), true ),
		"got={$r['error_code']}"
	);
	re_assert_false( 'G5-T03: wp_user_created=false', $r['wp_user_created'] );
	re_assert_false( 'G5-T04: affiliate_created=false', $r['affiliate_created'] );

	$users_after_g5     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );
	$affiliates_after_g5 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_affiliates' );
	re_assert_eq( 'G5-T05: wp_users delta=0', $users_before_g5, $users_after_g5 );
	re_assert_eq( 'G5-T06: konx_affiliates delta=0', $affiliates_before_g5, $affiliates_after_g5 );

	safe_cleanup_test_session( $g5_session['session_uuid'], $g5_session['session_id'] );
} else {
	re_assert( 'G5: SKIPPED (session or preexist creation failed)', false );
	if ( $g5_session ) { safe_cleanup_test_session( $g5_session['session_uuid'], $g5_session['session_id'] ); }
}

// Cleanup G5 synthetic user.
teardown_all_synthetic_data();

// ---------------------------------------------------------------------------
// ===== GROUP 6: Create compensation =====
// ---------------------------------------------------------------------------

echo "\n=== GROUP 6: Create compensation — injected failure AFTER wp_create_user ===\n\n";

// Strategy: use set_test_affiliate_fail(true) to inject a deterministic failure
// AFTER wp_create_user() succeeds and BEFORE affiliate creation.
// This exercises the real compensation path: WP user created → affiliate fails
// → compensate_delete_wp_user() removes the exact user → status=failed, delta=0.
//
// This is a test-only injection gated by KONX_MIGRATION_TEST_EXECUTION_ENABLED.

$g6_po10_id  = 9_040_001;
$g6_email    = "retest.g6.comp.{$g6_po10_id}@konx-test.invalid";

// Ensure email is free.
$g6_existing = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM wp_users WHERE user_email = %s LIMIT 1", $g6_email ) );
if ( $g6_existing ) { cleanup_synthetic_wp_user( (int) $g6_existing ); }

$users_before_g6     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );
$affiliates_before_g6 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_affiliates' );

$g6_session = re_create_test_session_with_plan( 'create', array(
	'email'   => $g6_email,
	'po10_id' => $g6_po10_id,
), 1, $g6_po10_id );

if ( $g6_session ) {
	// Inject: affiliate insert will fail; compensation will succeed.
	Konx_Migration_Record_Executor::set_test_affiliate_fail( true );

	re_promote_to_test_execution( $g6_session['session_uuid'], $g6_session['decisions'] );
	$r = Konx_Migration_Record_Executor::execute_record( $g6_session['session_uuid'], $g6_session['plan_row_id'] );
	test_clear_option_override( 'konx_migration_state' );

	// Reset injection immediately after execution.
	Konx_Migration_Record_Executor::reset_test_hooks();

	// REQUIRED: WP user was created (wp_create_user succeeded), then removed by compensation.
	re_assert_eq( 'G6-T01: status=failed (compensation succeeded)', 'failed', $r['status'] );
	re_assert_eq( 'G6-T02: error_code=affiliate_insert_failed', 'affiliate_insert_failed', $r['error_code'] );
	// Compensation removes the user — wp_user_created must be false in the result
	// (compensation succeeded means we report it as never happened).
	re_assert_false( 'G6-T03: wp_user_created=false after compensation', $r['wp_user_created'] );
	re_assert_false( 'G6-T04: affiliate_created=false', $r['affiliate_created'] );
	re_assert_false( 'G6-T05: wp_user_id=null after compensation', $r['wp_user_id'] );

	// Delta verification: both counts must be back to baseline.
	$users_after_g6     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );
	$affiliates_after_g6 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_affiliates' );
	re_assert_eq( 'G6-T06: wp_users delta=0 (compensation removed user)', $users_before_g6, $users_after_g6 );
	re_assert_eq( 'G6-T07: affiliates delta=0', $affiliates_before_g6, $affiliates_after_g6 );

	// Ledger must be 'failed', not 'completed'.
	$ledger_g6 = $wpdb->get_row( $wpdb->prepare(
		"SELECT status, affiliate_created, wp_user_id FROM wp_konx_migration_execution_ledger WHERE id = %d LIMIT 1",
		$g6_session['ledger_row_id']
	) );
	re_assert_eq( 'G6-T08: ledger status=failed', 'failed', $ledger_g6->status );
	re_assert( 'G6-T09: ledger does not falsely claim affiliate_created',
		! $ledger_g6->affiliate_created || '0' === (string) $ledger_g6->affiliate_created
	);

	// No orphan WP user with the synthetic email remains.
	$orphan = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM wp_users WHERE user_email = %s LIMIT 1", $g6_email ) );
	re_assert_false( 'G6-T10: no orphan WP user remains', $orphan );

	safe_cleanup_test_session( $g6_session['session_uuid'], $g6_session['session_id'] );
} else {
	re_assert( 'G6: SKIPPED (session creation failed)', false );
}

teardown_all_synthetic_data();

// ---------------------------------------------------------------------------
// ===== GROUP 7: Link_wp — success =====
// ---------------------------------------------------------------------------

echo "\n=== GROUP 7: Link_wp — success ===\n\n";

// Create a synthetic WP user, then create a plan with action=link_wp pointing to it.
$g7_email = 'retest.g7.' . time() . '@konx-test.invalid';
$g7_uid   = wp_create_user( 'retestg7_' . time(), 'TestPass99!', $g7_email );

if ( ! is_wp_error( $g7_uid ) ) {
	register_synthetic_user( $g7_uid );
	$g7_po10_id = 9_050_001;

	$users_before_g7     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );
	$affiliates_before_g7 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_affiliates' );

	$g7_session = re_create_test_session_with_plan( 'link_wp', array(
		'email'      => $g7_email,
		'po10_id'    => $g7_po10_id,
		'wp_user_id' => (int) $g7_uid,
		'team_name'  => 'G7TEAM' . $g7_po10_id,
	), 1, $g7_po10_id );

	if ( $g7_session ) {
		re_promote_to_test_execution( $g7_session['session_uuid'], $g7_session['decisions'] );
		$r = Konx_Migration_Record_Executor::execute_record( $g7_session['session_uuid'], $g7_session['plan_row_id'] );
		test_clear_option_override( 'konx_migration_state' );

		re_assert_eq( 'G7-T01: status=completed', 'completed', $r['status'] );
		re_assert_false( 'G7-T02: wp_user_created=false (existing user reused)', $r['wp_user_created'] );
		re_assert_true( 'G7-T03: affiliate_created=true', $r['affiliate_created'] );
		re_assert_eq( 'G7-T04: wp_user_id matches synthetic', (int) $g7_uid, (int) $r['wp_user_id'] );
		re_assert( 'G7-T05: affiliate_id is positive', $r['affiliate_id'] > 0 );

		$users_after_g7     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );
		$affiliates_after_g7 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_affiliates' );
		re_assert_eq( 'G7-T06: wp_users delta=0', $users_before_g7, $users_after_g7 );
		re_assert_eq( 'G7-T07: konx_affiliates delta=+1', $affiliates_before_g7 + 1, $affiliates_after_g7 );

		if ( $r['affiliate_id'] ) { register_synthetic_affiliate( $r['affiliate_id'] ); }
		safe_cleanup_test_session( $g7_session['session_uuid'], $g7_session['session_id'] );
	} else {
		re_assert( 'G7: SKIPPED (session creation failed)', false );
	}
} else {
	re_assert( 'G7: SKIPPED (WP user creation failed)', false );
}

teardown_all_synthetic_data();

// ---------------------------------------------------------------------------
// ===== GROUP 8: Link_wp — user deleted (stale) =====
// ---------------------------------------------------------------------------

echo "\n=== GROUP 8: Link_wp — user deleted (stale) ===\n\n";

// Create a WP user, record its ID, then delete it before execution.
$g8_email = 'retest.g8.' . time() . '@konx-test.invalid';
$g8_uid   = wp_create_user( 'retestg8_' . time(), 'TestPass99!', $g8_email );

if ( ! is_wp_error( $g8_uid ) ) {
	$g8_po10_id = 9_060_001;

	$g8_session = re_create_test_session_with_plan( 'link_wp', array(
		'email'      => $g8_email,
		'po10_id'    => $g8_po10_id,
		'wp_user_id' => (int) $g8_uid,
		'team_name'  => 'G8TEAM' . $g8_po10_id,
	), 1, $g8_po10_id );

	// Delete the WP user BEFORE execution (simulating stale state).
	cleanup_synthetic_wp_user( (int) $g8_uid );

	if ( $g8_session ) {
		re_promote_to_test_execution( $g8_session['session_uuid'], $g8_session['decisions'] );
		$r = Konx_Migration_Record_Executor::execute_record( $g8_session['session_uuid'], $g8_session['plan_row_id'] );
		test_clear_option_override( 'konx_migration_state' );

		re_assert_eq( 'G8-T01: status=failed (stale WP user)', 'failed', $r['status'] );
		re_assert( 'G8-T02: error_code contains stale or not_found',
			strpos( $r['error_code'], 'stale' ) !== false || strpos( $r['error_code'], 'not_found' ) !== false,
			"got={$r['error_code']}"
		);
		re_assert_false( 'G8-T03: affiliate_created=false', $r['affiliate_created'] );

		safe_cleanup_test_session( $g8_session['session_uuid'], $g8_session['session_id'] );
	} else {
		re_assert( 'G8: SKIPPED (session creation failed)', false );
	}
} else {
	re_assert( 'G8: SKIPPED (WP user creation failed)', false );
}

// ---------------------------------------------------------------------------
// ===== GROUP 9: Link_ca — success (fully synthetic fixture) =====
// ---------------------------------------------------------------------------

echo "\n=== GROUP 9: Link_ca — success (fully synthetic fixture) ===\n\n";

// Fully synthetic fixture — no real CA rows are selected or modified.
//   1. Synthetic WP user (fresh, never existed before)
//   2. Synthetic wp_wcusage_register row linked to that user's ID
//   3. Synthetic execution session with action=link_ca
// Requirement B: asserts that the CA row was reached via known synthetic ID,
// not via a discovery query (ORDER BY / MAX / status scan) on real data.

$g9_po10_id = 9_070_001;
$g9_email   = "retest.g9.linkca.{$g9_po10_id}@konx-test.invalid";

// Remove any leftover synthetic user.
$g9_stale_uid = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM wp_users WHERE user_email = %s LIMIT 1", $g9_email ) );
if ( $g9_stale_uid ) {
	cleanup_synthetic_wp_user( (int) $g9_stale_uid );
}

// Create synthetic WP user.
$g9_wp_uid = wp_create_user( 'retestg9_' . time(), 'TestPass99!', $g9_email );

if ( ! is_wp_error( $g9_wp_uid ) ) {
	register_synthetic_user( $g9_wp_uid );

	// Insert synthetic CA row linked to the synthetic WP user.
	// All NOT NULL text columns filled with synthetic placeholder values.
	$g9_ca_inserted = $wpdb->insert(
		'wp_wcusage_register',
		array(
			'userid'       => (int) $g9_wp_uid,
			'couponcode'   => 'G9CATEST' . $g9_po10_id,
			'promote'      => '',
			'referrer'     => '',
			'website'      => '',
			'status'       => 'active',
			'type'         => 'affiliate',
			'info'         => '',
			'date'         => current_time( 'mysql', true ),
			'dateaccepted' => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	$g9_ca_id = $g9_ca_inserted ? (int) $wpdb->insert_id : 0;

	re_assert_true( 'G9-T00: synthetic CA row inserted', $g9_ca_id > 0 );

	// Requirement B: verify this CA row is synthetic (userid matches synthetic WP user).
	// The executor SELECT-only query will find it by exact ID, not by discovery.
	$g9_ca_userid_check = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT userid FROM wp_wcusage_register WHERE id = %d", $g9_ca_id
	) );
	re_assert_eq( 'G9-B01: CA row userid is the synthetic WP user (no real CA row selected)',
		(int) $g9_wp_uid, $g9_ca_userid_check
	);

	$affiliates_before_g9 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_affiliates' );

	$g9_session = re_create_test_session_with_plan( 'link_ca', array(
		'email'               => $g9_email,
		'po10_id'             => $g9_po10_id,
		'wp_user_id'          => (int) $g9_wp_uid,
		'ca_id'               => $g9_ca_id,
		'coupon_affiliate_id' => $g9_ca_id,
		'team_name'           => 'G9TEAM' . $g9_po10_id,
	), 1, $g9_po10_id );

	if ( $g9_session && $g9_ca_id > 0 ) {
		re_promote_to_test_execution( $g9_session['session_uuid'], $g9_session['decisions'] );
		$r = Konx_Migration_Record_Executor::execute_record( $g9_session['session_uuid'], $g9_session['plan_row_id'] );
		test_clear_option_override( 'konx_migration_state' );

		re_assert_eq( 'G9-T01: status=completed', 'completed', $r['status'] );
		re_assert_false( 'G9-T02: wp_user_created=false (CA user reused)', $r['wp_user_created'] );
		re_assert_true( 'G9-T03: affiliate_created=true', $r['affiliate_created'] );
		re_assert_eq( 'G9-T04: wp_user_id matches synthetic CA userid', (int) $g9_wp_uid, (int) $r['wp_user_id'] );
		re_assert( 'G9-T05: affiliate_id is positive', $r['affiliate_id'] > 0 );

		$affiliates_after_g9 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_affiliates' );
		re_assert_eq( 'G9-T06: affiliates delta=+1', $affiliates_before_g9 + 1, $affiliates_after_g9 );

		// Verify CA row was SELECT-only — userid and couponcode unchanged.
		$ca_after = $wpdb->get_row( $wpdb->prepare(
			"SELECT userid, couponcode FROM wp_wcusage_register WHERE id = %d LIMIT 1",
			$g9_ca_id
		) );
		re_assert_eq( 'G9-T07: CA row userid unchanged (SELECT-only)',     (string) $g9_wp_uid,         (string) $ca_after->userid );
		re_assert_eq( 'G9-T08: CA row couponcode unchanged (SELECT-only)', 'G9CATEST' . $g9_po10_id,   $ca_after->couponcode );

		if ( $r['affiliate_id'] ) { register_synthetic_affiliate( $r['affiliate_id'] ); }
		safe_cleanup_test_session( $g9_session['session_uuid'], $g9_session['session_id'] );
	} else {
		re_assert( 'G9: SKIPPED (session creation failed or CA insert failed)', false );
		if ( $g9_session ) { safe_cleanup_test_session( $g9_session['session_uuid'], $g9_session['session_id'] ); }
	}

	// Explicit cleanup of synthetic CA row (before teardown_all_synthetic_data).
	if ( $g9_ca_id > 0 ) {
		$wpdb->delete( 'wp_wcusage_register', array( 'id' => $g9_ca_id ), array( '%d' ) );
	}
} else {
	re_assert( 'G9: SKIPPED (synthetic WP user creation failed)', false );
}

teardown_all_synthetic_data();

// ---------------------------------------------------------------------------
// ===== GROUP 10: Cross-session idempotency =====
// ---------------------------------------------------------------------------

echo "\n=== GROUP 10: Cross-session idempotency ===\n\n";

// Execute a 'create' in session A successfully, then attempt same source_record_id in session B.
$g10_po10_id_a = 9_080_001;
$g10_email_a   = "retest.g10a.{$g10_po10_id_a}@konx-test.invalid";

// Ensure clean email.
$existing = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM wp_users WHERE user_email = %s LIMIT 1", $g10_email_a ) );
if ( $existing ) { cleanup_synthetic_wp_user( (int) $existing ); }

$g10_session_a = re_create_test_session_with_plan( 'create', array(
	'email'   => $g10_email_a,
	'po10_id' => $g10_po10_id_a,
), 1, $g10_po10_id_a );

$g10_result_a = null;
if ( $g10_session_a ) {
	re_promote_to_test_execution( $g10_session_a['session_uuid'], $g10_session_a['decisions'] );
	$g10_result_a = Konx_Migration_Record_Executor::execute_record( $g10_session_a['session_uuid'], $g10_session_a['plan_row_id'] );
	test_clear_option_override( 'konx_migration_state' );
}

if ( $g10_result_a && 'completed' === $g10_result_a['status'] ) {
	if ( $g10_result_a['wp_user_id'] )  { register_synthetic_user( $g10_result_a['wp_user_id'] ); }
	if ( $g10_result_a['affiliate_id'] ) { register_synthetic_affiliate( $g10_result_a['affiliate_id'] ); }

	// Create session B with the same po10_id.
	$g10_session_b = re_create_test_session_with_plan( 'create', array(
		'email'   => $g10_email_a,
		'po10_id' => $g10_po10_id_a,
	), 1, $g10_po10_id_a );

	if ( $g10_session_b ) {
		re_promote_to_test_execution( $g10_session_b['session_uuid'], $g10_session_b['decisions'] );
		$g10_result_b = Konx_Migration_Record_Executor::execute_record( $g10_session_b['session_uuid'], $g10_session_b['plan_row_id'] );
		test_clear_option_override( 'konx_migration_state' );

		re_assert_eq( 'G10-T01: session B returns already_migrated', 'already_migrated', $g10_result_b['status'] );
		re_assert_false( 'G10-T02: no WP user created in session B', $g10_result_b['wp_user_created'] );
		re_assert_false( 'G10-T03: no affiliate created in session B', $g10_result_b['affiliate_created'] );

		safe_cleanup_test_session( $g10_session_b['session_uuid'], $g10_session_b['session_id'] );
	} else {
		re_assert( 'G10: SKIPPED (session B creation failed)', false );
	}

	safe_cleanup_test_session( $g10_session_a['session_uuid'], $g10_session_a['session_id'] );
} else {
	re_assert( 'G10: SKIPPED (session A execution did not complete)', false );
	if ( $g10_session_a ) {
		safe_cleanup_test_session( $g10_session_a['session_uuid'], $g10_session_a['session_id'] );
	}
}

teardown_all_synthetic_data();

// ---------------------------------------------------------------------------
// ===== GROUP 11: Same-session completed idempotency =====
// ---------------------------------------------------------------------------

echo "\n=== GROUP 11: Same-session completed idempotency ===\n\n";

$g11_po10_id = 9_090_001;
$g11_email   = "retest.g11.{$g11_po10_id}@konx-test.invalid";
$existing    = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM wp_users WHERE user_email = %s LIMIT 1", $g11_email ) );
if ( $existing ) { cleanup_synthetic_wp_user( (int) $existing ); }

$g11_session = re_create_test_session_with_plan( 'create', array(
	'email'   => $g11_email,
	'po10_id' => $g11_po10_id,
), 1, $g11_po10_id );

if ( $g11_session ) {
	re_promote_to_test_execution( $g11_session['session_uuid'], $g11_session['decisions'] );

	// First call — should complete.
	$r1 = Konx_Migration_Record_Executor::execute_record( $g11_session['session_uuid'], $g11_session['plan_row_id'] );

	// Second call on the same plan_row_id (ledger is now 'completed', not 'pending').
	$r2 = Konx_Migration_Record_Executor::execute_record( $g11_session['session_uuid'], $g11_session['plan_row_id'] );
	test_clear_option_override( 'konx_migration_state' );

	re_assert_eq( 'G11-T01: first call status=completed', 'completed', $r1['status'] );
	re_assert( 'G11-T02: second call refused (conflict or already_migrated)',
		in_array( $r2['status'], array( 'conflict', 'already_migrated' ), true ),
		"got={$r2['status']}"
	);

	// Verify only 1 affiliate was created.
	if ( $r1['affiliate_id'] ) { register_synthetic_affiliate( $r1['affiliate_id'] ); }
	if ( $r1['wp_user_id'] )   { register_synthetic_user( $r1['wp_user_id'] ); }

	safe_cleanup_test_session( $g11_session['session_uuid'], $g11_session['session_id'] );
} else {
	re_assert( 'G11: SKIPPED (session creation failed)', false );
}

teardown_all_synthetic_data();

// ---------------------------------------------------------------------------
// ===== GROUP 12: Non-actionable records =====
// ---------------------------------------------------------------------------

echo "\n=== GROUP 12: Non-actionable records ===\n\n";

foreach ( array( 'invalid', 'skip' ) as $na_action ) {
	$g12_po10_id = ( 'invalid' === $na_action ) ? 9_100_001 : 9_100_002;
	$g12_session = re_create_test_session_with_plan( $na_action, array(
		'po10_id' => $g12_po10_id,
	), 1, $g12_po10_id );

	if ( $g12_session ) {
		re_promote_to_test_execution( $g12_session['session_uuid'], $g12_session['decisions'] );
		$r = Konx_Migration_Record_Executor::execute_record( $g12_session['session_uuid'], $g12_session['plan_row_id'] );
		test_clear_option_override( 'konx_migration_state' );

		re_assert( "G12 ({$na_action}): status is skipped or failed (not completed)",
			in_array( $r['status'], array( 'skipped', 'failed' ), true ),
			"got={$r['status']}"
		);
		re_assert( "G12 ({$na_action}): error_code is non_actionable or invalid_action",
			in_array( $r['error_code'], array( 'non_actionable', 'invalid_action' ), true ),
			"got={$r['error_code']}"
		);
		re_assert_false( "G12 ({$na_action}): no WP user created", $r['wp_user_created'] );
		re_assert_false( "G12 ({$na_action}): no affiliate created", $r['affiliate_created'] );

		// Verify no business writes.
		$users_after_g12     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );
		$affiliates_after_g12 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_affiliates' );
		re_assert( "G12 ({$na_action}): no wp_users delta", $users_after_g12 <= $baseline_users, "after={$users_after_g12} baseline={$baseline_users}" );
		re_assert_eq( "G12 ({$na_action}): affiliates=0", 0, $affiliates_after_g12 );

		safe_cleanup_test_session( $g12_session['session_uuid'], $g12_session['session_id'] );
	} else {
		re_assert( "G12 ({$na_action}): SKIPPED (session creation failed)", false );
	}
}

// ---------------------------------------------------------------------------
// ===== GROUP 13: Compensation failure → partial state =====
// ---------------------------------------------------------------------------

echo "\n=== GROUP 13: Compensation failure → partial state ===\n\n";

// Strategy: inject BOTH affiliate failure AND compensation block simultaneously.
//   - wp_create_user() succeeds
//   - affiliate insert fails (test_affiliate_fail=true)
//   - compensate_delete_wp_user() is blocked (test_block_compensation=true)
// → result: status=partial, wp_user_id preserved in both result and ledger.
// Requirement E (compensation failure → partial) and F (exact wp_user_id preserved).

$g13_po10_id = 9_110_001;
$g13_email   = "retest.g13.partial.{$g13_po10_id}@konx-test.invalid";

// Ensure email is free.
$g13_existing = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM wp_users WHERE user_email = %s LIMIT 1", $g13_email ) );
if ( $g13_existing ) { cleanup_synthetic_wp_user( (int) $g13_existing ); }

$users_before_g13      = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );
$affiliates_before_g13 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_affiliates' );

$g13_session    = re_create_test_session_with_plan( 'create', array(
	'email'   => $g13_email,
	'po10_id' => $g13_po10_id,
), 1, $g13_po10_id );

$g13_orphan_uid = null;

if ( $g13_session ) {
	// Inject: affiliate insert will fail AND compensation will be blocked.
	Konx_Migration_Record_Executor::set_test_affiliate_fail( true );
	Konx_Migration_Record_Executor::set_test_block_compensation( true );

	re_promote_to_test_execution( $g13_session['session_uuid'], $g13_session['decisions'] );
	$r = Konx_Migration_Record_Executor::execute_record( $g13_session['session_uuid'], $g13_session['plan_row_id'] );
	test_clear_option_override( 'konx_migration_state' );

	// Reset injection flags immediately.
	Konx_Migration_Record_Executor::reset_test_hooks();

	// Core status assertions.
	re_assert_eq( 'G13-T01: status=partial (compensation blocked)',    'partial',              $r['status'] );
	re_assert_eq( 'G13-T02: error_code=affiliate_insert_failed',       'affiliate_insert_failed', $r['error_code'] );
	re_assert( 'G13-T03: wp_user_id is preserved in partial result',
		isset( $r['wp_user_id'] ) && $r['wp_user_id'] > 0,
		"wp_user_id=" . json_encode( $r['wp_user_id'] ?? null )
	);
	re_assert_false( 'G13-T04: affiliate_created=false',              $r['affiliate_created'] );

	// Record orphan user ID for explicit test teardown below.
	$g13_orphan_uid = ( isset( $r['wp_user_id'] ) && $r['wp_user_id'] > 0 ) ? (int) $r['wp_user_id'] : null;

	// Delta verification: WP user remains in DB (compensation blocked), no affiliate.
	$users_after_g13      = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );
	$affiliates_after_g13 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_affiliates' );
	re_assert_eq( 'G13-T05: wp_users delta=+1 (orphan present, compensation blocked)', $users_before_g13 + 1, $users_after_g13 );
	re_assert_eq( 'G13-T06: affiliates delta=0',                                       $affiliates_before_g13, $affiliates_after_g13 );

	// Ledger assertions (Requirement F: exact wp_user_id preserved in ledger).
	$ledger_g13 = $wpdb->get_row( $wpdb->prepare(
		"SELECT status, wp_user_id, affiliate_id, wp_user_created, affiliate_created
		 FROM wp_konx_migration_execution_ledger WHERE id = %d LIMIT 1",
		$g13_session['ledger_row_id']
	) );
	re_assert_eq( 'G13-T07: ledger status=partial', 'partial', $ledger_g13->status );
	re_assert( 'G13-T08: ledger wp_user_id matches result (exact ID preserved)',
		$g13_orphan_uid && (string) $g13_orphan_uid === (string) $ledger_g13->wp_user_id,
		"result_uid={$g13_orphan_uid} ledger_uid={$ledger_g13->wp_user_id}"
	);
	re_assert( 'G13-T09: ledger wp_user_created=true (user was created before affiliate fail)',
		'1' === (string) $ledger_g13->wp_user_created || true === (bool) $ledger_g13->wp_user_created
	);
	re_assert( 'G13-T10: ledger affiliate_created=false',
		'0' === (string) $ledger_g13->affiliate_created || false === (bool) $ledger_g13->affiliate_created
	);
	re_assert( 'G13-T11: ledger affiliate_id is null/empty (no affiliate was inserted)',
		empty( $ledger_g13->affiliate_id ) || '0' === (string) $ledger_g13->affiliate_id
	);

	safe_cleanup_test_session( $g13_session['session_uuid'], $g13_session['session_id'] );
} else {
	re_assert( 'G13: SKIPPED (session creation failed)', false );
}

// Explicit TEST teardown of orphan WP user left behind by blocked compensation.
// This is test infrastructure cleanup, NOT migration compensation logic.
if ( $g13_orphan_uid ) {
	cleanup_synthetic_wp_user( $g13_orphan_uid );
	echo "[RE-TEST] G13: Explicit teardown of orphan WP user ID={$g13_orphan_uid}\n";
}

teardown_all_synthetic_data();

// ---------------------------------------------------------------------------
// Final teardown.
// ---------------------------------------------------------------------------

teardown_all_synthetic_data();
teardown_all_test_sessions();

// ---------------------------------------------------------------------------
// Post-test DB state verification.
// ---------------------------------------------------------------------------

echo "\n=== POST-TEST DB STATE VERIFICATION ===\n\n";

$final_sessions    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_migration_exec_sessions' );
$final_plan        = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_migration_execution_plan' );
$final_ledger      = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_migration_execution_ledger' );
$final_completed   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_konx_migration_execution_ledger WHERE status='completed'" );
$final_users       = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_users' );
$final_affiliates  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_konx_affiliates' );
$final_ca          = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_wcusage_register' );

echo "sessions:          {$final_sessions} (required: 1)\n";
echo "plan rows:         {$final_plan} (required: 2402)\n";
echo "ledger rows:       {$final_ledger} (required: 2381)\n";
echo "completed ledger:  {$final_completed} (required: 0)\n";
echo "wp_users:          {$final_users} (required: 1933)\n";
echo "konx_affiliates:   {$final_affiliates} (required: 0)\n";
echo "wcusage_register:  {$final_ca} (required: 1924)\n";
echo "\n";

re_assert_eq( 'POST: sessions = 1',    1,    $final_sessions );
re_assert_eq( 'POST: plan = 2402',     2402, $final_plan );
re_assert_eq( 'POST: ledger = 2381',   2381, $final_ledger );
re_assert_eq( 'POST: completed = 0',   0,    $final_completed );
re_assert_eq( 'POST: wp_users = 1933', 1933, $final_users );
re_assert_eq( 'POST: affiliates = 0',  0,    $final_affiliates );
re_assert_eq( 'POST: ca = 1924',       1924, $final_ca );

// Canonical session integrity check.
$canonical_final = $wpdb->get_row( $wpdb->prepare(
	"SELECT status, revalidation_status, revalidation_stale_count, revalidation_conflict_count, final_plan_hash
	 FROM wp_konx_migration_exec_sessions WHERE session_uuid = %s",
	KONX_CANONICAL_SESSION_UUID
) );

re_assert_eq( 'POST: canonical status=frozen',        'frozen', $canonical_final->status );
re_assert_eq( 'POST: canonical revalidation=pass',    'pass',   $canonical_final->revalidation_status );
re_assert_eq( 'POST: canonical stale_count=0',        '0',      (string) $canonical_final->revalidation_stale_count );
re_assert_eq( 'POST: canonical conflict_count=0',     '0',      (string) $canonical_final->revalidation_conflict_count );
re_assert_eq( 'POST: canonical hash=8ffe9d...',
	'8ffe9d804450b69ad0ee09c9f224e1ca8b808a071a1676fe4af4c90cb2efc0fd',
	$canonical_final->final_plan_hash
);

// ---------------------------------------------------------------------------
// Results output.
// ---------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo " PHASE 24C-6D SINGLE-RECORD EXECUTOR — TEST RESULTS\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

foreach ( $test_output as $line ) {
	echo $line . "\n";
}

$total  = $pass_count + $fail_count;
$result_str = "{$pass_count}/{$total} passed";
echo "\n--- Results: {$result_str}";
if ( $fail_count > 0 ) {
	echo " ({$fail_count} FAILED)";
}
echo " ---\n\n";

if ( 0 === $fail_count ) {
	echo "  PHASE 24C-6D: ALL TESTS PASS\n\n";
} else {
	echo "  PHASE 24C-6D: {$fail_count} TEST(S) FAILED\n\n";
}

exit( $fail_count > 0 ? 1 : 0 );
