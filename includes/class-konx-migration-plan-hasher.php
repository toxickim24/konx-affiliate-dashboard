<?php
/**
 * Deterministic hashing utility for migration plan integrity.
 *
 * Produces stable SHA-256 fingerprints for:
 *   1. Source CSV files (raw byte hash)
 *   2. Final Migration Plan decision sets (canonical JSON hash)
 *
 * Hashing rules:
 * - Decisions are sorted by source_record_id before hashing so that
 *   insertion order in the state array does not affect the hash.
 * - Only the logically meaningful fields are included. Transient
 *   metadata (timestamps, reasons text, confidence labels) is excluded.
 * - All strings are trimmed and lowercased where case is semantically
 *   irrelevant (email, team names). Enum fields (action, affiliate_type)
 *   are stored as-is from the canonical set.
 * - Integers are cast explicitly to int; nulls are preserved as JSON null.
 * - PHP's json_encode is used (not serialize) to avoid cross-version
 *   instability in PHP serialization format.
 *
 * No WordPress dependencies. Pure PHP. Testable standalone.
 *
 * @package KonxAffiliateDashboard
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Migration_Plan_Hasher
 */
class Konx_Migration_Plan_Hasher {

	/**
	 * Algorithm used for source file hashing.
	 */
	const SOURCE_HASH_ALGORITHM = 'sha256';

	/**
	 * Algorithm used for plan hashing.
	 */
	const PLAN_HASH_ALGORITHM = 'sha256';

	/**
	 * Canonical field set included in the plan hash, in fixed order.
	 *
	 * Changing this list changes the hash for ALL plans — treat as stable.
	 *
	 * Excluded intentionally:
	 * - reasons / val_status / confidence / manual_review — human-readable annotations
	 * - sponsor_status — informational, not decision-determinant
	 * - match_method   — records how we found the user, not what we decided
	 * - ca_bridge      — bool flag already implied by ca_id
	 * - konx_id        — only present on skip/link_konx; skip records are hashed too
	 * - val_errors / val_warnings / dm_decision — FMP metadata, not the final decision
	 * - first_name / last_name / phone / sponsor — source data, not actionable fields
	 *
	 * @var array
	 */
	private static $canonical_fields = array(
		'source_record_id',
		'source_email',
		'action',
		'affiliate_type',
		'team_name',
		'wp_user_id',
		'coupon_affiliate_id',
	);

	// ------------------------------------------------------------------
	// Source File Hashing
	// ------------------------------------------------------------------

	/**
	 * Compute the SHA-256 hash of a raw source CSV file.
	 *
	 * @param string $file_path Absolute path to the CSV file.
	 * @return string|null 64-char hex hash, or null if file is unreadable.
	 */
	public static function hash_source_file( $file_path ) {
		if ( ! is_string( $file_path ) || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return null;
		}

		$hash = hash_file( self::SOURCE_HASH_ALGORITHM, $file_path );
		return false === $hash ? null : $hash;
	}

	/**
	 * Compute the SHA-256 hash of a raw string (e.g. in-memory CSV content).
	 *
	 * @param string $content Raw content.
	 * @return string 64-char hex hash.
	 */
	public static function hash_source_content( $content ) {
		return hash( self::SOURCE_HASH_ALGORITHM, (string) $content );
	}

	// ------------------------------------------------------------------
	// Final Migration Plan Hashing
	// ------------------------------------------------------------------

	/**
	 * Compute a deterministic SHA-256 hash of a Final Migration Plan.
	 *
	 * The input array is the decisions[] array from either:
	 *   - $state['final_migration_plan']['decisions']
	 *   - $state['decision_matrix']['decisions']
	 *
	 * The hash is stable as long as the set of (source_record_id, action,
	 * affiliate_type, team_name, wp_user_id, coupon_affiliate_id) does not
	 * change. Reordering the records in the input array does NOT change
	 * the hash because they are sorted by source_record_id before encoding.
	 *
	 * @param array $decisions Array of decision records from the FMP.
	 * @return string 64-char hex SHA-256 hash.
	 */
	public static function hash_final_plan( array $decisions ) {
		$canonical = self::canonicalize_decisions( $decisions );
		$json      = json_encode( $canonical ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return hash( self::PLAN_HASH_ALGORITHM, (string) $json );
	}

	/**
	 * Verify that a decisions array matches an expected hash.
	 *
	 * Uses hash_equals() to prevent timing-based comparisons.
	 *
	 * @param array  $decisions     Decision records.
	 * @param string $expected_hash Expected 64-char hex hash.
	 * @return bool True if hashes match.
	 */
	public static function verify_plan_hash( array $decisions, $expected_hash ) {
		if ( ! is_string( $expected_hash ) || 64 !== strlen( $expected_hash ) ) {
			return false;
		}

		return hash_equals( $expected_hash, self::hash_final_plan( $decisions ) );
	}

	/**
	 * Produce the canonical representation of a decisions array.
	 *
	 * Sorts by source_record_id, then extracts only the canonical field
	 * set in fixed key order. Returns an array suitable for json_encode.
	 *
	 * @param array $decisions Raw decisions from FMP.
	 * @return array Canonicalized records.
	 */
	public static function canonicalize_decisions( array $decisions ) {
		// Sort ascending by PO10 ID (source_record_id) for order-independence.
		usort(
			$decisions,
			function ( $a, $b ) {
				return (int) ( $a['po10_id'] ?? 0 ) - (int) ( $b['po10_id'] ?? 0 );
			}
		);

		$canonical = array();

		foreach ( $decisions as $d ) {
			// Normalize each canonical field in fixed insertion order.
			// Insertion order defines JSON key order in PHP assoc arrays.
			$record = array(
				'source_record_id'    => (int) ( $d['po10_id'] ?? 0 ),
				'source_email'        => strtolower( trim( (string) ( $d['email'] ?? '' ) ) ),
				'action'              => (string) ( $d['decision'] ?? '' ),
				'affiliate_type'      => (string) ( $d['affiliate_type'] ?? 'sales_agent' ),
				'team_name'           => trim( (string) ( $d['team_name'] ?? '' ) ),
				'wp_user_id'          => isset( $d['wp_user_id'] ) && null !== $d['wp_user_id']
					? (int) $d['wp_user_id']
					: null,
				'coupon_affiliate_id' => isset( $d['ca_id'] ) && null !== $d['ca_id']
					? (int) $d['ca_id']
					: null,
			);

			$canonical[] = $record;
		}

		return $canonical;
	}

	/**
	 * Count decisions by action type.
	 *
	 * @param array $decisions Decision records.
	 * @return array { create, link_wp, link_ca, review, invalid, skip, total }.
	 */
	public static function count_decisions( array $decisions ) {
		$counts = array(
			'create'   => 0,
			'link_wp'  => 0,
			'link_ca'  => 0,
			'review'   => 0,
			'invalid'  => 0,
			'skip'     => 0,
			'total'    => count( $decisions ),
		);

		foreach ( $decisions as $d ) {
			$action = (string) ( $d['decision'] ?? '' );
			if ( isset( $counts[ $action ] ) ) {
				$counts[ $action ]++;
			}
		}

		return $counts;
	}
}
