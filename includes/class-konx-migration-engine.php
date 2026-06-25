<?php
/**
 * Migration engine for importing PowerOf10 users into KonX Affiliates.
 *
 * Provides read-only analysis, conflict detection, dry-run simulation,
 * and batch preparation. Does NOT write any data — actual execution
 * is handled by the future migration wizard.
 *
 * Data source: powerof10.biz MySQL database (cross-database query).
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
	 * PowerOf10 database name.
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
	 * Default type for unmapped/empty values.
	 */
	const DEFAULT_TYPE = 'sales_agent';

	/**
	 * Constructor.
	 *
	 * @param string $po10_db PowerOf10 database name. Defaults to 'powerof10.biz'.
	 */
	public function __construct( $po10_db = 'powerof10.biz' ) {
		$this->po10_db = $po10_db;
	}

	// ------------------------------------------------------------------
	// 1. Scan Data Sources
	// ------------------------------------------------------------------

	/**
	 * Scan all data sources and return summary counts.
	 *
	 * @return array|WP_Error Scan results or error if PO10 unavailable.
	 */
	public function scan_data_sources() {
		global $wpdb;

		if ( ! $this->test_po10_connection() ) {
			return new \WP_Error( 'po10_unavailable', __( 'Cannot connect to PowerOf10 database.', 'konx-affiliate-dashboard' ) );
		}

		$po10 = $this->po10_db;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$po10_users     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$po10}`.users" );
		$wp_users       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		$konx_affiliates = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}konx_affiliates" );

		// Coupon Affiliates data.
		$ca_table = $wpdb->prefix . 'wcusage_register';
		$ca_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ca_table ) );
		$coupon_affiliates = $ca_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ca_table}" ) : 0;

		// PO10 users matched to WP by email.
		$matched = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$po10}`.users po10
			 JOIN {$wpdb->users} wp ON LOWER(po10.email) = LOWER(wp.user_email)"
		);

		$missing_wp = $po10_users - $matched;

		// Unique referral codes in PO10.
		$unique_codes = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT team_name) FROM `{$po10}`.users WHERE team_name IS NOT NULL AND team_name != ''" );

		// Sponsor references.
		$total_sponsors = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$po10}`.users WHERE referrer_team_name IS NOT NULL AND referrer_team_name != ''" );

		$resolved_sponsors = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$po10}`.users u
			 JOIN `{$po10}`.users p ON LOWER(p.team_name) = LOWER(u.referrer_team_name)
			 WHERE u.referrer_team_name IS NOT NULL AND u.referrer_team_name != ''
			 AND LOWER(u.referrer_team_name) != LOWER(u.team_name)"
		);

		$missing_sponsors = $total_sponsors - $resolved_sponsors;

		// Self-referrals.
		$self_referrals = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$po10}`.users
			 WHERE referrer_team_name IS NOT NULL AND referrer_team_name != ''
			 AND LOWER(team_name) = LOWER(referrer_team_name)"
		);
		$missing_sponsors -= $self_referrals;

		// Missing affiliate types.
		$missing_types = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$po10}`.users WHERE promotional_title IS NULL OR promotional_title = ''"
		);

		// Duplicate emails in PO10.
		$dup_emails = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT LOWER(email) AS em FROM `{$po10}`.users
				GROUP BY em HAVING COUNT(*) > 1
			) t"
		);

		// Case-insensitive duplicate referral codes in PO10.
		$dup_codes = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT LOWER(team_name) AS tn FROM `{$po10}`.users
				WHERE team_name IS NOT NULL AND team_name != ''
				GROUP BY tn HAVING COUNT(*) > 1
			) t"
		);

		// phpcs:enable

		return array(
			'po10_users'         => $po10_users,
			'wp_users'           => $wp_users,
			'konx_affiliates'    => $konx_affiliates,
			'coupon_affiliates'  => $coupon_affiliates,
			'po10_matched_to_wp' => $matched,
			'missing_wp_users'   => $missing_wp,
			'unique_codes'       => $unique_codes,
			'total_sponsors'     => $total_sponsors,
			'resolved_sponsors'  => $resolved_sponsors,
			'missing_sponsors'   => $missing_sponsors,
			'self_referrals'     => $self_referrals,
			'missing_types'      => $missing_types,
			'duplicate_emails'   => $dup_emails,
			'duplicate_codes'    => $dup_codes,
		);
	}

	// ------------------------------------------------------------------
	// 2. Analyze Referral Codes
	// ------------------------------------------------------------------

	/**
	 * Analyze referral codes from PowerOf10 data.
	 *
	 * @return array Code analysis results.
	 */
	public function analyze_referral_codes() {
		global $wpdb;

		$po10 = $this->po10_db;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT team_name) FROM `{$po10}`.users WHERE team_name IS NOT NULL AND team_name != ''" );
		$longest = (int) $wpdb->get_var( "SELECT MAX(LENGTH(team_name)) FROM `{$po10}`.users" );
		$over_12 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$po10}`.users WHERE LENGTH(team_name) > 12" );
		$over_50 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$po10}`.users WHERE LENGTH(team_name) > 50" );
		$empty_codes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$po10}`.users WHERE team_name IS NULL OR team_name = ''" );

		// Case-insensitive duplicates.
		$ci_dupes = $wpdb->get_results(
			"SELECT LOWER(team_name) AS code, COUNT(*) AS cnt
			 FROM `{$po10}`.users
			 WHERE team_name IS NOT NULL AND team_name != ''
			 GROUP BY code HAVING cnt > 1
			 ORDER BY cnt DESC LIMIT 20"
		);

		// Conflicts with existing KonX referral codes.
		$konx_conflicts = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$po10}`.users po10
			 JOIN {$wpdb->prefix}konx_affiliates ka ON LOWER(po10.team_name) = LOWER(ka.referral_code)
			 WHERE po10.team_name IS NOT NULL AND po10.team_name != ''"
		);

		// phpcs:enable

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
	 * Analyze affiliate types from PowerOf10 data with normalization.
	 *
	 * @return array Type analysis with source values, counts, and mappings.
	 */
	public function analyze_affiliate_types() {
		global $wpdb;

		$po10 = $this->po10_db;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT COALESCE(promotional_title, '') AS source_value, COUNT(*) AS cnt
			 FROM `{$po10}`.users
			 GROUP BY source_value
			 ORDER BY cnt DESC"
		);
		// phpcs:enable

		$types = array();
		$unmapped = array();

		foreach ( $rows as $row ) {
			$source     = $row->source_value;
			$normalized = self::normalize_type( $source );
			$status     = 'auto';

			if ( '' === $source ) {
				$status = 'defaulted';
			} elseif ( $normalized !== strtolower( trim( $source ) ) || ! in_array( $normalized, self::$valid_types, true ) ) {
				if ( in_array( $normalized, self::$valid_types, true ) ) {
					$status = 'normalized';
				} else {
					$status = 'unmapped';
					$unmapped[] = $source;
				}
			}

			$types[] = array(
				'source_value'   => '' === $source ? '(empty/null)' : $source,
				'normalized'     => $normalized,
				'count'          => (int) $row->cnt,
				'status'         => $status,
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
	 * Analyze sponsor/upline relationships from PowerOf10 data.
	 *
	 * @return array Sponsor analysis.
	 */
	public function analyze_sponsors() {
		global $wpdb;

		$po10 = $this->po10_db;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$po10}`.users WHERE referrer_team_name IS NOT NULL AND referrer_team_name != ''"
		);
		$no_sponsor = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$po10}`.users WHERE referrer_team_name IS NULL OR referrer_team_name = ''"
		);
		$self_refs = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$po10}`.users
			 WHERE referrer_team_name IS NOT NULL AND referrer_team_name != ''
			 AND LOWER(team_name) = LOWER(referrer_team_name)"
		);

		// Top orphaned sponsor names (referrer_team_name with no matching team_name).
		$orphaned = $wpdb->get_results(
			"SELECT u.referrer_team_name AS sponsor_name, COUNT(*) AS affected_users
			 FROM `{$po10}`.users u
			 LEFT JOIN `{$po10}`.users p ON LOWER(p.team_name) = LOWER(u.referrer_team_name)
			 WHERE u.referrer_team_name IS NOT NULL AND u.referrer_team_name != ''
			 AND p.id IS NULL
			 AND LOWER(u.team_name) != LOWER(u.referrer_team_name)
			 GROUP BY u.referrer_team_name
			 ORDER BY affected_users DESC
			 LIMIT 20"
		);

		$orphaned_total = 0;
		foreach ( $orphaned as $o ) {
			$orphaned_total += (int) $o->affected_users;
		}

		// Largest teams (most direct referrals).
		$largest_teams = $wpdb->get_results(
			"SELECT referrer_team_name AS sponsor, COUNT(*) AS team_size
			 FROM `{$po10}`.users
			 WHERE referrer_team_name IS NOT NULL AND referrer_team_name != ''
			 AND LOWER(team_name) != LOWER(referrer_team_name)
			 GROUP BY referrer_team_name
			 ORDER BY team_size DESC
			 LIMIT 10"
		);

		// Sample sponsor tree: top-level sponsor with 2 levels.
		$sample_tree = array();
		if ( ! empty( $largest_teams ) ) {
			$root_name = $largest_teams[0]->sponsor;
			$root_user = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, user_fname, user_lname, team_name, promotional_title FROM `{$po10}`.users WHERE LOWER(team_name) = LOWER(%s) LIMIT 1",
				$root_name
			) );

			if ( $root_user ) {
				$level1 = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, user_fname, user_lname, team_name, promotional_title
					 FROM `{$po10}`.users
					 WHERE LOWER(referrer_team_name) = LOWER(%s) AND LOWER(team_name) != LOWER(%s)
					 ORDER BY id LIMIT 5",
					$root_name,
					$root_name
				) );

				$sample_tree = array(
					'root'   => array(
						'team_name' => $root_user->team_name,
						'name'      => trim( $root_user->user_fname . ' ' . $root_user->user_lname ),
						'type'      => $root_user->promotional_title,
					),
					'children' => array(),
				);

				foreach ( $level1 as $child ) {
					$sample_tree['children'][] = array(
						'team_name' => $child->team_name,
						'name'      => trim( $child->user_fname . ' ' . $child->user_lname ),
						'type'      => $child->promotional_title,
					);
				}
			}
		}

		// phpcs:enable

		return array(
			'total_with_sponsor' => $total,
			'no_sponsor'         => $no_sponsor,
			'self_referrals'     => $self_refs,
			'resolved'           => $total - $orphaned_total - $self_refs,
			'orphaned_total'     => $orphaned_total,
			'orphaned_details'   => $orphaned,
			'largest_teams'      => $largest_teams,
			'sample_tree'        => $sample_tree,
		);
	}

	// ------------------------------------------------------------------
	// 5. Detect Conflicts
	// ------------------------------------------------------------------

	/**
	 * Detect all data conflicts that would block or complicate migration.
	 *
	 * @return array Conflict details by category.
	 */
	public function detect_conflicts() {
		global $wpdb;

		$po10 = $this->po10_db;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 1. Duplicate emails within PO10.
		$dup_emails = $wpdb->get_results(
			"SELECT LOWER(email) AS email, COUNT(*) AS cnt
			 FROM `{$po10}`.users GROUP BY email HAVING cnt > 1"
		);

		// 2. Case-insensitive duplicate referral codes within PO10.
		$dup_codes = $wpdb->get_results(
			"SELECT LOWER(team_name) AS code, GROUP_CONCAT(team_name ORDER BY id) AS variants, COUNT(*) AS cnt
			 FROM `{$po10}`.users
			 WHERE team_name IS NOT NULL AND team_name != ''
			 GROUP BY code HAVING cnt > 1
			 ORDER BY cnt DESC LIMIT 50"
		);

		// 3. Missing/invalid emails.
		$bad_emails = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$po10}`.users WHERE email IS NULL OR email = '' OR email NOT LIKE '%_@_%.__%'"
		);

		// 4. PO10 referral codes that conflict with existing KonX codes.
		$konx_code_conflicts = $wpdb->get_results(
			"SELECT po10.team_name AS po10_code, ka.referral_code AS konx_code, ka.id AS konx_affiliate_id
			 FROM `{$po10}`.users po10
			 JOIN {$wpdb->prefix}konx_affiliates ka ON LOWER(po10.team_name) = LOWER(ka.referral_code)
			 WHERE po10.team_name IS NOT NULL AND po10.team_name != ''"
		);

		// 5. PO10 users whose email already exists in WP AND has a KonX affiliate.
		$existing_affiliates = $wpdb->get_results(
			"SELECT po10.id AS po10_id, po10.email, po10.team_name AS po10_code,
			        ka.id AS konx_id, ka.referral_code AS konx_code, ka.affiliate_type
			 FROM `{$po10}`.users po10
			 JOIN {$wpdb->users} wp ON LOWER(po10.email) = LOWER(wp.user_email)
			 JOIN {$wpdb->prefix}konx_affiliates ka ON ka.user_id = wp.ID"
		);

		// 6. Self-referrals.
		$self_refs = $wpdb->get_results(
			"SELECT id, email, team_name, referrer_team_name
			 FROM `{$po10}`.users
			 WHERE referrer_team_name IS NOT NULL AND referrer_team_name != ''
			 AND LOWER(team_name) = LOWER(referrer_team_name)"
		);

		// phpcs:enable

		$critical = count( $dup_codes ) + count( $konx_code_conflicts );
		$warnings = $bad_emails + count( $self_refs );

		return array(
			'duplicate_emails'      => $dup_emails,
			'duplicate_codes'       => $dup_codes,
			'invalid_emails'        => $bad_emails,
			'konx_code_conflicts'   => $konx_code_conflicts,
			'existing_affiliates'   => $existing_affiliates,
			'self_referrals'        => $self_refs,
			'critical_count'        => $critical,
			'warning_count'         => $warnings,
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
		global $wpdb;

		$po10 = $this->po10_db;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, user_fname, user_lname, email, user_phone,
			        promotional_title, team_name, referrer_team_name,
			        source, country_code, created_at
			 FROM `{$po10}`.users
			 ORDER BY id
			 LIMIT %d",
			$limit
		) );
		// phpcs:enable

		$preview = array();
		foreach ( $rows as $row ) {
			$record = $this->build_record( $row );
			$preview[] = $record;
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

		$po10 = $this->po10_db;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, user_fname, user_lname, email, user_phone,
			        promotional_title, team_name, referrer_team_name,
			        source, country_code, created_at
			 FROM `{$po10}`.users
			 ORDER BY id"
		);
		// phpcs:enable

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

		// Build a set of all PO10 team_names for sponsor resolution.
		$all_team_names = array();
		foreach ( $rows as $r ) {
			if ( ! empty( $r->team_name ) ) {
				$all_team_names[ strtolower( $r->team_name ) ] = true;
			}
		}

		// Build set of existing KonX referral codes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$konx_codes_raw = $wpdb->get_col( "SELECT LOWER(referral_code) FROM {$wpdb->prefix}konx_affiliates" );
		$konx_codes = array_flip( $konx_codes_raw );

		foreach ( $rows as $row ) {
			$record = $this->build_record( $row );

			// Track type stats.
			if ( 'normalized' === $record['type_status'] ) {
				$type_normalized++;
			}
			if ( 'defaulted' === $record['type_status'] ) {
				$type_defaulted++;
			}

			// Check for errors.
			if ( ! empty( $record['errors'] ) ) {
				$will_skip++;
				$errors[] = array( 'po10_id' => $row->id, 'email' => $row->email, 'errors' => $record['errors'] );
				continue;
			}

			// Duplicate email within PO10 batch.
			$email_lower = strtolower( $record['email'] );
			if ( isset( $seen_emails[ $email_lower ] ) ) {
				$will_skip++;
				$errors[] = array( 'po10_id' => $row->id, 'email' => $row->email, 'errors' => array( 'duplicate_email_in_batch' ) );
				continue;
			}
			$seen_emails[ $email_lower ] = true;

			// Duplicate code within PO10 batch.
			$code_lower = strtolower( $record['referral_code'] );
			if ( ! empty( $code_lower ) && isset( $seen_codes[ $code_lower ] ) ) {
				$will_skip++;
				$errors[] = array( 'po10_id' => $row->id, 'email' => $row->email, 'errors' => array( 'duplicate_code_in_batch' ) );
				continue;
			}
			if ( ! empty( $code_lower ) ) {
				$seen_codes[ $code_lower ] = true;
			}

			// Code conflict with existing KonX.
			if ( ! empty( $code_lower ) && isset( $konx_codes[ $code_lower ] ) ) {
				$will_skip++;
				$errors[] = array( 'po10_id' => $row->id, 'email' => $row->email, 'errors' => array( 'code_conflicts_with_konx' ) );
				continue;
			}

			// Check if WP user exists.
			$wp_user = get_user_by( 'email', $record['email'] );
			if ( ! $wp_user ) {
				$will_create_user++;
			} else {
				// Check if already has affiliate.
				$existing_aff = Konx_Affiliate_Manager::get_affiliate_by_user( $wp_user->ID );
				if ( $existing_aff ) {
					$will_skip++;
					$warnings[] = array( 'po10_id' => $row->id, 'email' => $row->email, 'warning' => 'existing_affiliate' );
					continue;
				}
			}

			$will_create_affiliate++;

			// Type tracking.
			$t = $record['affiliate_type'];
			if ( ! isset( $by_type[ $t ] ) ) {
				$by_type[ $t ] = 0;
			}
			$by_type[ $t ]++;

			// Sponsor resolution.
			if ( ! empty( $record['parent_referral_code'] ) ) {
				$parent_lower = strtolower( $record['parent_referral_code'] );
				if ( $parent_lower === $code_lower ) {
					// Self-referral — clear.
					$warnings[] = array( 'po10_id' => $row->id, 'email' => $row->email, 'warning' => 'self_referral' );
				} elseif ( isset( $all_team_names[ $parent_lower ] ) || isset( $konx_codes[ $parent_lower ] ) ) {
					$will_link_sponsor++;
				} else {
					$orphan_sponsors++;
					$warnings[] = array( 'po10_id' => $row->id, 'email' => $row->email, 'warning' => 'orphan_sponsor' );
				}
			}
		}

		$total = $will_create_affiliate + $will_skip;
		$batch_size = 50;
		$est_batches = (int) ceil( $will_create_affiliate / $batch_size );

		return array(
			'total_records'         => $total,
			'will_create_users'     => $will_create_user,
			'will_create_affiliates' => $will_create_affiliate,
			'will_skip'             => $will_skip,
			'will_link_sponsors'    => $will_link_sponsor,
			'orphan_sponsors'       => $orphan_sponsors,
			'type_normalized'       => $type_normalized,
			'type_defaulted'        => $type_defaulted,
			'by_type'               => $by_type,
			'errors'                => $errors,
			'warnings'              => $warnings,
			'estimated_batches'     => $est_batches,
			'batch_size'            => $batch_size,
		);
	}

	// ------------------------------------------------------------------
	// 8. Prepare Batch
	// ------------------------------------------------------------------

	/**
	 * Prepare a batch of records for future execution.
	 *
	 * Returns migration-ready data without writing anything.
	 *
	 * @param int $offset Starting offset in the PO10 users table.
	 * @param int $limit  Batch size.
	 * @return array Array of prepared records.
	 */
	public function prepare_batch( $offset = 0, $limit = 50 ) {
		global $wpdb;

		$po10 = $this->po10_db;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, user_fname, user_lname, email, user_phone,
			        promotional_title, team_name, referrer_team_name,
			        source, country_code, created_at
			 FROM `{$po10}`.users
			 ORDER BY id
			 LIMIT %d OFFSET %d",
			$limit,
			$offset
		) );
		// phpcs:enable

		$batch = array();
		foreach ( $rows as $row ) {
			$batch[] = $this->build_record( $row );
		}

		return $batch;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Build a migration record from a PO10 user row.
	 *
	 * @param object $row PO10 user row.
	 * @return array Migration record with planned action, warnings, errors.
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

		// If it's already a valid type.
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
	 * Save state to wp_options for future wizard use.
	 *
	 * @param string $key   State key (e.g. 'scan', 'dry_run').
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
