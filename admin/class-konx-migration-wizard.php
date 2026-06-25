<?php
/**
 * Migration Wizard admin page.
 *
 * Guides administrators through reviewing PowerOf10 data before migration.
 * This is a review-and-approve workflow — no data is written until the
 * separate execution phase.
 *
 * Steps: Welcome → Health Check → Type Mapping → Sponsors → Conflicts
 *        → Preview → Dry Run → Approval
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Migration_Wizard
 */
class Konx_Migration_Wizard {

	/**
	 * Step definitions.
	 *
	 * @var array
	 */
	private static $steps = array(
		'welcome'     => 'Welcome',
		'health'      => 'Health Check',
		'types'       => 'Type Mapping',
		'sponsors'    => 'Sponsors',
		'conflicts'   => 'Conflicts',
		'preview'     => 'Preview',
		'dry-run'     => 'Dry Run',
		'approval'    => 'Approval',
	);

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_konx_migration_scan', array( __CLASS__, 'handle_scan' ) );
		add_action( 'admin_post_konx_migration_dry_run', array( __CLASS__, 'handle_dry_run' ) );
		add_action( 'admin_post_konx_migration_approve', array( __CLASS__, 'handle_approve' ) );
	}

	/**
	 * Register the submenu page.
	 */
	public static function register_menu() {
		add_submenu_page(
			'konx-affiliate-dashboard',
			__( 'Migration Wizard', 'konx-affiliate-dashboard' ),
			__( 'Migration', 'konx-affiliate-dashboard' ),
			'manage_konx_settings',
			'konx-migration',
			array( __CLASS__, 'render_page' )
		);
	}

	// ------------------------------------------------------------------
	// Page Router
	// ------------------------------------------------------------------

	/**
	 * Render the wizard page — routes to the active step.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = isset( $_GET['step'] ) ? sanitize_text_field( wp_unslash( $_GET['step'] ) ) : 'welcome';
		if ( ! isset( self::$steps[ $step ] ) ) {
			$step = 'welcome';
		}

		$feedback = self::get_feedback();
		$state    = get_option( 'konx_migration_state', array() );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Migration Wizard', 'konx-affiliate-dashboard' ); ?></h1>

			<?php self::render_progress_bar( $step ); ?>

			<?php if ( $feedback ) : ?>
				<div class="notice notice-<?php echo esc_attr( $feedback['type'] ); ?> is-dismissible" style="margin:16px 0;">
					<p><?php echo esc_html( $feedback['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<div style="max-width:960px;margin-top:20px;">
				<?php
				switch ( $step ) {
					case 'health':
						self::render_health( $state );
						break;
					case 'types':
						self::render_types( $state );
						break;
					case 'sponsors':
						self::render_sponsors( $state );
						break;
					case 'conflicts':
						self::render_conflicts( $state );
						break;
					case 'preview':
						self::render_preview( $state );
						break;
					case 'dry-run':
						self::render_dry_run( $state );
						break;
					case 'approval':
						self::render_approval( $state );
						break;
					default:
						self::render_welcome( $state );
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Progress Bar
	// ------------------------------------------------------------------

	/**
	 * Render the horizontal step progress bar.
	 *
	 * @param string $current_step The active step slug.
	 */
	private static function render_progress_bar( $current_step ) {
		$slugs   = array_keys( self::$steps );
		$current = array_search( $current_step, $slugs, true );

		echo '<div style="display:flex;gap:4px;align-items:center;margin:16px 0;flex-wrap:wrap;">';
		foreach ( self::$steps as $slug => $label ) {
			$idx = array_search( $slug, $slugs, true );
			if ( $idx < $current ) {
				$bg = '#00a32a'; $color = '#fff';
			} elseif ( $idx === $current ) {
				$bg = '#2271b1'; $color = '#fff';
			} else {
				$bg = '#e0e0e0'; $color = '#646970';
			}
			$url = admin_url( 'admin.php?page=konx-migration&step=' . $slug );
			printf(
				'<a href="%s" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:4px;background:%s;color:%s;text-decoration:none;font-size:12px;font-weight:600;white-space:nowrap;">%s %s</a>',
				esc_url( $url ),
				esc_attr( $bg ),
				esc_attr( $color ),
				$idx < $current ? '&#10003;' : esc_html( $idx + 1 ),
				esc_html( $label )
			);
		}
		echo '</div>';
	}

	// ------------------------------------------------------------------
	// Step 1: Welcome
	// ------------------------------------------------------------------

	/**
	 * Render the Welcome step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_welcome( $state ) {
		$scan     = isset( $state['scan'] ) ? $state['scan'] : null;
		$scan_at  = isset( $state['scan_at'] ) ? $state['scan_at'] : null;
		$approved = ! empty( $state['approved'] );

		?>
		<div class="konx-card" style="margin-bottom:20px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'PowerOf10 Migration', 'konx-affiliate-dashboard' ); ?></h2>
			<p><?php esc_html_e( 'This wizard guides you through importing PowerOf10 affiliate data into KonX Affiliates. No data will be changed until you explicitly approve and execute the migration.', 'konx-affiliate-dashboard' ); ?></p>

			<?php if ( $scan ) : ?>
				<div class="konx-stats-grid" style="margin:16px 0;">
					<?php self::stat_card( $scan['po10_users'], __( 'PowerOf10 Users', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
					<?php self::stat_card( $scan['wp_users'], __( 'WordPress Users', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
					<?php self::stat_card( $scan['konx_affiliates'], __( 'KonX Affiliates', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
					<?php self::stat_card( $scan['missing_wp_users'], __( 'Missing WP Users', 'konx-affiliate-dashboard' ), $scan['missing_wp_users'] > 0 ? '#dba617' : '#00a32a' ); ?>
				</div>
				<p class="description">
					<?php
					printf(
						esc_html__( 'Last scanned: %s', 'konx-affiliate-dashboard' ),
						esc_html( date_i18n( 'M j, Y g:ia', strtotime( $scan_at ) ) )
					);
					?>
				</p>
			<?php else : ?>
				<p style="color:#646970;"><?php esc_html_e( 'No scan data available yet. Click "Run Fresh Scan" to begin.', 'konx-affiliate-dashboard' ); ?></p>
			<?php endif; ?>

			<?php if ( $approved ) : ?>
				<div class="notice notice-success inline" style="margin:12px 0;">
					<p><?php esc_html_e( 'Migration plan has been approved. Ready for execution phase.', 'konx-affiliate-dashboard' ); ?></p>
				</div>
			<?php endif; ?>
		</div>

		<div style="display:flex;gap:8px;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="konx_migration_scan">
				<?php wp_nonce_field( 'konx_migration_scan', 'konx_mig_nonce' ); ?>
				<?php submit_button( $scan ? __( 'Run Fresh Scan', 'konx-affiliate-dashboard' ) : __( 'Start Scan', 'konx-affiliate-dashboard' ), 'primary', '', false ); ?>
			</form>
			<?php if ( $scan ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-migration&step=health' ) ); ?>" class="button"><?php esc_html_e( 'Continue to Health Check', 'konx-affiliate-dashboard' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Step 2: Health Check
	// ------------------------------------------------------------------

	/**
	 * Render the Health Check step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_health( $state ) {
		$scan = isset( $state['scan'] ) ? $state['scan'] : null;
		if ( ! $scan ) {
			self::render_no_scan();
			return;
		}

		$checks = array(
			array( 'label' => __( 'PowerOf10 Users', 'konx-affiliate-dashboard' ), 'value' => number_format( $scan['po10_users'] ), 'status' => $scan['po10_users'] > 0 ? 'ok' : 'error' ),
			array( 'label' => __( 'WordPress Users', 'konx-affiliate-dashboard' ), 'value' => number_format( $scan['wp_users'] ), 'status' => 'ok' ),
			array( 'label' => __( 'Existing KonX Affiliates', 'konx-affiliate-dashboard' ), 'value' => number_format( $scan['konx_affiliates'] ), 'status' => 'ok' ),
			array( 'label' => __( 'Coupon Affiliates Records', 'konx-affiliate-dashboard' ), 'value' => number_format( $scan['coupon_affiliates'] ), 'status' => 'ok' ),
			array( 'label' => __( 'PO10 Matched to WordPress', 'konx-affiliate-dashboard' ), 'value' => number_format( $scan['po10_matched_to_wp'] ), 'status' => 'ok' ),
			array( 'label' => __( 'Missing WordPress Users', 'konx-affiliate-dashboard' ), 'value' => number_format( $scan['missing_wp_users'] ), 'status' => $scan['missing_wp_users'] > 0 ? 'warning' : 'ok' ),
			array( 'label' => __( 'Missing Affiliate Types', 'konx-affiliate-dashboard' ), 'value' => number_format( $scan['missing_types'] ), 'status' => $scan['missing_types'] > 0 ? 'warning' : 'ok' ),
			array( 'label' => __( 'Unique Referral Codes', 'konx-affiliate-dashboard' ), 'value' => number_format( $scan['unique_codes'] ), 'status' => 'ok' ),
			array( 'label' => __( 'Resolved Sponsors', 'konx-affiliate-dashboard' ), 'value' => number_format( $scan['resolved_sponsors'] ), 'status' => 'ok' ),
			array( 'label' => __( 'Missing Sponsors', 'konx-affiliate-dashboard' ), 'value' => number_format( $scan['missing_sponsors'] ), 'status' => $scan['missing_sponsors'] > 0 ? 'warning' : 'ok' ),
			array( 'label' => __( 'Self-Referrals', 'konx-affiliate-dashboard' ), 'value' => number_format( $scan['self_referrals'] ), 'status' => $scan['self_referrals'] > 0 ? 'warning' : 'ok' ),
			array( 'label' => __( 'Duplicate Emails', 'konx-affiliate-dashboard' ), 'value' => number_format( $scan['duplicate_emails'] ), 'status' => $scan['duplicate_emails'] > 0 ? 'error' : 'ok' ),
			array( 'label' => __( 'Duplicate Referral Codes', 'konx-affiliate-dashboard' ), 'value' => number_format( $scan['duplicate_codes'] ), 'status' => $scan['duplicate_codes'] > 0 ? 'error' : 'ok' ),
		);

		?>
		<h2><?php esc_html_e( 'Data Health Check', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Overview of data readiness across all sources.', 'konx-affiliate-dashboard' ); ?></p>

		<table class="widefat fixed striped" style="margin:16px 0;">
			<thead><tr>
				<th><?php esc_html_e( 'Check', 'konx-affiliate-dashboard' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
				<th style="width:120px;"><?php esc_html_e( 'Value', 'konx-affiliate-dashboard' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $checks as $c ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $c['label'] ); ?></strong></td>
						<td><?php echo wp_kses_post( self::badge( $c['status'] ) ); ?></td>
						<td><?php echo esc_html( $c['value'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php self::render_nav( 'welcome', 'types' ); ?>
		<?php
	}

	// ------------------------------------------------------------------
	// Step 3: Affiliate Type Mapping
	// ------------------------------------------------------------------

	/**
	 * Render the Type Mapping step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_types( $state ) {
		if ( ! isset( $state['scan'] ) ) { self::render_no_scan(); return; }

		$engine = new Konx_Migration_Engine();
		$types  = $engine->analyze_affiliate_types();

		$normalized_cnt = 0;
		$defaulted_cnt  = 0;
		foreach ( $types['types'] as $t ) {
			if ( 'normalized' === $t['status'] ) { $normalized_cnt += $t['count']; }
			if ( 'defaulted' === $t['status'] ) { $defaulted_cnt += $t['count']; }
		}

		?>
		<h2><?php esc_html_e( 'Affiliate Type Mapping', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'PowerOf10 affiliate types are normalized to KonX types automatically.', 'konx-affiliate-dashboard' ); ?></p>

		<div class="konx-stats-grid" style="margin:16px 0;">
			<?php self::stat_card( $types['total'], __( 'Total Users', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
			<?php self::stat_card( $normalized_cnt, __( 'Normalized', 'konx-affiliate-dashboard' ), $normalized_cnt > 0 ? '#dba617' : '#00a32a' ); ?>
			<?php self::stat_card( $defaulted_cnt, __( 'Defaulted', 'konx-affiliate-dashboard' ), $defaulted_cnt > 0 ? '#dba617' : '#00a32a' ); ?>
			<?php self::stat_card( count( $types['unmapped'] ), __( 'Unmapped', 'konx-affiliate-dashboard' ), count( $types['unmapped'] ) > 0 ? '#d63638' : '#00a32a' ); ?>
		</div>

		<table class="widefat fixed striped" style="margin:16px 0;">
			<thead><tr>
				<th><?php esc_html_e( 'Source Value', 'konx-affiliate-dashboard' ); ?></th>
				<th style="width:40px;text-align:center;">&rarr;</th>
				<th><?php esc_html_e( 'KonX Type', 'konx-affiliate-dashboard' ); ?></th>
				<th style="width:100px;"><?php esc_html_e( 'Count', 'konx-affiliate-dashboard' ); ?></th>
				<th style="width:100px;"><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $types['types'] as $t ) : ?>
					<?php
					$badge_type = 'auto' === $t['status'] ? 'ok' : ( 'unmapped' === $t['status'] ? 'error' : 'warning' );
					$badge_label = ucfirst( $t['status'] );
					?>
					<tr>
						<td><code><?php echo esc_html( $t['source_value'] ); ?></code></td>
						<td style="text-align:center;">&rarr;</td>
						<td><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $t['normalized'] ) ) ); ?></strong></td>
						<td><?php echo esc_html( number_format( $t['count'] ) ); ?></td>
						<td><?php echo wp_kses_post( self::badge( $badge_type, $badge_label ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="konx-card" style="background:#f9f9f9;margin:16px 0;">
			<p><strong><?php esc_html_e( 'Need help?', 'konx-affiliate-dashboard' ); ?></strong>
			<?php esc_html_e( 'Normalized means a spelling variant was auto-corrected (e.g. "salesagent" to "sales_agent"). Defaulted means the source had no type and was assigned "Sales Agent" as default.', 'konx-affiliate-dashboard' ); ?></p>
		</div>

		<?php self::render_nav( 'health', 'sponsors' ); ?>
		<?php
	}

	// ------------------------------------------------------------------
	// Step 4: Sponsor Review
	// ------------------------------------------------------------------

	/**
	 * Render the Sponsor Relationship Review step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_sponsors( $state ) {
		if ( ! isset( $state['scan'] ) ) { self::render_no_scan(); return; }

		$engine   = new Konx_Migration_Engine();
		$sponsors = $engine->analyze_sponsors();

		?>
		<h2><?php esc_html_e( 'Sponsor Relationships', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Review the sponsor/upline hierarchy that will be imported from PowerOf10.', 'konx-affiliate-dashboard' ); ?></p>

		<div class="konx-stats-grid" style="margin:16px 0;">
			<?php self::stat_card( $sponsors['total_with_sponsor'], __( 'Have Sponsor', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
			<?php self::stat_card( $sponsors['resolved'], __( 'Resolved', 'konx-affiliate-dashboard' ), '#00a32a' ); ?>
			<?php self::stat_card( $sponsors['orphaned_total'], __( 'Orphaned', 'konx-affiliate-dashboard' ), $sponsors['orphaned_total'] > 0 ? '#d63638' : '#00a32a' ); ?>
			<?php self::stat_card( $sponsors['self_referrals'], __( 'Self-Referral', 'konx-affiliate-dashboard' ), $sponsors['self_referrals'] > 0 ? '#dba617' : '#00a32a' ); ?>
		</div>

		<?php if ( $sponsors['orphaned_total'] > 0 ) : ?>
			<div class="notice notice-warning inline" style="margin:12px 0;">
				<p><strong><?php esc_html_e( 'Orphaned sponsors will not be linked during migration.', 'konx-affiliate-dashboard' ); ?></strong>
				<?php esc_html_e( 'These users reference sponsors that no longer exist in the PowerOf10 database (likely deleted accounts). Their parent_affiliate_id will be set to NULL.', 'konx-affiliate-dashboard' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Top Orphaned Sponsors', 'konx-affiliate-dashboard' ); ?></h3>
			<table class="widefat fixed striped" style="max-width:500px;margin-bottom:20px;">
				<thead><tr>
					<th><?php esc_html_e( 'Sponsor Name', 'konx-affiliate-dashboard' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( 'Affected Users', 'konx-affiliate-dashboard' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $sponsors['orphaned_details'] as $o ) : ?>
						<tr>
							<td><code><?php echo esc_html( $o->sponsor_name ); ?></code></td>
							<td><?php echo esc_html( number_format( (int) $o->affected_users ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $sponsors['largest_teams'] ) ) : ?>
			<h3><?php esc_html_e( 'Largest Teams', 'konx-affiliate-dashboard' ); ?></h3>
			<table class="widefat fixed striped" style="max-width:500px;margin-bottom:20px;">
				<thead><tr>
					<th><?php esc_html_e( 'Sponsor', 'konx-affiliate-dashboard' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( 'Direct Members', 'konx-affiliate-dashboard' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $sponsors['largest_teams'] as $t ) : ?>
						<tr>
							<td><code><?php echo esc_html( $t->sponsor ); ?></code></td>
							<td><?php echo esc_html( number_format( (int) $t->team_size ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $sponsors['sample_tree'] ) ) : ?>
			<h3><?php esc_html_e( 'Sample Team Tree', 'konx-affiliate-dashboard' ); ?></h3>
			<div class="konx-card" style="font-family:monospace;font-size:13px;">
				<strong><?php echo esc_html( $sponsors['sample_tree']['root']['team_name'] ); ?></strong>
				<span class="description">(<?php echo esc_html( $sponsors['sample_tree']['root']['type'] ); ?>)</span>
				<?php foreach ( $sponsors['sample_tree']['children'] as $child ) : ?>
					<br>&nbsp;&nbsp;&#x251C;&#x2500;&#x2500; <?php echo esc_html( $child['team_name'] ); ?>
					<span class="description">(<?php echo esc_html( $child['type'] ); ?>)</span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php self::render_nav( 'types', 'conflicts' ); ?>
		<?php
	}

	// ------------------------------------------------------------------
	// Step 5: Conflict Review
	// ------------------------------------------------------------------

	/**
	 * Render the Conflict Review step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_conflicts( $state ) {
		if ( ! isset( $state['scan'] ) ) { self::render_no_scan(); return; }

		$engine    = new Konx_Migration_Engine();
		$conflicts = $engine->detect_conflicts();

		?>
		<h2><?php esc_html_e( 'Conflict Review', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Data conflicts that may affect migration. Critical issues will cause records to be skipped.', 'konx-affiliate-dashboard' ); ?></p>

		<div class="konx-stats-grid" style="margin:16px 0;">
			<?php self::stat_card( $conflicts['critical_count'], __( 'Critical', 'konx-affiliate-dashboard' ), $conflicts['critical_count'] > 0 ? '#d63638' : '#00a32a' ); ?>
			<?php self::stat_card( $conflicts['warning_count'], __( 'Warnings', 'konx-affiliate-dashboard' ), $conflicts['warning_count'] > 0 ? '#dba617' : '#00a32a' ); ?>
			<?php self::stat_card( count( $conflicts['existing_affiliates'] ), __( 'Existing', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
		</div>

		<?php if ( ! empty( $conflicts['duplicate_codes'] ) ) : ?>
			<h3><?php esc_html_e( 'Duplicate Referral Codes', 'konx-affiliate-dashboard' ); ?>
				<?php echo wp_kses_post( self::badge( 'error', __( 'Critical', 'konx-affiliate-dashboard' ) ) ); ?></h3>
			<p class="description"><?php esc_html_e( 'These codes appear more than once (case-insensitive). The second occurrence will be skipped during migration.', 'konx-affiliate-dashboard' ); ?></p>
			<table class="widefat fixed striped" style="margin-bottom:20px;">
				<thead><tr>
					<th><?php esc_html_e( 'Code', 'konx-affiliate-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Variants', 'konx-affiliate-dashboard' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'Count', 'konx-affiliate-dashboard' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $conflicts['duplicate_codes'] as $d ) : ?>
						<tr>
							<td><code><?php echo esc_html( $d->code ); ?></code></td>
							<td><?php echo esc_html( $d->variants ); ?></td>
							<td><?php echo esc_html( $d->cnt ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $conflicts['invalid_emails'] > 0 ) : ?>
			<h3><?php esc_html_e( 'Invalid Emails', 'konx-affiliate-dashboard' ); ?>
				<?php echo wp_kses_post( self::badge( 'warning', __( 'Warning', 'konx-affiliate-dashboard' ) ) ); ?></h3>
			<p class="description"><?php printf( esc_html__( '%d users have missing or invalid email addresses and will be skipped.', 'konx-affiliate-dashboard' ), $conflicts['invalid_emails'] ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $conflicts['self_referrals'] ) ) : ?>
			<h3><?php esc_html_e( 'Self-Referrals', 'konx-affiliate-dashboard' ); ?>
				<?php echo wp_kses_post( self::badge( 'warning', __( 'Warning', 'konx-affiliate-dashboard' ) ) ); ?></h3>
			<p class="description"><?php esc_html_e( 'These users list themselves as their own sponsor. Their parent will be set to NULL.', 'konx-affiliate-dashboard' ); ?></p>
			<table class="widefat fixed striped" style="margin-bottom:20px;">
				<thead><tr>
					<th style="width:80px;"><?php esc_html_e( 'PO10 ID', 'konx-affiliate-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Email', 'konx-affiliate-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Team Name', 'konx-affiliate-dashboard' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $conflicts['self_referrals'] as $sr ) : ?>
						<tr>
							<td><?php echo esc_html( $sr->id ); ?></td>
							<td><?php echo esc_html( $sr->email ); ?></td>
							<td><code><?php echo esc_html( $sr->team_name ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( empty( $conflicts['duplicate_codes'] ) && 0 === $conflicts['invalid_emails'] && empty( $conflicts['self_referrals'] ) ) : ?>
			<div class="notice notice-success inline" style="margin:12px 0;">
				<p><?php esc_html_e( 'No conflicts detected. All data is ready for migration.', 'konx-affiliate-dashboard' ); ?></p>
			</div>
		<?php endif; ?>

		<?php self::render_nav( 'sponsors', 'preview' ); ?>
		<?php
	}

	// ------------------------------------------------------------------
	// Step 6: Migration Preview
	// ------------------------------------------------------------------

	/**
	 * Render the Import Preview step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_preview( $state ) {
		if ( ! isset( $state['scan'] ) ) { self::render_no_scan(); return; }

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$limit  = 50;
		$offset = ( $page - 1 ) * $limit;

		$engine  = new Konx_Migration_Engine();
		$records = $engine->prepare_batch( $offset, $limit );
		$total   = isset( $state['scan']['po10_users'] ) ? (int) $state['scan']['po10_users'] : 0;
		$pages   = max( 1, (int) ceil( $total / $limit ) );

		?>
		<h2><?php esc_html_e( 'Import Preview', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description">
			<?php printf( esc_html__( 'Showing records %1$d-%2$d of %3$s. These are the planned import actions.', 'konx-affiliate-dashboard' ), $offset + 1, min( $offset + $limit, $total ), number_format( $total ) ); ?>
		</p>

		<div class="konx-table-wrap" style="margin:16px 0;">
			<table class="widefat fixed striped" style="font-size:12px;">
				<thead><tr>
					<th style="width:60px;"><?php esc_html_e( 'PO10 ID', 'konx-affiliate-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Email', 'konx-affiliate-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Name', 'konx-affiliate-dashboard' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Type', 'konx-affiliate-dashboard' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Code', 'konx-affiliate-dashboard' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Sponsor', 'konx-affiliate-dashboard' ); ?></th>
					<th style="width:70px;"><?php esc_html_e( 'Action', 'konx-affiliate-dashboard' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $records as $r ) : ?>
						<tr<?php echo 'skip' === $r['action'] ? ' style="opacity:0.5;"' : ''; ?>>
							<td><?php echo esc_html( $r['po10_id'] ); ?></td>
							<td><?php echo esc_html( $r['email'] ); ?></td>
							<td><?php echo esc_html( trim( $r['first_name'] . ' ' . $r['last_name'] ) ); ?></td>
							<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $r['affiliate_type'] ) ) ); ?></td>
							<td><code style="font-size:11px;"><?php echo esc_html( mb_substr( $r['referral_code'], 0, 15 ) ); ?></code></td>
							<td><code style="font-size:11px;"><?php echo esc_html( mb_substr( $r['parent_referral_code'], 0, 15 ) ); ?></code></td>
							<td>
								<?php if ( 'create' === $r['action'] ) : ?>
									<?php echo wp_kses_post( self::badge( 'ok', __( 'Create', 'konx-affiliate-dashboard' ) ) ); ?>
								<?php else : ?>
									<?php echo wp_kses_post( self::badge( 'error', __( 'Skip', 'konx-affiliate-dashboard' ) ) ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( $pages > 1 ) : ?>
			<div style="display:flex;gap:4px;margin-bottom:16px;">
				<?php for ( $i = 1; $i <= min( $pages, 10 ); $i++ ) : ?>
					<?php if ( $i === $page ) : ?>
						<span class="button button-primary" style="min-width:36px;text-align:center;"><?php echo esc_html( $i ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-migration&step=preview&paged=' . $i ) ); ?>" class="button" style="min-width:36px;text-align:center;"><?php echo esc_html( $i ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
				<?php if ( $pages > 10 ) : ?>
					<span class="description" style="padding:6px;">... <?php echo esc_html( $pages ); ?> <?php esc_html_e( 'pages', 'konx-affiliate-dashboard' ); ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php self::render_nav( 'conflicts', 'dry-run' ); ?>
		<?php
	}

	// ------------------------------------------------------------------
	// Step 7: Dry Run
	// ------------------------------------------------------------------

	/**
	 * Render the Dry Run step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_dry_run( $state ) {
		if ( ! isset( $state['scan'] ) ) { self::render_no_scan(); return; }

		$dr = isset( $state['dry_run'] ) ? $state['dry_run'] : null;

		?>
		<h2><?php esc_html_e( 'Dry Run', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Simulate the full migration without making any changes. This checks every record against WordPress for conflicts.', 'konx-affiliate-dashboard' ); ?></p>

		<?php if ( ! $dr ) : ?>
			<div class="konx-card" style="margin:16px 0;">
				<p><?php esc_html_e( 'No dry-run results yet. Click the button below to simulate the migration.', 'konx-affiliate-dashboard' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="konx_migration_dry_run">
					<?php wp_nonce_field( 'konx_migration_dry_run', 'konx_dr_nonce' ); ?>
					<?php submit_button( __( 'Run Dry Run', 'konx-affiliate-dashboard' ), 'primary', '', false ); ?>
				</form>
			</div>
		<?php else : ?>
			<div class="notice notice-info inline" style="margin:12px 0;">
				<p><strong><?php esc_html_e( 'No changes have been made.', 'konx-affiliate-dashboard' ); ?></strong> <?php esc_html_e( 'This is a simulation only.', 'konx-affiliate-dashboard' ); ?></p>
			</div>

			<div class="konx-stats-grid" style="margin:16px 0;">
				<?php self::stat_card( $dr['will_create_users'], __( 'WP Users to Create', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
				<?php self::stat_card( $dr['will_create_affiliates'], __( 'Affiliates to Create', 'konx-affiliate-dashboard' ), '#00a32a' ); ?>
				<?php self::stat_card( $dr['will_skip'], __( 'Records to Skip', 'konx-affiliate-dashboard' ), $dr['will_skip'] > 0 ? '#d63638' : '#00a32a' ); ?>
				<?php self::stat_card( $dr['will_link_sponsors'], __( 'Sponsor Links', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
				<?php self::stat_card( $dr['orphan_sponsors'], __( 'Orphan Sponsors', 'konx-affiliate-dashboard' ), $dr['orphan_sponsors'] > 0 ? '#dba617' : '#00a32a' ); ?>
				<?php self::stat_card( $dr['estimated_batches'], __( 'Estimated Batches', 'konx-affiliate-dashboard' ), '#646970' ); ?>
			</div>

			<?php if ( ! empty( $dr['by_type'] ) ) : ?>
				<h3><?php esc_html_e( 'By Affiliate Type', 'konx-affiliate-dashboard' ); ?></h3>
				<table class="widefat fixed striped" style="max-width:400px;margin-bottom:20px;">
					<thead><tr>
						<th><?php esc_html_e( 'Type', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:100px;"><?php esc_html_e( 'Count', 'konx-affiliate-dashboard' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $dr['by_type'] as $type => $count ) : ?>
							<tr>
								<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $type ) ) ); ?></td>
								<td><?php echo esc_html( number_format( $count ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $dr['errors'] ) ) : ?>
				<h3><?php esc_html_e( 'Records to Skip', 'konx-affiliate-dashboard' ); ?> (<?php echo esc_html( count( $dr['errors'] ) ); ?>)</h3>
				<table class="widefat fixed striped" style="margin-bottom:20px;font-size:12px;">
					<thead><tr>
						<th style="width:70px;"><?php esc_html_e( 'PO10 ID', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Email', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'konx-affiliate-dashboard' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $dr['errors'] as $err ) : ?>
							<tr>
								<td><?php echo esc_html( $err['po10_id'] ); ?></td>
								<td><?php echo esc_html( $err['email'] ); ?></td>
								<td><?php echo esc_html( implode( ', ', $err['errors'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
				<input type="hidden" name="action" value="konx_migration_dry_run">
				<?php wp_nonce_field( 'konx_migration_dry_run', 'konx_dr_nonce' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Re-run Dry Run', 'konx-affiliate-dashboard' ); ?></button>
			</form>
		<?php endif; ?>

		<?php self::render_nav( 'preview', 'approval' ); ?>
		<?php
	}

	// ------------------------------------------------------------------
	// Step 8: Approval
	// ------------------------------------------------------------------

	/**
	 * Render the Approval step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_approval( $state ) {
		$scan     = isset( $state['scan'] );
		$dr       = isset( $state['dry_run'] );
		$approved = ! empty( $state['approved'] );

		$checklist = array(
			array( 'done' => $scan, 'label' => __( 'Data scan completed', 'konx-affiliate-dashboard' ) ),
			array( 'done' => $scan, 'label' => __( 'Affiliate types reviewed', 'konx-affiliate-dashboard' ) ),
			array( 'done' => $scan, 'label' => __( 'Sponsor relationships reviewed', 'konx-affiliate-dashboard' ) ),
			array( 'done' => $scan, 'label' => __( 'Conflicts reviewed', 'konx-affiliate-dashboard' ) ),
			array( 'done' => $dr, 'label' => __( 'Dry run completed', 'konx-affiliate-dashboard' ) ),
		);

		$all_done = $scan && $dr;

		?>
		<h2><?php esc_html_e( 'Migration Approval', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Review the checklist and approve the migration plan. This does NOT execute the migration — it only records your approval.', 'konx-affiliate-dashboard' ); ?></p>

		<div class="konx-card" style="margin:16px 0;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Migration Checklist', 'konx-affiliate-dashboard' ); ?></h3>
			<?php foreach ( $checklist as $item ) : ?>
				<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f0f0f1;">
					<span style="width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;<?php echo $item['done'] ? 'background:#edfaef;color:#00a32a;' : 'background:#fcf0f1;color:#d63638;'; ?>">
						<?php echo $item['done'] ? '&#10003;' : '&#10007;'; ?>
					</span>
					<span><?php echo esc_html( $item['label'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $approved ) : ?>
			<div class="notice notice-success inline" style="margin:12px 0;">
				<p><strong><?php esc_html_e( 'Migration plan approved.', 'konx-affiliate-dashboard' ); ?></strong>
				<?php
				printf(
					esc_html__( 'Approved by user #%1$d on %2$s. The migration execution phase can now proceed.', 'konx-affiliate-dashboard' ),
					(int) $state['approved_by'],
					esc_html( date_i18n( 'M j, Y g:ia', strtotime( $state['approved_at'] ) ) )
				);
				?>
				</p>
			</div>
		<?php elseif ( $all_done ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="konx_migration_approve">
				<?php wp_nonce_field( 'konx_migration_approve', 'konx_approve_nonce' ); ?>
				<label style="display:flex;align-items:center;gap:8px;margin:16px 0;cursor:pointer;">
					<input type="checkbox" name="confirm" value="1" required>
					<strong><?php esc_html_e( 'I have reviewed the scan results, conflicts, and dry run. I understand that migration will create affiliate records in the database.', 'konx-affiliate-dashboard' ); ?></strong>
				</label>
				<?php submit_button( __( 'Approve Migration Plan', 'konx-affiliate-dashboard' ), 'primary', '', false ); ?>
			</form>
		<?php else : ?>
			<div class="notice notice-warning inline" style="margin:12px 0;">
				<p><?php esc_html_e( 'Complete all checklist items before approving. Run a scan and dry run first.', 'konx-affiliate-dashboard' ); ?></p>
			</div>
		<?php endif; ?>

		<?php self::render_nav( 'dry-run', null ); ?>
		<?php
	}

	// ------------------------------------------------------------------
	// Form Handlers
	// ------------------------------------------------------------------

	/**
	 * Handle the scan action — runs all analysis and stores results.
	 */
	public static function handle_scan() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		check_admin_referer( 'konx_migration_scan', 'konx_mig_nonce' );

		$engine = new Konx_Migration_Engine();
		$scan   = $engine->scan_data_sources();

		if ( is_wp_error( $scan ) ) {
			self::set_feedback( 'error', $scan->get_error_message() );
			wp_safe_redirect( admin_url( 'admin.php?page=konx-migration' ) );
			exit;
		}

		$state = get_option( 'konx_migration_state', array() );
		$state['scan']    = $scan;
		$state['scan_at'] = current_time( 'mysql', true );
		// Clear stale dry-run and approval on fresh scan.
		unset( $state['dry_run'], $state['dry_run_at'], $state['approved'], $state['approved_by'], $state['approved_at'] );
		update_option( 'konx_migration_state', $state, false );

		self::set_feedback( 'success', __( 'Scan completed successfully.', 'konx-affiliate-dashboard' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=konx-migration&step=health' ) );
		exit;
	}

	/**
	 * Handle the dry-run action.
	 */
	public static function handle_dry_run() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		check_admin_referer( 'konx_migration_dry_run', 'konx_dr_nonce' );

		$engine = new Konx_Migration_Engine();
		$dr     = $engine->dry_run();

		$state = get_option( 'konx_migration_state', array() );
		$state['dry_run']    = $dr;
		$state['dry_run_at'] = current_time( 'mysql', true );
		// Clear approval on new dry-run.
		unset( $state['approved'], $state['approved_by'], $state['approved_at'] );
		update_option( 'konx_migration_state', $state, false );

		self::set_feedback( 'success', __( 'Dry run completed. No changes were made.', 'konx-affiliate-dashboard' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=konx-migration&step=dry-run' ) );
		exit;
	}

	/**
	 * Handle the approval action.
	 */
	public static function handle_approve() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		check_admin_referer( 'konx_migration_approve', 'konx_approve_nonce' );

		if ( empty( $_POST['confirm'] ) ) {
			self::set_feedback( 'error', __( 'You must check the confirmation box.', 'konx-affiliate-dashboard' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=konx-migration&step=approval' ) );
			exit;
		}

		$state = get_option( 'konx_migration_state', array() );
		$state['approved']    = true;
		$state['approved_by'] = get_current_user_id();
		$state['approved_at'] = current_time( 'mysql', true );
		update_option( 'konx_migration_state', $state, false );

		self::set_feedback( 'success', __( 'Migration plan approved. The execution phase can now proceed.', 'konx-affiliate-dashboard' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=konx-migration&step=approval' ) );
		exit;
	}

	// ------------------------------------------------------------------
	// UI Helpers
	// ------------------------------------------------------------------

	/**
	 * Render a stat card.
	 *
	 * @param int|string $value The number to display.
	 * @param string     $label The label below.
	 * @param string     $color Accent color hex.
	 */
	private static function stat_card( $value, $label, $color = '#2271b1' ) {
		printf(
			'<div class="konx-stat-card"><span class="konx-stat-value" style="color:%s;">%s</span><span class="konx-stat-label">%s</span></div>',
			esc_attr( $color ),
			esc_html( is_numeric( $value ) ? number_format( $value ) : $value ),
			esc_html( $label )
		);
	}

	/**
	 * Render a status badge.
	 *
	 * @param string      $status 'ok', 'warning', or 'error'.
	 * @param string|null $label  Custom label. Defaults to status name.
	 * @return string HTML badge.
	 */
	private static function badge( $status, $label = null ) {
		$colors = array(
			'ok'      => array( '#edfaef', '#00a32a' ),
			'warning' => array( '#fcf6e3', '#946800' ),
			'error'   => array( '#fcf0f1', '#d63638' ),
		);
		$c = isset( $colors[ $status ] ) ? $colors[ $status ] : $colors['warning'];

		if ( null === $label ) {
			$defaults = array( 'ok' => 'OK', 'warning' => 'Warning', 'error' => 'Critical' );
			$label = isset( $defaults[ $status ] ) ? $defaults[ $status ] : $status;
		}

		return sprintf(
			'<span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;background:%s;color:%s;">%s</span>',
			esc_attr( $c[0] ),
			esc_attr( $c[1] ),
			esc_html( $label )
		);
	}

	/**
	 * Render navigation buttons.
	 *
	 * @param string|null $prev Previous step slug or null.
	 * @param string|null $next Next step slug or null.
	 */
	private static function render_nav( $prev, $next ) {
		echo '<div style="display:flex;justify-content:space-between;margin-top:20px;">';
		if ( $prev ) {
			printf( '<a href="%s" class="button">&larr; %s</a>', esc_url( admin_url( 'admin.php?page=konx-migration&step=' . $prev ) ), esc_html__( 'Back', 'konx-affiliate-dashboard' ) );
		} else {
			echo '<span></span>';
		}
		if ( $next ) {
			printf( '<a href="%s" class="button button-primary">%s &rarr;</a>', esc_url( admin_url( 'admin.php?page=konx-migration&step=' . $next ) ), esc_html__( 'Continue', 'konx-affiliate-dashboard' ) );
		}
		echo '</div>';
	}

	/**
	 * Render the "no scan" notice.
	 */
	private static function render_no_scan() {
		echo '<div class="notice notice-warning inline"><p>';
		printf(
			'%s <a href="%s">%s</a>',
			esc_html__( 'No scan data available.', 'konx-affiliate-dashboard' ),
			esc_url( admin_url( 'admin.php?page=konx-migration' ) ),
			esc_html__( 'Run a scan first.', 'konx-affiliate-dashboard' )
		);
		echo '</p></div>';
	}

	/**
	 * Set feedback transient.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message.
	 */
	private static function set_feedback( $type, $message ) {
		set_transient( 'konx_migration_feedback', array( 'type' => $type, 'message' => $message ), 30 );
	}

	/**
	 * Get and clear feedback.
	 *
	 * @return array|false
	 */
	private static function get_feedback() {
		$f = get_transient( 'konx_migration_feedback' );
		if ( $f ) { delete_transient( 'konx_migration_feedback' ); }
		return $f;
	}
}
