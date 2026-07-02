<?php
/**
 * Existing Affiliate Integrity Auditor.
 *
 * Performs read-only integrity checks across all five participating
 * systems before migration execution: PowerOf10 source data,
 * Coupon Affiliates, WordPress users, WooCommerce, and KonX.
 *
 * This class NEVER writes to the database. All methods are SELECT-only.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Integrity_Auditor
 */
class Konx_Integrity_Auditor {

	/**
	 * Valid KonX affiliate types.
	 *
	 * @var array
	 */
	private static $valid_types = array( 'business', 'team_agent', 'marketing_agent', 'sales_agent' );

	/**
	 * Acceptable PO10 promotional_title values (before normalization).
	 *
	 * @var array
	 */
	private static $valid_po10_types = array(
		'sales_agent', 'salesagent', 'sales agent',
		'team_agent', 'teamagent', 'team agent',
		'marketing_agent', 'marketingagent', 'marketing agent',
		'business', 'business_affiliate', 'business affiliate',
		'',
	);

	/**
	 * Run all integrity audits across all systems.
	 *
	 * @param array $state Migration wizard state.
	 * @return array Complete audit results.
	 */
	public static function audit_all( $state ) {
		$records = self::get_source_records( $state );

		$results = array(
			'generated_at' => current_time( 'mysql', true ),
			'po10'         => self::audit_po10( $records ),
			'coupon'       => self::audit_coupon_affiliates(),
			'wordpress'    => self::audit_wordpress(),
			'woocommerce'  => self::audit_woocommerce(),
			'konx'         => self::audit_konx(),
			'cross_system' => self::cross_system_reconciliation( $records ),
		);

		$results['readiness'] = self::calculate_readiness( $results );

		return $results;
	}

	// ------------------------------------------------------------------
	// 1. PowerOf10 Source Data Integrity
	// ------------------------------------------------------------------

	/**
	 * Audit PO10 source data integrity.
	 *
	 * @param array $records Source records.
	 * @return array Audit results with status and checks.
	 */
	public static function audit_po10( $records ) {
		if ( empty( $records ) ) {
			return array(
				'status' => 'fail',
				'label'  => 'PowerOf10',
				'total'  => 0,
				'checks' => array(),
				'detail' => array(),
			);
		}

		$checks = array();
		$detail = array();

		// Build lookup structures.
		$emails_lower     = array();
		$teams_lower      = array();
		$phones           = array();
		$team_set         = array();
		$sponsor_graph    = array();

		foreach ( $records as $r ) {
			$email_l = strtolower( trim( $r->email ?? '' ) );
			$team_l  = strtolower( trim( $r->team_name ?? '' ) );
			$phone   = trim( $r->user_phone ?? '' );

			if ( '' !== $email_l ) {
				$emails_lower[ $email_l ][] = $r->id ?? 0;
			}
			if ( '' !== $team_l ) {
				$teams_lower[ $team_l ][] = $r->id ?? 0;
				$team_set[ $team_l ]      = true;
			}
			if ( '' !== $phone ) {
				$phones[ $phone ][] = $r->id ?? 0;
			}
			if ( '' !== $team_l ) {
				$sponsor_l = strtolower( trim( $r->referrer_team_name ?? '' ) );
				if ( '' !== $sponsor_l ) {
					$sponsor_graph[ $team_l ] = $sponsor_l;
				}
			}
		}

		// 1. Duplicate emails.
		$dup_emails = array_filter( $emails_lower, function ( $ids ) {
			return count( $ids ) > 1;
		} );
		$checks['duplicate_emails'] = array(
			'label'    => 'Duplicate Emails',
			'count'    => count( $dup_emails ),
			'severity' => count( $dup_emails ) > 0 ? 'error' : 'pass',
		);
		if ( ! empty( $dup_emails ) ) {
			$detail['duplicate_emails'] = array_slice( $dup_emails, 0, 20, true );
		}

		// 2. Duplicate team names.
		$dup_teams = array_filter( $teams_lower, function ( $ids ) {
			return count( $ids ) > 1;
		} );
		$checks['duplicate_team_names'] = array(
			'label'    => 'Duplicate Team Names',
			'count'    => count( $dup_teams ),
			'severity' => count( $dup_teams ) > 0 ? 'error' : 'pass',
		);
		if ( ! empty( $dup_teams ) ) {
			$detail['duplicate_team_names'] = array_slice( $dup_teams, 0, 20, true );
		}

		// 3. Duplicate phone numbers.
		$dup_phones = array_filter( $phones, function ( $ids ) {
			return count( $ids ) > 1;
		} );
		$checks['duplicate_phones'] = array(
			'label'    => 'Duplicate Phone Numbers',
			'count'    => count( $dup_phones ),
			'severity' => count( $dup_phones ) > 0 ? 'warning' : 'pass',
		);

		// 4-6. Missing fields.
		$missing_email = 0;
		$missing_team  = 0;
		$missing_type  = 0;
		$missing_sponsor = 0;
		$self_sponsor  = 0;
		$invalid_phone = 0;
		$missing_date  = 0;
		$missing_country = 0;
		$invalid_type  = 0;

		$self_sponsor_detail = array();
		$invalid_type_detail = array();
		$missing_email_detail = array();

		foreach ( $records as $r ) {
			$email = trim( $r->email ?? '' );
			$team  = trim( $r->team_name ?? '' );
			$type  = trim( $r->promotional_title ?? '' );
			$sponsor = trim( $r->referrer_team_name ?? '' );
			$phone = trim( $r->user_phone ?? '' );
			$date  = trim( $r->created_at ?? '' );
			$country = trim( $r->country_code ?? '' );
			$rid   = $r->id ?? 0;

			if ( '' === $email || ! is_email( $email ) ) {
				$missing_email++;
				if ( count( $missing_email_detail ) < 10 ) {
					$missing_email_detail[] = array( 'id' => $rid, 'email' => $email );
				}
			}
			if ( '' === $team ) {
				$missing_team++;
			}
			if ( '' === $type ) {
				$missing_type++;
			}
			if ( '' === $sponsor ) {
				$missing_sponsor++;
			}
			if ( '' !== $sponsor && '' !== $team && strtolower( $sponsor ) === strtolower( $team ) ) {
				$self_sponsor++;
				if ( count( $self_sponsor_detail ) < 10 ) {
					$self_sponsor_detail[] = array( 'id' => $rid, 'team' => $team );
				}
			}
			if ( '' !== $phone && strlen( $phone ) < 5 ) {
				$invalid_phone++;
			}
			if ( '' === $date || '0000-00-00' === substr( $date, 0, 10 ) ) {
				$missing_date++;
			}
			if ( '' === $country ) {
				$missing_country++;
			}
			if ( '' !== $type && ! in_array( strtolower( $type ), self::$valid_po10_types, true ) ) {
				$invalid_type++;
				if ( count( $invalid_type_detail ) < 10 ) {
					$invalid_type_detail[] = array( 'id' => $rid, 'type' => $type );
				}
			}
		}

		$total = count( $records );

		$checks['missing_email']   = array( 'label' => 'Missing/Invalid Email', 'count' => $missing_email, 'severity' => $missing_email > 0 ? 'error' : 'pass' );
		$checks['missing_team']    = array( 'label' => 'Missing Team Name', 'count' => $missing_team, 'severity' => $missing_team > 0 ? 'error' : 'pass' );
		$checks['missing_type']    = array( 'label' => 'Missing Affiliate Type', 'count' => $missing_type, 'severity' => $missing_type > 0 ? 'warning' : 'pass' );
		$checks['missing_sponsor'] = array( 'label' => 'Missing Sponsor', 'count' => $missing_sponsor, 'severity' => 'info' );
		$checks['self_sponsor']    = array( 'label' => 'Self Sponsorship', 'count' => $self_sponsor, 'severity' => $self_sponsor > 0 ? 'warning' : 'pass' );
		$checks['invalid_phone']   = array( 'label' => 'Invalid Phone Numbers', 'count' => $invalid_phone, 'severity' => $invalid_phone > 0 ? 'warning' : 'pass' );
		$checks['missing_date']    = array( 'label' => 'Missing Registration Date', 'count' => $missing_date, 'severity' => $missing_date > 0 ? 'warning' : 'pass' );
		$checks['missing_country'] = array( 'label' => 'Missing Country Code', 'count' => $missing_country, 'severity' => $missing_country > 0 ? 'info' : 'pass' );
		$checks['invalid_type']    = array( 'label' => 'Invalid Promotional Title', 'count' => $invalid_type, 'severity' => $invalid_type > 0 ? 'warning' : 'pass' );

		if ( ! empty( $self_sponsor_detail ) ) {
			$detail['self_sponsor'] = $self_sponsor_detail;
		}
		if ( ! empty( $invalid_type_detail ) ) {
			$detail['invalid_type'] = $invalid_type_detail;
		}
		if ( ! empty( $missing_email_detail ) ) {
			$detail['missing_email'] = $missing_email_detail;
		}

		// Circular sponsorship detection.
		$circular = self::detect_circular_sponsors( $sponsor_graph );
		$checks['circular_sponsor'] = array(
			'label'    => 'Circular Sponsorship',
			'count'    => count( $circular ),
			'severity' => count( $circular ) > 0 ? 'error' : 'pass',
		);
		if ( ! empty( $circular ) ) {
			$detail['circular_sponsor'] = array_slice( $circular, 0, 10 );
		}

		// Orphan sponsor references.
		$orphans = array();
		foreach ( $sponsor_graph as $child => $parent ) {
			if ( ! isset( $team_set[ $parent ] ) ) {
				$orphans[ $parent ] = ( $orphans[ $parent ] ?? 0 ) + 1;
			}
		}
		$checks['orphan_sponsors'] = array(
			'label'    => 'Orphan Sponsor References',
			'count'    => count( $orphans ),
			'severity' => count( $orphans ) > 0 ? 'warning' : 'pass',
		);
		if ( ! empty( $orphans ) ) {
			arsort( $orphans );
			$detail['orphan_sponsors'] = array_slice( $orphans, 0, 20, true );
		}

		// Broken hierarchy (sponsor references non-existent team but not orphan — covered above).
		// Multiple possible sponsor matches checked via case differences.
		$multi_match = array();
		$teams_exact = array();
		foreach ( $records as $r ) {
			$tn = trim( $r->team_name ?? '' );
			if ( '' !== $tn ) {
				$teams_exact[ $tn ][] = strtolower( $tn );
			}
		}
		$by_lower = array();
		foreach ( $teams_exact as $exact => $lowers ) {
			$by_lower[ strtolower( $exact ) ][] = $exact;
		}
		foreach ( $by_lower as $lower => $variants ) {
			if ( count( array_unique( $variants ) ) > 1 ) {
				$multi_match[ $lower ] = array_unique( $variants );
			}
		}
		$checks['multi_match_sponsors'] = array(
			'label'    => 'Multiple Sponsor Match Variants',
			'count'    => count( $multi_match ),
			'severity' => count( $multi_match ) > 0 ? 'warning' : 'pass',
		);
		if ( ! empty( $multi_match ) ) {
			$detail['multi_match_sponsors'] = array_slice( $multi_match, 0, 10, true );
		}

		// Duplicate external IDs.
		$ext_ids = array();
		foreach ( $records as $r ) {
			$eid = isset( $r->id ) ? 'po10_' . (int) $r->id : '';
			if ( '' !== $eid ) {
				$ext_ids[ $eid ][] = $r->id;
			}
		}
		$dup_ext = array_filter( $ext_ids, function ( $ids ) {
			return count( $ids ) > 1;
		} );
		$checks['duplicate_external_ids'] = array(
			'label'    => 'Duplicate External IDs',
			'count'    => count( $dup_ext ),
			'severity' => count( $dup_ext ) > 0 ? 'error' : 'pass',
		);

		// Determine overall status.
		$status = 'pass';
		foreach ( $checks as $c ) {
			if ( 'error' === $c['severity'] ) {
				$status = 'fail';
				break;
			}
			if ( 'warning' === $c['severity'] ) {
				$status = 'warning';
			}
		}

		return array(
			'status' => $status,
			'label'  => 'PowerOf10',
			'total'  => $total,
			'checks' => $checks,
			'detail' => $detail,
		);
	}

	// ------------------------------------------------------------------
	// 2. Coupon Affiliates Integrity
	// ------------------------------------------------------------------

	/**
	 * Audit Coupon Affiliates data integrity.
	 *
	 * @return array Audit results.
	 */
	public static function audit_coupon_affiliates() {
		global $wpdb;

		$checks = array();
		$detail = array();
		$structures = array();

		$register_table  = $wpdb->prefix . 'wcusage_register';
		$activity_table  = $wpdb->prefix . 'wcusage_activity';
		$clicks_table    = $wpdb->prefix . 'wcusage_clicks';
		$payouts_table   = $wpdb->prefix . 'wcusage_payouts';
		$campaigns_table = $wpdb->prefix . 'wcusage_campaigns';
		$direct_table    = $wpdb->prefix . 'wcusage_directlinks';

		// Detect which tables exist.
		$tables_found = array();
		foreach ( array(
			'register'    => $register_table,
			'activity'    => $activity_table,
			'clicks'      => $clicks_table,
			'payouts'     => $payouts_table,
			'campaigns'   => $campaigns_table,
			'directlinks' => $direct_table,
		) as $key => $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$tables_found[ $key ] = array(
				'table'  => $table,
				'exists' => null !== $exists,
				'count'  => null !== $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) : 0,
			);
		}
		$structures['tables'] = $tables_found;

		if ( ! $tables_found['register']['exists'] ) {
			return array(
				'status'     => 'info',
				'label'      => 'Coupon Affiliates',
				'total'      => 0,
				'checks'     => array( 'table_exists' => array( 'label' => 'Register Table', 'count' => 0, 'severity' => 'info' ) ),
				'detail'     => array(),
				'structures' => $structures,
			);
		}

		$ca_count = $tables_found['register']['count'];

		// Load all CA registrations.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$registrations = $wpdb->get_results( "SELECT * FROM `{$register_table}`" );

		// 1. Registration without WordPress user.
		$no_wp_user = 0;
		$no_wp_detail = array();
		foreach ( $registrations as $reg ) {
			if ( ! empty( $reg->userid ) && ! get_userdata( (int) $reg->userid ) ) {
				$no_wp_user++;
				if ( count( $no_wp_detail ) < 10 ) {
					$no_wp_detail[] = array( 'ca_id' => $reg->id, 'userid' => $reg->userid, 'coupon' => $reg->couponcode ?? '' );
				}
			}
		}
		$checks['reg_no_wp_user'] = array(
			'label'    => 'Registration Without WP User',
			'count'    => $no_wp_user,
			'severity' => $no_wp_user > 0 ? 'warning' : 'pass',
		);
		if ( ! empty( $no_wp_detail ) ) {
			$detail['reg_no_wp_user'] = $no_wp_detail;
		}

		// 2. Duplicate coupon codes.
		$coupon_codes = array();
		foreach ( $registrations as $reg ) {
			$code = strtolower( trim( $reg->couponcode ?? '' ) );
			if ( '' !== $code ) {
				$coupon_codes[ $code ][] = $reg->id;
			}
		}
		$dup_codes = array_filter( $coupon_codes, function ( $ids ) {
			return count( $ids ) > 1;
		} );
		$checks['duplicate_coupon_codes'] = array(
			'label'    => 'Duplicate Coupon Codes',
			'count'    => count( $dup_codes ),
			'severity' => count( $dup_codes ) > 0 ? 'warning' : 'pass',
		);
		if ( ! empty( $dup_codes ) ) {
			$detail['duplicate_coupon_codes'] = array_slice( $dup_codes, 0, 10, true );
		}

		// 3. Duplicate user assignments.
		$user_ids = array();
		foreach ( $registrations as $reg ) {
			if ( ! empty( $reg->userid ) ) {
				$user_ids[ (int) $reg->userid ][] = $reg->id;
			}
		}
		$dup_users = array_filter( $user_ids, function ( $ids ) {
			return count( $ids ) > 1;
		} );
		$checks['duplicate_user_assignments'] = array(
			'label'    => 'Duplicate User Assignments',
			'count'    => count( $dup_users ),
			'severity' => count( $dup_users ) > 0 ? 'warning' : 'pass',
		);

		// 4. Coupon post without owner / Owner without coupon post.
		$coupon_posts_missing = 0;
		$owner_no_coupon      = 0;
		foreach ( $registrations as $reg ) {
			$code = trim( $reg->couponcode ?? '' );
			if ( '' === $code ) {
				continue;
			}
			// Check if WooCommerce coupon post exists.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$coupon_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND LOWER(post_title) = %s LIMIT 1",
				strtolower( $code )
			) );
			if ( ! $coupon_id ) {
				$coupon_posts_missing++;
			}
		}
		$checks['coupon_posts_missing'] = array(
			'label'    => 'Registration Without Coupon Post',
			'count'    => $coupon_posts_missing,
			'severity' => $coupon_posts_missing > 0 ? 'warning' : 'pass',
		);

		// 5. Coupon posts assigned to missing users.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$coupon_owners = $wpdb->get_results(
			"SELECT p.ID, p.post_title, pm.meta_value AS owner_id
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'wcu_select_coupon_user'
			 WHERE p.post_type = 'shop_coupon' AND p.post_status != 'trash'"
		);
		$orphan_coupons = 0;
		foreach ( $coupon_owners as $co ) {
			if ( ! empty( $co->owner_id ) && ! get_userdata( (int) $co->owner_id ) ) {
				$orphan_coupons++;
			}
		}
		$checks['coupon_orphan_owner'] = array(
			'label'    => 'Coupon Assigned to Deleted User',
			'count'    => $orphan_coupons,
			'severity' => $orphan_coupons > 0 ? 'warning' : 'pass',
		);

		// 6. Missing accepted dates / Invalid statuses / Empty registrations.
		$missing_accepted = 0;
		$invalid_status   = 0;
		$empty_reg        = 0;
		$valid_statuses   = array( 'pending', 'accepted', 'declined' );

		foreach ( $registrations as $reg ) {
			$status = strtolower( trim( $reg->status ?? '' ) );
			if ( 'accepted' === $status && ( empty( $reg->dateaccepted ) || '0000-00-00' === substr( $reg->dateaccepted, 0, 10 ) ) ) {
				$missing_accepted++;
			}
			if ( '' !== $status && ! in_array( $status, $valid_statuses, true ) ) {
				$invalid_status++;
			}
			if ( empty( $reg->couponcode ) && empty( $reg->userid ) ) {
				$empty_reg++;
			}
		}
		$checks['missing_accepted_date'] = array(
			'label'    => 'Missing Accepted Date',
			'count'    => $missing_accepted,
			'severity' => $missing_accepted > 0 ? 'info' : 'pass',
		);
		$checks['invalid_status'] = array(
			'label'    => 'Invalid Registration Status',
			'count'    => $invalid_status,
			'severity' => $invalid_status > 0 ? 'warning' : 'pass',
		);
		$checks['empty_registrations'] = array(
			'label'    => 'Empty Registrations',
			'count'    => $empty_reg,
			'severity' => $empty_reg > 0 ? 'warning' : 'pass',
		);

		// 7. Commission / financial data discovery.
		$financial = array();

		// Check coupon meta for unpaid commissions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$unpaid = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE pm.meta_key = 'wcu_text_unpaid_commission'
			   AND p.post_type = 'shop_coupon'
			   AND pm.meta_value != ''
			   AND pm.meta_value != '0'
			   AND pm.meta_value IS NOT NULL"
		);
		$financial['unpaid_commissions'] = array(
			'label' => 'Coupons With Unpaid Commission',
			'count' => count( $unpaid ),
		);

		// Check for alltime stats.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$alltime_count = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE pm.meta_key = 'wcu_alltime_stats'
			   AND p.post_type = 'shop_coupon'"
		);
		$financial['alltime_stats'] = array(
			'label' => 'Coupons With Alltime Stats',
			'count' => $alltime_count,
		);

		// Check payouts table.
		if ( $tables_found['payouts']['exists'] ) {
			$financial['payouts'] = array(
				'label' => 'Payout Records',
				'count' => $tables_found['payouts']['count'],
			);
		}

		$structures['financial'] = $financial;

		// Determine overall status.
		$status = 'pass';
		foreach ( $checks as $c ) {
			if ( 'error' === $c['severity'] ) {
				$status = 'fail';
				break;
			}
			if ( 'warning' === $c['severity'] ) {
				$status = 'warning';
			}
		}

		return array(
			'status'     => $status,
			'label'      => 'Coupon Affiliates',
			'total'      => $ca_count,
			'checks'     => $checks,
			'detail'     => $detail,
			'structures' => $structures,
		);
	}

	// ------------------------------------------------------------------
	// 3. WordPress User Integrity
	// ------------------------------------------------------------------

	/**
	 * Audit WordPress user integrity.
	 *
	 * @return array Audit results.
	 */
	public static function audit_wordpress() {
		global $wpdb;

		$checks = array();
		$detail = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );

		// 1. Duplicate emails.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$dup_emails = $wpdb->get_results(
			"SELECT LOWER(user_email) AS email, COUNT(*) AS cnt
			 FROM {$wpdb->users}
			 GROUP BY LOWER(user_email)
			 HAVING cnt > 1"
		);
		$checks['duplicate_emails'] = array(
			'label'    => 'Duplicate Emails',
			'count'    => count( $dup_emails ),
			'severity' => count( $dup_emails ) > 0 ? 'error' : 'pass',
		);
		if ( ! empty( $dup_emails ) ) {
			$detail['duplicate_emails'] = array_slice( $dup_emails, 0, 10 );
		}

		// 2. Duplicate usernames.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$dup_logins = $wpdb->get_results(
			"SELECT LOWER(user_login) AS login, COUNT(*) AS cnt
			 FROM {$wpdb->users}
			 GROUP BY LOWER(user_login)
			 HAVING cnt > 1"
		);
		$checks['duplicate_usernames'] = array(
			'label'    => 'Duplicate Usernames',
			'count'    => count( $dup_logins ),
			'severity' => count( $dup_logins ) > 0 ? 'error' : 'pass',
		);

		// 3. Missing first/last name.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$missing_fname = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT u.ID)
			 FROM {$wpdb->users} u
			 LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = 'first_name'
			 WHERE um.meta_value IS NULL OR um.meta_value = ''"
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$missing_lname = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT u.ID)
			 FROM {$wpdb->users} u
			 LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = 'last_name'
			 WHERE um.meta_value IS NULL OR um.meta_value = ''"
		);
		$checks['missing_first_name'] = array(
			'label'    => 'Missing First Name',
			'count'    => $missing_fname,
			'severity' => $missing_fname > ( $total * 0.5 ) ? 'warning' : 'pass',
		);
		$checks['missing_last_name'] = array(
			'label'    => 'Missing Last Name',
			'count'    => $missing_lname,
			'severity' => $missing_lname > ( $total * 0.5 ) ? 'warning' : 'pass',
		);

		// 4. KonX affiliate role checks.
		$konx_roles = array( 'konx_business_affiliate', 'konx_team_agent', 'konx_marketing_agent', 'konx_sales_agent' );
		$konx_role_pattern = '%konx_%';

		// Users with konx roles but no konx_affiliate_id meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$role_no_meta = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT um_role.user_id)
			 FROM {$wpdb->usermeta} um_role
			 LEFT JOIN {$wpdb->usermeta} um_aid ON um_role.user_id = um_aid.user_id AND um_aid.meta_key = 'konx_affiliate_id'
			 WHERE um_role.meta_key = %s
			   AND um_role.meta_value LIKE %s
			   AND (um_aid.meta_value IS NULL OR um_aid.meta_value = '')",
			$wpdb->prefix . 'capabilities',
			$konx_role_pattern
		) );
		$checks['role_without_meta'] = array(
			'label'    => 'KonX Role Without Affiliate ID',
			'count'    => $role_no_meta,
			'severity' => $role_no_meta > 0 ? 'warning' : 'pass',
		);

		// Users with konx_affiliate_id pointing to non-existent affiliate.
		$aff_table = $wpdb->prefix . 'konx_affiliates';
		$aff_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $aff_table ) );
		if ( null !== $aff_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$orphan_meta = (int) $wpdb->get_var(
				"SELECT COUNT(*)
				 FROM {$wpdb->usermeta} um
				 LEFT JOIN `{$aff_table}` a ON um.meta_value = a.id
				 WHERE um.meta_key = 'konx_affiliate_id'
				   AND um.meta_value != ''
				   AND a.id IS NULL"
			);
			$checks['orphan_affiliate_meta'] = array(
				'label'    => 'Affiliate Meta Points to Missing Record',
				'count'    => $orphan_meta,
				'severity' => $orphan_meta > 0 ? 'warning' : 'pass',
			);
		}

		// 5. Multiple konx roles on same user.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$all_caps = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id, meta_value
			 FROM {$wpdb->usermeta}
			 WHERE meta_key = %s AND meta_value LIKE %s",
			$wpdb->prefix . 'capabilities',
			$konx_role_pattern
		) );
		$multi_role = 0;
		foreach ( $all_caps as $cap_row ) {
			$caps = maybe_unserialize( $cap_row->meta_value );
			if ( ! is_array( $caps ) ) {
				continue;
			}
			$found = 0;
			foreach ( $konx_roles as $role ) {
				if ( ! empty( $caps[ $role ] ) ) {
					$found++;
				}
			}
			if ( $found > 1 ) {
				$multi_role++;
			}
		}
		$checks['multiple_konx_roles'] = array(
			'label'    => 'Multiple KonX Roles',
			'count'    => $multi_role,
			'severity' => $multi_role > 0 ? 'warning' : 'pass',
		);

		// Determine overall status.
		$status = 'pass';
		foreach ( $checks as $c ) {
			if ( 'error' === $c['severity'] ) {
				$status = 'fail';
				break;
			}
			if ( 'warning' === $c['severity'] ) {
				$status = 'warning';
			}
		}

		return array(
			'status' => $status,
			'label'  => 'WordPress',
			'total'  => $total,
			'checks' => $checks,
			'detail' => $detail,
		);
	}

	// ------------------------------------------------------------------
	// 4. WooCommerce Integrity
	// ------------------------------------------------------------------

	/**
	 * Audit WooCommerce data integrity.
	 *
	 * @return array Audit results.
	 */
	public static function audit_woocommerce() {
		global $wpdb;

		$checks = array();
		$detail = array();

		if ( ! function_exists( 'WC' ) ) {
			return array(
				'status' => 'fail',
				'label'  => 'WooCommerce',
				'total'  => 0,
				'checks' => array( 'wc_active' => array( 'label' => 'WooCommerce Active', 'count' => 0, 'severity' => 'error' ) ),
				'detail' => array(),
			);
		}

		// Products.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$products = $wpdb->get_results(
			"SELECT ID, post_title FROM {$wpdb->posts}
			 WHERE post_type IN ('product', 'product_variation')
			   AND post_status = 'publish'"
		);
		$product_count = count( $products );

		$map_table = $wpdb->prefix . 'konx_product_map';
		$map_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $map_table ) );

		$unmapped_products = 0;
		$orphan_mappings   = 0;
		$dup_mappings      = 0;
		$invalid_categories = 0;
		$valid_cats = array( 'starter_pack', 'pro_pack', 'ecard_pack', 'basic_pro_conference', 'business_conference', 'corporate_conference', 'enterprise_conference' );

		if ( null !== $map_exists ) {
			// Products without mapping.
			$product_ids = wp_list_pluck( $products, 'ID' );
			if ( ! empty( $product_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$mapped_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT product_id FROM `{$map_table}` WHERE product_id IN ({$placeholders})",
						...$product_ids
					)
				);
				$unmapped_products = count( array_diff( $product_ids, $mapped_ids ) );
			}

			// Mappings pointing to deleted products.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$all_mappings = $wpdb->get_results( "SELECT * FROM `{$map_table}`" );
			foreach ( $all_mappings as $m ) {
				$prod = wc_get_product( $m->product_id );
				if ( ! $prod ) {
					$orphan_mappings++;
				}
				if ( ! in_array( $m->product_type, $valid_cats, true ) ) {
					$invalid_categories++;
				}
			}

			// Duplicate mappings (same product_id mapped twice — should be prevented by UNIQUE constraint).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$dup_map = $wpdb->get_results(
				"SELECT product_id, COUNT(*) AS cnt
				 FROM `{$map_table}`
				 GROUP BY product_id
				 HAVING cnt > 1"
			);
			$dup_mappings = count( $dup_map );
		}

		$checks['unmapped_products'] = array(
			'label'    => 'Products Without Mapping',
			'count'    => $unmapped_products,
			'severity' => $unmapped_products > 0 ? 'info' : 'pass',
		);
		$checks['orphan_mappings'] = array(
			'label'    => 'Mappings to Deleted Products',
			'count'    => $orphan_mappings,
			'severity' => $orphan_mappings > 0 ? 'warning' : 'pass',
		);
		$checks['duplicate_mappings'] = array(
			'label'    => 'Duplicate Product Mappings',
			'count'    => $dup_mappings,
			'severity' => $dup_mappings > 0 ? 'error' : 'pass',
		);
		$checks['invalid_categories'] = array(
			'label'    => 'Invalid Commission Categories',
			'count'    => $invalid_categories,
			'severity' => $invalid_categories > 0 ? 'error' : 'pass',
		);

		// Coupons — duplicate codes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$dup_coupons = $wpdb->get_results(
			"SELECT LOWER(post_title) AS code, COUNT(*) AS cnt
			 FROM {$wpdb->posts}
			 WHERE post_type = 'shop_coupon' AND post_status != 'trash'
			 GROUP BY LOWER(post_title)
			 HAVING cnt > 1"
		);
		$checks['duplicate_coupon_codes'] = array(
			'label'    => 'Duplicate WC Coupon Codes',
			'count'    => count( $dup_coupons ),
			'severity' => count( $dup_coupons ) > 0 ? 'warning' : 'pass',
		);

		// Coupons assigned to missing users.
		$coupon_missing_user = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$coupon_users = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value AS user_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE pm.meta_key = 'wcu_select_coupon_user'
			   AND p.post_type = 'shop_coupon'
			   AND p.post_status != 'trash'
			   AND pm.meta_value != '' AND pm.meta_value != '0'"
		);
		foreach ( $coupon_users as $cu ) {
			if ( ! get_userdata( (int) $cu->user_id ) ) {
				$coupon_missing_user++;
			}
		}
		$checks['coupon_missing_user'] = array(
			'label'    => 'Coupons With Missing Owner User',
			'count'    => $coupon_missing_user,
			'severity' => $coupon_missing_user > 0 ? 'warning' : 'pass',
		);

		// Total coupons.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total_coupons = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_status != 'trash'"
		);

		// Determine overall status.
		$status = 'pass';
		foreach ( $checks as $c ) {
			if ( 'error' === $c['severity'] ) {
				$status = 'fail';
				break;
			}
			if ( 'warning' === $c['severity'] ) {
				$status = 'warning';
			}
		}

		return array(
			'status' => $status,
			'label'  => 'WooCommerce',
			'total'  => $product_count,
			'checks' => $checks,
			'detail' => $detail,
			'extra'  => array( 'products' => $product_count, 'coupons' => $total_coupons ),
		);
	}

	// ------------------------------------------------------------------
	// 5. KonX Integrity
	// ------------------------------------------------------------------

	/**
	 * Audit KonX tables integrity.
	 *
	 * @return array Audit results.
	 */
	public static function audit_konx() {
		global $wpdb;

		$checks = array();
		$detail = array();

		$aff_table  = $wpdb->prefix . 'konx_affiliates';
		$rule_table = $wpdb->prefix . 'konx_commission_rules';
		$map_table  = $wpdb->prefix . 'konx_product_map';
		$comm_table = $wpdb->prefix . 'konx_commissions';
		$api_table  = $wpdb->prefix . 'konx_api_keys';

		// Check if core table exists.
		$aff_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $aff_table ) );
		if ( null === $aff_exists ) {
			return array(
				'status' => 'fail',
				'label'  => 'KonX',
				'total'  => 0,
				'checks' => array( 'table_exists' => array( 'label' => 'Affiliates Table', 'count' => 0, 'severity' => 'error' ) ),
				'detail' => array(),
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$affiliates = $wpdb->get_results( "SELECT * FROM `{$aff_table}`" );
		$total      = count( $affiliates );

		// 1. Duplicate referral codes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$dup_codes = $wpdb->get_results(
			"SELECT LOWER(referral_code) AS code, COUNT(*) AS cnt
			 FROM `{$aff_table}`
			 GROUP BY LOWER(referral_code)
			 HAVING cnt > 1"
		);
		$checks['duplicate_codes'] = array(
			'label'    => 'Duplicate Referral Codes',
			'count'    => count( $dup_codes ),
			'severity' => count( $dup_codes ) > 0 ? 'error' : 'pass',
		);

		// 2. Duplicate external IDs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$dup_ext = $wpdb->get_results(
			"SELECT external_id, COUNT(*) AS cnt
			 FROM `{$aff_table}`
			 WHERE external_id IS NOT NULL AND external_id != ''
			 GROUP BY external_id
			 HAVING cnt > 1"
		);
		$checks['duplicate_external_ids'] = array(
			'label'    => 'Duplicate External IDs',
			'count'    => count( $dup_ext ),
			'severity' => count( $dup_ext ) > 0 ? 'error' : 'pass',
		);

		// 3. Missing parent affiliates.
		$missing_parent = 0;
		foreach ( $affiliates as $a ) {
			if ( ! empty( $a->parent_affiliate_id ) ) {
				$found = false;
				foreach ( $affiliates as $p ) {
					if ( (int) $p->id === (int) $a->parent_affiliate_id ) {
						$found = true;
						break;
					}
				}
				if ( ! $found ) {
					$missing_parent++;
				}
			}
		}
		$checks['missing_parent'] = array(
			'label'    => 'Missing Parent Affiliate',
			'count'    => $missing_parent,
			'severity' => $missing_parent > 0 ? 'warning' : 'pass',
		);

		// 4. Circular hierarchy.
		$circular = 0;
		$parent_map = array();
		foreach ( $affiliates as $a ) {
			if ( ! empty( $a->parent_affiliate_id ) ) {
				$parent_map[ (int) $a->id ] = (int) $a->parent_affiliate_id;
			}
		}
		foreach ( $parent_map as $child => $parent ) {
			$visited = array( $child => true );
			$current = $parent;
			$depth   = 0;
			while ( isset( $parent_map[ $current ] ) && $depth < 100 ) {
				if ( isset( $visited[ $current ] ) ) {
					$circular++;
					break;
				}
				$visited[ $current ] = true;
				$current = $parent_map[ $current ];
				$depth++;
			}
		}
		$checks['circular_hierarchy'] = array(
			'label'    => 'Circular Hierarchy',
			'count'    => $circular,
			'severity' => $circular > 0 ? 'error' : 'pass',
		);

		// 5. Missing WP users.
		$missing_wp = 0;
		foreach ( $affiliates as $a ) {
			if ( ! get_userdata( (int) $a->user_id ) ) {
				$missing_wp++;
			}
		}
		$checks['missing_wp_user'] = array(
			'label'    => 'Affiliate With Missing WP User',
			'count'    => $missing_wp,
			'severity' => $missing_wp > 0 ? 'error' : 'pass',
		);

		// 6. Invalid affiliate types.
		$invalid_types = 0;
		foreach ( $affiliates as $a ) {
			if ( ! in_array( $a->affiliate_type, self::$valid_types, true ) ) {
				$invalid_types++;
			}
		}
		$checks['invalid_types'] = array(
			'label'    => 'Invalid Affiliate Types',
			'count'    => $invalid_types,
			'severity' => $invalid_types > 0 ? 'error' : 'pass',
		);

		// 7. Commission rules coverage.
		$rule_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rule_table ) );
		if ( null !== $rule_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$active_rules = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$rule_table}` WHERE is_active = 1" );
			$checks['commission_rules'] = array(
				'label'    => 'Active Commission Rules',
				'count'    => $active_rules,
				'severity' => 0 === $active_rules ? 'warning' : 'pass',
			);
		}

		// 8. Orphan commissions.
		$comm_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $comm_table ) );
		if ( null !== $comm_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$orphan_comm = (int) $wpdb->get_var(
				"SELECT COUNT(*)
				 FROM `{$comm_table}` c
				 LEFT JOIN `{$aff_table}` a ON c.affiliate_id = a.id
				 WHERE a.id IS NULL"
			);
			$checks['orphan_commissions'] = array(
				'label'    => 'Orphan Commissions',
				'count'    => $orphan_comm,
				'severity' => $orphan_comm > 0 ? 'warning' : 'pass',
			);
		}

		// 9. API keys check.
		$api_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $api_table ) );
		if ( null !== $api_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$revoked_active = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM `{$api_table}` WHERE revoked_at IS NOT NULL AND last_used_at > revoked_at"
			);
			$checks['invalid_api_keys'] = array(
				'label'    => 'Revoked But Recently Used API Keys',
				'count'    => $revoked_active,
				'severity' => $revoked_active > 0 ? 'warning' : 'pass',
			);
		}

		// Determine overall status.
		$status = 'pass';
		foreach ( $checks as $c ) {
			if ( 'error' === $c['severity'] ) {
				$status = 'fail';
				break;
			}
			if ( 'warning' === $c['severity'] ) {
				$status = 'warning';
			}
		}

		return array(
			'status' => $status,
			'label'  => 'KonX',
			'total'  => $total,
			'checks' => $checks,
			'detail' => $detail,
		);
	}

	// ------------------------------------------------------------------
	// 6. Cross-System Reconciliation
	// ------------------------------------------------------------------

	/**
	 * Reconcile records across all five systems.
	 *
	 * @param array $records Source records from PO10/CSV.
	 * @return array Reconciliation results.
	 */
	public static function cross_system_reconciliation( $records ) {
		global $wpdb;

		$result = array(
			'only_po10'       => 0,
			'only_ca'         => 0,
			'only_wp'         => 0,
			'only_konx'       => 0,
			'in_all'          => 0,
			'po10_and_wp'     => 0,
			'po10_and_ca'     => 0,
			'missing_in_dest' => 0,
			'merge_candidates' => array(),
		);

		if ( empty( $records ) ) {
			return array(
				'status'  => 'info',
				'label'   => 'Cross-System',
				'summary' => $result,
				'detail'  => array(),
			);
		}

		// Build email sets for each system.
		$po10_emails = array();
		foreach ( $records as $r ) {
			$em = strtolower( trim( $r->email ?? '' ) );
			if ( '' !== $em ) {
				$po10_emails[ $em ] = true;
			}
		}

		// WP users.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wp_rows = $wpdb->get_col( "SELECT LOWER(user_email) FROM {$wpdb->users}" );
		$wp_emails = array_flip( $wp_rows );

		// Coupon Affiliates.
		$ca_emails = array();
		$ca_table  = $wpdb->prefix . 'wcusage_register';
		$ca_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ca_table ) );
		if ( null !== $ca_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ca_rows = $wpdb->get_results(
				"SELECT r.userid, LOWER(u.user_email) AS email
				 FROM `{$ca_table}` r
				 INNER JOIN {$wpdb->users} u ON r.userid = u.ID"
			);
			foreach ( $ca_rows as $cr ) {
				if ( ! empty( $cr->email ) ) {
					$ca_emails[ $cr->email ] = true;
				}
			}
		}

		// KonX affiliates.
		$konx_emails = array();
		$aff_table   = $wpdb->prefix . 'konx_affiliates';
		$aff_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $aff_table ) );
		if ( null !== $aff_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$konx_rows = $wpdb->get_results(
				"SELECT LOWER(u.user_email) AS email
				 FROM `{$aff_table}` a
				 INNER JOIN {$wpdb->users} u ON a.user_id = u.ID"
			);
			foreach ( $konx_rows as $kr ) {
				if ( ! empty( $kr->email ) ) {
					$konx_emails[ $kr->email ] = true;
				}
			}
		}

		// Reconcile.
		$all_emails = array_unique( array_merge(
			array_keys( $po10_emails ),
			array_keys( $wp_emails ),
			array_keys( $ca_emails ),
			array_keys( $konx_emails )
		) );

		$detail_records = array();
		$counts = array(
			'only_po10'   => 0,
			'only_ca'     => 0,
			'only_wp'     => 0,
			'only_konx'   => 0,
			'in_all'      => 0,
			'po10_and_wp' => 0,
			'po10_and_ca' => 0,
			'po10_no_wp'  => 0,
		);

		foreach ( $all_emails as $email ) {
			$in_po10 = isset( $po10_emails[ $email ] );
			$in_wp   = isset( $wp_emails[ $email ] );
			$in_ca   = isset( $ca_emails[ $email ] );
			$in_konx = isset( $konx_emails[ $email ] );

			$systems = ( $in_po10 ? 1 : 0 ) + ( $in_wp ? 1 : 0 ) + ( $in_ca ? 1 : 0 ) + ( $in_konx ? 1 : 0 );

			if ( $in_po10 && $in_wp && $in_ca && $in_konx ) {
				$counts['in_all']++;
			} elseif ( $in_po10 && ! $in_wp && ! $in_ca && ! $in_konx ) {
				$counts['only_po10']++;
			} elseif ( ! $in_po10 && $in_ca && ! $in_konx ) {
				$counts['only_ca']++;
			} elseif ( ! $in_po10 && ! $in_ca && ! $in_konx && $in_wp ) {
				$counts['only_wp']++;
			} elseif ( ! $in_po10 && ! $in_ca && $in_konx ) {
				$counts['only_konx']++;
			}

			if ( $in_po10 && $in_wp ) {
				$counts['po10_and_wp']++;
			}
			if ( $in_po10 && $in_ca ) {
				$counts['po10_and_ca']++;
			}
			if ( $in_po10 && ! $in_wp ) {
				$counts['po10_no_wp']++;
			}
		}

		// Merge candidates: in PO10 + CA but not KonX (need affiliate creation).
		$merge_count = 0;
		foreach ( $po10_emails as $email => $_ ) {
			if ( isset( $ca_emails[ $email ] ) && ! isset( $konx_emails[ $email ] ) ) {
				$merge_count++;
			}
		}

		$counts['merge_candidates'] = $merge_count;
		$counts['missing_in_dest']  = $counts['po10_no_wp'];
		$counts['total_emails']     = count( $all_emails );

		// Overall status.
		$status = 'pass';
		if ( $counts['only_po10'] > 100 ) {
			$status = 'warning';
		}

		return array(
			'status'  => $status,
			'label'   => 'Cross-System',
			'summary' => $counts,
			'detail'  => $detail_records,
		);
	}

	// ------------------------------------------------------------------
	// Readiness Calculation
	// ------------------------------------------------------------------

	/**
	 * Calculate overall migration readiness from all audits.
	 *
	 * @param array $results All audit results.
	 * @return array Readiness assessment.
	 */
	public static function calculate_readiness( $results ) {
		$systems = array( 'po10', 'coupon', 'wordpress', 'woocommerce', 'konx', 'cross_system' );
		$total_checks = 0;
		$passed       = 0;
		$warnings     = 0;
		$errors       = 0;

		foreach ( $systems as $sys ) {
			if ( ! isset( $results[ $sys ] ) ) {
				continue;
			}
			$r = $results[ $sys ];
			if ( isset( $r['checks'] ) && is_array( $r['checks'] ) ) {
				foreach ( $r['checks'] as $check ) {
					$total_checks++;
					if ( 'pass' === $check['severity'] ) {
						$passed++;
					} elseif ( 'warning' === $check['severity'] ) {
						$warnings++;
					} elseif ( 'error' === $check['severity'] ) {
						$errors++;
					} else {
						$passed++; // info counts as pass.
					}
				}
			}
		}

		$score  = $total_checks > 0 ? round( ( ( $passed + $warnings * 0.5 ) / $total_checks ) * 100 ) : 0;
		$status = 'pass';
		if ( $errors > 0 ) {
			$status = 'fail';
		} elseif ( $warnings > 0 ) {
			$status = 'warning';
		}

		return array(
			'score'        => min( $score, 100 ),
			'status'       => $status,
			'total_checks' => $total_checks,
			'passed'       => $passed,
			'warnings'     => $warnings,
			'errors'       => $errors,
		);
	}

	// ------------------------------------------------------------------
	// Export Helpers
	// ------------------------------------------------------------------

	/**
	 * Export integrity audit as CSV rows.
	 *
	 * @param array $audit Full audit results.
	 * @return array CSV rows (each row is an array).
	 */
	public static function export_csv( $audit ) {
		$rows = array();
		$rows[] = array( 'KonX Integrity Audit Report', '', '', '', '' );
		$rows[] = array( 'Generated', $audit['generated_at'] ?? '' );
		$rows[] = array( '' );

		$rows[] = array( 'Readiness Score', ( $audit['readiness']['score'] ?? 0 ) . '%' );
		$rows[] = array( 'Status', strtoupper( $audit['readiness']['status'] ?? 'unknown' ) );
		$rows[] = array( 'Total Checks', $audit['readiness']['total_checks'] ?? 0 );
		$rows[] = array( 'Passed', $audit['readiness']['passed'] ?? 0 );
		$rows[] = array( 'Warnings', $audit['readiness']['warnings'] ?? 0 );
		$rows[] = array( 'Errors', $audit['readiness']['errors'] ?? 0 );
		$rows[] = array( '' );

		$systems = array( 'po10', 'coupon', 'wordpress', 'woocommerce', 'konx' );
		foreach ( $systems as $sys ) {
			if ( ! isset( $audit[ $sys ] ) ) {
				continue;
			}
			$s = $audit[ $sys ];
			$rows[] = array( strtoupper( $s['label'] ?? $sys ), 'Status: ' . strtoupper( $s['status'] ?? 'unknown' ), 'Records: ' . ( $s['total'] ?? 0 ) );
			$rows[] = array( 'Check', 'Count', 'Severity' );
			if ( ! empty( $s['checks'] ) ) {
				foreach ( $s['checks'] as $check ) {
					$rows[] = array( $check['label'], $check['count'], $check['severity'] );
				}
			}
			$rows[] = array( '' );
		}

		// Cross-system.
		if ( isset( $audit['cross_system']['summary'] ) ) {
			$rows[] = array( 'CROSS-SYSTEM RECONCILIATION' );
			foreach ( $audit['cross_system']['summary'] as $key => $val ) {
				$rows[] = array( $key, $val );
			}
		}

		return $rows;
	}

	/**
	 * Export integrity audit as JSON-serializable array.
	 *
	 * @param array $audit Full audit results.
	 * @return array JSON-ready data.
	 */
	public static function export_json( $audit ) {
		return array(
			'report_type'   => 'konx_integrity_audit',
			'generated_at'  => $audit['generated_at'] ?? '',
			'readiness'     => $audit['readiness'] ?? array(),
			'po10'          => $audit['po10'] ?? array(),
			'coupon'        => $audit['coupon'] ?? array(),
			'wordpress'     => $audit['wordpress'] ?? array(),
			'woocommerce'   => $audit['woocommerce'] ?? array(),
			'konx'          => $audit['konx'] ?? array(),
			'cross_system'  => $audit['cross_system'] ?? array(),
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Get source records from migration state.
	 *
	 * @param array $state Migration state.
	 * @return array Source records as objects.
	 */
	private static function get_source_records( $state ) {
		if ( ! empty( $state['csv_records'] ) ) {
			$records = array();
			foreach ( $state['csv_records'] as $r ) {
				$records[] = (object) $r;
			}
			return $records;
		}

		// Try database source.
		if ( 'database' === ( $state['source'] ?? '' ) ) {
			global $wpdb;
			$po10_db = 'powerof10.biz';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$test = $wpdb->get_var( "SELECT 1 FROM `{$po10_db}`.users LIMIT 1" );
			if ( null !== $test ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $wpdb->get_results(
					"SELECT id, user_fname, user_lname, email, user_phone,
					        promotional_title, team_name, referrer_team_name,
					        source, country_code, created_at
					 FROM `{$po10_db}`.users ORDER BY id"
				);
			}
		}

		return array();
	}

	/**
	 * Detect circular sponsor chains in a parent graph.
	 *
	 * @param array $graph Associative array of child_team => parent_team.
	 * @return array List of circular chains found.
	 */
	private static function detect_circular_sponsors( $graph ) {
		$circular = array();
		$visited  = array();

		foreach ( $graph as $node => $parent ) {
			if ( isset( $visited[ $node ] ) ) {
				continue;
			}

			$path    = array();
			$current = $node;
			$depth   = 0;

			while ( isset( $graph[ $current ] ) && $depth < 50 ) {
				if ( isset( $path[ $current ] ) ) {
					// Found a cycle.
					$cycle = array();
					$in_cycle = false;
					foreach ( $path as $p => $_idx ) {
						if ( $p === $current ) {
							$in_cycle = true;
						}
						if ( $in_cycle ) {
							$cycle[] = $p;
						}
					}
					$cycle[] = $current;
					$circular[] = implode( ' -> ', $cycle );
					break;
				}
				$path[ $current ] = $depth;
				$visited[ $current ] = true;
				$current = $graph[ $current ];
				$depth++;
			}
			$visited[ $node ] = true;
		}

		return array_unique( $circular );
	}
}
