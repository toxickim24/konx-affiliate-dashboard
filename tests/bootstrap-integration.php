<?php
/**
 * Minimal integration test bootstrap for Phase 24C-6B.
 *
 * Provides a lightweight WordPress-compatible environment for running
 * database-integrated tests without requiring full WP CLI bootstrap.
 *
 * Implements only what the 24C-6B classes actually call:
 *   - $wpdb (with real DB connection)
 *   - current_time()
 *   - sanitize_*() functions
 *   - wp_json_encode()
 *   - WP_Error
 *   - KONX_AFFILIATE_VERSION / KONX_AFFILIATE_DB_VERSION constants
 *
 * @package KonxAffiliateDashboard
 */

// ---------------------------------------------------------------------------
// WordPress constants the classes expect.
// ---------------------------------------------------------------------------
define( 'ABSPATH', __DIR__ . '/' );
define( 'KONX_AFFILIATE_VERSION', '1.15.0' );
define( 'KONX_AFFILIATE_DB_VERSION', '1.4.1' );
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

// ---------------------------------------------------------------------------
// Phase 24C-6D test execution gate.
// Enables Konx_Migration_Record_Executor::execute_record() and
// Konx_Migration_Exec_Session::set_test_execution_status().
// MUST NOT be defined in production wp-config.php or plugin bootstrap.
// ---------------------------------------------------------------------------
if ( ! defined( 'KONX_MIGRATION_TEST_EXECUTION_ENABLED' ) ) {
	define( 'KONX_MIGRATION_TEST_EXECUTION_ENABLED', true );
}

// ---------------------------------------------------------------------------
// WP_Error stub.
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// ---------------------------------------------------------------------------
// WordPress options API stubs — required for FMP parity check in revalidator.
// ---------------------------------------------------------------------------

/**
 * Unserialize a value only if it was serialized.
 * Mirrors WordPress's maybe_unserialize() for use in tests.
 */
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $data ) {
		if ( ! is_string( $data ) ) {
			return $data;
		}
		// Only attempt unserialization on strings that look serialized.
		// PHP serialized strings begin with a type letter followed by ':'.
		$trimmed = trim( $data );
		if ( in_array( substr( $trimmed, 0, 2 ), array( 'a:', 'O:', 's:', 'b:', 'i:', 'd:', 'N;' ), true ) || 'b:0;' === $trimmed ) {
			$unserialized = @unserialize( $trimmed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $unserialized || 'b:0;' === $trimmed ) {
				return $unserialized;
			}
		}
		return $data;
	}
}

/**
 * Sentinel value used by test_remove_option() to signal that get_option()
 * should return the caller-supplied $default rather than any stored value.
 * This lets tests simulate a missing option without touching the real DB.
 */
if ( ! defined( 'TEST_OPTION_NOT_SET' ) ) {
	define( 'TEST_OPTION_NOT_SET', '__konx_test_option_not_set_sentinel_24c6c__' );
}

/**
 * Per-test option overrides.  Keys are option names, values are override
 * payloads.  When a key is present in this array, get_option() returns the
 * override value instead of reading from the database.  A value equal to
 * TEST_OPTION_NOT_SET causes get_option() to return $default (simulating a
 * missing option without deleting anything from wp_options).
 *
 * @var array<string, mixed>
 */
$_test_option_overrides = array();

/**
 * Install a per-test option override.
 *
 * @param string $option Option name.
 * @param mixed  $value  Override value returned by get_option().
 */
function test_set_option_override( $option, $value ) {
	global $_test_option_overrides;
	$_test_option_overrides[ $option ] = $value;
}

/**
 * Simulate a missing option without touching the database.
 * get_option( $option, $default ) will return $default.
 *
 * @param string $option Option name.
 */
function test_remove_option( $option ) {
	global $_test_option_overrides;
	$_test_option_overrides[ $option ] = TEST_OPTION_NOT_SET;
}

/**
 * Remove the per-test override for $option.
 * Subsequent calls to get_option() will read from the real database.
 *
 * @param string $option Option name.
 */
function test_clear_option_override( $option ) {
	global $_test_option_overrides;
	unset( $_test_option_overrides[ $option ] );
}

/**
 * Read a WordPress option from wp_options via the test $wpdb connection.
 * Returns $default if the option does not exist.
 *
 * Checks $_test_option_overrides first so individual tests can control what
 * the FMP parity check sees without touching the real wp_options table.
 * This is required so that the FMP parity check in
 * Konx_Migration_Revalidator::check_plan_hash() can verify the live
 * Final Migration Plan against the frozen plan hash.
 */
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		global $wpdb, $_test_option_overrides;

		// Check per-test overrides first.
		if ( array_key_exists( $option, $_test_option_overrides ) ) {
			$val = $_test_option_overrides[ $option ];
			// TEST_OPTION_NOT_SET simulates a missing option → return $default.
			if ( TEST_OPTION_NOT_SET === $val ) {
				return $default;
			}
			return $val;
		}

		// Fall through to the real database.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option
			)
		);
		if ( null === $row ) {
			return $default;
		}
		return maybe_unserialize( $row->option_value );
	}
}

// ---------------------------------------------------------------------------
// WordPress i18n stubs (passthrough — not needed for test logic).
// ---------------------------------------------------------------------------
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'sprintf' ) ) {
	// Already a PHP native — just a safeguard.
}

// ---------------------------------------------------------------------------
// Sanitization stubs (passthrough with minimal cleaning).
// ---------------------------------------------------------------------------
function sanitize_text_field( $str ) {
	return trim( strip_tags( (string) $str ) );
}

function sanitize_email( $email ) {
	return trim( (string) $email );
}

function sanitize_file_name( $name ) {
	return basename( (string) $name );
}

function wp_json_encode( $data, $options = 0 ) {
	return json_encode( $data, $options ); // phpcs:ignore
}

function absint( $val ) {
	return abs( (int) $val );
}

// ---------------------------------------------------------------------------
// current_time() — returns UTC datetime string.
// ---------------------------------------------------------------------------
function current_time( $type, $gmt = false ) {
	if ( 'mysql' === $type ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
	return time();
}

// ---------------------------------------------------------------------------
// Minimal $wpdb using mysqli directly.
// ---------------------------------------------------------------------------
class Konx_Test_WPDB {
	public $prefix       = 'wp_';
	public $last_error   = '';
	public $insert_id    = 0;
	public $rows_affected = 0; // Tracks affected_rows from last query()/update()/delete().
	// Standard WP table references.
	public $users        = 'wp_users';
	public $usermeta     = 'wp_usermeta';
	public $options      = 'wp_options';
	public $posts        = 'wp_posts';
	private $conn;

	public function __construct( $db, $user = 'root', $pass = '', $host = 'localhost' ) {
		$this->conn = new mysqli( $host, $user, $pass, $db );
		if ( $this->conn->connect_error ) {
			die( 'DB connection failed: ' . $this->conn->connect_error . PHP_EOL );
		}
		$this->conn->set_charset( 'utf8mb4' );
	}

	public function prepare( $query, ...$args ) {
		// Simplified sprintf-style preparation (safe for our tests).
		$i = 0;
		return preg_replace_callback( '/%([sdf])/', function ( $m ) use ( $args, &$i ) {
			$val = $args[ $i++ ] ?? null;
			if ( null === $val ) {
				return 'NULL';
			}
			switch ( $m[1] ) {
				case 'd': return (int) $val;
				case 'f': return (float) $val;
				case 's': return "'" . $this->conn->real_escape_string( (string) $val ) . "'";
			}
			return $val;
		}, $query );
	}

	public function get_row( $query, $output = OBJECT ) {
		$result = $this->conn->query( $query );
		$this->last_error = $this->conn->error;
		if ( ! $result ) return null;
		$row = $result->fetch_object();
		$result->free();
		return $row;
	}

	public function get_results( $query, $output = OBJECT ) {
		$result = $this->conn->query( $query );
		$this->last_error = $this->conn->error;
		if ( ! $result ) return array();
		$rows = array();
		while ( $row = $result->fetch_object() ) {
			$rows[] = $row;
		}
		$result->free();
		return $rows;
	}

	public function get_var( $query ) {
		$result = $this->conn->query( $query );
		$this->last_error = $this->conn->error;
		if ( ! $result ) return null;
		$row = $result->fetch_row();
		$result->free();
		return $row ? $row[0] : null;
	}

	public function get_col( $query ) {
		$result = $this->conn->query( $query );
		$this->last_error = $this->conn->error;
		if ( ! $result ) return array();
		$col = array();
		while ( $row = $result->fetch_row() ) {
			$col[] = $row[0];
		}
		$result->free();
		return $col;
	}

	public function insert( $table, $data, $format = null ) {
		$cols = implode( ', ', array_map( function( $c ) { return "`{$c}`"; }, array_keys( $data ) ) );
		$vals = array();
		foreach ( $data as $val ) {
			if ( null === $val ) {
				$vals[] = 'NULL';
			} elseif ( is_int( $val ) || is_float( $val ) ) {
				$vals[] = $val;
			} else {
				$vals[] = "'" . $this->conn->real_escape_string( (string) $val ) . "'";
			}
		}
		$sql = "INSERT INTO `{$table}` ({$cols}) VALUES (" . implode( ', ', $vals ) . ")";
		try {
			$ok = $this->conn->query( $sql );
		} catch ( mysqli_sql_exception $e ) {
			$this->last_error = $e->getMessage();
			$this->insert_id  = 0;
			return false;
		}
		$this->last_error = $this->conn->error;
		$this->insert_id  = $this->conn->insert_id;
		return $ok ? 1 : false;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$set = array();
		foreach ( $data as $col => $val ) {
			if ( null === $val ) {
				$set[] = "`{$col}` = NULL";
			} elseif ( is_int( $val ) || is_float( $val ) ) {
				$set[] = "`{$col}` = {$val}";
			} else {
				$set[] = "`{$col}` = '" . $this->conn->real_escape_string( (string) $val ) . "'";
			}
		}
		$where_parts = array();
		foreach ( $where as $col => $val ) {
			if ( null === $val ) {
				$where_parts[] = "`{$col}` IS NULL";
			} elseif ( is_int( $val ) || is_float( $val ) ) {
				$where_parts[] = "`{$col}` = {$val}";
			} else {
				$where_parts[] = "`{$col}` = '" . $this->conn->real_escape_string( (string) $val ) . "'";
			}
		}
		$sql = "UPDATE `{$table}` SET " . implode( ', ', $set ) . " WHERE " . implode( ' AND ', $where_parts );
		try {
			$ok = $this->conn->query( $sql );
		} catch ( mysqli_sql_exception $e ) {
			// Triggered by SIGNAL in triggers (e.g. test-installed freeze-blocker).
			$this->last_error = $e->getMessage();
			return false;
		}
		$this->last_error   = $this->conn->error;
		$this->rows_affected = $this->conn->affected_rows;
		return $ok ? $this->conn->affected_rows : false;
	}

	public function delete( $table, $where, $format = null ) {
		$where_parts = array();
		foreach ( $where as $col => $val ) {
			if ( is_int( $val ) || is_float( $val ) ) {
				$where_parts[] = "`{$col}` = {$val}";
			} else {
				$where_parts[] = "`{$col}` = '" . $this->conn->real_escape_string( (string) $val ) . "'";
			}
		}
		$sql = "DELETE FROM `{$table}` WHERE " . implode( ' AND ', $where_parts );
		$ok  = $this->conn->query( $sql );
		$this->last_error   = $this->conn->error;
		$this->rows_affected = $this->conn->affected_rows;
		return $ok ? $this->conn->affected_rows : false;
	}

	public function query( $sql ) {
		$ok = $this->conn->query( $sql );
		$this->last_error = $this->conn->error;
		// Track affected_rows for UPDATE/DELETE via query() — used by record executor
		// for the atomic ledger claim check.
		$this->rows_affected = $this->conn->affected_rows;
		return $ok;
	}

	/**
	 * Return the number of rows affected by the last query() or update() call.
	 *
	 * Required by Konx_Migration_Record_Executor::get_affected_rows() to detect
	 * whether the atomic ledger claim UPDATE affected exactly 1 row.
	 *
	 * @return int
	 */
	public function get_affected_rows() {
		return isset( $this->rows_affected ) ? (int) $this->rows_affected : 0;
	}
}

// Instantiate global $wpdb.
$wpdb = new Konx_Test_WPDB( 'konx.world', 'root', '', '127.0.0.1' );

// ---------------------------------------------------------------------------
// WordPress API stubs for Phase 24C-6D (record executor).
// These are no-op or minimal implementations that replace full WP functions
// for the test environment. Do NOT add duplicates of stubs already above.
// ---------------------------------------------------------------------------

/**
 * sanitize_textarea_field — passthrough strip_tags for test env.
 */
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

/**
 * is_email — basic email validation for test env.
 */
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}
}

/**
 * wp_generate_password — generate a random password in test env.
 */
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special = true, $extra = true ) {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		if ( $special ) { $chars .= '!@#$%^&*'; }
		if ( $extra )   { $chars .= '()-_[]{}<>~`+=,.;:/?|'; }
		$pw  = '';
		$max = strlen( $chars ) - 1;
		for ( $i = 0; $i < $length; $i++ ) {
			$pw .= $chars[ random_int( 0, $max ) ];
		}
		return $pw;
	}
}

/**
 * sanitize_user — strip invalid characters from username.
 */
if ( ! function_exists( 'sanitize_user' ) ) {
	function sanitize_user( $username, $strict = false ) {
		$username = preg_replace( '|%([a-fA-F0-9][a-fA-F0-9])|', '', (string) $username );
		$username = preg_replace( '/&.+?;/', '', $username );
		$username = str_replace( array( '<', '>' ), '', $username );
		if ( $strict ) {
			$username = preg_replace( '|[^a-z0-9 _.\-@]|i', '', $username );
		}
		$username = trim( $username );
		return $username;
	}
}

/**
 * email_exists — check if email is in wp_users.
 */
if ( ! function_exists( 'email_exists' ) ) {
	function email_exists( $email ) {
		global $wpdb;
		$uid = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE LOWER(user_email) = %s LIMIT 1",
				strtolower( trim( $email ) )
			)
		);
		return $uid ? (int) $uid : false;
	}
}

/**
 * username_exists — check if username is in wp_users.
 */
if ( ! function_exists( 'username_exists' ) ) {
	function username_exists( $username ) {
		global $wpdb;
		$uid = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE user_login = %s LIMIT 1",
				$username
			)
		);
		return $uid ? (int) $uid : false;
	}
}

/**
 * get_user_by — minimal implementation for test env.
 */
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) {
		global $wpdb;
		switch ( $field ) {
			case 'id':
			case 'ID':
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID = %d LIMIT 1", (int) $value ) );
				break;
			case 'email':
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE user_email = %s LIMIT 1", $value ) );
				break;
			case 'login':
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE user_login = %s LIMIT 1", $value ) );
				break;
			default:
				$row = null;
		}
		return $row ?: false;
	}
}

/**
 * wp_create_user — create a WP user by inserting into wp_users.
 */
if ( ! function_exists( 'wp_create_user' ) ) {
	function wp_create_user( $username, $password, $email ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// Check for duplicate.
		if ( username_exists( $username ) ) {
			return new WP_Error( 'existing_user_login', "Username {$username} already exists." );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'existing_user_email', "Email {$email} already exists." );
		}
		// Hash the password.
		$hashed = '$P$B' . md5( $password . time() ); // Simplified hash for tests.
		$ok = $wpdb->insert( $wpdb->users, array(
			'user_login'          => $username,
			'user_pass'           => $hashed,
			'user_email'          => $email,
			'user_registered'     => $now,
			'user_status'         => 0,
			'display_name'        => $username,
		) );
		if ( false === $ok ) {
			return new WP_Error( 'db_insert_failed', 'Failed to insert user: ' . $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}
}

/**
 * get_userdata — retrieve a WP user object by ID.
 */
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return get_user_by( 'id', $user_id );
	}
}

/**
 * update_user_meta — update/insert a user meta row.
 */
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $meta_key, $meta_value, $prev_value = '' ) {
		global $wpdb;
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
			$user_id, $meta_key
		) );
		if ( $existing ) {
			return $wpdb->update( $wpdb->usermeta, array( 'meta_value' => maybe_serialize( $meta_value ) ), array( 'user_id' => $user_id, 'meta_key' => $meta_key ) );
		}
		return $wpdb->insert( $wpdb->usermeta, array( 'user_id' => (int) $user_id, 'meta_key' => $meta_key, 'meta_value' => maybe_serialize( $meta_value ) ) );
	}
}

/**
 * maybe_serialize — return serialized form only for arrays/objects.
 */
if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $data ) {
		if ( is_array( $data ) || is_object( $data ) ) {
			return serialize( $data );
		}
		return $data;
	}
}

/**
 * add_filter / remove_filter / do_action / apply_filters — no-op stubs.
 * These are called by the executor for notification suppression.
 */
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		// No-op in test environment — notifications are not sent anyway.
		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $callback, $priority = 10 ) {
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		// No-op.
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_new_user_notification' ) ) {
	function wp_new_user_notification( $user_id, $deprecated = null, $notify = '' ) {
		// No-op in test environment.
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = PHP_INT_MAX ) {
		return random_int( $min, $max );
	}
}

// ---------------------------------------------------------------------------
// Load the Phase 24C-6B classes.
// ---------------------------------------------------------------------------
$includes = dirname( __DIR__ ) . '/includes/';
require_once $includes . 'class-konx-migration-plan-hasher.php';
require_once $includes . 'class-konx-migration-exec-session.php';
require_once $includes . 'class-konx-migration-execution-ledger.php';
require_once $includes . 'class-konx-migration-execution-plan.php';
require_once $includes . 'class-konx-migration-revalidator.php';

// Phase 24C-6D: Single-record executor.
require_once $includes . 'class-konx-migration-record-executor.php';

// ---------------------------------------------------------------------------
// Canonical session protection — NEVER delete this session.
// ---------------------------------------------------------------------------

/**
 * The UUID of the one real (non-test) migration snapshot.
 * safe_cleanup_test_session() refuses to touch this UUID even if accidentally
 * passed in.
 */
if ( ! defined( 'KONX_CANONICAL_SESSION_UUID' ) ) {
	define( 'KONX_CANONICAL_SESSION_UUID', '395e2b79-1e0a-49e8-9ea6-1ae146c9a54d' );
}

// ---------------------------------------------------------------------------
// UUID/session-ID tracking registry.
// ---------------------------------------------------------------------------

/**
 * Registry of sessions created by the current test process.
 * Keys are UUIDs (string), values are session IDs (int).
 *
 * @var array<string, int>
 */
$_konx_test_created_sessions = array();

/**
 * Record a session that was just created by a test.
 *
 * @param string $uuid       Session UUID.
 * @param int    $session_id DB primary-key ID.
 */
function register_test_session( $uuid, $session_id ) {
	global $_konx_test_created_sessions;
	if ( KONX_CANONICAL_SESSION_UUID === $uuid ) {
		return; // Never track the canonical session.
	}
	$_konx_test_created_sessions[ $uuid ] = (int) $session_id;
}

/**
 * Remove a session from the registry (after successful cleanup).
 *
 * @param string $uuid Session UUID.
 */
function deregister_test_session( $uuid ) {
	global $_konx_test_created_sessions;
	unset( $_konx_test_created_sessions[ $uuid ] );
}

// ---------------------------------------------------------------------------
// Safe cleanup helper (Step 5).
// ---------------------------------------------------------------------------

/**
 * Atomically delete a test session and all its associated plan/ledger rows.
 *
 * Guards:
 *  1. Refuses the canonical production UUID unconditionally.
 *  2. Verifies the supplied ID and UUID identify the same DB row before deleting.
 *  3. Wraps all three deletes in an InnoDB transaction; rolls back on any failure.
 *  4. Deregisters the UUID from the tracking registry on success.
 *
 * Returns true on success (or if the session no longer exists), false on
 * refusal / mismatch / DB error.
 *
 * @param string $uuid       Session UUID.
 * @param int    $session_id DB primary-key ID.
 * @return bool
 */
function safe_cleanup_test_session( $uuid, $session_id ) {
	global $wpdb;

	// Guard 1: never touch the canonical session.
	if ( KONX_CANONICAL_SESSION_UUID === $uuid ) {
		echo "[TEARDOWN] REFUSED: will not delete canonical session {$uuid}\n";
		return false;
	}

	$session_id = (int) $session_id;

	// Guard 2: verify UUID+ID identify the same row.
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, session_uuid FROM wp_konx_migration_exec_sessions WHERE id = %d AND session_uuid = %s LIMIT 1",
			$session_id,
			$uuid
		)
	);

	if ( null === $row ) {
		// Row already gone — treat as success (idempotent).
		deregister_test_session( $uuid );
		return true;
	}

	// Guard 3: atomic three-table delete.
	$wpdb->query( 'START TRANSACTION' );

	$plan_table    = $wpdb->prefix . 'konx_migration_execution_plan';
	$ledger_table  = $wpdb->prefix . 'konx_migration_execution_ledger';
	$session_table = $wpdb->prefix . 'konx_migration_exec_sessions';

	$del_ledger = $wpdb->delete( $ledger_table, array( 'session_id' => $session_id ), array( '%d' ) );
	$del_plan   = $wpdb->delete( $plan_table,   array( 'session_id' => $session_id ), array( '%d' ) );

	// Delete session row only when UUID still matches (double-check inside TX).
	$del_session = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM `{$session_table}` WHERE id = %d AND session_uuid = %s LIMIT 1",
			$session_id,
			$uuid
		)
	);

	if ( false === $del_ledger || false === $del_plan || false === $del_session || ! $del_session ) {
		$wpdb->query( 'ROLLBACK' );
		echo "[TEARDOWN] ROLLBACK: failed to delete session {$uuid} (id={$session_id}): {$wpdb->last_error}\n";
		return false;
	}

	$wpdb->query( 'COMMIT' );
	deregister_test_session( $uuid );
	return true;
}

/**
 * Tear down every session registered in the current test process.
 *
 * Called explicitly at the end of test files AND registered as a shutdown
 * handler so crashes during a test run still attempt cleanup.
 *
 * Sessions are cleaned in LIFO order (most recently created first) to
 * respect any potential FK constraints.
 *
 * Note: this does NOT fire on SIGKILL / SIGSEGV / OOM — those failures
 * require manual intervention.
 */
function teardown_all_test_sessions() {
	global $_konx_test_created_sessions;

	if ( empty( $_konx_test_created_sessions ) ) {
		return;
	}

	// LIFO: reverse so the most recently created session is cleaned first.
	$sessions = array_reverse( $_konx_test_created_sessions, true );

	foreach ( $sessions as $uuid => $session_id ) {
		safe_cleanup_test_session( $uuid, $session_id );
	}
}

// Register as shutdown handler for crash recovery.
// Does NOT fire on SIGKILL, SIGSEGV, or OOM kills.
register_shutdown_function( 'teardown_all_test_sessions' );

echo "[BOOTSTRAP] Integration test environment ready.\n";
echo "[BOOTSTRAP] DB: konx.world | Tables: exec_sessions, execution_plan, execution_ledger\n";
echo "[BOOTSTRAP] Test teardown registry active. Canonical session protected: " . KONX_CANONICAL_SESSION_UUID . "\n\n";
