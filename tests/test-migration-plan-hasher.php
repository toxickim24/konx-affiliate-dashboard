<?php
/**
 * Tests for Konx_Migration_Plan_Hasher.
 *
 * These tests are designed to run standalone (no WordPress bootstrap required)
 * because Konx_Migration_Plan_Hasher has zero WordPress dependencies.
 *
 * Run: php tests/test-migration-plan-hasher.php
 *
 * @package KonxAffiliateDashboard
 * @since   1.3.0
 */

// ---------------------------------------------------------------------------
// Bootstrap — load the class directly (no WP needed).
// ---------------------------------------------------------------------------

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../includes/class-konx-migration-plan-hasher.php';

// ---------------------------------------------------------------------------
// Minimal test harness (no PHPUnit dependency).
// ---------------------------------------------------------------------------

$test_results = array();
$failures     = 0;

/**
 * Assert equality and record result.
 *
 * @param string $label       Test name.
 * @param mixed  $expected    Expected value.
 * @param mixed  $actual      Actual value.
 */
function assert_equals( $label, $expected, $actual ) {
	global $test_results, $failures;

	$passed = $expected === $actual;

	$test_results[] = array(
		'label'   => $label,
		'passed'  => $passed,
		'expected' => $expected,
		'actual'   => $actual,
	);

	if ( ! $passed ) {
		$failures++;
	}
}

/**
 * Assert that two values are NOT equal.
 *
 * @param string $label    Test name.
 * @param mixed  $value_a  First value.
 * @param mixed  $value_b  Second value.
 */
function assert_not_equals( $label, $value_a, $value_b ) {
	global $test_results, $failures;

	$passed = $value_a !== $value_b;

	$test_results[] = array(
		'label'   => $label,
		'passed'  => $passed,
		'expected' => '(not equal)',
		'actual'   => $passed ? '(different — correct)' : '(same — wrong)',
	);

	if ( ! $passed ) {
		$failures++;
	}
}

/**
 * Assert that a value is true.
 *
 * @param string $label  Test name.
 * @param mixed  $actual Value to check.
 */
function assert_true( $label, $actual ) {
	assert_equals( $label, true, (bool) $actual );
}

// ---------------------------------------------------------------------------
// Test fixtures.
// ---------------------------------------------------------------------------

$fixture_base = array(
	array(
		'po10_id'        => 101,
		'email'          => 'alice@example.com',
		'decision'       => 'create',
		'affiliate_type' => 'sales_agent',
		'team_name'      => 'ALICE',
		'wp_user_id'     => null,
		'ca_id'          => null,
	),
	array(
		'po10_id'        => 202,
		'email'          => 'bob@example.com',
		'decision'       => 'link_wp',
		'affiliate_type' => 'business',
		'team_name'      => 'BOB',
		'wp_user_id'     => 55,
		'ca_id'          => null,
	),
	array(
		'po10_id'        => 303,
		'email'          => 'carol@example.com',
		'decision'       => 'link_ca',
		'affiliate_type' => 'team_agent',
		'team_name'      => 'CAROL',
		'wp_user_id'     => 77,
		'ca_id'          => 12,
	),
	array(
		'po10_id'        => 404,
		'email'          => 'dave@example.com',
		'decision'       => 'invalid',
		'affiliate_type' => 'sales_agent',
		'team_name'      => 'DAVE',
		'wp_user_id'     => null,
		'ca_id'          => null,
	),
);

// ---------------------------------------------------------------------------
// HASHING TESTS
// ---------------------------------------------------------------------------

// Test 1: Same plan produces same hash.
$hash_a = Konx_Migration_Plan_Hasher::hash_final_plan( $fixture_base );
$hash_b = Konx_Migration_Plan_Hasher::hash_final_plan( $fixture_base );
assert_equals( 'T01: Same plan → same hash (idempotent)', $hash_a, $hash_b );

// Test 2: Hash is 64 hex characters (SHA-256).
assert_equals( 'T02: Hash length is 64 chars', 64, strlen( $hash_a ) );

// Test 3: Reordered array produces the same hash.
$fixture_reordered = array_reverse( $fixture_base );
$hash_reordered    = Konx_Migration_Plan_Hasher::hash_final_plan( $fixture_reordered );
assert_equals( 'T03: Reordered decisions → same hash', $hash_a, $hash_reordered );

// Test 4: Record changed → different hash.
$fixture_changed          = $fixture_base;
$fixture_changed[0]['decision'] = 'link_wp'; // was 'create'
$hash_changed             = Konx_Migration_Plan_Hasher::hash_final_plan( $fixture_changed );
assert_not_equals( 'T04: Action changed → different hash', $hash_a, $hash_changed );

// Test 5: WP user mapping changed → different hash.
$fixture_wp_changed             = $fixture_base;
$fixture_wp_changed[1]['wp_user_id'] = 999; // was 55
$hash_wp_changed                = Konx_Migration_Plan_Hasher::hash_final_plan( $fixture_wp_changed );
assert_not_equals( 'T05: WP user_id changed → different hash', $hash_a, $hash_wp_changed );

// Test 6: CA ID changed → different hash.
$fixture_ca_changed           = $fixture_base;
$fixture_ca_changed[2]['ca_id'] = 99; // was 12
$hash_ca_changed              = Konx_Migration_Plan_Hasher::hash_final_plan( $fixture_ca_changed );
assert_not_equals( 'T06: CA ID changed → different hash', $hash_a, $hash_ca_changed );

// Test 7: Email case differences are normalized → same hash.
$fixture_email_case          = $fixture_base;
$fixture_email_case[0]['email'] = 'ALICE@EXAMPLE.COM'; // uppercase
$hash_email_case             = Konx_Migration_Plan_Hasher::hash_final_plan( $fixture_email_case );
assert_equals( 'T07: Email case-normalized → same hash', $hash_a, $hash_email_case );

// Test 8: Email whitespace trimmed → same hash.
$fixture_email_ws          = $fixture_base;
$fixture_email_ws[0]['email'] = '  alice@example.com  '; // leading/trailing spaces
$hash_email_ws             = Konx_Migration_Plan_Hasher::hash_final_plan( $fixture_email_ws );
assert_equals( 'T08: Email whitespace trimmed → same hash', $hash_a, $hash_email_ws );

// Test 9: Extra non-canonical fields do NOT affect hash.
$fixture_extra                 = $fixture_base;
$fixture_extra[0]['first_name'] = 'Alice'; // not a canonical field
$fixture_extra[0]['reasons']    = array( 'some reason' );
$hash_extra                    = Konx_Migration_Plan_Hasher::hash_final_plan( $fixture_extra );
assert_equals( 'T09: Non-canonical fields ignored → same hash', $hash_a, $hash_extra );

// Test 10: verify_plan_hash — correct hash returns true.
assert_true( 'T10: verify_plan_hash correct hash → true', Konx_Migration_Plan_Hasher::verify_plan_hash( $fixture_base, $hash_a ) );

// Test 11: verify_plan_hash — wrong hash returns false.
$wrong_hash = str_repeat( '0', 64 );
assert_equals( 'T11: verify_plan_hash wrong hash → false', false, Konx_Migration_Plan_Hasher::verify_plan_hash( $fixture_base, $wrong_hash ) );

// Test 12: verify_plan_hash — short/invalid hash returns false.
assert_equals( 'T12: verify_plan_hash invalid hash length → false', false, Konx_Migration_Plan_Hasher::verify_plan_hash( $fixture_base, 'abc' ) );

// ---------------------------------------------------------------------------
// COUNT TESTS
// ---------------------------------------------------------------------------

// Test 13: count_decisions returns correct counts.
$counts = Konx_Migration_Plan_Hasher::count_decisions( $fixture_base );
assert_equals( 'T13a: count create = 1', 1, $counts['create'] );
assert_equals( 'T13b: count link_wp = 1', 1, $counts['link_wp'] );
assert_equals( 'T13c: count link_ca = 1', 1, $counts['link_ca'] );
assert_equals( 'T13d: count invalid = 1', 1, $counts['invalid'] );
assert_equals( 'T13e: count total = 4', 4, $counts['total'] );

// Test 14: count_decisions with empty array.
$empty_counts = Konx_Migration_Plan_Hasher::count_decisions( array() );
assert_equals( 'T14: count empty array total = 0', 0, $empty_counts['total'] );

// Test 15: hash_source_content returns 64-char SHA-256.
$content_hash = Konx_Migration_Plan_Hasher::hash_source_content( 'id,email,team_name\n1,a@b.com,TEAM1' );
assert_equals( 'T15: hash_source_content returns 64 chars', 64, strlen( $content_hash ) );

// Test 16: hash_source_file returns null for non-existent file.
$null_hash = Konx_Migration_Plan_Hasher::hash_source_file( '/non/existent/file.csv' );
assert_equals( 'T16: hash_source_file non-existent → null', null, $null_hash );

// ---------------------------------------------------------------------------
// CANONICALIZATION TESTS
// ---------------------------------------------------------------------------

// Test 17: canonicalize_decisions produces fixed-length output.
$canonical = Konx_Migration_Plan_Hasher::canonicalize_decisions( $fixture_base );
assert_equals( 'T17: canonical count matches input', count( $fixture_base ), count( $canonical ) );

// Test 18: canonical records sorted by source_record_id.
assert_equals( 'T18a: canonical[0].source_record_id = 101', 101, $canonical[0]['source_record_id'] );
assert_equals( 'T18b: canonical[1].source_record_id = 202', 202, $canonical[1]['source_record_id'] );
assert_equals( 'T18c: canonical[2].source_record_id = 303', 303, $canonical[2]['source_record_id'] );

// Test 19: canonical record has exactly 7 keys.
assert_equals( 'T19: canonical record has 7 keys', 7, count( $canonical[0] ) );

// Test 20: canonical record keys match expected set.
$expected_keys   = array( 'source_record_id', 'source_email', 'action', 'affiliate_type', 'team_name', 'wp_user_id', 'coupon_affiliate_id' );
$canonical_keys  = array_keys( $canonical[0] );
assert_equals( 'T20: canonical record keys match spec', $expected_keys, $canonical_keys );

// ---------------------------------------------------------------------------
// Results output.
// ---------------------------------------------------------------------------

echo "\n=== Konx_Migration_Plan_Hasher Tests ===\n\n";

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

exit( $failures > 0 ? 1 : 0 );
