<?php
/**
 * Source comparison engine for migration.
 *
 * Compares uploaded CSV/PO10 data against WordPress users, KonX
 * affiliates, and Coupon Affiliates (if active) to detect duplicates,
 * matches, and reconcile orphan sponsors. Read-only.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Source_Comparator
 */
class Konx_Source_Comparator {

	/**
	 * Run the full comparison and return results.
	 *
	 * @param array $source_records Array of source row objects (from engine/CSV).
	 * @return array Comparison results.
	 */
	public static function compare( $source_records ) {
		$wp_matches    = self::compare_wp_users( $source_records );
		$konx_matches  = self::compare_konx_affiliates( $source_records );
		$ca_result     = self::compare_coupon_affiliates( $source_records );
		$sponsor_recon = self::reconcile_sponsors( $source_records );

		$issues = array_merge(
			$wp_matches['issues'],
			$konx_matches['issues'],
			$ca_result['issues'],
			$sponsor_recon['issues']
		);

		return array(
			'summary' => array(
				'csv_records'       => count( $source_records ),
				'wp_matches'        => $wp_matches['matched'],
				'wp_new'            => $wp_matches['new'],
				'konx_matches'      => $konx_matches['matched'],
				'konx_new'          => $konx_matches['new'],
				'ca_detected'       => $ca_result['detected'],
				'ca_matches'        => $ca_result['matched'],
				'sponsors_explained' => $sponsor_recon['explained'],
				'sponsors_missing'  => $sponsor_recon['still_missing'],
				'total_issues'      => count( $issues ),
			),
			'wp'       => $wp_matches,
			'konx'     => $konx_matches,
			'ca'       => $ca_result,
			'sponsors' => $sponsor_recon,
			'issues'   => $issues,
		);
	}

	// ------------------------------------------------------------------
	// WordPress Users Comparison
	// ------------------------------------------------------------------

	/**
	 * Compare source records against wp_users by email.
	 *
	 * @param array $records Source records.
	 * @return array { matched, new, issues[] }.
	 */
	private static function compare_wp_users( $records ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wp_emails_raw = $wpdb->get_col( "SELECT LOWER(user_email) FROM {$wpdb->users}" );
		$wp_emails = array_flip( $wp_emails_raw );

		$matched = 0;
		$new     = 0;
		$issues  = array();

		foreach ( $records as $r ) {
			$email = strtolower( trim( $r->email ?? '' ) );
			if ( empty( $email ) ) {
				continue;
			}

			if ( isset( $wp_emails[ $email ] ) ) {
				$matched++;
			} else {
				$new++;
			}
		}

		if ( $matched > 0 ) {
			$issues[] = self::issue(
				'CSV vs WP Users',
				sprintf( '%d emails', $matched ),
				'match',
				'info',
				sprintf( __( '%d CSV records match existing WordPress users. Affiliate profiles will be added to existing accounts.', 'konx-affiliate-dashboard' ), $matched )
			);
		}

		if ( $new > 0 ) {
			$issues[] = self::issue(
				'CSV vs WP Users',
				sprintf( '%d emails', $new ),
				'new',
				'info',
				sprintf( __( '%d CSV records have no WordPress account. New accounts will be created during migration.', 'konx-affiliate-dashboard' ), $new )
			);
		}

		return array( 'matched' => $matched, 'new' => $new, 'issues' => $issues );
	}

	// ------------------------------------------------------------------
	// KonX Affiliates Comparison
	// ------------------------------------------------------------------

	/**
	 * Compare source records against existing KonX affiliates.
	 *
	 * @param array $records Source records.
	 * @return array { matched, new, duplicates[], issues[] }.
	 */
	private static function compare_konx_affiliates( $records ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_affiliates';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$konx_codes_raw = $wpdb->get_col( "SELECT LOWER(referral_code) FROM {$table}" );
		$konx_codes = array_flip( $konx_codes_raw );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$konx_users_raw = $wpdb->get_results(
			"SELECT ka.id, ka.referral_code, ka.affiliate_type, u.user_email
			 FROM {$table} ka JOIN {$wpdb->users} u ON ka.user_id = u.ID"
		);
		$konx_emails = array();
		foreach ( $konx_users_raw as $ku ) {
			$konx_emails[ strtolower( $ku->user_email ) ] = $ku;
		}

		$matched    = 0;
		$new        = 0;
		$duplicates = array();
		$issues     = array();

		foreach ( $records as $r ) {
			$email = strtolower( trim( $r->email ?? '' ) );
			$code  = strtolower( trim( $r->team_name ?? '' ) );
			$found = false;

			// Check by email.
			if ( ! empty( $email ) && isset( $konx_emails[ $email ] ) ) {
				$matched++;
				$found = true;
				$ka = $konx_emails[ $email ];
				$duplicates[] = array(
					'po10_id'    => $r->id,
					'email'      => $r->email,
					'csv_code'   => $r->team_name,
					'konx_id'    => $ka->id,
					'konx_code'  => $ka->referral_code,
					'konx_type'  => $ka->affiliate_type,
				);
			}

			// Check by code.
			if ( ! $found && ! empty( $code ) && isset( $konx_codes[ $code ] ) ) {
				$matched++;
				$found = true;
			}

			if ( ! $found ) {
				$new++;
			}
		}

		if ( $matched > 0 ) {
			$issues[] = self::issue(
				'CSV vs KonX',
				sprintf( '%d records', $matched ),
				'match',
				$matched > 0 ? 'warning' : 'info',
				sprintf( __( '%d CSV records already have KonX affiliate profiles. These will be skipped during migration.', 'konx-affiliate-dashboard' ), $matched )
			);
		}

		return array(
			'matched'    => $matched,
			'new'        => $new,
			'duplicates' => $duplicates,
			'issues'     => $issues,
		);
	}

	// ------------------------------------------------------------------
	// Coupon Affiliates Comparison
	// ------------------------------------------------------------------

	/**
	 * Compare source records against Coupon Affiliates data (if available).
	 *
	 * @param array $records Source records.
	 * @return array { detected, matched, issues[] }.
	 */
	private static function compare_coupon_affiliates( $records ) {
		global $wpdb;

		$ca_table = $wpdb->prefix . 'wcusage_register';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ca_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ca_table ) );

		if ( ! $ca_exists ) {
			return array(
				'detected' => false,
				'matched'  => 0,
				'issues'   => array(
					self::issue( 'Coupon Affiliates', '', 'info', 'info', __( 'Coupon Affiliates plugin not detected. Comparison skipped. This does not block migration.', 'konx-affiliate-dashboard' ) ),
				),
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ca_codes_raw = $wpdb->get_col( "SELECT LOWER(couponcode) FROM {$ca_table}" );
		$ca_codes = array_flip( $ca_codes_raw );
		$ca_total = count( $ca_codes );

		$matched     = 0;
		$csv_only    = 0;
		$issues      = array();

		foreach ( $records as $r ) {
			$code = strtolower( trim( $r->team_name ?? '' ) );
			if ( ! empty( $code ) && isset( $ca_codes[ $code ] ) ) {
				$matched++;
				unset( $ca_codes[ $code ] );
			} else {
				$csv_only++;
			}
		}

		$ca_only = count( $ca_codes );

		$issues[] = self::issue(
			'Coupon Affiliates',
			sprintf( '%d records', $ca_total ),
			'info',
			'info',
			sprintf( __( 'Coupon Affiliates detected: %d records. %d match CSV, %d only in Coupon Affiliates, %d only in CSV.', 'konx-affiliate-dashboard' ), $ca_total, $matched, $ca_only, $csv_only )
		);

		return array(
			'detected'  => true,
			'matched'   => $matched,
			'ca_only'   => $ca_only,
			'csv_only'  => $csv_only,
			'ca_total'  => $ca_total,
			'issues'    => $issues,
		);
	}

	// ------------------------------------------------------------------
	// Sponsor Reconciliation
	// ------------------------------------------------------------------

	/**
	 * Reconcile orphan sponsor references against site data.
	 *
	 * @param array $records Source records.
	 * @return array { explained, still_missing, details[], issues[] }.
	 */
	private static function reconcile_sponsors( $records ) {
		global $wpdb;

		// Build source team_name set.
		$source_teams = array();
		foreach ( $records as $r ) {
			$tn = strtolower( trim( $r->team_name ?? '' ) );
			if ( '' !== $tn ) {
				$source_teams[ $tn ] = true;
			}
		}

		// Collect orphan sponsors (referenced but not in source).
		$orphan_counts = array();
		foreach ( $records as $r ) {
			$rtn = strtolower( trim( $r->referrer_team_name ?? '' ) );
			$tn  = strtolower( trim( $r->team_name ?? '' ) );
			if ( '' !== $rtn && $rtn !== $tn && ! isset( $source_teams[ $rtn ] ) ) {
				$orphan_counts[ $rtn ] = ( $orphan_counts[ $rtn ] ?? 0 ) + 1;
			}
		}

		if ( empty( $orphan_counts ) ) {
			return array( 'explained' => 0, 'still_missing' => 0, 'details' => array(), 'issues' => array() );
		}

		// Check WP users by display_name or login.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wp_names_raw = $wpdb->get_col( "SELECT LOWER(display_name) FROM {$wpdb->users}" );
		$wp_names = array_flip( $wp_names_raw );

		// Check KonX affiliate codes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$konx_codes_raw = $wpdb->get_col( "SELECT LOWER(referral_code) FROM {$wpdb->prefix}konx_affiliates" );
		$konx_codes = array_flip( $konx_codes_raw );

		// Check Coupon Affiliates codes.
		$ca_codes = array();
		$ca_table = $wpdb->prefix . 'wcusage_register';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ca_table ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ca_raw = $wpdb->get_col( "SELECT LOWER(couponcode) FROM {$ca_table}" );
			$ca_codes = array_flip( $ca_raw );
		}

		$explained     = 0;
		$still_missing = 0;
		$details       = array();
		$issues        = array();

		arsort( $orphan_counts );

		foreach ( array_slice( $orphan_counts, 0, 20, true ) as $sponsor => $count ) {
			$found_in = array();
			if ( isset( $wp_names[ $sponsor ] ) ) { $found_in[] = 'WP Users'; }
			if ( isset( $konx_codes[ $sponsor ] ) ) { $found_in[] = 'KonX'; }
			if ( isset( $ca_codes[ $sponsor ] ) ) { $found_in[] = 'Coupon Affiliates'; }

			if ( ! empty( $found_in ) ) {
				$explained += $count;
				$details[] = array(
					'sponsor' => $sponsor,
					'count'   => $count,
					'found'   => implode( ', ', $found_in ),
					'status'  => 'found',
				);
			} else {
				$still_missing += $count;
				$details[] = array(
					'sponsor' => $sponsor,
					'count'   => $count,
					'found'   => '',
					'status'  => 'missing',
				);
			}
		}

		// Count remaining orphans beyond top 20.
		$remaining = array_slice( $orphan_counts, 20, null, true );
		foreach ( $remaining as $sponsor => $count ) {
			$found = isset( $wp_names[ $sponsor ] ) || isset( $konx_codes[ $sponsor ] ) || isset( $ca_codes[ $sponsor ] );
			if ( $found ) { $explained += $count; } else { $still_missing += $count; }
		}

		if ( $explained > 0 ) {
			$issues[] = self::issue(
				'Sponsor Reconciliation',
				sprintf( '%d references', $explained ),
				'reconciled',
				'info',
				sprintf( __( '%d orphan sponsor references found in existing site data (WP users, KonX, or Coupon Affiliates).', 'konx-affiliate-dashboard' ), $explained )
			);
		}

		if ( $still_missing > 0 ) {
			$issues[] = self::issue(
				'Sponsor Reconciliation',
				sprintf( '%d references', $still_missing ),
				'missing',
				'warning',
				sprintf( __( '%d orphan sponsor references not found anywhere. These affiliates will have no parent.', 'konx-affiliate-dashboard' ), $still_missing )
			);
		}

		return array(
			'explained'     => $explained,
			'still_missing' => $still_missing,
			'details'       => $details,
			'issues'        => $issues,
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Build a comparison issue record.
	 */
	private static function issue( $source, $record, $match_type, $severity, $message ) {
		return array(
			'source'     => $source,
			'record'     => $record,
			'match_type' => $match_type,
			'severity'   => $severity,
			'message'    => $message,
		);
	}

	/**
	 * Generate CSV export for comparison results.
	 *
	 * @param array $results Output of compare().
	 * @return array CSV rows.
	 */
	public static function export_csv( $results ) {
		$rows   = array();
		$rows[] = array( 'Source', 'Record', 'Match Type', 'Severity', 'Message' );

		foreach ( $results['issues'] as $i ) {
			$rows[] = array(
				$i['source'],
				$i['record'],
				$i['match_type'],
				strtoupper( $i['severity'] ),
				$i['message'],
			);
		}

		// Add sponsor reconciliation details.
		if ( ! empty( $results['sponsors']['details'] ) ) {
			$rows[] = array( '', '', '', '', '' );
			$rows[] = array( 'Sponsor Reconciliation', 'Sponsor Name', 'Affected Users', 'Found In', 'Status' );
			foreach ( $results['sponsors']['details'] as $d ) {
				$rows[] = array(
					'',
					$d['sponsor'],
					$d['count'],
					$d['found'] ?: 'Not found',
					$d['status'],
				);
			}
		}

		// Add KonX duplicate details.
		if ( ! empty( $results['konx']['duplicates'] ) ) {
			$rows[] = array( '', '', '', '', '' );
			$rows[] = array( 'KonX Duplicates', 'PO10 ID', 'Email', 'CSV Code', 'KonX Code' );
			foreach ( $results['konx']['duplicates'] as $d ) {
				$rows[] = array( '', $d['po10_id'], $d['email'], $d['csv_code'], $d['konx_code'] );
			}
		}

		return $rows;
	}
}
