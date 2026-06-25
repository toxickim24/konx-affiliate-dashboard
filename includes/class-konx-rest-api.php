<?php
/**
 * REST API for KonX Affiliates.
 *
 * Registers the konx-affiliates/v1 namespace and provides the
 * POST /users endpoint for creating WordPress users and affiliate
 * profiles from external systems (PowerOf10).
 *
 * Authentication via X-KONX-API-Key header. All requests are logged
 * to wp_konx_api_log via Konx_Api_Helper.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_REST_API
 */
class Konx_REST_API {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'konx-affiliates/v1';

	/**
	 * Valid affiliate types accepted by the API.
	 *
	 * @var array
	 */
	private static $valid_types = array( 'business', 'team_agent', 'marketing_agent', 'sales_agent' );

	/**
	 * Valid affiliate statuses.
	 *
	 * @var array
	 */
	private static $valid_statuses = array( 'active', 'pending', 'suspended', 'inactive' );

	/**
	 * Type normalization map for common variants.
	 *
	 * @var array
	 */
	private static $type_normalize = array(
		'salesagent'          => 'sales_agent',
		'sales agent'         => 'sales_agent',
		'teamagent'           => 'team_agent',
		'team agent'          => 'team_agent',
		'marketingagent'      => 'marketing_agent',
		'marketing agent'     => 'marketing_agent',
		'business_affiliate'  => 'business',
		'business affiliate'  => 'business',
	);

	/**
	 * Register REST routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register all REST API routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/users',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_user' ),
				'permission_callback' => array( __CLASS__, 'check_api_key' ),
			)
		);
	}

	// ------------------------------------------------------------------
	// Authentication
	// ------------------------------------------------------------------

	/**
	 * Permission callback: validate the API key from request header.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error True if authenticated, WP_Error otherwise.
	 */
	public static function check_api_key( $request ) {
		$key = Konx_Api_Helper::get_key_from_request( $request );

		if ( empty( $key ) ) {
			Konx_Api_Helper::log_request( null, '/users', 'POST', 401, 'Missing API key' );
			return new \WP_Error(
				'missing_api_key',
				__( 'API key is required. Send it via the X-KONX-API-Key header.', 'konx-affiliate-dashboard' ),
				array( 'status' => 401 )
			);
		}

		$key_row = Konx_Api_Helper::validate_key( $key );

		if ( ! $key_row ) {
			Konx_Api_Helper::log_request( null, '/users', 'POST', 401, 'Invalid API key' );
			return new \WP_Error(
				'invalid_api_key',
				__( 'Invalid or revoked API key.', 'konx-affiliate-dashboard' ),
				array( 'status' => 401 )
			);
		}

		// Store the validated key row on the request for use in the callback.
		$request->set_param( '_konx_api_key_id', (int) $key_row->id );

		return true;
	}

	// ------------------------------------------------------------------
	// POST /users
	// ------------------------------------------------------------------

	/**
	 * Handle POST /users — create a WordPress user and affiliate profile.
	 *
	 * Idempotent: safe to call multiple times with the same external_id.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error The response.
	 */
	public static function create_user( $request ) {
		$api_key_id = $request->get_param( '_konx_api_key_id' );
		$params     = $request->get_json_params();

		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		// --- Validate required fields ---
		$validation = self::validate_payload( $params );
		if ( is_wp_error( $validation ) ) {
			Konx_Api_Helper::log_request( $api_key_id, '/users', 'POST', $validation->get_error_data()['status'], $validation->get_error_message() );
			return $validation;
		}

		// --- Normalize fields ---
		$external_id    = sanitize_text_field( $params['external_id'] );
		$email          = sanitize_email( $params['email'] );
		$first_name     = sanitize_text_field( $params['first_name'] );
		$last_name      = sanitize_text_field( $params['last_name'] );
		$affiliate_type = self::normalize_type( sanitize_text_field( $params['affiliate_type'] ) );
		$referral_code  = sanitize_text_field( $params['referral_code'] );
		$password       = isset( $params['password'] ) && '' !== $params['password'] ? $params['password'] : null;
		$phone          = isset( $params['phone'] ) ? sanitize_text_field( mb_substr( $params['phone'], 0, 30 ) ) : null;
		$source         = isset( $params['source'] ) ? sanitize_text_field( $params['source'] ) : null;
		$country        = isset( $params['country'] ) ? sanitize_text_field( $params['country'] ) : null;
		$status         = isset( $params['status'] ) ? sanitize_text_field( $params['status'] ) : 'active';
		$parent_code    = isset( $params['parent_referral_code'] ) ? sanitize_text_field( $params['parent_referral_code'] ) : null;

		// Validate normalized type is valid.
		if ( ! in_array( $affiliate_type, self::$valid_types, true ) ) {
			return self::error_response( $api_key_id, 'invalid_affiliate_type', __( 'Invalid affiliate type after normalization.', 'konx-affiliate-dashboard' ), 422 );
		}

		// Validate status.
		if ( ! in_array( $status, self::$valid_statuses, true ) ) {
			$status = 'active';
		}

		// --- Idempotency Check 1: external_id ---
		$existing_by_ext = self::find_by_external_id( $external_id );
		if ( $existing_by_ext ) {
			Konx_Api_Helper::log_request( $api_key_id, '/users', 'POST', 200, null );
			return new \WP_REST_Response( array(
				'success'      => true,
				'created'      => false,
				'reason'       => 'external_id_exists',
				'user_id'      => (int) $existing_by_ext->user_id,
				'affiliate_id' => (int) $existing_by_ext->id,
				'external_id'  => $existing_by_ext->external_id,
				'referral_code' => $existing_by_ext->referral_code,
			), 200 );
		}

		// --- Idempotency Check 2: email ---
		$existing_wp_user = get_user_by( 'email', $email );
		if ( $existing_wp_user ) {
			$existing_affiliate = Konx_Affiliate_Manager::get_affiliate_by_user( $existing_wp_user->ID );
			if ( $existing_affiliate ) {
				Konx_Api_Helper::log_request( $api_key_id, '/users', 'POST', 200, null );
				return new \WP_REST_Response( array(
					'success'      => true,
					'created'      => false,
					'reason'       => 'email_has_affiliate',
					'user_id'      => (int) $existing_wp_user->ID,
					'affiliate_id' => (int) $existing_affiliate->id,
					'external_id'  => $existing_affiliate->external_id,
					'referral_code' => $existing_affiliate->referral_code,
				), 200 );
			}

			// WP user exists but no affiliate — create profile only.
			$user_id = $existing_wp_user->ID;
		} else {
			// --- Create WordPress user ---
			$user_id = self::create_wp_user( $email, $first_name, $last_name, $password );
			if ( is_wp_error( $user_id ) ) {
				return self::error_response( $api_key_id, 'user_creation_failed', $user_id->get_error_message(), 500 );
			}
		}

		// --- Resolve parent affiliate ---
		$parent_affiliate_id = null;
		$parent_resolved     = null;
		$warnings            = array();

		if ( ! empty( $parent_code ) ) {
			$parent = Konx_Affiliate_Manager::get_affiliate_by_referral_code( $parent_code );
			if ( $parent ) {
				$parent_affiliate_id = (int) $parent->id;
				$parent_resolved     = true;
			} else {
				$parent_resolved = false;
				$warnings[]      = 'parent_not_found';
			}
		}

		// --- Check for referral code conflict ---
		$existing_code = Konx_Affiliate_Manager::get_affiliate_by_referral_code( $referral_code );
		if ( $existing_code ) {
			return self::error_response( $api_key_id, 'referral_code_conflict', __( 'This referral code is already in use.', 'konx-affiliate-dashboard' ), 409 );
		}

		// --- Create affiliate profile ---
		$args = array(
			'referral_code' => $referral_code,
			'external_id'   => $external_id,
		);

		if ( $parent_affiliate_id ) {
			$args['parent_affiliate_id'] = $parent_affiliate_id;
		}
		if ( $phone ) {
			$args['phone'] = $phone;
		}

		$affiliate_id = Konx_Affiliate_Manager::create_affiliate_profile( $user_id, $affiliate_type, $args );

		if ( is_wp_error( $affiliate_id ) ) {
			return self::error_response( $api_key_id, 'affiliate_creation_failed', $affiliate_id->get_error_message(), 500 );
		}

		// Set status if not the default 'active'.
		if ( 'active' !== $status ) {
			Konx_Affiliate_Manager::update_affiliate_status( $affiliate_id, $status );
		}

		// Store source as user meta if provided.
		if ( $source ) {
			update_user_meta( $user_id, 'konx_source', $source );
		}
		if ( $country ) {
			update_user_meta( $user_id, 'konx_country', $country );
		}

		// --- Success response ---
		Konx_Api_Helper::log_request( $api_key_id, '/users', 'POST', 201, null );

		$response_data = array(
			'success'        => true,
			'created'        => true,
			'user_id'        => $user_id,
			'affiliate_id'   => $affiliate_id,
			'external_id'    => $external_id,
			'referral_code'  => $referral_code,
			'affiliate_type' => $affiliate_type,
			'status'         => $status,
			'warnings'       => $warnings,
		);

		if ( null !== $parent_resolved ) {
			$response_data['parent_resolved'] = $parent_resolved;
		}

		return new \WP_REST_Response( $response_data, 201 );
	}

	// ------------------------------------------------------------------
	// Validation
	// ------------------------------------------------------------------

	/**
	 * Validate the request payload.
	 *
	 * @param array $params The request parameters.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	private static function validate_payload( $params ) {
		$required = array( 'external_id', 'email', 'first_name', 'last_name', 'affiliate_type', 'referral_code' );
		$missing  = array();

		foreach ( $required as $field ) {
			if ( ! isset( $params[ $field ] ) || '' === trim( $params[ $field ] ) ) {
				$missing[] = $field;
			}
		}

		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				'invalid_payload',
				sprintf( __( 'Missing required fields: %s', 'konx-affiliate-dashboard' ), implode( ', ', $missing ) ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_email( $params['email'] ) ) {
			return new \WP_Error(
				'invalid_payload',
				__( 'Invalid email address.', 'konx-affiliate-dashboard' ),
				array( 'status' => 400 )
			);
		}

		$normalized_type = self::normalize_type( sanitize_text_field( $params['affiliate_type'] ) );
		if ( ! in_array( $normalized_type, self::$valid_types, true ) ) {
			return new \WP_Error(
				'invalid_affiliate_type',
				sprintf(
					__( 'Invalid affiliate type: "%s". Valid types: %s', 'konx-affiliate-dashboard' ),
					esc_html( $params['affiliate_type'] ),
					implode( ', ', self::$valid_types )
				),
				array( 'status' => 422 )
			);
		}

		$code = sanitize_text_field( $params['referral_code'] );
		if ( strlen( $code ) > 50 ) {
			return new \WP_Error(
				'invalid_payload',
				__( 'Referral code must be 50 characters or fewer.', 'konx-affiliate-dashboard' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Normalize an affiliate type string to a valid database value.
	 *
	 * @param string $type The raw affiliate type.
	 * @return string The normalized type, or the original if no mapping found.
	 */
	private static function normalize_type( $type ) {
		$lower = strtolower( trim( $type ) );

		if ( isset( self::$type_normalize[ $lower ] ) ) {
			return self::$type_normalize[ $lower ];
		}

		// Already a valid type.
		if ( in_array( $lower, self::$valid_types, true ) ) {
			return $lower;
		}

		return $type;
	}

	// ------------------------------------------------------------------
	// User Creation
	// ------------------------------------------------------------------

	/**
	 * Create a WordPress user for the affiliate.
	 *
	 * Generates a unique user_login from the email prefix. If the
	 * login is taken, appends a numeric suffix.
	 *
	 * @param string      $email      Email address.
	 * @param string      $first_name First name.
	 * @param string      $last_name  Last name.
	 * @param string|null $password   Password (null = generate random).
	 * @return int|WP_Error User ID on success, WP_Error on failure.
	 */
	private static function create_wp_user( $email, $first_name, $last_name, $password = null ) {
		// Suppress new user notification emails.
		add_filter( 'wp_send_new_user_notification_to_user', '__return_false' );
		add_filter( 'wp_send_new_user_notification_to_admin', '__return_false' );

		// Generate login from email prefix.
		$login_base = sanitize_user( strstr( $email, '@', true ), true );
		if ( empty( $login_base ) ) {
			$login_base = 'user';
		}

		$login = $login_base;
		$suffix = 1;
		while ( username_exists( $login ) ) {
			$login = $login_base . $suffix;
			$suffix++;
			if ( $suffix > 999 ) {
				$login = $login_base . wp_rand( 1000, 99999 );
				break;
			}
		}

		// Generate password if not provided.
		if ( empty( $password ) ) {
			$password = wp_generate_password( 16, true, true );
		}

		$user_id = wp_create_user( $login, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		wp_update_user( array(
			'ID'           => $user_id,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => trim( $first_name . ' ' . $last_name ),
		) );

		// Remove filters after creation.
		remove_filter( 'wp_send_new_user_notification_to_user', '__return_false' );
		remove_filter( 'wp_send_new_user_notification_to_admin', '__return_false' );

		return $user_id;
	}

	// ------------------------------------------------------------------
	// Lookups
	// ------------------------------------------------------------------

	/**
	 * Find an affiliate by external_id.
	 *
	 * @param string $external_id The external system ID.
	 * @return object|null The affiliate row, or null.
	 */
	private static function find_by_external_id( $external_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_affiliates';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE external_id = %s",
				sanitize_text_field( $external_id )
			)
		);
	}

	// ------------------------------------------------------------------
	// Error Helpers
	// ------------------------------------------------------------------

	/**
	 * Build a WP_Error response and log the request.
	 *
	 * @param int    $api_key_id API key ID.
	 * @param string $code       Error code.
	 * @param string $message    Human-readable message.
	 * @param int    $status     HTTP status code.
	 * @return WP_Error
	 */
	private static function error_response( $api_key_id, $code, $message, $status ) {
		Konx_Api_Helper::log_request( $api_key_id, '/users', 'POST', $status, $message );
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
