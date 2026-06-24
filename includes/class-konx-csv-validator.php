<?php
/**
 * CSV row-level validator for migration.
 *
 * Validates every record from the migration source against business
 * rules: email format, required fields, duplicate detection, sponsor
 * resolution, type mapping. Read-only — no data writes.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_CSV_Validator
 */
class Konx_CSV_Validator {

	/**
	 * Severity levels.
	 */
	const ERROR   = 'error';
	const WARNING = 'warning';
	const INFO    = 'info';

	/**
	 * Validate all source records and return categorised issues.
	 *
	 * @param array $records Array of row objects (from migration engine).
	 * @return array { summary, issues[], by_category }.
	 */
	public static function validate( $records ) {
		$issues       = array();
		$seen_emails  = array();
		$seen_codes   = array();
		$team_names   = array();
		$valid        = 0;
		$with_warning = 0;
		$with_error   = 0;

		// Build team name set for sponsor resolution.
		foreach ( $records as $r ) {
			$tn = strtolower( trim( $r->team_name ?? '' ) );
			if ( '' !== $tn ) {
				$team_names[ $tn ] = true;
			}
		}

		$row_num = 0;
		foreach ( $records as $r ) {
			$row_num++;
			$row_issues = array();

			// --- Email ---
			$email = trim( $r->email ?? '' );
			if ( '' === $email ) {
				$row_issues[] = self::issue( $row_num, self::ERROR, 'email', __( 'Missing email', 'konx-affiliate-dashboard' ), '' );
			} elseif ( ! is_email( $email ) ) {
				$row_issues[] = self::issue( $row_num, self::ERROR, 'email', __( 'Invalid email format', 'konx-affiliate-dashboard' ), $email );
			} else {
				$el = strtolower( $email );
				if ( isset( $seen_emails[ $el ] ) ) {
					$row_issues[] = self::issue( $row_num, self::ERROR, 'email', sprintf( __( 'Duplicate email (first seen row %d)', 'konx-affiliate-dashboard' ), $seen_emails[ $el ] ), $email );
				} else {
					$seen_emails[ $el ] = $row_num;
				}
			}

			// --- Team Name ---
			$code = trim( $r->team_name ?? '' );
			if ( '' === $code ) {
				$row_issues[] = self::issue( $row_num, self::ERROR, 'team_name', __( 'Missing team name', 'konx-affiliate-dashboard' ), '' );
			} elseif ( strlen( $code ) > 50 ) {
				$row_issues[] = self::issue( $row_num, self::ERROR, 'team_name', __( 'Team name exceeds 50 characters', 'konx-affiliate-dashboard' ), $code );
			} else {
				$cl = strtolower( $code );
				if ( isset( $seen_codes[ $cl ] ) ) {
					$row_issues[] = self::issue( $row_num, self::ERROR, 'team_name', sprintf( __( 'Duplicate team name (first seen row %d)', 'konx-affiliate-dashboard' ), $seen_codes[ $cl ] ), $code );
				} else {
					$seen_codes[ $cl ] = $row_num;
				}
			}

			// --- Affiliate Type ---
			$type = trim( $r->promotional_title ?? '' );
			if ( '' === $type ) {
				$row_issues[] = self::issue( $row_num, self::WARNING, 'promotional_title', __( 'Missing affiliate type (will default to Sales Agent)', 'konx-affiliate-dashboard' ), '' );
			} else {
				$normalized = Konx_Migration_Engine::normalize_type( $type );
				$valid_types = array( 'business', 'team_agent', 'marketing_agent', 'sales_agent' );
				if ( ! in_array( $normalized, $valid_types, true ) ) {
					$row_issues[] = self::issue( $row_num, self::WARNING, 'promotional_title', sprintf( __( 'Unknown type "%s" (will default to Sales Agent)', 'konx-affiliate-dashboard' ), $type ), $type );
				}
			}

			// --- Sponsor ---
			$sponsor = trim( $r->referrer_team_name ?? '' );
			if ( '' !== $sponsor ) {
				$sl = strtolower( $sponsor );
				$cl_self = strtolower( trim( $r->team_name ?? '' ) );

				if ( $sl === $cl_self ) {
					$row_issues[] = self::issue( $row_num, self::WARNING, 'referrer_team_name', __( 'Self-referral (sponsor equals own team name)', 'konx-affiliate-dashboard' ), $sponsor );
				} elseif ( ! isset( $team_names[ $sl ] ) ) {
					$row_issues[] = self::issue( $row_num, self::WARNING, 'referrer_team_name', __( 'Sponsor not found in source data', 'konx-affiliate-dashboard' ), $sponsor );
				}
			}

			// --- First / Last Name ---
			if ( '' === trim( $r->user_fname ?? '' ) ) {
				$row_issues[] = self::issue( $row_num, self::WARNING, 'user_fname', __( 'Missing first name', 'konx-affiliate-dashboard' ), '' );
			}
			if ( '' === trim( $r->user_lname ?? '' ) ) {
				$row_issues[] = self::issue( $row_num, self::WARNING, 'user_lname', __( 'Missing last name', 'konx-affiliate-dashboard' ), '' );
			}

			// Tally.
			$has_error   = false;
			$has_warning = false;
			foreach ( $row_issues as $ri ) {
				$issues[] = $ri;
				if ( self::ERROR === $ri['severity'] ) { $has_error = true; }
				if ( self::WARNING === $ri['severity'] ) { $has_warning = true; }
			}

			if ( $has_error ) {
				$with_error++;
			} elseif ( $has_warning ) {
				$with_warning++;
			} else {
				$valid++;
			}
		}

		// Group by category.
		$by_category = array();
		foreach ( $issues as $i ) {
			$by_category[ $i['field'] ][] = $i;
		}

		// Count by severity.
		$error_count   = count( array_filter( $issues, function ( $i ) { return self::ERROR === $i['severity']; } ) );
		$warning_count = count( array_filter( $issues, function ( $i ) { return self::WARNING === $i['severity']; } ) );

		return array(
			'summary' => array(
				'total'        => count( $records ),
				'valid'        => $valid,
				'with_warning' => $with_warning,
				'with_error'   => $with_error,
				'error_count'  => $error_count,
				'warning_count' => $warning_count,
			),
			'issues'      => $issues,
			'by_category' => $by_category,
		);
	}

	/**
	 * Build a single issue record.
	 */
	private static function issue( $row, $severity, $field, $message, $value ) {
		return array(
			'row'      => $row,
			'severity' => $severity,
			'field'    => $field,
			'message'  => $message,
			'value'    => $value,
		);
	}

	/**
	 * Generate CSV export data for validation issues.
	 *
	 * @param array $issues Array of issue records.
	 * @return array Array of rows (header + data) suitable for CSV output.
	 */
	public static function export_csv( $issues ) {
		$rows   = array();
		$rows[] = array( 'Row', 'Severity', 'Field', 'Issue', 'Value' );

		foreach ( $issues as $i ) {
			$rows[] = array(
				$i['row'],
				strtoupper( $i['severity'] ),
				$i['field'],
				$i['message'],
				$i['value'],
			);
		}

		return $rows;
	}
}
