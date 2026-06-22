<?php
/**
 * Plugin settings page.
 *
 * Provides admin UI for configuring commission rates, recurring rates,
 * admin fees, withdrawal rules, and referral tracking settings.
 *
 * Commission rates are stored in wp_konx_commission_rules (custom table).
 * All other settings are stored in wp_options.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Settings_Page
 */
class Konx_Settings_Page {

	/**
	 * Option keys.
	 */
	const OPT_RECURRING_RATE = 'konx_recurring_commission_rate';
	const OPT_ADMIN_FEES     = 'konx_admin_fee_settings';
	const OPT_GENERAL        = 'konx_affiliate_settings';
	const OPT_REFERRAL       = 'konx_referral_settings';

	/**
	 * Affiliate types and product types for the commission rate matrix.
	 */
	private static $affiliate_types = array(
		'business'        => 'Business Affiliate',
		'referral'        => 'Referral Affiliate',
		'team_agent'      => 'Team Agent',
		'marketing_agent' => 'Marketing Agent',
		'sales_agent'     => 'Sales Agent',
	);

	private static $product_types = array(
		'starter_pack' => 'Starter Pack',
		'pro_pack'     => 'Pro Pack',
		'ecard_pack'   => 'eCard Pack',
	);

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_konx_save_settings', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Register the submenu page.
	 */
	public static function register_menu() {
		add_submenu_page(
			'konx-affiliate-dashboard',
			__( 'Settings', 'konx-affiliate-dashboard' ),
			__( 'Settings', 'konx-affiliate-dashboard' ),
			'manage_konx_settings',
			'konx-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'konx-affiliate-dashboard' ) );
		}

		$rates         = self::get_commission_rates();
		$recurring     = get_option( self::OPT_RECURRING_RATE, '0.1000' );
		$fee_settings  = get_option( self::OPT_ADMIN_FEES, array() );
		$general       = get_option( self::OPT_GENERAL, array() );
		$referral      = get_option( self::OPT_REFERRAL, array() );
		$feedback      = self::get_feedback();

		$recurring_pct = number_format( (float) $recurring * 100, 0 );
		$min_wd        = ! empty( $general['min_withdrawal'] ) ? $general['min_withdrawal'] : '50.00';
		$cookie_days   = ! empty( $referral['cookie_days'] ) ? $referral['cookie_days'] : 30;
		$ref_param     = ! empty( $referral['ref_param'] ) ? $referral['ref_param'] : 'ref';
		$dedup_hours   = ! empty( $referral['dedup_hours'] ) ? $referral['dedup_hours'] : 24;

		?>
		<div class="wrap">
			<div class="konx-page-header">
				<h1><?php esc_html_e( 'Settings', 'konx-affiliate-dashboard' ); ?></h1>
			</div>

			<?php if ( $feedback ) : ?>
				<div class="notice notice-<?php echo esc_attr( $feedback['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $feedback['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="konx_save_settings">
				<?php wp_nonce_field( 'konx_save_settings', 'konx_settings_nonce' ); ?>

				<!-- Commission Rates -->
				<div class="konx-form-card">
					<h2><?php esc_html_e( 'Commission Rates (%)', 'konx-affiliate-dashboard' ); ?></h2>
					<p class="description"><?php esc_html_e( 'One-time commission rates for pack purchases. Enter as percentage (e.g., 40 for 40%).', 'konx-affiliate-dashboard' ); ?></p>
					<div class="konx-table-wrap">
						<table class="widefat fixed striped" style="max-width:700px;margin-top:12px;">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Affiliate Type', 'konx-affiliate-dashboard' ); ?></th>
									<?php foreach ( self::$product_types as $slug => $label ) : ?>
										<th scope="col"><?php echo esc_html( $label ); ?></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( self::$affiliate_types as $aff_type => $aff_label ) : ?>
									<tr>
										<th scope="row"><?php echo esc_html( $aff_label ); ?></th>
										<?php foreach ( self::$product_types as $prod_type => $prod_label ) : ?>
											<?php
											$key      = $aff_type . '_' . $prod_type;
											$rate_val = isset( $rates[ $key ] ) ? $rates[ $key ] : '0';
											$rate_pct = number_format( (float) $rate_val * 100, 0 );
											?>
											<td>
												<input type="number" name="rates[<?php echo esc_attr( $key ); ?>]"
													value="<?php echo esc_attr( $rate_pct ); ?>"
													min="0" max="100" step="1" style="width:70px;"
													aria-label="<?php echo esc_attr( $aff_label . ' - ' . $prod_label ); ?>"> %
											</td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>

				<div class="konx-grid-2">
					<!-- Recurring Commission -->
					<div class="konx-form-card">
						<h2><?php esc_html_e( 'Recurring Commission', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'recurring_commission' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
						<p class="description"><?php esc_html_e( 'Applied to subscription renewals. Same rate for all affiliate types.', 'konx-affiliate-dashboard' ); ?></p>
						<table class="form-table">
							<tr>
								<th><label for="recurring_rate"><?php esc_html_e( 'Rate', 'konx-affiliate-dashboard' ); ?></label></th>
								<td>
									<input type="number" id="recurring_rate" name="recurring_rate"
										value="<?php echo esc_attr( $recurring_pct ); ?>"
										min="0" max="100" step="1" style="width:70px;"> %
								</td>
							</tr>
						</table>
					</div>

					<!-- Withdrawal Settings -->
					<div class="konx-form-card">
						<h2><?php esc_html_e( 'Withdrawal Settings', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'min_withdrawal' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
						<table class="form-table">
							<tr>
								<th><label for="min_withdrawal"><?php esc_html_e( 'Minimum ($)', 'konx-affiliate-dashboard' ); ?></label></th>
								<td>
									<input type="number" id="min_withdrawal" name="min_withdrawal"
										value="<?php echo esc_attr( $min_wd ); ?>"
										min="0" step="0.01" style="width:100px;">
								</td>
							</tr>
						</table>
					</div>
				</div>

				<div class="konx-grid-2">
					<!-- Admin Fees -->
					<div class="konx-form-card">
						<h2><?php esc_html_e( 'Monthly Admin Fees ($)', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'admin_fee' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
						<p class="description"><?php esc_html_e( 'Leave blank to use the default.', 'konx-affiliate-dashboard' ); ?></p>
						<table class="form-table">
							<?php foreach ( self::$affiliate_types as $aff_type => $aff_label ) : ?>
								<tr>
									<th><label for="fee_<?php echo esc_attr( $aff_type ); ?>"><?php echo esc_html( $aff_label ); ?></label></th>
									<td>
										$<input type="number" id="fee_<?php echo esc_attr( $aff_type ); ?>"
											name="fees[<?php echo esc_attr( $aff_type ); ?>]"
											value="<?php echo esc_attr( ! empty( $fee_settings[ $aff_type ] ) ? $fee_settings[ $aff_type ] : '' ); ?>"
											min="0" step="0.01" style="width:90px;" placeholder="auto">
									</td>
								</tr>
							<?php endforeach; ?>
							<tr>
								<th><label for="fee_default"><?php esc_html_e( 'Default', 'konx-affiliate-dashboard' ); ?></label></th>
								<td>
									$<input type="number" id="fee_default" name="fees[default]"
										value="<?php echo esc_attr( ! empty( $fee_settings['default'] ) ? $fee_settings['default'] : '10.00' ); ?>"
										min="0" step="0.01" style="width:90px;">
								</td>
							</tr>
						</table>
					</div>

					<!-- Referral Settings -->
					<div class="konx-form-card">
						<h2><?php esc_html_e( 'Referral Tracking', 'konx-affiliate-dashboard' ); ?></h2>
						<table class="form-table">
							<tr>
								<th><label for="cookie_days"><?php esc_html_e( 'Remember Referral For', 'konx-affiliate-dashboard' ); ?></label></th>
								<td>
									<input type="number" id="cookie_days" name="cookie_days"
										value="<?php echo esc_attr( $cookie_days ); ?>"
										min="1" max="365" step="1" style="width:80px;"> <?php esc_html_e( 'days', 'konx-affiliate-dashboard' ); ?>
									<p class="description"><?php esc_html_e( 'How long to track a referral after someone clicks an affiliate link.', 'konx-affiliate-dashboard' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="ref_param"><?php esc_html_e( 'Link Parameter', 'konx-affiliate-dashboard' ); ?></label></th>
								<td>
									<input type="text" id="ref_param" name="ref_param"
										value="<?php echo esc_attr( $ref_param ); ?>"
										style="width:80px;">
									<p class="description"><?php esc_html_e( 'The URL parameter used in referral links. Default: "ref" (yoursite.com/?ref=CODE). Most users should not change this.', 'konx-affiliate-dashboard' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="dedup_hours"><?php esc_html_e( 'Ignore Repeat Clicks Within', 'konx-affiliate-dashboard' ); ?></label></th>
								<td>
									<input type="number" id="dedup_hours" name="dedup_hours"
										value="<?php echo esc_attr( $dedup_hours ); ?>"
										min="1" max="168" step="1" style="width:80px;"> <?php esc_html_e( 'hours', 'konx-affiliate-dashboard' ); ?>
									<p class="description"><?php esc_html_e( 'Multiple clicks from the same person within this window are counted as one click.', 'konx-affiliate-dashboard' ); ?></p>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<!-- Data Removal -->
				<div class="konx-form-card" style="border-color:#d63638;">
					<h2 style="color:#d63638;"><?php esc_html_e( 'Data Removal on Uninstall', 'konx-affiliate-dashboard' ); ?></h2>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Delete Data', 'konx-affiliate-dashboard' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="remove_all_data" value="1" <?php checked( get_option( 'konx_remove_all_data', false ) ); ?>>
									<?php esc_html_e( 'Delete all affiliate data when this plugin is deleted', 'konx-affiliate-dashboard' ); ?>
								</label>
								<p class="description" style="color:#d63638;">
									<?php esc_html_e( 'When checked, deleting the plugin will permanently remove all database tables, commissions, wallet history, affiliate profiles, and user data. This cannot be undone.', 'konx-affiliate-dashboard' ); ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'When unchecked (default), all data is preserved even if the plugin is deleted. You can reinstall later without losing anything.', 'konx-affiliate-dashboard' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button( __( 'Save All Settings', 'konx-affiliate-dashboard' ) ); ?>
			</form>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Save Handler
	// ------------------------------------------------------------------

	/**
	 * Handle settings form submission.
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		check_admin_referer( 'konx_save_settings', 'konx_settings_nonce' );

		// --- Commission Rates ---
		if ( isset( $_POST['rates'] ) && is_array( $_POST['rates'] ) ) {
			self::save_commission_rates( wp_unslash( $_POST['rates'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		// --- Recurring Rate ---
		if ( isset( $_POST['recurring_rate'] ) ) {
			$pct  = absint( $_POST['recurring_rate'] );
			$rate = number_format( $pct / 100, 4, '.', '' );
			update_option( self::OPT_RECURRING_RATE, $rate );
		}

		// --- Admin Fees ---
		if ( isset( $_POST['fees'] ) && is_array( $_POST['fees'] ) ) {
			$fees = array();
			foreach ( wp_unslash( $_POST['fees'] ) as $key => $val ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$key = sanitize_text_field( $key );
				if ( '' !== $val ) {
					$fees[ $key ] = number_format( (float) sanitize_text_field( $val ), 2, '.', '' );
				}
			}
			update_option( self::OPT_ADMIN_FEES, $fees );
		}

		// --- Withdrawal Settings ---
		$general = get_option( self::OPT_GENERAL, array() );
		if ( isset( $_POST['min_withdrawal'] ) ) {
			$general['min_withdrawal'] = number_format( (float) sanitize_text_field( wp_unslash( $_POST['min_withdrawal'] ) ), 2, '.', '' );
		}
		update_option( self::OPT_GENERAL, $general );

		// --- Referral Settings ---
		$referral = array();
		if ( isset( $_POST['cookie_days'] ) ) {
			$referral['cookie_days'] = absint( $_POST['cookie_days'] ) ?: 30;
		}
		if ( isset( $_POST['ref_param'] ) ) {
			$referral['ref_param'] = sanitize_key( wp_unslash( $_POST['ref_param'] ) ) ?: 'ref';
		}
		if ( isset( $_POST['dedup_hours'] ) ) {
			$referral['dedup_hours'] = absint( $_POST['dedup_hours'] ) ?: 24;
		}
		update_option( self::OPT_REFERRAL, $referral );

		// --- Data Removal Setting ---
		update_option( 'konx_remove_all_data', ! empty( $_POST['remove_all_data'] ) );

		self::set_feedback( 'success', __( 'Settings saved.', 'konx-affiliate-dashboard' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=konx-settings' ) );
		exit;
	}

	// ------------------------------------------------------------------
	// Commission Rates (Custom Table)
	// ------------------------------------------------------------------

	/**
	 * Get current commission rates from the custom table as a flat map.
	 *
	 * @return array Key format: "{affiliate_type}_{product_type}" => rate decimal string.
	 */
	private static function get_commission_rates() {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_commission_rules';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT affiliate_type, product_type, rate FROM {$table} WHERE commission_type = %s",
			'one_time'
		) );

		$rates = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$rates[ $row->affiliate_type . '_' . $row->product_type ] = $row->rate;
			}
		}

		return $rates;
	}

	/**
	 * Save commission rates to the custom table.
	 *
	 * Uses INSERT ... ON DUPLICATE KEY UPDATE for each rate to safely
	 * update existing rules or create new ones.
	 *
	 * @param array $posted_rates Key format: "{affiliate_type}_{product_type}" => percentage string.
	 */
	private static function save_commission_rates( $posted_rates ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_commission_rules';
		$now   = current_time( 'mysql', true );

		foreach ( self::$affiliate_types as $aff_type => $aff_label ) {
			foreach ( self::$product_types as $prod_type => $prod_label ) {
				$key = $aff_type . '_' . $prod_type;
				if ( ! isset( $posted_rates[ $key ] ) ) {
					continue;
				}

				$pct  = absint( sanitize_text_field( $posted_rates[ $key ] ) );
				$rate = number_format( $pct / 100, 4, '.', '' );

				// Check if rule exists.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$existing = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, rate FROM {$table} WHERE affiliate_type = %s AND product_type = %s AND commission_type = %s",
					$aff_type,
					$prod_type,
					'one_time'
				) );

				if ( $existing ) {
					if ( $existing->rate !== $rate ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update(
							$table,
							array( 'rate' => $rate, 'updated_at' => $now ),
							array( 'id' => $existing->id ),
							array( '%s', '%s' ),
							array( '%d' )
						);
					}
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->insert(
						$table,
						array(
							'affiliate_type'  => $aff_type,
							'product_type'    => $prod_type,
							'commission_type' => 'one_time',
							'rate'            => $rate,
							'is_active'       => 1,
							'created_at'      => $now,
							'updated_at'      => $now,
						),
						array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
					);
				}
			}
		}
	}

	// ------------------------------------------------------------------
	// Settings Readers (Static Helpers)
	// ------------------------------------------------------------------

	/**
	 * Get the recurring commission rate.
	 *
	 * @return string Decimal rate string (e.g. '0.1000').
	 */
	public static function get_recurring_rate() {
		return get_option( self::OPT_RECURRING_RATE, '0.1000' );
	}

	/**
	 * Get the referral cookie duration in days.
	 *
	 * @return int Days.
	 */
	public static function get_cookie_days() {
		$settings = get_option( self::OPT_REFERRAL, array() );
		return ! empty( $settings['cookie_days'] ) ? absint( $settings['cookie_days'] ) : 30;
	}

	/**
	 * Get the referral URL parameter name.
	 *
	 * @return string Parameter name (default 'ref').
	 */
	public static function get_ref_param() {
		$settings = get_option( self::OPT_REFERRAL, array() );
		return ! empty( $settings['ref_param'] ) ? sanitize_key( $settings['ref_param'] ) : 'ref';
	}

	/**
	 * Get the click dedup window in seconds.
	 *
	 * @return int Seconds.
	 */
	public static function get_dedup_window() {
		$settings = get_option( self::OPT_REFERRAL, array() );
		$hours    = ! empty( $settings['dedup_hours'] ) ? absint( $settings['dedup_hours'] ) : 24;
		return $hours * 3600;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Set feedback transient.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message.
	 */
	private static function set_feedback( $type, $message ) {
		set_transient( 'konx_settings_feedback', array(
			'type'    => $type,
			'message' => $message,
		), 30 );
	}

	/**
	 * Get and clear feedback.
	 *
	 * @return array|false
	 */
	private static function get_feedback() {
		$feedback = get_transient( 'konx_settings_feedback' );
		if ( $feedback ) {
			delete_transient( 'konx_settings_feedback' );
		}
		return $feedback;
	}
}
