<?php
/**
 * Admin affiliate management page.
 *
 * Provides a listing of all affiliates with search, filters, and
 * individual affiliate detail/edit views. Admins can change type,
 * status, activate pending affiliates, and assign agent roles.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Affiliates_Page
 */
class Konx_Affiliates_Page {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_konx_update_affiliate', array( __CLASS__, 'handle_update' ) );
	}

	/**
	 * Register the submenu page.
	 */
	public static function register_menu() {
		add_submenu_page(
			'konx-affiliate-dashboard',
			__( 'Affiliates', 'konx-affiliate-dashboard' ),
			__( 'Affiliates', 'konx-affiliate-dashboard' ),
			'manage_konx_affiliates',
			'konx-affiliates',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Route to list or detail view.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_affiliates' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'konx-affiliate-dashboard' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'list';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$aff_id = isset( $_GET['affiliate_id'] ) ? absint( $_GET['affiliate_id'] ) : 0;

		if ( 'detail' === $view && $aff_id ) {
			self::render_detail( $aff_id );
		} else {
			self::render_list();
		}
	}

	// ------------------------------------------------------------------
	// List View
	// ------------------------------------------------------------------

	/**
	 * Render the affiliate list view.
	 */
	private static function render_list() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type_filter = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

		$result   = self::query_affiliates( $type_filter, $status_filter, $search, $paged );
		$feedback = self::get_feedback();

		$types = array(
			''                => __( 'All Types', 'konx-affiliate-dashboard' ),
			'business'        => __( 'Business', 'konx-affiliate-dashboard' ),
			'referral'        => __( 'Referral', 'konx-affiliate-dashboard' ),
			'team_agent'      => __( 'Team Agent', 'konx-affiliate-dashboard' ),
			'marketing_agent' => __( 'Marketing Agent', 'konx-affiliate-dashboard' ),
			'sales_agent'     => __( 'Sales Agent', 'konx-affiliate-dashboard' ),
		);

		$statuses = array(
			''          => __( 'All Statuses', 'konx-affiliate-dashboard' ),
			'active'    => __( 'Active', 'konx-affiliate-dashboard' ),
			'pending'   => __( 'Pending', 'konx-affiliate-dashboard' ),
			'suspended' => __( 'Suspended', 'konx-affiliate-dashboard' ),
			'inactive'  => __( 'Inactive', 'konx-affiliate-dashboard' ),
		);

		$status_colors = array(
			'active'    => '#00a32a',
			'pending'   => '#dba617',
			'suspended' => '#d63638',
			'inactive'  => '#787c82',
		);

		?>
		<div class="wrap">
			<div class="konx-page-header">
				<h1><?php esc_html_e( 'Affiliates', 'konx-affiliate-dashboard' ); ?></h1>
			</div>

			<?php if ( $feedback ) : ?>
				<div class="notice notice-<?php echo esc_attr( $feedback['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $feedback['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Filters -->
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="konx-affiliates">
				<div class="konx-filters">
					<select name="type">
						<?php foreach ( $types as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $type_filter, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="status">
						<?php foreach ( $statuses as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status_filter, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, email, or code...', 'konx-affiliate-dashboard' ); ?>">
					<?php submit_button( __( 'Filter', 'konx-affiliate-dashboard' ), 'secondary', '', false ); ?>
				</div>
			</form>

			<?php if ( empty( $result['entries'] ) ) : ?>
				<div class="konx-empty-state">
					<span class="dashicons dashicons-groups"></span>
					<p><?php esc_html_e( 'No affiliates found. Affiliates appear here after registering.', 'konx-affiliate-dashboard' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th style="width:40px;"><?php esc_html_e( 'ID', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Name', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Email', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Type', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Code', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Sales', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Balance', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Registered', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'konx-affiliate-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['entries'] as $aff ) : ?>
							<tr>
								<td><?php echo esc_html( $aff->id ); ?></td>
								<td><?php echo esc_html( $aff->display_name ); ?></td>
								<td><?php echo esc_html( $aff->user_email ); ?></td>
								<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $aff->affiliate_type ) ) ); ?></td>
								<td>
									<span class="konx-badge konx-badge-<?php echo esc_attr( $aff->status ); ?>"><?php echo esc_html( ucfirst( $aff->status ) ); ?></span>
								</td>
								<td><code><?php echo esc_html( $aff->referral_code ); ?></code></td>
								<td><?php echo esc_html( $aff->completed_sales ); ?></td>
								<td>$<?php echo esc_html( $aff->cached_balance ); ?></td>
								<td><?php echo esc_html( date_i18n( 'Y-m-d', strtotime( $aff->registered_at ) ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-affiliates&view=detail&affiliate_id=' . $aff->id ) ); ?>" class="button button-small">
										<?php esc_html_e( 'View', 'konx-affiliate-dashboard' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $result['pages'] > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<?php
							echo wp_kses_post( paginate_links( array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $paged,
								'total'   => $result['pages'],
							) ) );
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Detail View
	// ------------------------------------------------------------------

	/**
	 * Render the affiliate detail/edit view.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 */
	private static function render_detail( $affiliate_id ) {
		$aff = Konx_Affiliate_Manager::get_affiliate( $affiliate_id );
		if ( ! $aff ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Affiliate not found.', 'konx-affiliate-dashboard' ) . '</p></div>';
			return;
		}

		$user         = get_userdata( $aff->user_id );
		$balance      = Konx_Wallet::get_affiliate_balance_summary( $affiliate_id );
		$fee_status   = Konx_Admin_Fees::get_fee_status( $affiliate_id );
		$milestone    = Konx_Milestone_Bonus::get_progress_to_next_milestone( $affiliate_id );
		$referral_url = add_query_arg( 'ref', $aff->referral_code, home_url( '/' ) );
		$feedback     = self::get_feedback();

		$all_types = array(
			'business'        => __( 'Business Affiliate', 'konx-affiliate-dashboard' ),
			'referral'        => __( 'Referral Affiliate', 'konx-affiliate-dashboard' ),
			'team_agent'      => __( 'Team Agent', 'konx-affiliate-dashboard' ),
			'marketing_agent' => __( 'Marketing Agent', 'konx-affiliate-dashboard' ),
			'sales_agent'     => __( 'Sales Agent', 'konx-affiliate-dashboard' ),
		);

		$all_statuses = array(
			'active'    => __( 'Active', 'konx-affiliate-dashboard' ),
			'pending'   => __( 'Pending', 'konx-affiliate-dashboard' ),
			'suspended' => __( 'Suspended', 'konx-affiliate-dashboard' ),
			'inactive'  => __( 'Inactive', 'konx-affiliate-dashboard' ),
		);

		// Recent commissions (5).
		$commissions = self::get_recent_commissions( $affiliate_id, 5 );

		// Recent withdrawals (5).
		$withdrawals = Konx_Withdrawals::get_requests( array(
			'affiliate_id' => $affiliate_id,
			'per_page'     => 5,
		) );

		?>
		<div class="wrap">
			<h1>
				<?php
				printf(
					/* translators: %s: affiliate display name */
					esc_html__( 'Affiliate: %s', 'konx-affiliate-dashboard' ),
					esc_html( $user ? $user->display_name : '#' . $affiliate_id )
				);
				?>
			</h1>

			<a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-affiliates' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Back to List', 'konx-affiliate-dashboard' ); ?></a>

			<?php if ( $feedback ) : ?>
				<div class="notice notice-<?php echo esc_attr( $feedback['type'] ); ?> is-dismissible" style="margin-top:12px;">
					<p><?php echo esc_html( $feedback['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="konx-grid-2" style="margin-top:20px;">

				<!-- Profile Info -->
				<div class="konx-card">
					<h2><?php esc_html_e( 'Profile', 'konx-affiliate-dashboard' ); ?></h2>
					<table class="form-table">
						<tr><th><?php esc_html_e( 'Affiliate ID', 'konx-affiliate-dashboard' ); ?></th><td><?php echo esc_html( $aff->id ); ?></td></tr>
						<tr><th><?php esc_html_e( 'WordPress User', 'konx-affiliate-dashboard' ); ?></th><td><?php echo $user ? esc_html( $user->display_name . ' (' . $user->user_email . ')' ) : '—'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Referral Code', 'konx-affiliate-dashboard' ); ?></th><td><code><?php echo esc_html( $aff->referral_code ); ?></code></td></tr>
						<tr><th><?php esc_html_e( 'Referral Link', 'konx-affiliate-dashboard' ); ?></th><td><input type="text" value="<?php echo esc_url( $referral_url ); ?>" readonly style="width:100%;"></td></tr>
						<tr><th><?php esc_html_e( 'Payment Email', 'konx-affiliate-dashboard' ); ?></th><td><?php echo $aff->payment_email ? esc_html( $aff->payment_email ) : '<em>' . esc_html__( 'Not set', 'konx-affiliate-dashboard' ) . '</em>'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Registered', 'konx-affiliate-dashboard' ); ?></th><td><?php echo esc_html( $aff->registered_at ); ?></td></tr>
						<?php if ( $aff->parent_affiliate_id ) : ?>
							<tr><th><?php esc_html_e( 'Referred By', 'konx-affiliate-dashboard' ); ?></th><td>#<?php echo esc_html( $aff->parent_affiliate_id ); ?></td></tr>
						<?php endif; ?>
					</table>
				</div>

				<!-- Edit Type & Status -->
				<div class="konx-card">
					<h2><?php esc_html_e( 'Manage', 'konx-affiliate-dashboard' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="konx_update_affiliate">
						<input type="hidden" name="affiliate_id" value="<?php echo esc_attr( $aff->id ); ?>">
						<?php wp_nonce_field( 'konx_update_affiliate_' . $aff->id, 'konx_aff_nonce' ); ?>

						<table class="form-table">
							<tr>
								<th><label for="affiliate_type"><?php esc_html_e( 'Affiliate Type', 'konx-affiliate-dashboard' ); ?></label></th>
								<td>
									<select name="affiliate_type" id="affiliate_type">
										<?php foreach ( $all_types as $val => $label ) : ?>
											<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $aff->affiliate_type, $val ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th><label for="affiliate_status"><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></label></th>
								<td>
									<select name="affiliate_status" id="affiliate_status">
										<?php foreach ( $all_statuses as $val => $label ) : ?>
											<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $aff->status, $val ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th><label for="admin_notes"><?php esc_html_e( 'Notes', 'konx-affiliate-dashboard' ); ?></label></th>
								<td><textarea name="admin_notes" id="admin_notes" rows="3" style="width:100%;"><?php echo esc_textarea( $aff->notes ); ?></textarea></td>
							</tr>
						</table>

						<?php submit_button( __( 'Save Changes', 'konx-affiliate-dashboard' ) ); ?>
					</form>
				</div>
			</div>

			<!-- Stats Row -->
			<div class="konx-stats-grid">
				<div class="konx-stat-card">
					<span class="konx-stat-value"><?php echo esc_html( $aff->completed_sales ); ?></span>
					<span class="konx-stat-label"><?php esc_html_e( 'Total Sales', 'konx-affiliate-dashboard' ); ?></span>
				</div>
				<div class="konx-stat-card">
					<span class="konx-stat-value">$<?php echo esc_html( $balance['lifetime_earnings'] ); ?></span>
					<span class="konx-stat-label"><?php esc_html_e( 'Lifetime Earnings', 'konx-affiliate-dashboard' ); ?></span>
				</div>
				<div class="konx-stat-card">
					<span class="konx-stat-value">$<?php echo esc_html( $balance['available_balance'] ); ?></span>
					<span class="konx-stat-label"><?php esc_html_e( 'Balance', 'konx-affiliate-dashboard' ); ?></span>
				</div>
				<div class="konx-stat-card">
					<span class="konx-stat-value">$<?php echo esc_html( $balance['total_withdrawals'] ); ?></span>
					<span class="konx-stat-label"><?php esc_html_e( 'Withdrawn', 'konx-affiliate-dashboard' ); ?></span>
				</div>
				<div class="konx-stat-card">
					<span class="konx-stat-value"><?php echo esc_html( $milestone['milestones_achieved'] ); ?></span>
					<span class="konx-stat-label"><?php esc_html_e( 'Milestones', 'konx-affiliate-dashboard' ); ?></span>
				</div>
				<div class="konx-stat-card">
					<span class="konx-stat-value"><?php echo $fee_status['can_earn'] ? '<span style="color:var(--konx-success);">OK</span>' : '<span style="color:var(--konx-danger);">$' . esc_html( $fee_status['total_outstanding'] ) . '</span>'; ?></span>
					<span class="konx-stat-label"><?php esc_html_e( 'Admin Fees', 'konx-affiliate-dashboard' ); ?></span>
				</div>
			</div>

			<div class="konx-grid-2">
				<!-- Recent Commissions -->
				<div class="konx-card">
					<h2><?php esc_html_e( 'Recent Commissions', 'konx-affiliate-dashboard' ); ?></h2>
					<?php if ( empty( $commissions ) ) : ?>
						<p><?php esc_html_e( 'No commissions.', 'konx-affiliate-dashboard' ); ?></p>
					<?php else : ?>
						<table class="widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Date', 'konx-affiliate-dashboard' ); ?></th>
									<th><?php esc_html_e( 'Product', 'konx-affiliate-dashboard' ); ?></th>
									<th><?php esc_html_e( 'Amount', 'konx-affiliate-dashboard' ); ?></th>
									<th><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $commissions as $c ) : ?>
									<tr>
										<td><?php echo esc_html( date_i18n( 'Y-m-d', strtotime( $c->created_at ) ) ); ?></td>
										<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $c->product_type ) ) ); ?></td>
										<td>$<?php echo esc_html( $c->commission_amount ); ?></td>
										<td><?php echo esc_html( ucfirst( $c->status ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<!-- Recent Withdrawals -->
				<div class="konx-card">
					<h2><?php esc_html_e( 'Recent Withdrawals', 'konx-affiliate-dashboard' ); ?></h2>
					<?php if ( empty( $withdrawals['entries'] ) ) : ?>
						<p><?php esc_html_e( 'No withdrawals.', 'konx-affiliate-dashboard' ); ?></p>
					<?php else : ?>
						<table class="widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Date', 'konx-affiliate-dashboard' ); ?></th>
									<th><?php esc_html_e( 'Amount', 'konx-affiliate-dashboard' ); ?></th>
									<th><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $withdrawals['entries'] as $w ) : ?>
									<tr>
										<td><?php echo esc_html( date_i18n( 'Y-m-d', strtotime( $w->requested_at ) ) ); ?></td>
										<td>$<?php echo esc_html( $w->amount ); ?></td>
										<td><?php echo esc_html( ucfirst( $w->status ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Action Handler
	// ------------------------------------------------------------------

	/**
	 * Handle affiliate type/status updates.
	 */
	public static function handle_update() {
		if ( ! current_user_can( 'manage_konx_affiliates' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		$affiliate_id = isset( $_POST['affiliate_id'] ) ? absint( $_POST['affiliate_id'] ) : 0;
		check_admin_referer( 'konx_update_affiliate_' . $affiliate_id, 'konx_aff_nonce' );

		if ( ! $affiliate_id ) {
			self::redirect_with_feedback( 'error', __( 'Invalid affiliate ID.', 'konx-affiliate-dashboard' ), $affiliate_id );
			return;
		}

		$new_type   = isset( $_POST['affiliate_type'] ) ? sanitize_text_field( wp_unslash( $_POST['affiliate_type'] ) ) : '';
		$new_status = isset( $_POST['affiliate_status'] ) ? sanitize_text_field( wp_unslash( $_POST['affiliate_status'] ) ) : '';
		$notes      = isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ) ) : '';

		$messages = array();

		// Update type if changed.
		if ( ! empty( $new_type ) ) {
			$result = Konx_Affiliate_Manager::update_affiliate_type( $affiliate_id, $new_type );
			if ( is_wp_error( $result ) ) {
				self::redirect_with_feedback( 'error', $result->get_error_message(), $affiliate_id );
				return;
			}
			if ( true === $result ) {
				// $result is true even if type unchanged; only add message if it actually changed.
				$messages[] = __( 'Type updated.', 'konx-affiliate-dashboard' );
			}
		}

		// Update status if changed.
		if ( ! empty( $new_status ) ) {
			$result = Konx_Affiliate_Manager::update_affiliate_status( $affiliate_id, $new_status );
			if ( is_wp_error( $result ) ) {
				self::redirect_with_feedback( 'error', $result->get_error_message(), $affiliate_id );
				return;
			}
			if ( true === $result ) {
				$messages[] = __( 'Status updated.', 'konx-affiliate-dashboard' );
			}
		}

		// Update notes.
		if ( '' !== $notes ) {
			global $wpdb;
			$table = $wpdb->prefix . 'konx_affiliates';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'notes'      => $notes,
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $affiliate_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$messages[] = __( 'Notes saved.', 'konx-affiliate-dashboard' );
		}

		$msg = ! empty( $messages ) ? implode( ' ', $messages ) : __( 'No changes made.', 'konx-affiliate-dashboard' );
		self::redirect_with_feedback( 'success', $msg, $affiliate_id );
	}

	// ------------------------------------------------------------------
	// Queries
	// ------------------------------------------------------------------

	/**
	 * Query affiliates with filters and pagination.
	 *
	 * @param string $type     Type filter.
	 * @param string $status   Status filter.
	 * @param string $search   Search term.
	 * @param int    $page     Page number.
	 * @return array { entries, total, pages }
	 */
	private static function query_affiliates( $type, $status, $search, $page ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'konx_affiliates';
		$per_page = 20;
		$offset   = ( max( 1, (int) $page ) - 1 ) * $per_page;

		$where  = 'WHERE 1=1';
		$params = array();

		if ( ! empty( $type ) ) {
			$where   .= ' AND a.affiliate_type = %s';
			$params[] = $type;
		}

		if ( ! empty( $status ) ) {
			$where   .= ' AND a.status = %s';
			$params[] = $status;
		}

		if ( ! empty( $search ) ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND (a.referral_code LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$count_sql = "SELECT COUNT(*) FROM {$table} a INNER JOIN {$wpdb->users} u ON a.user_id = u.ID {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = empty( $params )
			? (int) $wpdb->get_var( $count_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		$select_params   = $params;
		$select_params[] = $per_page;
		$select_params[] = $offset;

		$select_sql = "SELECT a.*, u.display_name, u.user_email
			FROM {$table} a
			INNER JOIN {$wpdb->users} u ON a.user_id = u.ID
			{$where}
			ORDER BY a.registered_at DESC
			LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$entries = empty( $select_params )
			? $wpdb->get_results( $select_sql )
			: $wpdb->get_results( $wpdb->prepare( $select_sql, $select_params ) );

		return array(
			'entries' => $entries ? $entries : array(),
			'total'   => $total,
			'pages'   => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Get recent commissions for an affiliate.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 * @param int $limit        Number of records.
	 * @return array
	 */
	private static function get_recent_commissions( $affiliate_id, $limit = 5 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_commissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE affiliate_id = %d ORDER BY created_at DESC LIMIT %d",
			absint( $affiliate_id ),
			absint( $limit )
		) );

		return $results ? $results : array();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Redirect with feedback.
	 *
	 * @param string $type         'success' or 'error'.
	 * @param string $message      Feedback message.
	 * @param int    $affiliate_id Optional affiliate ID for detail redirect.
	 */
	private static function redirect_with_feedback( $type, $message, $affiliate_id = 0 ) {
		set_transient( 'konx_aff_feedback', array(
			'type'    => $type,
			'message' => $message,
		), 30 );

		$url = $affiliate_id
			? admin_url( 'admin.php?page=konx-affiliates&view=detail&affiliate_id=' . $affiliate_id )
			: admin_url( 'admin.php?page=konx-affiliates' );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Get and clear feedback.
	 *
	 * @return array|false
	 */
	private static function get_feedback() {
		$feedback = get_transient( 'konx_aff_feedback' );
		if ( $feedback ) {
			delete_transient( 'konx_aff_feedback' );
		}
		return $feedback;
	}
}
