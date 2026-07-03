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
		'welcome'            => 'Welcome',
		'source'             => 'Data Source',
		'field-mapping'      => 'Field Mapping',
		'health'             => 'Health Check',
		'types'              => 'Type Mapping',
		'sponsors'           => 'Sponsors',
		'conflicts'          => 'Conflicts',
		'sponsor-resolution' => 'Resolution',
		'existing-system'    => 'Existing System',
		'integrity-audit'    => 'Integrity Audit',
		'decision-matrix'    => 'Decision Matrix',
		'validation'         => 'Validation',
		'source-comparison'  => 'Comparison',
		'summary'            => 'Summary',
		'preview'            => 'Preview',
		'dry-run'            => 'Dry Run',
		'approval'           => 'Approval',
		'audit'              => 'Audit Report',
	);

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_konx_migration_scan', array( __CLASS__, 'handle_scan' ) );
		add_action( 'admin_post_konx_migration_csv_upload', array( __CLASS__, 'handle_csv_upload' ) );
		add_action( 'admin_post_konx_migration_run_validation', array( __CLASS__, 'handle_run_validation' ) );
		add_action( 'admin_post_konx_migration_export_validation', array( __CLASS__, 'handle_export_validation' ) );
		add_action( 'admin_post_konx_migration_export_comparison', array( __CLASS__, 'handle_export_comparison' ) );
		add_action( 'admin_post_konx_migration_export_summary', array( __CLASS__, 'handle_export_summary' ) );
		add_action( 'admin_post_konx_migration_save_resolutions', array( __CLASS__, 'handle_save_resolutions' ) );
		add_action( 'admin_post_konx_migration_export_decision_csv', array( __CLASS__, 'handle_export_decision_csv' ) );
		add_action( 'admin_post_konx_migration_dry_run', array( __CLASS__, 'handle_dry_run' ) );
		add_action( 'admin_post_konx_migration_approve', array( __CLASS__, 'handle_approve' ) );
		add_action( 'admin_post_konx_migration_export_audit_csv', array( __CLASS__, 'handle_export_audit_csv' ) );
		add_action( 'admin_post_konx_migration_export_audit_json', array( __CLASS__, 'handle_export_audit_json' ) );
		add_action( 'admin_post_konx_migration_export_integrity_csv', array( __CLASS__, 'handle_export_integrity_csv' ) );
		add_action( 'admin_post_konx_migration_export_integrity_json', array( __CLASS__, 'handle_export_integrity_json' ) );
	}

	/**
	 * Register the submenu page.
	 */
	public static function register_menu() {
		add_submenu_page(
			null,
			__( 'Migration Wizard', 'konx-affiliate-dashboard' ),
			'',
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
					case 'source':
						self::render_source( $state );
						break;
					case 'field-mapping':
						self::render_field_mapping( $state );
						break;
					case 'validation':
						self::render_validation( $state );
						break;
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
					case 'sponsor-resolution':
						self::render_sponsor_resolution( $state );
						break;
					case 'existing-system':
						self::render_existing_system( $state );
						break;
					case 'integrity-audit':
						self::render_integrity_audit( $state );
						break;
					case 'decision-matrix':
						self::render_decision_matrix( $state );
						break;
					case 'source-comparison':
						self::render_comparison( $state );
						break;
					case 'summary':
						self::render_summary( $state );
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
					case 'audit':
						self::render_audit( $state );
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
				<p style="color:#646970;"><?php esc_html_e( 'Upload a CSV file or run a database scan to begin.', 'konx-affiliate-dashboard' ); ?></p>
			<?php endif; ?>

			<?php if ( $approved ) : ?>
				<div class="notice notice-success inline" style="margin:12px 0;">
					<p><?php esc_html_e( 'Migration plan has been approved. Ready for execution phase.', 'konx-affiliate-dashboard' ); ?></p>
				</div>
			<?php endif; ?>
		</div>

		<div style="display:flex;gap:8px;">
			<?php if ( $scan ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-migration&step=health' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Continue to Health Check', 'konx-affiliate-dashboard' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-migration&step=source' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Choose Data Source', 'konx-affiliate-dashboard' ); ?></a>
			<?php endif; ?>
		</div>

		<?php if ( $scan ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-migration&step=health' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Continue to Health Check', 'konx-affiliate-dashboard' ); ?></a>
		<?php endif; ?>
		<?php
	}

	// ------------------------------------------------------------------
	// Step 2: Data Source Selection
	// ------------------------------------------------------------------

	/**
	 * Render the Data Source selection step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_source( $state ) {
		$source    = isset( $state['source'] ) ? $state['source'] : null;
		$csv_info  = isset( $state['csv_info'] ) ? $state['csv_info'] : null;
		$has_db    = false;

		// Check if local PO10 DB is accessible (developer convenience).
		$engine = new Konx_Migration_Engine();
		$has_db = $engine->test_po10_connection();

		?>
		<h2><?php esc_html_e( 'Choose Migration Data Source', 'konx-affiliate-dashboard' ); ?></h2>

		<div class="notice notice-info inline" style="margin:0 0 16px;">
			<p><strong><?php esc_html_e( 'CSV Upload is recommended for live migration.', 'konx-affiliate-dashboard' ); ?></strong>
			<?php esc_html_e( 'Direct database access should only be used in local development or controlled staging environments.', 'konx-affiliate-dashboard' ); ?></p>
		</div>

		<!-- CSV Upload (Recommended) -->
		<div class="konx-card" style="margin-bottom:16px;border-left:4px solid #00a32a;">
			<h3 style="margin-top:0;">
				<?php echo wp_kses_post( self::badge( 'ok', __( 'Recommended', 'konx-affiliate-dashboard' ) ) ); ?>
				<?php esc_html_e( 'CSV Upload from PowerOf10', 'konx-affiliate-dashboard' ); ?>
			</h3>
			<p class="description"><?php esc_html_e( 'Upload a CSV file exported from the PowerOf10 admin panel. This is the safest method for production migration — no database credentials needed.', 'konx-affiliate-dashboard' ); ?></p>

			<?php if ( $csv_info && 'csv' === $source ) : ?>
				<div class="notice notice-success inline" style="margin:8px 0;">
					<p>
						<?php
						printf(
							esc_html__( 'CSV loaded: %1$s rows, %2$s columns. File: %3$s', 'konx-affiliate-dashboard' ),
							esc_html( number_format( $csv_info['row_count'] ) ),
							esc_html( count( $csv_info['columns_found'] ) ),
							esc_html( $csv_info['file_name'] )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-top:12px;">
				<input type="hidden" name="action" value="konx_migration_csv_upload">
				<?php wp_nonce_field( 'konx_migration_csv_upload', 'konx_csv_nonce' ); ?>
				<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
					<div>
						<label for="konx-csv-file" style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">
							<?php esc_html_e( 'Select CSV File', 'konx-affiliate-dashboard' ); ?>
						</label>
						<input type="file" id="konx-csv-file" name="csv_file" accept=".csv" required>
					</div>
					<?php submit_button( __( 'Upload & Validate', 'konx-affiliate-dashboard' ), 'primary', '', false ); ?>
				</div>
				<p class="description" style="margin-top:8px;">
					<?php
					printf(
						esc_html__( 'Required columns: %s. Max file size: 10 MB.', 'konx-affiliate-dashboard' ),
						'<code>' . esc_html( implode( ', ', Konx_Migration_Engine::get_required_csv_columns() ) ) . '</code>'
					);
					?>
				</p>
			</form>
		</div>

		<!-- CSV Validation Results -->
		<?php if ( $csv_info && isset( $csv_info['columns_found'] ) ) : ?>
			<div class="konx-card" style="margin-bottom:16px;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'CSV Validation', 'konx-affiliate-dashboard' ); ?></h3>
				<table class="widefat fixed striped" style="max-width:600px;">
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Rows', 'konx-affiliate-dashboard' ); ?></strong></td>
							<td><?php echo esc_html( number_format( $csv_info['row_count'] ) ); ?></td>
							<td><?php echo wp_kses_post( self::badge( $csv_info['row_count'] > 0 ? 'ok' : 'error' ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Required Columns', 'konx-affiliate-dashboard' ); ?></strong></td>
							<td><?php echo empty( $csv_info['columns_missing'] ) ? esc_html__( 'All present', 'konx-affiliate-dashboard' ) : esc_html__( 'Missing: ', 'konx-affiliate-dashboard' ) . esc_html( implode( ', ', $csv_info['columns_missing'] ) ); ?></td>
							<td><?php echo wp_kses_post( self::badge( empty( $csv_info['columns_missing'] ) ? 'ok' : 'error' ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Optional Columns', 'konx-affiliate-dashboard' ); ?></strong></td>
							<td><?php echo empty( $csv_info['columns_optional'] ) ? esc_html__( 'None', 'konx-affiliate-dashboard' ) : esc_html( implode( ', ', $csv_info['columns_optional'] ) ); ?></td>
							<td><?php echo wp_kses_post( self::badge( 'ok' ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'File Size', 'konx-affiliate-dashboard' ); ?></strong></td>
							<td><?php echo esc_html( size_format( $csv_info['file_size'] ) ); ?></td>
							<td><?php echo wp_kses_post( self::badge( 'ok' ) ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<!-- Local Database (Developer Only) -->
		<div class="konx-card" style="margin-bottom:16px;border-left:4px solid #dba617;opacity:0.85;">
			<h3 style="margin-top:0;">
				<?php echo wp_kses_post( self::badge( 'warning', __( 'Developer Only', 'konx-affiliate-dashboard' ) ) ); ?>
				<?php esc_html_e( 'Local PowerOf10 Database Scan', 'konx-affiliate-dashboard' ); ?>
			</h3>
			<p class="description"><?php esc_html_e( 'Directly query the PowerOf10 database on this server. Only use for local development or controlled staging environments.', 'konx-affiliate-dashboard' ); ?></p>

			<?php if ( $has_db ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
					<input type="hidden" name="action" value="konx_migration_scan">
					<input type="hidden" name="source" value="database">
					<?php wp_nonce_field( 'konx_migration_scan', 'konx_mig_nonce' ); ?>
					<?php submit_button( __( 'Scan Local Database', 'konx-affiliate-dashboard' ), 'secondary', '', false ); ?>
				</form>
				<?php if ( 'database' === $source ) : ?>
					<div class="notice notice-success inline" style="margin:8px 0;">
						<p><?php esc_html_e( 'Local database scan loaded.', 'konx-affiliate-dashboard' ); ?></p>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<p style="color:#d63638;margin-top:8px;"><?php esc_html_e( 'PowerOf10 database not accessible on this server.', 'konx-affiliate-dashboard' ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( $source ) : ?>
			<?php self::render_nav( 'welcome', 'field-mapping' ); ?>
		<?php else : ?>
			<?php self::render_nav( 'welcome', null ); ?>
		<?php endif; ?>
		<?php
	}

	// ------------------------------------------------------------------
	// Step 3: Field Mapping
	// ------------------------------------------------------------------

	/**
	 * Render the CSV Field Mapping step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_field_mapping( $state ) {
		$mappings   = isset( $state['field_mappings'] ) ? $state['field_mappings'] : null;
		$csv_info   = isset( $state['csv_info'] ) ? $state['csv_info'] : null;
		$validation = null;

		if ( ! $mappings && ! $csv_info ) {
			?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'No CSV data available. Upload a CSV file from the Data Source step first.', 'konx-affiliate-dashboard' ); ?></p>
			</div>
			<?php
			self::render_nav( 'source', null );
			return;
		}

		if ( $mappings ) {
			$validation = Konx_CSV_Field_Mapper::validate_mappings( $mappings );
		}

		$target_fields = Konx_CSV_Field_Mapper::get_target_fields();

		?>
		<h2><?php esc_html_e( 'CSV Field Mapping', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Review how CSV columns are mapped to KonX fields. Auto-detected mappings are shown below.', 'konx-affiliate-dashboard' ); ?></p>

		<?php if ( $validation ) : ?>
			<div class="konx-stats-grid" style="margin:16px 0;">
				<?php self::stat_card( $validation['mapped_required'] . '/' . $validation['total_required'], __( 'Required Mapped', 'konx-affiliate-dashboard' ), empty( $validation['missing'] ) ? '#00a32a' : '#d63638' ); ?>
				<?php self::stat_card( $validation['mapped_optional'], __( 'Optional Mapped', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
				<?php self::stat_card( count( $validation['unmapped'] ), __( 'Unmapped Columns', 'konx-affiliate-dashboard' ), count( $validation['unmapped'] ) > 0 ? '#dba617' : '#00a32a' ); ?>
			</div>

			<?php if ( ! empty( $validation['missing'] ) ) : ?>
				<div class="notice notice-error inline" style="margin:0 0 16px;">
					<p><strong><?php esc_html_e( 'Missing required mappings:', 'konx-affiliate-dashboard' ); ?></strong> <?php echo esc_html( implode( ', ', $validation['missing'] ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $validation['duplicates'] ) ) : ?>
				<div class="notice notice-error inline" style="margin:0 0 16px;">
					<p><strong><?php esc_html_e( 'Duplicate target fields:', 'konx-affiliate-dashboard' ); ?></strong> <?php echo esc_html( implode( ', ', $validation['duplicates'] ) ); ?></p>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<!-- Mapping Table -->
		<table class="widefat fixed striped" style="margin:16px 0;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'CSV Column', 'konx-affiliate-dashboard' ); ?></th>
					<th style="width:40px;text-align:center;">&rarr;</th>
					<th><?php esc_html_e( 'KonX Field', 'konx-affiliate-dashboard' ); ?></th>
					<th style="width:100px;"><?php esc_html_e( 'Confidence', 'konx-affiliate-dashboard' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $mappings ) : ?>
					<?php foreach ( $mappings as $m ) : ?>
						<?php
						$is_required = ! empty( $m['target_field'] ) && isset( $target_fields[ $m['target_field'] ] ) && $target_fields[ $m['target_field'] ]['required'];
						$badge_type  = 'mapped' === $m['status'] ? 'ok' : 'warning';
						$conf_colors = array( 'exact' => '#00a32a', 'alias' => '#2271b1', 'none' => '#646970' );
						$conf_labels = array( 'exact' => __( 'Exact', 'konx-affiliate-dashboard' ), 'alias' => __( 'Alias', 'konx-affiliate-dashboard' ), 'none' => __( 'None', 'konx-affiliate-dashboard' ) );
						?>
						<tr<?php echo 'unmapped' === $m['status'] ? ' style="opacity:0.6;"' : ''; ?>>
							<td><code><?php echo esc_html( $m['csv_column'] ); ?></code></td>
							<td style="text-align:center;">&rarr;</td>
							<td>
								<?php if ( ! empty( $m['target_label'] ) ) : ?>
									<strong><?php echo esc_html( $m['target_label'] ); ?></strong>
									<?php if ( $is_required ) : ?>
										<span style="color:#d63638;font-size:11px;"> *</span>
									<?php endif; ?>
								<?php else : ?>
									<span style="color:#646970;"><?php esc_html_e( 'Not mapped', 'konx-affiliate-dashboard' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<span style="color:<?php echo esc_attr( $conf_colors[ $m['confidence'] ] ?? '#646970' ); ?>;font-size:12px;font-weight:600;">
									<?php echo esc_html( $conf_labels[ $m['confidence'] ] ?? $m['confidence'] ); ?>
								</span>
							</td>
							<td>
								<?php echo wp_kses_post( self::badge( $badge_type, 'mapped' === $m['status'] ? __( 'Mapped', 'konx-affiliate-dashboard' ) : __( 'Unmapped', 'konx-affiliate-dashboard' ) ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<div class="konx-card" style="background:#f9f9f9;margin:16px 0;">
			<p><strong><?php esc_html_e( 'Need help?', 'konx-affiliate-dashboard' ); ?></strong>
			<?php esc_html_e( 'Exact match means the CSV column name matches the expected field exactly. Alias means a recognized alternative name was detected (e.g. "firstname" for "First Name"). Unmapped columns are ignored during import.', 'konx-affiliate-dashboard' ); ?></p>
			<p class="description"><span style="color:#d63638;">*</span> <?php esc_html_e( 'Required fields must be mapped for migration to proceed.', 'konx-affiliate-dashboard' ); ?></p>
		</div>

		<?php
		$can_continue = $validation && $validation['valid'];
		self::render_nav( 'source', $can_continue ? 'health' : null );
	}

	// ------------------------------------------------------------------
	// Validation Preview
	// ------------------------------------------------------------------

	/**
	 * Render the Validation Preview step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_validation( $state ) {
		$vr = isset( $state['validation_results'] ) ? $state['validation_results'] : null;

		if ( ! isset( $state['scan'] ) ) {
			self::render_no_scan();
			return;
		}

		?>
		<h2><?php esc_html_e( 'Validation Preview', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Validate every record against business rules before proceeding. No data is written.', 'konx-affiliate-dashboard' ); ?></p>

		<?php if ( ! $vr ) : ?>
			<div class="konx-card" style="margin:16px 0;">
				<p><?php esc_html_e( 'Run validation to check all records for errors and warnings.', 'konx-affiliate-dashboard' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="konx_migration_run_validation">
					<?php wp_nonce_field( 'konx_migration_run_validation', 'konx_val_nonce' ); ?>
					<?php submit_button( __( 'Run Validation', 'konx-affiliate-dashboard' ), 'primary', '', false ); ?>
				</form>
			</div>
		<?php else : ?>
			<?php $s = $vr['summary']; ?>

			<!-- Summary Cards -->
			<div class="konx-stats-grid" style="margin:16px 0;">
				<?php self::stat_card( $s['total'], __( 'Total Records', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
				<?php self::stat_card( $s['valid'], __( 'Valid', 'konx-affiliate-dashboard' ), '#00a32a' ); ?>
				<?php self::stat_card( $s['with_warning'], __( 'Warnings', 'konx-affiliate-dashboard' ), $s['with_warning'] > 0 ? '#dba617' : '#00a32a' ); ?>
				<?php self::stat_card( $s['with_error'], __( 'Errors', 'konx-affiliate-dashboard' ), $s['with_error'] > 0 ? '#d63638' : '#00a32a' ); ?>
			</div>

			<?php if ( 0 === $s['error_count'] && 0 === $s['warning_count'] ) : ?>
				<div class="notice notice-success inline" style="margin:0 0 16px;">
					<p><?php esc_html_e( 'All records passed validation. No issues detected.', 'konx-affiliate-dashboard' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $s['error_count'] > 0 ) : ?>
				<div class="notice notice-error inline" style="margin:0 0 16px;">
					<p><?php printf( esc_html__( '%d error(s) found. Records with errors will be skipped during migration.', 'konx-affiliate-dashboard' ), $s['error_count'] ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Category Summary -->
			<?php if ( ! empty( $vr['by_category'] ) ) : ?>
				<h3><?php esc_html_e( 'Issues by Category', 'konx-affiliate-dashboard' ); ?></h3>
				<table class="widefat fixed striped" style="max-width:500px;margin-bottom:20px;">
					<thead><tr>
						<th><?php esc_html_e( 'Field', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Errors', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Warnings', 'konx-affiliate-dashboard' ); ?></th>
					</tr></thead>
					<tbody>
						<?php
						$field_labels = array(
							'email' => __( 'Email', 'konx-affiliate-dashboard' ),
							'team_name' => __( 'Team Name', 'konx-affiliate-dashboard' ),
							'promotional_title' => __( 'Affiliate Type', 'konx-affiliate-dashboard' ),
							'referrer_team_name' => __( 'Sponsor', 'konx-affiliate-dashboard' ),
							'user_fname' => __( 'First Name', 'konx-affiliate-dashboard' ),
							'user_lname' => __( 'Last Name', 'konx-affiliate-dashboard' ),
						);
						foreach ( $vr['by_category'] as $field => $field_issues ) :
							$errs = count( array_filter( $field_issues, function ( $i ) { return 'error' === $i['severity']; } ) );
							$warns = count( array_filter( $field_issues, function ( $i ) { return 'warning' === $i['severity']; } ) );
						?>
						<tr>
							<td><strong><?php echo esc_html( $field_labels[ $field ] ?? ucwords( str_replace( '_', ' ', $field ) ) ); ?></strong></td>
							<td style="color:<?php echo $errs > 0 ? '#d63638' : '#646970'; ?>;"><?php echo esc_html( $errs ); ?></td>
							<td style="color:<?php echo $warns > 0 ? '#dba617' : '#646970'; ?>;"><?php echo esc_html( $warns ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<!-- Issue Details (first 50) -->
			<?php if ( ! empty( $vr['issues'] ) ) : ?>
				<h3><?php esc_html_e( 'Issue Details', 'konx-affiliate-dashboard' ); ?>
					<span class="description" style="font-weight:normal;margin-left:8px;"><?php printf( esc_html__( 'Showing first %d of %d', 'konx-affiliate-dashboard' ), min( 50, count( $vr['issues'] ) ), count( $vr['issues'] ) ); ?></span>
				</h3>
				<div class="konx-table-wrap">
					<table class="widefat fixed striped" style="font-size:12px;margin-bottom:16px;">
						<thead><tr>
							<th style="width:60px;"><?php esc_html_e( 'Row', 'konx-affiliate-dashboard' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Severity', 'konx-affiliate-dashboard' ); ?></th>
							<th style="width:100px;"><?php esc_html_e( 'Field', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Issue', 'konx-affiliate-dashboard' ); ?></th>
							<th style="width:150px;"><?php esc_html_e( 'Value', 'konx-affiliate-dashboard' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( array_slice( $vr['issues'], 0, 50 ) as $issue ) : ?>
								<tr>
									<td><?php echo esc_html( $issue['row'] ); ?></td>
									<td><?php echo wp_kses_post( self::badge( 'error' === $issue['severity'] ? 'error' : 'warning', strtoupper( $issue['severity'] ) ) ); ?></td>
									<td><?php echo esc_html( $field_labels[ $issue['field'] ] ?? $issue['field'] ); ?></td>
									<td><?php echo esc_html( $issue['message'] ); ?></td>
									<td><code style="font-size:11px;"><?php echo esc_html( mb_substr( $issue['value'], 0, 40 ) ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<!-- Actions -->
			<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<input type="hidden" name="action" value="konx_migration_run_validation">
					<?php wp_nonce_field( 'konx_migration_run_validation', 'konx_val_nonce' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Re-run Validation', 'konx-affiliate-dashboard' ); ?></button>
				</form>
				<?php if ( ! empty( $vr['issues'] ) ) : ?>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=konx_migration_export_validation' ), 'konx_export_validation', 'konx_exp_nonce' ) ); ?>" class="button">
						<?php esc_html_e( 'Download Validation Report (CSV)', 'konx-affiliate-dashboard' ); ?>
					</a>
				<?php endif; ?>
			</div>

		<?php endif; ?>

		<?php
		$can_continue = $vr && ( 0 === $vr['summary']['with_error'] || $vr['summary']['valid'] > 0 );
		self::render_nav( 'decision-matrix', $can_continue ? 'source-comparison' : null );
	}

	// ------------------------------------------------------------------
	// Step 3: Health Check
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

		<?php self::render_nav( 'field-mapping', 'types' ); ?>
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

		$engine = self::build_engine_from_state();
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

		$engine   = self::build_engine_from_state();
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

		$engine    = self::build_engine_from_state();
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

		<?php self::render_nav( 'sponsors', 'sponsor-resolution' ); ?>
		<?php
	}



	// Sponsor Resolution Assistant
	// ------------------------------------------------------------------

	/**
	 * Normalize a team name for fuzzy matching.
	 *
	 * Strips spaces, dots, underscores, hyphens and lowercases.
	 *
	 * @param string $name Raw team name.
	 * @return string Normalized name.
	 */
	private static function normalize_sponsor_name( $name ) {
		return strtolower( str_replace( array( ' ', '.', '_', '-' ), '', trim( $name ) ) );
	}

	/**
	 * Build sponsor resolution proposals from source records.
	 *
	 * Classifies every orphan sponsor and suggests resolutions.
	 * Pure read-only — no database writes.
	 *
	 * @param array $state Migration state.
	 * @return array Array of proposals keyed by lowercase orphan name.
	 */
	private static function build_resolution_proposals( $state ) {
		$engine  = self::build_engine_from_state();
		$records = $engine->get_source_records();
		if ( empty( $records ) ) {
			return array();
		}

		// Build team_name indexes.
		$team_set       = array(); // lowercase => record
		$normalized_set = array(); // normalized => lowercase original
		foreach ( $records as $r ) {
			$tn = strtolower( trim( $r->team_name ) );
			if ( '' !== $tn ) {
				$team_set[ $tn ] = $r;
				$norm            = self::normalize_sponsor_name( $r->team_name );
				if ( ! isset( $normalized_set[ $norm ] ) ) {
					$normalized_set[ $norm ] = $tn;
				}
			}
		}

		// Collect orphans.
		$orphans = array(); // lowercase sponsor => { name, count, users[] }
		foreach ( $records as $r ) {
			$sponsor = trim( $r->referrer_team_name );
			$self_tn = trim( $r->team_name );
			if ( '' === $sponsor || strtolower( $sponsor ) === strtolower( $self_tn ) ) {
				continue;
			}
			$sl = strtolower( $sponsor );
			if ( ! isset( $team_set[ $sl ] ) ) {
				if ( ! isset( $orphans[ $sl ] ) ) {
					$orphans[ $sl ] = array( 'name' => $sponsor, 'count' => 0, 'sample_users' => array() );
				}
				$orphans[ $sl ]['count']++;
				if ( count( $orphans[ $sl ]['sample_users'] ) < 3 ) {
					$orphans[ $sl ]['sample_users'][] = array(
						'id'        => $r->id,
						'team_name' => $r->team_name,
						'email'     => $r->email,
					);
				}
			}
		}

		// Classify each orphan.
		$proposals = array();
		foreach ( $orphans as $sl => $detail ) {
			$classification = 'missing';
			$suggestion     = '';
			$confidence     = 'low';

			// 1. Punctuation/space normalization match.
			$norm = self::normalize_sponsor_name( $detail['name'] );
			if ( isset( $normalized_set[ $norm ] ) && $normalized_set[ $norm ] !== $sl ) {
				$classification = 'punctuation';
				$suggestion     = $normalized_set[ $norm ];
				$confidence     = 'high';
			}

			// 2. Numbered variant (e.g. "rillo" → "rillo1").
			if ( 'missing' === $classification ) {
				$numbered = array();
				foreach ( $team_set as $existing_tn => $existing_r ) {
					if ( preg_match( '/^' . preg_quote( $sl, '/' ) . '\d+$/', $existing_tn ) ) {
						$numbered[ $existing_tn ] = $existing_r->id;
					}
				}
				if ( ! empty( $numbered ) ) {
					$classification = 'numbered';
					// Suggest the lowest-numbered variant.
					ksort( $numbered );
					$suggestion = array_key_first( $numbered );
					$confidence = 'medium';
				}
			}

			$proposals[ $sl ] = array(
				'orphan_name'    => $detail['name'],
				'affected_count' => $detail['count'],
				'classification' => $classification,
				'suggestion'     => $suggestion,
				'confidence'     => $confidence,
				'sample_users'   => $detail['sample_users'],
			);
		}

		// Sort: punctuation first, then numbered (by affected count desc), then missing.
		uasort( $proposals, function ( $a, $b ) {
			$order = array( 'punctuation' => 0, 'numbered' => 1, 'missing' => 2 );
			$ca    = $order[ $a['classification'] ] ?? 3;
			$cb    = $order[ $b['classification'] ] ?? 3;
			if ( $ca !== $cb ) {
				return $ca - $cb;
			}
			return $b['affected_count'] - $a['affected_count'];
		} );

		return $proposals;
	}

	/**
	 * Render the Sponsor Resolution Assistant step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_sponsor_resolution( $state ) {
		if ( ! isset( $state['scan'] ) ) {
			self::render_no_scan();
			return;
		}

		$proposals   = self::build_resolution_proposals( $state );
		$resolutions = isset( $state['sponsor_resolutions'] ) ? $state['sponsor_resolutions'] : array();
		$categories  = Konx_Product_Mapper::get_categories(); // unused but consistent style.

		// Count summaries.
		$total       = count( $proposals );
		$auto_count  = 0;
		$review_count = 0;
		$missing_count = 0;
		$resolved_count = 0;
		foreach ( $proposals as $sl => $p ) {
			if ( isset( $resolutions[ $sl ] ) ) {
				$resolved_count++;
			}
			if ( 'punctuation' === $p['classification'] ) {
				$auto_count++;
			} elseif ( 'numbered' === $p['classification'] ) {
				$review_count++;
			} else {
				$missing_count++;
			}
		}

		?>
		<h2><?php esc_html_e( 'Sponsor Resolution Assistant', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Review orphan sponsors and choose how to resolve them before migration. Decisions are saved in preview state only — no data is written to the database.', 'konx-affiliate-dashboard' ); ?></p>

		<?php if ( empty( $proposals ) ) : ?>
			<div class="notice notice-success inline" style="margin:12px 0;">
				<p><?php esc_html_e( 'No orphan sponsors detected. All sponsor references resolve correctly.', 'konx-affiliate-dashboard' ); ?></p>
			</div>
		<?php else : ?>
			<!-- Summary Cards -->
			<div class="konx-stats-grid" style="margin:16px 0;">
				<?php self::stat_card( $total, __( 'Orphan Sponsors', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
				<?php self::stat_card( $auto_count, __( 'Auto-Resolvable', 'konx-affiliate-dashboard' ), '#00a32a' ); ?>
				<?php self::stat_card( $review_count, __( 'Needs Review', 'konx-affiliate-dashboard' ), '#dba617' ); ?>
				<?php self::stat_card( $missing_count, __( 'Genuine Missing', 'konx-affiliate-dashboard' ), '#d63638' ); ?>
				<?php self::stat_card( $resolved_count . '/' . $total, __( 'Decisions Made', 'konx-affiliate-dashboard' ), $resolved_count === $total ? '#00a32a' : '#646970' ); ?>
			</div>

			<!-- Resolution Form -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="konx_migration_save_resolutions">
				<?php wp_nonce_field( 'konx_migration_save_resolutions', 'konx_res_nonce' ); ?>

				<table class="widefat fixed striped" style="margin:16px 0;font-size:13px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Orphan Sponsor', 'konx-affiliate-dashboard' ); ?></th>
							<th style="width:70px;"><?php esc_html_e( 'Users', 'konx-affiliate-dashboard' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Classification', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Suggestion', 'konx-affiliate-dashboard' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Confidence', 'konx-affiliate-dashboard' ); ?></th>
							<th style="width:180px;"><?php esc_html_e( 'Resolution', 'konx-affiliate-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $proposals as $sl => $p ) :
							$saved = isset( $resolutions[ $sl ] ) ? $resolutions[ $sl ] : '';
							$class_labels = array(
								'punctuation' => __( 'Punctuation', 'konx-affiliate-dashboard' ),
								'numbered'    => __( 'Numbered', 'konx-affiliate-dashboard' ),
								'missing'     => __( 'Missing', 'konx-affiliate-dashboard' ),
							);
							$class_colors = array(
								'punctuation' => '#00a32a',
								'numbered'    => '#dba617',
								'missing'     => '#d63638',
							);
							$conf_colors = array( 'high' => '#00a32a', 'medium' => '#dba617', 'low' => '#d63638' );
							?>
							<tr>
								<td>
									<code style="font-size:12px;"><?php echo esc_html( $p['orphan_name'] ); ?></code>
									<?php if ( ! empty( $p['sample_users'] ) ) : ?>
										<details style="margin-top:4px;">
											<summary style="cursor:pointer;font-size:11px;color:#2271b1;"><?php esc_html_e( 'Show affected', 'konx-affiliate-dashboard' ); ?></summary>
											<div style="font-size:11px;color:#646970;margin-top:4px;">
												<?php foreach ( $p['sample_users'] as $u ) : ?>
													<?php echo esc_html( "#{$u['id']} {$u['team_name']} ({$u['email']})" ); ?><br>
												<?php endforeach; ?>
												<?php if ( $p['affected_count'] > 3 ) : ?>
													<?php printf( esc_html__( '... and %d more', 'konx-affiliate-dashboard' ), $p['affected_count'] - 3 ); ?>
												<?php endif; ?>
											</div>
										</details>
									<?php endif; ?>
								</td>
								<td style="font-weight:600;"><?php echo esc_html( $p['affected_count'] ); ?></td>
								<td>
									<span style="color:<?php echo esc_attr( $class_colors[ $p['classification'] ] ?? '#646970' ); ?>;font-weight:600;font-size:12px;">
										<?php echo esc_html( $class_labels[ $p['classification'] ] ?? $p['classification'] ); ?>
									</span>
								</td>
								<td>
									<?php if ( $p['suggestion'] ) : ?>
										<code style="font-size:12px;"><?php echo esc_html( $p['suggestion'] ); ?></code>
									<?php else : ?>
										<span style="color:#646970;">&mdash;</span>
									<?php endif; ?>
								</td>
								<td>
									<span style="color:<?php echo esc_attr( $conf_colors[ $p['confidence'] ] ?? '#646970' ); ?>;font-size:12px;font-weight:600;">
										<?php echo esc_html( ucfirst( $p['confidence'] ) ); ?>
									</span>
								</td>
								<td>
									<select name="resolution[<?php echo esc_attr( $sl ); ?>]" style="width:100%;font-size:12px;">
										<option value=""><?php esc_html_e( '— Choose —', 'konx-affiliate-dashboard' ); ?></option>
										<?php if ( $p['suggestion'] ) : ?>
											<option value="accept" <?php selected( $saved, 'accept' ); ?>>
												<?php printf( esc_html__( 'Accept: %s', 'konx-affiliate-dashboard' ), esc_html( $p['suggestion'] ) ); ?>
											</option>
										<?php endif; ?>
										<option value="root" <?php selected( $saved, 'root' ); ?>><?php esc_html_e( 'Leave as Root', 'konx-affiliate-dashboard' ); ?></option>
										<option value="ignore" <?php selected( $saved, 'ignore' ); ?>><?php esc_html_e( 'Ignore (NULL parent)', 'konx-affiliate-dashboard' ); ?></option>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div style="display:flex;gap:8px;align-items:center;">
					<?php submit_button( __( 'Save Decisions', 'konx-affiliate-dashboard' ), 'primary', '', false ); ?>
					<span class="description"><?php esc_html_e( 'Saves to preview state only. No database changes.', 'konx-affiliate-dashboard' ); ?></span>
				</div>
			</form>
		<?php endif; ?>

		<?php self::render_nav( 'conflicts', 'existing-system' ); ?>
		<?php
	}

	/**
	 * Handle saving sponsor resolution decisions.
	 *
	 * Stores admin decisions in konx_migration_state only.
	 * No affiliates, users, or sponsors are modified.
	 */
	public static function handle_save_resolutions() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		check_admin_referer( 'konx_migration_save_resolutions', 'konx_res_nonce' );

		$raw = isset( $_POST['resolution'] ) && is_array( $_POST['resolution'] ) ? $_POST['resolution'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$resolutions = array();
		$valid_actions = array( 'accept', 'root', 'ignore' );
		foreach ( $raw as $key => $value ) {
			$key   = sanitize_text_field( wp_unslash( $key ) );
			$value = sanitize_text_field( wp_unslash( $value ) );
			if ( '' !== $value && in_array( $value, $valid_actions, true ) ) {
				$resolutions[ $key ] = $value;
			}
		}

		$state = get_option( 'konx_migration_state', array() );
		$state['sponsor_resolutions'] = $resolutions;
		// Clear dry-run and approval since resolution decisions changed.
		unset( $state['dry_run'], $state['dry_run_at'], $state['approved'], $state['approved_by'], $state['approved_at'] );
		update_option( 'konx_migration_state', $state, false );

		$count = count( $resolutions );
		self::set_feedback(
			'success',
			sprintf(
				__( '%d sponsor resolution(s) saved to preview state.', 'konx-affiliate-dashboard' ),
				$count
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=konx-migration&step=sponsor-resolution' ) );
		exit;
	}

	// ------------------------------------------------------------------
	// Existing System Analysis
	// ------------------------------------------------------------------

	/**
	 * Analyse CSV records against the existing WordPress environment.
	 *
	 * Checks WP users, Coupon Affiliates, WooCommerce customers,
	 * and existing KonX data. Pure read-only — no writes.
	 *
	 * @param array $state Migration state.
	 * @return array Analysis results.
	 */
	private static function analyse_existing_system( $state ) {
		global $wpdb;

		$engine  = self::build_engine_from_state();
		$records = $engine->get_source_records();

		if ( empty( $records ) ) {
			return array();
		}

		// --- Build lookup sets ---

		// WP users by email.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wp_users_raw = $wpdb->get_results( "SELECT ID, user_email, user_login, display_name FROM {$wpdb->users}" );
		$wp_by_email  = array();
		foreach ( $wp_users_raw as $u ) {
			$wp_by_email[ strtolower( $u->user_email ) ] = $u;
		}

		// Coupon Affiliates (if table exists).
		$ca_table  = $wpdb->prefix . 'wcusage_register';
		$ca_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ca_table ) ) === $ca_table );
		$ca_by_user = array();
		$ca_fields  = array();
		if ( $ca_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ca_rows = $wpdb->get_results( "SELECT * FROM {$ca_table}" );
			// Discover field names from first row.
			if ( ! empty( $ca_rows ) ) {
				$ca_fields = array_keys( (array) $ca_rows[0] );
			}
			foreach ( $ca_rows as $ca ) {
				$ca_by_user[ (int) $ca->userid ] = $ca;
			}
		}

		// KonX affiliates by email (via wp_user_id join).
		$konx_table = $wpdb->prefix . 'konx_affiliates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$konx_rows    = $wpdb->get_results( "SELECT a.*, u.user_email FROM {$konx_table} a LEFT JOIN {$wpdb->users} u ON a.wp_user_id = u.ID" );
		$konx_by_email = array();
		foreach ( $konx_rows as $k ) {
			if ( ! empty( $k->user_email ) ) {
				$konx_by_email[ strtolower( $k->user_email ) ] = $k;
			}
		}

		// WC orders by customer email.
		$wc_orders_table = $wpdb->prefix . 'wc_orders';
		$wc_has_hpos     = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wc_orders_table ) ) === $wc_orders_table );
		$wc_order_counts = array();
		if ( $wc_has_hpos ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wc_raw = $wpdb->get_results( "SELECT LOWER(billing_email) as email, COUNT(*) as cnt FROM {$wc_orders_table} WHERE status NOT IN ('trash','auto-draft') GROUP BY LOWER(billing_email)" );
			foreach ( $wc_raw as $w ) {
				if ( $w->email ) {
					$wc_order_counts[ $w->email ] = (int) $w->cnt;
				}
			}
		}

		// --- Analyse each CSV record ---
		$results = array();
		$summary = array(
			'total'              => count( $records ),
			'wp_found'           => 0,
			'ca_found'           => 0,
			'konx_found'         => 0,
			'wc_customers'       => 0,
			'wc_orders'          => 0,
			'new_affiliates'     => 0,
			'conflicts'          => 0,
			'needs_review'       => 0,
			'safe_to_import'     => 0,
		);

		foreach ( $records as $r ) {
			$email = strtolower( trim( $r->email ) );
			if ( '' === $email ) {
				continue;
			}

			$wp_user = isset( $wp_by_email[ $email ] ) ? $wp_by_email[ $email ] : null;
			$ca_rec  = null;
			if ( $wp_user && isset( $ca_by_user[ (int) $wp_user->ID ] ) ) {
				$ca_rec = $ca_by_user[ (int) $wp_user->ID ];
			}
			$konx_rec    = isset( $konx_by_email[ $email ] ) ? $konx_by_email[ $email ] : null;
			$wc_orders   = isset( $wc_order_counts[ $email ] ) ? $wc_order_counts[ $email ] : 0;

			// Classify.
			$flags = array();
			if ( $wp_user ) {
				$flags[] = 'wp_user';
				$summary['wp_found']++;
			}
			if ( $ca_rec ) {
				$flags[] = 'coupon_affiliate';
				$summary['ca_found']++;
			}
			if ( $konx_rec ) {
				$flags[] = 'konx_affiliate';
				$summary['konx_found']++;
			}
			if ( $wc_orders > 0 ) {
				$flags[] = 'wc_customer';
				$summary['wc_customers']++;
				$summary['wc_orders'] += $wc_orders;
			}

			// Determine classification.
			$classification = 'new';
			if ( $konx_rec ) {
				$classification = 'conflict';
				$summary['conflicts']++;
			} elseif ( $ca_rec && $wp_user ) {
				$classification = 'needs_review';
				$summary['needs_review']++;
			} elseif ( $wp_user ) {
				$classification = 'safe';
				$summary['safe_to_import']++;
			} else {
				$classification = 'new';
				$summary['new_affiliates']++;
			}

			$results[] = array(
				'po10_id'        => $r->id,
				'email'          => $r->email,
				'team_name'      => $r->team_name,
				'classification' => $classification,
				'flags'          => $flags,
				'wp_user_id'     => $wp_user ? (int) $wp_user->ID : null,
				'wp_login'       => $wp_user ? $wp_user->user_login : null,
				'ca_coupon'      => $ca_rec ? ( $ca_rec->couponcode ?? null ) : null,
				'ca_status'      => $ca_rec ? ( $ca_rec->status ?? null ) : null,
				'konx_id'        => $konx_rec ? (int) $konx_rec->id : null,
				'wc_orders'      => $wc_orders,
			);
		}

		return array(
			'summary'   => $summary,
			'records'   => $results,
			'ca_exists' => $ca_exists,
			'ca_fields' => $ca_fields,
			'ca_count'  => count( $ca_by_user ),
		);
	}

	/**
	 * Render the Existing System Analysis step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_existing_system( $state ) {
		if ( ! isset( $state['scan'] ) ) {
			self::render_no_scan();
			return;
		}

		$analysis = self::analyse_existing_system( $state );
		if ( empty( $analysis ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No source records available.', 'konx-affiliate-dashboard' ) . '</p></div>';
			self::render_nav( 'sponsor-resolution', null );
			return;
		}

		$sm = $analysis['summary'];

		// Store in state for audit report.
		$s = get_option( 'konx_migration_state', array() );
		$s['existing_system'] = array(
			'summary'   => $sm,
			'ca_exists' => $analysis['ca_exists'],
			'ca_fields' => $analysis['ca_fields'],
			'ca_count'  => $analysis['ca_count'],
		);
		update_option( 'konx_migration_state', $s, false );

		?>
		<h2><?php esc_html_e( 'Existing System Analysis', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Compares each CSV record against WordPress users, Coupon Affiliates, WooCommerce customers, and existing KonX data. This is read-only — no data is modified.', 'konx-affiliate-dashboard' ); ?></p>

		<!-- Summary Cards -->
		<div class="konx-stats-grid" style="margin:16px 0;">
			<?php self::stat_card( $sm['wp_found'], __( 'WP Users Found', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
			<?php self::stat_card( $sm['ca_found'], __( 'Coupon Affiliates', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
			<?php self::stat_card( $sm['wc_customers'], __( 'WC Customers', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
			<?php self::stat_card( $sm['wc_orders'], __( 'WC Orders', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
			<?php self::stat_card( $sm['konx_found'], __( 'KonX Existing', 'konx-affiliate-dashboard' ), $sm['konx_found'] > 0 ? '#d63638' : '#00a32a' ); ?>
			<?php self::stat_card( $sm['new_affiliates'], __( 'New Affiliates', 'konx-affiliate-dashboard' ), '#00a32a' ); ?>
			<?php self::stat_card( $sm['safe_to_import'], __( 'Safe to Import', 'konx-affiliate-dashboard' ), '#00a32a' ); ?>
			<?php self::stat_card( $sm['needs_review'], __( 'Needs Review', 'konx-affiliate-dashboard' ), $sm['needs_review'] > 0 ? '#dba617' : '#00a32a' ); ?>
			<?php self::stat_card( $sm['conflicts'], __( 'Conflicts', 'konx-affiliate-dashboard' ), $sm['conflicts'] > 0 ? '#d63638' : '#00a32a' ); ?>
		</div>

		<!-- Coupon Affiliates Discovery -->
		<?php if ( $analysis['ca_exists'] ) : ?>
			<div class="konx-card" style="margin:16px 0;">
				<h3 style="margin-top:0;">
					<span class="dashicons dashicons-database" style="color:#2271b1;"></span>
					<?php esc_html_e( 'Coupon Affiliates Discovery', 'konx-affiliate-dashboard' ); ?>
				</h3>
				<table style="width:100%;font-size:13px;max-width:400px;">
					<tr><td style="padding:4px 0;"><?php esc_html_e( 'Table', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;"><code>wp_wcusage_register</code></td></tr>
					<tr><td style="padding:4px 0;"><?php esc_html_e( 'Total Records', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;font-weight:600;"><?php echo esc_html( number_format( $analysis['ca_count'] ) ); ?></td></tr>
					<tr><td style="padding:4px 0;"><?php esc_html_e( 'Matched to CSV', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;font-weight:600;"><?php echo esc_html( number_format( $sm['ca_found'] ) ); ?></td></tr>
				</table>
				<?php if ( ! empty( $analysis['ca_fields'] ) ) : ?>
					<details style="margin-top:8px;">
						<summary style="cursor:pointer;font-size:12px;color:#2271b1;font-weight:600;">
							<?php printf( esc_html__( 'View %d discovered fields', 'konx-affiliate-dashboard' ), count( $analysis['ca_fields'] ) ); ?>
						</summary>
						<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px;">
							<?php foreach ( $analysis['ca_fields'] as $field ) : ?>
								<code style="font-size:11px;padding:2px 6px;background:#f0f0f1;border-radius:3px;"><?php echo esc_html( $field ); ?></code>
							<?php endforeach; ?>
						</div>
					</details>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<div class="notice notice-info inline" style="margin:16px 0;">
				<p><?php esc_html_e( 'Coupon Affiliates plugin is not installed or has no data.', 'konx-affiliate-dashboard' ); ?></p>
			</div>
		<?php endif; ?>

		<!-- Classification Breakdown -->
		<h3><?php esc_html_e( 'Record Classification', 'konx-affiliate-dashboard' ); ?>
			<span class="description" style="font-weight:normal;margin-left:8px;">
				<?php printf( esc_html__( 'Showing first %d of %d', 'konx-affiliate-dashboard' ), min( 50, count( $analysis['records'] ) ), count( $analysis['records'] ) ); ?>
			</span>
		</h3>
		<div class="konx-table-wrap">
			<table class="widefat fixed striped" style="font-size:12px;margin-bottom:16px;">
				<thead>
					<tr>
						<th style="width:60px;"><?php esc_html_e( 'PO10 ID', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Email', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:100px;"><?php esc_html_e( 'Team Name', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'WP User', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'CA', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:60px;"><?php esc_html_e( 'Orders', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:60px;"><?php esc_html_e( 'KonX', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:100px;"><?php esc_html_e( 'Action', 'konx-affiliate-dashboard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$class_labels = array(
						'new'          => __( 'Create', 'konx-affiliate-dashboard' ),
						'safe'         => __( 'Safe Import', 'konx-affiliate-dashboard' ),
						'needs_review' => __( 'Review', 'konx-affiliate-dashboard' ),
						'conflict'     => __( 'Conflict', 'konx-affiliate-dashboard' ),
					);
					$class_badge = array(
						'new'          => 'ok',
						'safe'         => 'ok',
						'needs_review' => 'warning',
						'conflict'     => 'error',
					);
					foreach ( array_slice( $analysis['records'], 0, 50 ) as $rec ) :
					?>
						<tr>
							<td><?php echo esc_html( $rec['po10_id'] ); ?></td>
							<td style="font-size:11px;"><?php echo esc_html( $rec['email'] ); ?></td>
							<td><code style="font-size:11px;"><?php echo esc_html( mb_substr( $rec['team_name'], 0, 12 ) ); ?></code></td>
							<td><?php echo $rec['wp_user_id'] ? esc_html( '#' . $rec['wp_user_id'] ) : '<span style="color:#646970;">&mdash;</span>'; ?></td>
							<td><?php echo $rec['ca_coupon'] ? '<code style="font-size:11px;">' . esc_html( $rec['ca_coupon'] ) . '</code>' : '<span style="color:#646970;">&mdash;</span>'; ?></td>
							<td><?php echo $rec['wc_orders'] > 0 ? esc_html( $rec['wc_orders'] ) : '<span style="color:#646970;">0</span>'; ?></td>
							<td><?php echo $rec['konx_id'] ? esc_html( '#' . $rec['konx_id'] ) : '<span style="color:#646970;">&mdash;</span>'; ?></td>
							<td><?php echo wp_kses_post( self::badge( $class_badge[ $rec['classification'] ] ?? 'ok', $class_labels[ $rec['classification'] ] ?? $rec['classification'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="notice notice-info inline" style="margin:0 0 16px;">
			<p><?php esc_html_e( 'This analysis is read-only. No WordPress users, affiliates, or financial records have been modified.', 'konx-affiliate-dashboard' ); ?></p>
		</div>

		<?php self::render_nav( 'sponsor-resolution', 'integrity-audit' ); ?>
		<?php
	}

	// ------------------------------------------------------------------
	// Existing Affiliate Integrity Audit
	// ------------------------------------------------------------------

	/**
	 * Render the Integrity Audit step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_integrity_audit( $state ) {
		if ( ! isset( $state['scan'] ) ) {
			self::render_no_scan();
			return;
		}

		$audit = Konx_Integrity_Auditor::audit_all( $state );

		// Store in state for the final audit report.
		$s = get_option( 'konx_migration_state', array() );
		$s['integrity_audit'] = $audit;
		update_option( 'konx_migration_state', $s, false );

		$readiness = $audit['readiness'];
		$score     = $readiness['score'] ?? 0;
		$status    = $readiness['status'] ?? 'unknown';

		// Status colors.
		$status_colors = array(
			'pass'    => array( 'bg' => '#00a32a', 'label' => __( 'PASS', 'konx-affiliate-dashboard' ) ),
			'warning' => array( 'bg' => '#dba617', 'label' => __( 'WARNING', 'konx-affiliate-dashboard' ) ),
			'fail'    => array( 'bg' => '#d63638', 'label' => __( 'FAIL', 'konx-affiliate-dashboard' ) ),
		);
		$sc = $status_colors[ $status ] ?? $status_colors['fail'];

		?>
		<h2><?php esc_html_e( 'Existing Affiliate Integrity Audit', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Comprehensive read-only integrity check across all participating systems. No data is modified.', 'konx-affiliate-dashboard' ); ?></p>

		<!-- Readiness Banner -->
		<div style="background:<?php echo esc_attr( $sc['bg'] ); ?>;color:#fff;padding:16px 24px;border-radius:6px;margin:16px 0;display:flex;align-items:center;gap:16px;">
			<div style="font-size:32px;font-weight:700;"><?php echo esc_html( $score ); ?>%</div>
			<div>
				<div style="font-size:16px;font-weight:600;"><?php esc_html_e( 'Migration Readiness Score', 'konx-affiliate-dashboard' ); ?></div>
				<div style="font-size:13px;opacity:0.9;">
					<?php echo esc_html( $sc['label'] ); ?> &mdash;
					<?php printf(
						/* translators: 1: passed count, 2: warning count, 3: error count */
						esc_html__( '%1$d passed, %2$d warnings, %3$d errors', 'konx-affiliate-dashboard' ),
						$readiness['passed'],
						$readiness['warnings'],
						$readiness['errors']
					); ?>
				</div>
			</div>
		</div>

		<!-- System Health Cards -->
		<div class="konx-stats-grid" style="margin:16px 0;">
			<?php
			$system_keys = array( 'po10', 'coupon', 'wordpress', 'woocommerce', 'konx', 'cross_system' );
			foreach ( $system_keys as $sk ) {
				if ( ! isset( $audit[ $sk ] ) ) {
					continue;
				}
				$sys    = $audit[ $sk ];
				$slabel = $sys['label'] ?? $sk;
				$sstat  = $sys['status'] ?? 'info';
				$scolor = '#00a32a';
				if ( 'warning' === $sstat ) {
					$scolor = '#dba617';
				} elseif ( 'fail' === $sstat || 'error' === $sstat ) {
					$scolor = '#d63638';
				} elseif ( 'info' === $sstat ) {
					$scolor = '#2271b1';
				}
				self::stat_card( strtoupper( $sstat ), $slabel . ' Health', $scolor );
			}
			?>
		</div>

		<?php
		// Render detail sections for each system.
		foreach ( $system_keys as $sk ) {
			if ( ! isset( $audit[ $sk ] ) ) {
				continue;
			}
			$sys = $audit[ $sk ];

			if ( 'cross_system' === $sk ) {
				self::render_integrity_cross_system( $sys );
				continue;
			}

			self::render_integrity_system_section( $sys );
		}
		?>

		<!-- Export Options -->
		<div style="display:flex;gap:8px;margin:16px 0;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<?php wp_nonce_field( 'konx_export_integrity', 'konx_integrity_nonce' ); ?>
				<input type="hidden" name="action" value="konx_migration_export_integrity_csv">
				<button type="submit" class="button">
					<span class="dashicons dashicons-download" style="vertical-align:text-bottom;"></span>
					<?php esc_html_e( 'Export Integrity Report (CSV)', 'konx-affiliate-dashboard' ); ?>
				</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<?php wp_nonce_field( 'konx_export_integrity', 'konx_integrity_nonce' ); ?>
				<input type="hidden" name="action" value="konx_migration_export_integrity_json">
				<button type="submit" class="button">
					<span class="dashicons dashicons-download" style="vertical-align:text-bottom;"></span>
					<?php esc_html_e( 'Export Integrity Report (JSON)', 'konx-affiliate-dashboard' ); ?>
				</button>
			</form>
		</div>

		<div class="notice notice-info inline" style="margin:0 0 16px;">
			<p><?php esc_html_e( 'This audit is 100% read-only. No records in any system have been created, modified, or deleted.', 'konx-affiliate-dashboard' ); ?></p>
		</div>

		<?php self::render_nav( 'existing-system', 'decision-matrix' ); ?>
		<?php
	}

	/**
	 * Render a single system integrity section.
	 *
	 * @param array $sys System audit results.
	 */
	private static function render_integrity_system_section( $sys ) {
		$label  = $sys['label'] ?? 'System';
		$status = $sys['status'] ?? 'info';
		$checks = $sys['checks'] ?? array();
		$detail = $sys['detail'] ?? array();
		$total  = $sys['total'] ?? 0;

		$scolors = array( 'pass' => '#00a32a', 'warning' => '#dba617', 'fail' => '#d63638', 'error' => '#d63638', 'info' => '#2271b1' );
		$color   = $scolors[ $status ] ?? '#646970';

		?>
		<div class="konx-card" style="margin:16px 0;border-left:4px solid <?php echo esc_attr( $color ); ?>;">
			<h3 style="margin-top:0;display:flex;align-items:center;gap:8px;">
				<?php echo esc_html( $label ); ?>
				<?php echo wp_kses_post( self::badge(
					'fail' === $status || 'error' === $status ? 'error' : ( 'warning' === $status ? 'warning' : 'ok' ),
					strtoupper( $status )
				) ); ?>
				<span style="font-weight:normal;font-size:12px;color:#646970;margin-left:auto;"><?php printf( esc_html__( '%s records', 'konx-affiliate-dashboard' ), esc_html( number_format( $total ) ) ); ?></span>
			</h3>
			<?php if ( ! empty( $checks ) ) : ?>
				<table class="widefat fixed striped" style="font-size:12px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Check', 'konx-affiliate-dashboard' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Count', 'konx-affiliate-dashboard' ); ?></th>
							<th style="width:100px;"><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $checks as $key => $check ) : ?>
							<tr>
								<td><?php echo esc_html( $check['label'] ); ?></td>
								<td><?php echo esc_html( number_format( $check['count'] ) ); ?></td>
								<td><?php echo wp_kses_post( self::badge(
									'error' === $check['severity'] ? 'error' : ( 'warning' === $check['severity'] ? 'warning' : 'ok' ),
									strtoupper( $check['severity'] )
								) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php
			// Show expandable details.
			if ( ! empty( $detail ) ) :
				foreach ( $detail as $dkey => $dval ) :
					$dlabel = ucwords( str_replace( '_', ' ', $dkey ) );
					?>
					<details style="margin-top:8px;">
						<summary style="cursor:pointer;font-size:12px;color:#2271b1;font-weight:600;">
							<?php echo esc_html( $dlabel ); ?> (<?php echo esc_html( is_array( $dval ) ? count( $dval ) : 0 ); ?>)
						</summary>
						<div style="margin-top:6px;font-size:11px;max-height:200px;overflow:auto;background:#f6f7f7;padding:8px;border-radius:4px;">
							<pre style="margin:0;white-space:pre-wrap;"><?php echo esc_html( wp_json_encode( $dval, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
						</div>
					</details>
				<?php endforeach;
			endif;

			// Show CA structures if present.
			if ( ! empty( $sys['structures'] ) ) :
				?>
				<details style="margin-top:8px;">
					<summary style="cursor:pointer;font-size:12px;color:#2271b1;font-weight:600;">
						<?php esc_html_e( 'Discovered Structures', 'konx-affiliate-dashboard' ); ?>
					</summary>
					<div style="margin-top:6px;font-size:11px;">
						<?php if ( ! empty( $sys['structures']['tables'] ) ) : ?>
							<table class="widefat fixed striped" style="font-size:11px;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Table', 'konx-affiliate-dashboard' ); ?></th>
										<th style="width:80px;"><?php esc_html_e( 'Exists', 'konx-affiliate-dashboard' ); ?></th>
										<th style="width:80px;"><?php esc_html_e( 'Records', 'konx-affiliate-dashboard' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $sys['structures']['tables'] as $tkey => $tinfo ) : ?>
										<tr>
											<td><code><?php echo esc_html( $tinfo['table'] ); ?></code></td>
											<td><?php echo $tinfo['exists'] ? '<span style="color:#00a32a;">Yes</span>' : '<span style="color:#646970;">No</span>'; ?></td>
											<td><?php echo esc_html( number_format( $tinfo['count'] ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
						<?php if ( ! empty( $sys['structures']['financial'] ) ) : ?>
							<h4 style="margin:12px 0 4px;"><?php esc_html_e( 'Financial Data Discovery', 'konx-affiliate-dashboard' ); ?></h4>
							<table class="widefat fixed striped" style="font-size:11px;">
								<thead><tr><th><?php esc_html_e( 'Item', 'konx-affiliate-dashboard' ); ?></th><th style="width:80px;"><?php esc_html_e( 'Count', 'konx-affiliate-dashboard' ); ?></th></tr></thead>
								<tbody>
									<?php foreach ( $sys['structures']['financial'] as $finfo ) : ?>
										<tr>
											<td><?php echo esc_html( $finfo['label'] ); ?></td>
											<td><?php echo esc_html( number_format( $finfo['count'] ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render cross-system reconciliation section.
	 *
	 * @param array $sys Cross-system results.
	 */
	private static function render_integrity_cross_system( $sys ) {
		$summary = $sys['summary'] ?? array();
		$status  = $sys['status'] ?? 'info';
		$scolors = array( 'pass' => '#00a32a', 'warning' => '#dba617', 'fail' => '#d63638', 'info' => '#2271b1' );
		$color   = $scolors[ $status ] ?? '#646970';

		$labels = array(
			'total_emails'     => __( 'Total Unique Emails', 'konx-affiliate-dashboard' ),
			'only_po10'        => __( 'Exists Only in PowerOf10', 'konx-affiliate-dashboard' ),
			'only_ca'          => __( 'Exists Only in Coupon Affiliates', 'konx-affiliate-dashboard' ),
			'only_wp'          => __( 'Exists Only in WordPress', 'konx-affiliate-dashboard' ),
			'only_konx'        => __( 'Exists Only in KonX', 'konx-affiliate-dashboard' ),
			'in_all'           => __( 'Exists in All Systems', 'konx-affiliate-dashboard' ),
			'po10_and_wp'      => __( 'PO10 + WordPress Match', 'konx-affiliate-dashboard' ),
			'po10_and_ca'      => __( 'PO10 + Coupon Affiliates Match', 'konx-affiliate-dashboard' ),
			'po10_no_wp'       => __( 'PO10 Missing WordPress Account', 'konx-affiliate-dashboard' ),
			'merge_candidates' => __( 'Merge Candidates (PO10+CA, no KonX)', 'konx-affiliate-dashboard' ),
			'missing_in_dest'  => __( 'Need New WP Account', 'konx-affiliate-dashboard' ),
		);
		?>
		<div class="konx-card" style="margin:16px 0;border-left:4px solid <?php echo esc_attr( $color ); ?>;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Cross-System Reconciliation', 'konx-affiliate-dashboard' ); ?></h3>
			<table class="widefat fixed striped" style="font-size:12px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Metric', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:100px;"><?php esc_html_e( 'Count', 'konx-affiliate-dashboard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $summary as $key => $val ) : ?>
						<tr>
							<td><?php echo esc_html( $labels[ $key ] ?? ucwords( str_replace( '_', ' ', $key ) ) ); ?></td>
							<td style="font-weight:600;"><?php echo esc_html( number_format( $val ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Integrity Audit Export Handlers
	// ------------------------------------------------------------------

	/**
	 * Export integrity audit as CSV.
	 */
	public static function handle_export_integrity_csv() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		check_admin_referer( 'konx_export_integrity', 'konx_integrity_nonce' );

		$state = get_option( 'konx_migration_state', array() );
		$audit = ! empty( $state['integrity_audit'] ) ? $state['integrity_audit'] : Konx_Integrity_Auditor::audit_all( $state );

		$csv      = Konx_Integrity_Auditor::export_csv( $audit );
		$filename = 'konx-integrity-audit-' . gmdate( 'Y-m-d-His' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		foreach ( $csv as $row ) {
			fputcsv( $output, $row );
		}
		fclose( $output );
		exit;
	}

	/**
	 * Export integrity audit as JSON.
	 */
	public static function handle_export_integrity_json() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		check_admin_referer( 'konx_export_integrity', 'konx_integrity_nonce' );

		$state = get_option( 'konx_migration_state', array() );
		$audit = ! empty( $state['integrity_audit'] ) ? $state['integrity_audit'] : Konx_Integrity_Auditor::audit_all( $state );

		$json     = Konx_Integrity_Auditor::export_json( $audit );
		$filename = 'konx-integrity-audit-' . gmdate( 'Y-m-d-His' ) . '.json';
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		echo wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	// ------------------------------------------------------------------
	// ------------------------------------------------------------------
	// Migration Decision Matrix
	// ------------------------------------------------------------------

	/**
	 * Build the migration decision for every CSV record.
	 *
	 * Combines CSV data, WP users, Coupon Affiliates, KonX affiliates,
	 * and validation results into one decision per record.
	 * Pure read-only — no database writes.
	 *
	 * @param array $state Migration state.
	 * @return array { summary, decisions[] }.
	 */
	private static function build_decision_matrix( $state ) {
		global $wpdb;

		$engine  = self::build_engine_from_state();
		$records = $engine->get_source_records();
		if ( empty( $records ) ) {
			return array( 'summary' => array(), 'decisions' => array() );
		}

		// Build validation error index.
		$error_ids = array();
		if ( ! empty( $state['validation_results']['issues'] ) ) {
			foreach ( $state['validation_results']['issues'] as $issue ) {
				if ( 'error' === $issue['severity'] ) {
					$error_ids[ $issue['row'] ][] = $issue['message'];
				}
			}
		}

		// Build sponsor resolution index.
		$resolutions = isset( $state['sponsor_resolutions'] ) ? $state['sponsor_resolutions'] : array();

		// WP users by email.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wp_rows    = $wpdb->get_results( "SELECT ID, user_email FROM {$wpdb->users}" );
		$wp_by_email = array();
		foreach ( $wp_rows as $u ) {
			$wp_by_email[ strtolower( $u->user_email ) ] = (int) $u->ID;
		}

		// Coupon Affiliates by WP user ID.
		$ca_table  = $wpdb->prefix . 'wcusage_register';
		$ca_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ca_table ) ) === $ca_table );
		$ca_by_user = array();
		if ( $ca_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ca_rows = $wpdb->get_results( "SELECT userid, couponcode, status FROM {$ca_table}" );
			foreach ( $ca_rows as $ca ) {
				$ca_by_user[ (int) $ca->userid ] = $ca;
			}
		}

		// KonX affiliates by email (via wp_user_id join).
		$konx_table = $wpdb->prefix . 'konx_affiliates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$konx_rows     = $wpdb->get_results( "SELECT a.id, a.referral_code, u.user_email FROM {$konx_table} a LEFT JOIN {$wpdb->users} u ON a.wp_user_id = u.ID" );
		$konx_by_email = array();
		foreach ( $konx_rows as $k ) {
			if ( ! empty( $k->user_email ) ) {
				$konx_by_email[ strtolower( $k->user_email ) ] = $k;
			}
		}

		// Team name set for sponsor resolution.
		$team_set = array();
		foreach ( $records as $r ) {
			$tn = strtolower( trim( $r->team_name ) );
			if ( '' !== $tn ) {
				$team_set[ $tn ] = true;
			}
		}

		// Build decisions.
		$decisions = array();
		$summary   = array(
			'total'       => count( $records ),
			'create'      => 0,
			'link_wp'     => 0,
			'link_ca'     => 0,
			'link_konx'   => 0,
			'skip'        => 0,
			'review'      => 0,
			'invalid'     => 0,
		);

		$row_num = 0;
		foreach ( $records as $r ) {
			$row_num++;
			$email   = strtolower( trim( $r->email ) );
			$wp_id   = isset( $wp_by_email[ $email ] ) ? $wp_by_email[ $email ] : null;
			$ca_rec  = ( $wp_id && isset( $ca_by_user[ $wp_id ] ) ) ? $ca_by_user[ $wp_id ] : null;
			$konx    = isset( $konx_by_email[ $email ] ) ? $konx_by_email[ $email ] : null;

			// Sponsor status.
			$sponsor_tn = strtolower( trim( $r->referrer_team_name ) );
			$sponsor_status = 'none';
			if ( '' !== $sponsor_tn ) {
				if ( isset( $team_set[ $sponsor_tn ] ) ) {
					$sponsor_status = 'resolved';
				} elseif ( isset( $resolutions[ $sponsor_tn ] ) ) {
					$sponsor_status = 'manual_' . $resolutions[ $sponsor_tn ];
				} else {
					$sponsor_status = 'orphan';
				}
			}

			// Validation status.
			$val_status = 'valid';
			$val_errors = array();
			if ( isset( $error_ids[ $row_num ] ) ) {
				$val_status = 'error';
				$val_errors = $error_ids[ $row_num ];
			}

			// --- Decision logic ---
			$decision = 'create';
			$reasons  = array();

			if ( 'error' === $val_status ) {
				$decision = 'invalid';
				$reasons  = $val_errors;
			} elseif ( $konx ) {
				$decision  = 'skip';
				$reasons[] = sprintf( __( 'Already in KonX (affiliate #%d)', 'konx-affiliate-dashboard' ), $konx->id );
			} elseif ( $ca_rec && $wp_id ) {
				$decision  = 'link_ca';
				$reasons[] = sprintf( __( 'Existing Coupon Affiliate (coupon: %s)', 'konx-affiliate-dashboard' ), $ca_rec->couponcode );
			} elseif ( $wp_id ) {
				$decision  = 'link_wp';
				$reasons[] = sprintf( __( 'Existing WP user #%d', 'konx-affiliate-dashboard' ), $wp_id );
			} else {
				$decision  = 'create';
				$reasons[] = __( 'New user and affiliate', 'konx-affiliate-dashboard' );
			}

			// Add sponsor context.
			if ( 'orphan' === $sponsor_status && 'invalid' !== $decision ) {
				$reasons[] = __( 'Sponsor unresolved (will be NULL)', 'konx-affiliate-dashboard' );
			}

			$summary[ $decision ]++;

			$decisions[] = array(
				'po10_id'        => $r->id,
				'email'          => $r->email,
				'team_name'      => $r->team_name,
				'sponsor'        => $r->referrer_team_name,
				'wp_user_id'     => $wp_id,
				'ca_coupon'      => $ca_rec ? $ca_rec->couponcode : null,
				'konx_id'        => $konx ? (int) $konx->id : null,
				'val_status'     => $val_status,
				'sponsor_status' => $sponsor_status,
				'decision'       => $decision,
				'reasons'        => $reasons,
			);
		}

		return array(
			'summary'   => $summary,
			'decisions' => $decisions,
		);
	}

	/**
	 * Render the Decision Matrix step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_decision_matrix( $state ) {
		if ( ! isset( $state['scan'] ) ) {
			self::render_no_scan();
			return;
		}

		$matrix = self::build_decision_matrix( $state );
		$sm     = $matrix['summary'];

		if ( empty( $sm ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No source records available.', 'konx-affiliate-dashboard' ) . '</p></div>';
			self::render_nav( 'integrity-audit', null );
			return;
		}

		// Store summary in state for audit report.
		$s = get_option( 'konx_migration_state', array() );
		$s['decision_matrix'] = array(
			'summary'   => $sm,
			'decisions' => $matrix['decisions'],
		);
		update_option( 'konx_migration_state', $s, false );

		// Decision labels and badge types.
		$labels = array(
			'create'    => __( 'Create New', 'konx-affiliate-dashboard' ),
			'link_wp'   => __( 'Link WP', 'konx-affiliate-dashboard' ),
			'link_ca'   => __( 'Link CA', 'konx-affiliate-dashboard' ),
			'link_konx' => __( 'Link KonX', 'konx-affiliate-dashboard' ),
			'skip'      => __( 'Skip', 'konx-affiliate-dashboard' ),
			'review'    => __( 'Review', 'konx-affiliate-dashboard' ),
			'invalid'   => __( 'Invalid', 'konx-affiliate-dashboard' ),
		);
		$badge_types = array(
			'create' => 'ok', 'link_wp' => 'ok', 'link_ca' => 'ok',
			'link_konx' => 'warning', 'skip' => 'warning',
			'review' => 'warning', 'invalid' => 'error',
		);

		?>
		<h2><?php esc_html_e( 'Migration Decision Matrix', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Every CSV record has been assigned a migration decision based on existing system data, validation results, and sponsor analysis. This is read-only — no data is modified.', 'konx-affiliate-dashboard' ); ?></p>

		<!-- Summary Cards -->
		<div class="konx-stats-grid" style="margin:16px 0;">
			<?php self::stat_card( $sm['create'], __( 'Create New', 'konx-affiliate-dashboard' ), '#00a32a' ); ?>
			<?php self::stat_card( $sm['link_wp'], __( 'Link WP User', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
			<?php self::stat_card( $sm['link_ca'], __( 'Link Coupon Aff', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
			<?php self::stat_card( $sm['link_konx'], __( 'Link KonX', 'konx-affiliate-dashboard' ), $sm['link_konx'] > 0 ? '#dba617' : '#00a32a' ); ?>
			<?php self::stat_card( $sm['skip'], __( 'Skip', 'konx-affiliate-dashboard' ), $sm['skip'] > 0 ? '#dba617' : '#00a32a' ); ?>
			<?php self::stat_card( $sm['review'], __( 'Manual Review', 'konx-affiliate-dashboard' ), $sm['review'] > 0 ? '#dba617' : '#00a32a' ); ?>
			<?php self::stat_card( $sm['invalid'], __( 'Invalid', 'konx-affiliate-dashboard' ), $sm['invalid'] > 0 ? '#d63638' : '#00a32a' ); ?>
		</div>

		<!-- Totals check -->
		<?php
		$decision_total = $sm['create'] + $sm['link_wp'] + $sm['link_ca'] + $sm['link_konx'] + $sm['skip'] + $sm['review'] + $sm['invalid'];
		if ( $decision_total === $sm['total'] ) : ?>
			<div class="notice notice-success inline" style="margin:0 0 16px;">
				<p><?php printf( esc_html__( 'All %s records have been assigned a decision. Matrix is complete.', 'konx-affiliate-dashboard' ), number_format( $sm['total'] ) ); ?></p>
			</div>
		<?php else : ?>
			<div class="notice notice-error inline" style="margin:0 0 16px;">
				<p><?php printf( esc_html__( 'Decision total (%d) does not match record total (%d).', 'konx-affiliate-dashboard' ), $decision_total, $sm['total'] ); ?></p>
			</div>
		<?php endif; ?>

		<!-- Filter Tabs -->
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['dm_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['dm_filter'] ) ) : '';
		$filter_opts = array(
			''         => sprintf( __( 'All (%s)', 'konx-affiliate-dashboard' ), number_format( $sm['total'] ) ),
			'create'   => sprintf( __( 'Create (%d)', 'konx-affiliate-dashboard' ), $sm['create'] ),
			'link_wp'  => sprintf( __( 'Link WP (%d)', 'konx-affiliate-dashboard' ), $sm['link_wp'] ),
			'link_ca'  => sprintf( __( 'Link CA (%d)', 'konx-affiliate-dashboard' ), $sm['link_ca'] ),
			'invalid'  => sprintf( __( 'Invalid (%d)', 'konx-affiliate-dashboard' ), $sm['invalid'] ),
		);
		if ( $sm['skip'] > 0 ) {
			$filter_opts['skip'] = sprintf( __( 'Skip (%d)', 'konx-affiliate-dashboard' ), $sm['skip'] );
		}
		?>
		<div style="display:flex;gap:4px;margin-bottom:12px;flex-wrap:wrap;">
			<?php foreach ( $filter_opts as $fv => $fl ) :
				$url = admin_url( 'admin.php?page=konx-migration&step=decision-matrix' . ( $fv ? '&dm_filter=' . $fv : '' ) );
				$active = ( $filter === $fv );
			?>
				<a href="<?php echo esc_url( $url ); ?>" class="button <?php echo $active ? 'button-primary' : ''; ?>" style="font-size:12px;">
					<?php echo esc_html( $fl ); ?>
				</a>
			<?php endforeach; ?>
		</div>

		<!-- Decision Table -->
		<?php
		$filtered = $matrix['decisions'];
		if ( '' !== $filter ) {
			$filtered = array_filter( $filtered, function ( $d ) use ( $filter ) {
				return $d['decision'] === $filter;
			} );
		}
		$showing = array_slice( $filtered, 0, 50 );
		?>
		<h3><?php esc_html_e( 'Decision Details', 'konx-affiliate-dashboard' ); ?>
			<span class="description" style="font-weight:normal;margin-left:8px;">
				<?php printf( esc_html__( 'Showing %1$d of %2$d', 'konx-affiliate-dashboard' ), count( $showing ), count( $filtered ) ); ?>
			</span>
		</h3>
		<div class="konx-table-wrap">
			<table class="widefat fixed striped" style="font-size:12px;margin-bottom:16px;">
				<thead>
					<tr>
						<th style="width:55px;"><?php esc_html_e( 'ID', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Email', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:90px;"><?php esc_html_e( 'Team', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:70px;"><?php esc_html_e( 'WP', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:70px;"><?php esc_html_e( 'CA', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:55px;"><?php esc_html_e( 'KonX', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:65px;"><?php esc_html_e( 'Valid', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:75px;"><?php esc_html_e( 'Sponsor', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:85px;"><?php esc_html_e( 'Decision', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'konx-affiliate-dashboard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $showing as $d ) :
						$sp_labels = array(
							'none'           => '—',
							'resolved'       => __( 'OK', 'konx-affiliate-dashboard' ),
							'orphan'         => __( 'Orphan', 'konx-affiliate-dashboard' ),
							'manual_accept'  => __( 'Fixed', 'konx-affiliate-dashboard' ),
							'manual_root'    => __( 'Root', 'konx-affiliate-dashboard' ),
							'manual_ignore'  => __( 'Ignore', 'konx-affiliate-dashboard' ),
						);
						$sp_colors = array(
							'none' => '#646970', 'resolved' => '#00a32a', 'orphan' => '#d63638',
							'manual_accept' => '#00a32a', 'manual_root' => '#2271b1', 'manual_ignore' => '#646970',
						);
					?>
						<tr<?php echo 'invalid' === $d['decision'] || 'skip' === $d['decision'] ? ' style="opacity:0.5;"' : ''; ?>>
							<td><?php echo esc_html( $d['po10_id'] ); ?></td>
							<td style="font-size:11px;"><?php echo esc_html( $d['email'] ); ?></td>
							<td><code style="font-size:11px;"><?php echo esc_html( mb_substr( $d['team_name'], 0, 10 ) ); ?></code></td>
							<td><?php echo $d['wp_user_id'] ? esc_html( '#' . $d['wp_user_id'] ) : '<span style="color:#646970;">—</span>'; ?></td>
							<td><?php echo $d['ca_coupon'] ? '<code style="font-size:11px;">' . esc_html( mb_substr( $d['ca_coupon'], 0, 8 ) ) . '</code>' : '<span style="color:#646970;">—</span>'; ?></td>
							<td><?php echo $d['konx_id'] ? esc_html( '#' . $d['konx_id'] ) : '<span style="color:#646970;">—</span>'; ?></td>
							<td>
								<?php if ( 'error' === $d['val_status'] ) : ?>
									<?php echo wp_kses_post( self::badge( 'error', 'ERR' ) ); ?>
								<?php else : ?>
									<?php echo wp_kses_post( self::badge( 'ok', 'OK' ) ); ?>
								<?php endif; ?>
							</td>
							<td>
								<span style="color:<?php echo esc_attr( $sp_colors[ $d['sponsor_status'] ] ?? '#646970' ); ?>;font-size:11px;font-weight:600;">
									<?php echo esc_html( $sp_labels[ $d['sponsor_status'] ] ?? $d['sponsor_status'] ); ?>
								</span>
							</td>
							<td><?php echo wp_kses_post( self::badge( $badge_types[ $d['decision'] ] ?? 'ok', $labels[ $d['decision'] ] ?? $d['decision'] ) ); ?></td>
							<td style="font-size:11px;color:#646970;"><?php echo esc_html( implode( '; ', $d['reasons'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<!-- Export -->
		<div style="margin-bottom:16px;">
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=konx_migration_export_decision_csv' ), 'konx_export_decision', 'konx_dec_nonce' ) ); ?>" class="button">
				<span class="dashicons dashicons-media-spreadsheet" style="vertical-align:text-bottom;"></span>
				<?php esc_html_e( 'Export Decision Matrix (CSV)', 'konx-affiliate-dashboard' ); ?>
			</a>
		</div>

		<div class="notice notice-info inline" style="margin:0 0 16px;">
			<p><?php esc_html_e( 'This matrix is read-only. No users, affiliates, or financial records have been created or modified.', 'konx-affiliate-dashboard' ); ?></p>
		</div>

		<?php self::render_nav( 'integrity-audit', 'validation' ); ?>
		<?php
	}

	/**
	 * Handle CSV export of the decision matrix.
	 */
	public static function handle_export_decision_csv() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		check_admin_referer( 'konx_export_decision', 'konx_dec_nonce' );

		$state = get_option( 'konx_migration_state', array() );
		if ( empty( $state['decision_matrix']['decisions'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=konx-migration&step=decision-matrix' ) );
			exit;
		}

		$decisions = $state['decision_matrix']['decisions'];
		$filename  = 'konx-decision-matrix-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'PO10_ID', 'Email', 'Team_Name', 'Sponsor', 'WP_User_ID', 'CA_Coupon', 'KonX_ID', 'Validation', 'Sponsor_Status', 'Decision', 'Reasons' ) );

		foreach ( $decisions as $d ) {
			fputcsv( $output, array(
				$d['po10_id'],
				$d['email'],
				$d['team_name'],
				$d['sponsor'],
				$d['wp_user_id'] ?? '',
				$d['ca_coupon'] ?? '',
				$d['konx_id'] ?? '',
				$d['val_status'],
				$d['sponsor_status'],
				$d['decision'],
				implode( '; ', $d['reasons'] ),
			) );
		}

		fclose( $output );
		exit;
	}

	// ------------------------------------------------------------------
	// Source Comparison
	// ------------------------------------------------------------------

	/**
	 * Render the Source Comparison step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_comparison( $state ) {
		if ( ! isset( $state['scan'] ) ) {
			self::render_no_scan();
			return;
		}

		$engine  = self::build_engine_from_state();
		$records = $engine->get_source_records();

		if ( empty( $records ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No source records available.', 'konx-affiliate-dashboard' ) . '</p></div>';
			self::render_nav( 'validation', null );
			return;
		}

		$comparison = Konx_Source_Comparator::compare( $records );

		// Store in state for export.
		$s = get_option( 'konx_migration_state', array() );
		$s['comparison'] = $comparison;
		update_option( 'konx_migration_state', $s, false );

		$sm = $comparison['summary'];

		?>
		<h2><?php esc_html_e( 'Source Comparison', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Compare CSV data against existing WordPress users, KonX affiliates, and Coupon Affiliates to detect duplicates and reconcile sponsors.', 'konx-affiliate-dashboard' ); ?></p>

		<!-- Summary Cards -->
		<div class="konx-stats-grid" style="margin:16px 0;">
			<?php self::stat_card( $sm['csv_records'], __( 'CSV Records', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
			<?php self::stat_card( $sm['wp_matches'], __( 'WP Matches', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
			<?php self::stat_card( $sm['konx_matches'], __( 'KonX Matches', 'konx-affiliate-dashboard' ), $sm['konx_matches'] > 0 ? '#dba617' : '#00a32a' ); ?>
			<?php self::stat_card( $sm['ca_detected'] ? $sm['ca_matches'] : '—', __( 'CA Matches', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
			<?php self::stat_card( $sm['sponsors_explained'], __( 'Sponsors Found', 'konx-affiliate-dashboard' ), '#00a32a' ); ?>
			<?php self::stat_card( $sm['sponsors_missing'], __( 'Still Missing', 'konx-affiliate-dashboard' ), $sm['sponsors_missing'] > 0 ? '#d63638' : '#00a32a' ); ?>
		</div>

		<!-- Issue Table -->
		<?php if ( ! empty( $comparison['issues'] ) ) : ?>
			<h3><?php esc_html_e( 'Comparison Results', 'konx-affiliate-dashboard' ); ?></h3>
			<div class="konx-table-wrap">
				<table class="widefat fixed striped" style="font-size:12px;margin-bottom:16px;">
					<thead><tr>
						<th style="width:140px;"><?php esc_html_e( 'Source', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:120px;"><?php esc_html_e( 'Record', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Type', 'konx-affiliate-dashboard' ); ?></th>
						<th style="width:70px;"><?php esc_html_e( 'Severity', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Message', 'konx-affiliate-dashboard' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $comparison['issues'] as $issue ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $issue['source'] ); ?></strong></td>
								<td><code style="font-size:11px;"><?php echo esc_html( mb_substr( $issue['record'], 0, 20 ) ); ?></code></td>
								<td><?php echo esc_html( $issue['match_type'] ); ?></td>
								<td><?php echo wp_kses_post( self::badge( 'warning' === $issue['severity'] ? 'warning' : 'ok', strtoupper( $issue['severity'] ) ) ); ?></td>
								<td><?php echo esc_html( $issue['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<!-- Sponsor Reconciliation Details -->
		<?php if ( ! empty( $comparison['sponsors']['details'] ) ) : ?>
			<h3><?php esc_html_e( 'Orphan Sponsor Reconciliation', 'konx-affiliate-dashboard' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Orphan sponsors from CSV checked against existing site data.', 'konx-affiliate-dashboard' ); ?></p>
			<table class="widefat fixed striped" style="max-width:600px;font-size:12px;margin-bottom:16px;">
				<thead><tr>
					<th><?php esc_html_e( 'Sponsor', 'konx-affiliate-dashboard' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'Affected', 'konx-affiliate-dashboard' ); ?></th>
					<th style="width:130px;"><?php esc_html_e( 'Found In', 'konx-affiliate-dashboard' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $comparison['sponsors']['details'] as $d ) : ?>
						<tr>
							<td><code><?php echo esc_html( $d['sponsor'] ); ?></code></td>
							<td><?php echo esc_html( $d['count'] ); ?></td>
							<td><?php echo esc_html( $d['found'] ?: '—' ); ?></td>
							<td><?php echo wp_kses_post( self::badge( 'found' === $d['status'] ? 'ok' : 'error', 'found' === $d['status'] ? __( 'Found', 'konx-affiliate-dashboard' ) : __( 'Missing', 'konx-affiliate-dashboard' ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<!-- Export -->
		<div style="margin:16px 0;">
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=konx_migration_export_comparison' ), 'konx_export_comparison', 'konx_cmp_nonce' ) ); ?>" class="button">
				<?php esc_html_e( 'Download Comparison Report (CSV)', 'konx-affiliate-dashboard' ); ?>
			</a>
		</div>

		<?php self::render_nav( 'validation', 'summary' ); ?>
		<?php
	}

	// ------------------------------------------------------------------
	// Migration Summary
	// ------------------------------------------------------------------

	/**
	 * Render the Migration Summary step.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_summary( $state ) {
		if ( ! isset( $state['scan'] ) ) {
			self::render_no_scan();
			return;
		}

		$summary   = Konx_Migration_Summary::build( $state );
		$readiness = $summary['readiness'];

		$status_colors = array(
			'ready'           => '#00a32a',
			'needs_attention' => '#dba617',
			'blocked'         => '#d63638',
		);
		$status_icons = array(
			'ready'           => '&#10003;',
			'needs_attention' => '&#9888;',
			'blocked'         => '&#10007;',
		);
		$rc = $status_colors[ $readiness['status'] ] ?? '#646970';
		$ri = $status_icons[ $readiness['status'] ] ?? '&#8226;';

		?>
		<h2><?php esc_html_e( 'Migration Summary', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Complete overview of the migration plan. Review before proceeding to the import preview.', 'konx-affiliate-dashboard' ); ?></p>

		<!-- Readiness Banner -->
		<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo esc_attr( $rc ); ?>;border-radius:4px;padding:16px;margin:16px 0;display:flex;align-items:center;gap:12px;">
			<span style="font-size:24px;color:<?php echo esc_attr( $rc ); ?>;"><?php echo $ri; // Safe HTML entity. ?></span>
			<div>
				<strong style="font-size:15px;color:<?php echo esc_attr( $rc ); ?>;"><?php echo esc_html( $readiness['label'] ); ?></strong>
				<?php if ( ! empty( $readiness['reasons'] ) ) : ?>
					<ul style="margin:4px 0 0 16px;font-size:13px;color:#646970;">
						<?php foreach ( $readiness['reasons'] as $reason ) : ?>
							<li><?php echo esc_html( $reason ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>

		<!-- Record Counts -->
		<div class="konx-stats-grid" style="margin:16px 0;">
			<?php self::stat_card( $summary['records']['total'], __( 'Total Records', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
			<?php self::stat_card( $summary['records']['valid'], __( 'Valid', 'konx-affiliate-dashboard' ), '#00a32a' ); ?>
			<?php self::stat_card( $summary['records']['warnings'], __( 'Warnings', 'konx-affiliate-dashboard' ), $summary['records']['warnings'] > 0 ? '#dba617' : '#00a32a' ); ?>
			<?php self::stat_card( $summary['records']['errors'], __( 'Errors', 'konx-affiliate-dashboard' ), $summary['records']['errors'] > 0 ? '#d63638' : '#00a32a' ); ?>
		</div>

		<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin:16px 0;">

			<!-- Affiliate Types -->
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px;">
				<h3 style="margin:0 0 12px;font-size:14px;display:flex;align-items:center;gap:6px;">
					<span class="dashicons dashicons-groups" style="color:#2271b1;"></span>
					<?php esc_html_e( 'Affiliate Types', 'konx-affiliate-dashboard' ); ?>
				</h3>
				<?php if ( ! empty( $summary['types'] ) ) : ?>
					<table style="width:100%;font-size:13px;">
						<?php foreach ( $summary['types'] as $type => $count ) : ?>
							<tr>
								<td style="padding:3px 0;"><?php echo esc_html( ucwords( str_replace( '_', ' ', $type ) ) ); ?></td>
								<td style="padding:3px 0;text-align:right;font-weight:600;"><?php echo esc_html( number_format( $count ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Run a dry run to see type breakdown.', 'konx-affiliate-dashboard' ); ?></p>
				<?php endif; ?>
			</div>

			<!-- Sponsors -->
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px;">
				<h3 style="margin:0 0 12px;font-size:14px;display:flex;align-items:center;gap:6px;">
					<span class="dashicons dashicons-networking" style="color:#2271b1;"></span>
					<?php esc_html_e( 'Sponsor Hierarchy', 'konx-affiliate-dashboard' ); ?>
				</h3>
				<table style="width:100%;font-size:13px;">
					<tr>
						<td style="padding:3px 0;"><?php esc_html_e( 'Resolved', 'konx-affiliate-dashboard' ); ?></td>
						<td style="padding:3px 0;text-align:right;font-weight:600;color:#00a32a;"><?php echo esc_html( number_format( $summary['sponsors']['resolved'] ) ); ?></td>
					</tr>
					<tr>
						<td style="padding:3px 0;"><?php esc_html_e( 'Missing / Orphaned', 'konx-affiliate-dashboard' ); ?></td>
						<td style="padding:3px 0;text-align:right;font-weight:600;color:<?php echo $summary['sponsors']['missing'] > 0 ? '#dba617' : '#00a32a'; ?>;"><?php echo esc_html( number_format( $summary['sponsors']['missing'] ) ); ?></td>
					</tr>
					<tr>
						<td style="padding:3px 0;"><?php esc_html_e( 'Self-Referrals', 'konx-affiliate-dashboard' ); ?></td>
						<td style="padding:3px 0;text-align:right;font-weight:600;"><?php echo esc_html( number_format( $summary['sponsors']['self_ref'] ) ); ?></td>
					</tr>
				</table>
				<?php if ( $summary['sponsors']['missing'] > 100 ) : ?>
					<p style="margin:8px 0 0;font-size:12px;color:#dba617;"><?php printf( esc_html__( '%d orphan references — these affiliates will have no parent.', 'konx-affiliate-dashboard' ), $summary['sponsors']['missing'] ); ?></p>
				<?php endif; ?>
			</div>

			<!-- Validation -->
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px;">
				<h3 style="margin:0 0 12px;font-size:14px;display:flex;align-items:center;gap:6px;">
					<span class="dashicons dashicons-yes-alt" style="color:#2271b1;"></span>
					<?php esc_html_e( 'Validation', 'konx-affiliate-dashboard' ); ?>
				</h3>
				<?php if ( $summary['has_validation'] ) : ?>
					<table style="width:100%;font-size:13px;">
						<tr>
							<td style="padding:3px 0;"><?php esc_html_e( 'Errors', 'konx-affiliate-dashboard' ); ?></td>
							<td style="padding:3px 0;text-align:right;font-weight:600;color:<?php echo $summary['validation']['error_count'] > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo esc_html( $summary['validation']['error_count'] ); ?></td>
						</tr>
						<tr>
							<td style="padding:3px 0;"><?php esc_html_e( 'Warnings', 'konx-affiliate-dashboard' ); ?></td>
							<td style="padding:3px 0;text-align:right;font-weight:600;color:<?php echo $summary['validation']['warning_count'] > 0 ? '#dba617' : '#00a32a'; ?>;"><?php echo esc_html( $summary['validation']['warning_count'] ); ?></td>
						</tr>
					</table>
					<?php if ( ! empty( $summary['validation']['top_categories'] ) ) : ?>
						<p style="margin:8px 0 0;font-size:12px;color:#646970;">
							<?php esc_html_e( 'Top issues:', 'konx-affiliate-dashboard' ); ?>
							<?php echo esc_html( implode( ', ', array_map( function ( $f, $c ) { return "$f ($c)"; }, array_keys( $summary['validation']['top_categories'] ), $summary['validation']['top_categories'] ) ) ); ?>
						</p>
					<?php endif; ?>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Validation not yet run.', 'konx-affiliate-dashboard' ); ?></p>
				<?php endif; ?>
			</div>

		</div>

		<!-- Dry Run Projection -->
		<?php if ( $summary['has_dryrun'] ) : ?>
			<h3><?php esc_html_e( 'Dry Run Projection', 'konx-affiliate-dashboard' ); ?></h3>
			<div class="konx-stats-grid" style="margin:0 0 16px;">
				<?php self::stat_card( $summary['projection']['to_create'], __( 'To Create', 'konx-affiliate-dashboard' ), '#00a32a' ); ?>
				<?php self::stat_card( $summary['projection']['to_skip'], __( 'To Skip', 'konx-affiliate-dashboard' ), $summary['projection']['to_skip'] > 0 ? '#d63638' : '#00a32a' ); ?>
				<?php self::stat_card( $summary['projection']['wp_users'], __( 'New WP Users', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
				<?php self::stat_card( $summary['projection']['sponsor_links'], __( 'Sponsor Links', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
			</div>
		<?php endif; ?>

		<!-- Export -->
		<div style="margin:16px 0;">
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=konx_migration_export_summary' ), 'konx_export_summary', 'konx_sum_nonce' ) ); ?>" class="button">
				<?php esc_html_e( 'Download Summary Report (CSV)', 'konx-affiliate-dashboard' ); ?>
			</a>
		</div>

		<div class="notice notice-info inline" style="margin:0 0 16px;">
			<p><?php esc_html_e( 'This is a preview only. No data has been written. Continue to the Import Preview for record-level details.', 'konx-affiliate-dashboard' ); ?></p>
		</div>

		<?php self::render_nav( 'source-comparison', 'preview' ); ?>
		<?php
	}

	// ------------------------------------------------------------------
	// Step 7: Migration Preview
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

		$engine  = self::build_engine_from_state();
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

		<?php self::render_nav( 'summary', 'dry-run' ); ?>
		<?php
	}

	// ------------------------------------------------------------------
	// Step 8: Dry Run
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

		<?php self::render_nav( 'dry-run', 'audit' ); ?>
		<?php
	}

	// ------------------------------------------------------------------
	// Step 14: Audit Report
	// ------------------------------------------------------------------

	/**
	 * Render the Audit Report step.
	 *
	 * Displays a comprehensive read-only audit of all migration preview
	 * data. No data is written — this is purely a review/reporting tool.
	 *
	 * @param array $state Migration state.
	 */
	private static function render_audit( $state ) {
		if ( ! isset( $state['scan'] ) ) {
			self::render_no_scan();
			return;
		}

		$audit = Konx_Migration_Audit::build();
		if ( ! $audit ) {
			self::render_no_scan();
			return;
		}

		$s  = $audit['summary'];
		$rc = array( 'ready' => '#00a32a', 'needs_attention' => '#dba617', 'blocked' => '#d63638' );
		$ri = array( 'ready' => '&#10003;', 'needs_attention' => '&#9888;', 'blocked' => '&#10007;' );
		$rs = $audit['readiness']['status'];

		?>
		<h2><?php esc_html_e( 'Migration Audit Report', 'konx-affiliate-dashboard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Comprehensive read-only audit of all migration preview data. No data has been imported.', 'konx-affiliate-dashboard' ); ?></p>

		<!-- Preview-Only Warning -->
		<div class="notice notice-warning inline" style="margin:16px 0;border-left:4px solid #dba617;">
			<p><strong><?php esc_html_e( 'Preview Only', 'konx-affiliate-dashboard' ); ?></strong> &mdash;
			<?php esc_html_e( 'This report reflects preview data only. No WordPress users, affiliate records, or production data have been created. Migration execution is not yet available.', 'konx-affiliate-dashboard' ); ?></p>
		</div>

		<!-- Readiness Score -->
		<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo esc_attr( isset( $rc[ $rs ] ) ? $rc[ $rs ] : '#646970' ); ?>;border-radius:4px;padding:20px;margin:0 0 20px;display:flex;align-items:center;gap:16px;">
			<span style="font-size:36px;color:<?php echo esc_attr( isset( $rc[ $rs ] ) ? $rc[ $rs ] : '#646970' ); ?>;"><?php echo isset( $ri[ $rs ] ) ? $ri[ $rs ] : '&#8226;'; // Safe entity. ?></span>
			<div>
				<strong style="font-size:18px;color:<?php echo esc_attr( isset( $rc[ $rs ] ) ? $rc[ $rs ] : '#646970' ); ?>;"><?php echo esc_html( $audit['readiness']['label'] ); ?></strong>
				<?php if ( ! empty( $audit['readiness']['reasons'] ) ) : ?>
					<ul style="margin:4px 0 0 16px;font-size:13px;color:#646970;">
						<?php foreach ( $audit['readiness']['reasons'] as $reason ) : ?>
							<li><?php echo esc_html( $reason ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
			<?php if ( $audit['approved'] ) : ?>
				<div style="margin-left:auto;text-align:right;">
					<?php echo wp_kses_post( self::badge( 'ok', __( 'Approved', 'konx-affiliate-dashboard' ) ) ); ?>
					<div style="font-size:11px;color:#646970;margin-top:4px;">
						<?php printf( esc_html__( 'User #%d, %s', 'konx-affiliate-dashboard' ), $audit['approved_by'], esc_html( date_i18n( 'M j, Y', strtotime( $audit['approved_at'] ) ) ) ); ?>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<!-- Summary Cards -->
		<div class="konx-stats-grid" style="margin:0 0 20px;">
			<?php self::stat_card( $s['records']['total'], __( 'Total Records', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
			<?php self::stat_card( $s['records']['valid'], __( 'Valid', 'konx-affiliate-dashboard' ), '#00a32a' ); ?>
			<?php self::stat_card( $s['records']['warnings'], __( 'Warnings', 'konx-affiliate-dashboard' ), $s['records']['warnings'] > 0 ? '#dba617' : '#00a32a' ); ?>
			<?php self::stat_card( $s['records']['errors'], __( 'Errors', 'konx-affiliate-dashboard' ), $s['records']['errors'] > 0 ? '#d63638' : '#00a32a' ); ?>
			<?php self::stat_card( $audit['sponsors']['missing'], __( 'Orphan Sponsors', 'konx-affiliate-dashboard' ), $audit['sponsors']['missing'] > 0 ? '#dba617' : '#00a32a' ); ?>
			<?php if ( $audit['duplicates'] ) : ?>
				<?php self::stat_card( $audit['duplicates']['total_duplicates'], __( 'Duplicates', 'konx-affiliate-dashboard' ), $audit['duplicates']['total_duplicates'] > 0 ? '#d63638' : '#00a32a' ); ?>
			<?php endif; ?>
		</div>

		<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;margin-bottom:20px;">

			<!-- Source Info -->
			<div class="konx-card">
				<h2 style="display:flex;align-items:center;gap:8px;">
					<span class="dashicons dashicons-media-spreadsheet" style="color:#2271b1;"></span>
					<?php esc_html_e( 'Data Source', 'konx-affiliate-dashboard' ); ?>
				</h2>
				<table style="width:100%;font-size:13px;">
					<tr><td style="padding:4px 0;font-weight:500;"><?php esc_html_e( 'Source', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;"><?php echo esc_html( strtoupper( $audit['source'] ) ); ?></td></tr>
					<tr><td style="padding:4px 0;font-weight:500;"><?php esc_html_e( 'Scan Date', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;"><?php echo $audit['scan_at'] ? esc_html( date_i18n( 'M j, Y g:ia', strtotime( $audit['scan_at'] ) ) ) : '—'; ?></td></tr>
					<?php if ( $audit['csv_info'] ) : ?>
						<tr><td style="padding:4px 0;font-weight:500;"><?php esc_html_e( 'File', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;"><code style="font-size:12px;"><?php echo esc_html( $audit['csv_info']['file_name'] ?? '—' ); ?></code></td></tr>
						<tr><td style="padding:4px 0;font-weight:500;"><?php esc_html_e( 'Rows', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;"><?php echo esc_html( number_format( $audit['csv_info']['row_count'] ?? 0 ) ); ?></td></tr>
					<?php endif; ?>
				</table>
			</div>

			<!-- Affiliate Types -->
			<div class="konx-card">
				<h2 style="display:flex;align-items:center;gap:8px;">
					<span class="dashicons dashicons-groups" style="color:#2271b1;"></span>
					<?php esc_html_e( 'Affiliate Types', 'konx-affiliate-dashboard' ); ?>
				</h2>
				<?php if ( ! empty( $s['types'] ) ) : ?>
					<table style="width:100%;font-size:13px;">
						<?php foreach ( $s['types'] as $type => $count ) : ?>
							<tr>
								<td style="padding:4px 0;"><?php echo esc_html( ucwords( str_replace( '_', ' ', $type ) ) ); ?></td>
								<td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( number_format( $count ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Run a dry run to see type breakdown.', 'konx-affiliate-dashboard' ); ?></p>
				<?php endif; ?>
			</div>

			<!-- Sponsor Hierarchy -->
			<div class="konx-card">
				<h2 style="display:flex;align-items:center;gap:8px;">
					<span class="dashicons dashicons-networking" style="color:#2271b1;"></span>
					<?php esc_html_e( 'Sponsor Hierarchy', 'konx-affiliate-dashboard' ); ?>
				</h2>
				<table style="width:100%;font-size:13px;">
					<tr><td style="padding:4px 0;"><?php esc_html_e( 'Total References', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( number_format( $audit['sponsors']['total'] ) ); ?></td></tr>
					<tr><td style="padding:4px 0;"><?php esc_html_e( 'Resolved', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:#00a32a;"><?php echo esc_html( number_format( $audit['sponsors']['resolved'] ) ); ?></td></tr>
					<tr><td style="padding:4px 0;"><?php esc_html_e( 'Missing', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:<?php echo $audit['sponsors']['missing'] > 0 ? '#dba617' : '#00a32a'; ?>;"><?php echo esc_html( number_format( $audit['sponsors']['missing'] ) ); ?></td></tr>
					<tr><td style="padding:4px 0;"><?php esc_html_e( 'Self-Referrals', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( number_format( $audit['sponsors']['self_referrals'] ) ); ?></td></tr>
				</table>
				<?php if ( ! empty( $audit['sponsors']['still_missing'] ) ) : ?>
					<details style="margin-top:8px;">
						<summary style="cursor:pointer;font-size:12px;color:#2271b1;font-weight:600;"><?php printf( esc_html__( 'View %d unresolved sponsors', 'konx-affiliate-dashboard' ), count( $audit['sponsors']['still_missing'] ) ); ?></summary>
						<table class="widefat fixed striped" style="font-size:12px;margin-top:8px;">
							<thead><tr><th><?php esc_html_e( 'Sponsor', 'konx-affiliate-dashboard' ); ?></th><th style="width:60px;"><?php esc_html_e( 'Refs', 'konx-affiliate-dashboard' ); ?></th></tr></thead>
							<tbody>
								<?php foreach ( array_slice( $audit['sponsors']['still_missing'], 0, 20 ) as $m ) : ?>
									<tr><td><code><?php echo esc_html( $m['sponsor'] ); ?></code></td><td><?php echo esc_html( $m['count'] ); ?></td></tr>
								<?php endforeach; ?>
								<?php if ( count( $audit['sponsors']['still_missing'] ) > 20 ) : ?>
									<tr><td colspan="2" style="color:#646970;"><?php printf( esc_html__( '... and %d more', 'konx-affiliate-dashboard' ), count( $audit['sponsors']['still_missing'] ) - 20 ); ?></td></tr>
								<?php endif; ?>
							</tbody>
						</table>
					</details>
				<?php endif; ?>
			</div>

			<!-- Duplicates / Conflicts -->
			<?php if ( $audit['duplicates'] ) : ?>
				<div class="konx-card">
					<h2 style="display:flex;align-items:center;gap:8px;">
						<span class="dashicons dashicons-warning" style="color:<?php echo $audit['duplicates']['total_duplicates'] > 0 ? '#d63638' : '#00a32a'; ?>;"></span>
						<?php esc_html_e( 'Duplicates & Conflicts', 'konx-affiliate-dashboard' ); ?>
					</h2>
					<table style="width:100%;font-size:13px;">
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Duplicate Emails', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:<?php echo $audit['duplicates']['email_duplicate_count'] > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo esc_html( $audit['duplicates']['email_duplicate_count'] ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Duplicate Team Names', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:<?php echo $audit['duplicates']['code_duplicate_count'] > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo esc_html( $audit['duplicates']['code_duplicate_count'] ); ?></td></tr>
					</table>
					<?php if ( ! empty( $audit['duplicates']['email_duplicates'] ) ) : ?>
						<details style="margin-top:8px;">
							<summary style="cursor:pointer;font-size:12px;color:#2271b1;font-weight:600;"><?php printf( esc_html__( 'View %d duplicate emails', 'konx-affiliate-dashboard' ), $audit['duplicates']['email_duplicate_count'] ); ?></summary>
							<table class="widefat fixed striped" style="font-size:12px;margin-top:8px;">
								<thead><tr><th><?php esc_html_e( 'Row', 'konx-affiliate-dashboard' ); ?></th><th><?php esc_html_e( 'Email', 'konx-affiliate-dashboard' ); ?></th></tr></thead>
								<tbody>
									<?php foreach ( array_slice( $audit['duplicates']['email_duplicates'], 0, 20 ) as $d ) : ?>
										<tr><td><?php echo esc_html( $d['row'] ); ?></td><td><code><?php echo esc_html( $d['value'] ); ?></code></td></tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</details>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<!-- Validation Summary -->
			<?php if ( $audit['validation'] ) : ?>
				<div class="konx-card">
					<h2 style="display:flex;align-items:center;gap:8px;">
						<span class="dashicons dashicons-yes-alt" style="color:#2271b1;"></span>
						<?php esc_html_e( 'Validation Breakdown', 'konx-affiliate-dashboard' ); ?>
					</h2>
					<table style="width:100%;font-size:13px;">
						<?php foreach ( $audit['validation']['by_field'] as $field => $counts ) : ?>
							<tr>
								<td style="padding:4px 0;"><code><?php echo esc_html( $field ); ?></code></td>
								<td style="padding:4px 0;text-align:right;">
									<?php if ( $counts['error'] > 0 ) : ?>
										<span style="color:#d63638;font-weight:600;"><?php echo esc_html( $counts['error'] ); ?> <?php esc_html_e( 'errors', 'konx-affiliate-dashboard' ); ?></span>
									<?php endif; ?>
									<?php if ( $counts['warning'] > 0 ) : ?>
										<span style="color:#dba617;font-weight:600;<?php echo $counts['error'] > 0 ? 'margin-left:8px;' : ''; ?>"><?php echo esc_html( $counts['warning'] ); ?> <?php esc_html_e( 'warnings', 'konx-affiliate-dashboard' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
					<?php if ( ! empty( $audit['validation']['top_issues'] ) ) : ?>
						<h3 style="font-size:13px;margin:12px 0 8px;"><?php esc_html_e( 'Top Issues', 'konx-affiliate-dashboard' ); ?></h3>
						<?php foreach ( array_slice( $audit['validation']['top_issues'], 0, 5 ) as $ti ) : ?>
							<div style="display:flex;align-items:baseline;gap:6px;font-size:12px;line-height:2;">
								<span style="color:<?php echo 'error' === $ti['severity'] ? '#d63638' : '#dba617'; ?>;flex-shrink:0;"><?php echo 'error' === $ti['severity'] ? '&#10007;' : '&#9888;'; ?></span>
								<span><?php echo esc_html( $ti['message'] ); ?></span>
								<span style="margin-left:auto;font-weight:600;">&times;<?php echo esc_html( $ti['count'] ); ?></span>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<!-- Field Mapping -->
			<?php if ( $audit['field_map'] ) : ?>
				<div class="konx-card">
					<h2 style="display:flex;align-items:center;gap:8px;">
						<span class="dashicons dashicons-editor-table" style="color:#2271b1;"></span>
						<?php esc_html_e( 'Field Mapping', 'konx-affiliate-dashboard' ); ?>
					</h2>
					<div class="konx-stats-grid" style="margin:0 0 12px;">
						<?php self::stat_card( $audit['field_map']['mapped'], __( 'Mapped', 'konx-affiliate-dashboard' ), '#00a32a' ); ?>
						<?php self::stat_card( $audit['field_map']['unmapped'], __( 'Unmapped', 'konx-affiliate-dashboard' ), $audit['field_map']['unmapped'] > 0 ? '#dba617' : '#00a32a' ); ?>
						<?php self::stat_card( $audit['field_map']['exact'], __( 'Exact', 'konx-affiliate-dashboard' ), '#2271b1' ); ?>
					</div>
					<details>
						<summary style="cursor:pointer;font-size:12px;color:#2271b1;font-weight:600;"><?php esc_html_e( 'View all column mappings', 'konx-affiliate-dashboard' ); ?></summary>
						<table class="widefat fixed striped" style="font-size:12px;margin-top:8px;">
							<thead><tr><th><?php esc_html_e( 'CSV Column', 'konx-affiliate-dashboard' ); ?></th><th><?php esc_html_e( 'KonX Field', 'konx-affiliate-dashboard' ); ?></th><th style="width:80px;"><?php esc_html_e( 'Match', 'konx-affiliate-dashboard' ); ?></th></tr></thead>
							<tbody>
								<?php foreach ( $audit['field_map']['details'] as $fm ) : ?>
									<tr<?php echo 'unmapped' === $fm['status'] ? ' style="opacity:0.5;"' : ''; ?>>
										<td><code><?php echo esc_html( $fm['csv_column'] ); ?></code></td>
										<td><?php echo esc_html( $fm['target'] ); ?></td>
										<td><?php echo wp_kses_post( self::badge( 'mapped' === $fm['status'] ? 'ok' : 'warning', ucfirst( $fm['confidence'] ) ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</details>
				</div>
			<?php endif; ?>

			<!-- Comparison Summary -->
			<?php if ( $audit['comparison'] ) : ?>
				<div class="konx-card">
					<h2 style="display:flex;align-items:center;gap:8px;">
						<span class="dashicons dashicons-randomize" style="color:#2271b1;"></span>
						<?php esc_html_e( 'Cross-Source Comparison', 'konx-affiliate-dashboard' ); ?>
					</h2>
					<table style="width:100%;font-size:13px;">
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'WordPress Matches', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( $audit['comparison']['wp']['matched'] ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'New WP Users Needed', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( $audit['comparison']['wp']['new'] ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'KonX Matches', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:<?php echo $audit['comparison']['konx']['matched'] > 0 ? '#dba617' : '#00a32a'; ?>;"><?php echo esc_html( $audit['comparison']['konx']['matched'] ); ?></td></tr>
						<?php if ( $audit['comparison']['ca']['detected'] ) : ?>
							<tr><td style="padding:4px 0;"><?php esc_html_e( 'Coupon Affiliate Matches', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( $audit['comparison']['ca']['matched'] ); ?></td></tr>
						<?php endif; ?>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Total Issues Found', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( $audit['comparison']['issue_count'] ); ?></td></tr>
					</table>
				</div>
			<?php endif; ?>

			<!-- Sponsor Resolution Summary -->
			<?php if ( ! empty( $audit['sponsor_resolutions'] ) && $audit['sponsor_resolutions']['total_decisions'] > 0 ) : ?>
				<?php $sr = $audit['sponsor_resolutions']; ?>
				<div class="konx-card">
					<h2 style="display:flex;align-items:center;gap:8px;">
						<span class="dashicons dashicons-admin-links" style="color:#2271b1;"></span>
						<?php esc_html_e( 'Sponsor Resolutions', 'konx-affiliate-dashboard' ); ?>
					</h2>
					<table style="width:100%;font-size:13px;">
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Decisions Made', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( $sr['total_decisions'] ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Accept Suggestion', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:#00a32a;"><?php echo esc_html( $sr['accepted'] ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Leave as Root', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:#2271b1;"><?php echo esc_html( $sr['root'] ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Ignore (NULL)', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:#646970;"><?php echo esc_html( $sr['ignored'] ); ?></td></tr>
					</table>
				</div>
			<?php endif; ?>

			<!-- Existing System Summary -->
			<?php if ( ! empty( $audit['existing_system'] ) && ! empty( $audit['existing_system']['summary'] ) ) : ?>
				<?php $es = $audit['existing_system']['summary']; ?>
				<div class="konx-card">
					<h2 style="display:flex;align-items:center;gap:8px;">
						<span class="dashicons dashicons-admin-site-alt3" style="color:#2271b1;"></span>
						<?php esc_html_e( 'Existing System', 'konx-affiliate-dashboard' ); ?>
					</h2>
					<table style="width:100%;font-size:13px;">
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'WP Users Found', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( number_format( $es['wp_found'] ) ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Coupon Affiliates', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( number_format( $es['ca_found'] ) ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'WC Customers', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( number_format( $es['wc_customers'] ) ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'New Affiliates', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:#00a32a;"><?php echo esc_html( number_format( $es['new_affiliates'] ) ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Conflicts', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:<?php echo $es['conflicts'] > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo esc_html( $es['conflicts'] ); ?></td></tr>
					</table>
				</div>
			<?php endif; ?>

			<!-- Integrity Audit Summary -->
			<?php if ( ! empty( $audit['integrity_audit']['readiness'] ) ) : ?>
				<?php $ia = $audit['integrity_audit']; ?>
				<div class="konx-card">
					<h2 style="display:flex;align-items:center;gap:8px;">
						<span class="dashicons dashicons-shield" style="color:#2271b1;"></span>
						<?php esc_html_e( 'Integrity Audit', 'konx-affiliate-dashboard' ); ?>
					</h2>
					<table style="width:100%;font-size:13px;">
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Readiness Score', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( $ia['readiness']['score'] ); ?>%</td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Total Checks', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( $ia['readiness']['total_checks'] ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Passed', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:#00a32a;"><?php echo esc_html( $ia['readiness']['passed'] ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Warnings', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:<?php echo $ia['readiness']['warnings'] > 0 ? '#dba617' : '#00a32a'; ?>;"><?php echo esc_html( $ia['readiness']['warnings'] ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Errors', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:<?php echo $ia['readiness']['errors'] > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo esc_html( $ia['readiness']['errors'] ); ?></td></tr>
					</table>
				</div>
			<?php endif; ?>

			<!-- Decision Matrix Summary -->
			<?php if ( ! empty( $audit['decision_matrix'] ) ) : ?>
				<?php $dm = $audit['decision_matrix']; ?>
				<div class="konx-card">
					<h2 style="display:flex;align-items:center;gap:8px;">
						<span class="dashicons dashicons-editor-table" style="color:#2271b1;"></span>
						<?php esc_html_e( 'Migration Decisions', 'konx-affiliate-dashboard' ); ?>
					</h2>
					<table style="width:100%;font-size:13px;">
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Create New', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:#00a32a;"><?php echo esc_html( number_format( $dm['create'] ) ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Link WP User', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( number_format( $dm['link_wp'] ) ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Link Coupon Affiliate', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( number_format( $dm['link_ca'] ) ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Skip / Link KonX', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:<?php echo ( $dm['skip'] + $dm['link_konx'] ) > 0 ? '#dba617' : '#00a32a'; ?>;"><?php echo esc_html( $dm['skip'] + $dm['link_konx'] ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Invalid', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:<?php echo $dm['invalid'] > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo esc_html( $dm['invalid'] ); ?></td></tr>
					</table>
				</div>
			<?php endif; ?>

			<!-- Projection -->
			<?php if ( $s['has_dryrun'] ) : ?>
				<div class="konx-card">
					<h2 style="display:flex;align-items:center;gap:8px;">
						<span class="dashicons dashicons-chart-bar" style="color:#2271b1;"></span>
						<?php esc_html_e( 'Dry Run Projection', 'konx-affiliate-dashboard' ); ?>
					</h2>
					<table style="width:100%;font-size:13px;">
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Affiliates to Create', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:#00a32a;"><?php echo esc_html( number_format( $s['projection']['to_create'] ) ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Records to Skip', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;color:<?php echo $s['projection']['to_skip'] > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo esc_html( number_format( $s['projection']['to_skip'] ) ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'WP Users to Create', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( number_format( $s['projection']['wp_users'] ) ); ?></td></tr>
						<tr><td style="padding:4px 0;"><?php esc_html_e( 'Sponsor Links', 'konx-affiliate-dashboard' ); ?></td><td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo esc_html( number_format( $s['projection']['sponsor_links'] ) ); ?></td></tr>
					</table>
				</div>
			<?php endif; ?>

		</div>

		<!-- Export Options -->
		<div class="konx-card" style="margin-bottom:16px;">
			<h2 style="display:flex;align-items:center;gap:8px;">
				<span class="dashicons dashicons-download" style="color:#2271b1;"></span>
				<?php esc_html_e( 'Export Audit Report', 'konx-affiliate-dashboard' ); ?>
			</h2>
			<div style="display:flex;gap:8px;flex-wrap:wrap;">
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=konx_migration_export_audit_csv' ), 'konx_export_audit', 'konx_audit_nonce' ) ); ?>" class="button">
					<span class="dashicons dashicons-media-spreadsheet" style="vertical-align:text-bottom;"></span>
					<?php esc_html_e( 'Download CSV', 'konx-affiliate-dashboard' ); ?>
				</a>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=konx_migration_export_audit_json' ), 'konx_export_audit', 'konx_audit_nonce' ) ); ?>" class="button">
					<span class="dashicons dashicons-editor-code" style="vertical-align:text-bottom;"></span>
					<?php esc_html_e( 'Download JSON', 'konx-affiliate-dashboard' ); ?>
				</a>
				<button type="button" class="button" onclick="window.print();">
					<span class="dashicons dashicons-printer" style="vertical-align:text-bottom;"></span>
					<?php esc_html_e( 'Print Report', 'konx-affiliate-dashboard' ); ?>
				</button>
			</div>
		</div>

		<!-- Warnings Footer -->
		<div style="background:#fcf6e3;border:1px solid #dba617;border-radius:4px;padding:12px 16px;margin-bottom:16px;">
			<strong style="color:#946800;"><?php esc_html_e( 'Important Notices', 'konx-affiliate-dashboard' ); ?></strong>
			<ul style="margin:8px 0 0 16px;font-size:13px;color:#946800;">
				<?php foreach ( $audit['warnings'] as $warning ) : ?>
					<li><?php echo esc_html( $warning ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>

		<?php self::render_nav( 'approval', null ); ?>
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
			wp_safe_redirect( admin_url( 'admin.php?page=konx-migration&step=source' ) );
			exit;
		}

		$state = get_option( 'konx_migration_state', array() );
		$state['source']  = 'database';
		$state['scan']    = $scan;
		$state['scan_at'] = current_time( 'mysql', true );
		unset( $state['dry_run'], $state['dry_run_at'], $state['approved'], $state['approved_by'], $state['approved_at'], $state['csv_info'], $state['sponsor_resolutions'] );
		update_option( 'konx_migration_state', $state, false );

		self::set_feedback( 'success', __( 'Database scan completed successfully.', 'konx-affiliate-dashboard' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=konx-migration&step=health' ) );
		exit;
	}

	/**
	 * Handle CSV file upload — validate, auto-detect field mappings, store in state.
	 */
	public static function handle_csv_upload() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		check_admin_referer( 'konx_migration_csv_upload', 'konx_csv_nonce' );

		if ( empty( $_FILES['csv_file'] ) || UPLOAD_ERR_OK !== $_FILES['csv_file']['error'] ) {
			self::set_feedback( 'error', __( 'No file uploaded or upload error.', 'konx-affiliate-dashboard' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=konx-migration' ) );
			exit;
		}

		$file = $_FILES['csv_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ext  = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );

		if ( 'csv' !== $ext ) {
			self::set_feedback( 'error', __( 'Only .csv files are accepted.', 'konx-affiliate-dashboard' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=konx-migration' ) );
			exit;
		}

		// Validate CSV structure.
		$validation = Konx_Migration_Engine::validate_csv( $file['tmp_name'] );
		if ( is_wp_error( $validation ) ) {
			self::set_feedback( 'error', $validation->get_error_message() );
			wp_safe_redirect( admin_url( 'admin.php?page=konx-migration' ) );
			exit;
		}

		// Auto-detect field mappings from CSV headers.
		$field_mappings    = Konx_CSV_Field_Mapper::auto_detect( $validation['columns_found'] );
		$mapping_validation = Konx_CSV_Field_Mapper::validate_mappings( $field_mappings );

		// Load CSV into engine for scan.
		$engine = new Konx_Migration_Engine();
		$loaded = $engine->load_from_csv( $file['tmp_name'] );

		$scan = null;
		$csv_records = array();
		if ( true === $loaded ) {
			$scan = $engine->scan_data_sources();
			foreach ( $engine->get_source_records() as $r ) {
				$csv_records[] = (array) $r;
			}
		}

		// Store in state.
		$state = get_option( 'konx_migration_state', array() );
		$validation['file_name'] = sanitize_file_name( $file['name'] );
		$state['source']         = 'csv';
		$state['csv_info']       = $validation;
		$state['csv_records']    = $csv_records;
		$state['field_mappings'] = $field_mappings;
		if ( $scan ) {
			$state['scan']    = $scan;
			$state['scan_at'] = current_time( 'mysql', true );
		}
		unset( $state['dry_run'], $state['dry_run_at'], $state['approved'], $state['approved_by'], $state['approved_at'], $state['sponsor_resolutions'] );
		update_option( 'konx_migration_state', $state, false );

		if ( $mapping_validation['valid'] ) {
			self::set_feedback( 'success', sprintf( __( 'CSV uploaded: %d records. All required fields mapped.', 'konx-affiliate-dashboard' ), $validation['row_count'] ) );
		} else {
			self::set_feedback( 'warning', sprintf( __( 'CSV uploaded: %d records. Some required fields are not mapped — review the mapping below.', 'konx-affiliate-dashboard' ), $validation['row_count'] ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=konx-migration&step=field-mapping' ) );
		exit;
	}

	/**
	 * Handle run validation — validate all source records.
	 */
	public static function handle_run_validation() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		check_admin_referer( 'konx_migration_run_validation', 'konx_val_nonce' );

		$engine  = self::build_engine_from_state();
		$records = $engine->get_source_records();

		if ( empty( $records ) ) {
			self::set_feedback( 'error', __( 'No source records available. Run a scan first.', 'konx-affiliate-dashboard' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=konx-migration' ) );
			exit;
		}

		$results = Konx_CSV_Validator::validate( $records );

		$state = get_option( 'konx_migration_state', array() );
		$state['validation_results'] = $results;
		$state['validation_at']      = current_time( 'mysql', true );
		update_option( 'konx_migration_state', $state, false );

		$msg = sprintf(
			__( 'Validation complete: %d valid, %d warnings, %d errors.', 'konx-affiliate-dashboard' ),
			$results['summary']['valid'],
			$results['summary']['with_warning'],
			$results['summary']['with_error']
		);
		self::set_feedback( 0 === $results['summary']['with_error'] ? 'success' : 'warning', $msg );
		wp_safe_redirect( admin_url( 'admin.php?page=konx-migration&step=validation' ) );
		exit;
	}

	/**
	 * Handle validation report CSV export.
	 */
	public static function handle_export_validation() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		check_admin_referer( 'konx_export_validation', 'konx_exp_nonce' );

		$state = get_option( 'konx_migration_state', array() );
		if ( empty( $state['validation_results']['issues'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=konx-migration&step=validation' ) );
			exit;
		}

		$csv_data = Konx_CSV_Validator::export_csv( $state['validation_results']['issues'] );
		$filename = 'konx-validation-report-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		foreach ( $csv_data as $row ) {
			fputcsv( $output, $row );
		}
		fclose( $output );
		exit;
	}

	/**
	 * Handle comparison report CSV export.
	 */
	public static function handle_export_comparison() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		check_admin_referer( 'konx_export_comparison', 'konx_cmp_nonce' );

		$state = get_option( 'konx_migration_state', array() );
		if ( empty( $state['comparison'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=konx-migration&step=source-comparison' ) );
			exit;
		}

		$csv = Konx_Source_Comparator::export_csv( $state['comparison'] );
		$filename = 'konx-comparison-report-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		foreach ( $csv as $row ) {
			fputcsv( $output, $row );
		}
		fclose( $output );
		exit;
	}

	/**
	 * Handle summary report CSV export.
	 */
	public static function handle_export_summary() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		check_admin_referer( 'konx_export_summary', 'konx_sum_nonce' );

		$state   = get_option( 'konx_migration_state', array() );
		$summary = Konx_Migration_Summary::build( $state );
		$csv     = Konx_Migration_Summary::export_csv( $summary );

		$filename = 'konx-migration-summary-' . gmdate( 'Y-m-d-His' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		foreach ( $csv as $row ) {
			fputcsv( $output, $row );
		}
		fclose( $output );
		exit;
	}

	/**
	 * Handle audit report CSV export.
	 */
	public static function handle_export_audit_csv() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		check_admin_referer( 'konx_export_audit', 'konx_audit_nonce' );

		$audit = Konx_Migration_Audit::build();
		if ( ! $audit ) {
			wp_safe_redirect( admin_url( 'admin.php?page=konx-migration&step=audit' ) );
			exit;
		}

		$csv      = Konx_Migration_Audit::export_csv( $audit );
		$filename = 'konx-migration-audit-' . gmdate( 'Y-m-d-His' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		foreach ( $csv as $row ) {
			fputcsv( $output, $row );
		}
		fclose( $output );
		exit;
	}

	/**
	 * Handle audit report JSON export.
	 */
	public static function handle_export_audit_json() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		check_admin_referer( 'konx_export_audit', 'konx_audit_nonce' );

		$audit = Konx_Migration_Audit::build();
		if ( ! $audit ) {
			wp_safe_redirect( admin_url( 'admin.php?page=konx-migration&step=audit' ) );
			exit;
		}

		$json     = Konx_Migration_Audit::export_json( $audit );
		$filename = 'konx-migration-audit-' . gmdate( 'Y-m-d-His' ) . '.json';
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		echo wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
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

		$engine = self::build_engine_from_state();
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
	/**
	 * Build a migration engine instance from the stored state.
	 *
	 * If the source is CSV, restores cached records from state.
	 * If the source is database, returns a standard DB-backed engine.
	 *
	 * @return Konx_Migration_Engine
	 */
	private static function build_engine_from_state() {
		$state  = get_option( 'konx_migration_state', array() );
		$engine = new Konx_Migration_Engine();

		if ( 'csv' === ( $state['source'] ?? '' ) && ! empty( $state['csv_records'] ) ) {
			// Restore cached CSV records as objects.
			$records = array();
			foreach ( $state['csv_records'] as $r ) {
				$records[] = (object) $r;
			}
			// Use reflection to set private properties since load_from_csv expects a file.
			$ref = new \ReflectionClass( $engine );
			$prop_records = $ref->getProperty( 'records' );
			$prop_records->setAccessible( true );
			$prop_records->setValue( $engine, $records );
			$prop_source = $ref->getProperty( 'source' );
			$prop_source->setAccessible( true );
			$prop_source->setValue( $engine, 'csv' );
		}

		return $engine;
	}

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
