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
	public $prefix  = 'wp_';
	public $last_error = '';
	public $insert_id  = 0;
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
		$ok  = $this->conn->query( $sql );
		$this->last_error = $this->conn->error;
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
		$this->last_error = $this->conn->error;
		return $ok ? $this->conn->affected_rows : false;
	}

	public function query( $sql ) {
		$ok = $this->conn->query( $sql );
		$this->last_error = $this->conn->error;
		return $ok;
	}
}

// Instantiate global $wpdb.
$wpdb = new Konx_Test_WPDB( 'konx.world', 'root', '', '127.0.0.1' );

// ---------------------------------------------------------------------------
// Load the Phase 24C-6B classes.
// ---------------------------------------------------------------------------
$includes = dirname( __DIR__ ) . '/includes/';
require_once $includes . 'class-konx-migration-plan-hasher.php';
require_once $includes . 'class-konx-migration-exec-session.php';
require_once $includes . 'class-konx-migration-execution-ledger.php';
require_once $includes . 'class-konx-migration-execution-plan.php';
require_once $includes . 'class-konx-migration-revalidator.php';

echo "[BOOTSTRAP] Integration test environment ready.\n";
echo "[BOOTSTRAP] DB: konx.world | Tables: exec_sessions, execution_plan, execution_ledger\n\n";
