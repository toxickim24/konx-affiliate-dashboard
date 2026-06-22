<?php
/**
 * Admin page for admin fee management.
 *
 * Provides a UI under "KonX Affiliates > Admin Fees" where administrators
 * can view fee records, mark them as paid/overdue/waived, search by
 * affiliate, and filter by status.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Admin_Fees_Page
 */
class Konx_Admin_Fees_Page {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_konx_update_fee_status', array( __CLASS__, 'handle_status_update' ) );
		add_action( 'admin_post_konx_create_fee', array( __CLASS__, 'handle_create_fee' ) );
	}

	/**
	 * Register the submenu page.
	 */
	public static function register_menu() {
		add_submenu_page(
			'konx-affiliate-dashboard',
			__( 'Admin Fees', 'konx-affiliate-dashboard' ),
			__( 'Admin Fees', 'konx-affiliate-dashboard' ),
			'manage_konx_settings',
			'konx-admin-fees',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the admin fees page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'konx-affiliate-dashboard' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

		$result = Konx_Admin_Fees::get_all_fees( $status_filter, $search, $paged, 20 );
		$feedback = self::get_feedback();

		$statuses = array(
			''        => __( 'All Statuses', 'konx-affiliate-dashboard' ),
			'unpaid'  => __( 'Unpaid', 'konx-affiliate-dashboard' ),
			'overdue' => __( 'Overdue', 'konx-affiliate-dashboard' ),
			'paid'    => __( 'Paid', 'konx-affiliate-dashboard' ),
			'waived'  => __( 'Waived', 'konx-affiliate-dashboard' ),
		);

		?>
		<div class="wrap">
			<div class="konx-page-header">
				<h1><?php esc_html_e( 'Admin Fees', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'admin_fee' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h1>
			</div>

			<?php if ( $feedback ) : ?>
				<div class="notice notice-<?php echo esc_attr( $feedback['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $feedback['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Filters -->
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="konx-admin-fees">
				<div class="konx-filters">
					<select name="status">
						<?php foreach ( $statuses as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status_filter, $val ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search affiliate...', 'konx-affiliate-dashboard' ); ?>">
					<?php submit_button( __( 'Filter', 'konx-affiliate-dashboard' ), 'secondary', '', false ); ?>
				</div>
			</form>

			<!-- Create Fee Form -->
			<h2><?php esc_html_e( 'Create Fee', 'konx-affiliate-dashboard' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:20px;">
				<input type="hidden" name="action" value="konx_create_fee">
				<?php wp_nonce_field( 'konx_create_fee', 'konx_fee_nonce' ); ?>
				<label><?php esc_html_e( 'Affiliate:', 'konx-affiliate-dashboard' ); ?>
					<select name="affiliate_id" required>
						<option value=""><?php esc_html_e( '— Select Affiliate —', 'konx-affiliate-dashboard' ); ?></option>
						<?php
						global $wpdb;
						$aff_list = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
							"SELECT a.id, u.display_name FROM {$wpdb->prefix}konx_affiliates a INNER JOIN {$wpdb->users} u ON a.user_id = u.ID ORDER BY u.display_name"
						);
						foreach ( $aff_list as $a ) {
							printf( '<option value="%d">%s (#%d)</option>', esc_attr( $a->id ), esc_html( $a->display_name ), esc_html( $a->id ) );
						}
						?>
					</select>
				</label>
				<label><?php esc_html_e( 'Period:', 'konx-affiliate-dashboard' ); ?>
					<input type="text" name="fee_period" placeholder="2026-07" required style="width:100px;">
				</label>
				<label><?php esc_html_e( 'Due Date:', 'konx-affiliate-dashboard' ); ?>
					<input type="date" name="due_date" required>
				</label>
				<label><?php esc_html_e( 'Amount:', 'konx-affiliate-dashboard' ); ?>
					<input type="number" name="fee_amount" step="0.01" min="0" style="width:80px;" placeholder="auto">
				</label>
				<?php submit_button( __( 'Create', 'konx-affiliate-dashboard' ), 'secondary', '', false ); ?>
			</form>

			<!-- Fee List -->
			<?php if ( empty( $result['entries'] ) ) : ?>
				<p><?php esc_html_e( 'No fee records found.', 'konx-affiliate-dashboard' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th style="width:50px;"><?php esc_html_e( 'ID', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Affiliate', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Type', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Period', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Due Date', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Paid Date', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'konx-affiliate-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['entries'] as $fee ) : ?>
							<tr>
								<td><?php echo esc_html( $fee->id ); ?></td>
								<td>
									<?php echo esc_html( $fee->display_name ); ?>
									<br><small><?php echo esc_html( $fee->user_email ); ?></small>
								</td>
								<td><?php echo esc_html( $fee->affiliate_type ); ?></td>
								<td><?php echo esc_html( $fee->fee_period ); ?></td>
								<td>$<?php echo esc_html( $fee->fee_amount ); ?></td>
								<td><?php echo esc_html( $fee->due_date ); ?></td>
								<td>
									<span class="konx-badge konx-badge-<?php echo esc_attr( $fee->status ); ?>"><?php echo esc_html( ucfirst( $fee->status ) ); ?></span>
								</td>
								<td><?php echo $fee->paid_date ? esc_html( $fee->paid_date ) : '—'; ?></td>
								<td>
									<?php if ( in_array( $fee->status, array( 'unpaid', 'overdue' ), true ) ) : ?>
										<a href="<?php echo esc_url( self::action_url( $fee->id, 'paid' ) ); ?>" class="button button-small button-primary">
											<?php esc_html_e( 'Mark Paid', 'konx-affiliate-dashboard' ); ?>
										</a>
										<a href="<?php echo esc_url( self::action_url( $fee->id, 'waived' ) ); ?>" class="button button-small">
											<?php esc_html_e( 'Waive', 'konx-affiliate-dashboard' ); ?>
										</a>
									<?php endif; ?>
									<?php if ( 'unpaid' === $fee->status ) : ?>
										<a href="<?php echo esc_url( self::action_url( $fee->id, 'overdue' ) ); ?>" class="button button-small">
											<?php esc_html_e( 'Mark Overdue', 'konx-affiliate-dashboard' ); ?>
										</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $result['pages'] > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<?php
							echo wp_kses_post(
								paginate_links( array(
									'base'    => add_query_arg( 'paged', '%#%' ),
									'format'  => '',
									'current' => $paged,
									'total'   => $result['pages'],
								) )
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Action Handlers
	// ------------------------------------------------------------------

	/**
	 * Handle fee status update.
	 */
	public static function handle_status_update() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		$fee_id     = isset( $_GET['fee_id'] ) ? absint( $_GET['fee_id'] ) : 0;
		$new_status = isset( $_GET['new_status'] ) ? sanitize_text_field( wp_unslash( $_GET['new_status'] ) ) : '';

		check_admin_referer( 'konx_fee_action_' . $fee_id );

		if ( ! $fee_id || ! $new_status ) {
			self::redirect_with_feedback( 'error', __( 'Invalid request.', 'konx-affiliate-dashboard' ) );
			return;
		}

		$method_map = array(
			'paid'    => 'mark_paid',
			'overdue' => 'mark_overdue',
			'waived'  => 'mark_waived',
		);

		if ( ! isset( $method_map[ $new_status ] ) ) {
			self::redirect_with_feedback( 'error', __( 'Invalid status.', 'konx-affiliate-dashboard' ) );
			return;
		}

		$result = Konx_Admin_Fees::{$method_map[ $new_status ]}( $fee_id );

		if ( is_wp_error( $result ) ) {
			self::redirect_with_feedback( 'error', $result->get_error_message() );
		} else {
			self::redirect_with_feedback(
				'success',
				sprintf(
					/* translators: 1: fee ID, 2: new status */
					__( 'Fee #%1$d marked as %2$s.', 'konx-affiliate-dashboard' ),
					$fee_id,
					$new_status
				)
			);
		}
	}

	/**
	 * Handle fee creation.
	 */
	public static function handle_create_fee() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		check_admin_referer( 'konx_create_fee', 'konx_fee_nonce' );

		$affiliate_id = isset( $_POST['affiliate_id'] ) ? absint( $_POST['affiliate_id'] ) : 0;
		$fee_period   = isset( $_POST['fee_period'] ) ? sanitize_text_field( wp_unslash( $_POST['fee_period'] ) ) : '';
		$due_date     = isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '';
		$fee_amount   = isset( $_POST['fee_amount'] ) && '' !== $_POST['fee_amount']
			? sanitize_text_field( wp_unslash( $_POST['fee_amount'] ) )
			: '';

		if ( ! $affiliate_id || ! $fee_period || ! $due_date ) {
			self::redirect_with_feedback( 'error', __( 'Affiliate ID, period, and due date are required.', 'konx-affiliate-dashboard' ) );
			return;
		}

		$affiliate = Konx_Affiliate_Manager::get_affiliate( $affiliate_id );
		if ( ! $affiliate ) {
			self::redirect_with_feedback( 'error', __( 'Affiliate not found.', 'konx-affiliate-dashboard' ) );
			return;
		}

		$result = Konx_Admin_Fees::create_monthly_fee( $affiliate_id, $fee_period, $due_date, $fee_amount );

		if ( is_wp_error( $result ) ) {
			self::redirect_with_feedback( 'error', $result->get_error_message() );
		} else {
			self::redirect_with_feedback( 'success', __( 'Fee created.', 'konx-affiliate-dashboard' ) );
		}
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Generate a nonced action URL for a fee status change.
	 *
	 * @param int    $fee_id     Fee record ID.
	 * @param string $new_status Target status.
	 * @return string The nonced URL.
	 */
	private static function action_url( $fee_id, $new_status ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'konx_update_fee_status',
					'fee_id'     => $fee_id,
					'new_status' => $new_status,
				),
				admin_url( 'admin-post.php' )
			),
			'konx_fee_action_' . $fee_id
		);
	}

	/**
	 * Redirect with feedback.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Feedback message.
	 */
	private static function redirect_with_feedback( $type, $message ) {
		set_transient( 'konx_fee_feedback', array(
			'type'    => $type,
			'message' => $message,
		), 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=konx-admin-fees' ) );
		exit;
	}

	/**
	 * Get and clear stored feedback.
	 *
	 * @return array|false
	 */
	private static function get_feedback() {
		$feedback = get_transient( 'konx_fee_feedback' );
		if ( $feedback ) {
			delete_transient( 'konx_fee_feedback' );
		}
		return $feedback;
	}
}
