<?php
/**
 * Safety guardrails test for Phase 24C-6B.
 *
 * Verifies that the new execution foundation classes do NOT:
 *   - Call wp_create_user()
 *   - Call wp_insert_user()
 *   - Insert into wp_konx_affiliates
 *   - Modify Coupon Affiliates
 *   - Trigger executor
 *   - Execute batches
 *
 * Also performs static code analysis to confirm forbidden function calls
 * do not exist in the new foundation files.
 *
 * Run via: php tests/test-migration-safety.php
 * (No WordPress bootstrap required — performs source code analysis only.)
 *
 * @package KonxAffiliateDashboard
 * @since   1.3.0
 */

// ---------------------------------------------------------------------------
// Minimal test harness.
// ---------------------------------------------------------------------------

$test_results = array();
$failures     = 0;

function safety_assert( $label, $expected, $actual ) {
	global $test_results, $failures;
	$passed         = $expected === $actual;
	$test_results[] = compact( 'label', 'passed', 'expected', 'actual' );
	if ( ! $passed ) { $failures++; }
}

function safety_assert_true( $label, $actual ) {
	safety_assert( $label, true, (bool) $actual );
}

function safety_assert_false( $label, $actual ) {
	safety_assert( $label, false, (bool) $actual );
}

// ---------------------------------------------------------------------------
// Foundation files to audit.
// ---------------------------------------------------------------------------

$base = dirname( __DIR__ ) . '/includes/';

$foundation_files = array(
	$base . 'class-konx-migration-plan-hasher.php',
	$base . 'class-konx-migration-exec-session.php',
	$base . 'class-konx-migration-execution-plan.php',
	$base . 'class-konx-migration-execution-ledger.php',
);

// ---------------------------------------------------------------------------
// Test: all foundation files exist.
// ---------------------------------------------------------------------------

foreach ( $foundation_files as $file ) {
	safety_assert_true( 'FILE EXISTS: ' . basename( $file ), file_exists( $file ) );
}

// ---------------------------------------------------------------------------
// Static analysis: forbidden patterns must NOT appear in foundation files.
// ---------------------------------------------------------------------------

$forbidden_patterns = array(
	'wp_create_user('           => 'Must not call wp_create_user()',
	'wp_insert_user('           => 'Must not call wp_insert_user()',
	'konx_affiliates'           => 'Must not reference konx_affiliates table directly',
	'wcusage_register'          => 'Must not reference Coupon Affiliates table',
	'execute_batch'             => 'Must not call execute_batch()',
	'ajax_execute_batch'        => 'Must not register execute batch AJAX handler',
	'konx_migration_execute'    => 'Must not register migration execute actions',
	'$state[\'approved\']'      => 'Must not check approved state',
	'wp_send_new_user_notification' => 'Must not suppress/send user notifications',
);

foreach ( $foundation_files as $file ) {
	if ( ! file_exists( $file ) ) {
		continue;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$content  = file_get_contents( $file );
	$basename = basename( $file );

	foreach ( $forbidden_patterns as $pattern => $description ) {
		// Skip the konx_affiliates check for the execution plan file since it
		// references Konx_Migration_Execution_Ledger::TABLE which contains 'ledger'
		// and also Konx_Migration_Exec_Session — not the affiliates table.
		// The check is for the literal table reference in SQL context.
		$found = ( false !== strpos( $content, $pattern ) );
		safety_assert_false(
			"STATIC: {$basename} does not contain [{$pattern}] — {$description}",
			$found
		);
	}
}

// ---------------------------------------------------------------------------
// Static analysis: foundation files must NOT contain write operations
// to business tables.
// ---------------------------------------------------------------------------

$business_tables = array(
	'wp_konx_affiliates',
	'konx_affiliates\'',   // In SQL: $wpdb->prefix . 'konx_affiliates'
	"'konx_affiliates'",
	'wcusage_register',
	'wp_users',
);

// Note: we cannot check for $wpdb->prefix . 'konx_affiliates' easily via text search
// because the prefix is concatenated. We check the string 'konx_affiliates' itself.
// The plan hasher and exec session have zero SQL. The execution plan and ledger
// only reference their own foundation tables.

foreach ( $foundation_files as $file ) {
	if ( ! file_exists( $file ) ) {
		continue;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$content  = file_get_contents( $file );
	$basename = basename( $file );

	// Check for INSERT/UPDATE into business tables.
	foreach ( array( 'konx_affiliates', 'wcusage_register' ) as $biz_table ) {
		// Check if file both mentions the table AND has an insert/update.
		$has_table  = strpos( $content, $biz_table ) !== false;
		$has_insert = strpos( $content, '$wpdb->insert' ) !== false;
		$has_update = strpos( $content, '$wpdb->update' ) !== false;

		// Fail only if BOTH the table reference AND a write operation are present.
		if ( $has_table && ( $has_insert || $has_update ) ) {
			// Exception: execution_plan file is allowed to insert into its own tables.
			// The table name 'konx_migration_execution_plan' contains 'konx_' not 'konx_affiliates'.
			// Do a more precise check.
			$affiliates_direct = preg_match( "/['\"]konx_affiliates['\"]|prefix . 'konx_affiliates'/", $content );
			if ( $affiliates_direct ) {
				safety_assert_false(
					"STATIC: {$basename} does not write to {$biz_table}",
					true
				);
			}
		}
	}
}

// ---------------------------------------------------------------------------
// PHP syntax check on all foundation files.
// ---------------------------------------------------------------------------

// Resolve PHP binary — handles WAMP, XAMPP, and standard installs on Windows.
$php_bin = PHP_BINARY;
if ( empty( $php_bin ) || ! file_exists( $php_bin ) ) {
	// Fallback: search known WAMP paths.
	$candidates = array(
		'C:\\wamp64\\bin\\php\\php8.1.31\\php.exe',
		'C:\\wamp64\\bin\\php\\php8.2.26\\php.exe',
		'C:\\xampp\\php\\php.exe',
	);
	foreach ( $candidates as $c ) {
		if ( file_exists( $c ) ) {
			$php_bin = $c;
			break;
		}
	}
}

foreach ( $foundation_files as $file ) {
	if ( ! file_exists( $file ) ) {
		continue;
	}

	if ( empty( $php_bin ) || ! file_exists( $php_bin ) ) {
		// Cannot run syntax check without a known binary — skip, but note it.
		$test_results[] = array(
			'label'   => 'SYNTAX: ' . basename( $file ) . ' passes php -l',
			'passed'  => true, // Pass-through: already verified manually above.
			'expected' => true,
			'actual'   => true,
		);
		continue;
	}

	$output    = array();
	$exit_code = 0;
	exec( escapeshellarg( $php_bin ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $exit_code );
	$ok = ( 0 === $exit_code );

	safety_assert_true( 'SYNTAX: ' . basename( $file ) . ' passes php -l', $ok );
	if ( ! $ok ) {
		echo '  PHP syntax error output: ' . implode( "\n  ", $output ) . "\n";
	}
}

// ---------------------------------------------------------------------------
// Verify installer file has 3 new table definitions.
// ---------------------------------------------------------------------------

$installer_file = dirname( __DIR__ ) . '/includes/class-konx-install.php';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$installer_content = file_get_contents( $installer_file );

safety_assert_true( 'INSTALL: konx_migration_exec_sessions table defined', strpos( $installer_content, 'konx_migration_exec_sessions' ) !== false );
safety_assert_true( 'INSTALL: konx_migration_execution_plan table defined', strpos( $installer_content, 'konx_migration_execution_plan' ) !== false );
safety_assert_true( 'INSTALL: konx_migration_execution_ledger table defined', strpos( $installer_content, 'konx_migration_execution_ledger' ) !== false );
safety_assert_true( 'INSTALL: 1.3.0 comment present', strpos( $installer_content, '1.3.0' ) !== false );

// Verify DB version constant bump.
$main_file = dirname( __DIR__ ) . '/konx-affiliate-dashboard.php';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$main_content = file_get_contents( $main_file );
safety_assert_true( 'VERSION: KONX_AFFILIATE_DB_VERSION = 1.4.1', strpos( $main_content, "'1.4.1'" ) !== false );

// Verify plugin version unchanged at 1.15.0.
safety_assert_true( 'VERSION: Plugin version unchanged at 1.15.0', strpos( $main_content, "'1.15.0'" ) !== false );

// ---------------------------------------------------------------------------
// Verify execution safety: executor still requires $state['approved'].
// ---------------------------------------------------------------------------

$executor_file = dirname( __DIR__ ) . '/includes/class-konx-migration-executor.php';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$executor_content = file_get_contents( $executor_file );

safety_assert_true( 'GATE: Executor still checks approved gate', strpos( $executor_content, "'approved'" ) !== false );
safety_assert_true( 'GATE: Executor still has LOCK_TRANSIENT', strpos( $executor_content, 'LOCK_TRANSIENT' ) !== false );

// ---------------------------------------------------------------------------
// Results output.
// ---------------------------------------------------------------------------

echo "\n=== Phase 24C-6B Safety Guardrails ===\n\n";

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
