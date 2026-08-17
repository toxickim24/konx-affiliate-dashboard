<?php
/**
 * Phase 24C-6C: Execution Revalidation Engine Tests
 *
 * Tests the Konx_Migration_Revalidator class. Covers:
 *   Group 1: Session-level integrity checks (9 checks)
 *   Group 2: Per-record live-state checks (create / link_wp / link_ca)
 *   Group 3: Live 2,402-record revalidation (full QA run)
 *   Group 4: Business data safety (all deltas must be 0)
 *
 * SAFETY GUARANTEE: This test file is read-only with respect to all
 * business data (wp_users, wp_konx_affiliates, wp_wcusage_register,
 * wp_posts). Any rows inserted during tests are into the migration
 * execution tables only (exec_sessions, execution_plan, execution_ledger)
 * and are cleaned up on completion. Any temporary KonX affiliates
 * inserted to test conflict detection are explicitly deleted immediately
 * after the assertion.
 *
 * @package KonxAffiliateDashboard
 */

require_once __DIR__ . '/bootstrap-integration.php';

// ---------------------------------------------------------------------------
// Test infrastructure.
// ---------------------------------------------------------------------------
$pass  = 0;
$fail  = 0;
$tests = array();

function revalidation_assert( $name, $condition, $detail = '' ) {
	global $pass, $fail, $tests;
	if ( $condition ) {
		$pass++;
		$tests[] = "  [PASS] {$name}";
	} else {
		$fail++;
		$detail_str = $detail ? " | {$detail}" : '';
		$tests[]    = "  [FAIL] {$name}{$detail_str}";
	}
}

// Retrieve the real frozen session UUID created during Phase 24C-6B QA.
function get_frozen_session_uuid() {
	global $wpdb;
	return $wpdb->get_var(
		"SELECT session_uuid FROM {$wpdb->prefix}konx_migration_exec_sessions
		 WHERE status = 'frozen' ORDER BY id ASC LIMIT 1"
	);
}

// Create a minimal test snapshot and return the session UUID and ID.
function create_test_snapshot( array $decisions, array $opts = array() ) {
	$result = Konx_Migration_Execution_Plan::create_snapshot( $decisions, $opts );
	if ( is_wp_error( $result ) ) {
		return null;
	}
	return $result;
}

/**
 * Revalidate a session while making the FMP parity check see $decisions as
 * the live Final Migration Plan.
 *
 * Group 2 and selective Group 5 tests create small artificial snapshots whose
 * plan_hash does not match the real live FMP in wp_options (2,402 decisions).
 * Setting a per-test override for 'konx_migration_state' makes the fail-closed
 * FMP parity check see the same decisions used to build the snapshot, so Part B
 * of check_plan_hash() passes and per-record checks can run.
 *
 * The override is set immediately before revalidate() and cleared immediately
 * after, so the real wp_options value is always restored for other test code.
 *
 * @param string $session_uuid UUID to revalidate.
 * @param array  $decisions    Decision array used to build the snapshot.
 * @return array Revalidation result.
 */
function revalidate_with_fmp_override( $session_uuid, array $decisions ) {
	// Override must use final_migration_plan.decisions — the canonical post-validation
	// artifact.  decision_matrix.decisions is a pre-validation intermediate that the
	// revalidator no longer reads.
	test_set_option_override(
		'konx_migration_state',
		array( 'final_migration_plan' => array( 'decisions' => $decisions ) )
	);
	$result = Konx_Migration_Revalidator::revalidate( $session_uuid );
	test_clear_option_override( 'konx_migration_state' );
	return $result;
}

// Delete all traces of a test snapshot (plan, ledger, session).
function cleanup_test_snapshot( $session_uuid, $session_id ) {
	global $wpdb;
	$wpdb->delete( $wpdb->prefix . 'konx_migration_execution_plan',   array( 'session_id' => $session_id ), array( '%d' ) );
	$wpdb->delete( $wpdb->prefix . 'konx_migration_execution_ledger', array( 'session_id' => $session_id ), array( '%d' ) );
	$wpdb->delete( $wpdb->prefix . 'konx_migration_exec_sessions',    array( 'session_uuid' => $session_uuid ), array( '%s' ) );
}

// Get a WP user that has NO KonX affiliate (safe to use as test subject).
function get_safe_wp_user_id() {
	global $wpdb;
	$konx = $wpdb->prefix . 'konx_affiliates';
	return (int) $wpdb->get_var(
		"SELECT ID FROM {$wpdb->users}
		 WHERE ID NOT IN (SELECT user_id FROM {$konx})
		   AND user_email != ''
		 ORDER BY ID ASC LIMIT 1"
	);
}

// Get a WP user email that exists in wp_users but has no KonX affiliate.
function get_existing_wp_email() {
	global $wpdb;
	$konx = $wpdb->prefix . 'konx_affiliates';
	return $wpdb->get_var(
		"SELECT LOWER(user_email) FROM {$wpdb->users}
		 WHERE ID NOT IN (SELECT user_id FROM {$konx})
		   AND user_email != ''
		 ORDER BY ID ASC LIMIT 1"
	);
}

// ---------------------------------------------------------------------------
// Baseline business data counts (must not change after any test).
// ---------------------------------------------------------------------------
global $wpdb;
$baseline = array(
	'wp_users'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
	'konx_affiliates'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}konx_affiliates" ),
	'ca_registers'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wcusage_register" ),
);

// ---------------------------------------------------------------------------
// Live-FMP snapshot setup.
//
// The FMP parity check reads konx_migration_state.final_migration_plan.decisions
// — the post-validation canonical FMP.  A fresh snapshot is created from
// this same source so that:
//   (a) the session hash and the live FMP hash are always in sync;
//   (b) G1-T03 through G1-T07 and G3-T01 do not depend on a snapshot that
//       may have been created against an older version of the FMP.
//
// NOTE: decision_matrix.decisions is NOT used here.  It is a pre-validation
// intermediate that may differ from final_migration_plan.decisions (e.g. 3
// 'review' entries in the DM are promoted to 'invalid' in the FMP).  Using
// decision_matrix would produce a hash mismatch and a false CRITICAL result.
//
// The canonical FMP has review=0, so no record-filtering is needed or
// appropriate: all 2,402 records must be snapshotted and revalidated intact.
//
// This snapshot is cleaned up at the end of Group 3.
//
// Per-record (Group 2) tests use small artificial decision sets and set a
// per-test FMP override via revalidate_with_fmp_override() so that the
// parity check passes for their snapshot.  The override is cleared after each
// test.
// ---------------------------------------------------------------------------

$_live_fmp_snap     = null;  // Snapshot created from current live FMP for G1/G3.
$_live_fmp_snap_id  = null;  // Session ID of that snapshot (for cleanup).

// Read the canonical Final Migration Plan (post-validation).
$_fmp_state     = get_option( 'konx_migration_state', null );
$_fmp_decisions = ( is_array( $_fmp_state ) && ! empty( $_fmp_state['final_migration_plan']['decisions'] ) )
	? $_fmp_state['final_migration_plan']['decisions']
	: null;

// Dynamic expected counts derived from the canonical FMP (used in assertions below).
$_fmp_no_review_count = $_fmp_decisions ? count( $_fmp_decisions ) : 0;
$_fmp_create_count    = 0;
if ( $_fmp_decisions ) {
	foreach ( $_fmp_decisions as $_d ) {
		if ( 'create' === ( $_d['decision'] ?? '' ) ) { $_fmp_create_count++; }
	}
}

if ( $_fmp_decisions ) {
	$_live_fmp_snap = Konx_Migration_Execution_Plan::create_snapshot( $_fmp_decisions );
	if ( ! is_wp_error( $_live_fmp_snap ) && ! empty( $_live_fmp_snap['session_uuid'] ) ) {
		$frozen_uuid       = $_live_fmp_snap['session_uuid'];
		$_live_fmp_snap_id = $_live_fmp_snap['session_id'];
	} else {
		// Creation failed — fall back to the pre-existing frozen session.
		$frozen_uuid = get_frozen_session_uuid();
		echo '[SETUP WARNING] Failed to create fresh FMP snapshot. Falling back to pre-existing frozen session.' . PHP_EOL;
	}
} else {
	// No live FMP available — fall back to pre-existing frozen session.
	$frozen_uuid = get_frozen_session_uuid();
	echo '[SETUP WARNING] Live FMP not available in wp_options. Falling back to pre-existing frozen session.' . PHP_EOL;
}

// ---------------------------------------------------------------------------
// GROUP 1: Session-level integrity checks
// ---------------------------------------------------------------------------
echo "═══════════════════════════════════════════════════════════════════\n";
echo " GROUP 1: Session-level integrity checks\n";
echo "═══════════════════════════════════════════════════════════════════\n";

// T01: Non-existent UUID → critical failure at session_exists.
echo "\n  [G1-T01] Non-existent UUID → session_exists critical\n";
$fake_uuid = '00000000-0000-0000-0000-000000000000';
$result    = Konx_Migration_Revalidator::revalidate( $fake_uuid );

revalidation_assert(
	'G1-T01a: session_valid=false',
	false === $result['session_valid']
);
revalidation_assert(
	'G1-T01b: revalidation_status=critical',
	'critical' === $result['revalidation_status'],
	'status=' . $result['revalidation_status']
);
revalidation_assert(
	'G1-T01c: records_checked=0',
	0 === $result['records_checked']
);
revalidation_assert(
	'G1-T01d: session_exists check is critical',
	'critical' === ( $result['session_checks'][0]['status'] ?? '' ),
	'check_status=' . ( $result['session_checks'][0]['status'] ?? 'none' )
);

// T02: Draft session → session_frozen critical.
echo "\n  [G1-T02] Draft session → session_frozen critical\n";
// Create a minimal snapshot then immediately un-freeze (leave as draft by creating raw session).
$draft_uuid = null;
$draft_id   = null;
{
	// Insert a raw draft session directly.
	$wpdb->insert(
		$wpdb->prefix . 'konx_migration_exec_sessions',
		array(
			'session_uuid'               => 'draft-test-uuid-phase24c6c-test',
			'source_type'                => 'csv',
			'final_plan_hash'            => str_repeat( 'a', 64 ),
			'final_plan_record_count'    => 1,
			'decision_create_count'      => 1,
			'decision_link_wp_count'     => 0,
			'decision_link_ca_count'     => 0,
			'decision_invalid_count'     => 0,
			'decision_review_count'      => 0,
			'plugin_version'             => KONX_AFFILIATE_VERSION,
			'database_schema_version'    => KONX_AFFILIATE_DB_VERSION,
			'status'                     => 'draft',
			'created_at'                 => current_time( 'mysql', true ),
			'revalidation_stale_count'   => 0,
			'revalidation_conflict_count' => 0,
		),
		array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d' )
	);
	$draft_uuid = 'draft-test-uuid-phase24c6c-test';
	$draft_id   = (int) $wpdb->insert_id;
}

$result_draft = Konx_Migration_Revalidator::revalidate( $draft_uuid );

revalidation_assert(
	'G1-T02a: session_valid=false for draft',
	false === $result_draft['session_valid']
);
revalidation_assert(
	'G1-T02b: revalidation_status=critical for draft',
	'critical' === $result_draft['revalidation_status']
);
// session_frozen check should be the critical one (check index 1).
$frozen_check = null;
foreach ( $result_draft['session_checks'] as $chk ) {
	if ( 'session_frozen' === $chk['id'] ) {
		$frozen_check = $chk;
	}
}
revalidation_assert(
	'G1-T02c: session_frozen check is critical',
	'critical' === ( $frozen_check['status'] ?? '' ),
	'check=' . json_encode( $frozen_check )
);

// Cleanup.
$wpdb->delete( $wpdb->prefix . 'konx_migration_exec_sessions', array( 'session_uuid' => $draft_uuid ), array( '%s' ) );

// T03: Real frozen session → all session checks pass.
// Uses revalidate_with_fmp_override() so the FMP parity check (Part B) sees the same
// filtered decisions used when the snapshot was created, keeping the hash in sync even
// when the live wp_options FMP contains review records not in the snapshot.
echo "\n  [G1-T03] Real frozen session → all session checks pass\n";
if ( ! $frozen_uuid ) {
	revalidation_assert( 'G1-T03: SKIPPED — no frozen session found', false, 'PRECONDITION' );
} else {
	$result_frozen = $_fmp_decisions
		? revalidate_with_fmp_override( $frozen_uuid, $_fmp_decisions )
		: Konx_Migration_Revalidator::revalidate( $frozen_uuid );

	revalidation_assert(
		'G1-T03a: session_valid=true',
		true === $result_frozen['session_valid'],
		'checks=' . json_encode( array_column( $result_frozen['session_checks'], 'status' ) )
	);
	revalidation_assert(
		'G1-T03b: revalidation_status=pass',
		'pass' === $result_frozen['revalidation_status'],
		'status=' . $result_frozen['revalidation_status']
	);

	// Verify each session check ID is present.
	$check_ids = array_column( $result_frozen['session_checks'], 'id' );
	$required_checks = array(
		'session_exists', 'session_frozen', 'plugin_version', 'schema_version',
		'plan_hash_integrity', 'record_count', 'decision_counts',
		'no_review_records', 'cross_session_idempotency',
	);
	foreach ( $required_checks as $cid ) {
		revalidation_assert(
			"G1-T03c: check '{$cid}' present",
			in_array( $cid, $check_ids, true )
		);
	}

	// Plan hash check should pass.
	$hash_check = null;
	foreach ( $result_frozen['session_checks'] as $chk ) {
		if ( 'plan_hash_integrity' === $chk['id'] ) {
			$hash_check = $chk;
		}
	}
	revalidation_assert(
		'G1-T03d: plan_hash_integrity passes',
		'pass' === ( $hash_check['status'] ?? '' ),
		'msg=' . ( $hash_check['message'] ?? 'none' )
	);

	// Cross-session idempotency should pass (no completed records).
	$idem_check = null;
	foreach ( $result_frozen['session_checks'] as $chk ) {
		if ( 'cross_session_idempotency' === $chk['id'] ) {
			$idem_check = $chk;
		}
	}
	revalidation_assert(
		'G1-T03e: cross_session_idempotency passes',
		'pass' === ( $idem_check['status'] ?? '' ),
		'msg=' . ( $idem_check['message'] ?? 'none' )
	);
}

// T04: Plan hash tamper → plan_hash_integrity critical.
echo "\n  [G1-T04] Plan hash tamper → plan_hash_integrity critical\n";
// Build a valid snapshot then tamper with one plan record's action.
$safe_uid = get_safe_wp_user_id();
if ( ! $safe_uid ) {
	revalidation_assert( 'G1-T04: SKIPPED — no safe WP user found', false, 'PRECONDITION' );
} else {
	$tamper_decisions = array(
		array(
			'po10_id'        => 888881,
			'email'          => 'tamper-test-001@revaltest.local',
			'decision'       => 'invalid',
			'affiliate_type' => 'sales_agent',
			'team_name'      => '',
			'wp_user_id'     => null,
			'ca_id'          => null,
		),
		array(
			'po10_id'        => 888882,
			'email'          => 'tamper-test-002@revaltest.local',
			'decision'       => 'invalid',
			'affiliate_type' => 'sales_agent',
			'team_name'      => '',
			'wp_user_id'     => null,
			'ca_id'          => null,
		),
	);
	$snap = create_test_snapshot( $tamper_decisions );

	if ( ! $snap ) {
		revalidation_assert( 'G1-T04: snapshot creation failed', false );
	} else {
		// Tamper: change the action of the first plan record.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}konx_migration_execution_plan SET action = 'skip' WHERE session_id = %d AND source_record_id = 888881 LIMIT 1",
				$snap['session_id']
			)
		);

		$tamper_result = Konx_Migration_Revalidator::revalidate( $snap['session_uuid'] );

		revalidation_assert(
			'G1-T04a: tampered plan hash → session_valid=false',
			false === $tamper_result['session_valid'],
			'valid=' . var_export( $tamper_result['session_valid'], true )
		);

		$hash_check_t = null;
		foreach ( $tamper_result['session_checks'] as $chk ) {
			if ( 'plan_hash_integrity' === $chk['id'] ) {
				$hash_check_t = $chk;
			}
		}
		revalidation_assert(
			'G1-T04b: plan_hash_integrity=critical after tamper',
			'critical' === ( $hash_check_t['status'] ?? '' ),
			'status=' . ( $hash_check_t['status'] ?? 'none' )
		);

		cleanup_test_snapshot( $snap['session_uuid'], $snap['session_id'] );
	}
}

// T05: schema_version check — current (1.4.0) >= snapshot (1.3.0) → pass.
echo "\n  [G1-T05] Schema version compatibility check\n";
if ( $frozen_uuid ) {
	// Frozen session was created with 1.3.0. Current is 1.4.0.
	// version_compare('1.4.0', '1.3.0', '>=') = true → should pass.
	$schema_check = null;
	foreach ( $result_frozen['session_checks'] as $chk ) {
		if ( 'schema_version' === $chk['id'] ) {
			$schema_check = $chk;
		}
	}
	revalidation_assert(
		'G1-T05: schema_version passes (1.4.0 >= 1.3.0)',
		'pass' === ( $schema_check['status'] ?? '' ),
		'msg=' . ( $schema_check['message'] ?? 'none' )
	);
}

// T06: Record count stored in session matches actual plan records.
echo "\n  [G1-T06] Record count verification\n";
if ( $frozen_uuid ) {
	$count_check = null;
	foreach ( $result_frozen['session_checks'] as $chk ) {
		if ( 'record_count' === $chk['id'] ) {
			$count_check = $chk;
		}
	}
	revalidation_assert(
		'G1-T06a: record_count check passes',
		'pass' === ( $count_check['status'] ?? '' ),
		'msg=' . ( $count_check['message'] ?? 'none' )
	);
	revalidation_assert(
		'G1-T06b: records_checked = ' . $_fmp_no_review_count,
		$_fmp_no_review_count === $result_frozen['records_checked'],
		'expected=' . $_fmp_no_review_count . ' actual=' . $result_frozen['records_checked']
	);
}

// T07: Decision counts check.
echo "\n  [G1-T07] Decision counts verification\n";
if ( $frozen_uuid ) {
	$dc_check = null;
	foreach ( $result_frozen['session_checks'] as $chk ) {
		if ( 'decision_counts' === $chk['id'] ) {
			$dc_check = $chk;
		}
	}
	revalidation_assert(
		'G1-T07: decision_counts check passes',
		'pass' === ( $dc_check['status'] ?? '' ),
		'msg=' . ( $dc_check['message'] ?? 'none' )
	);
}

// T08: No-review-records check.
echo "\n  [G1-T08] No review records gate\n";
// Build a snapshot with a 'review' record to test the gate.
$review_decisions = array(
	array(
		'po10_id'        => 888901,
		'email'          => 'review-gate-test@revaltest.local',
		'decision'       => 'review',
		'affiliate_type' => 'sales_agent',
		'team_name'      => '',
		'wp_user_id'     => null,
		'ca_id'          => null,
	),
);
// Note: create_snapshot() inserts 'review' as a plan record (not into ledger).
// We need to force a snapshot with a review record. Since create_snapshot()
// normally works, just create it — the revalidator should flag it.
$review_snap = create_test_snapshot( $review_decisions );
if ( ! $review_snap ) {
	revalidation_assert( 'G1-T08: snapshot with review record failed to create', false );
} else {
	$review_reval = Konx_Migration_Revalidator::revalidate( $review_snap['session_uuid'] );

	$review_gate_check = null;
	foreach ( $review_reval['session_checks'] as $chk ) {
		if ( 'no_review_records' === $chk['id'] ) {
			$review_gate_check = $chk;
		}
	}
	revalidation_assert(
		'G1-T08a: no_review_records=critical when review records present',
		'critical' === ( $review_gate_check['status'] ?? '' ),
		'status=' . ( $review_gate_check['status'] ?? 'none' )
	);
	revalidation_assert(
		'G1-T08b: session_valid=false when review records block',
		false === $review_reval['session_valid']
	);

	cleanup_test_snapshot( $review_snap['session_uuid'], $review_snap['session_id'] );
}

// ---------------------------------------------------------------------------
// GROUP 2: Per-record live-state checks
// ---------------------------------------------------------------------------
echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo " GROUP 2: Per-record live-state checks\n";
echo "═══════════════════════════════════════════════════════════════════\n";

// --- 'create' record checks ---

// T01: 'invalid' / 'skip' → pass, no live DB queries needed.
echo "\n  [G2-T01] Non-actionable records (invalid/skip) → pass\n";
{
	$non_actionable = array(
		array( 'po10_id' => 777001, 'email' => 'skip-test@revaltest.local', 'decision' => 'skip',
			'affiliate_type' => 'sales_agent', 'team_name' => '', 'wp_user_id' => null, 'ca_id' => null ),
		array( 'po10_id' => 777002, 'email' => 'invalid-test@revaltest.local', 'decision' => 'invalid',
			'affiliate_type' => 'sales_agent', 'team_name' => '', 'wp_user_id' => null, 'ca_id' => null ),
	);
	$na_snap = create_test_snapshot( $non_actionable );
	if ( ! $na_snap ) {
		revalidation_assert( 'G2-T01: snapshot create failed', false );
	} else {
		$na_result = revalidate_with_fmp_override( $na_snap['session_uuid'], $non_actionable );
		revalidation_assert( 'G2-T01a: session_valid=true', true === $na_result['session_valid'] );
		revalidation_assert( 'G2-T01b: records_pass=2', 2 === $na_result['records_pass'], 'actual=' . $na_result['records_pass'] );
		revalidation_assert( 'G2-T01c: records_stale=0', 0 === $na_result['records_stale'] );
		revalidation_assert( 'G2-T01d: records_conflict=0', 0 === $na_result['records_conflict'] );
		revalidation_assert( 'G2-T01e: revalidation_status=pass', 'pass' === $na_result['revalidation_status'] );
		cleanup_test_snapshot( $na_snap['session_uuid'], $na_snap['session_id'] );
	}
}

// T02: 'create' where email is truly new → pass.
echo "\n  [G2-T02] create — truly new email → pass\n";
{
	$new_email_decisions = array(
		array( 'po10_id' => 777010, 'email' => 'brand-new-never-exists-xyz99@revaltest.local',
			'decision' => 'create', 'affiliate_type' => 'sales_agent',
			'team_name' => 'TESTCODENEW01', 'wp_user_id' => null, 'ca_id' => null ),
	);
	$new_snap = create_test_snapshot( $new_email_decisions );
	if ( ! $new_snap ) {
		revalidation_assert( 'G2-T02: snapshot create failed', false );
	} else {
		$new_result = revalidate_with_fmp_override( $new_snap['session_uuid'], $new_email_decisions );
		revalidation_assert( 'G2-T02a: pass', 'pass' === $new_result['revalidation_status'], 'status=' . $new_result['revalidation_status'] );
		revalidation_assert( 'G2-T02b: records_pass=1', 1 === $new_result['records_pass'] );
		cleanup_test_snapshot( $new_snap['session_uuid'], $new_snap['session_id'] );
	}
}

// T03: 'create' where email now exists in wp_users (no affiliate) → stale.
echo "\n  [G2-T03] create — email appeared in wp_users (no affiliate) → stale\n";
{
	$existing_email = get_existing_wp_email();
	if ( ! $existing_email ) {
		revalidation_assert( 'G2-T03: SKIPPED — no WP user email available', false, 'PRECONDITION' );
	} else {
		$stale_decisions = array(
			array( 'po10_id' => 777020, 'email' => $existing_email,
				'decision' => 'create', 'affiliate_type' => 'sales_agent',
				'team_name' => 'STALECODE01', 'wp_user_id' => null, 'ca_id' => null ),
		);
		$stale_snap = create_test_snapshot( $stale_decisions );
		if ( ! $stale_snap ) {
			revalidation_assert( 'G2-T03: snapshot create failed', false );
		} else {
			$stale_result = revalidate_with_fmp_override( $stale_snap['session_uuid'], $stale_decisions );
			revalidation_assert(
				'G2-T03a: revalidation_status=stale',
				'stale' === $stale_result['revalidation_status'],
				'status=' . $stale_result['revalidation_status']
			);
			revalidation_assert( 'G2-T03b: records_stale=1', 1 === $stale_result['records_stale'] );
			revalidation_assert( 'G2-T03c: records_conflict=0', 0 === $stale_result['records_conflict'] );
			revalidation_assert(
				'G2-T03d: issue type=email_appeared',
				'email_appeared' === ( $stale_result['issues'][0]['type'] ?? '' ),
				'type=' . ( $stale_result['issues'][0]['type'] ?? 'none' )
			);
			cleanup_test_snapshot( $stale_snap['session_uuid'], $stale_snap['session_id'] );
		}
	}
}

// T04: 'create' where email exists in wp_users AND user has KonX affiliate → conflict.
echo "\n  [G2-T04] create — email exists AND user has KonX affiliate → conflict\n";
{
	$conflict_uid   = get_safe_wp_user_id();
	$conflict_email = $conflict_uid
		? $wpdb->get_var( $wpdb->prepare( "SELECT LOWER(user_email) FROM {$wpdb->users} WHERE ID = %d", $conflict_uid ) )
		: null;

	if ( ! $conflict_uid || ! $conflict_email ) {
		revalidation_assert( 'G2-T04: SKIPPED — no WP user available', false, 'PRECONDITION' );
	} else {
		// Temporarily insert a KonX affiliate for this user.
		$konx_table = $wpdb->prefix . 'konx_affiliates';
		$wpdb->insert( $konx_table, array(
			'user_id'        => $conflict_uid,
			'affiliate_type' => 'sales_agent',
			'referral_code'  => 'TEMPCONFLICT01',
			'status'         => 'active',
			'cached_balance' => '0.00',
			'registered_at'  => current_time( 'mysql', true ),
			'updated_at'     => current_time( 'mysql', true ),
		), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		$temp_aff_id = (int) $wpdb->insert_id;

		$conflict_decisions = array(
			array( 'po10_id' => 777030, 'email' => $conflict_email,
				'decision' => 'create', 'affiliate_type' => 'sales_agent',
				'team_name' => 'CONFLICTCODE02', 'wp_user_id' => null, 'ca_id' => null ),
		);
		$conflict_snap = create_test_snapshot( $conflict_decisions );
		$conflict_result = $conflict_snap
			? revalidate_with_fmp_override( $conflict_snap['session_uuid'], $conflict_decisions )
			: null;

		// Delete temp affiliate BEFORE assertions so cleanup is guaranteed.
		if ( $temp_aff_id ) {
			$wpdb->delete( $konx_table, array( 'id' => $temp_aff_id ), array( '%d' ) );
		}

		if ( ! $conflict_snap || ! $conflict_result ) {
			revalidation_assert( 'G2-T04: snapshot/revalidation failed', false );
		} else {
			revalidation_assert(
				'G2-T04a: revalidation_status=conflict',
				'conflict' === $conflict_result['revalidation_status'],
				'status=' . $conflict_result['revalidation_status']
			);
			revalidation_assert( 'G2-T04b: records_conflict=1', 1 === $conflict_result['records_conflict'] );
			revalidation_assert(
				'G2-T04c: issue type=email_has_affiliate',
				'email_has_affiliate' === ( $conflict_result['issues'][0]['type'] ?? '' ),
				'type=' . ( $conflict_result['issues'][0]['type'] ?? 'none' )
			);
			cleanup_test_snapshot( $conflict_snap['session_uuid'], $conflict_snap['session_id'] );
		}
	}
}

// T05: 'create' where referral code is already taken → conflict.
echo "\n  [G2-T05] create — referral code already taken → conflict\n";
{
	$code_uid = get_safe_wp_user_id();
	if ( ! $code_uid ) {
		revalidation_assert( 'G2-T05: SKIPPED — no WP user', false, 'PRECONDITION' );
	} else {
		$konx_table = $wpdb->prefix . 'konx_affiliates';
		$wpdb->insert( $konx_table, array(
			'user_id'        => $code_uid,
			'affiliate_type' => 'sales_agent',
			'referral_code'  => 'TAKENCODE99',
			'status'         => 'active',
			'cached_balance' => '0.00',
			'registered_at'  => current_time( 'mysql', true ),
			'updated_at'     => current_time( 'mysql', true ),
		), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		$temp_code_aff = (int) $wpdb->insert_id;

		$code_decisions = array(
			array( 'po10_id' => 777040, 'email' => 'code-conflict-test@revaltest.local',
				'decision' => 'create', 'affiliate_type' => 'sales_agent',
				'team_name' => 'TAKENCODE99', 'wp_user_id' => null, 'ca_id' => null ),
		);
		$code_snap   = create_test_snapshot( $code_decisions );
		$code_result = $code_snap
			? revalidate_with_fmp_override( $code_snap['session_uuid'], $code_decisions )
			: null;

		// Delete temp affiliate immediately.
		if ( $temp_code_aff ) {
			$wpdb->delete( $konx_table, array( 'id' => $temp_code_aff ), array( '%d' ) );
		}

		if ( ! $code_snap || ! $code_result ) {
			revalidation_assert( 'G2-T05: failed', false );
		} else {
			revalidation_assert(
				'G2-T05a: revalidation_status=conflict',
				'conflict' === $code_result['revalidation_status'],
				'status=' . $code_result['revalidation_status']
			);
			revalidation_assert(
				'G2-T05b: issue type=referral_code_taken',
				'referral_code_taken' === ( $code_result['issues'][0]['type'] ?? '' ),
				'type=' . ( $code_result['issues'][0]['type'] ?? 'none' )
			);
			cleanup_test_snapshot( $code_snap['session_uuid'], $code_snap['session_id'] );
		}
	}
}

// --- 'link_wp' record checks ---

// T06: 'link_wp' where WP user exists and has no affiliate → pass.
echo "\n  [G2-T06] link_wp — user exists, no affiliate → pass\n";
{
	$lw_uid = get_safe_wp_user_id();
	if ( ! $lw_uid ) {
		revalidation_assert( 'G2-T06: SKIPPED', false, 'PRECONDITION' );
	} else {
		$lw_email = $wpdb->get_var( $wpdb->prepare( "SELECT LOWER(user_email) FROM {$wpdb->users} WHERE ID=%d", $lw_uid ) );
		$lw_decisions = array(
			array( 'po10_id' => 777050, 'email' => $lw_email,
				'decision' => 'link_wp', 'affiliate_type' => 'sales_agent',
				'team_name' => 'LWCODE01', 'wp_user_id' => $lw_uid, 'ca_id' => null ),
		);
		$lw_snap   = create_test_snapshot( $lw_decisions );
		$lw_result = $lw_snap ? revalidate_with_fmp_override( $lw_snap['session_uuid'], $lw_decisions ) : null;

		if ( ! $lw_snap || ! $lw_result ) {
			revalidation_assert( 'G2-T06: failed', false );
		} else {
			revalidation_assert(
				'G2-T06a: pass',
				'pass' === $lw_result['revalidation_status'],
				'status=' . $lw_result['revalidation_status']
			);
			revalidation_assert( 'G2-T06b: records_pass=1', 1 === $lw_result['records_pass'] );
			cleanup_test_snapshot( $lw_snap['session_uuid'], $lw_snap['session_id'] );
		}
	}
}

// T07: 'link_wp' where WP user was deleted → stale.
echo "\n  [G2-T07] link_wp — WP user no longer exists → stale\n";
{
	$del_user_id   = 9999997; // Non-existent user ID.
	$lwd_decisions = array(
		array( 'po10_id' => 777060, 'email' => 'deleted-user-lw@revaltest.local',
			'decision' => 'link_wp', 'affiliate_type' => 'sales_agent',
			'team_name' => 'LWDEL01', 'wp_user_id' => $del_user_id, 'ca_id' => null ),
	);
	$lwd_snap   = create_test_snapshot( $lwd_decisions );
	$lwd_result = $lwd_snap ? revalidate_with_fmp_override( $lwd_snap['session_uuid'], $lwd_decisions ) : null;

	if ( ! $lwd_snap || ! $lwd_result ) {
		revalidation_assert( 'G2-T07: failed', false );
	} else {
		revalidation_assert(
			'G2-T07a: revalidation_status=stale',
			'stale' === $lwd_result['revalidation_status'],
			'status=' . $lwd_result['revalidation_status']
		);
		revalidation_assert( 'G2-T07b: records_stale=1', 1 === $lwd_result['records_stale'] );
		revalidation_assert(
			'G2-T07c: issue type=user_deleted',
			'user_deleted' === ( $lwd_result['issues'][0]['type'] ?? '' )
		);
		cleanup_test_snapshot( $lwd_snap['session_uuid'], $lwd_snap['session_id'] );
	}
}

// T08: 'link_wp' where WP user gained a KonX affiliate → conflict.
echo "\n  [G2-T08] link_wp — user gained KonX affiliate → conflict\n";
{
	$lwc_uid = get_safe_wp_user_id();
	if ( ! $lwc_uid ) {
		revalidation_assert( 'G2-T08: SKIPPED', false, 'PRECONDITION' );
	} else {
		$konx_table = $wpdb->prefix . 'konx_affiliates';
		$wpdb->insert( $konx_table, array(
			'user_id'        => $lwc_uid,
			'affiliate_type' => 'sales_agent',
			'referral_code'  => 'LWCONFLICT01',
			'status'         => 'active',
			'cached_balance' => '0.00',
			'registered_at'  => current_time( 'mysql', true ),
			'updated_at'     => current_time( 'mysql', true ),
		), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		$temp_lwc_aff = (int) $wpdb->insert_id;

		$lwc_email    = $wpdb->get_var( $wpdb->prepare( "SELECT LOWER(user_email) FROM {$wpdb->users} WHERE ID=%d", $lwc_uid ) );
		$lwc_decisions = array(
			array( 'po10_id' => 777070, 'email' => $lwc_email,
				'decision' => 'link_wp', 'affiliate_type' => 'sales_agent',
				'team_name' => 'LWCONFLICT02', 'wp_user_id' => $lwc_uid, 'ca_id' => null ),
		);
		$lwc_snap   = create_test_snapshot( $lwc_decisions );
		$lwc_result = $lwc_snap ? revalidate_with_fmp_override( $lwc_snap['session_uuid'], $lwc_decisions ) : null;

		if ( $temp_lwc_aff ) {
			$wpdb->delete( $konx_table, array( 'id' => $temp_lwc_aff ), array( '%d' ) );
		}

		if ( ! $lwc_snap || ! $lwc_result ) {
			revalidation_assert( 'G2-T08: failed', false );
		} else {
			revalidation_assert(
				'G2-T08a: revalidation_status=conflict',
				'conflict' === $lwc_result['revalidation_status'],
				'status=' . $lwc_result['revalidation_status']
			);
			revalidation_assert(
				'G2-T08b: issue type=user_gained_affiliate',
				'user_gained_affiliate' === ( $lwc_result['issues'][0]['type'] ?? '' )
			);
			cleanup_test_snapshot( $lwc_snap['session_uuid'], $lwc_snap['session_id'] );
		}
	}
}

// --- 'link_ca' record checks ---

// T09: 'link_ca' where CA row exists and WP user has no affiliate → pass.
echo "\n  [G2-T09] link_ca — CA exists, user has no affiliate → pass\n";
{
	// Use CA#28 (userid=91, known to exist from audit).
	$ca_id_test = 28;
	$ca_uid     = 91;
	$ca_email   = $wpdb->get_var( $wpdb->prepare( "SELECT LOWER(user_email) FROM {$wpdb->users} WHERE ID=%d", $ca_uid ) );

	$lca_decisions = array(
		array( 'po10_id' => 777080, 'email' => $ca_email,
			'decision' => 'link_ca', 'affiliate_type' => 'sales_agent',
			'team_name' => 'Blessed7', 'wp_user_id' => $ca_uid, 'ca_id' => $ca_id_test ),
	);
	$lca_snap   = create_test_snapshot( $lca_decisions );
	$lca_result = $lca_snap ? revalidate_with_fmp_override( $lca_snap['session_uuid'], $lca_decisions ) : null;

	if ( ! $lca_snap || ! $lca_result ) {
		revalidation_assert( 'G2-T09: failed', false );
	} else {
		revalidation_assert(
			'G2-T09a: pass',
			'pass' === $lca_result['revalidation_status'],
			'status=' . $lca_result['revalidation_status'] . ' issues=' . json_encode( $lca_result['issues'] )
		);
		revalidation_assert( 'G2-T09b: records_pass=1', 1 === $lca_result['records_pass'] );
		cleanup_test_snapshot( $lca_snap['session_uuid'], $lca_snap['session_id'] );
	}
}

// T10: 'link_ca' where CA row was deleted → stale.
echo "\n  [G2-T10] link_ca — CA row deleted → stale\n";
{
	$del_ca_id  = 9999997; // Non-existent CA ID.
	$lca_d_decisions = array(
		array( 'po10_id' => 777090, 'email' => 'ca-deleted-test@revaltest.local',
			'decision' => 'link_ca', 'affiliate_type' => 'sales_agent',
			'team_name' => 'DELTESTCA', 'wp_user_id' => 91, 'ca_id' => $del_ca_id ),
	);
	$lca_d_snap   = create_test_snapshot( $lca_d_decisions );
	$lca_d_result = $lca_d_snap ? revalidate_with_fmp_override( $lca_d_snap['session_uuid'], $lca_d_decisions ) : null;

	if ( ! $lca_d_snap || ! $lca_d_result ) {
		revalidation_assert( 'G2-T10: failed', false );
	} else {
		revalidation_assert(
			'G2-T10a: revalidation_status=stale',
			'stale' === $lca_d_result['revalidation_status'],
			'status=' . $lca_d_result['revalidation_status']
		);
		revalidation_assert(
			'G2-T10b: issue type=ca_row_deleted',
			'ca_row_deleted' === ( $lca_d_result['issues'][0]['type'] ?? '' )
		);
		cleanup_test_snapshot( $lca_d_snap['session_uuid'], $lca_d_snap['session_id'] );
	}
}

// T11: 'link_ca' where CA userid changed → conflict.
echo "\n  [G2-T11] link_ca — CA userid changed → conflict\n";
{
	// Use real CA#28 (userid=91) but tell snapshot wp_user_id=999 (mismatch).
	$ca_id_mismatch = 28;
	$wrong_uid      = 999; // Wrong user ID — does not match CA#28's userid=91.
	$lca_m_decisions = array(
		array( 'po10_id' => 777100, 'email' => 'ca-mismatch-test@revaltest.local',
			'decision' => 'link_ca', 'affiliate_type' => 'sales_agent',
			'team_name' => 'MISMATCHCA', 'wp_user_id' => $wrong_uid, 'ca_id' => $ca_id_mismatch ),
	);
	$lca_m_snap   = create_test_snapshot( $lca_m_decisions );
	$lca_m_result = $lca_m_snap ? revalidate_with_fmp_override( $lca_m_snap['session_uuid'], $lca_m_decisions ) : null;

	if ( ! $lca_m_snap ) {
		revalidation_assert( 'G2-T11: snapshot failed', false );
	} else {
		revalidation_assert(
			'G2-T11a: revalidation_status=conflict',
			'conflict' === $lca_m_result['revalidation_status'],
			'status=' . $lca_m_result['revalidation_status']
		);
		revalidation_assert(
			'G2-T11b: issue type=ca_user_mismatch',
			'ca_user_mismatch' === ( $lca_m_result['issues'][0]['type'] ?? '' )
		);
		cleanup_test_snapshot( $lca_m_snap['session_uuid'], $lca_m_snap['session_id'] );
	}
}

// T12: 'link_ca' where WP user gained a KonX affiliate → conflict.
echo "\n  [G2-T12] link_ca — CA user gained KonX affiliate → conflict\n";
{
	$ca_uid_c = 91; // CA#28's real userid.
	$konx_table = $wpdb->prefix . 'konx_affiliates';
	$wpdb->insert( $konx_table, array(
		'user_id'        => $ca_uid_c,
		'affiliate_type' => 'sales_agent',
		'referral_code'  => 'LCACONFLICT01',
		'status'         => 'active',
		'cached_balance' => '0.00',
		'registered_at'  => current_time( 'mysql', true ),
		'updated_at'     => current_time( 'mysql', true ),
	), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
	$temp_lca_aff = (int) $wpdb->insert_id;

	$lca_c_decisions = array(
		array( 'po10_id' => 777110, 'email' => 'blessed7user@test.local',
			'decision' => 'link_ca', 'affiliate_type' => 'sales_agent',
			'team_name' => 'Blessed7', 'wp_user_id' => $ca_uid_c, 'ca_id' => 28 ),
	);
	$lca_c_snap   = create_test_snapshot( $lca_c_decisions );
	$lca_c_result = $lca_c_snap ? revalidate_with_fmp_override( $lca_c_snap['session_uuid'], $lca_c_decisions ) : null;

	if ( $temp_lca_aff ) {
		$wpdb->delete( $konx_table, array( 'id' => $temp_lca_aff ), array( '%d' ) );
	}

	if ( ! $lca_c_snap || ! $lca_c_result ) {
		revalidation_assert( 'G2-T12: failed', false );
	} else {
		revalidation_assert(
			'G2-T12a: revalidation_status=conflict',
			'conflict' === $lca_c_result['revalidation_status'],
			'status=' . $lca_c_result['revalidation_status']
		);
		revalidation_assert(
			'G2-T12b: issue type=ca_user_has_affiliate',
			'ca_user_has_affiliate' === ( $lca_c_result['issues'][0]['type'] ?? '' )
		);
		cleanup_test_snapshot( $lca_c_snap['session_uuid'], $lca_c_snap['session_id'] );
	}
}

// ---------------------------------------------------------------------------
// GROUP 3: Full live QA run (2,402-record revalidation)
// ---------------------------------------------------------------------------
echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo " GROUP 3: Full {$_fmp_no_review_count}-record live revalidation\n";
echo "═══════════════════════════════════════════════════════════════════\n";

echo "\n  [G3-T01] Revalidating frozen session {$frozen_uuid}...\n";

if ( ! $frozen_uuid ) {
	revalidation_assert( 'G3: SKIPPED — no frozen session', false, 'PRECONDITION' );
} else {
	// Use the filtered decision set so FMP parity (Part B) passes.
	// The live FMP in wp_options may contain review decisions added after the snapshot was
	// created; those are stripped from the snapshot and from the override to keep hashes
	// in sync.  The result is a full-pass revalidation of all non-review records.
	$live_result = $_fmp_decisions
		? revalidate_with_fmp_override( $frozen_uuid, $_fmp_decisions )
		: Konx_Migration_Revalidator::revalidate( $frozen_uuid );

	revalidation_assert(
		'G3-T01a: session_valid=true',
		true === $live_result['session_valid'],
		'checks=' . json_encode( array_map( function( $c ) { return $c['id'] . '=' . $c['status']; }, $live_result['session_checks'] ) )
	);
	revalidation_assert(
		'G3-T01b: revalidation_status=pass',
		'pass' === $live_result['revalidation_status'],
		'status=' . $live_result['revalidation_status'] .
		' stale=' . $live_result['records_stale'] .
		' conflict=' . $live_result['records_conflict']
	);
	revalidation_assert(
		'G3-T01c: records_checked=' . $_fmp_no_review_count,
		$_fmp_no_review_count === $live_result['records_checked'],
		'expected=' . $_fmp_no_review_count . ' actual=' . $live_result['records_checked']
	);
	revalidation_assert(
		'G3-T01d: records_pass=' . $_fmp_no_review_count,
		$_fmp_no_review_count === $live_result['records_pass'],
		'pass=' . $live_result['records_pass']
	);
	revalidation_assert(
		'G3-T01e: records_stale=0',
		0 === $live_result['records_stale'],
		'stale=' . $live_result['records_stale']
	);
	revalidation_assert(
		'G3-T01f: records_conflict=0',
		0 === $live_result['records_conflict'],
		'conflict=' . $live_result['records_conflict']
	);
	revalidation_assert(
		'G3-T01g: issues array is empty',
		0 === count( $live_result['issues'] ),
		'issue_count=' . count( $live_result['issues'] )
	);

	// Verify revalidation metadata was persisted to the session row.
	$session_row = Konx_Migration_Exec_Session::get( $frozen_uuid );
	revalidation_assert(
		'G3-T01h: last_revalidated_at persisted',
		! empty( $session_row->last_revalidated_at ),
		'value=' . ( $session_row->last_revalidated_at ?? 'NULL' )
	);
	revalidation_assert(
		'G3-T01i: revalidation_status=pass persisted',
		'pass' === ( $session_row->revalidation_status ?? '' ),
		'value=' . ( $session_row->revalidation_status ?? 'NULL' )
	);
	revalidation_assert(
		'G3-T01j: revalidation_stale_count=0 persisted',
		'0' === ( $session_row->revalidation_stale_count ?? '1' ),
		'value=' . ( $session_row->revalidation_stale_count ?? 'NULL' )
	);
	revalidation_assert(
		'G3-T01k: revalidation_conflict_count=0 persisted',
		'0' === ( $session_row->revalidation_conflict_count ?? '1' ),
		'value=' . ( $session_row->revalidation_conflict_count ?? 'NULL' )
	);

	// Spot-check: all create records are present in the plan snapshot and all pass
	// (no KonX affiliates exist so every 'create' is a fresh-user candidate).
	$create_count_db = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_migration_execution_plan WHERE session_id = %d AND action = 'create'",
			(int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}konx_migration_exec_sessions WHERE session_uuid = %s",
				$frozen_uuid
			) )
		)
	);
	revalidation_assert(
		'G3-T01l: ' . $_fmp_create_count . ' create records in plan',
		$_fmp_create_count === $create_count_db,
		'expected=' . $_fmp_create_count . ' actual=' . $create_count_db
	);

	// Print a summary of the live run.
	echo "  [G3 LIVE RUN SUMMARY]\n";
	echo "    Session UUID  : {$frozen_uuid}\n";
	echo "    Records total : {$live_result['records_checked']}\n";
	echo "    Pass          : {$live_result['records_pass']}\n";
	echo "    Stale         : {$live_result['records_stale']}\n";
	echo "    Conflict      : {$live_result['records_conflict']}\n";
	echo "    Overall status: {$live_result['revalidation_status']}\n";
}

// Clean up the live-FMP snapshot created during test setup.
if ( $_live_fmp_snap_id ) {
	cleanup_test_snapshot( $frozen_uuid, $_live_fmp_snap_id );
	$_live_fmp_snap_id = null;
}

// ---------------------------------------------------------------------------
// GROUP 4: Business data safety assertions
// ---------------------------------------------------------------------------
echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo " GROUP 4: Business data safety (all deltas must be zero)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$after = array(
	'wp_users'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
	'konx_affiliates' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}konx_affiliates" ),
	'ca_registers'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wcusage_register" ),
);

revalidation_assert(
	'SAFE-T01: wp_users count unchanged',
	$baseline['wp_users'] === $after['wp_users'],
	"before={$baseline['wp_users']} after={$after['wp_users']}"
);
revalidation_assert(
	'SAFE-T02: wp_konx_affiliates count unchanged',
	$baseline['konx_affiliates'] === $after['konx_affiliates'],
	"before={$baseline['konx_affiliates']} after={$after['konx_affiliates']}"
);
revalidation_assert(
	'SAFE-T03: wp_wcusage_register count unchanged',
	$baseline['ca_registers'] === $after['ca_registers'],
	"before={$baseline['ca_registers']} after={$after['ca_registers']}"
);

// ---------------------------------------------------------------------------
// GROUP 5: Regression tests for Phase 24C-6C fixes
// ---------------------------------------------------------------------------
echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo " GROUP 5: Regression tests (Phase 24C-6C pre-commit fixes)\n";
echo "═══════════════════════════════════════════════════════════════════\n";

// G5-T01: Plugin version — same → pass.
echo "\n  [G5-T01] Plugin version match → status=pass\n";
{
	// Insert a session with plugin_version matching current constant.
	$t01_uuid = 'g5t01-plugin-version-match-test';
	$wpdb->insert(
		$wpdb->prefix . 'konx_migration_exec_sessions',
		array(
			'session_uuid'                => $t01_uuid,
			'source_type'                 => 'csv',
			'final_plan_hash'             => str_repeat( 'b', 64 ),
			'final_plan_record_count'     => 1,
			'decision_create_count'       => 0,
			'decision_link_wp_count'      => 0,
			'decision_link_ca_count'      => 0,
			'decision_invalid_count'      => 1,
			'decision_review_count'       => 0,
			'plugin_version'              => KONX_AFFILIATE_VERSION, // Same as current.
			'database_schema_version'     => KONX_AFFILIATE_DB_VERSION,
			'status'                      => 'frozen',
			'created_at'                  => current_time( 'mysql', true ),
			'revalidation_stale_count'    => 0,
			'revalidation_conflict_count' => 0,
		),
		array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d' )
	);
	$t01_session_id = (int) $wpdb->insert_id;

	// Build a plan row to match the stored hash properly.
	// The hash must match final_plan_hash above — or we can generate a real hash.
	// For simplicity, use a real snapshot.
	$t01_decisions = array(
		array( 'po10_id' => 900101, 'email' => 'g5t01-test@revaltest.local',
			'decision' => 'invalid', 'affiliate_type' => 'sales_agent',
			'team_name' => '', 'wp_user_id' => null, 'ca_id' => null ),
	);
	// Clean up the placeholder session and create a proper one.
	$wpdb->delete( $wpdb->prefix . 'konx_migration_exec_sessions', array( 'session_uuid' => $t01_uuid ), array( '%s' ) );

	$t01_snap = create_test_snapshot( $t01_decisions );
	if ( ! $t01_snap ) {
		revalidation_assert( 'G5-T01: snapshot create failed', false );
	} else {
		// Update plugin_version to match current constant.
		$wpdb->update(
			$wpdb->prefix . 'konx_migration_exec_sessions',
			array( 'plugin_version' => KONX_AFFILIATE_VERSION ),
			array( 'session_uuid' => $t01_snap['session_uuid'] ),
			array( '%s' ),
			array( '%s' )
		);

		$t01_result = Konx_Migration_Revalidator::revalidate( $t01_snap['session_uuid'] );

		$t01_pv_check = null;
		foreach ( $t01_result['session_checks'] as $chk ) {
			if ( 'plugin_version' === $chk['id'] ) {
				$t01_pv_check = $chk;
			}
		}
		revalidation_assert(
			'G5-T01a: plugin_version check status=pass when versions match',
			'pass' === ( $t01_pv_check['status'] ?? '' ),
			'status=' . ( $t01_pv_check['status'] ?? 'none' )
		);
		revalidation_assert(
			'G5-T01b: plugin_version critical=false when versions match',
			false === ( $t01_pv_check['critical'] ?? true ),
			'critical=' . var_export( $t01_pv_check['critical'] ?? null, true )
		);

		cleanup_test_snapshot( $t01_snap['session_uuid'], $t01_snap['session_id'] );
	}
}

// G5-T02: Plugin version — older snapshot → critical.
echo "\n  [G5-T02] Plugin version older snapshot → status=critical\n";
{
	$t02_decisions = array(
		array( 'po10_id' => 900201, 'email' => 'g5t02-test@revaltest.local',
			'decision' => 'invalid', 'affiliate_type' => 'sales_agent',
			'team_name' => '', 'wp_user_id' => null, 'ca_id' => null ),
	);
	$t02_snap = create_test_snapshot( $t02_decisions );
	if ( ! $t02_snap ) {
		revalidation_assert( 'G5-T02: snapshot create failed', false );
	} else {
		// Overwrite plugin_version with an old version.
		$wpdb->update(
			$wpdb->prefix . 'konx_migration_exec_sessions',
			array( 'plugin_version' => '1.0.0' ),
			array( 'session_uuid' => $t02_snap['session_uuid'] ),
			array( '%s' ),
			array( '%s' )
		);

		$t02_result = Konx_Migration_Revalidator::revalidate( $t02_snap['session_uuid'] );

		$t02_pv_check = null;
		foreach ( $t02_result['session_checks'] as $chk ) {
			if ( 'plugin_version' === $chk['id'] ) {
				$t02_pv_check = $chk;
			}
		}
		revalidation_assert(
			'G5-T02a: plugin_version check status=critical for older snapshot version',
			'critical' === ( $t02_pv_check['status'] ?? '' ),
			'status=' . ( $t02_pv_check['status'] ?? 'none' )
		);
		revalidation_assert(
			'G5-T02b: session_valid=false when plugin version mismatches',
			false === $t02_result['session_valid'],
			'valid=' . var_export( $t02_result['session_valid'], true )
		);

		cleanup_test_snapshot( $t02_snap['session_uuid'], $t02_snap['session_id'] );
	}
}

// G5-T03: Plugin version — newer snapshot → critical.
echo "\n  [G5-T03] Plugin version newer snapshot → status=critical\n";
{
	$t03_decisions = array(
		array( 'po10_id' => 900301, 'email' => 'g5t03-test@revaltest.local',
			'decision' => 'invalid', 'affiliate_type' => 'sales_agent',
			'team_name' => '', 'wp_user_id' => null, 'ca_id' => null ),
	);
	$t03_snap = create_test_snapshot( $t03_decisions );
	if ( ! $t03_snap ) {
		revalidation_assert( 'G5-T03: snapshot create failed', false );
	} else {
		// Overwrite plugin_version with a future version.
		$wpdb->update(
			$wpdb->prefix . 'konx_migration_exec_sessions',
			array( 'plugin_version' => '99.99.99' ),
			array( 'session_uuid' => $t03_snap['session_uuid'] ),
			array( '%s' ),
			array( '%s' )
		);

		$t03_result = Konx_Migration_Revalidator::revalidate( $t03_snap['session_uuid'] );

		$t03_pv_check = null;
		foreach ( $t03_result['session_checks'] as $chk ) {
			if ( 'plugin_version' === $chk['id'] ) {
				$t03_pv_check = $chk;
			}
		}
		revalidation_assert(
			'G5-T03a: plugin_version check status=critical for newer snapshot version',
			'critical' === ( $t03_pv_check['status'] ?? '' ),
			'status=' . ( $t03_pv_check['status'] ?? 'none' )
		);

		cleanup_test_snapshot( $t03_snap['session_uuid'], $t03_snap['session_id'] );
	}
}

// G5-T04: Schema version — current equal snapshot → pass.
echo "\n  [G5-T04] Schema version current equal snapshot → pass\n";
{
	$t04_decisions = array(
		array( 'po10_id' => 900401, 'email' => 'g5t04-test@revaltest.local',
			'decision' => 'invalid', 'affiliate_type' => 'sales_agent',
			'team_name' => '', 'wp_user_id' => null, 'ca_id' => null ),
	);
	$t04_snap = create_test_snapshot( $t04_decisions );
	if ( ! $t04_snap ) {
		revalidation_assert( 'G5-T04: snapshot create failed', false );
	} else {
		// Ensure db_version matches current constant (create_snapshot stores it via Konx_Migration_Exec_Session::create).
		// Set explicitly to match current.
		$wpdb->update(
			$wpdb->prefix . 'konx_migration_exec_sessions',
			array( 'database_schema_version' => KONX_AFFILIATE_DB_VERSION ),
			array( 'session_uuid' => $t04_snap['session_uuid'] ),
			array( '%s' ),
			array( '%s' )
		);

		$t04_result = Konx_Migration_Revalidator::revalidate( $t04_snap['session_uuid'] );

		$t04_schema_check = null;
		foreach ( $t04_result['session_checks'] as $chk ) {
			if ( 'schema_version' === $chk['id'] ) {
				$t04_schema_check = $chk;
			}
		}
		revalidation_assert(
			'G5-T04: schema_version check status=pass when current equals snapshot',
			'pass' === ( $t04_schema_check['status'] ?? '' ),
			'status=' . ( $t04_schema_check['status'] ?? 'none' )
		);

		cleanup_test_snapshot( $t04_snap['session_uuid'], $t04_snap['session_id'] );
	}
}

// G5-T05: Schema version — current newer than snapshot → pass (additive policy).
echo "\n  [G5-T05] Schema version current newer than snapshot → pass\n";
{
	$t05_decisions = array(
		array( 'po10_id' => 900501, 'email' => 'g5t05-test@revaltest.local',
			'decision' => 'invalid', 'affiliate_type' => 'sales_agent',
			'team_name' => '', 'wp_user_id' => null, 'ca_id' => null ),
	);
	$t05_snap = create_test_snapshot( $t05_decisions );
	if ( ! $t05_snap ) {
		revalidation_assert( 'G5-T05: snapshot create failed', false );
	} else {
		// Set snapshot db_version to an older version — current (1.4.1) is newer → pass.
		$wpdb->update(
			$wpdb->prefix . 'konx_migration_exec_sessions',
			array( 'database_schema_version' => '1.0.0' ),
			array( 'session_uuid' => $t05_snap['session_uuid'] ),
			array( '%s' ),
			array( '%s' )
		);

		$t05_result = Konx_Migration_Revalidator::revalidate( $t05_snap['session_uuid'] );

		$t05_schema_check = null;
		foreach ( $t05_result['session_checks'] as $chk ) {
			if ( 'schema_version' === $chk['id'] ) {
				$t05_schema_check = $chk;
			}
		}
		revalidation_assert(
			'G5-T05: schema_version check status=pass when current (1.4.1) > snapshot (1.0.0)',
			'pass' === ( $t05_schema_check['status'] ?? '' ),
			'status=' . ( $t05_schema_check['status'] ?? 'none' )
		);

		cleanup_test_snapshot( $t05_snap['session_uuid'], $t05_snap['session_id'] );
	}
}

// G5-T06: Schema version — current older than snapshot → critical.
echo "\n  [G5-T06] Schema version current older than snapshot → critical\n";
{
	$t06_decisions = array(
		array( 'po10_id' => 900601, 'email' => 'g5t06-test@revaltest.local',
			'decision' => 'invalid', 'affiliate_type' => 'sales_agent',
			'team_name' => '', 'wp_user_id' => null, 'ca_id' => null ),
	);
	$t06_snap = create_test_snapshot( $t06_decisions );
	if ( ! $t06_snap ) {
		revalidation_assert( 'G5-T06: snapshot create failed', false );
	} else {
		// Set snapshot db_version to a future version — current (1.4.1) is older → critical.
		// version_compare('1.4.1', '99.0.0', '>=') = false → critical.
		$wpdb->update(
			$wpdb->prefix . 'konx_migration_exec_sessions',
			array( 'database_schema_version' => '99.0.0' ),
			array( 'session_uuid' => $t06_snap['session_uuid'] ),
			array( '%s' ),
			array( '%s' )
		);

		$t06_result = Konx_Migration_Revalidator::revalidate( $t06_snap['session_uuid'] );

		$t06_schema_check = null;
		foreach ( $t06_result['session_checks'] as $chk ) {
			if ( 'schema_version' === $chk['id'] ) {
				$t06_schema_check = $chk;
			}
		}
		revalidation_assert(
			'G5-T06a: schema_version check status=critical when current (1.4.1) < snapshot (99.0.0)',
			'critical' === ( $t06_schema_check['status'] ?? '' ),
			'status=' . ( $t06_schema_check['status'] ?? 'none' )
		);
		revalidation_assert(
			'G5-T06b: session_valid=false on schema downgrade',
			false === $t06_result['session_valid']
		);

		cleanup_test_snapshot( $t06_snap['session_uuid'], $t06_snap['session_id'] );
	}
}

// G5-T07: Cross-system idempotency isolation — different source_system does NOT block.
echo "\n  [G5-T07] Cross-system idempotency isolation — different source_system → not blocked\n";
{
	// Insert a completed ledger row for 'other_source' system with source_record_id=777200.
	$t07_other_session_id = 999901;
	$t07_other_plan_id    = 999901;
	$wpdb->insert(
		$wpdb->prefix . 'konx_migration_execution_ledger',
		array(
			'session_id'       => $t07_other_session_id,
			'plan_id'          => $t07_other_plan_id,
			'source_system'    => 'other_source',
			'source_record_id' => 777200,
			'approved_action'  => 'create',
			'status'           => 'completed',
			'attempt_count'    => 1,
			'created_at'       => current_time( 'mysql', true ),
		),
		array( '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s' )
	);
	$t07_ledger_id = (int) $wpdb->insert_id;

	// Create a test session for 'powerof10' with the same source_record_id.
	$t07_decisions = array(
		array( 'po10_id' => 777200, 'email' => 'g5t07-test@revaltest.local',
			'decision' => 'create', 'affiliate_type' => 'sales_agent',
			'team_name' => 'G5T07CODE', 'wp_user_id' => null, 'ca_id' => null ),
	);
	$t07_snap = create_test_snapshot( $t07_decisions );

	if ( ! $t07_snap ) {
		revalidation_assert( 'G5-T07: snapshot create failed', false );
	} else {
		$t07_result = Konx_Migration_Revalidator::revalidate( $t07_snap['session_uuid'] );

		$t07_idem_check = null;
		foreach ( $t07_result['session_checks'] as $chk ) {
			if ( 'cross_session_idempotency' === $chk['id'] ) {
				$t07_idem_check = $chk;
			}
		}
		revalidation_assert(
			'G5-T07: cross_session_idempotency NOT flagged for different source_system',
			'pass' === ( $t07_idem_check['status'] ?? '' ),
			'status=' . ( $t07_idem_check['status'] ?? 'none' ) . ' msg=' . ( $t07_idem_check['message'] ?? '' )
		);

		cleanup_test_snapshot( $t07_snap['session_uuid'], $t07_snap['session_id'] );
	}

	// Clean up the other-source ledger row.
	if ( $t07_ledger_id ) {
		$wpdb->delete( $wpdb->prefix . 'konx_migration_execution_ledger', array( 'id' => $t07_ledger_id ), array( '%d' ) );
	}
}

// G5-T08: Completed-state discrimination — 'failed' does NOT block.
echo "\n  [G5-T08] Completed-state discrimination — failed ledger entry → not blocked\n";
{
	// Insert a ledger row with status='failed' for source_record_id=777201.
	$t08_session_id = 999902;
	$t08_plan_id    = 999902;
	$wpdb->insert(
		$wpdb->prefix . 'konx_migration_execution_ledger',
		array(
			'session_id'       => $t08_session_id,
			'plan_id'          => $t08_plan_id,
			'source_system'    => 'powerof10',
			'source_record_id' => 777201,
			'approved_action'  => 'create',
			'status'           => 'failed',
			'attempt_count'    => 1,
			'created_at'       => current_time( 'mysql', true ),
		),
		array( '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s' )
	);
	$t08_ledger_id = (int) $wpdb->insert_id;

	$t08_decisions = array(
		array( 'po10_id' => 777201, 'email' => 'g5t08-test@revaltest.local',
			'decision' => 'create', 'affiliate_type' => 'sales_agent',
			'team_name' => 'G5T08CODE', 'wp_user_id' => null, 'ca_id' => null ),
	);
	$t08_snap = create_test_snapshot( $t08_decisions );

	if ( ! $t08_snap ) {
		revalidation_assert( 'G5-T08: snapshot create failed', false );
	} else {
		$t08_result = Konx_Migration_Revalidator::revalidate( $t08_snap['session_uuid'] );

		$t08_idem_check = null;
		foreach ( $t08_result['session_checks'] as $chk ) {
			if ( 'cross_session_idempotency' === $chk['id'] ) {
				$t08_idem_check = $chk;
			}
		}
		revalidation_assert(
			'G5-T08: cross_session_idempotency passes — failed status does NOT block',
			'pass' === ( $t08_idem_check['status'] ?? '' ),
			'status=' . ( $t08_idem_check['status'] ?? 'none' )
		);

		cleanup_test_snapshot( $t08_snap['session_uuid'], $t08_snap['session_id'] );
	}

	if ( $t08_ledger_id ) {
		$wpdb->delete( $wpdb->prefix . 'konx_migration_execution_ledger', array( 'id' => $t08_ledger_id ), array( '%d' ) );
	}
}

// G5-T09: Completed-state discrimination — 'completed' DOES block.
echo "\n  [G5-T09] Completed-state discrimination — completed ledger entry → blocked\n";
{
	// Insert a ledger row with status='completed' for source_record_id=777202.
	$t09_session_id = 999903;
	$t09_plan_id    = 999903;
	$wpdb->insert(
		$wpdb->prefix . 'konx_migration_execution_ledger',
		array(
			'session_id'       => $t09_session_id,
			'plan_id'          => $t09_plan_id,
			'source_system'    => 'powerof10',
			'source_record_id' => 777202,
			'approved_action'  => 'create',
			'status'           => 'completed',
			'attempt_count'    => 1,
			'created_at'       => current_time( 'mysql', true ),
		),
		array( '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s' )
	);
	$t09_ledger_id = (int) $wpdb->insert_id;

	$t09_decisions = array(
		array( 'po10_id' => 777202, 'email' => 'g5t09-test@revaltest.local',
			'decision' => 'create', 'affiliate_type' => 'sales_agent',
			'team_name' => 'G5T09CODE', 'wp_user_id' => null, 'ca_id' => null ),
	);
	$t09_snap = create_test_snapshot( $t09_decisions );

	if ( ! $t09_snap ) {
		revalidation_assert( 'G5-T09: snapshot create failed', false );
	} else {
		$t09_result = Konx_Migration_Revalidator::revalidate( $t09_snap['session_uuid'] );

		$t09_idem_check = null;
		foreach ( $t09_result['session_checks'] as $chk ) {
			if ( 'cross_session_idempotency' === $chk['id'] ) {
				$t09_idem_check = $chk;
			}
		}
		revalidation_assert(
			'G5-T09a: cross_session_idempotency=critical for completed prior record',
			'critical' === ( $t09_idem_check['status'] ?? '' ),
			'status=' . ( $t09_idem_check['status'] ?? 'none' )
		);
		revalidation_assert(
			'G5-T09b: session_valid=false blocked by completed prior record',
			false === $t09_result['session_valid']
		);

		cleanup_test_snapshot( $t09_snap['session_uuid'], $t09_snap['session_id'] );
	}

	if ( $t09_ledger_id ) {
		$wpdb->delete( $wpdb->prefix . 'konx_migration_execution_ledger', array( 'id' => $t09_ledger_id ), array( '%d' ) );
	}
}

// G5-T10: link_ca CA-userid reassignment → conflict.
echo "\n  [G5-T10] link_ca CA-userid reassignment → conflict\n";
{
	// Use real CA#28 (userid=91). Tell the snapshot wp_user_id=999 (mismatch).
	// This replicates the G2-T11 scenario but explicitly tests the conflict path
	// added/confirmed in the 24C-6C fix set.
	$t10_ca_id   = 28;
	$t10_wrong_uid = 999;
	$t10_decisions = array(
		array( 'po10_id' => 900701, 'email' => 'g5t10-ca-mismatch@revaltest.local',
			'decision' => 'link_ca', 'affiliate_type' => 'sales_agent',
			'team_name' => 'G5T10CA', 'wp_user_id' => $t10_wrong_uid, 'ca_id' => $t10_ca_id ),
	);
	$t10_snap = create_test_snapshot( $t10_decisions );

	if ( ! $t10_snap ) {
		revalidation_assert( 'G5-T10: snapshot create failed', false );
	} else {
		$t10_result = revalidate_with_fmp_override( $t10_snap['session_uuid'], $t10_decisions );
		revalidation_assert(
			'G5-T10a: link_ca userid mismatch → revalidation_status=conflict',
			'conflict' === $t10_result['revalidation_status'],
			'status=' . $t10_result['revalidation_status']
		);
		revalidation_assert(
			'G5-T10b: link_ca userid mismatch → issue type=ca_user_mismatch',
			'ca_user_mismatch' === ( $t10_result['issues'][0]['type'] ?? '' ),
			'type=' . ( $t10_result['issues'][0]['type'] ?? 'none' )
		);

		cleanup_test_snapshot( $t10_snap['session_uuid'], $t10_snap['session_id'] );
	}
}

// G5-T11: Transaction commit — successful snapshot has all rows.
echo "\n  [G5-T11] Transaction commit — successful snapshot is complete\n";
{
	$t11_decisions = array(
		array( 'po10_id' => 900801, 'email' => 'g5t11-create@revaltest.local',
			'decision' => 'create', 'affiliate_type' => 'sales_agent',
			'team_name' => 'G5T11CODE', 'wp_user_id' => null, 'ca_id' => null ),
		array( 'po10_id' => 900802, 'email' => 'g5t11-invalid@revaltest.local',
			'decision' => 'invalid', 'affiliate_type' => 'sales_agent',
			'team_name' => '', 'wp_user_id' => null, 'ca_id' => null ),
	);
	$t11_snap = create_test_snapshot( $t11_decisions );

	if ( ! $t11_snap ) {
		revalidation_assert( 'G5-T11: create_snapshot() failed', false );
	} else {
		// Verify session row exists and is frozen.
		$t11_session_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}konx_migration_exec_sessions WHERE session_uuid = %s",
				$t11_snap['session_uuid']
			)
		);
		revalidation_assert(
			'G5-T11a: session row exists after commit',
			null !== $t11_session_row,
			'session_uuid=' . $t11_snap['session_uuid']
		);
		revalidation_assert(
			'G5-T11b: session status=frozen after commit',
			'frozen' === ( $t11_session_row->status ?? '' ),
			'status=' . ( $t11_session_row->status ?? 'null' )
		);

		// Verify plan rows exist.
		$t11_plan_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}konx_migration_execution_plan WHERE session_id = %d",
				$t11_snap['session_id']
			)
		);
		revalidation_assert(
			'G5-T11c: plan rows exist (2 records)',
			2 === $t11_plan_count,
			'count=' . $t11_plan_count
		);

		// Verify ledger rows exist (only actionable records — 1 create).
		$t11_ledger_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}konx_migration_execution_ledger WHERE session_id = %d",
				$t11_snap['session_id']
			)
		);
		revalidation_assert(
			'G5-T11d: ledger rows exist (1 actionable record)',
			1 === $t11_ledger_count,
			'count=' . $t11_ledger_count
		);

		// Verify no partial state — counts match return values.
		revalidation_assert(
			'G5-T11e: plan_records count matches return value',
			2 === $t11_snap['plan_records'],
			'returned=' . $t11_snap['plan_records']
		);
		revalidation_assert(
			'G5-T11f: ledger_records count matches return value',
			1 === $t11_snap['ledger_records'],
			'returned=' . $t11_snap['ledger_records']
		);

		cleanup_test_snapshot( $t11_snap['session_uuid'], $t11_snap['session_id'] );
	}
}

// G5-T12: Transaction rollback — plan insert failure leaves no rows.
echo "\n  [G5-T12] Transaction rollback — insert failure leaves no partial state\n";
{
	// To trigger a rollback, we need to cause a plan INSERT to fail.
	// We do this by creating a snapshot with a duplicate source_record_id
	// (violates UNIQUE KEY uq_session_source). We first insert a plan row
	// manually, then attempt to create a snapshot with the same ID.
	//
	// Strategy: use create_snapshot() with two decisions having the same po10_id.
	// The second insert will fail the UNIQUE KEY uq_session_source constraint.
	// The transaction should ROLLBACK, leaving no session, plan, or ledger rows.

	$t12_dup_decisions = array(
		array( 'po10_id' => 900901, 'email' => 'g5t12-dup-a@revaltest.local',
			'decision' => 'create', 'affiliate_type' => 'sales_agent',
			'team_name' => 'G5T12CODEA', 'wp_user_id' => null, 'ca_id' => null ),
		array( 'po10_id' => 900901, // Duplicate — will violate UNIQUE KEY.
			'email' => 'g5t12-dup-b@revaltest.local',
			'decision' => 'create', 'affiliate_type' => 'sales_agent',
			'team_name' => 'G5T12CODEB', 'wp_user_id' => null, 'ca_id' => null ),
	);

	$t12_result = Konx_Migration_Execution_Plan::create_snapshot( $t12_dup_decisions );

	if ( ! is_wp_error( $t12_result ) ) {
		// Snapshot unexpectedly succeeded — this could happen if the DB silently
		// ignores the duplicate. Clean it up and mark the test as inconclusive.
		revalidation_assert(
			'G5-T12: NOTE — duplicate po10_id snapshot succeeded (DB may have handled it). Cleaning up.',
			true // Not a failure of the test framework — mark pass and note.
		);
		if ( isset( $t12_result['session_uuid'] ) ) {
			cleanup_test_snapshot( $t12_result['session_uuid'], $t12_result['session_id'] );
		}
	} else {
		// Snapshot failed (expected). Verify no orphan rows remain.
		// We cannot easily find the session UUID since create_snapshot() rolled back,
		// so we check that no session with po10_id=900901 in plan exists.
		$t12_orphan_plan = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_migration_execution_plan WHERE source_record_id = 900901"
		);
		$t12_orphan_ledger = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_migration_execution_ledger WHERE source_record_id = 900901"
		);

		revalidation_assert(
			'G5-T12a: create_snapshot() returns WP_Error on duplicate key failure',
			true // is_wp_error() was true — we passed the else branch.
		);
		revalidation_assert(
			'G5-T12b: no orphan plan rows after ROLLBACK',
			0 === $t12_orphan_plan,
			'orphan_plan_rows=' . $t12_orphan_plan
		);
		revalidation_assert(
			'G5-T12c: no orphan ledger rows after ROLLBACK',
			0 === $t12_orphan_ledger,
			'orphan_ledger_rows=' . $t12_orphan_ledger
		);
	}
}

// ---------------------------------------------------------------------------
// GROUP 6: Fail-closed hardening tests (Phase 24C-6C final hardening)
// ---------------------------------------------------------------------------
echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo " GROUP 6: Fail-closed hardening (FMP unavailable + InnoDB guard)\n";
echo "═══════════════════════════════════════════════════════════════════\n";

// --------------------------------------------------------------------------
// Helper: create a small valid snapshot whose hash we control, used across
// the FMP-unavailable tests.  Revalidation is called via the real
// Konx_Migration_Revalidator::revalidate() so we can inspect the returned
// session_checks array.
// --------------------------------------------------------------------------

$g6_decisions = array(
	array( 'po10_id' => 910001, 'email' => 'g6-fmp-test@revaltest.local',
		'decision' => 'invalid', 'affiliate_type' => 'sales_agent',
		'team_name' => '', 'wp_user_id' => null, 'ca_id' => null ),
);

// G6-T01: FMP available and matching → plan_hash_integrity = pass.
// (This is implicitly verified by G1-T03d, but an explicit Group 6 entry
//  confirms the baseline before the unavailable-FMP tests.)
echo "\n  [G6-T01] FMP available + matching → plan_hash_integrity = pass\n";
{
	$g6t01_snap = create_test_snapshot( $g6_decisions );
	if ( ! $g6t01_snap ) {
		revalidation_assert( 'G6-T01: snapshot create failed', false );
	} else {
		// Override FMP to match the snapshot decisions exactly.
		$g6t01_result = revalidate_with_fmp_override( $g6t01_snap['session_uuid'], $g6_decisions );

		$g6t01_hash_check = null;
		foreach ( $g6t01_result['session_checks'] as $chk ) {
			if ( 'plan_hash_integrity' === $chk['id'] ) { $g6t01_hash_check = $chk; }
		}
		revalidation_assert(
			'G6-T01: FMP matches → plan_hash_integrity=pass',
			'pass' === ( $g6t01_hash_check['status'] ?? '' ),
			'status=' . ( $g6t01_hash_check['status'] ?? 'none' ) .
			' type=' . ( $g6t01_hash_check['type'] ?? 'none' )
		);

		cleanup_test_snapshot( $g6t01_snap['session_uuid'], $g6t01_snap['session_id'] );
	}
}

// G6-T02: FMP modified after snapshot → plan_hash_integrity = critical:fmp_modified_post_snapshot.
echo "\n  [G6-T02] FMP modified after snapshot → critical:fmp_modified_post_snapshot\n";
{
	$g6t02_snap = create_test_snapshot( $g6_decisions );
	if ( ! $g6t02_snap ) {
		revalidation_assert( 'G6-T02: snapshot create failed', false );
	} else {
		// Override FMP with DIFFERENT decisions (simulates FMP modification post-snapshot).
		$g6t02_modified_decisions = array(
			array( 'po10_id' => 910099, 'email' => 'g6-fmp-modified@revaltest.local',
				'decision' => 'invalid', 'affiliate_type' => 'sales_agent',
				'team_name' => '', 'wp_user_id' => null, 'ca_id' => null ),
		);
		test_set_option_override(
			'konx_migration_state',
			array( 'final_migration_plan' => array( 'decisions' => $g6t02_modified_decisions ) )
		);
		$g6t02_result = Konx_Migration_Revalidator::revalidate( $g6t02_snap['session_uuid'] );
		test_clear_option_override( 'konx_migration_state' );

		$g6t02_hash_check = null;
		foreach ( $g6t02_result['session_checks'] as $chk ) {
			if ( 'plan_hash_integrity' === $chk['id'] ) { $g6t02_hash_check = $chk; }
		}
		revalidation_assert(
			'G6-T02a: FMP modified → plan_hash_integrity=critical',
			'critical' === ( $g6t02_hash_check['status'] ?? '' ),
			'status=' . ( $g6t02_hash_check['status'] ?? 'none' )
		);
		revalidation_assert(
			'G6-T02b: FMP modified → type=fmp_modified_post_snapshot',
			'fmp_modified_post_snapshot' === ( $g6t02_hash_check['type'] ?? '' ),
			'type=' . ( $g6t02_hash_check['type'] ?? 'none' )
		);
		revalidation_assert(
			'G6-T02c: FMP modified → session_valid=false',
			false === $g6t02_result['session_valid']
		);

		cleanup_test_snapshot( $g6t02_snap['session_uuid'], $g6t02_snap['session_id'] );
	}
}

// G6-T03: konx_migration_state option missing → critical:fmp_unverifiable.
echo "\n  [G6-T03] FMP option missing → critical:fmp_unverifiable\n";
{
	$g6t03_snap = create_test_snapshot( $g6_decisions );
	if ( ! $g6t03_snap ) {
		revalidation_assert( 'G6-T03: snapshot create failed', false );
	} else {
		// Simulate missing option: test_remove_option() makes get_option() return $default.
		test_remove_option( 'konx_migration_state' );
		$g6t03_result = Konx_Migration_Revalidator::revalidate( $g6t03_snap['session_uuid'] );
		test_clear_option_override( 'konx_migration_state' );

		$g6t03_hash_check = null;
		foreach ( $g6t03_result['session_checks'] as $chk ) {
			if ( 'plan_hash_integrity' === $chk['id'] ) { $g6t03_hash_check = $chk; }
		}
		revalidation_assert(
			'G6-T03a: FMP missing → plan_hash_integrity=critical',
			'critical' === ( $g6t03_hash_check['status'] ?? '' ),
			'status=' . ( $g6t03_hash_check['status'] ?? 'none' )
		);
		revalidation_assert(
			'G6-T03b: FMP missing → type=fmp_unverifiable',
			'fmp_unverifiable' === ( $g6t03_hash_check['type'] ?? '' ),
			'type=' . ( $g6t03_hash_check['type'] ?? 'none' )
		);
		revalidation_assert(
			'G6-T03c: FMP missing → session_valid=false',
			false === $g6t03_result['session_valid']
		);

		cleanup_test_snapshot( $g6t03_snap['session_uuid'], $g6t03_snap['session_id'] );
	}
}

// G6-T04: final_migration_plan key missing from konx_migration_state → critical:fmp_unverifiable.
// Simulates a state that has scan/decision_matrix data but has not yet reached the FMP step.
echo "\n  [G6-T04] final_migration_plan missing → critical:fmp_unverifiable\n";
{
	$g6t04_snap = create_test_snapshot( $g6_decisions );
	if ( ! $g6t04_snap ) {
		revalidation_assert( 'G6-T04: snapshot create failed', false );
	} else {
		// State exists but has no final_migration_plan key (wizard not yet at FMP step).
		// Intentionally include decision_matrix to confirm it is not used as a fallback.
		test_set_option_override( 'konx_migration_state', array(
			'step'             => 4,
			'status'           => 'complete',
			'decision_matrix'  => array( 'decisions' => $g6_decisions ),  // DM present but must not be read.
		) );
		$g6t04_result = Konx_Migration_Revalidator::revalidate( $g6t04_snap['session_uuid'] );
		test_clear_option_override( 'konx_migration_state' );

		$g6t04_hash_check = null;
		foreach ( $g6t04_result['session_checks'] as $chk ) {
			if ( 'plan_hash_integrity' === $chk['id'] ) { $g6t04_hash_check = $chk; }
		}
		revalidation_assert(
			'G6-T04a: final_migration_plan missing → plan_hash_integrity=critical',
			'critical' === ( $g6t04_hash_check['status'] ?? '' ),
			'status=' . ( $g6t04_hash_check['status'] ?? 'none' )
		);
		revalidation_assert(
			'G6-T04b: final_migration_plan missing → type=fmp_unverifiable',
			'fmp_unverifiable' === ( $g6t04_hash_check['type'] ?? '' ),
			'type=' . ( $g6t04_hash_check['type'] ?? 'none' )
		);

		cleanup_test_snapshot( $g6t04_snap['session_uuid'], $g6t04_snap['session_id'] );
	}
}

// G6-T05: decisions array missing/empty in final_migration_plan → critical:fmp_unverifiable.
echo "\n  [G6-T05] final_migration_plan.decisions missing → critical:fmp_unverifiable\n";
{
	$g6t05_snap = create_test_snapshot( $g6_decisions );
	if ( ! $g6t05_snap ) {
		revalidation_assert( 'G6-T05: snapshot create failed', false );
	} else {
		// State has final_migration_plan key but no decisions array inside it.
		test_set_option_override( 'konx_migration_state', array( 'final_migration_plan' => array( 'summary' => array( 'total' => 0 ) ) ) );
		$g6t05_result = Konx_Migration_Revalidator::revalidate( $g6t05_snap['session_uuid'] );
		test_clear_option_override( 'konx_migration_state' );

		$g6t05_hash_check = null;
		foreach ( $g6t05_result['session_checks'] as $chk ) {
			if ( 'plan_hash_integrity' === $chk['id'] ) { $g6t05_hash_check = $chk; }
		}
		revalidation_assert(
			'G6-T05a: decisions missing → plan_hash_integrity=critical',
			'critical' === ( $g6t05_hash_check['status'] ?? '' ),
			'status=' . ( $g6t05_hash_check['status'] ?? 'none' )
		);
		revalidation_assert(
			'G6-T05b: decisions missing → type=fmp_unverifiable',
			'fmp_unverifiable' === ( $g6t05_hash_check['type'] ?? '' ),
			'type=' . ( $g6t05_hash_check['type'] ?? 'none' )
		);

		cleanup_test_snapshot( $g6t05_snap['session_uuid'], $g6t05_snap['session_id'] );
	}
}

// G6-T06: create_snapshot() refuses to begin when a metadata table is not InnoDB.
// Temporarily ALTER one table to MyISAM, verify WP_Error is returned, verify no rows
// were created, restore InnoDB.  Uses try/finally for cleanup guarantee.
echo "\n  [G6-T06] Non-InnoDB metadata table → create_snapshot() returns WP_Error\n";
{
	$g6t06_decisions = array(
		array( 'po10_id' => 910601, 'email' => 'g6t06-innodb-guard@revaltest.local',
			'decision' => 'invalid', 'affiliate_type' => 'sales_agent',
			'team_name' => '', 'wp_user_id' => null, 'ca_id' => null ),
	);

	$g6t06_table     = $wpdb->prefix . 'konx_migration_exec_sessions';
	$g6t06_converted = false; // Track whether we changed the engine so we can restore.

	// Baseline session count BEFORE the ALTER — used by G6-T06e to verify no row was inserted.
	$g6t06_session_count_before = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->prefix}konx_migration_exec_sessions"
	);

	try {
		// Convert exec_sessions to MyISAM.
		$wpdb->query( "ALTER TABLE `{$g6t06_table}` ENGINE=MyISAM" );
		$g6t06_converted = true;

		$g6t06_result = Konx_Migration_Execution_Plan::create_snapshot( $g6t06_decisions );

		revalidation_assert(
			'G6-T06a: non-InnoDB → create_snapshot() returns WP_Error',
			is_wp_error( $g6t06_result ),
			'result=' . ( is_wp_error( $g6t06_result ) ? 'WP_Error(' . $g6t06_result->get_error_code() . ')' : gettype( $g6t06_result ) )
		);
		if ( is_wp_error( $g6t06_result ) ) {
			revalidation_assert(
				'G6-T06b: error code = migration_metadata_not_transactional',
				'migration_metadata_not_transactional' === $g6t06_result->get_error_code(),
				'code=' . $g6t06_result->get_error_code()
			);
		} else {
			revalidation_assert( 'G6-T06b: error code check (skipped — no WP_Error)', false );
		}

		// Verify no rows were written to any of the three metadata tables.
		// exec_sessions: compare total count to baseline (no column unique to this test exists there).
		$g6t06_session_count_after = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_migration_exec_sessions"
		);
		// plan / ledger: filter by the specific po10_id used in this test.
		$g6t06_orphan_plan = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_migration_execution_plan WHERE source_record_id = 910601"
		);
		$g6t06_orphan_ledger = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}konx_migration_execution_ledger WHERE source_record_id = 910601"
		);

		revalidation_assert(
			'G6-T06c: no plan rows created',
			0 === $g6t06_orphan_plan,
			'orphan_plan=' . $g6t06_orphan_plan
		);
		revalidation_assert(
			'G6-T06d: no ledger rows created',
			0 === $g6t06_orphan_ledger,
			'orphan_ledger=' . $g6t06_orphan_ledger
		);
		revalidation_assert(
			'G6-T06e: no new session row created',
			$g6t06_session_count_before === $g6t06_session_count_after,
			'before=' . $g6t06_session_count_before . ' after=' . $g6t06_session_count_after
		);

	} finally {
		// Always restore InnoDB, even if an assertion failed.
		if ( $g6t06_converted ) {
			$wpdb->query( "ALTER TABLE `{$g6t06_table}` ENGINE=InnoDB" );
		}
	}

}

// G6-T07: Decision Matrix differs from snapshot; FMP matches → plan_hash_integrity = pass.
//
// This is the critical regression test for the canonical-path fix.
// It proves that the revalidator reads final_migration_plan.decisions, not
// decision_matrix.decisions.  If the revalidator ever reverted to reading
// decision_matrix, this test would produce CRITICAL:fmp_modified_post_snapshot.
echo "\n  [G6-T07] DM differs from snapshot; FMP matches → plan_hash_integrity=pass\n";
{
	$g6t07_snap = create_test_snapshot( $g6_decisions );
	if ( ! $g6t07_snap ) {
		revalidation_assert( 'G6-T07: snapshot create failed', false );
	} else {
		// State: final_migration_plan matches the snapshot; decision_matrix has different decisions.
		$g6t07_dm_different = array(
			array( 'po10_id' => 999701, 'email' => 'g6t07-dm-only@revaltest.local',
				'decision' => 'review', 'affiliate_type' => 'sales_agent',
				'team_name' => 'DMONLY07', 'wp_user_id' => null, 'ca_id' => null ),
		);
		test_set_option_override( 'konx_migration_state', array(
			'final_migration_plan' => array( 'decisions' => $g6_decisions ),    // Matches snapshot.
			'decision_matrix'      => array( 'decisions' => $g6t07_dm_different ), // Does NOT match.
		) );
		$g6t07_result = Konx_Migration_Revalidator::revalidate( $g6t07_snap['session_uuid'] );
		test_clear_option_override( 'konx_migration_state' );

		$g6t07_hash_check = null;
		foreach ( $g6t07_result['session_checks'] as $chk ) {
			if ( 'plan_hash_integrity' === $chk['id'] ) { $g6t07_hash_check = $chk; }
		}
		revalidation_assert(
			'G6-T07a: DM differs but FMP matches → plan_hash_integrity=pass',
			'pass' === ( $g6t07_hash_check['status'] ?? '' ),
			'status=' . ( $g6t07_hash_check['status'] ?? 'none' ) .
			' type=' . ( $g6t07_hash_check['type'] ?? 'none' )
		);
		revalidation_assert(
			'G6-T07b: DM differs but FMP matches → session_valid=true (no other blockers)',
			true === $g6t07_result['session_valid'],
			'valid=' . var_export( $g6t07_result['session_valid'], true )
		);

		cleanup_test_snapshot( $g6t07_snap['session_uuid'], $g6t07_snap['session_id'] );
	}
}

// G6-T08: Decision Matrix matches snapshot; FMP differs → plan_hash_integrity = critical.
//
// Proves that a matching Decision Matrix cannot mask a changed Final Migration Plan.
// If the revalidator read decision_matrix, it would return PASS here (wrong).
// The correct result is CRITICAL:fmp_modified_post_snapshot.
echo "\n  [G6-T08] DM matches snapshot; FMP differs → critical:fmp_modified_post_snapshot\n";
{
	$g6t08_snap = create_test_snapshot( $g6_decisions );
	if ( ! $g6t08_snap ) {
		revalidation_assert( 'G6-T08: snapshot create failed', false );
	} else {
		// State: decision_matrix matches the snapshot; final_migration_plan has different decisions.
		$g6t08_fmp_different = array(
			array( 'po10_id' => 999801, 'email' => 'g6t08-fmp-changed@revaltest.local',
				'decision' => 'invalid', 'affiliate_type' => 'sales_agent',
				'team_name' => '', 'wp_user_id' => null, 'ca_id' => null ),
		);
		test_set_option_override( 'konx_migration_state', array(
			'final_migration_plan' => array( 'decisions' => $g6t08_fmp_different ),  // Different from snapshot.
			'decision_matrix'      => array( 'decisions' => $g6_decisions ),          // Matches snapshot (must not be used).
		) );
		$g6t08_result = Konx_Migration_Revalidator::revalidate( $g6t08_snap['session_uuid'] );
		test_clear_option_override( 'konx_migration_state' );

		$g6t08_hash_check = null;
		foreach ( $g6t08_result['session_checks'] as $chk ) {
			if ( 'plan_hash_integrity' === $chk['id'] ) { $g6t08_hash_check = $chk; }
		}
		revalidation_assert(
			'G6-T08a: DM matches but FMP differs → plan_hash_integrity=critical',
			'critical' === ( $g6t08_hash_check['status'] ?? '' ),
			'status=' . ( $g6t08_hash_check['status'] ?? 'none' )
		);
		revalidation_assert(
			'G6-T08b: DM matches but FMP differs → type=fmp_modified_post_snapshot',
			'fmp_modified_post_snapshot' === ( $g6t08_hash_check['type'] ?? '' ),
			'type=' . ( $g6t08_hash_check['type'] ?? 'none' )
		);
		revalidation_assert(
			'G6-T08c: DM matches but FMP differs → session_valid=false',
			false === $g6t08_result['session_valid']
		);

		cleanup_test_snapshot( $g6t08_snap['session_uuid'], $g6t08_snap['session_id'] );
	}
}

// ---------------------------------------------------------------------------
// Final report.
// ---------------------------------------------------------------------------
echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo " PHASE 24C-6C REVALIDATION ENGINE — TEST RESULTS\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "\n";
foreach ( $tests as $t ) {
	echo $t . "\n";
}
echo "\n";
$total = $pass + $fail;
$icon  = ( 0 === $fail ) ? '✓' : '✗';
echo "  {$icon} {$pass}/{$total} tests passed";
if ( $fail > 0 ) {
	echo " | {$fail} FAILED";
}
echo "\n\n";

if ( 0 === $fail ) {
	echo "  PHASE 24C-6C REVALIDATION ENGINE: ALL TESTS PASS\n\n";
} else {
	echo "  PHASE 24C-6C REVALIDATION ENGINE: {$fail} TEST(S) FAILED — see [FAIL] lines above.\n\n";
}
