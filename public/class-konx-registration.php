<?php
/**
 * Frontend affiliate registration.
 *
 * Registers the [konx_affiliate_register] shortcode and handles
 * new affiliate sign-ups for both logged-out (new WP account) and
 * logged-in (existing WP account) users.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Registration
 */
class Konx_Registration {

	/**
	 * Affiliate types available for self-registration.
	 * Agent types are admin-assigned only.
	 *
	 * @var array type => label
	 */
	private static $registration_types = array(
		'referral' => 'Referral Affiliate',
		'business' => 'Business Affiliate',
	);

	/**
	 * Register shortcode and hooks.
	 */
	public static function init() {
		add_shortcode( 'konx_affiliate_register', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
		add_action( 'admin_post_konx_affiliate_register', array( __CLASS__, 'handle_registration' ) );
		add_action( 'admin_post_nopriv_konx_affiliate_register', array( __CLASS__, 'handle_registration' ) );
	}

	// ------------------------------------------------------------------
	// Shortcode
	// ------------------------------------------------------------------

	/**
	 * Render the [konx_affiliate_register] shortcode.
	 *
	 * @return string Registration form HTML.
	 */
	public static function render_shortcode() {
		// Already an affiliate? Show a message.
		if ( is_user_logged_in() ) {
			$affiliate = Konx_Affiliate_Manager::get_affiliate_by_user( get_current_user_id() );
			if ( $affiliate ) {
				return '<div class="konx-reg-notice konx-reg-notice-info">'
					. esc_html__( 'You are already registered as an affiliate.', 'konx-affiliate-dashboard' )
					. '</div>';
			}
		}

		// Feedback from a previous submission.
		$feedback = self::get_feedback();

		ob_start();
		include KONX_AFFILIATE_PLUGIN_DIR . 'public/views/register.php';
		return ob_get_clean();
	}

	// ------------------------------------------------------------------
	// Form Handler
	// ------------------------------------------------------------------

	/**
	 * Handle the registration form submission.
	 */
	public static function handle_registration() {
		check_admin_referer( 'konx_affiliate_register', 'konx_reg_nonce' );

		$redirect = isset( $_POST['_wp_http_referer'] )
			? esc_url_raw( wp_unslash( $_POST['_wp_http_referer'] ) )
			: home_url( '/' );

		// Collect fields.
		$first_name     = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name      = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$email          = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password       = isset( $_POST['password'] ) ? $_POST['password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords must not be sanitized.
		$affiliate_type = isset( $_POST['affiliate_type'] ) ? sanitize_text_field( wp_unslash( $_POST['affiliate_type'] ) ) : 'referral';
		$wise_email     = isset( $_POST['wise_email'] ) ? sanitize_email( wp_unslash( $_POST['wise_email'] ) ) : '';
		$terms          = ! empty( $_POST['terms'] );
		$ref_code       = isset( $_POST['ref_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['ref_code'] ) ) ) : '';

		// --- Validation ---

		if ( empty( $first_name ) || empty( $last_name ) || empty( $email ) ) {
			self::set_feedback( 'error', __( 'First name, last name, and email are required.', 'konx-affiliate-dashboard' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( ! is_email( $email ) ) {
			self::set_feedback( 'error', __( 'Please enter a valid email address.', 'konx-affiliate-dashboard' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( ! $terms ) {
			self::set_feedback( 'error', __( 'You must accept the terms and conditions.', 'konx-affiliate-dashboard' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( ! isset( self::$registration_types[ $affiliate_type ] ) ) {
			self::set_feedback( 'error', __( 'Invalid affiliate type selected.', 'konx-affiliate-dashboard' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		// --- Determine or create WordPress user ---

		$user_id = 0;

		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();

			// Duplicate check.
			$existing = Konx_Affiliate_Manager::get_affiliate_by_user( $user_id );
			if ( $existing ) {
				self::set_feedback( 'error', __( 'You already have an affiliate account.', 'konx-affiliate-dashboard' ) );
				wp_safe_redirect( $redirect );
				exit;
			}
		} else {
			// New user: password required.
			if ( empty( $password ) || strlen( $password ) < 8 ) {
				self::set_feedback( 'error', __( 'Password must be at least 8 characters.', 'konx-affiliate-dashboard' ) );
				wp_safe_redirect( $redirect );
				exit;
			}

			if ( email_exists( $email ) ) {
				self::set_feedback( 'error', __( 'An account with this email already exists. Please log in first.', 'konx-affiliate-dashboard' ) );
				wp_safe_redirect( $redirect );
				exit;
			}

			if ( username_exists( $email ) ) {
				self::set_feedback( 'error', __( 'This username is already taken.', 'konx-affiliate-dashboard' ) );
				wp_safe_redirect( $redirect );
				exit;
			}

			$user_id = wp_create_user( $email, $password, $email );

			if ( is_wp_error( $user_id ) ) {
				self::set_feedback( 'error', $user_id->get_error_message() );
				wp_safe_redirect( $redirect );
				exit;
			}

			// Set name.
			wp_update_user( array(
				'ID'           => $user_id,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => $first_name . ' ' . $last_name,
			) );
		}

		// --- Create affiliate profile ---

		// Determine parent affiliate from referral code.
		$parent_affiliate_id = 0;
		if ( ! empty( $ref_code ) ) {
			$parent = Konx_Affiliate_Manager::get_affiliate_by_referral_code( $ref_code );
			if ( $parent ) {
				$parent_affiliate_id = (int) $parent->id;
			}
		}

		$args = array();
		if ( $parent_affiliate_id ) {
			$args['parent_affiliate_id'] = $parent_affiliate_id;
		}
		if ( ! empty( $wise_email ) ) {
			$args['payment_email'] = $wise_email;
		}

		$affiliate_id = Konx_Affiliate_Manager::create_affiliate_profile( $user_id, $affiliate_type, $args );

		if ( is_wp_error( $affiliate_id ) ) {
			self::set_feedback( 'error', $affiliate_id->get_error_message() );
			wp_safe_redirect( $redirect );
			exit;
		}

		// Business affiliates start as pending (awaiting pack purchase).
		if ( 'business' === $affiliate_type ) {
			Konx_Affiliate_Manager::update_affiliate_status( $affiliate_id, 'pending' );
		}

		// Create initial admin fee record for the current period.
		$current_period = current_time( 'Y-m' );
		$due_date       = current_time( 'Y-m-t' ); // Last day of current month.
		Konx_Admin_Fees::create_monthly_fee( $affiliate_id, $current_period, $due_date );

		// --- Notifications ---

		self::send_admin_notification( $user_id, $affiliate_type, $affiliate_id );
		self::send_user_confirmation( $user_id, $affiliate_type );

		// Log in the new user if they were not already logged in.
		if ( ! is_user_logged_in() ) {
			wp_set_auth_cookie( $user_id, true );
			wp_set_current_user( $user_id );
		}

		self::set_feedback( 'success', __( 'Registration successful! Welcome to the KonX Affiliate Program.', 'konx-affiliate-dashboard' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	// ------------------------------------------------------------------
	// Email Notifications
	// ------------------------------------------------------------------

	/**
	 * Send admin notification of new affiliate registration.
	 *
	 * @param int    $user_id        WordPress user ID.
	 * @param string $affiliate_type Affiliate type.
	 * @param int    $affiliate_id   Affiliate table ID.
	 */
	private static function send_admin_notification( $user_id, $affiliate_type, $affiliate_id ) {
		$user    = get_userdata( $user_id );
		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] New Affiliate Registration', 'konx-affiliate-dashboard' ),
			get_bloginfo( 'name' )
		);

		$type_label = isset( self::$registration_types[ $affiliate_type ] )
			? self::$registration_types[ $affiliate_type ]
			: $affiliate_type;

		$message = sprintf(
			"New affiliate registered:\n\nName: %s\nEmail: %s\nType: %s\nAffiliate ID: %d\n\nManage affiliates: %s",
			$user->display_name,
			$user->user_email,
			$type_label,
			$affiliate_id,
			admin_url( 'admin.php?page=konx-affiliate-dashboard' )
		);

		wp_mail( get_option( 'admin_email' ), $subject, $message );
	}

	/**
	 * Send user confirmation email.
	 *
	 * @param int    $user_id        WordPress user ID.
	 * @param string $affiliate_type Affiliate type.
	 */
	private static function send_user_confirmation( $user_id, $affiliate_type ) {
		$user    = get_userdata( $user_id );
		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Welcome to the %s Affiliate Program', 'konx-affiliate-dashboard' ),
			get_bloginfo( 'name' )
		);

		$type_label = isset( self::$registration_types[ $affiliate_type ] )
			? self::$registration_types[ $affiliate_type ]
			: $affiliate_type;

		$status_note = ( 'business' === $affiliate_type )
			? __( 'Your account is pending until your pack purchase is confirmed.', 'konx-affiliate-dashboard' )
			: __( 'Your account is now active.', 'konx-affiliate-dashboard' );

		$message = sprintf(
			"Hello %s,\n\nThank you for joining the affiliate program!\n\nType: %s\nStatus: %s\n\nYou can access your dashboard here:\n%s\n\nBest regards,\n%s",
			$user->first_name ?: $user->display_name,
			$type_label,
			$status_note,
			home_url( '/' ),
			get_bloginfo( 'name' )
		);

		wp_mail( $user->user_email, $subject, $message );
	}

	// ------------------------------------------------------------------
	// Assets
	// ------------------------------------------------------------------

	/**
	 * Enqueue registration CSS only on pages with the shortcode.
	 */
	public static function maybe_enqueue_assets() {
		global $post;

		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'konx_affiliate_register' ) ) {
			return;
		}

		wp_enqueue_style(
			'konx-frontend',
			KONX_AFFILIATE_PLUGIN_URL . 'assets/css/konx-frontend.css',
			array(),
			KONX_AFFILIATE_VERSION
		);

		wp_enqueue_style(
			'konx-registration',
			KONX_AFFILIATE_PLUGIN_URL . 'assets/css/konx-registration.css',
			array( 'konx-frontend' ),
			KONX_AFFILIATE_VERSION
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Get self-registration affiliate types.
	 *
	 * @return array type => label
	 */
	public static function get_registration_types() {
		return self::$registration_types;
	}

	/**
	 * Set feedback transient.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message text.
	 */
	private static function set_feedback( $type, $message ) {
		set_transient( 'konx_reg_feedback', array(
			'type'    => $type,
			'message' => $message,
		), 60 );
	}

	/**
	 * Get and clear feedback transient.
	 *
	 * @return array|false
	 */
	private static function get_feedback() {
		$feedback = get_transient( 'konx_reg_feedback' );
		if ( $feedback ) {
			delete_transient( 'konx_reg_feedback' );
		}
		return $feedback;
	}
}
