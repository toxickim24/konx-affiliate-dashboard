<?php
/**
 * Regression tests for the test-session teardown infrastructure.
 *
 * Verifies that:
 *   T01 — register_test_session() records UUID → ID mappings.
 *   T02 — safe_cleanup_test_session() deletes only the target session's rows.
 *   T03 — Unrelated sessions are never touched.
 *   T04 — Canonical session UUID is refused unconditionally.
 *   T05 — UUID/ID mismatch fails closed (no deletion occurs).
 *   T06 — safe_cleanup_test_session() is idempotent (double-call is safe).
 *   T07 — teardown_all_test_sessions() leaves the canonical session intact.
 *   T08 — No historical-discovery queries; only explicitly registered sessions
 *          are touched.
 *
 * Run via: php tests/test-migration-teardown.php
 *
 * @package KonxAffiliateDashboard
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once __DIR__ . '/bootstrap-integration.php';
}

// ---------------------------------------------------------------------------
// Minimal test harness.
// ---------------------------------------------------------------------------

$test_results = array();
$failures     = 0;

function td_assert( $label, $expected, $actual ) {
	global $test_results, $failures;
	$passed         = $expected === $actual;
	$test_results[] = compact( 'label', 'passed', 'expected', 'actual' );
	if ( ! $passed ) { $failures++; }
}

function td_assert_true( $label, $actual ) {
	td_assert( $label, true, (bool) $actual );
}

function td_assert_false( $label, $actual ) {
	td_assert( $label, false, (bool) $actual );
}

// ---------------------------------------------------------------------------
// Helpers: create/verify test sessions directly (no snapshot machinery).
// ---------------------------------------------------------------------------

global $wpdb;

$ses_t    = $wpdb->prefix . 'konx_migration_exec_sessions';
$plan_t   = $wpdb->prefix . 'konx_migration_execution_plan';
$ledger_t = $wpdb->prefix . 'konx_migration_execution_ledger';

/**
 * Insert a minimal draft session directly and return ['uuid' => ..., 'id' => ...].
 * Used only in this test file to avoid depending on the production factory.
 */
function td_create_raw_session( $suffix = '' ) {
	global $wpdb, $ses_t;
	$uuid = sprintf(
		'tdtest-%s-%s',
		$suffix ?: 'raw',
		substr( md5( uniqid( '', true ) ), 0, 12 )
	);
	// Pad to 36 chars (UUID length) if needed — our test UUIDs are readable strings.
	// For the mismatch test (T05) we need a valid-looking UUID to pass the initial check.
	// Use Exec_Session::create() for real UUID-format sessions.
	$result = Konx_Migration_Exec_Session::create( array(
		'source_type'             => 'csv',
		'source_filename'         => "teardown_test_{$suffix}.csv",
		'source_hash'             => str_repeat( 'f', 64 ),
		'final_plan_hash'         => str_repeat( md5( $uuid )[0], 64 ),
		'final_plan_record_count' => 1,
		'decision_create_count'   => 1,
		'created_by'              => 0,
	) );
	if ( is_wp_error( $result ) ) {
		return null;
	}
	return array( 'uuid' => $result['session_uuid'], 'id' => (int) $result['id'] );
}

/**
 * Insert one plan row + one ledger row for the given session_id.
 * Returns true on success.
 */
function td_insert_plan_and_ledger( $session_id, $po10_id ) {
	global $wpdb, $plan_t, $ledger_t;
	$ok_plan = $wpdb->insert(
		$plan_t,
		array(
			'session_id'       => $session_id,
			'source_system'    => 'powerof10',
			'source_record_id' => $po10_id,
			'source_email'     => "tdtest{$po10_id}@test.invalid",
			'action'           => 'create',
			'created_at'       => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%d', '%s', '%s', '%s' )
	);
	$plan_id = (int) $wpdb->insert_id;
	if ( ! $ok_plan || ! $plan_id ) {
		return false;
	}
	$ok_ledger = $wpdb->insert(
		$ledger_t,
		array(
			'session_id'       => $session_id,
			'plan_id'          => $plan_id,
			'source_system'    => 'powerof10',
			'source_record_id' => $po10_id,
			'approved_action'  => 'create',
			'status'           => 'pending',
			'attempt_count'    => 0,
			'created_at'       => current_time( 'mysql', true ),
		),
		array( '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s' )
	);
	return (bool) $ok_ledger;
}

/**
 * Count rows for a session_id in a given table.
 */
function td_count( $table, $session_id ) {
	global $wpdb;
	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE session_id = %d", $session_id )
	);
}

/**
 * Check whether a session row exists (any status).
 */
function td_session_exists( $session_id ) {
	global $wpdb, $ses_t;
	return null !== $wpdb->get_var(
		$wpdb->prepare( "SELECT id FROM `{$ses_t}` WHERE id = %d LIMIT 1", $session_id )
	);
}

// ---------------------------------------------------------------------------
// Pre-flight: tables must exist.
// ---------------------------------------------------------------------------

foreach ( array( $ses_t, $plan_t, $ledger_t ) as $t ) {
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
	td_assert_true( "PRE: table exists: {$t}", $t === $exists );
}

// ---------------------------------------------------------------------------
// T01 — register_test_session() records UUID → ID mappings.
// ---------------------------------------------------------------------------

echo "\n=== T01: register_test_session() records mappings ===\n\n";

global $_konx_test_created_sessions;
$_konx_test_created_sessions = array(); // Clear registry before test.

$t01a = td_create_raw_session( 't01a' );
$t01b = td_create_raw_session( 't01b' );

td_assert_false( 'T01: Registry empty before registration', isset( $_konx_test_created_sessions[ $t01a['uuid'] ] ) );

register_test_session( $t01a['uuid'], $t01a['id'] );
register_test_session( $t01b['uuid'], $t01b['id'] );

td_assert_true(  'T01b: UUID-A registered', isset( $_konx_test_created_sessions[ $t01a['uuid'] ] ) );
td_assert(       'T01c: UUID-A maps to correct ID', $t01a['id'], $_konx_test_created_sessions[ $t01a['uuid'] ] );
td_assert_true(  'T01d: UUID-B registered', isset( $_konx_test_created_sessions[ $t01b['uuid'] ] ) );
td_assert(       'T01e: UUID-B maps to correct ID', $t01b['id'], $_konx_test_created_sessions[ $t01b['uuid'] ] );
td_assert(       'T01f: Registry has exactly 2 entries', 2, count( $_konx_test_created_sessions ) );

// Canonical UUID must never be accepted into the registry.
register_test_session( KONX_CANONICAL_SESSION_UUID, 99999 );
td_assert_false( 'T01g: Canonical UUID refused by register_test_session()', isset( $_konx_test_created_sessions[ KONX_CANONICAL_SESSION_UUID ] ) );

// Cleanup T01 sessions (direct deletes — not yet testing safe_cleanup).
$wpdb->delete( $ses_t, array( 'id' => $t01a['id'] ), array( '%d' ) );
$wpdb->delete( $ses_t, array( 'id' => $t01b['id'] ), array( '%d' ) );
$_konx_test_created_sessions = array();

// ---------------------------------------------------------------------------
// T02 — safe_cleanup_test_session() deletes only the target session's rows.
// ---------------------------------------------------------------------------

echo "\n=== T02: safe_cleanup_test_session() removes target rows ===\n\n";

$t02 = td_create_raw_session( 't02' );
td_insert_plan_and_ledger( $t02['id'], 30001 );
register_test_session( $t02['uuid'], $t02['id'] );

td_assert( 'T02a: plan row exists before cleanup',   1, td_count( $plan_t,   $t02['id'] ) );
td_assert( 'T02b: ledger row exists before cleanup', 1, td_count( $ledger_t, $t02['id'] ) );
td_assert_true( 'T02c: session row exists before cleanup', td_session_exists( $t02['id'] ) );

$ok = safe_cleanup_test_session( $t02['uuid'], $t02['id'] );

td_assert_true(  'T02d: safe_cleanup returns true',        $ok );
td_assert(       'T02e: plan rows deleted',    0, td_count( $plan_t,   $t02['id'] ) );
td_assert(       'T02f: ledger rows deleted',  0, td_count( $ledger_t, $t02['id'] ) );
td_assert_false( 'T02g: session row deleted',  td_session_exists( $t02['id'] ) );
td_assert_false( 'T02h: UUID deregistered from registry', isset( $_konx_test_created_sessions[ $t02['uuid'] ] ) );

// ---------------------------------------------------------------------------
// T03 — Unrelated sessions are never touched.
// ---------------------------------------------------------------------------

echo "\n=== T03: Unrelated sessions untouched ===\n\n";

$t03_target    = td_create_raw_session( 't03_target' );
$t03_bystander = td_create_raw_session( 't03_bystander' );

td_insert_plan_and_ledger( $t03_target['id'],    40001 );
td_insert_plan_and_ledger( $t03_bystander['id'], 40002 );

register_test_session( $t03_target['uuid'], $t03_target['id'] );
// Bystander intentionally NOT registered.

$ok3 = safe_cleanup_test_session( $t03_target['uuid'], $t03_target['id'] );

td_assert_true(  'T03a: cleanup of target returns true', $ok3 );
td_assert(       'T03b: target plan rows deleted',    0, td_count( $plan_t,   $t03_target['id'] ) );
td_assert(       'T03c: target ledger rows deleted',  0, td_count( $ledger_t, $t03_target['id'] ) );
td_assert_false( 'T03d: target session row deleted',  td_session_exists( $t03_target['id'] ) );

// Bystander rows must be untouched.
td_assert( 'T03e: bystander plan rows intact',    1, td_count( $plan_t,   $t03_bystander['id'] ) );
td_assert( 'T03f: bystander ledger rows intact',  1, td_count( $ledger_t, $t03_bystander['id'] ) );
td_assert_true( 'T03g: bystander session intact', td_session_exists( $t03_bystander['id'] ) );

// Cleanup bystander manually.
$wpdb->delete( $plan_t,   array( 'session_id' => $t03_bystander['id'] ), array( '%d' ) );
$wpdb->delete( $ledger_t, array( 'session_id' => $t03_bystander['id'] ), array( '%d' ) );
$wpdb->delete( $ses_t,    array( 'id' => $t03_bystander['id'] ),         array( '%d' ) );

// ---------------------------------------------------------------------------
// T04 — Canonical session UUID is refused unconditionally.
// ---------------------------------------------------------------------------

echo "\n=== T04: Canonical UUID refused by safe_cleanup_test_session() ===\n\n";

// Verify the canonical session still exists (basic sanity).
$canonical_row = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT id, status FROM `{$ses_t}` WHERE session_uuid = %s LIMIT 1",
		KONX_CANONICAL_SESSION_UUID
	)
);
td_assert_true( 'T04a: Canonical session exists in DB', null !== $canonical_row );

if ( null !== $canonical_row ) {
	$canonical_id = (int) $canonical_row->id;

	$refused = safe_cleanup_test_session( KONX_CANONICAL_SESSION_UUID, $canonical_id );

	td_assert_false( 'T04b: safe_cleanup refuses canonical UUID (returns false)', $refused );

	// Canonical session row must still be present.
	td_assert_true( 'T04c: Canonical session row still exists after refused cleanup', td_session_exists( $canonical_id ) );
}

// ---------------------------------------------------------------------------
// T05 — UUID/ID mismatch fails closed (no deletion occurs).
// ---------------------------------------------------------------------------

echo "\n=== T05: UUID/ID mismatch fails closed ===\n\n";

$t05a = td_create_raw_session( 't05a' );
$t05b = td_create_raw_session( 't05b' );

td_insert_plan_and_ledger( $t05a['id'], 50001 );
td_insert_plan_and_ledger( $t05b['id'], 50002 );
register_test_session( $t05a['uuid'], $t05a['id'] );
register_test_session( $t05b['uuid'], $t05b['id'] );

// Supply UUID of t05a but ID of t05b — deliberate mismatch.
$mismatch_result = safe_cleanup_test_session( $t05a['uuid'], $t05b['id'] );

// The call should either find no row (UUID+ID don't match → idempotent return)
// or roll back. Either way, BOTH sessions' rows must be intact.
td_assert_true( 'T05a: t05a session still exists after mismatch call', td_session_exists( $t05a['id'] ) );
td_assert_true( 'T05b: t05b session still exists after mismatch call', td_session_exists( $t05b['id'] ) );
td_assert( 'T05c: t05a plan rows intact',   1, td_count( $plan_t,   $t05a['id'] ) );
td_assert( 'T05d: t05b plan rows intact',   1, td_count( $plan_t,   $t05b['id'] ) );
td_assert( 'T05e: t05a ledger rows intact', 1, td_count( $ledger_t, $t05a['id'] ) );
td_assert( 'T05f: t05b ledger rows intact', 1, td_count( $ledger_t, $t05b['id'] ) );

// Cleanup both properly.
safe_cleanup_test_session( $t05a['uuid'], $t05a['id'] );
safe_cleanup_test_session( $t05b['uuid'], $t05b['id'] );

// ---------------------------------------------------------------------------
// T06 — safe_cleanup_test_session() is idempotent.
// ---------------------------------------------------------------------------

echo "\n=== T06: safe_cleanup_test_session() is idempotent ===\n\n";

$t06 = td_create_raw_session( 't06' );
td_insert_plan_and_ledger( $t06['id'], 60001 );
register_test_session( $t06['uuid'], $t06['id'] );

$first  = safe_cleanup_test_session( $t06['uuid'], $t06['id'] );
$second = safe_cleanup_test_session( $t06['uuid'], $t06['id'] ); // Already gone.

td_assert_true( 'T06a: First cleanup returns true',  $first );
td_assert_true( 'T06b: Second cleanup returns true (idempotent)', $second );
td_assert( 'T06c: Plan rows still 0 after second call',   0, td_count( $plan_t,   $t06['id'] ) );
td_assert( 'T06d: Ledger rows still 0 after second call', 0, td_count( $ledger_t, $t06['id'] ) );
td_assert_false( 'T06e: Session still gone after second call', td_session_exists( $t06['id'] ) );

// ---------------------------------------------------------------------------
// T07 — teardown_all_test_sessions() leaves the canonical session intact.
// ---------------------------------------------------------------------------

echo "\n=== T07: teardown_all_test_sessions() preserves canonical session ===\n\n";

// Create two more test sessions and register them.
$t07a = td_create_raw_session( 't07a' );
$t07b = td_create_raw_session( 't07b' );
td_insert_plan_and_ledger( $t07a['id'], 70001 );
td_insert_plan_and_ledger( $t07b['id'], 70002 );
register_test_session( $t07a['uuid'], $t07a['id'] );
register_test_session( $t07b['uuid'], $t07b['id'] );

// Also confirm canonical session exists before teardown.
$canonical_before = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM `{$ses_t}` WHERE session_uuid = %s",
		KONX_CANONICAL_SESSION_UUID
	)
);
td_assert( 'T07a: Canonical session present before teardown_all', '1', (string) $canonical_before );

teardown_all_test_sessions();

// Test sessions must be gone.
td_assert_false( 'T07b: t07a session gone after teardown_all', td_session_exists( $t07a['id'] ) );
td_assert_false( 'T07c: t07b session gone after teardown_all', td_session_exists( $t07b['id'] ) );

// Canonical must still be present.
$canonical_after = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM `{$ses_t}` WHERE session_uuid = %s",
		KONX_CANONICAL_SESSION_UUID
	)
);
td_assert( 'T07d: Canonical session preserved after teardown_all', '1', (string) $canonical_after );

// ---------------------------------------------------------------------------
// T08 — No historical-discovery queries; only registered sessions cleaned.
// ---------------------------------------------------------------------------
//
// Verifies the contract: teardown_all_test_sessions() only touches sessions
// that were explicitly registered. It does NOT query for "all sessions not
// created by the production wizard" or any other historical discovery pattern.
//
// Methodology: create an unregistered "stray" session with a distinctive hash,
// run teardown_all_test_sessions(), verify the stray row was not deleted.
// ---------------------------------------------------------------------------

echo "\n=== T08: teardown_all_test_sessions() only removes registered sessions ===\n\n";

$_konx_test_created_sessions = array(); // Empty registry.

$t08_stray = td_create_raw_session( 't08stray' );
td_insert_plan_and_ledger( $t08_stray['id'], 80001 );
// Deliberately NOT registered.

teardown_all_test_sessions(); // Should be a no-op.

td_assert_true( 'T08a: Stray session untouched by empty-registry teardown_all', td_session_exists( $t08_stray['id'] ) );
td_assert( 'T08b: Stray plan rows untouched',   1, td_count( $plan_t,   $t08_stray['id'] ) );
td_assert( 'T08c: Stray ledger rows untouched', 1, td_count( $ledger_t, $t08_stray['id'] ) );

// Cleanup stray manually.
$wpdb->delete( $plan_t,   array( 'session_id' => $t08_stray['id'] ), array( '%d' ) );
$wpdb->delete( $ledger_t, array( 'session_id' => $t08_stray['id'] ), array( '%d' ) );
$wpdb->delete( $ses_t,    array( 'id' => $t08_stray['id'] ),         array( '%d' ) );

// ---------------------------------------------------------------------------
// Results output.
// ---------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo " TEST TEARDOWN INFRASTRUCTURE — REGRESSION TEST RESULTS\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

foreach ( $test_results as $r ) {
	$status = $r['passed'] ? 'PASS' : 'FAIL';
	echo "[{$status}] {$r['label']}\n";
	if ( ! $r['passed'] ) {
		echo "       Expected: " . json_encode( $r['expected'] ) . "\n";
		echo "       Actual:   " . json_encode( $r['actual'] ) . "\n";
	}
}

$total  = count( $test_results );
$passed = $total - $failures;

echo "\n--- Results: {$passed}/{$total} passed";
if ( $failures > 0 ) {
	echo " ({$failures} FAILED)";
}
echo " ---\n\n";

if ( 0 === $failures ) {
	echo "  TEARDOWN INFRASTRUCTURE: ALL TESTS PASS\n\n";
} else {
	echo "  TEARDOWN INFRASTRUCTURE: {$failures} TEST(S) FAILED\n\n";
}

// Final explicit teardown (registry should be empty by now, but belt-and-suspenders).
teardown_all_test_sessions();

exit( $failures > 0 ? 1 : 0 );
