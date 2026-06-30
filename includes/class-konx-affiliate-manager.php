<?php
/**
 * Affiliate profile management.
 *
 * Handles creation, retrieval, and updates of affiliate records
 * in the wp_konx_affiliates table. Keeps custom table and wp_usermeta
 * in sync for every write operation.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Affiliate_Manager
 */
class Konx_Affiliate_Manager {

	/**
	 * Valid affiliate statuses.
	 *
	 * @var array
	 */
	private static $valid_statuses = array( 'active', 'inactive', 'suspended', 'pending' );

	/**
	 * Valid affiliate types (database values).
	 *
	 * @var array
	 */
	private static $valid_types = array( 'business', 'team_agent', 'marketing_agent', 'sales_agent' );

	// ------------------------------------------------------------------
	// Create
	// ------------------------------------------------------------------

	/**
	 * Create a new affiliate profile for a WordPress user.
	 *
	 * Inserts a row into wp_konx_affiliates, assigns the matching
	 * WordPress role, and stores lookup meta in wp_usermeta.
	 *
	 * @param int    $user_id        WordPress user ID.
	 * @param string $affiliate_type One of the valid affiliate types.
	 * @param array  $args {
	 *     Optional. Additional profile fields.
	 *
	 *     @type int    $parent_affiliate_id Referring affiliate ID.
	 *     @type string $payment_email       Wise payout email.
	 *     @type string $external_id         External system ID (e.g. 'po10_2305').
	 *     @type string $phone               Phone number.
	 *     @type string $referral_code       Override the auto-generated referral code.
	 *     @type string $notes               Admin notes.
	 * }
	 * @return int|WP_Error The new affiliate ID, or WP_Error on failure.
	 */
	public static function create_affiliate_profile( $user_id, $affiliate_type = 'sales_agent', $args = array() ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'invalid_user', __( 'Invalid WordPress user ID.', 'konx-affiliate-dashboard' ) );
		}

		if ( ! in_array( $affiliate_type, self::$valid_types, true ) ) {
			return new \WP_Error( 'invalid_type', __( 'Invalid affiliate type.', 'konx-affiliate-dashboard' ) );
		}

		// Prevent duplicate profiles.
		$existing = self::get_affiliate_by_user( $user_id );
		if ( $existing ) {
			return new \WP_Error( 'duplicate_profile', __( 'This user already has an affiliate profile.', 'konx-affiliate-dashboard' ) );
		}

		// Allow callers to supply a referral code (e.g. imported PO10 team_name).
		if ( ! empty( $args['referral_code'] ) ) {
			$referral_code = sanitize_text_field( $args['referral_code'] );
			// Verify uniqueness.
			if ( self::get_affiliate_by_referral_code( $referral_code ) ) {
				return new \WP_Error( 'duplicate_code', __( 'This referral code is already in use.', 'konx-affiliate-dashboard' ) );
			}
		} else {
			$referral_code = self::generate_referral_code();
		}

		$table = $wpdb->prefix . 'konx_affiliates';
		$now   = current_time( 'mysql', true );

		$data = array(
			'user_id'        => $user_id,
			'affiliate_type' => $affiliate_type,
			'referral_code'  => $referral_code,
			'status'         => 'active',
			'completed_sales' => 0,
			'cached_balance' => 0.00,
			'registered_at'  => $now,
			'updated_at'     => $now,
		);

		if ( ! empty( $args['parent_affiliate_id'] ) ) {
			$data['parent_affiliate_id'] = absint( $args['parent_affiliate_id'] );
		}
		if ( ! empty( $args['payment_email'] ) ) {
			$data['payment_email'] = sanitize_email( $args['payment_email'] );
		}
		if ( ! empty( $args['external_id'] ) ) {
			$data['external_id'] = sanitize_text_field( mb_substr( $args['external_id'], 0, 50 ) );
		}
		if ( ! empty( $args['phone'] ) ) {
			$data['phone'] = sanitize_text_field( mb_substr( $args['phone'], 0, 30 ) );
		}
		if ( isset( $args['notes'] ) && '' !== $args['notes'] ) {
			$data['notes'] = sanitize_textarea_field( $args['notes'] );
		}

		$formats = array(
			'%d', // user_id
			'%s', // affiliate_type
			'%s', // referral_code
			'%s', // status
			'%d', // completed_sales
			'%f', // cached_balance
			'%s', // registered_at
			'%s', // updated_at
		);

		if ( isset( $data['parent_affiliate_id'] ) ) {
			$formats[] = '%d';
		}
		if ( isset( $data['payment_email'] ) ) {
			$formats[] = '%s';
		}
		if ( isset( $data['external_id'] ) ) {
			$formats[] = '%s';
		}
		if ( isset( $data['phone'] ) ) {
			$formats[] = '%s';
		}
		if ( isset( $data['notes'] ) ) {
			$formats[] = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( $table, $data, $formats );

		if ( false === $inserted ) {
			return new \WP_Error( 'db_insert_failed', __( 'Failed to create affiliate profile.', 'konx-affiliate-dashboard' ) );
		}

		$affiliate_id = (int) $wpdb->insert_id;

		// Assign the WordPress role.
		$role = Konx_Roles::affiliate_type_to_role( $affiliate_type );
		if ( $role ) {
			$user = get_userdata( $user_id );
			$user->add_role( $role );
		}

		// Store lookup meta.
		update_user_meta( $user_id, 'konx_affiliate_id', $affiliate_id );
		update_user_meta( $user_id, 'konx_affiliate_type', $affiliate_type );
		update_user_meta( $user_id, 'konx_referral_code', $referral_code );

		return $affiliate_id;
	}

	// ------------------------------------------------------------------
	// Read
	// ------------------------------------------------------------------

	/**
	 * Get an affiliate record by its primary ID.
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 * @return object|null The affiliate row, or null if not found.
	 */
	public static function get_affiliate( $affiliate_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_affiliates';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $affiliate_id ) )
		);
	}

	/**
	 * Get an affiliate record by WordPress user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return object|null The affiliate row, or null if not found.
	 */
	public static function get_affiliate_by_user( $user_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_affiliates';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", absint( $user_id ) )
		);
	}

	/**
	 * Get an affiliate record by referral code.
	 *
	 * @param string $referral_code The 8-character referral code.
	 * @return object|null The affiliate row, or null if not found.
	 */
	public static function get_affiliate_by_referral_code( $referral_code ) {
		global $wpdb;

		$referral_code = strtoupper( sanitize_text_field( $referral_code ) );
		if ( empty( $referral_code ) ) {
			return null;
		}

		$table = $wpdb->prefix . 'konx_affiliates';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE referral_code = %s", $referral_code )
		);
	}

	// ------------------------------------------------------------------
	// Update
	// ------------------------------------------------------------------

	/**
	 * Update an affiliate's type.
	 *
	 * This is the single code path for type changes. It updates the
	 * custom table, user meta, and WordPress role atomically.
	 *
	 * @param int    $affiliate_id The affiliate table ID.
	 * @param string $new_type     The new affiliate type.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function update_affiliate_type( $affiliate_id, $new_type ) {
		global $wpdb;

		if ( ! in_array( $new_type, self::$valid_types, true ) ) {
			return new \WP_Error( 'invalid_type', __( 'Invalid affiliate type.', 'konx-affiliate-dashboard' ) );
		}

		$affiliate = self::get_affiliate( $affiliate_id );
		if ( ! $affiliate ) {
			return new \WP_Error( 'not_found', __( 'Affiliate not found.', 'konx-affiliate-dashboard' ) );
		}

		$old_type = $affiliate->affiliate_type;
		if ( $old_type === $new_type ) {
			return true;
		}

		$table = $wpdb->prefix . 'konx_affiliates';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			array(
				'affiliate_type' => $new_type,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => $affiliate_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new \WP_Error( 'db_update_failed', __( 'Failed to update affiliate type.', 'konx-affiliate-dashboard' ) );
		}

		// Sync user meta.
		update_user_meta( $affiliate->user_id, 'konx_affiliate_type', $new_type );

		// Swap WordPress roles.
		$user     = get_userdata( $affiliate->user_id );
		$old_role = Konx_Roles::affiliate_type_to_role( $old_type );
		$new_role = Konx_Roles::affiliate_type_to_role( $new_type );

		if ( $user && $old_role ) {
			$user->remove_role( $old_role );
		}
		if ( $user && $new_role ) {
			$user->add_role( $new_role );
		}

		// Log to audit log.
		self::log_audit(
			'affiliate_type_changed',
			'affiliate',
			$affiliate_id,
			$old_type,
			$new_type,
			sprintf( 'Affiliate type changed from %s to %s.', $old_type, $new_type )
		);

		return true;
	}

	/**
	 * Update an affiliate's status.
	 *
	 * @param int    $affiliate_id The affiliate table ID.
	 * @param string $new_status   One of: active, inactive, suspended, pending.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function update_affiliate_status( $affiliate_id, $new_status ) {
		global $wpdb;

		if ( ! in_array( $new_status, self::$valid_statuses, true ) ) {
			return new \WP_Error( 'invalid_status', __( 'Invalid affiliate status.', 'konx-affiliate-dashboard' ) );
		}

		$affiliate = self::get_affiliate( $affiliate_id );
		if ( ! $affiliate ) {
			return new \WP_Error( 'not_found', __( 'Affiliate not found.', 'konx-affiliate-dashboard' ) );
		}

		$old_status = $affiliate->status;
		if ( $old_status === $new_status ) {
			return true;
		}

		$table = $wpdb->prefix . 'konx_affiliates';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			array(
				'status'     => $new_status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $affiliate_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new \WP_Error( 'db_update_failed', __( 'Failed to update affiliate status.', 'konx-affiliate-dashboard' ) );
		}

		self::log_audit(
			'affiliate_status_changed',
			'affiliate',
			$affiliate_id,
			$old_status,
			$new_status,
			sprintf( 'Affiliate status changed from %s to %s.', $old_status, $new_status )
		);

		return true;
	}

	/**
	 * Increment the completed sales count for an affiliate.
	 *
	 * Called inside the same transaction as commission creation.
	 * The new count should match MAX(sale_sequence) for this affiliate.
	 *
	 * @param int $affiliate_id The affiliate table ID.
	 * @param int $new_count    The new completed sales count.
	 * @return bool True on success, false on failure.
	 */
	public static function increment_sales_count( $affiliate_id, $new_count ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_affiliates';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array(
				'completed_sales' => absint( $new_count ),
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $affiliate_id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Update the cached wallet balance for an affiliate.
	 *
	 * Called inside the same transaction as wallet ledger writes.
	 *
	 * @param int   $affiliate_id The affiliate table ID.
	 * @param float $new_balance  The new cached balance.
	 * @return bool True on success, false on failure.
	 */
	public static function update_cached_balance( $affiliate_id, $new_balance ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_affiliates';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array(
				'cached_balance' => number_format( (float) $new_balance, 2, '.', '' ),
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $affiliate_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Update an affiliate's payment email.
	 *
	 * @param int    $affiliate_id The affiliate table ID.
	 * @param string $email        The Wise payout email.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function update_payment_email( $affiliate_id, $email ) {
		global $wpdb;

		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'Invalid email address.', 'konx-affiliate-dashboard' ) );
		}

		$table = $wpdb->prefix . 'konx_affiliates';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array(
				'payment_email' => $email,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $affiliate_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result ? true : new \WP_Error( 'db_update_failed', __( 'Failed to update payment email.', 'konx-affiliate-dashboard' ) );
	}

	// ------------------------------------------------------------------
	// Referral Code Generation
	// ------------------------------------------------------------------

	/**
	 * Generate a unique 8-character uppercase alphanumeric referral code.
	 *
	 * Uses wp_generate_password for cryptographic randomness, then
	 * verifies uniqueness against the database.
	 *
	 * @return string The unique referral code.
	 */
	private static function generate_referral_code() {
		global $wpdb;

		$table      = $wpdb->prefix . 'konx_affiliates';
		$characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // No 0/O/1/I to avoid confusion.
		$length     = 8;
		$max_attempts = 10;

		for ( $i = 0; $i < $max_attempts; $i++ ) {
			$code = '';
			$bytes = random_bytes( $length );
			$char_count = strlen( $characters );

			for ( $j = 0; $j < $length; $j++ ) {
				$code .= $characters[ ord( $bytes[ $j ] ) % $char_count ];
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE referral_code = %s", $code )
			);

			if ( '0' === $exists ) {
				return $code;
			}
		}

		// Extremely unlikely fallback — append timestamp fragment.
		return $code . substr( (string) time(), -4 );
	}

	// ------------------------------------------------------------------
	// Audit Helper
	// ------------------------------------------------------------------

	/**
	 * Insert an audit log entry.
	 *
	 * @param string      $event_type  Event type identifier.
	 * @param string      $object_type Object type (e.g. 'affiliate').
	 * @param int         $object_id   Object ID.
	 * @param string|null $old_value   Previous value.
	 * @param string|null $new_value   New value.
	 * @param string      $description Human-readable description.
	 */
	private static function log_audit( $event_type, $object_type, $object_id, $old_value, $new_value, $description ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_audit_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'event_type'  => sanitize_text_field( $event_type ),
				'object_type' => sanitize_text_field( $object_type ),
				'object_id'   => absint( $object_id ),
				'actor_id'    => get_current_user_id() ?: null,
				'old_value'   => $old_value,
				'new_value'   => $new_value,
				'description' => sanitize_text_field( $description ),
				'ip_address'  => self::get_client_ip(),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get the current client IP address.
	 *
	 * @return string|null The IP address, or null if unavailable.
	 */
	private static function get_client_ip() {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return null;
	}
}
