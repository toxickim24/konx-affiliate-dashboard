<?php
/**
 * Tests for Konx_Migration_Exec_Session.
 *
 * Tests the immutable session table against a real MySQL database.
 *
 * Run standalone: php tests/test-migration-exec-session.php
 * Or via wp eval-file if WP-CLI is available.
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

function ses_assert( $label, $expected, $actual ) {
	global $test_results, $failures;
	$passed         = $expected === $actual;
	$test_results[] = compact( 'label', 'passed', 'expected', 'actual' );
	if ( ! $passed ) { $failures++; }
}

function ses_assert_true( $label, $actual ) {
	ses_assert( $label, true, (bool) $actual );
}

function ses_assert_false( $label, $actual ) {
	ses_assert( $label, false, (bool) $actual );
}

function ses_assert_not_null( $label, $actual ) {
	global $test_results, $failures;
	$passed         = null !== $actual;
	$test_results[] = array( 'label' => $label, 'passed' => $passed, 'expected' => '(not null)', 'actual' => json_encode( $actual ) );
	if ( ! $passed ) { $failures++; }
}

// ---------------------------------------------------------------------------
// Pre-flight: verify table exists.
// ---------------------------------------------------------------------------

global $wpdb;
$table  = $wpdb->prefix . 'konx_migration_exec_sessions';
$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
ses_assert_true( 'PRE: wp_konx_migration_exec_sessions table exists', $table === $exists );

if ( $table !== $exists ) {
	echo "[FATAL] Table does not exist. Run DB upgrade first.\n";
	exit(1);
}

// ---------------------------------------------------------------------------
// Helper.
// ---------------------------------------------------------------------------

$valid_plan_hash = str_repeat( 'a', 64 );

function make_session_args( $plan_hash = null ) {
	global $valid_plan_hash;
	return array(
		'source_type'             => 'csv',
		'source_filename'         => 'po10_export.csv',
		'source_hash'             => str_repeat( 'b', 64 ),
		'final_plan_hash'         => $plan_hash ?? $valid_plan_hash,
		'final_plan_record_count' => 10,
		'decision_create_count'   => 5,
		'decision_link_wp_count'  => 2,
		'decision_link_ca_count'  => 1,
		'decision_invalid_count'  => 1,
		'decision_review_count'   => 1,
		'created_by'              => 1,
	);
}

// ---------------------------------------------------------------------------
// T01: Missing plan hash → WP_Error
// ---------------------------------------------------------------------------
$bad1 = Konx_Migration_Exec_Session::create( array( 'final_plan_record_count' => 5 ) );
ses_assert_true( 'T01: Missing plan_hash → WP_Error', is_wp_error( $bad1 ) );

// ---------------------------------------------------------------------------
// T02: Short hash → WP_Error
// ---------------------------------------------------------------------------
$bad2 = Konx_Migration_Exec_Session::create( array_merge( make_session_args(), array( 'final_plan_hash' => 'short' ) ) );
ses_assert_true( 'T02: Short plan_hash → WP_Error', is_wp_error( $bad2 ) );

// ---------------------------------------------------------------------------
// T03: Zero record count → WP_Error
// ---------------------------------------------------------------------------
$bad3 = Konx_Migration_Exec_Session::create( array_merge( make_session_args(), array( 'final_plan_record_count' => 0 ) ) );
ses_assert_true( 'T03: Zero record count → WP_Error', is_wp_error( $bad3 ) );

// ---------------------------------------------------------------------------
// T04: Valid create succeeds
// ---------------------------------------------------------------------------
$created = Konx_Migration_Exec_Session::create( make_session_args() );
ses_assert_false( 'T04: Valid create → no WP_Error', is_wp_error( $created ) );
ses_assert_true(  'T04b: Returns session_uuid', ! empty( $created['session_uuid'] ) );
ses_assert_true(  'T04c: Returns id', ! empty( $created['id'] ) );

$uuid = $created['session_uuid'];
$sid  = $created['id'];

ses_assert( 'T04d: UUID is 36 chars', 36, strlen( $uuid ) );
ses_assert_true( 'T04e: UUID matches v4 pattern',
	(bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid )
);

// ---------------------------------------------------------------------------
// T05: get() returns correct row
// ---------------------------------------------------------------------------
$fetched = Konx_Migration_Exec_Session::get( $uuid );
ses_assert_not_null( 'T05: get() returns row', $fetched );
ses_assert( 'T05b: status is draft', 'draft', $fetched->status ?? null );
ses_assert( 'T05c: plugin_version persisted', KONX_AFFILIATE_VERSION, $fetched->plugin_version ?? null );
ses_assert( 'T05d: db_schema_version persisted', KONX_AFFILIATE_DB_VERSION, $fetched->database_schema_version ?? null );
ses_assert( 'T05e: final_plan_hash persisted', str_repeat( 'a', 64 ), $fetched->final_plan_hash ?? null );
ses_assert( 'T05f: record_count persisted', '10', (string)($fetched->final_plan_record_count ?? '') );
ses_assert( 'T05g: decision_create_count', '5', (string)($fetched->decision_create_count ?? '') );
ses_assert( 'T05h: source_type', 'csv', $fetched->source_type ?? null );
ses_assert( 'T05i: source_filename', 'po10_export.csv', $fetched->source_filename ?? null );

// ---------------------------------------------------------------------------
// T06: is_frozen() → false for draft
// ---------------------------------------------------------------------------
ses_assert_false( 'T06: Draft session is_frozen() → false', Konx_Migration_Exec_Session::is_frozen( $uuid ) );

// ---------------------------------------------------------------------------
// T07: freeze() draft → frozen
// ---------------------------------------------------------------------------
$freeze_r = Konx_Migration_Exec_Session::freeze( $uuid );
ses_assert_false( 'T07: freeze() returns no WP_Error', is_wp_error( $freeze_r ) );

$after = Konx_Migration_Exec_Session::get( $uuid );
ses_assert( 'T07b: Status is frozen', 'frozen', $after->status ?? null );
ses_assert_true( 'T07c: is_frozen() → true', Konx_Migration_Exec_Session::is_frozen( $uuid ) );

// Identity fields must be unchanged after freeze.
ses_assert( 'T07d: plan_hash unchanged after freeze', str_repeat( 'a', 64 ), $after->final_plan_hash ?? null );
ses_assert( 'T07e: record_count unchanged', '10', (string)($after->final_plan_record_count ?? '') );
ses_assert( 'T07f: plugin_version unchanged', KONX_AFFILIATE_VERSION, $after->plugin_version ?? null );
ses_assert( 'T07g: schema_version unchanged', KONX_AFFILIATE_DB_VERSION, $after->database_schema_version ?? null );

// ---------------------------------------------------------------------------
// T08: Cannot re-freeze → WP_Error
// ---------------------------------------------------------------------------
$refreeze = Konx_Migration_Exec_Session::freeze( $uuid );
ses_assert_true( 'T08: Re-freeze frozen session → WP_Error', is_wp_error( $refreeze ) );

// ---------------------------------------------------------------------------
// T09: get_by_plan_hash returns the session
// ---------------------------------------------------------------------------
$by_hash = Konx_Migration_Exec_Session::get_by_plan_hash( str_repeat( 'a', 64 ) );
ses_assert_true( 'T09: get_by_plan_hash finds the session', count( $by_hash ) >= 1 );
$uuids_found = array_column( $by_hash, 'session_uuid' );
ses_assert_true( 'T09b: UUID is in results', in_array( $uuid, $uuids_found, true ) );

// ---------------------------------------------------------------------------
// T10: Two sessions have different UUIDs
// ---------------------------------------------------------------------------
$created2 = Konx_Migration_Exec_Session::create( make_session_args( str_repeat( 'c', 64 ) ) );
ses_assert_false( 'T10: Second session no WP_Error', is_wp_error( $created2 ) );
if ( ! is_wp_error( $created2 ) ) {
	ses_assert_true( 'T10b: UUIDs differ', $uuid !== $created2['session_uuid'] );
	// Cleanup second session.
	$wpdb->delete( $wpdb->prefix . 'konx_migration_exec_sessions', array( 'id' => $created2['id'] ), array( '%d' ) );
}

// ---------------------------------------------------------------------------
// T11: invalidate() works on frozen session
// ---------------------------------------------------------------------------
$inv = Konx_Migration_Exec_Session::invalidate( $uuid );
ses_assert_false( 'T11: invalidate() → no WP_Error', is_wp_error( $inv ) );
$inv_row = Konx_Migration_Exec_Session::get( $uuid );
ses_assert( 'T11b: Status is invalidated', 'invalidated', $inv_row->status ?? null );

// ---------------------------------------------------------------------------
// T12: invalidate() non-existent → WP_Error
// ---------------------------------------------------------------------------
$bad_inv = Konx_Migration_Exec_Session::invalidate( 'not-real-uuid-here-0000-000000000000' );
ses_assert_true( 'T12: invalidate non-existent → WP_Error', is_wp_error( $bad_inv ) );

// ---------------------------------------------------------------------------
// T13: get_by_id() works
// ---------------------------------------------------------------------------
$by_id = Konx_Migration_Exec_Session::get_by_id( $sid );
ses_assert_not_null( 'T13: get_by_id() returns row', $by_id );
ses_assert( 'T13b: get_by_id() UUID matches', $uuid, $by_id->session_uuid ?? null );

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------
$wpdb->delete( $wpdb->prefix . 'konx_migration_exec_sessions', array( 'id' => $sid ), array( '%d' ) );

// ---------------------------------------------------------------------------
// Results output.
// ---------------------------------------------------------------------------
echo "\n=== Konx_Migration_Exec_Session Tests ===\n\n";
foreach ( $test_results as $r ) {
	$s = $r['passed'] ? 'PASS' : 'FAIL';
	echo "[{$s}] {$r['label']}\n";
	if ( ! $r['passed'] ) {
		echo "       Expected: " . json_encode( $r['expected'] ) . "\n";
		echo "       Actual:   " . json_encode( $r['actual'] ) . "\n";
	}
}
$total  = count( $test_results );
$passed = $total - $failures;
echo "\n--- Results: {$passed}/{$total} passed";
if ( $failures > 0 ) { echo " ({$failures} FAILED)"; }
echo " ---\n\n";
exit( $failures > 0 ? 1 : 0 );
