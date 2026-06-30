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
	 * Settings tabs configuration.
	 *
	 * @var array
	 */
	private static $tabs = array(
		'dashboard'       => 'Dashboard',
		'general'         => 'General',
		'api-keys'        => 'API Keys',
		'product-mapping' => 'Product Mapping',
		'migration'       => 'Migration',
		'system-status'   => 'System Status',
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
	 * Render the tabbed settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'konx-affiliate-dashboard' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		if ( ! isset( self::$tabs[ $active_tab ] ) ) {
			$active_tab = 'dashboard';
		}

		$feedback = self::get_feedback();

		?>
		<div class="wrap">
			<div class="konx-page-header">
				<h1><?php esc_html_e( 'Settings', 'konx-affiliate-dashboard' ); ?></h1>
			</div>

			<nav class="nav-tab-wrapper" style="margin-bottom:20px;">
				<?php foreach ( self::$tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-settings&tab=' . $slug ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( $feedback ) : ?>
				<div class="notice notice-<?php echo esc_attr( $feedback['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $feedback['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			switch ( $active_tab ) {
				case 'general':
					self::render_general_tab();
					break;
				case 'api-keys':
					Konx_Api_Keys_Page::render_content();
					break;
				case 'product-mapping':
					Konx_Admin_Product_Mapping::render_content();
					break;
				case 'migration':
					self::render_migration_tab();
					break;
				case 'system-status':
					Konx_System_Status::render_content();
					break;
				case 'dashboard':
				default:
					self::render_dashboard_tab();
					break;
			}
			?>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Dashboard Tab
	// ------------------------------------------------------------------

	private static function render_dashboard_tab() {
		$health  = Konx_Health_Engine::get_all();
		$overall = Konx_Health_Engine::overall_status( $health );

		$status_labels = array(
			'healthy' => __( 'Healthy', 'konx-affiliate-dashboard' ),
			'warning' => __( 'Needs Attention', 'konx-affiliate-dashboard' ),
			'error'   => __( 'Issue Detected', 'konx-affiliate-dashboard' ),
		);
		$status_colors = array(
			'healthy' => '#00a32a',
			'warning' => '#dba617',
			'error'   => '#d63638',
		);

		?>
		<!-- Health Overview Banner -->
		<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
			<?php
			self::health_pill( __( 'System Health', 'konx-affiliate-dashboard' ), $status_labels[ $overall ], $status_colors[ $overall ] );

			$mig_labels = array( 'ok' => __( 'Approved', 'konx-affiliate-dashboard' ), 'warning' => __( 'Ready for Review', 'konx-affiliate-dashboard' ), 'info' => __( 'Not Started', 'konx-affiliate-dashboard' ) );
			$mig_colors = array( 'ok' => '#00a32a', 'warning' => '#dba617', 'info' => '#2271b1' );
			$ms = $health['migration']['status'];
			self::health_pill( __( 'Migration', 'konx-affiliate-dashboard' ), isset( $mig_labels[ $ms ] ) ? $mig_labels[ $ms ] : $ms, isset( $mig_colors[ $ms ] ) ? $mig_colors[ $ms ] : '#646970' );

			self::health_pill( __( 'API', 'konx-affiliate-dashboard' ), $health['api']['active'] > 0 ? __( 'Configured', 'konx-affiliate-dashboard' ) : __( 'Not Configured', 'konx-affiliate-dashboard' ), $health['api']['active'] > 0 ? '#00a32a' : '#dba617' );

			self::health_pill( __( 'Products', 'konx-affiliate-dashboard' ), $health['products']['mapped'] > 0 ? __( 'Mapped', 'konx-affiliate-dashboard' ) : __( 'Not Mapped', 'konx-affiliate-dashboard' ), $health['products']['mapped'] > 0 ? '#00a32a' : '#dba617' );
			?>
		</div>

		<!-- Quick Start -->
		<div class="konx-card" style="margin-bottom:24px;border-left:4px solid #2271b1;">
			<h2 style="margin-top:0;display:flex;align-items:center;gap:8px;">
				<span class="dashicons dashicons-flag" style="color:#2271b1;"></span>
				<?php esc_html_e( 'Quick Start', 'konx-affiliate-dashboard' ); ?>
			</h2>
			<ol style="margin:12px 0 0 20px;font-size:13px;line-height:2;">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-settings&tab=api-keys' ) ); ?>"><?php esc_html_e( 'Configure API Keys', 'konx-affiliate-dashboard' ); ?></a> <?php echo $health['api']['active'] > 0 ? '<span style="color:#00a32a;">&#10003;</span>' : ''; ?></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-settings&tab=product-mapping' ) ); ?>"><?php esc_html_e( 'Configure Product Mapping', 'konx-affiliate-dashboard' ); ?></a> <?php echo $health['products']['mapped'] > 0 ? '<span style="color:#00a32a;">&#10003;</span>' : ''; ?></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-settings&tab=general' ) ); ?>"><?php esc_html_e( 'Configure Commission Rates', 'konx-affiliate-dashboard' ); ?></a> <?php echo $health['commission']['active_rules'] > 0 ? '<span style="color:#00a32a;">&#10003;</span>' : ''; ?></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-settings&tab=migration' ) ); ?>"><?php esc_html_e( '(Optional) Review Migration Data', 'konx-affiliate-dashboard' ); ?></a></li>
				<li><?php esc_html_e( 'Monitor Affiliates and Commissions', 'konx-affiliate-dashboard' ); ?></li>
			</ol>
		</div>

		<!-- Health Cards Grid -->
		<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;">
			<?php self::health_card_open( 'dashicons-admin-network', __( 'API Keys', 'konx-affiliate-dashboard' ), $health['api']['status'] ); ?>
				<?php self::health_item( $health['api']['active'] > 0 ? 'ok' : 'warning', sprintf( _n( '%d active key', '%d active keys', $health['api']['active'], 'konx-affiliate-dashboard' ), $health['api']['active'] ) ); ?>
				<?php if ( $health['api']['revoked'] > 0 ) : ?>
					<?php self::health_item( 'info', sprintf( __( '%d revoked', 'konx-affiliate-dashboard' ), $health['api']['revoked'] ) ); ?>
				<?php endif; ?>
			<?php self::health_card_close( admin_url( 'admin.php?page=konx-settings&tab=api-keys' ), __( 'Manage API Keys', 'konx-affiliate-dashboard' ) ); ?>

			<?php self::health_card_open( 'dashicons-products', __( 'Product Mapping', 'konx-affiliate-dashboard' ), $health['products']['status'] ); ?>
				<?php self::health_item( $health['products']['mapped'] > 0 ? 'ok' : 'warning', sprintf( _n( '%d product mapped', '%d products mapped', $health['products']['mapped'], 'konx-affiliate-dashboard' ), $health['products']['mapped'] ) ); ?>
			<?php self::health_card_close( admin_url( 'admin.php?page=konx-settings&tab=product-mapping' ), __( 'Manage Product Mapping', 'konx-affiliate-dashboard' ) ); ?>

			<?php self::health_card_open( 'dashicons-migrate', __( 'Migration', 'konx-affiliate-dashboard' ), $health['migration']['status'] ); ?>
				<?php if ( $health['migration']['source'] ) : ?>
					<?php self::health_item( 'ok', sprintf( __( 'Source: %s', 'konx-affiliate-dashboard' ), strtoupper( esc_html( $health['migration']['source'] ) ) ) ); ?>
				<?php else : ?>
					<?php self::health_item( 'info', __( 'No source selected', 'konx-affiliate-dashboard' ) ); ?>
				<?php endif; ?>
				<?php if ( $health['migration']['approved'] ) : ?>
					<?php self::health_item( 'ok', __( 'Plan approved', 'konx-affiliate-dashboard' ) ); ?>
				<?php else : ?>
					<?php self::health_item( 'info', __( 'Preview only', 'konx-affiliate-dashboard' ) ); ?>
				<?php endif; ?>
			<?php self::health_card_close( admin_url( 'admin.php?page=konx-settings&tab=migration' ), __( 'Open Migration', 'konx-affiliate-dashboard' ) ); ?>

			<?php self::health_card_open( 'dashicons-admin-tools', __( 'System Status', 'konx-affiliate-dashboard' ), $health['system']['status'] ); ?>
				<?php foreach ( $health['system']['items'] as $item ) : ?>
					<?php self::health_item( $item['status'], $item['label'] . ': ' . $item['value'] ); ?>
				<?php endforeach; ?>
			<?php self::health_card_close( admin_url( 'admin.php?page=konx-settings&tab=system-status' ), __( 'View Details', 'konx-affiliate-dashboard' ) ); ?>

			<?php self::health_card_open( 'dashicons-groups', __( 'Affiliates', 'konx-affiliate-dashboard' ), $health['affiliates']['status'] ); ?>
				<?php self::health_item( 'info', sprintf( __( '%d total affiliates', 'konx-affiliate-dashboard' ), $health['affiliates']['total'] ) ); ?>
				<?php if ( $health['affiliates']['active'] > 0 ) : ?>
					<?php self::health_item( 'ok', sprintf( __( '%d active', 'konx-affiliate-dashboard' ), $health['affiliates']['active'] ) ); ?>
				<?php endif; ?>
				<?php if ( $health['affiliates']['pending'] > 0 ) : ?>
					<?php self::health_item( 'warning', sprintf( __( '%d pending', 'konx-affiliate-dashboard' ), $health['affiliates']['pending'] ) ); ?>
				<?php endif; ?>
			<?php self::health_card_close( admin_url( 'admin.php?page=konx-affiliates' ), __( 'View Affiliates', 'konx-affiliate-dashboard' ) ); ?>

			<?php self::health_card_open( 'dashicons-chart-area', __( 'Commission Rules', 'konx-affiliate-dashboard' ), $health['commission']['status'] ); ?>
				<?php self::health_item( $health['commission']['active_rules'] > 0 ? 'ok' : 'warning', sprintf( __( '%d active rules', 'konx-affiliate-dashboard' ), $health['commission']['active_rules'] ) ); ?>
			<?php self::health_card_close( admin_url( 'admin.php?page=konx-settings&tab=general' ), __( 'Manage Rules', 'konx-affiliate-dashboard' ) ); ?>
		</div>

		<p style="margin-top:20px;font-size:13px;color:#646970;">
			<?php
			printf(
				esc_html__( 'Need help? Visit the %s for guides and troubleshooting.', 'konx-affiliate-dashboard' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=konx-help' ) ) . '">' . esc_html__( 'Help Center', 'konx-affiliate-dashboard' ) . '</a>'
			);
			?>
		</p>
		<?php
	}

	// ------------------------------------------------------------------
	// Dashboard Card Helpers
	// ------------------------------------------------------------------

	private static function health_pill( $label, $value, $color ) {
		printf(
			'<div style="display:flex;align-items:center;gap:8px;padding:8px 16px;background:#fff;border:1px solid #c3c4c7;border-radius:20px;">'
			. '<strong style="font-size:12px;color:#1d2327;">%s:</strong>'
			. '<span style="font-size:12px;font-weight:600;color:%s;">%s</span></div>',
			esc_html( $label ),
			esc_attr( $color ),
			esc_html( $value )
		);
	}

	private static function health_card_open( $icon, $title, $status ) {
		$border = array( 'ok' => '#00a32a', 'warning' => '#dba617', 'error' => '#d63638', 'info' => '#2271b1' );
		$bc     = isset( $border[ $status ] ) ? $border[ $status ] : '#c3c4c7';
		printf(
			'<div style="background:#fff;border:1px solid #c3c4c7;border-top:3px solid %s;border-radius:6px;padding:16px;display:flex;flex-direction:column;">'
			. '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">'
			. '<span class="dashicons %s" style="color:%s;font-size:18px;"></span>'
			. '<h3 style="margin:0;font-size:14px;">%s</h3></div>',
			esc_attr( $bc ), esc_attr( $icon ), esc_attr( $bc ), esc_html( $title )
		);
	}

	private static function health_card_close( $url, $label ) {
		printf(
			'<div style="margin-top:auto;padding-top:12px;border-top:1px solid #f0f0f1;">'
			. '<a href="%s" class="button button-small">%s</a></div></div>',
			esc_url( $url ), esc_html( $label )
		);
	}

	private static function health_item( $status, $text ) {
		$icons  = array( 'ok' => '&#10003;', 'warning' => '&#9888;', 'error' => '&#10007;', 'info' => '&#8226;' );
		$colors = array( 'ok' => '#00a32a', 'warning' => '#dba617', 'error' => '#d63638', 'info' => '#646970' );
		$ic = isset( $icons[ $status ] ) ? $icons[ $status ] : '&#8226;';
		$cl = isset( $colors[ $status ] ) ? $colors[ $status ] : '#646970';
		printf(
			'<div style="display:flex;align-items:baseline;gap:6px;font-size:13px;line-height:1.8;">'
			. '<span style="color:%s;flex-shrink:0;">%s</span><span>%s</span></div>',
			esc_attr( $cl ), $ic, esc_html( $text ) // $ic is safe HTML entity.
		);
	}

	// ------------------------------------------------------------------
	// Migration Tab
	// ------------------------------------------------------------------

	private static function render_migration_tab() {
		$status = get_option( 'konx_migration_status', '' );
		?>
		<h2><?php esc_html_e( 'Data Migration', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Import affiliate data from PowerOf10 or CSV sources using the Migration Wizard.', 'konx-affiliate-dashboard' ); ?></p>

		<div class="konx-card" style="margin-top:16px;max-width:600px;">
			<p><strong><?php esc_html_e( 'Status:', 'konx-affiliate-dashboard' ); ?></strong>
			<?php
			if ( 'completed' === $status ) {
				echo '<span style="color:#00a32a;font-weight:600;">' . esc_html__( 'Completed', 'konx-affiliate-dashboard' ) . '</span>';
			} elseif ( in_array( $status, array( 'previewed', 'in_progress' ), true ) ) {
				echo '<span style="color:#dba617;font-weight:600;">' . esc_html__( 'In Progress', 'konx-affiliate-dashboard' ) . '</span>';
			} else {
				echo '<span style="color:#646970;">' . esc_html__( 'Not started', 'konx-affiliate-dashboard' ) . '</span>';
			}
			?>
			</p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-migration' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Open Migration Wizard', 'konx-affiliate-dashboard' ); ?>
			</a>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// General Tab (original settings form)
	// ------------------------------------------------------------------

	private static function render_general_tab() {
		$rates         = self::get_commission_rates();
		$recurring     = get_option( self::OPT_RECURRING_RATE, '0.1000' );
		$fee_settings  = get_option( self::OPT_ADMIN_FEES, array() );
		$general       = get_option( self::OPT_GENERAL, array() );
		$referral      = get_option( self::OPT_REFERRAL, array() );

		$recurring_pct = number_format( (float) $recurring * 100, 0 );
		$min_wd        = ! empty( $general['min_withdrawal'] ) ? $general['min_withdrawal'] : '50.00';
		$cookie_days   = ! empty( $referral['cookie_days'] ) ? $referral['cookie_days'] : 90;
		$ref_param     = ! empty( $referral['ref_param'] ) ? $referral['ref_param'] : 'ref';
		$dedup_hours   = ! empty( $referral['dedup_hours'] ) ? $referral['dedup_hours'] : 24;

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="konx_save_settings">
			<?php wp_nonce_field( 'konx_save_settings', 'konx_settings_nonce' ); ?>

			<!-- Commission Rates -->
			<div class="konx-form-card">
				<h2><?php esc_html_e( 'Commission Rates (%)', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'commission_rate' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
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
				<div class="konx-form-card">
					<h2><?php esc_html_e( 'Recurring Commission', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'recurring_commission' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
					<p class="description"><?php esc_html_e( 'Applied to subscription renewals. Same rate for all affiliate types.', 'konx-affiliate-dashboard' ); ?></p>
					<table class="form-table">
						<tr>
							<th><label for="recurring_rate"><?php esc_html_e( 'Rate', 'konx-affiliate-dashboard' ); ?></label></th>
							<td><input type="number" id="recurring_rate" name="recurring_rate" value="<?php echo esc_attr( $recurring_pct ); ?>" min="0" max="100" step="1" style="width:70px;"> %</td>
						</tr>
					</table>
				</div>
				<div class="konx-form-card">
					<h2><?php esc_html_e( 'Withdrawal Settings', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'min_withdrawal' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
					<table class="form-table">
						<tr>
							<th><label for="min_withdrawal"><?php esc_html_e( 'Minimum ($)', 'konx-affiliate-dashboard' ); ?></label></th>
							<td><input type="number" id="min_withdrawal" name="min_withdrawal" value="<?php echo esc_attr( $min_wd ); ?>" min="0" step="0.01" style="width:100px;"></td>
						</tr>
					</table>
				</div>
			</div>

			<div class="konx-grid-2">
				<div class="konx-form-card">
					<h2><?php esc_html_e( 'Monthly Admin Fees ($)', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'admin_fee' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
					<p class="description"><?php esc_html_e( 'Leave blank to use the default.', 'konx-affiliate-dashboard' ); ?></p>
					<table class="form-table">
						<?php foreach ( self::$affiliate_types as $aff_type => $aff_label ) : ?>
							<tr>
								<th><label for="fee_<?php echo esc_attr( $aff_type ); ?>"><?php echo esc_html( $aff_label ); ?></label></th>
								<td>$<input type="number" id="fee_<?php echo esc_attr( $aff_type ); ?>" name="fees[<?php echo esc_attr( $aff_type ); ?>]" value="<?php echo esc_attr( ! empty( $fee_settings[ $aff_type ] ) ? $fee_settings[ $aff_type ] : '' ); ?>" min="0" step="0.01" style="width:90px;" placeholder="auto"></td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<th><label for="fee_default"><?php esc_html_e( 'Default', 'konx-affiliate-dashboard' ); ?></label></th>
							<td>$<input type="number" id="fee_default" name="fees[default]" value="<?php echo esc_attr( ! empty( $fee_settings['default'] ) ? $fee_settings['default'] : '10.00' ); ?>" min="0" step="0.01" style="width:90px;"></td>
						</tr>
					</table>
				</div>
				<div class="konx-form-card">
					<h2><?php esc_html_e( 'Referral Tracking', 'konx-affiliate-dashboard' ); ?></h2>
					<table class="form-table">
						<tr>
							<th><label for="cookie_days"><?php esc_html_e( 'Remember Referral For', 'konx-affiliate-dashboard' ); ?></label></th>
							<td><input type="number" id="cookie_days" name="cookie_days" value="<?php echo esc_attr( $cookie_days ); ?>" min="1" max="365" step="1" style="width:80px;"> <?php esc_html_e( 'days', 'konx-affiliate-dashboard' ); ?></td>
						</tr>
						<tr>
							<th><label for="ref_param"><?php esc_html_e( 'Link Parameter', 'konx-affiliate-dashboard' ); ?></label></th>
							<td><input type="text" id="ref_param" name="ref_param" value="<?php echo esc_attr( $ref_param ); ?>" style="width:80px;"></td>
						</tr>
						<tr>
							<th><label for="dedup_hours"><?php esc_html_e( 'Ignore Repeat Clicks Within', 'konx-affiliate-dashboard' ); ?></label></th>
							<td><input type="number" id="dedup_hours" name="dedup_hours" value="<?php echo esc_attr( $dedup_hours ); ?>" min="1" max="168" step="1" style="width:80px;"> <?php esc_html_e( 'hours', 'konx-affiliate-dashboard' ); ?></td>
						</tr>
					</table>
				</div>
			</div>

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
							<p class="description" style="color:#d63638;"><?php esc_html_e( 'This cannot be undone.', 'konx-affiliate-dashboard' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<?php submit_button( __( 'Save All Settings', 'konx-affiliate-dashboard' ) ); ?>
		</form>
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
			$referral['cookie_days'] = absint( $_POST['cookie_days'] ) ?: 90;
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
		wp_safe_redirect( admin_url( 'admin.php?page=konx-settings&tab=general' ) );
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
		return ! empty( $settings['cookie_days'] ) ? absint( $settings['cookie_days'] ) : 90;
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
