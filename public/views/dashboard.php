<?php
/**
 * Affiliate dashboard view template.
 *
 * Variables available from Konx_Dashboard::prepare_dashboard_data():
 *   $data['affiliate']          — Affiliate row object.
 *   $data['balance']            — Balance summary array.
 *   $data['milestone']          — Milestone progress array.
 *   $data['estimated_bonus']    — Estimated next bonus (string).
 *   $data['fee_status']         — Admin fee status array.
 *   $data['commissions']        — Commission history { entries, total, pages }.
 *   $data['bonuses']            — Bonus history { entries, total, pages }.
 *   $data['pending_withdrawal'] — Pending withdrawal object or null.
 *   $data['withdrawal_history'] — Withdrawal history { entries, total, pages }.
 *   $data['min_withdrawal']     — Minimum withdrawal amount (string).
 *   $data['referral_url']       — Full referral URL.
 *   $data['feedback']           — Feedback message array or false.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aff       = $data['affiliate'];
$balance   = $data['balance'];
$milestone = $data['milestone'];
$fee       = $data['fee_status'];
?>

<div class="konx-dashboard">

	<!-- Top Bar -->
	<div class="konx-topbar">
		<span><?php printf( esc_html__( 'Welcome, %s', 'konx-affiliate-dashboard' ), esc_html( wp_get_current_user()->display_name ) ); ?></span>
		<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="konx-btn konx-btn-sm">
			<?php esc_html_e( 'Log Out', 'konx-affiliate-dashboard' ); ?>
		</a>
	</div>

	<?php if ( $data['feedback'] ) : ?>
		<div class="konx-notice konx-notice-<?php echo esc_attr( $data['feedback']['type'] ); ?>">
			<?php echo esc_html( $data['feedback']['message'] ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! $fee['can_earn'] ) : ?>
		<div class="konx-notice konx-notice-error">
			<?php
			printf(
				/* translators: %s: outstanding fee amount */
				esc_html__( 'You have $%s in outstanding admin fees. Commissions are paused until fees are paid.', 'konx-affiliate-dashboard' ),
				esc_html( $fee['total_outstanding'] )
			);
			?>
		</div>
	<?php endif; ?>

	<!-- Profile & Referral -->
	<div class="konx-section konx-profile">
		<h3><?php esc_html_e( 'Your Affiliate Profile', 'konx-affiliate-dashboard' ); ?></h3>
		<table class="konx-info-table">
			<tr>
				<td><?php esc_html_e( 'Type', 'konx-affiliate-dashboard' ); ?></td>
				<td><strong><?php echo esc_html( Konx_Dashboard::format_type( $aff->affiliate_type ) ); ?></strong></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></td>
				<td><?php echo wp_kses( Konx_Dashboard::format_status( $aff->status ), array( 'span' => array( 'style' => array() ) ) ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Referral Code', 'konx-affiliate-dashboard' ); ?></td>
				<td><code><?php echo esc_html( $aff->referral_code ); ?></code></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Referral Link', 'konx-affiliate-dashboard' ); ?></td>
				<td>
					<input type="text" id="konx-referral-url" value="<?php echo esc_url( $data['referral_url'] ); ?>" readonly class="konx-referral-input">
					<button type="button" class="konx-btn konx-btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('konx-referral-url').value).then(function(){alert('<?php echo esc_js( __( 'Copied!', 'konx-affiliate-dashboard' ) ); ?>')});">
						<?php esc_html_e( 'Copy', 'konx-affiliate-dashboard' ); ?>
					</button>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Member Since', 'konx-affiliate-dashboard' ); ?></td>
				<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $aff->registered_at ) ) ); ?></td>
			</tr>
		</table>
	</div>

	<!-- Financial Summary -->
	<div class="konx-section konx-summary">
		<h3><?php esc_html_e( 'Financial Summary', 'konx-affiliate-dashboard' ); ?></h3>
		<div class="konx-stats-grid">
			<div class="konx-stat">
				<span class="konx-stat-value">$<?php echo esc_html( $balance['lifetime_earnings'] ); ?></span>
				<span class="konx-stat-label"><?php esc_html_e( 'Total Earnings', 'konx-affiliate-dashboard' ); ?></span>
			</div>
			<div class="konx-stat">
				<span class="konx-stat-value">$<?php echo esc_html( $balance['available_balance'] ); ?></span>
				<span class="konx-stat-label"><?php esc_html_e( 'Available Balance', 'konx-affiliate-dashboard' ); ?></span>
			</div>
			<div class="konx-stat">
				<span class="konx-stat-value">$<?php echo esc_html( $balance['total_withdrawals'] ); ?></span>
				<span class="konx-stat-label"><?php esc_html_e( 'Total Withdrawn', 'konx-affiliate-dashboard' ); ?></span>
			</div>
			<div class="konx-stat">
				<span class="konx-stat-value"><?php echo esc_html( $milestone['completed_sales'] ); ?></span>
				<span class="konx-stat-label"><?php esc_html_e( 'Total Sales', 'konx-affiliate-dashboard' ); ?></span>
			</div>
		</div>
	</div>

	<!-- Milestone Progress -->
	<div class="konx-section konx-milestone">
		<h3><?php esc_html_e( 'Milestone Progress', 'konx-affiliate-dashboard' ); ?></h3>
		<div class="konx-progress-wrap">
			<div class="konx-progress-bar">
				<div class="konx-progress-fill" style="width:<?php echo esc_attr( min( 100, $milestone['percent_complete'] ) ); ?>%;"></div>
			</div>
			<p class="konx-progress-text">
				<?php
				printf(
					/* translators: 1: sales in current block, 2: block size, 3: next milestone target */
					esc_html__( '%1$d / %2$d sales toward milestone at %3$d', 'konx-affiliate-dashboard' ),
					$milestone['sales_in_block'],
					Konx_Milestone_Bonus::BLOCK_SIZE,
					$milestone['next_milestone_at']
				);
				?>
			</p>
			<p class="konx-progress-meta">
				<?php
				printf(
					/* translators: 1: milestones achieved, 2: estimated bonus */
					esc_html__( 'Milestones achieved: %1$d | Estimated next bonus: $%2$s', 'konx-affiliate-dashboard' ),
					$milestone['milestones_achieved'],
					esc_html( $data['estimated_bonus'] )
				);
				?>
			</p>
		</div>

		<?php if ( ! empty( $data['bonuses']['entries'] ) ) : ?>
			<h4><?php esc_html_e( 'Bonus History', 'konx-affiliate-dashboard' ); ?></h4>
			<table class="konx-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Milestone', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Block', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Bonus', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Date', 'konx-affiliate-dashboard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['bonuses']['entries'] as $bonus ) : ?>
						<tr>
							<td>#<?php echo esc_html( $bonus->milestone_number ); ?></td>
							<td><?php echo esc_html( $bonus->sale_block_start . '–' . $bonus->sale_block_end ); ?></td>
							<td>$<?php echo esc_html( $bonus->bonus_amount ); ?></td>
							<td><?php echo wp_kses( Konx_Dashboard::format_status( $bonus->status ), array( 'span' => array( 'style' => array() ) ) ); ?></td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $bonus->created_at ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- Commission History -->
	<div class="konx-section konx-commissions">
		<h3><?php esc_html_e( 'Recent Commissions', 'konx-affiliate-dashboard' ); ?></h3>
		<?php if ( empty( $data['commissions']['entries'] ) ) : ?>
			<p><?php esc_html_e( 'No commissions yet.', 'konx-affiliate-dashboard' ); ?></p>
		<?php else : ?>
			<table class="konx-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Type', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Product', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Price', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Rate', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Commission', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['commissions']['entries'] as $comm ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $comm->created_at ) ) ); ?></td>
							<td><?php echo esc_html( Konx_Dashboard::format_commission_type( $comm->commission_type ) ); ?></td>
							<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $comm->product_type ) ) ); ?></td>
							<td>$<?php echo esc_html( $comm->product_price ); ?></td>
							<td><?php echo esc_html( number_format( (float) $comm->commission_rate * 100, 0 ) ); ?>%</td>
							<td>$<?php echo esc_html( $comm->commission_amount ); ?></td>
							<td><?php echo wp_kses( Konx_Dashboard::format_status( $comm->status ), array( 'span' => array( 'style' => array() ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $data['commissions']['total'] > 10 ) : ?>
				<p class="konx-muted"><?php printf( esc_html__( 'Showing 10 of %d commissions.', 'konx-affiliate-dashboard' ), $data['commissions']['total'] ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<!-- Withdrawal Section -->
	<div class="konx-section konx-withdrawals">
		<h3><?php esc_html_e( 'Withdrawals', 'konx-affiliate-dashboard' ); ?></h3>

		<?php if ( $data['pending_withdrawal'] ) : ?>
			<div class="konx-notice konx-notice-info">
				<?php
				printf(
					/* translators: 1: amount, 2: status */
					esc_html__( 'You have a %2$s withdrawal request for $%1$s. Please wait for it to be processed.', 'konx-affiliate-dashboard' ),
					esc_html( $data['pending_withdrawal']->amount ),
					esc_html( $data['pending_withdrawal']->status )
				);
				?>
			</div>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="konx-withdrawal-form">
				<input type="hidden" name="action" value="konx_affiliate_withdrawal">
				<?php wp_nonce_field( 'konx_withdrawal_request_' . $aff->id, 'konx_wd_nonce' ); ?>

				<div class="konx-form-row">
					<label for="konx-wd-amount"><?php esc_html_e( 'Amount ($)', 'konx-affiliate-dashboard' ); ?></label>
					<input type="number" id="konx-wd-amount" name="amount" step="0.01"
						min="<?php echo esc_attr( $data['min_withdrawal'] ); ?>"
						max="<?php echo esc_attr( $balance['available_balance'] ); ?>"
						required>
					<small>
						<?php
						printf(
							/* translators: 1: available balance, 2: minimum amount */
							esc_html__( 'Available: $%1$s | Minimum: $%2$s', 'konx-affiliate-dashboard' ),
							esc_html( $balance['available_balance'] ),
							esc_html( $data['min_withdrawal'] )
						);
						?>
					</small>
				</div>

				<div class="konx-form-row">
					<label for="konx-wd-email"><?php esc_html_e( 'Wise Email', 'konx-affiliate-dashboard' ); ?></label>
					<input type="email" id="konx-wd-email" name="payment_email"
						value="<?php echo esc_attr( $aff->payment_email ); ?>" required>
				</div>

				<div class="konx-form-row">
					<label for="konx-wd-holder"><?php esc_html_e( 'Account Holder Name', 'konx-affiliate-dashboard' ); ?></label>
					<input type="text" id="konx-wd-holder" name="account_holder">
				</div>

				<div class="konx-form-row">
					<label for="konx-wd-currency"><?php esc_html_e( 'Currency', 'konx-affiliate-dashboard' ); ?></label>
					<select id="konx-wd-currency" name="currency">
						<option value="USD" selected>USD</option>
					</select>
				</div>

				<div class="konx-form-row">
					<label for="konx-wd-notes"><?php esc_html_e( 'Notes (optional)', 'konx-affiliate-dashboard' ); ?></label>
					<textarea id="konx-wd-notes" name="notes" rows="2"></textarea>
				</div>

				<button type="submit" class="konx-btn konx-btn-primary">
					<?php esc_html_e( 'Submit Withdrawal Request', 'konx-affiliate-dashboard' ); ?>
				</button>
			</form>
		<?php endif; ?>

		<?php if ( ! empty( $data['withdrawal_history']['entries'] ) ) : ?>
			<h4><?php esc_html_e( 'Withdrawal History', 'konx-affiliate-dashboard' ); ?></h4>
			<table class="konx-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Processed', 'konx-affiliate-dashboard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['withdrawal_history']['entries'] as $wd ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $wd->requested_at ) ) ); ?></td>
							<td>$<?php echo esc_html( $wd->amount ); ?></td>
							<td><?php echo wp_kses( Konx_Dashboard::format_status( $wd->status ), array( 'span' => array( 'style' => array() ) ) ); ?></td>
							<td><?php echo $wd->processed_at ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $wd->processed_at ) ) ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- Admin Fee Status -->
	<?php if ( $fee['unpaid_count'] > 0 || $fee['overdue_count'] > 0 ) : ?>
		<div class="konx-section konx-fees">
			<h3><?php esc_html_e( 'Admin Fee Status', 'konx-affiliate-dashboard' ); ?></h3>
			<table class="konx-info-table">
				<tr>
					<td><?php esc_html_e( 'Unpaid Fees', 'konx-affiliate-dashboard' ); ?></td>
					<td><?php echo esc_html( $fee['unpaid_count'] ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Overdue Fees', 'konx-affiliate-dashboard' ); ?></td>
					<td><?php echo esc_html( $fee['overdue_count'] ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Total Outstanding', 'konx-affiliate-dashboard' ); ?></td>
					<td><strong>$<?php echo esc_html( $fee['total_outstanding'] ); ?></strong></td>
				</tr>
			</table>
			<p class="konx-muted"><?php esc_html_e( 'Please contact the administrator to resolve outstanding fees.', 'konx-affiliate-dashboard' ); ?></p>
		</div>
	<?php endif; ?>

</div>
