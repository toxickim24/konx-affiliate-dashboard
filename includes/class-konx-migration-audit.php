<?php
/**
 * Migration Audit Report builder.
 *
 * Aggregates data from the migration summary, CSV validator, source
 * comparator, and field mapper into a comprehensive audit report.
 * All methods are read-only — no database writes.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Migration_Audit
 */
class Konx_Migration_Audit {

	/**
	 * Build the complete audit report from stored migration state.
	 *
	 * @return array|null Full audit data, or null if no scan exists.
	 */
	public static function build() {
		$state = get_option( 'konx_migration_state', array() );
		$scan  = isset( $state['scan'] ) ? $state['scan'] : null;

		if ( ! $scan ) {
			return null;
		}

		$summary    = Konx_Migration_Summary::build( $state );
		$validation = isset( $state['validation_results'] ) ? $state['validation_results'] : null;
		$comparison = isset( $state['comparison'] ) ? $state['comparison'] : null;
		$field_map  = isset( $state['field_mappings'] ) ? $state['field_mappings'] : null;
		$csv_info   = isset( $state['csv_info'] ) ? $state['csv_info'] : null;

		return array(
			'generated_at' => current_time( 'mysql', true ),
			'plugin_ver'   => KONX_AFFILIATE_VERSION,
			'source'       => isset( $state['source'] ) ? $state['source'] : 'unknown',
			'scan_at'      => isset( $state['scan_at'] ) ? $state['scan_at'] : null,
			'csv_info'     => $csv_info,
			'field_map'    => $field_map ? self::build_field_map_summary( $field_map ) : null,
			'summary'      => $summary,
			'validation'   => $validation ? self::build_validation_summary( $validation ) : null,
			'duplicates'   => $validation ? self::build_duplicate_report( $validation ) : null,
			'comparison'   => $comparison ? self::build_comparison_summary( $comparison ) : null,
			'sponsors'     => self::build_sponsor_report( $scan, $comparison ),
			'readiness'    => $summary['readiness'],
			'approved'     => ! empty( $state['approved'] ),
			'approved_by'  => isset( $state['approved_by'] ) ? (int) $state['approved_by'] : null,
			'approved_at'  => isset( $state['approved_at'] ) ? $state['approved_at'] : null,
			'warnings'     => self::build_warnings(),
		);
	}

	/**
	 * Build field mapping summary.
	 *
	 * @param array $mappings Field mappings from state.
	 * @return array Summary data.
	 */
	private static function build_field_map_summary( $mappings ) {
		$mapped   = 0;
		$unmapped = 0;
		$exact    = 0;
		$alias    = 0;
		$details  = array();

		foreach ( $mappings as $m ) {
			$details[] = array(
				'csv_column'  => $m['csv_column'],
				'target'      => ! empty( $m['target_label'] ) ? $m['target_label'] : '—',
				'confidence'  => $m['confidence'],
				'status'      => $m['status'],
			);
			if ( 'mapped' === $m['status'] ) {
				$mapped++;
				if ( 'exact' === $m['confidence'] ) { $exact++; }
				if ( 'alias' === $m['confidence'] ) { $alias++; }
			} else {
				$unmapped++;
			}
		}

		return array(
			'total'    => count( $mappings ),
			'mapped'   => $mapped,
			'unmapped' => $unmapped,
			'exact'    => $exact,
			'alias'    => $alias,
			'details'  => $details,
		);
	}

	/**
	 * Build validation summary with categorized issues.
	 *
	 * @param array $validation Validation results from state.
	 * @return array Summary data.
	 */
	private static function build_validation_summary( $validation ) {
		$by_severity = array( 'error' => 0, 'warning' => 0, 'info' => 0 );
		$by_field    = array();
		$top_issues  = array();

		if ( ! empty( $validation['issues'] ) ) {
			foreach ( $validation['issues'] as $issue ) {
				$sev = $issue['severity'];
				if ( isset( $by_severity[ $sev ] ) ) {
					$by_severity[ $sev ]++;
				}

				$field = $issue['field'];
				if ( ! isset( $by_field[ $field ] ) ) {
					$by_field[ $field ] = array( 'error' => 0, 'warning' => 0 );
				}
				if ( isset( $by_field[ $field ][ $sev ] ) ) {
					$by_field[ $field ][ $sev ]++;
				}
			}

			// Build top issue types with counts.
			$issue_types = array();
			foreach ( $validation['issues'] as $issue ) {
				$key = $issue['field'] . ':' . $issue['message'];
				if ( ! isset( $issue_types[ $key ] ) ) {
					$issue_types[ $key ] = array(
						'field'    => $issue['field'],
						'message'  => $issue['message'],
						'severity' => $issue['severity'],
						'count'    => 0,
					);
				}
				$issue_types[ $key ]['count']++;
			}
			usort( $issue_types, function ( $a, $b ) { return $b['count'] - $a['count']; } );
			$top_issues = array_slice( $issue_types, 0, 10 );
		}

		return array(
			'summary'     => $validation['summary'],
			'by_severity' => $by_severity,
			'by_field'    => $by_field,
			'top_issues'  => $top_issues,
			'total_issues' => count( $validation['issues'] ),
		);
	}

	/**
	 * Extract duplicate records from validation issues.
	 *
	 * @param array $validation Validation results from state.
	 * @return array Duplicate report.
	 */
	private static function build_duplicate_report( $validation ) {
		$email_dupes = array();
		$code_dupes  = array();

		if ( ! empty( $validation['issues'] ) ) {
			foreach ( $validation['issues'] as $issue ) {
				if ( 'email' === $issue['field'] && false !== strpos( $issue['message'], 'Duplicate' ) ) {
					$email_dupes[] = array(
						'row'   => $issue['row'],
						'value' => $issue['value'],
					);
				}
				if ( 'team_name' === $issue['field'] && false !== strpos( $issue['message'], 'Duplicate' ) ) {
					$code_dupes[] = array(
						'row'   => $issue['row'],
						'value' => $issue['value'],
					);
				}
			}
		}

		return array(
			'email_duplicates'     => $email_dupes,
			'email_duplicate_count' => count( $email_dupes ),
			'code_duplicates'      => $code_dupes,
			'code_duplicate_count' => count( $code_dupes ),
			'total_duplicates'     => count( $email_dupes ) + count( $code_dupes ),
		);
	}

	/**
	 * Build comparison summary from stored comparison data.
	 *
	 * @param array $comparison Comparison results from state.
	 * @return array Summary data.
	 */
	private static function build_comparison_summary( $comparison ) {
		return array(
			'summary'  => $comparison['summary'],
			'wp'       => array(
				'matched' => $comparison['wp']['matched'],
				'new'     => $comparison['wp']['new'],
			),
			'konx'     => array(
				'matched' => $comparison['konx']['matched'],
				'new'     => $comparison['konx']['new'],
			),
			'ca'       => array(
				'detected' => $comparison['ca']['detected'],
				'matched'  => $comparison['ca']['matched'],
			),
			'issue_count' => count( $comparison['issues'] ),
		);
	}

	/**
	 * Build sponsor hierarchy report.
	 *
	 * @param array      $scan       Scan data.
	 * @param array|null $comparison Comparison data if available.
	 * @return array Sponsor report.
	 */
	private static function build_sponsor_report( $scan, $comparison ) {
		$report = array(
			'total'          => isset( $scan['total_sponsors'] ) ? (int) $scan['total_sponsors'] : 0,
			'resolved'       => isset( $scan['resolved_sponsors'] ) ? (int) $scan['resolved_sponsors'] : 0,
			'missing'        => isset( $scan['missing_sponsors'] ) ? (int) $scan['missing_sponsors'] : 0,
			'self_referrals' => isset( $scan['self_referrals'] ) ? (int) $scan['self_referrals'] : 0,
			'reconciled'     => array(),
			'still_missing'  => array(),
		);

		if ( $comparison && ! empty( $comparison['sponsors']['details'] ) ) {
			foreach ( $comparison['sponsors']['details'] as $d ) {
				if ( 'found' === $d['status'] ) {
					$report['reconciled'][] = $d;
				} else {
					$report['still_missing'][] = $d;
				}
			}
		}

		return $report;
	}

	/**
	 * Build admin warnings for the audit report.
	 *
	 * @return array Warning strings.
	 */
	private static function build_warnings() {
		return array(
			__( 'This is a preview audit report only. No data has been imported.', 'konx-affiliate-dashboard' ),
			__( 'No WordPress users have been created.', 'konx-affiliate-dashboard' ),
			__( 'No affiliate records have been written to production tables.', 'konx-affiliate-dashboard' ),
			__( 'Migration execution is not yet available. This report is for review purposes only.', 'konx-affiliate-dashboard' ),
		);
	}

	// ------------------------------------------------------------------
	// Export Formats
	// ------------------------------------------------------------------

	/**
	 * Export audit report as CSV rows.
	 *
	 * @param array $audit Output of build().
	 * @return array Array of CSV rows.
	 */
	public static function export_csv( $audit ) {
		$rows = array();
		$rows[] = array( 'KonX Migration Audit Report' );
		$rows[] = array( 'Generated', $audit['generated_at'] );
		$rows[] = array( 'Plugin Version', $audit['plugin_ver'] );
		$rows[] = array( 'Source', strtoupper( $audit['source'] ) );
		$rows[] = array( 'Scan Date', $audit['scan_at'] );
		$rows[] = array( '' );

		// Summary.
		$rows[] = array( 'Section', 'Metric', 'Value' );
		$s = $audit['summary'];
		$rows[] = array( 'Records', 'Total', $s['records']['total'] );
		$rows[] = array( 'Records', 'Valid', $s['records']['valid'] );
		$rows[] = array( 'Records', 'With Warnings', $s['records']['warnings'] );
		$rows[] = array( 'Records', 'With Errors', $s['records']['errors'] );

		foreach ( $s['types'] as $type => $count ) {
			$rows[] = array( 'Types', ucwords( str_replace( '_', ' ', $type ) ), $count );
		}

		$rows[] = array( 'Sponsors', 'Total', $audit['sponsors']['total'] );
		$rows[] = array( 'Sponsors', 'Resolved', $audit['sponsors']['resolved'] );
		$rows[] = array( 'Sponsors', 'Missing', $audit['sponsors']['missing'] );
		$rows[] = array( 'Sponsors', 'Self-Referrals', $audit['sponsors']['self_referrals'] );

		if ( $audit['duplicates'] ) {
			$rows[] = array( 'Duplicates', 'Email', $audit['duplicates']['email_duplicate_count'] );
			$rows[] = array( 'Duplicates', 'Team Name', $audit['duplicates']['code_duplicate_count'] );
		}

		$rows[] = array( 'Readiness', 'Status', $audit['readiness']['label'] );
		foreach ( $audit['readiness']['reasons'] as $r ) {
			$rows[] = array( 'Readiness', 'Reason', $r );
		}

		$rows[] = array( 'Approval', 'Approved', $audit['approved'] ? 'Yes' : 'No' );
		if ( $audit['approved'] ) {
			$rows[] = array( 'Approval', 'By User', '#' . $audit['approved_by'] );
			$rows[] = array( 'Approval', 'Date', $audit['approved_at'] );
		}

		$rows[] = array( '' );
		foreach ( $audit['warnings'] as $w ) {
			$rows[] = array( 'WARNING', $w );
		}

		return $rows;
	}

	/**
	 * Export audit report as JSON-serializable array.
	 *
	 * @param array $audit Output of build().
	 * @return array Clean data for JSON encoding.
	 */
	public static function export_json( $audit ) {
		return array(
			'report_type'  => 'konx_migration_audit',
			'generated_at' => $audit['generated_at'],
			'plugin_ver'   => $audit['plugin_ver'],
			'source'       => $audit['source'],
			'scan_at'      => $audit['scan_at'],
			'csv_info'     => $audit['csv_info'] ? array(
				'file_name'   => $audit['csv_info']['file_name'] ?? null,
				'row_count'   => $audit['csv_info']['row_count'] ?? 0,
				'columns'     => $audit['csv_info']['columns_found'] ?? array(),
			) : null,
			'field_mapping' => $audit['field_map'],
			'records'       => $audit['summary']['records'],
			'types'         => $audit['summary']['types'],
			'sponsors'      => $audit['sponsors'],
			'validation'    => $audit['validation'],
			'duplicates'    => $audit['duplicates'],
			'comparison'    => $audit['comparison'],
			'readiness'     => $audit['readiness'],
			'projection'    => $audit['summary']['projection'],
			'approval'      => array(
				'approved'    => $audit['approved'],
				'approved_by' => $audit['approved_by'],
				'approved_at' => $audit['approved_at'],
			),
			'warnings'      => $audit['warnings'],
		);
	}
}
