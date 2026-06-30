<?php
/**
 * Migration engine for importing PowerOf10 users into KonX Affiliates.
 *
 * Provides read-only analysis, conflict detection, dry-run simulation,
 * and batch preparation. Does NOT write any data — actual execution
 * is handled by the future migration wizard.
 *
 * Supports two data sources:
 * - CSV file upload (recommended for production)
 * - Local PowerOf10 database (developer/staging only)
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Migration_Engine
 */
class Konx_Migration_Engine {

	/**
	 * Data source: 'csv' or 'database'.
	 *
	 * @var string
	 */
	private $source = 'database';

	/**
	 * Cached source records (array of objects).
	 *
	 * @var array|null
	 */
	private $records = null;

	/**
	 * PowerOf10 database name (for database source only).
	 *
	 * @var string
	 */
	private $po10_db;

	/**
	 * Type normalization map.
	 *
	 * @var array
	 */
	private static $type_map = array(
		'sales_agent'         => 'sales_agent',
		'salesagent'          => 'sales_agent',
		'sales agent'         => 'sales_agent',
		'team_agent'          => 'team_agent',
		'teamagent'           => 'team_agent',
		'team agent'          => 'team_agent',
		'marketing_agent'     => 'marketing_agent',
		'marketingagent'      => 'marketing_agent',
		'marketing agent'     => 'marketing_agent',
		'business'            => 'business',
		'business_affiliate'  => 'business',
		'business affiliate'  => 'business',
	);

	/**
	 * Valid KonX affiliate types.
	 *
	 * @var array
	 */
	private static $valid_types = array( 'business', 'team_agent', 'marketing_agent', 'sales_agent' );

	/**
	 * Required CSV columns.
	 *
	 * @var array
	 */
	private static $required_csv_columns = array( 'id', 'email', 'user_fname', 'user_lname', 'promotional_title', 'team_name' );

	/**
	 * All recognized CSV columns.
	 *
	 * @var array
	 */
	private static $all_csv_columns = array(
		'id', 'email', 'user_fname', 'user_lname', 'user_phone',
		'promotional_title', 'team_name', 'referrer_team_name',
		'source', 'country_code', 'created_at',
	);

	/**
	 * Default type for unmapped/empty values.
	 */
	const DEFAULT_TYPE = 'sales_agent';

	/**
	 * Max CSV file size in bytes (10 MB).
	 */
	const MAX_CSV_SIZE = 10485760;

	/**
	 * Constructor.
	 *
	 * @param string $po10_db PowerOf10 database name. Defaults to 'powerof10.biz'.
	 */
	public function __construct( $po10_db = 'powerof10.biz' ) {
		$this->po10_db = $po10_db;
	}

	// ------------------------------------------------------------------
	// Source Management
	// ------------------------------------------------------------------

	/**
	 * Get the current data source type.
	 *
	 * @return string 'csv' or 'database'.
	 */
	public function get_source() {
		return $this->source;
	}

	/**
	 * Load records from a CSV file.
	 *
	 * Parses the CSV, validates columns, and caches rows as objects
	 * matching the database row format so all analysis methods work
	 * identically regardless of source.
	 *
	 * @param string $file_path Absolute path to the CSV file.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function load_from_csv( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new \WP_Error( 'file_not_found', __( 'CSV file not found or not readable.', 'konx-affiliate-dashboard' ) );
		}

		$size = filesize( $file_path );
		if ( $size > self::MAX_CSV_SIZE ) {
			return new \WP_Error( 'file_too_large', sprintf( __( 'CSV file exceeds maximum size of %s MB.', 'konx-affiliate-dashboard' ), round( self::MAX_CSV_SIZE / 1048576 ) ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$handle = fopen( $file_path, 'r' );
		if ( false === $handle ) {
			return new \WP_Error( 'file_open_failed', __( 'Failed to open CSV file.', 'konx-affiliate-dashboard' ) );
		}

		// Read header row.
		$header = fgetcsv( $handle );
		if ( false === $header || empty( $header ) ) {
			fclose( $handle );
			return new \WP_Error( 'empty_csv', __( 'CSV file is empty or has no header row.', 'konx-affiliate-dashboard' ) );
		}

		// Normalize header — trim whitespace, lowercase.
		$header = array_map( function ( $h ) {
			return strtolower( trim( $h ) );
		}, $header );

		// Remove BOM if present.
		if ( isset( $header[0] ) ) {
			$header[0] = preg_replace( '/^\x{FEFF}/u', '', $header[0] );
		}

		// Validate required columns.
		$missing = array();
		foreach ( self::$required_csv_columns as $col ) {
			if ( ! in_array( $col, $header, true ) ) {
				$missing[] = $col;
			}
		}
		if ( ! empty( $missing ) ) {
			fclose( $handle );
			return new \WP_Error( 'missing_columns', sprintf( __( 'Missing required CSV columns: %s', 'konx-affiliate-dashboard' ), implode( ', ', $missing ) ) );
		}

		// Check for duplicate headers.
		$unique_headers = array_unique( $header );
		if ( count( $unique_headers ) !== count( $header ) ) {
			fclose( $handle );
			return new \WP_Error( 'duplicate_headers', __( 'CSV contains duplicate column headers.', 'konx-affiliate-dashboard' ) );
		}

		// Parse rows into objects matching database format.
		$records = array();
		$line    = 1;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$line++;
			if ( count( $row ) !== count( $header ) ) {
				continue; // Skip malformed rows.
			}

			$data = array_combine( $header, $row );

			$obj = (object) array(
				'id'                => isset( $data['id'] ) ? (int) $data['id'] : $line,
				'email'             => isset( $data['email'] ) ? trim( $data['email'] ) : '',
				'user_fname'        => isset( $data['user_fname'] ) ? trim( $data['user_fname'] ) : '',
				'user_lname'        => isset( $data['user_lname'] ) ? trim( $data['user_lname'] ) : '',
				'user_phone'        => isset( $data['user_phone'] ) ? trim( $data['user_phone'] ) : '',
				'promotional_title' => isset( $data['promotional_title'] ) ? trim( $data['promotional_title'] ) : '',
				'team_name'         => isset( $data['team_name'] ) ? trim( $data['team_name'] ) : '',
				'referrer_team_name' => isset( $data['referrer_team_name'] ) ? trim( $data['referrer_team_name'] ) : '',
				'source'            => isset( $data['source'] ) ? trim( $data['source'] ) : '',
				'country_code'      => isset( $data['country_code'] ) ? trim( $data['country_code'] ) : '',
				'created_at'        => isset( $data['created_at'] ) ? trim( $data['created_at'] ) : '',
			);

			$records[] = $obj;
		}

		fclose( $handle );

		if ( empty( $records ) ) {
			return new \WP_Error( 'no_data_rows', __( 'CSV file contains no data rows.', 'konx-affiliate-dashboard' ) );
		}

		$this->records = $records;
		$this->source  = 'csv';

		return true;
	}

	/**
	 * Get the required CSV columns.
	 *
	 * @return array
	 */
	public static function get_required_csv_columns() {
		return self::$required_csv_columns;
	}

	/**
	 * Get all recognized CSV columns.
	 *
	 * @return array
	 */
	public static function get_all_csv_columns() {
		return self::$all_csv_columns;
	}

	/**
	 * Validate a CSV file without fully loading it.
	 *
	 * Returns validation summary including column check and row count.
	 *
	 * @param string $file_path Absolute path to CSV file.
	 * @return array|WP_Error Validation results or error.
	 */
	public static function validate_csv( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new \WP_Error( 'file_not_found', __( 'CSV file not found.', 'konx-affiliate-dashboard' ) );
		}

		$size = filesize( $file_path );
		if ( $size > self::MAX_CSV_SIZE ) {
			return new \WP_Error( 'file_too_large', __( 'CSV file exceeds 10 MB limit.', 'konx-affiliate-dashboard' ) );
		}

		$handle = fopen( $file_path, 'r' );
		if ( false === $handle ) {
			return new \WP_Error( 'file_open_failed', __( 'Cannot open CSV file.', 'konx-affiliate-dashboard' ) );
		}

		$header = fgetcsv( $handle );
		if ( false === $header || empty( $header ) ) {
			fclose( $handle );
			return new \WP_Error( 'empty_csv', __( 'CSV is empty.', 'konx-affiliate-dashboard' ) );
		}

		$header = array_map( function ( $h ) { return strtolower( trim( $h ) ); }, $header );
		if ( isset( $header[0] ) ) {
			$header[0] = preg_replace( '/^\x{FEFF}/u', '', $header[0] );
		}

		$missing  = array_diff( self::$required_csv_columns, $header );
		$optional = array_intersect( array_diff( self::$all_csv_columns, self::$required_csv_columns ), $header );
		$extra    = array_diff( $header, self::$all_csv_columns );

		// Count rows.
		$row_count = 0;
		while ( fgetcsv( $handle ) !== false ) {
			$row_count++;
		}
		fclose( $handle );

		return array(
			'valid'            => empty( $missing ) && $row_count > 0,
			'file_size'        => $size,
			'columns_found'    => $header,
			'columns_missing'  => array_values( $missing ),
			'columns_optional' => array_values( $optional ),
			'columns_extra'    => array_values( $extra ),
			'row_count'        => $row_count,
		);
	}

	/**
	 * Get all source records — from CSV cache or database query.
	 *
	 * @return array Array of row objects.
	 */
	public function get_source_records() {
		if ( null !== $this->records ) {
			return $this->records;
		}

		if ( 'csv' === $this->source ) {
			return array(); // CSV not loaded yet.
		}

		// Database source: query all PO10 users.
		global $wpdb;
		$po10 = $this->po10_db;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->records = $wpdb->get_results(
			"SELECT id, user_fname, user_lname, email, user_phone,
			        promotional_title, team_name, referrer_team_name,
			        source, country_code, created_at
			 FROM `{$po10}`.users
			 ORDER BY id"
		);

		return $this->records;
	}

	// ------------------------------------------------------------------
	// 1. Scan Data Sources
	// ------------------------------------------------------------------

	/**
	 * Scan all data sources and return summary counts.
	 *
	 * Works with both CSV and database sources.
	 *
	 * @return array|WP_Error Scan results or error.
	 */
	public function scan_data_sources() {
		global $wpdb;

		$records = $this->get_source_records();
		if ( empty( $records ) && 'database' === $this->source ) {
			if ( ! $this->test_po10_connection() ) {
				return new \WP_Error( 'po10_unavailable', __( 'Cannot connect to PowerOf10 database.', 'konx-affiliate-dashboard' ) );
			}
			$records = $this->get_source_records();
		}

		$po10_users = count( $records );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wp_users        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		$konx_affiliates = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}konx_affiliates" );

		$ca_table = $wpdb->prefix . 'wcusage_register';
		$ca_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ca_table ) );
		$coupon_affiliates = $ca_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ca_table}" ) : 0;
		// phpcs:enable

		// Build sets for analysis.
		$emails       = array();
		$codes        = array();
		$code_counts  = array();
		$email_counts = array();
		$sponsors     = 0;
		$self_refs    = 0;
		$missing_types = 0;
		$matched      = 0;

		foreach ( $records as $r ) {
			$el = strtolower( trim( $r->email ) );
			$cl = strtolower( trim( $r->team_name ) );

			if ( ! empty( $el ) ) {
				$email_counts[ $el ] = ( $email_counts[ $el ] ?? 0 ) + 1;
			}
			if ( ! empty( $cl ) ) {
				$code_counts[ $cl ] = ( $code_counts[ $cl ] ?? 0 ) + 1;
				$codes[ $cl ] = true;
			}

			$rtn = strtolower( trim( $r->referrer_team_name ) );
			if ( ! empty( $rtn ) ) {
				$sponsors++;
				if ( $rtn === $cl ) {
					$self_refs++;
				}
			}

			if ( empty( trim( (string) $r->promotional_title ) ) ) {
				$missing_types++;
			}

			if ( ! empty( $el ) && get_user_by( 'email', $r->email ) ) {
				$matched++;
			}
		}

		$unique_codes     = count( $codes );
		$missing_wp       = $po10_users - $matched;
		$dup_emails       = count( array_filter( $email_counts, function ( $c ) { return $c > 1; } ) );
		$dup_codes        = count( array_filter( $code_counts, function ( $c ) { return $c > 1; } ) );

		// Sponsor resolution.
		$resolved = 0;
		$orphaned = 0;
		foreach ( $records as $r ) {
			$rtn = strtolower( trim( $r->referrer_team_name ) );
			$tn  = strtolower( trim( $r->team_name ) );
			if ( ! empty( $rtn ) && $rtn !== $tn ) {
				if ( isset( $codes[ $rtn ] ) ) {
					$resolved++;
				} else {
					$orphaned++;
				}
			}
		}

		return array(
			'source'             => $this->source,
			'po10_users'         => $po10_users,
			'wp_users'           => $wp_users,
			'konx_affiliates'    => $konx_affiliates,
			'coupon_affiliates'  => $coupon_affiliates,
			'po10_matched_to_wp' => $matched,
			'missing_wp_users'   => $missing_wp,
			'unique_codes'       => $unique_codes,
			'total_sponsors'     => $sponsors,
			'resolved_sponsors'  => $resolved,
			'missing_sponsors'   => $orphaned,
			'self_referrals'     => $self_refs,
			'missing_types'      => $missing_types,
			'duplicate_emails'   => $dup_emails,
			'duplicate_codes'    => $dup_codes,
		);
	}

	// ------------------------------------------------------------------
	// 2. Analyze Referral Codes
	// ------------------------------------------------------------------

	/**
	 * Analyze referral codes from source data.
	 *
	 * @return array Code analysis results.
	 */
	public function analyze_referral_codes() {
		global $wpdb;

		$records = $this->get_source_records();

		$code_map    = array(); // lowercase => [original variants]
		$longest     = 0;
		$over_12     = 0;
		$over_50     = 0;
		$empty_codes = 0;

		foreach ( $records as $r ) {
			$tn = trim( $r->team_name );
			if ( '' === $tn ) {
				$empty_codes++;
				continue;
			}
			$len = strlen( $tn );
			if ( $len > $longest ) { $longest = $len; }
			if ( $len > 12 ) { $over_12++; }
			if ( $len > 50 ) { $over_50++; }

			$lower = strtolower( $tn );
			$code_map[ $lower ][] = $tn;
		}

		$total = count( $code_map );

		$ci_dupes = array();
		foreach ( $code_map as $lower => $variants ) {
			if ( count( $variants ) > 1 ) {
				$ci_dupes[] = (object) array(
					'code' => $lower,
					'cnt'  => count( $variants ),
				);
			}
		}

		// Check conflicts with existing KonX codes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$konx_codes_raw = $wpdb->get_col( "SELECT LOWER(referral_code) FROM {$wpdb->prefix}konx_affiliates" );
		$konx_set = array_flip( $konx_codes_raw );

		$konx_conflicts = 0;
		foreach ( $code_map as $lower => $variants ) {
			if ( isset( $konx_set[ $lower ] ) ) {
				$konx_conflicts++;
			}
		}

		return array(
			'total_unique'      => $total,
			'longest_code'      => $longest,
			'over_12_chars'     => $over_12,
			'over_50_chars'     => $over_50,
			'empty_codes'       => $empty_codes,
			'ci_duplicates'     => $ci_dupes,
			'konx_conflicts'    => $konx_conflicts,
		);
	}

	// ------------------------------------------------------------------
	// 3. Analyze Affiliate Types
	// ------------------------------------------------------------------

	/**
	 * Analyze affiliate types from source data with normalization.
	 *
	 * @return array Type analysis.
	 */
	public function analyze_affiliate_types() {
		$records = $this->get_source_records();

		$counts = array();
		foreach ( $records as $r ) {
			$val = trim( (string) $r->promotional_title );
			$counts[ $val ] = ( $counts[ $val ] ?? 0 ) + 1;
		}

		arsort( $counts );
		$types    = array();
		$unmapped = array();

		foreach ( $counts as $source => $cnt ) {
			$normalized = self::normalize_type( $source );
			$status     = 'auto';

			if ( '' === $source ) {
				$status = 'defaulted';
			} elseif ( $normalized !== strtolower( trim( $source ) ) || ! in_array( $normalized, self::$valid_types, true ) ) {
				$status = in_array( $normalized, self::$valid_types, true ) ? 'normalized' : 'unmapped';
				if ( 'unmapped' === $status ) { $unmapped[] = $source; }
			}

			$types[] = array(
				'source_value' => '' === $source ? '(empty/null)' : $source,
				'normalized'   => $normalized,
				'count'        => $cnt,
				'status'       => $status,
			);
		}

		return array(
			'types'    => $types,
			'unmapped' => $unmapped,
			'total'    => array_sum( array_column( $types, 'count' ) ),
		);
	}

	// ------------------------------------------------------------------
	// 4. Analyze Sponsors
	// ------------------------------------------------------------------

	/**
	 * Analyze sponsor/upline relationships from source data.
	 *
	 * @return array Sponsor analysis.
	 */
	public function analyze_sponsors() {
		$records = $this->get_source_records();

		$team_set    = array(); // lowercase team_name => first record
		$sponsor_cnt = array();
		$total       = 0;
		$no_sponsor  = 0;
		$self_refs   = 0;

		foreach ( $records as $r ) {
			$tn = strtolower( trim( $r->team_name ) );
			if ( ! empty( $tn ) && ! isset( $team_set[ $tn ] ) ) {
				$team_set[ $tn ] = $r;
			}

			$rtn = strtolower( trim( $r->referrer_team_name ) );
			if ( empty( $rtn ) ) {
				$no_sponsor++;
			} else {
				$total++;
				if ( $rtn === $tn ) {
					$self_refs++;
				} else {
					$sponsor_cnt[ $rtn ] = ( $sponsor_cnt[ $rtn ] ?? 0 ) + 1;
				}
			}
		}

		// Orphaned sponsors.
		$orphaned_details = array();
		$orphaned_total   = 0;
		foreach ( $sponsor_cnt as $stn => $cnt ) {
			if ( ! isset( $team_set[ $stn ] ) ) {
				$orphaned_details[] = (object) array( 'sponsor_name' => $stn, 'affected_users' => $cnt );
				$orphaned_total += $cnt;
			}
		}
		usort( $orphaned_details, function ( $a, $b ) { return $b->affected_users - $a->affected_users; } );
		$orphaned_details = array_slice( $orphaned_details, 0, 20 );

		// Largest teams.
		arsort( $sponsor_cnt );
		$largest_teams = array();
		$i = 0;
		foreach ( $sponsor_cnt as $stn => $cnt ) {
			if ( $i >= 10 ) { break; }
			$largest_teams[] = (object) array( 'sponsor' => $stn, 'team_size' => $cnt );
			$i++;
		}

		// Sample tree.
		$sample_tree = array();
		if ( ! empty( $largest_teams ) ) {
			$root_tn   = $largest_teams[0]->sponsor;
			$root_user = isset( $team_set[ $root_tn ] ) ? $team_set[ $root_tn ] : null;

			if ( $root_user ) {
				$children = array();
				$c = 0;
				foreach ( $records as $r ) {
					if ( $c >= 5 ) { break; }
					$rtn = strtolower( trim( $r->referrer_team_name ) );
					$tn  = strtolower( trim( $r->team_name ) );
					if ( $rtn === $root_tn && $tn !== $root_tn ) {
						$children[] = array(
							'team_name' => $r->team_name,
							'name'      => trim( $r->user_fname . ' ' . $r->user_lname ),
							'type'      => $r->promotional_title,
						);
						$c++;
					}
				}

				$sample_tree = array(
					'root'     => array(
						'team_name' => $root_user->team_name,
						'name'      => trim( $root_user->user_fname . ' ' . $root_user->user_lname ),
						'type'      => $root_user->promotional_title,
					),
					'children' => $children,
				);
			}
		}

		return array(
			'total_with_sponsor' => $total,
			'no_sponsor'         => $no_sponsor,
			'self_referrals'     => $self_refs,
			'resolved'           => $total - $orphaned_total - $self_refs,
			'orphaned_total'     => $orphaned_total,
			'orphaned_details'   => $orphaned_details,
			'largest_teams'      => $largest_teams,
			'sample_tree'        => $sample_tree,
		);
	}

	// ------------------------------------------------------------------
	// 5. Detect Conflicts
	// ------------------------------------------------------------------

	/**
	 * Detect all data conflicts from source data.
	 *
	 * @return array Conflict details by category.
	 */
	public function detect_conflicts() {
		global $wpdb;

		$records = $this->get_source_records();

		// 1. Duplicate emails.
		$email_map = array();
		foreach ( $records as $r ) {
			$el = strtolower( trim( $r->email ) );
			if ( ! empty( $el ) ) { $email_map[ $el ] = ( $email_map[ $el ] ?? 0 ) + 1; }
		}
		$dup_emails = array();
		foreach ( $email_map as $em => $cnt ) {
			if ( $cnt > 1 ) { $dup_emails[] = (object) array( 'email' => $em, 'cnt' => $cnt ); }
		}

		// 2. Duplicate referral codes (case-insensitive).
		$code_map = array();
		foreach ( $records as $r ) {
			$tn = trim( $r->team_name );
			if ( '' !== $tn ) {
				$lower = strtolower( $tn );
				$code_map[ $lower ]['variants'][] = $tn;
			}
		}
		$dup_codes = array();
		foreach ( $code_map as $lower => $data ) {
			if ( count( $data['variants'] ) > 1 ) {
				$dup_codes[] = (object) array(
					'code'     => $lower,
					'variants' => implode( ', ', array_unique( $data['variants'] ) ),
					'cnt'      => count( $data['variants'] ),
				);
			}
		}

		// 3. Invalid emails.
		$bad_emails = 0;
		foreach ( $records as $r ) {
			$em = trim( $r->email );
			if ( empty( $em ) || false === strpos( $em, '@' ) ) { $bad_emails++; }
		}

		// 4. KonX code conflicts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$konx_codes_raw = $wpdb->get_results( "SELECT id, referral_code, affiliate_type FROM {$wpdb->prefix}konx_affiliates" );
		$konx_code_conflicts = array();
		$konx_set = array();
		foreach ( $konx_codes_raw as $kc ) { $konx_set[ strtolower( $kc->referral_code ) ] = $kc; }

		foreach ( $code_map as $lower => $data ) {
			if ( isset( $konx_set[ $lower ] ) ) {
				$kc = $konx_set[ $lower ];
				$konx_code_conflicts[] = (object) array(
					'po10_code'          => $data['variants'][0],
					'konx_code'          => $kc->referral_code,
					'konx_affiliate_id'  => $kc->id,
				);
			}
		}

		// 5. Existing affiliates.
		$existing_affiliates = array();
		foreach ( $records as $r ) {
			$em = strtolower( trim( $r->email ) );
			if ( empty( $em ) ) { continue; }
			$wp_user = get_user_by( 'email', $r->email );
			if ( $wp_user ) {
				$aff = Konx_Affiliate_Manager::get_affiliate_by_user( $wp_user->ID );
				if ( $aff ) {
					$existing_affiliates[] = (object) array(
						'po10_id'        => $r->id,
						'email'          => $r->email,
						'po10_code'      => $r->team_name,
						'konx_id'        => $aff->id,
						'konx_code'      => $aff->referral_code,
						'affiliate_type' => $aff->affiliate_type,
					);
				}
			}
		}

		// 6. Self-referrals.
		$self_refs = array();
		foreach ( $records as $r ) {
			$tn  = strtolower( trim( $r->team_name ) );
			$rtn = strtolower( trim( $r->referrer_team_name ) );
			if ( ! empty( $rtn ) && $rtn === $tn ) {
				$self_refs[] = (object) array(
					'id'                 => $r->id,
					'email'              => $r->email,
					'team_name'          => $r->team_name,
					'referrer_team_name' => $r->referrer_team_name,
				);
			}
		}

		$critical = count( $dup_codes ) + count( $konx_code_conflicts );
		$warnings = $bad_emails + count( $self_refs );

		return array(
			'duplicate_emails'    => $dup_emails,
			'duplicate_codes'     => $dup_codes,
			'invalid_emails'      => $bad_emails,
			'konx_code_conflicts' => $konx_code_conflicts,
			'existing_affiliates' => $existing_affiliates,
			'self_referrals'      => $self_refs,
			'critical_count'      => $critical,
			'warning_count'       => $warnings,
		);
	}

	// ------------------------------------------------------------------
	// 6. Build Migration Preview
	// ------------------------------------------------------------------

	/**
	 * Build a preview of the first N migration records.
	 *
	 * @param int $limit Number of records to preview.
	 * @return array Array of preview records.
	 */
	public function build_migration_preview( $limit = 50 ) {
		$records = $this->get_source_records();
		$preview = array();
		$count   = 0;

		foreach ( $records as $row ) {
			if ( $count >= $limit ) { break; }
			$preview[] = $this->build_record( $row );
			$count++;
		}

		return $preview;
	}

	// ------------------------------------------------------------------
	// 7. Dry Run
	// ------------------------------------------------------------------

	/**
	 * Run a full dry-run simulation — no database writes.
	 *
	 * @return array Dry-run summary.
	 */
	public function dry_run() {
		global $wpdb;

		$records = $this->get_source_records();

		$will_create_user      = 0;
		$will_create_affiliate = 0;
		$will_skip             = 0;
		$will_link_sponsor     = 0;
		$orphan_sponsors       = 0;
		$type_normalized       = 0;
		$type_defaulted        = 0;
		$errors                = array();
		$warnings              = array();
		$by_type               = array();
		$seen_codes            = array();
		$seen_emails           = array();

		// Build a set of all source team_names for sponsor resolution.
		$all_team_names = array();
		foreach ( $records as $r ) {
			if ( ! empty( $r->team_name ) ) {
				$all_team_names[ strtolower( $r->team_name ) ] = true;
			}
		}

		// Build set of existing KonX referral codes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$konx_codes_raw = $wpdb->get_col( "SELECT LOWER(referral_code) FROM {$wpdb->prefix}konx_affiliates" );
		$konx_codes = array_flip( $konx_codes_raw );

		foreach ( $records as $row ) {
			$record = $this->build_record( $row );

			if ( 'normalized' === $record['type_status'] ) { $type_normalized++; }
			if ( 'defaulted' === $record['type_status'] ) { $type_defaulted++; }

			if ( ! empty( $record['errors'] ) ) {
				$will_skip++;
				$errors[] = array( 'po10_id' => $row->id, 'email' => $row->email, 'errors' => $record['errors'] );
				continue;
			}

			$email_lower = strtolower( $record['email'] );
			if ( isset( $seen_emails[ $email_lower ] ) ) {
				$will_skip++;
				$errors[] = array( 'po10_id' => $row->id, 'email' => $row->email, 'errors' => array( 'duplicate_email_in_batch' ) );
				continue;
			}
			$seen_emails[ $email_lower ] = true;

			$code_lower = strtolower( $record['referral_code'] );
			if ( ! empty( $code_lower ) && isset( $seen_codes[ $code_lower ] ) ) {
				$will_skip++;
				$errors[] = array( 'po10_id' => $row->id, 'email' => $row->email, 'errors' => array( 'duplicate_code_in_batch' ) );
				continue;
			}
			if ( ! empty( $code_lower ) ) { $seen_codes[ $code_lower ] = true; }

			if ( ! empty( $code_lower ) && isset( $konx_codes[ $code_lower ] ) ) {
				$will_skip++;
				$errors[] = array( 'po10_id' => $row->id, 'email' => $row->email, 'errors' => array( 'code_conflicts_with_konx' ) );
				continue;
			}

			$wp_user = get_user_by( 'email', $record['email'] );
			if ( ! $wp_user ) {
				$will_create_user++;
			} else {
				$existing_aff = Konx_Affiliate_Manager::get_affiliate_by_user( $wp_user->ID );
				if ( $existing_aff ) {
					$will_skip++;
					$warnings[] = array( 'po10_id' => $row->id, 'email' => $row->email, 'warning' => 'existing_affiliate' );
					continue;
				}
			}

			$will_create_affiliate++;

			$t = $record['affiliate_type'];
			$by_type[ $t ] = ( $by_type[ $t ] ?? 0 ) + 1;

			if ( ! empty( $record['parent_referral_code'] ) ) {
				$parent_lower = strtolower( $record['parent_referral_code'] );
				if ( $parent_lower === $code_lower ) {
					$warnings[] = array( 'po10_id' => $row->id, 'email' => $row->email, 'warning' => 'self_referral' );
				} elseif ( isset( $all_team_names[ $parent_lower ] ) || isset( $konx_codes[ $parent_lower ] ) ) {
					$will_link_sponsor++;
				} else {
					$orphan_sponsors++;
					$warnings[] = array( 'po10_id' => $row->id, 'email' => $row->email, 'warning' => 'orphan_sponsor' );
				}
			}
		}

		$total       = $will_create_affiliate + $will_skip;
		$batch_size  = 50;
		$est_batches = (int) ceil( $will_create_affiliate / $batch_size );

		return array(
			'source'                 => $this->source,
			'total_records'          => $total,
			'will_create_users'      => $will_create_user,
			'will_create_affiliates' => $will_create_affiliate,
			'will_skip'              => $will_skip,
			'will_link_sponsors'     => $will_link_sponsor,
			'orphan_sponsors'        => $orphan_sponsors,
			'type_normalized'        => $type_normalized,
			'type_defaulted'         => $type_defaulted,
			'by_type'                => $by_type,
			'errors'                 => $errors,
			'warnings'               => $warnings,
			'estimated_batches'      => $est_batches,
			'batch_size'             => $batch_size,
		);
	}

	// ------------------------------------------------------------------
	// 8. Prepare Batch
	// ------------------------------------------------------------------

	/**
	 * Prepare a batch of records for future execution.
	 *
	 * @param int $offset Starting offset.
	 * @param int $limit  Batch size.
	 * @return array Array of prepared records.
	 */
	public function prepare_batch( $offset = 0, $limit = 50 ) {
		$records = $this->get_source_records();
		$slice   = array_slice( $records, $offset, $limit );
		$batch   = array();

		foreach ( $slice as $row ) {
			$batch[] = $this->build_record( $row );
		}

		return $batch;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Build a migration record from a source row.
	 *
	 * @param object $row Source row (DB or CSV-parsed).
	 * @return array Migration record.
	 */
	private function build_record( $row ) {
		$errors   = array();
		$warnings = array();

		$email = sanitize_email( $row->email );
		if ( empty( $email ) || ! is_email( $email ) ) {
			$errors[] = 'invalid_email';
		}

		$code = sanitize_text_field( $row->team_name );
		if ( empty( $code ) ) {
			$errors[] = 'empty_referral_code';
		} elseif ( strlen( $code ) > 50 ) {
			$errors[] = 'referral_code_too_long';
		}

		$source_type = '' === (string) $row->promotional_title ? '' : $row->promotional_title;
		$normalized  = self::normalize_type( $source_type );
		$type_status = 'auto';

		if ( '' === $source_type || null === $row->promotional_title ) {
			$type_status = 'defaulted';
		} elseif ( strtolower( trim( $source_type ) ) !== $normalized ) {
			$type_status = 'normalized';
		}

		$action = empty( $errors ) ? 'create' : 'skip';

		return array(
			'po10_id'              => (int) $row->id,
			'external_id'          => 'po10_' . (int) $row->id,
			'email'                => $email,
			'first_name'           => sanitize_text_field( $row->user_fname ),
			'last_name'            => sanitize_text_field( $row->user_lname ),
			'phone'                => sanitize_text_field( $row->user_phone ),
			'source_type'          => $source_type,
			'affiliate_type'       => $normalized,
			'type_status'          => $type_status,
			'referral_code'        => $code,
			'parent_referral_code' => sanitize_text_field( $row->referrer_team_name ),
			'source'               => sanitize_text_field( $row->source ),
			'country'              => sanitize_text_field( $row->country_code ),
			'registered_at'        => $row->created_at,
			'action'               => $action,
			'warnings'             => $warnings,
			'errors'               => $errors,
		);
	}

	/**
	 * Normalize a PowerOf10 affiliate type to a KonX type.
	 *
	 * @param string $type The raw type value.
	 * @return string The normalized KonX affiliate type.
	 */
	public static function normalize_type( $type ) {
		if ( null === $type || '' === $type ) {
			return self::DEFAULT_TYPE;
		}

		$lower = strtolower( trim( $type ) );

		if ( isset( self::$type_map[ $lower ] ) ) {
			return self::$type_map[ $lower ];
		}

		if ( in_array( $lower, self::$valid_types, true ) ) {
			return $lower;
		}

		return self::DEFAULT_TYPE;
	}

	/**
	 * Test connectivity to the PowerOf10 database.
	 *
	 * @return bool True if accessible.
	 */
	public function test_po10_connection() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var( "SELECT 1 FROM `{$this->po10_db}`.users LIMIT 1" );
		return null !== $result;
	}

	/**
	 * Save state to wp_options.
	 *
	 * @param string $key   State key.
	 * @param mixed  $value Data to store.
	 */
	public function save_state( $key, $value ) {
		$state = get_option( 'konx_migration_state', array() );
		$state[ $key ] = $value;
		$state['updated_at'] = current_time( 'mysql', true );
		update_option( 'konx_migration_state', $state, false );
	}

	/**
	 * Get state from wp_options.
	 *
	 * @param string $key State key.
	 * @return mixed|null The stored value, or null.
	 */
	public function get_state( $key ) {
		$state = get_option( 'konx_migration_state', array() );
		return isset( $state[ $key ] ) ? $state[ $key ] : null;
	}
}
