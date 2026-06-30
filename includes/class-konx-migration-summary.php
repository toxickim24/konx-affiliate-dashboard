<?php
/**
 * Migration summary builder.
 *
 * Consolidates scan, validation, type mapping, and sponsor analysis
 * into a unified summary for the migration wizard. Read-only.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Migration_Summary
 */
class Konx_Migration_Summary {

	/**
	 * Readiness statuses.
	 */
	const READY          = 'ready';
	const NEEDS_ATTENTION = 'needs_attention';
	const BLOCKED        = 'blocked';

	/**
	 * Build a complete migration summary from stored state and live data.
	 *
	 * @param array $state The konx_migration_state option value.
	 * @return array Full summary data.
	 */
	public static function build( $state ) {
		$scan       = $state['scan'] ?? null;
		$dry_run    = $state['dry_run'] ?? null;
		$validation = $state['validation_results'] ?? null;

		// --- Record counts ---
		$records = array(
			'total'    => $scan ? (int) $scan['po10_users'] : 0,
			'valid'    => $validation ? (int) $validation['summary']['valid'] : 0,
			'warnings' => $validation ? (int) $validation['summary']['with_warning'] : 0,
			'errors'   => $validation ? (int) $validation['summary']['with_error'] : 0,
		);

		// --- Affiliate type breakdown ---
		$types = array();
		if ( $dry_run && ! empty( $dry_run['by_type'] ) ) {
			$types = $dry_run['by_type'];
		} elseif ( $scan ) {
			// Build from engine if dry-run not available.
			$engine = new Konx_Migration_Engine();
			$ta     = $engine->analyze_affiliate_types();
			foreach ( $ta['types'] as $t ) {
				$norm = $t['normalized'];
				$types[ $norm ] = ( $types[ $norm ] ?? 0 ) + $t['count'];
			}
		}

		// --- Sponsor summary ---
		$sponsors = array(
			'total'    => $scan ? (int) $scan['total_sponsors'] : 0,
			'resolved' => $scan ? (int) $scan['resolved_sponsors'] : 0,
			'missing'  => $scan ? (int) $scan['missing_sponsors'] : 0,
			'self_ref' => $scan ? (int) $scan['self_referrals'] : 0,
		);

		// --- Validation summary ---
		$val_summary = array(
			'error_count'   => $validation ? (int) $validation['summary']['error_count'] : 0,
			'warning_count' => $validation ? (int) $validation['summary']['warning_count'] : 0,
			'top_categories' => array(),
		);
		if ( $validation && ! empty( $validation['by_category'] ) ) {
			foreach ( $validation['by_category'] as $field => $issues ) {
				$val_summary['top_categories'][ $field ] = count( $issues );
			}
			arsort( $val_summary['top_categories'] );
			$val_summary['top_categories'] = array_slice( $val_summary['top_categories'], 0, 5, true );
		}

		// --- Dry-run projection ---
		$projection = array(
			'to_create'      => $dry_run ? (int) $dry_run['will_create_affiliates'] : 0,
			'to_skip'        => $dry_run ? (int) $dry_run['will_skip'] : 0,
			'wp_users'       => $dry_run ? (int) $dry_run['will_create_users'] : 0,
			'sponsor_links'  => $dry_run ? (int) $dry_run['will_link_sponsors'] : 0,
			'orphan_sponsors' => $dry_run ? (int) $dry_run['orphan_sponsors'] : 0,
			'batches'        => $dry_run ? (int) $dry_run['estimated_batches'] : 0,
		);

		// --- Readiness ---
		$readiness = self::calculate_readiness( $records, $val_summary, $scan );

		return array(
			'records'    => $records,
			'types'      => $types,
			'sponsors'   => $sponsors,
			'validation' => $val_summary,
			'projection' => $projection,
			'readiness'  => $readiness,
			'has_scan'   => ! empty( $scan ),
			'has_dryrun' => ! empty( $dry_run ),
			'has_validation' => ! empty( $validation ),
		);
	}

	/**
	 * Calculate migration readiness status.
	 *
	 * @param array $records    Record counts.
	 * @param array $validation Validation summary.
	 * @param array $scan       Scan data.
	 * @return array { status, label, reasons[] }.
	 */
	public static function calculate_readiness( $records, $validation, $scan ) {
		$reasons = array();

		// Blocked conditions.
		if ( empty( $scan ) ) {
			return array(
				'status'  => self::BLOCKED,
				'label'   => __( 'Not Started', 'konx-affiliate-dashboard' ),
				'reasons' => array( __( 'No data scan has been performed.', 'konx-affiliate-dashboard' ) ),
			);
		}

		if ( 0 === $records['total'] ) {
			return array(
				'status'  => self::BLOCKED,
				'label'   => __( 'Blocked', 'konx-affiliate-dashboard' ),
				'reasons' => array( __( 'No records found in source data.', 'konx-affiliate-dashboard' ) ),
			);
		}

		// Warning conditions.
		if ( $records['errors'] > 0 ) {
			$reasons[] = sprintf(
				__( '%d records have errors and will be skipped.', 'konx-affiliate-dashboard' ),
				$records['errors']
			);
		}

		if ( $records['warnings'] > 0 ) {
			$reasons[] = sprintf(
				__( '%d records have warnings.', 'konx-affiliate-dashboard' ),
				$records['warnings']
			);
		}

		if ( ! empty( $scan['missing_sponsors'] ) && $scan['missing_sponsors'] > 50 ) {
			$reasons[] = sprintf(
				__( '%d orphan sponsor references.', 'konx-affiliate-dashboard' ),
				$scan['missing_sponsors']
			);
		}

		if ( ! empty( $scan['duplicate_codes'] ) && $scan['duplicate_codes'] > 0 ) {
			$reasons[] = sprintf(
				__( '%d duplicate referral codes.', 'konx-affiliate-dashboard' ),
				$scan['duplicate_codes']
			);
		}

		if ( ! empty( $reasons ) ) {
			return array(
				'status'  => self::NEEDS_ATTENTION,
				'label'   => __( 'Needs Attention', 'konx-affiliate-dashboard' ),
				'reasons' => $reasons,
			);
		}

		return array(
			'status'  => self::READY,
			'label'   => __( 'Ready for Review', 'konx-affiliate-dashboard' ),
			'reasons' => array(),
		);
	}

	/**
	 * Generate CSV export data for the summary.
	 *
	 * @param array $summary Output of build().
	 * @return array Array of CSV rows.
	 */
	public static function export_csv( $summary ) {
		$rows = array();
		$rows[] = array( 'Section', 'Metric', 'Value' );

		// Records.
		$rows[] = array( 'Records', 'Total', $summary['records']['total'] );
		$rows[] = array( 'Records', 'Valid', $summary['records']['valid'] );
		$rows[] = array( 'Records', 'Warnings', $summary['records']['warnings'] );
		$rows[] = array( 'Records', 'Errors', $summary['records']['errors'] );

		// Types.
		foreach ( $summary['types'] as $type => $count ) {
			$rows[] = array( 'Affiliate Types', ucwords( str_replace( '_', ' ', $type ) ), $count );
		}

		// Sponsors.
		$rows[] = array( 'Sponsors', 'Total References', $summary['sponsors']['total'] );
		$rows[] = array( 'Sponsors', 'Resolved', $summary['sponsors']['resolved'] );
		$rows[] = array( 'Sponsors', 'Missing/Orphaned', $summary['sponsors']['missing'] );
		$rows[] = array( 'Sponsors', 'Self-Referrals', $summary['sponsors']['self_ref'] );

		// Validation.
		$rows[] = array( 'Validation', 'Total Errors', $summary['validation']['error_count'] );
		$rows[] = array( 'Validation', 'Total Warnings', $summary['validation']['warning_count'] );
		foreach ( $summary['validation']['top_categories'] as $cat => $cnt ) {
			$rows[] = array( 'Validation', 'Category: ' . $cat, $cnt );
		}

		// Projection.
		$rows[] = array( 'Projection', 'Affiliates to Create', $summary['projection']['to_create'] );
		$rows[] = array( 'Projection', 'Records to Skip', $summary['projection']['to_skip'] );
		$rows[] = array( 'Projection', 'WP Users to Create', $summary['projection']['wp_users'] );
		$rows[] = array( 'Projection', 'Sponsor Links', $summary['projection']['sponsor_links'] );

		// Readiness.
		$rows[] = array( 'Readiness', 'Status', $summary['readiness']['label'] );
		foreach ( $summary['readiness']['reasons'] as $r ) {
			$rows[] = array( 'Readiness', 'Reason', $r );
		}

		return $rows;
	}
}
