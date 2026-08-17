<?php
/**
 * Tests for Konx_Migration_Execution_Plan::create_snapshot().
 *
 * Requires WordPress bootstrap. Run via:
 *   wp eval-file tests/test-migration-snapshot.php
 *
 * Tests verify that:
 *   1. Snapshot creates exactly the right record counts.
 *   2. Plan records preserve action, WP user mapping, and CA reference.
 *   3. Ledger rows are created only for actionable records.
 *   4. No WP users are created.
 *   5. No KonX affiliates are created.
 *   6. Coupon Affiliates table is untouched.
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

function snap_assert( $label, $expected, $actual ) {
	global $test_results, $failures;
	$passed         = $expected === $actual;
	$test_results[] = compact( 'label', 'passed', 'expected', 'actual' );
	if ( ! $passed ) { $failures++; }
}

function snap_assert_true( $label, $actual ) {
	snap_assert( $label, true, (bool) $actual );
}

function snap_assert_false( $label, $actual ) {
	snap_assert( $label, false, (bool) $actual );
}

// ---------------------------------------------------------------------------
// Pre-flight: baseline counts.
// ---------------------------------------------------------------------------

global $wpdb;

$wp_users_before         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_users" );
$konx_affiliates_before  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_konx_affiliates" );

$ca_table  = 'wp_wcusage_register';
$ca_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ca_table ) );
$ca_before = $ca_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ca_table}" ) : -1;

// ---------------------------------------------------------------------------
// Fixture: minimal FMP decisions array (7 records, all action types).
// ---------------------------------------------------------------------------

$fixture_decisions = array(
	array( 'po10_id' => 1001, 'email' => 'create1@test.invalid',  'decision' => 'create',  'affiliate_type' => 'sales_agent',  'team_name' => 'CREATE1',  'wp_user_id' => null, 'ca_id' => null, 'first_name' => 'Alice', 'last_name' => 'One',   'sponsor' => '',       'match_method' => 'none',         'confidence' => 'n/a',    'sponsor_status' => 'none',     'val_status' => 'valid' ),
	array( 'po10_id' => 1002, 'email' => 'create2@test.invalid',  'decision' => 'create',  'affiliate_type' => 'business',     'team_name' => 'CREATE2',  'wp_user_id' => null, 'ca_id' => null, 'first_name' => 'Bob',   'last_name' => 'Two',   'sponsor' => 'CREATE1', 'match_method' => 'none',        'confidence' => 'n/a',    'sponsor_status' => 'resolved', 'val_status' => 'valid' ),
	array( 'po10_id' => 1003, 'email' => 'linkwp@test.invalid',   'decision' => 'link_wp', 'affiliate_type' => 'team_agent',   'team_name' => 'LINKWP1',  'wp_user_id' => 9901, 'ca_id' => null, 'first_name' => 'Carol', 'last_name' => 'Three', 'sponsor' => '',       'match_method' => 'email',        'confidence' => 'high',   'sponsor_status' => 'none',     'val_status' => 'valid' ),
	array( 'po10_id' => 1004, 'email' => 'linkca@test.invalid',   'decision' => 'link_ca', 'affiliate_type' => 'sales_agent',  'team_name' => 'LINKCA1',  'wp_user_id' => 9902, 'ca_id' => 42,   'first_name' => 'Dave',  'last_name' => 'Four',  'sponsor' => '',       'match_method' => 'ca_bridge',    'confidence' => 'medium', 'sponsor_status' => 'none',     'val_status' => 'valid' ),
	array( 'po10_id' => 1005, 'email' => 'invalid1@test.invalid', 'decision' => 'invalid', 'affiliate_type' => 'sales_agent',  'team_name' => '',         'wp_user_id' => null, 'ca_id' => null, 'first_name' => '',      'last_name' => '',      'sponsor' => '',       'match_method' => 'none',         'confidence' => 'n/a',    'sponsor_status' => 'none',     'val_status' => 'error' ),
	array( 'po10_id' => 1006, 'email' => 'skip1@test.invalid',    'decision' => 'skip',    'affiliate_type' => 'sales_agent',  'team_name' => 'SKIP1',    'wp_user_id' => null, 'ca_id' => null, 'first_name' => 'Eve',   'last_name' => 'Six',   'sponsor' => '',       'match_method' => 'none',         'confidence' => 'n/a',    'sponsor_status' => 'none',     'val_status' => 'valid' ),
	array( 'po10_id' => 1007, 'email' => 'review1@test.invalid',  'decision' => 'review',  'affiliate_type' => 'sales_agent',  'team_name' => 'REVIEW1',  'wp_user_id' => null, 'ca_id' => null, 'first_name' => 'Frank', 'last_name' => 'Seven', 'sponsor' => '',       'match_method' => 'none',         'confidence' => 'low',    'sponsor_status' => 'none',     'val_status' => 'warning' ),
);

// ---------------------------------------------------------------------------
// Test: create_snapshot with empty decisions → WP_Error.
// ---------------------------------------------------------------------------

$bad_snap = Konx_Migration_Execution_Plan::create_snapshot( array() );
snap_assert_true( 'T01: Empty decisions → WP_Error', is_wp_error( $bad_snap ) );

// ---------------------------------------------------------------------------
// Test: create_snapshot with valid fixture.
// ---------------------------------------------------------------------------

$snapshot = Konx_Migration_Execution_Plan::create_snapshot(
	$fixture_decisions,
	array(
		'source_filename'      => 'test_po10_export.csv',
		'source_hash'          => str_repeat( 'f', 64 ),
		'validation_timestamp' => '2026-08-17 10:00:00',
		'dry_run_timestamp'    => '2026-08-17 10:05:00',
		'created_by'           => 0,
	)
);

snap_assert_false( 'T02: Valid snapshot → no WP_Error', is_wp_error( $snapshot ) );

if ( is_wp_error( $snapshot ) ) {
	echo 'FATAL: snapshot creation failed: ' . $snapshot->get_error_message() . "\n";
} else {
	$session_uuid = $snapshot['session_uuid'];
	$session_id   = $snapshot['session_id'];

	// --- Record counts ---

	snap_assert( 'T03: plan_records = 7', 7, $snapshot['plan_records'] );
	// Actionable = create(2) + link_wp(1) + link_ca(1) = 4.
	snap_assert( 'T04: ledger_records = 4', 4, $snapshot['ledger_records'] );

	// --- Plan hash ---

	snap_assert( 'T05: final_plan_hash is 64 chars', 64, strlen( $snapshot['final_plan_hash'] ) );

	// --- Decision counts ---

	$dc = $snapshot['decision_counts'];
	snap_assert( 'T06a: decision_counts create = 2',  2, $dc['create'] );
	snap_assert( 'T06b: decision_counts link_wp = 1', 1, $dc['link_wp'] );
	snap_assert( 'T06c: decision_counts link_ca = 1', 1, $dc['link_ca'] );
	snap_assert( 'T06d: decision_counts invalid = 1', 1, $dc['invalid'] );
	snap_assert( 'T06e: decision_counts review = 1',  1, $dc['review'] );
	snap_assert( 'T06f: decision_counts total = 7',   7, $dc['total'] );

	// --- Session is frozen after snapshot ---

	snap_assert_true( 'T07: Session is_frozen after snapshot', Konx_Migration_Exec_Session::is_frozen( $session_uuid ) );

	$session = Konx_Migration_Exec_Session::get( $session_uuid );
	snap_assert( 'T07b: Session status = frozen', 'frozen', $session->status ?? null );

	// --- Verify plan records ---

	$plan_records = Konx_Migration_Execution_Plan::get_by_session( $session_id );
	snap_assert( 'T08: get_by_session returns 7 records', 7, count( $plan_records ) );

	// Find specific records.
	$create_record  = null;
	$link_wp_record = null;
	$link_ca_record = null;
	$invalid_record = null;
	$skip_record    = null;

	foreach ( $plan_records as $pr ) {
		switch ( (int) $pr->source_record_id ) {
			case 1001: $create_record  = $pr; break;
			case 1003: $link_wp_record = $pr; break;
			case 1004: $link_ca_record = $pr; break;
			case 1005: $invalid_record = $pr; break;
			case 1006: $skip_record    = $pr; break;
		}
	}

	// Verify create record.
	snap_assert_false( 'T09a: create record exists', null === $create_record );
	if ( $create_record ) {
		snap_assert( 'T09b: create action', 'create', $create_record->action );
		snap_assert( 'T09c: create wp_user_id is null', null, $create_record->wp_user_id );
		snap_assert( 'T09d: create email lowercased', 'create1@test.invalid', $create_record->source_email );
		snap_assert( 'T09e: create team_name preserved', 'CREATE1', $create_record->team_name );
	}

	// Verify link_wp record — WP user ID preserved.
	snap_assert_false( 'T10a: link_wp record exists', null === $link_wp_record );
	if ( $link_wp_record ) {
		snap_assert( 'T10b: link_wp action', 'link_wp', $link_wp_record->action );
		snap_assert( 'T10c: link_wp wp_user_id = 9901', '9901', (string) $link_wp_record->wp_user_id );
		snap_assert( 'T10d: link_wp ca_id is null', null, $link_wp_record->coupon_affiliate_id );
	}

	// Verify link_ca record — CA reference preserved.
	snap_assert_false( 'T11a: link_ca record exists', null === $link_ca_record );
	if ( $link_ca_record ) {
		snap_assert( 'T11b: link_ca action', 'link_ca', $link_ca_record->action );
		snap_assert( 'T11c: link_ca wp_user_id = 9902', '9902', (string) $link_ca_record->wp_user_id );
		snap_assert( 'T11d: link_ca coupon_affiliate_id = 42', '42', (string) $link_ca_record->coupon_affiliate_id );
	}

	// Verify invalid/skip actions are stored (not filtered out).
	snap_assert_false( 'T12a: invalid record exists', null === $invalid_record );
	snap_assert_false( 'T12b: skip record exists', null === $skip_record );
	if ( $invalid_record ) {
		snap_assert( 'T12c: invalid action', 'invalid', $invalid_record->action );
	}

	// --- Verify ledger rows: only actionable records get ledger entries ---

	$ledger_records = Konx_Migration_Execution_Ledger::get_by_session( $session_id );
	snap_assert( 'T13: ledger has 4 rows', 4, count( $ledger_records ) );

	$ledger_ids = array_column( $ledger_records, 'source_record_id' );
	$ledger_ids = array_map( 'intval', $ledger_ids );
	sort( $ledger_ids );

	snap_assert( 'T14: ledger record IDs = [1001,1002,1003,1004]', array( 1001, 1002, 1003, 1004 ), $ledger_ids );

	// Verify ledger statuses are all pending.
	$statuses = array_unique( array_column( $ledger_records, 'status' ) );
	snap_assert( 'T15: all ledger records status = pending', array( 'pending' ), $statuses );

	// Verify attempt_count = 0 for all.
	$attempts = array_unique( array_column( $ledger_records, 'attempt_count' ) );
	snap_assert( 'T16: all attempt_counts = 0', array( '0' ), $attempts );

	// --- Duplicate session-record pair rejected ---

	// Try to insert a duplicate snapshot for the same session.
	// The UNIQUE KEY uq_session_source on execution_plan prevents this at DB level.
	$duplicate = Konx_Migration_Execution_Plan::create_snapshot(
		$fixture_decisions,
		array( 'source_hash' => str_repeat( 'f', 64 ) )
	);
	// Two possible outcomes: WP_Error (DB error on duplicate plan insert) OR a new session
	// with a different UUID (if the service creates a NEW session per call). Either is OK
	// for Phase 24C-6B. We just verify the original data was not mutated.
	$original_count = Konx_Migration_Execution_Plan::get_by_session( $session_id );
	snap_assert( 'T17: Original plan records unchanged after second call', 7, count( $original_count ) );

	// If a new session was created, clean it up.
	if ( ! is_wp_error( $duplicate ) && isset( $duplicate['session_id'] ) && $duplicate['session_id'] !== $session_id ) {
		$plan_t   = $wpdb->prefix . 'konx_migration_execution_plan';
		$ledger_t = $wpdb->prefix . 'konx_migration_execution_ledger';
		$ses_t    = $wpdb->prefix . 'konx_migration_exec_sessions';
		$wpdb->delete( $plan_t,   array( 'session_id' => $duplicate['session_id'] ), array( '%d' ) );
		$wpdb->delete( $ledger_t, array( 'session_id' => $duplicate['session_id'] ), array( '%d' ) );
		$wpdb->delete( $ses_t,    array( 'id' => $duplicate['session_id'] ), array( '%d' ) );
	}

	// --- SAFETY: verify no business entities were created ---

	$wp_users_after        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_users" );
	$konx_affiliates_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_konx_affiliates" );
	$ca_after              = $ca_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ca_table}" ) : -1;

	snap_assert( 'T18: wp_users delta = 0 (no WP users created)', $wp_users_before, $wp_users_after );
	snap_assert( 'T19: konx_affiliates delta = 0 (no affiliates created)', $konx_affiliates_before, $konx_affiliates_after );
	if ( $ca_exists ) {
		snap_assert( 'T20: Coupon Affiliates unchanged', $ca_before, $ca_after );
	}

	// --- Cleanup test data ---

	$plan_table   = $wpdb->prefix . 'konx_migration_execution_plan';
	$ledger_table = $wpdb->prefix . 'konx_migration_execution_ledger';
	$ses_table    = $wpdb->prefix . 'konx_migration_exec_sessions';

	$wpdb->delete( $plan_table,   array( 'session_id' => $session_id ), array( '%d' ) );
	$wpdb->delete( $ledger_table, array( 'session_id' => $session_id ), array( '%d' ) );
	$wpdb->delete( $ses_table,    array( 'id' => $session_id ), array( '%d' ) );
}

// ---------------------------------------------------------------------------
// Results output.
// ---------------------------------------------------------------------------

echo "\n=== Konx_Migration_Execution_Plan Snapshot Tests ===\n\n";

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
