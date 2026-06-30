<?php
/**
 * CSV field mapper for migration.
 *
 * Auto-detects CSV column-to-KonX-field mappings based on header names,
 * validates required fields are mapped, and applies mappings during
 * CSV parsing. Read-only — no data writes.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_CSV_Field_Mapper
 */
class Konx_CSV_Field_Mapper {

	/**
	 * KonX target fields with labels and required status.
	 *
	 * @var array
	 */
	private static $target_fields = array(
		'id'                 => array( 'label' => 'ID', 'required' => true ),
		'email'              => array( 'label' => 'Email', 'required' => true ),
		'user_fname'         => array( 'label' => 'First Name', 'required' => true ),
		'user_lname'         => array( 'label' => 'Last Name', 'required' => true ),
		'promotional_title'  => array( 'label' => 'Affiliate Type', 'required' => true ),
		'team_name'          => array( 'label' => 'Team Name', 'required' => true ),
		'referrer_team_name' => array( 'label' => 'Sponsor', 'required' => false ),
		'user_phone'         => array( 'label' => 'Phone', 'required' => false ),
		'source'             => array( 'label' => 'Source', 'required' => false ),
		'country_code'       => array( 'label' => 'Country', 'required' => false ),
		'created_at'         => array( 'label' => 'Created Date', 'required' => false ),
	);

	/**
	 * Auto-detection aliases: lowercase CSV header → target field.
	 *
	 * @var array
	 */
	private static $aliases = array(
		// ID.
		'id'                  => 'id',
		'user_id'             => 'id',
		'userid'              => 'id',
		'po10_id'             => 'id',
		'powerof10_id'        => 'id',

		// Email.
		'email'               => 'email',
		'email_address'       => 'email',
		'emailaddress'        => 'email',
		'user_email'          => 'email',
		'useremail'           => 'email',
		'e-mail'              => 'email',

		// First name.
		'user_fname'          => 'user_fname',
		'first_name'          => 'user_fname',
		'firstname'           => 'user_fname',
		'fname'               => 'user_fname',
		'given_name'          => 'user_fname',

		// Last name.
		'user_lname'          => 'user_lname',
		'last_name'           => 'user_lname',
		'lastname'            => 'user_lname',
		'lname'               => 'user_lname',
		'surname'             => 'user_lname',
		'family_name'         => 'user_lname',

		// Affiliate type.
		'promotional_title'   => 'promotional_title',
		'affiliate_type'      => 'promotional_title',
		'affiliatetype'       => 'promotional_title',
		'type'                => 'promotional_title',
		'agent_type'          => 'promotional_title',
		'role'                => 'promotional_title',

		// Team name / referral code.
		'team_name'           => 'team_name',
		'teamname'            => 'team_name',
		'team'                => 'team_name',
		'referral_code'       => 'team_name',
		'referralcode'        => 'team_name',
		'code'                => 'team_name',
		'coupon_code'         => 'team_name',

		// Sponsor.
		'referrer_team_name'  => 'referrer_team_name',
		'sponsor'             => 'referrer_team_name',
		'sponsor_name'        => 'referrer_team_name',
		'sponsorname'         => 'referrer_team_name',
		'referrer'            => 'referrer_team_name',
		'upline'              => 'referrer_team_name',
		'parent'              => 'referrer_team_name',
		'parent_team'         => 'referrer_team_name',

		// Phone.
		'user_phone'          => 'user_phone',
		'phone'               => 'user_phone',
		'phone_number'        => 'user_phone',
		'phonenumber'         => 'user_phone',
		'mobile'              => 'user_phone',
		'tel'                 => 'user_phone',

		// Source.
		'source'              => 'source',
		'origin'              => 'source',

		// Country.
		'country_code'        => 'country_code',
		'country'             => 'country_code',
		'countrycode'         => 'country_code',

		// Created date.
		'created_at'          => 'created_at',
		'createdat'           => 'created_at',
		'created'             => 'created_at',
		'registered_at'       => 'created_at',
		'registration_date'   => 'created_at',
		'date_created'        => 'created_at',
		'join_date'           => 'created_at',
	);

	/**
	 * Auto-detect field mappings from CSV headers.
	 *
	 * @param array $csv_headers Array of CSV column header strings.
	 * @return array Array of mapping results: [ { csv_column, target_field, target_label, confidence, status } ].
	 */
	public static function auto_detect( $csv_headers ) {
		$mappings    = array();
		$used_targets = array();

		foreach ( $csv_headers as $header ) {
			$lower  = strtolower( trim( $header ) );
			$target = null;
			$confidence = 'none';

			if ( isset( self::$aliases[ $lower ] ) ) {
				$candidate = self::$aliases[ $lower ];
				if ( ! isset( $used_targets[ $candidate ] ) ) {
					$target     = $candidate;
					$confidence = ( $lower === $candidate ) ? 'exact' : 'alias';
					$used_targets[ $candidate ] = true;
				}
			}

			$status = 'unmapped';
			$label  = '';
			if ( $target && isset( self::$target_fields[ $target ] ) ) {
				$label  = self::$target_fields[ $target ]['label'];
				$status = 'mapped';
			}

			$mappings[] = array(
				'csv_column'   => $header,
				'target_field' => $target,
				'target_label' => $label,
				'confidence'   => $confidence,
				'status'       => $status,
			);
		}

		return $mappings;
	}

	/**
	 * Validate that all required fields have mappings.
	 *
	 * @param array $mappings Output of auto_detect() or stored mappings.
	 * @return array { valid, missing[], mapped_required, mapped_optional, unmapped[] }.
	 */
	public static function validate_mappings( $mappings ) {
		$mapped_targets = array();
		foreach ( $mappings as $m ) {
			if ( ! empty( $m['target_field'] ) ) {
				$mapped_targets[ $m['target_field'] ] = $m['csv_column'];
			}
		}

		$missing          = array();
		$mapped_required  = 0;
		$mapped_optional  = 0;
		$unmapped         = array();

		foreach ( self::$target_fields as $field => $def ) {
			if ( isset( $mapped_targets[ $field ] ) ) {
				if ( $def['required'] ) {
					$mapped_required++;
				} else {
					$mapped_optional++;
				}
			} elseif ( $def['required'] ) {
				$missing[] = $def['label'];
			}
		}

		foreach ( $mappings as $m ) {
			if ( 'unmapped' === $m['status'] ) {
				$unmapped[] = $m['csv_column'];
			}
		}

		// Check for duplicate targets.
		$target_counts = array();
		foreach ( $mappings as $m ) {
			if ( ! empty( $m['target_field'] ) ) {
				$target_counts[ $m['target_field'] ] = ( $target_counts[ $m['target_field'] ] ?? 0 ) + 1;
			}
		}
		$duplicates = array_filter( $target_counts, function ( $c ) { return $c > 1; } );

		return array(
			'valid'           => empty( $missing ) && empty( $duplicates ),
			'missing'         => $missing,
			'duplicates'      => array_keys( $duplicates ),
			'mapped_required' => $mapped_required,
			'mapped_optional' => $mapped_optional,
			'unmapped'        => $unmapped,
			'total_required'  => count( array_filter( self::$target_fields, function ( $d ) { return $d['required']; } ) ),
		);
	}

	/**
	 * Get the target field definitions.
	 *
	 * @return array Target field key => { label, required }.
	 */
	public static function get_target_fields() {
		return self::$target_fields;
	}

	/**
	 * Build a column index map from validated mappings.
	 *
	 * Returns target_field => csv_column_index for use during CSV parsing.
	 *
	 * @param array $mappings  The mapping array.
	 * @param array $headers   The CSV header row.
	 * @return array target_field => column_index.
	 */
	public static function build_column_index( $mappings, $headers ) {
		$header_lower = array_map( 'strtolower', array_map( 'trim', $headers ) );
		$index        = array();

		foreach ( $mappings as $m ) {
			if ( ! empty( $m['target_field'] ) && ! empty( $m['csv_column'] ) ) {
				$col_lower = strtolower( trim( $m['csv_column'] ) );
				$pos       = array_search( $col_lower, $header_lower, true );
				if ( false !== $pos ) {
					$index[ $m['target_field'] ] = $pos;
				}
			}
		}

		return $index;
	}
}
