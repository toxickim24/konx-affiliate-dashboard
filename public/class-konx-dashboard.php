<?php
/**
 * Frontend affiliate dashboard.
 *
 * Registers the [konx_affiliate_dashboard] shortcode and prepares
 * all data for the dashboard view. Only logged-in affiliates can
 * access the dashboard; non-affiliates see an informational message.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Dashboard
 */
class Konx_Dashboard {

	/**
	 * Register shortcode and hooks.
	 */
	public static function init() {
		add_shortcode( 'konx_affiliate_dashboard', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
		add_action( 'admin_post_konx_affiliate_withdrawal', array( __CLASS__, 'handle_withdrawal_form' ) );
		add_action( 'admin_post_nopriv_konx_affiliate_withdrawal', '__return_false' );
		add_action( 'admin_post_konx_update_profile', array( __CLASS__, 'handle_profile_update' ) );
	}

	/**
	 * Handle affiliate profile update.
	 */
	public static function handle_profile_update() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		$user_id   = get_current_user_id();
		$affiliate = Konx_Affiliate_Manager::get_affiliate_by_user( $user_id );
		if ( ! $affiliate ) {
			wp_die( esc_html__( 'Affiliate not found.', 'konx-affiliate-dashboard' ) );
		}

		check_admin_referer( 'konx_update_profile_' . $affiliate->id, 'konx_profile_nonce' );

		$redirect = isset( $_POST['_wp_http_referer'] ) ? esc_url_raw( wp_unslash( $_POST['_wp_http_referer'] ) ) : home_url( '/' );

		// Update payment email.
		$payment_email = isset( $_POST['payment_email'] ) ? sanitize_email( wp_unslash( $_POST['payment_email'] ) ) : '';
		if ( ! empty( $payment_email ) ) {
			Konx_Affiliate_Manager::update_payment_email( (int) $affiliate->id, $payment_email );
		}

		self::set_feedback( (int) $affiliate->id, 'success', __( 'Profile updated.', 'konx-affiliate-dashboard' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Find the page containing the [konx_affiliate_dashboard] shortcode.
	 *
	 * @return int Page ID, or 0.
	 */
	private static function get_dashboard_page_id() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$page_id = $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%[konx_affiliate_dashboard]%' LIMIT 1"
		);

		return $page_id ? (int) $page_id : 0;
	}

	// ------------------------------------------------------------------
	// Shortcode
	// ------------------------------------------------------------------

	/**
	 * Render the [konx_affiliate_dashboard] shortcode.
	 *
	 * @return string Dashboard HTML.
	 */
	public static function render_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<div class="konx-dash-notice">'
				. esc_html__( 'Please log in to access your affiliate dashboard.', 'konx-affiliate-dashboard' )
				. ' <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">'
				. esc_html__( 'Log in', 'konx-affiliate-dashboard' ) . '</a></div>';
		}

		$user_id   = get_current_user_id();
		$affiliate = Konx_Affiliate_Manager::get_affiliate_by_user( $user_id );

		if ( ! $affiliate ) {
			return '<div class="konx-dash-notice">'
				. esc_html__( 'You do not have an affiliate account. Contact the administrator to get started.', 'konx-affiliate-dashboard' )
				. '</div>';
		}

		// Prepare all dashboard data.
		$data = self::prepare_dashboard_data( $affiliate );

		// Render the view.
		ob_start();
		include KONX_AFFILIATE_PLUGIN_DIR . 'public/views/dashboard.php';
		return ob_get_clean();
	}

	// ------------------------------------------------------------------
	// Data Preparation
	// ------------------------------------------------------------------

	/**
	 * Prepare all data needed by the dashboard view.
	 *
	 * @param object $affiliate The affiliate row.
	 * @return array Dashboard data.
	 */
	private static function prepare_dashboard_data( $affiliate ) {
		$affiliate_id = (int) $affiliate->id;

		// Balance summary.
		$balance = Konx_Wallet::get_affiliate_balance_summary( $affiliate_id );

		// Milestone progress.
		$milestone = Konx_Milestone_Bonus::get_progress_to_next_milestone( $affiliate_id );

		// Estimated next bonus (sum of approved commissions in current block).
		$current_block_start = ( $milestone['milestones_achieved'] * Konx_Milestone_Bonus::BLOCK_SIZE ) + 1;
		$current_block_end   = ( $milestone['milestones_achieved'] + 1 ) * Konx_Milestone_Bonus::BLOCK_SIZE;
		$estimated_bonus     = Konx_Milestone_Bonus::calculate_bonus_amount( $affiliate_id, $current_block_start, $current_block_end );

		// Admin fee status.
		$fee_status = Konx_Admin_Fees::get_fee_status( $affiliate_id );

		// Commission history (recent 10).
		$commissions = self::get_commission_history( $affiliate_id, 1, 10 );

		// Milestone bonus history.
		$bonuses = Konx_Milestone_Bonus::get_bonus_history( $affiliate_id, 1, 5 );

		// Withdrawal status.
		$pending_withdrawal = Konx_Withdrawals::get_user_pending_request( $affiliate_id );
		$withdrawal_history = Konx_Withdrawals::get_requests( array(
			'affiliate_id' => $affiliate_id,
			'page'         => 1,
			'per_page'     => 10,
		) );
		$min_withdrawal = Konx_Withdrawals::get_minimum_amount();

		// Referral link.
		$referral_url = add_query_arg( 'ref', $affiliate->referral_code, home_url( '/' ) );

		// Feedback message.
		$feedback = get_transient( 'konx_dash_feedback_' . $affiliate_id );
		if ( $feedback ) {
			delete_transient( 'konx_dash_feedback_' . $affiliate_id );
		}

		// User journey.
		$journey = Konx_User_Journey::get_journey( $affiliate_id );

		return array(
			'affiliate'          => $affiliate,
			'balance'            => $balance,
			'milestone'          => $milestone,
			'estimated_bonus'    => $estimated_bonus,
			'fee_status'         => $fee_status,
			'commissions'        => $commissions,
			'bonuses'            => $bonuses,
			'pending_withdrawal' => $pending_withdrawal,
			'withdrawal_history' => $withdrawal_history,
			'min_withdrawal'     => $min_withdrawal,
			'referral_url'       => $referral_url,
			'feedback'           => $feedback,
			'journey'            => $journey,
		);
	}

	/**
	 * Get paginated commission history for an affiliate.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 * @param int $page         Page number.
	 * @param int $per_page     Per page.
	 * @return array { entries, total, pages }
	 */
	private static function get_commission_history( $affiliate_id, $page = 1, $per_page = 10 ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'konx_commissions';
		$per_page = max( 1, min( 100, (int) $per_page ) );
		$offset   = ( max( 1, (int) $page ) - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE affiliate_id = %d", absint( $affiliate_id ) )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE affiliate_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
				absint( $affiliate_id ),
				$per_page,
				$offset
			)
		);

		return array(
			'entries' => $entries ? $entries : array(),
			'total'   => $total,
			'pages'   => (int) ceil( $total / $per_page ),
		);
	}

	// ------------------------------------------------------------------
	// Withdrawal Form Handler
	// ------------------------------------------------------------------

	/**
	 * Handle the affiliate withdrawal form submission.
	 */
	public static function handle_withdrawal_form() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		$user_id   = get_current_user_id();
		$affiliate = Konx_Affiliate_Manager::get_affiliate_by_user( $user_id );

		if ( ! $affiliate ) {
			wp_die( esc_html__( 'Affiliate account not found.', 'konx-affiliate-dashboard' ) );
		}

		check_admin_referer( 'konx_withdrawal_request_' . $affiliate->id, 'konx_wd_nonce' );

		$amount         = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
		$payment_email  = isset( $_POST['payment_email'] ) ? sanitize_email( wp_unslash( $_POST['payment_email'] ) ) : '';
		$account_holder = isset( $_POST['account_holder'] ) ? sanitize_text_field( wp_unslash( $_POST['account_holder'] ) ) : '';
		$currency       = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : 'USD';
		$notes          = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		// Redirect URL (the page containing the shortcode).
		$redirect = isset( $_POST['_wp_http_referer'] ) ? esc_url_raw( wp_unslash( $_POST['_wp_http_referer'] ) ) : home_url( '/' );

		if ( empty( $amount ) || empty( $payment_email ) ) {
			self::set_feedback( $affiliate->id, 'error', __( 'Amount and Wise email are required.', 'konx-affiliate-dashboard' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$result = Konx_Withdrawals::create_request(
			(int) $affiliate->id,
			$amount,
			$payment_email,
			$account_holder,
			$currency,
			$notes
		);

		if ( is_wp_error( $result ) ) {
			self::set_feedback( $affiliate->id, 'error', $result->get_error_message() );
		} else {
			self::set_feedback( $affiliate->id, 'success', __( 'Withdrawal request submitted successfully.', 'konx-affiliate-dashboard' ) );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	// ------------------------------------------------------------------
	// Assets
	// ------------------------------------------------------------------

	/**
	 * Enqueue dashboard CSS only on pages with the shortcode.
	 */
	public static function maybe_enqueue_assets() {
		global $post;

		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'konx_affiliate_dashboard' ) ) {
			return;
		}

		wp_enqueue_style(
			'konx-frontend',
			KONX_AFFILIATE_PLUGIN_URL . 'assets/css/konx-frontend.css',
			array(),
			KONX_AFFILIATE_VERSION
		);

		wp_enqueue_style(
			'konx-dashboard',
			KONX_AFFILIATE_PLUGIN_URL . 'assets/css/konx-dashboard.css',
			array( 'konx-frontend' ),
			KONX_AFFILIATE_VERSION
		);

		wp_enqueue_style(
			'konx-tooltips',
			KONX_AFFILIATE_PLUGIN_URL . 'assets/css/konx-tooltips.css',
			array(),
			KONX_AFFILIATE_VERSION
		);

		wp_enqueue_script(
			'konx-tooltips',
			KONX_AFFILIATE_PLUGIN_URL . 'assets/js/konx-tooltips.js',
			array(),
			KONX_AFFILIATE_VERSION,
			true
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Set a feedback message for the affiliate dashboard.
	 *
	 * @param int    $affiliate_id Affiliate ID.
	 * @param string $type         'success' or 'error'.
	 * @param string $message      Message text.
	 */
	private static function set_feedback( $affiliate_id, $type, $message ) {
		set_transient( 'konx_dash_feedback_' . $affiliate_id, array(
			'type'    => $type,
			'message' => $message,
		), 60 );
	}

	/**
	 * Format affiliate type for display.
	 *
	 * @param string $type The affiliate_type value.
	 * @return string Human-readable label.
	 */
	public static function format_type( $type ) {
		$labels = array(
			'business'        => __( 'Business Affiliate', 'konx-affiliate-dashboard' ),
			'team_agent'      => __( 'Team Agent', 'konx-affiliate-dashboard' ),
			'marketing_agent' => __( 'Marketing Agent', 'konx-affiliate-dashboard' ),
			'sales_agent'     => __( 'Sales Agent', 'konx-affiliate-dashboard' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : ucwords( str_replace( '_', ' ', $type ) );
	}

	/**
	 * Format commission type for display.
	 *
	 * @param string $type Commission type value.
	 * @return string
	 */
	public static function format_commission_type( $type ) {
		$labels = array(
			'one_time'  => __( 'One-Time', 'konx-affiliate-dashboard' ),
			'recurring' => __( 'Recurring', 'konx-affiliate-dashboard' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * Format status for display with color.
	 *
	 * @param string $status Status value.
	 * @return string HTML span with color.
	 */
	public static function format_status( $status ) {
		$colors = array(
			'approved'  => '#00a32a',
			'pending'   => '#dba617',
			'blocked'   => '#d63638',
			'reversed'  => '#787c82',
			'completed' => '#00a32a',
			'rejected'  => '#d63638',
			'cancelled' => '#787c82',
			'unpaid'    => '#d63638',
			'overdue'   => '#d63638',
			'paid'      => '#00a32a',
			'waived'    => '#72aee6',
		);
		$color = isset( $colors[ $status ] ) ? $colors[ $status ] : '#787c82';
		return '<span style="color:' . esc_attr( $color ) . ';font-weight:600;">' . esc_html( ucfirst( $status ) ) . '</span>';
	}
}
