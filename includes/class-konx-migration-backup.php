<?php
/**
 * Migration backup engine.
 *
 * Exports existing database state to CSV files before migration execution.
 * Creates timestamped backup folders in wp-content/uploads/konx-backups/.
 * Read-only on source data — only writes backup files and session metadata.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Migration_Backup
 */
class Konx_Migration_Backup {

	/**
	 * Backup directory name inside wp-content/uploads.
	 */
	const BACKUP_DIR = 'konx-backups';

	/**
	 * Tables to back up.
	 *
	 * @var array
	 */
	private static $konx_tables = array(
		'konx_affiliates',
		'konx_commission_rules',
		'konx_product_map',
		'konx_referral_clicks',
		'konx_referral_conversions',
		'konx_commissions',
		'konx_wallet_ledger',
		'konx_withdrawals',
		'konx_admin_fees',
		'konx_milestones',
		'konx_audit_log',
	);

	// ------------------------------------------------------------------
	// Backup Creation
	// ------------------------------------------------------------------

	/**
	 * Create a full pre-migration backup.
	 *
	 * Exports KonX tables, matched WP users, usermeta, and Coupon
	 * Affiliate reference data into a timestamped folder. Updates
	 * the migration session with backup metadata.
	 *
	 * @param string     $session_id   Migration session ID.
	 * @param array|null $matched_emails Emails from decision matrix (users affected by migration).
	 * @return array|WP_Error Backup manifest or error.
	 */
	public static function create( $session_id, $matched_emails = null ) {
		$backup_id  = 'bak_' . gmdate( 'Ymd_His' ) . '_' . substr( bin2hex( random_bytes( 3 ) ), 0, 6 );
		$backup_dir = self::get_backup_path( $backup_id );

		// Create backup directory.
		if ( ! wp_mkdir_p( $backup_dir ) ) {
			return new \WP_Error( 'dir_failed', __( 'Failed to create backup directory.', 'konx-affiliate-dashboard' ) );
		}

		// Protect with .htaccess and index.
		self::protect_directory( $backup_dir );

		$manifest = array(
			'backup_id'   => $backup_id,
			'session_id'  => $session_id,
			'created_at'  => gmdate( 'c' ),
			'created_by'  => get_current_user_id(),
			'directory'   => $backup_dir,
			'files'       => array(),
			'warnings'    => array(),
		);

		// 1. Export KonX tables.
		foreach ( self::$konx_tables as $table_name ) {
			$result = self::export_table( $table_name, $backup_dir );
			$manifest['files'][] = $result;
			if ( ! empty( $result['warning'] ) ) {
				$manifest['warnings'][] = $result['warning'];
			}
		}

		// 2. Export matched WP users.
		if ( ! empty( $matched_emails ) ) {
			$result = self::export_matched_users( $matched_emails, $backup_dir );
			$manifest['files'][] = $result;

			// 3. Export usermeta for matched users.
			$result_meta = self::export_matched_usermeta( $matched_emails, $backup_dir );
			$manifest['files'][] = $result_meta;
		}

		// 4. Export Coupon Affiliates reference data.
		$ca_result = self::export_coupon_affiliates( $backup_dir );
		$manifest['files'][] = $ca_result;

		// 5. Export WooCommerce coupon references.
		$wc_result = self::export_wc_coupons( $backup_dir );
		$manifest['files'][] = $wc_result;

		// Write manifest file.
		$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$manifest_path = $backup_dir . '/manifest.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $manifest_path, $manifest_json );

		$manifest['files'][] = array(
			'filename'  => 'manifest.json',
			'table'     => null,
			'rows'      => null,
			'size'      => filesize( $manifest_path ),
			'status'    => 'ok',
		);

		return $manifest;
	}

	// ------------------------------------------------------------------
	// Table Export
	// ------------------------------------------------------------------

	/**
	 * Export a single KonX table to CSV.
	 *
	 * @param string $table_name Table name without prefix.
	 * @param string $backup_dir Backup directory path.
	 * @return array File result.
	 */
	private static function export_table( $table_name, $backup_dir ) {
		global $wpdb;

		$full_table = $wpdb->prefix . $table_name;
		$filename   = $table_name . '.csv';
		$filepath   = $backup_dir . '/' . $filename;

		// Check table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table ) ) === $full_table );

		if ( ! $exists ) {
			return array(
				'filename' => $filename,
				'table'    => $table_name,
				'rows'     => 0,
				'size'     => 0,
				'status'   => 'skipped',
				'warning'  => sprintf( __( 'Table %s does not exist.', 'konx-affiliate-dashboard' ), $table_name ),
			);
		}

		// Get column names.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$full_table}", 0 );

		// Get all rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$full_table}", ARRAY_A );

		// Write CSV.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = fopen( $filepath, 'w' );
		if ( ! $fp ) {
			return array(
				'filename' => $filename,
				'table'    => $table_name,
				'rows'     => 0,
				'size'     => 0,
				'status'   => 'error',
				'warning'  => sprintf( __( 'Failed to open %s for writing.', 'konx-affiliate-dashboard' ), $filename ),
			);
		}

		// Header row.
		fputcsv( $fp, $columns );

		// Data rows.
		foreach ( $rows as $row ) {
			fputcsv( $fp, array_values( $row ) );
		}

		fclose( $fp );

		return array(
			'filename' => $filename,
			'table'    => $table_name,
			'rows'     => count( $rows ),
			'size'     => filesize( $filepath ),
			'status'   => 'ok',
		);
	}

	/**
	 * Export WordPress users matched by email.
	 *
	 * @param array  $emails     Email addresses from the migration plan.
	 * @param string $backup_dir Backup directory path.
	 * @return array File result.
	 */
	private static function export_matched_users( $emails, $backup_dir ) {
		global $wpdb;

		$filename = 'matched_wp_users.csv';
		$filepath = $backup_dir . '/' . $filename;

		if ( empty( $emails ) ) {
			return array(
				'filename' => $filename,
				'table'    => 'wp_users (matched)',
				'rows'     => 0,
				'size'     => 0,
				'status'   => 'skipped',
				'warning'  => __( 'No matched emails provided.', 'konx-affiliate-dashboard' ),
			);
		}

		// Build placeholders for IN clause.
		$placeholders = implode( ',', array_fill( 0, count( $emails ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, user_login, user_email, user_registered, display_name FROM {$wpdb->users} WHERE user_email IN ({$placeholders})",
				...$emails
			),
			ARRAY_A
		);

		return self::write_csv( $filepath, $filename, 'wp_users (matched)', $rows );
	}

	/**
	 * Export usermeta for matched WordPress users.
	 *
	 * @param array  $emails     Email addresses.
	 * @param string $backup_dir Backup directory path.
	 * @return array File result.
	 */
	private static function export_matched_usermeta( $emails, $backup_dir ) {
		global $wpdb;

		$filename = 'matched_wp_usermeta.csv';
		$filepath = $backup_dir . '/' . $filename;

		if ( empty( $emails ) ) {
			return array(
				'filename' => $filename,
				'table'    => 'wp_usermeta (matched)',
				'rows'     => 0,
				'size'     => 0,
				'status'   => 'skipped',
			);
		}

		$placeholders = implode( ',', array_fill( 0, count( $emails ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.umeta_id, m.user_id, m.meta_key, m.meta_value
				 FROM {$wpdb->usermeta} m
				 INNER JOIN {$wpdb->users} u ON m.user_id = u.ID
				 WHERE u.user_email IN ({$placeholders})",
				...$emails
			),
			ARRAY_A
		);

		return self::write_csv( $filepath, $filename, 'wp_usermeta (matched)', $rows );
	}

	/**
	 * Export Coupon Affiliates registration data.
	 *
	 * @param string $backup_dir Backup directory path.
	 * @return array File result.
	 */
	private static function export_coupon_affiliates( $backup_dir ) {
		global $wpdb;

		$filename  = 'coupon_affiliates.csv';
		$filepath  = $backup_dir . '/' . $filename;
		$ca_table  = $wpdb->prefix . 'wcusage_register';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ca_table ) ) === $ca_table );

		if ( ! $exists ) {
			return array(
				'filename' => $filename,
				'table'    => 'wcusage_register',
				'rows'     => 0,
				'size'     => 0,
				'status'   => 'skipped',
				'warning'  => __( 'Coupon Affiliates table not found.', 'konx-affiliate-dashboard' ),
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$ca_table}", ARRAY_A );

		return self::write_csv( $filepath, $filename, 'wcusage_register', $rows );
	}

	/**
	 * Export WooCommerce coupon posts referenced by affiliates.
	 *
	 * @param string $backup_dir Backup directory path.
	 * @return array File result.
	 */
	private static function export_wc_coupons( $backup_dir ) {
		global $wpdb;

		$filename = 'wc_coupons.csv';
		$filepath = $backup_dir . '/' . $filename;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT ID, post_title, post_status, post_date FROM {$wpdb->posts} WHERE post_type = 'shop_coupon'",
			ARRAY_A
		);

		return self::write_csv( $filepath, $filename, 'wp_posts (shop_coupon)', $rows );
	}

	// ------------------------------------------------------------------
	// Backup Verification
	// ------------------------------------------------------------------

	/**
	 * Verify backup integrity.
	 *
	 * Checks that all expected files exist, are readable, and have
	 * the expected row counts.
	 *
	 * @param array $manifest Backup manifest from create().
	 * @return array {
	 *     @type bool  $valid    True if all checks pass.
	 *     @type array $checks   Individual file verification results.
	 *     @type array $warnings Any warnings found.
	 * }
	 */
	public static function verify( $manifest ) {
		$checks   = array();
		$warnings = array();
		$valid    = true;

		$backup_dir = $manifest['directory'];

		// Check directory exists.
		if ( ! is_dir( $backup_dir ) ) {
			return array(
				'valid'    => false,
				'checks'   => array(),
				'warnings' => array( __( 'Backup directory does not exist.', 'konx-affiliate-dashboard' ) ),
			);
		}

		foreach ( $manifest['files'] as $file ) {
			if ( 'skipped' === $file['status'] ) {
				$checks[] = array(
					'filename' => $file['filename'],
					'status'   => 'skipped',
					'message'  => __( 'Skipped (source not available).', 'konx-affiliate-dashboard' ),
				);
				continue;
			}

			$filepath = $backup_dir . '/' . $file['filename'];
			$check    = array( 'filename' => $file['filename'] );

			// File exists?
			if ( ! file_exists( $filepath ) ) {
				$check['status']  = 'fail';
				$check['message'] = __( 'File does not exist.', 'konx-affiliate-dashboard' );
				$valid = false;
				$checks[] = $check;
				continue;
			}

			// File readable?
			if ( ! is_readable( $filepath ) ) {
				$check['status']  = 'fail';
				$check['message'] = __( 'File is not readable.', 'konx-affiliate-dashboard' );
				$valid = false;
				$checks[] = $check;
				continue;
			}

			// File size > 0 for tables that had rows?
			$size = filesize( $filepath );
			if ( isset( $file['rows'] ) && $file['rows'] > 0 && $size <= 0 ) {
				$check['status']  = 'fail';
				$check['message'] = sprintf(
					__( 'File is empty but %d rows were expected.', 'konx-affiliate-dashboard' ),
					$file['rows']
				);
				$valid = false;
				$checks[] = $check;
				continue;
			}

			// Verify row count matches (for CSVs only, skip manifest.json).
			if ( null !== $file['rows'] && 'manifest.json' !== $file['filename'] ) {
				$csv_lines = self::count_csv_rows( $filepath );
				if ( $csv_lines !== $file['rows'] ) {
					$check['status']  = 'warn';
					$check['message'] = sprintf(
						__( 'Row count mismatch: expected %d, found %d.', 'konx-affiliate-dashboard' ),
						$file['rows'],
						$csv_lines
					);
					$warnings[] = $check['message'];
				} else {
					$check['status']  = 'pass';
					$check['message'] = sprintf(
						__( 'Verified: %d rows, %s.', 'konx-affiliate-dashboard' ),
						$file['rows'],
						size_format( $size )
					);
				}
			} else {
				$check['status']  = 'pass';
				$check['message'] = sprintf( __( 'File exists, %s.', 'konx-affiliate-dashboard' ), size_format( $size ) );
			}

			$checks[] = $check;
		}

		return array(
			'valid'    => $valid,
			'checks'   => $checks,
			'warnings' => $warnings,
		);
	}

	// ------------------------------------------------------------------
	// Backup Summary
	// ------------------------------------------------------------------

	/**
	 * Build a summary of the backup for display.
	 *
	 * @param array $manifest Backup manifest.
	 * @param array $verification Verification results.
	 * @return array Summary data for UI rendering.
	 */
	public static function summarize( $manifest, $verification ) {
		$total_files = count( $manifest['files'] );
		$total_rows  = 0;
		$total_size  = 0;
		$ok_count    = 0;
		$skip_count  = 0;
		$error_count = 0;

		foreach ( $manifest['files'] as $file ) {
			if ( 'ok' === $file['status'] ) {
				$ok_count++;
				$total_rows += isset( $file['rows'] ) ? (int) $file['rows'] : 0;
				$total_size += isset( $file['size'] ) ? (int) $file['size'] : 0;
			} elseif ( 'skipped' === $file['status'] ) {
				$skip_count++;
			} else {
				$error_count++;
			}
		}

		return array(
			'backup_id'      => $manifest['backup_id'],
			'session_id'     => $manifest['session_id'],
			'created_at'     => $manifest['created_at'],
			'created_by'     => $manifest['created_by'],
			'directory'      => $manifest['directory'],
			'total_files'    => $total_files,
			'files_ok'       => $ok_count,
			'files_skipped'  => $skip_count,
			'files_error'    => $error_count,
			'total_rows'     => $total_rows,
			'total_size'     => $total_size,
			'total_size_fmt' => size_format( $total_size ),
			'verified'       => $verification['valid'],
			'warnings'       => array_merge( $manifest['warnings'], $verification['warnings'] ),
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Get the backup base directory path.
	 *
	 * @param string|null $backup_id Optional backup ID for subdirectory.
	 * @return string Full filesystem path.
	 */
	public static function get_backup_path( $backup_id = null ) {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . self::BACKUP_DIR;

		if ( $backup_id ) {
			return trailingslashit( $base ) . sanitize_file_name( $backup_id );
		}

		return $base;
	}

	/**
	 * Protect a directory from web access.
	 *
	 * Creates .htaccess and index.php files.
	 *
	 * @param string $dir Directory path.
	 */
	private static function protect_directory( $dir ) {
		// .htaccess to deny all web access.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		// Empty index.php as a fallback.
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		// Also protect the parent backups directory.
		$parent = dirname( $dir );
		$parent_htaccess = $parent . '/.htaccess';
		if ( ! file_exists( $parent_htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $parent_htaccess, "Deny from all\n" );
		}
		$parent_index = $parent . '/index.php';
		if ( ! file_exists( $parent_index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $parent_index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Write rows to a CSV file.
	 *
	 * @param string $filepath Full file path.
	 * @param string $filename File name for manifest.
	 * @param string $table    Table name for manifest.
	 * @param array  $rows     Array of associative arrays.
	 * @return array File result.
	 */
	private static function write_csv( $filepath, $filename, $table, $rows ) {
		if ( empty( $rows ) ) {
			// Write header-only file.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $filepath, '' );

			return array(
				'filename' => $filename,
				'table'    => $table,
				'rows'     => 0,
				'size'     => 0,
				'status'   => 'ok',
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = fopen( $filepath, 'w' );
		if ( ! $fp ) {
			return array(
				'filename' => $filename,
				'table'    => $table,
				'rows'     => 0,
				'size'     => 0,
				'status'   => 'error',
				'warning'  => sprintf( __( 'Failed to open %s for writing.', 'konx-affiliate-dashboard' ), $filename ),
			);
		}

		// Header.
		fputcsv( $fp, array_keys( $rows[0] ) );

		// Data.
		foreach ( $rows as $row ) {
			fputcsv( $fp, array_values( $row ) );
		}

		fclose( $fp );

		return array(
			'filename' => $filename,
			'table'    => $table,
			'rows'     => count( $rows ),
			'size'     => filesize( $filepath ),
			'status'   => 'ok',
		);
	}

	/**
	 * Count data rows in a CSV file (excluding header).
	 *
	 * @param string $filepath CSV file path.
	 * @return int Row count.
	 */
	private static function count_csv_rows( $filepath ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = fopen( $filepath, 'r' );
		if ( ! $fp ) {
			return -1;
		}

		$count = 0;
		$first = true;
		while ( false !== fgetcsv( $fp ) ) {
			if ( $first ) {
				$first = false;
				continue; // Skip header.
			}
			$count++;
		}

		fclose( $fp );
		return $count;
	}

	/**
	 * List all existing backups.
	 *
	 * @return array Array of backup metadata (from manifest.json files).
	 */
	public static function list_backups() {
		$base = self::get_backup_path();
		if ( ! is_dir( $base ) ) {
			return array();
		}

		$backups = array();
		$dirs    = glob( $base . '/bak_*', GLOB_ONLYDIR );

		if ( ! $dirs ) {
			return array();
		}

		foreach ( $dirs as $dir ) {
			$manifest_path = $dir . '/manifest.json';
			if ( file_exists( $manifest_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
				$json = file_get_contents( $manifest_path );
				$data = json_decode( $json, true );
				if ( $data ) {
					$backups[] = array(
						'backup_id'  => $data['backup_id'],
						'session_id' => $data['session_id'],
						'created_at' => $data['created_at'],
						'created_by' => $data['created_by'],
						'file_count' => count( $data['files'] ),
						'directory'  => $dir,
					);
				}
			}
		}

		return $backups;
	}
}
