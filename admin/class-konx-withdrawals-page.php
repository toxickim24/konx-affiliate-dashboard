<?php
/**
 * Admin page for withdrawal request management.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Withdrawals_Page
 */
class Konx_Withdrawals_Page {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_konx_withdrawal_action', array( __CLASS__, 'handle_action' ) );
	}

	/**
	 * Register the submenu page.
	 */
	public static function register_menu() {
		add_submenu_page(
			'konx-affiliate-dashboard',
			__( 'Withdrawals', 'konx-affiliate-dashboard' ),
			__( 'Withdrawals', 'konx-affiliate-dashboard' ),
			'manage_konx_withdrawals',
			'konx-withdrawals',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the withdrawals admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_withdrawals' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'konx-affiliate-dashboard' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

		$result   = Konx_Withdrawals::get_requests( array(
			'status'   => $status_filter,
			'search'   => $search,
			'page'     => $paged,
			'per_page' => 20,
		) );
		$feedback = self::get_feedback();

		$statuses = array(
			''          => __( 'All Statuses', 'konx-affiliate-dashboard' ),
			'pending'   => __( 'Pending', 'konx-affiliate-dashboard' ),
			'approved'  => __( 'Approved', 'konx-affiliate-dashboard' ),
			'completed' => __( 'Completed', 'konx-affiliate-dashboard' ),
			'rejected'  => __( 'Rejected', 'konx-affiliate-dashboard' ),
			'cancelled' => __( 'Cancelled', 'konx-affiliate-dashboard' ),
		);

		$status_colors = array(
			'pending'   => '#dba617',
			'approved'  => '#2271b1',
			'completed' => '#00a32a',
			'rejected'  => '#d63638',
			'cancelled' => '#787c82',
		);

		?>
		<div class="wrap">
			<div class="konx-page-header">
				<h1><?php esc_html_e( 'Withdrawal Requests', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'withdrawal_status' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h1>
			</div>

			<?php if ( $feedback ) : ?>
				<div class="notice notice-<?php echo esc_attr( $feedback['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $feedback['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Filters -->
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="konx-withdrawals">
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

			<!-- Requests Table -->
			<?php if ( empty( $result['entries'] ) ) : ?>
				<div class="konx-empty-state">
					<span class="dashicons dashicons-money-alt"></span>
					<p><?php esc_html_e( 'No withdrawal requests found.', 'konx-affiliate-dashboard' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th style="width:50px;"><?php esc_html_e( 'ID', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Affiliate', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Balance', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Wise Email', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Requested', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Processed', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'konx-affiliate-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['entries'] as $req ) : ?>
							<tr>
								<td><?php echo esc_html( $req->id ); ?></td>
								<td>
									<?php echo esc_html( $req->display_name ); ?>
									<br><small><?php echo esc_html( $req->user_email ); ?></small>
								</td>
								<td><strong>$<?php echo esc_html( $req->amount ); ?></strong></td>
								<td>$<?php echo esc_html( $req->cached_balance ); ?></td>
								<td><?php echo esc_html( $req->payment_email ); ?></td>
								<td>
									<?php
									printf(
										'<span class="konx-badge konx-badge-%s">%s</span>',
										esc_attr( $req->status ),
										esc_html( ucfirst( $req->status ) )
									);
									?>
								</td>
								<td><?php echo esc_html( $req->requested_at ); ?></td>
								<td><?php echo $req->processed_at ? esc_html( $req->processed_at ) : '—'; ?></td>
								<td>
									<?php self::render_actions( $req ); ?>
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

	/**
	 * Render action buttons for a withdrawal request.
	 *
	 * @param object $req The request row.
	 */
	private static function render_actions( $req ) {
		if ( ! in_array( $req->status, array( 'pending', 'approved' ), true ) ) {
			if ( $req->transaction_reference ) {
				echo '<small>' . esc_html__( 'Ref: ', 'konx-affiliate-dashboard' ) . esc_html( $req->transaction_reference ) . '</small>';
			}
			return;
		}

		$base_url = admin_url( 'admin-post.php' );

		if ( 'pending' === $req->status ) {
			echo '<a href="' . esc_url( self::action_url( $req->id, 'approve' ) ) . '" class="button button-small">' .
				esc_html__( 'Approve', 'konx-affiliate-dashboard' ) . '</a> ';
		}

		// Complete form with Wise reference field.
		?>
		<form method="post" action="<?php echo esc_url( $base_url ); ?>" style="display:inline;">
			<input type="hidden" name="action" value="konx_withdrawal_action">
			<input type="hidden" name="request_id" value="<?php echo esc_attr( $req->id ); ?>">
			<input type="hidden" name="do" value="complete">
			<?php wp_nonce_field( 'konx_withdrawal_' . $req->id, 'konx_wd_nonce' ); ?>
			<input type="text" name="transaction_reference" placeholder="<?php esc_attr_e( 'Wise ref', 'konx-affiliate-dashboard' ); ?>" style="width:120px;" required>
			<button type="submit" class="button button-small button-primary"
				onclick="return confirm('<?php echo esc_js( sprintf( __( 'Complete withdrawal of $%s? This will debit the wallet.', 'konx-affiliate-dashboard' ), $req->amount ) ); ?>');">
				<?php esc_html_e( 'Complete', 'konx-affiliate-dashboard' ); ?>
			</button>
		</form>
		<?php

		echo ' <a href="' . esc_url( self::action_url( $req->id, 'reject' ) ) . '" class="button button-small"'
			. ' onclick="var r=prompt(\'' . esc_js( __( 'Rejection reason:', 'konx-affiliate-dashboard' ) ) . '\');if(!r)return false;this.href+=\'&reason=\'+encodeURIComponent(r);return true;">'
			. esc_html__( 'Reject', 'konx-affiliate-dashboard' ) . '</a> ';

		echo ' <a href="' . esc_url( self::action_url( $req->id, 'cancel' ) ) . '" class="button button-small"'
			. ' onclick="return confirm(\'' . esc_js( __( 'Cancel this withdrawal request?', 'konx-affiliate-dashboard' ) ) . '\');">'
			. esc_html__( 'Cancel', 'konx-affiliate-dashboard' ) . '</a>';
	}

	// ------------------------------------------------------------------
	// Action Handler
	// ------------------------------------------------------------------

	/**
	 * Handle all withdrawal admin actions.
	 */
	public static function handle_action() {
		if ( ! current_user_can( 'manage_konx_withdrawals' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		// Determine source: POST (complete) or GET (approve/reject/cancel).
		$is_post    = 'POST' === $_SERVER['REQUEST_METHOD'];
		$request_id = $is_post
			? ( isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0 )
			: ( isset( $_GET['request_id'] ) ? absint( $_GET['request_id'] ) : 0 );
		$do_action  = $is_post
			? ( isset( $_POST['do'] ) ? sanitize_text_field( wp_unslash( $_POST['do'] ) ) : '' )
			: ( isset( $_GET['do'] ) ? sanitize_text_field( wp_unslash( $_GET['do'] ) ) : '' );

		if ( ! $request_id || ! $do_action ) {
			self::redirect_with_feedback( 'error', __( 'Invalid request.', 'konx-affiliate-dashboard' ) );
			return;
		}

		// Verify nonce.
		if ( $is_post ) {
			check_admin_referer( 'konx_withdrawal_' . $request_id, 'konx_wd_nonce' );
		} else {
			check_admin_referer( 'konx_wd_action_' . $request_id );
		}

		$result = null;

		switch ( $do_action ) {
			case 'approve':
				$result = Konx_Withdrawals::approve_request( $request_id );
				$msg    = __( 'Withdrawal approved.', 'konx-affiliate-dashboard' );
				break;

			case 'reject':
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$reason = isset( $_GET['reason'] ) ? sanitize_text_field( wp_unslash( $_GET['reason'] ) ) : '';
				if ( empty( $reason ) ) {
					$reason = __( 'Rejected by admin.', 'konx-affiliate-dashboard' );
				}
				$result = Konx_Withdrawals::reject_request( $request_id, $reason );
				$msg    = __( 'Withdrawal rejected.', 'konx-affiliate-dashboard' );
				break;

			case 'cancel':
				$result = Konx_Withdrawals::cancel_request( $request_id );
				$msg    = __( 'Withdrawal cancelled.', 'konx-affiliate-dashboard' );
				break;

			case 'complete':
				$tx_ref = isset( $_POST['transaction_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['transaction_reference'] ) ) : '';
				$result = Konx_Withdrawals::complete_request( $request_id, $tx_ref );
				$msg    = __( 'Withdrawal completed. Wallet debited.', 'konx-affiliate-dashboard' );
				break;

			default:
				self::redirect_with_feedback( 'error', __( 'Unknown action.', 'konx-affiliate-dashboard' ) );
				return;
		}

		if ( is_wp_error( $result ) ) {
			self::redirect_with_feedback( 'error', $result->get_error_message() );
		} else {
			self::redirect_with_feedback( 'success', $msg );
		}
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Generate a nonced action URL.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $do_action  Action name.
	 * @return string
	 */
	private static function action_url( $request_id, $do_action ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'konx_withdrawal_action',
					'request_id' => $request_id,
					'do'         => $do_action,
				),
				admin_url( 'admin-post.php' )
			),
			'konx_wd_action_' . $request_id
		);
	}

	/**
	 * Redirect with feedback.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Feedback message.
	 */
	private static function redirect_with_feedback( $type, $message ) {
		set_transient( 'konx_wd_feedback', array(
			'type'    => $type,
			'message' => $message,
		), 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=konx-withdrawals' ) );
		exit;
	}

	/**
	 * Get and clear stored feedback.
	 *
	 * @return array|false
	 */
	private static function get_feedback() {
		$feedback = get_transient( 'konx_wd_feedback' );
		if ( $feedback ) {
			delete_transient( 'konx_wd_feedback' );
		}
		return $feedback;
	}
}
