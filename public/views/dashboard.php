<?php
/**
 * Affiliate dashboard view template.
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
		<span class="konx-topbar-welcome">
			<?php printf( esc_html__( 'Welcome back, %s', 'konx-affiliate-dashboard' ), '<strong>' . esc_html( wp_get_current_user()->display_name ) . '</strong>' ); ?>
		</span>
		<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="konx-btn konx-btn-sm konx-btn-outline">
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
				esc_html__( 'You have $%s in outstanding admin fees. Commissions are paused until fees are paid. Please contact the administrator.', 'konx-affiliate-dashboard' ),
				esc_html( $fee['total_outstanding'] )
			);
			?>
		</div>
	<?php endif; ?>

	<!-- Hero Section -->
	<div class="konx-hero">
		<div class="konx-hero-info">
			<h2><?php printf( esc_html__( 'Hello, %s', 'konx-affiliate-dashboard' ), esc_html( wp_get_current_user()->first_name ?: wp_get_current_user()->display_name ) ); ?></h2>
			<div class="konx-hero-meta">
				<span class="konx-badge konx-badge-<?php echo esc_attr( $aff->status ); ?>"><?php echo esc_html( ucfirst( $aff->status ) ); ?></span>
				<span><?php echo esc_html( Konx_Dashboard::format_type( $aff->affiliate_type ) ); ?></span>
			</div>
			<div class="konx-hero-actions">
				<button type="button" class="konx-btn konx-btn-sm" onclick="konxCopyText('<?php echo esc_js( $data['referral_url'] ); ?>', this)">
					<?php esc_html_e( 'Copy Referral Link', 'konx-affiliate-dashboard' ); ?>
				</button>
			</div>
		</div>
		<div class="konx-hero-balance">
			<div class="konx-hero-balance-label"><?php esc_html_e( 'Available Balance', 'konx-affiliate-dashboard' ); ?></div>
			<div class="konx-hero-balance-value">$<?php echo esc_html( $balance['available_balance'] ); ?></div>
		</div>
	</div>

	<!-- User Journey / Getting Started -->
	<?php if ( ! empty( $data['journey'] ) && $data['journey']['percent'] < 100 ) : ?>
		<?php $journey = $data['journey']; ?>
		<div class="konx-section">
			<h3><?php esc_html_e( 'Getting Started', 'konx-affiliate-dashboard' ); ?> — <?php echo esc_html( $journey['percent'] ); ?>% <?php esc_html_e( 'Complete', 'konx-affiliate-dashboard' ); ?></h3>
			<div class="konx-progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr( $journey['percent'] ); ?>" aria-valuemin="0" aria-valuemax="100">
				<div class="konx-progress-fill" style="width:<?php echo esc_attr( $journey['percent'] ); ?>%;"></div>
			</div>
			<div class="konx-journey-grid">
				<?php foreach ( $journey['steps'] as $step ) : ?>
					<div class="konx-journey-step <?php echo $step['done'] ? 'done' : ''; ?>">
						<span class="konx-journey-check"><?php echo $step['done'] ? '&#10003;' : '&#9675;'; ?></span>
						<div>
							<strong><?php echo esc_html( $step['label'] ); ?></strong>
							<?php if ( ! $step['done'] ) : ?>
								<small><?php echo esc_html( $step['hint'] ); ?></small>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( $journey['next_action'] ) : ?>
				<p class="konx-muted" style="margin-top:12px;">
					<?php printf( esc_html__( 'Next step: %s', 'konx-affiliate-dashboard' ), '<strong>' . esc_html( $journey['next_action']['hint'] ) . '</strong>' ); ?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<!-- Performance Stats -->
	<div class="konx-stats-grid">
		<div class="konx-stat">
			<span class="konx-stat-value">$<?php echo esc_html( $balance['lifetime_earnings'] ); ?></span>
			<span class="konx-stat-label"><?php esc_html_e( 'Total Earnings', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'lifetime_earnings' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		</div>
		<div class="konx-stat">
			<span class="konx-stat-value">$<?php echo esc_html( $balance['available_balance'] ); ?></span>
			<span class="konx-stat-label"><?php esc_html_e( 'Available', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'available_balance' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		</div>
		<div class="konx-stat">
			<span class="konx-stat-value">$<?php echo esc_html( $balance['total_withdrawals'] ); ?></span>
			<span class="konx-stat-label"><?php esc_html_e( 'Withdrawn', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'total_withdrawn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		</div>
		<div class="konx-stat">
			<span class="konx-stat-value"><?php echo esc_html( $milestone['completed_sales'] ); ?></span>
			<span class="konx-stat-label"><?php esc_html_e( 'Total Sales', 'konx-affiliate-dashboard' ); ?></span>
		</div>
		<div class="konx-stat">
			<span class="konx-stat-value"><?php echo esc_html( $milestone['milestones_achieved'] ); ?></span>
			<span class="konx-stat-label"><?php esc_html_e( 'Milestones', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'milestone_bonus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		</div>
		<div class="konx-stat">
			<span class="konx-stat-value">$<?php echo esc_html( $data['estimated_bonus'] ); ?></span>
			<span class="konx-stat-label"><?php esc_html_e( 'Est. Next Bonus', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'milestone_bonus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		</div>
	</div>

	<!-- Milestone Progress -->
	<div class="konx-section">
		<h3><?php esc_html_e( 'Milestone Progress', 'konx-affiliate-dashboard' ); ?></h3>
		<div class="konx-progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr( $milestone['percent_complete'] ); ?>" aria-valuemin="0" aria-valuemax="100">
			<div class="konx-progress-fill" style="width:<?php echo esc_attr( min( 100, $milestone['percent_complete'] ) ); ?>%;"></div>
		</div>
		<p class="konx-progress-text">
			<strong><?php echo esc_html( $milestone['sales_in_block'] ); ?></strong> / <?php echo esc_html( Konx_Milestone_Bonus::BLOCK_SIZE ); ?>
			<?php esc_html_e( 'sales toward milestone at', 'konx-affiliate-dashboard' ); ?> <strong><?php echo esc_html( $milestone['next_milestone_at'] ); ?></strong>
		</p>
		<?php if ( ! empty( $data['bonuses']['entries'] ) ) : ?>
			<h4><?php esc_html_e( 'Bonus History', 'konx-affiliate-dashboard' ); ?></h4>
			<div class="konx-table-wrap">
				<table class="konx-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Milestone', 'konx-affiliate-dashboard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Block', 'konx-affiliate-dashboard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Bonus', 'konx-affiliate-dashboard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Date', 'konx-affiliate-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $data['bonuses']['entries'] as $bonus ) : ?>
							<tr>
								<td>#<?php echo esc_html( $bonus->milestone_number ); ?></td>
								<td><?php echo esc_html( $bonus->sale_block_start . '–' . $bonus->sale_block_end ); ?></td>
								<td><strong>$<?php echo esc_html( $bonus->bonus_amount ); ?></strong></td>
								<td><span class="konx-badge konx-badge-<?php echo esc_attr( $bonus->status ); ?>"><?php echo esc_html( ucfirst( $bonus->status ) ); ?></span></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $bonus->created_at ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<!-- Referral Tools -->
	<div class="konx-section">
		<h3><?php esc_html_e( 'Referral Tools', 'konx-affiliate-dashboard' ); ?></h3>
		<div class="konx-referral-tools">
			<div class="konx-referral-box">
				<label><?php esc_html_e( 'Referral Code', 'konx-affiliate-dashboard' ); ?></label>
				<div class="konx-referral-copy">
					<input type="text" id="konx-ref-code" value="<?php echo esc_attr( $aff->referral_code ); ?>" readonly>
					<button type="button" class="konx-btn konx-btn-sm konx-btn-outline" onclick="konxCopyText('<?php echo esc_js( $aff->referral_code ); ?>', this)">
						<?php esc_html_e( 'Copy', 'konx-affiliate-dashboard' ); ?>
					</button>
				</div>
			</div>
			<div class="konx-referral-box">
				<label><?php esc_html_e( 'Referral Link', 'konx-affiliate-dashboard' ); ?></label>
				<div class="konx-referral-copy">
					<input type="text" id="konx-ref-url" value="<?php echo esc_url( $data['referral_url'] ); ?>" readonly>
					<button type="button" class="konx-btn konx-btn-sm konx-btn-outline" onclick="konxCopyText('<?php echo esc_js( $data['referral_url'] ); ?>', this)">
						<?php esc_html_e( 'Copy', 'konx-affiliate-dashboard' ); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- Share & QR -->
		<div style="margin-top:14px;">
			<label style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:var(--konx-text-light,#646970);font-weight:600;display:block;margin-bottom:8px;"><?php esc_html_e( 'Share Your Link', 'konx-affiliate-dashboard' ); ?></label>
			<div style="display:flex;gap:8px;flex-wrap:wrap;">
				<?php
				$share_url   = urlencode( $data['referral_url'] );
				$share_text  = urlencode( __( 'Check out KonX! Use my referral link:', 'konx-affiliate-dashboard' ) );
				$share_email = rawurlencode( __( 'Join KonX', 'konx-affiliate-dashboard' ) );
				?>
				<a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $share_url; ?>" target="_blank" rel="noopener" class="konx-btn konx-btn-sm konx-btn-outline" title="Facebook"><?php esc_html_e( 'Facebook', 'konx-affiliate-dashboard' ); ?></a>
				<a href="https://twitter.com/intent/tweet?text=<?php echo $share_text; ?>&url=<?php echo $share_url; ?>" target="_blank" rel="noopener" class="konx-btn konx-btn-sm konx-btn-outline" title="X"><?php esc_html_e( 'X / Twitter', 'konx-affiliate-dashboard' ); ?></a>
				<a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo $share_url; ?>" target="_blank" rel="noopener" class="konx-btn konx-btn-sm konx-btn-outline" title="LinkedIn"><?php esc_html_e( 'LinkedIn', 'konx-affiliate-dashboard' ); ?></a>
				<a href="mailto:?subject=<?php echo $share_email; ?>&body=<?php echo $share_text; ?>%20<?php echo $share_url; ?>" class="konx-btn konx-btn-sm konx-btn-outline" title="Email"><?php esc_html_e( 'Email', 'konx-affiliate-dashboard' ); ?></a>
			</div>
		</div>
	</div>

	<!-- Financial Activity Tabs -->
	<div class="konx-section">
		<h3><?php esc_html_e( 'Financial Activity', 'konx-affiliate-dashboard' ); ?></h3>

		<div class="konx-tabs" role="tablist">
			<button class="konx-tab active" role="tab" aria-selected="true" data-tab="commissions"><?php esc_html_e( 'Commissions', 'konx-affiliate-dashboard' ); ?></button>
			<button class="konx-tab" role="tab" aria-selected="false" data-tab="withdrawals"><?php esc_html_e( 'Withdrawals', 'konx-affiliate-dashboard' ); ?></button>
		</div>

		<!-- Commissions Tab -->
		<div class="konx-tab-content active" id="tab-commissions" role="tabpanel">
			<?php if ( empty( $data['commissions']['entries'] ) ) : ?>
				<p class="konx-muted"><?php esc_html_e( 'No commissions yet. Share your referral link to start earning!', 'konx-affiliate-dashboard' ); ?></p>
			<?php else : ?>
				<div class="konx-table-wrap">
					<table class="konx-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Date', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Type', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Product', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Price', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Rate', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Commission', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
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
									<td><strong>$<?php echo esc_html( $comm->commission_amount ); ?></strong></td>
									<td><span class="konx-badge konx-badge-<?php echo esc_attr( $comm->status ); ?>"><?php echo esc_html( ucfirst( $comm->status ) ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php if ( $data['commissions']['total'] > 10 ) : ?>
					<p class="konx-muted"><?php printf( esc_html__( 'Showing 10 of %d commissions.', 'konx-affiliate-dashboard' ), $data['commissions']['total'] ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<!-- Withdrawals Tab -->
		<div class="konx-tab-content" id="tab-withdrawals" role="tabpanel">
			<?php if ( $data['pending_withdrawal'] ) : ?>
				<div class="konx-notice konx-notice-info">
					<?php
					printf(
						esc_html__( 'You have a %s withdrawal request for $%s.', 'konx-affiliate-dashboard' ),
						'<strong>' . esc_html( $data['pending_withdrawal']->status ) . '</strong>',
						esc_html( $data['pending_withdrawal']->amount )
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
							max="<?php echo esc_attr( $balance['available_balance'] ); ?>" required>
						<small><?php printf( esc_html__( 'Available: $%1$s | Minimum: $%2$s', 'konx-affiliate-dashboard' ), esc_html( $balance['available_balance'] ), esc_html( $data['min_withdrawal'] ) ); ?></small>
					</div>

					<div class="konx-form-row">
						<label for="konx-wd-email"><?php esc_html_e( 'Wise Email', 'konx-affiliate-dashboard' ); ?></label>
						<input type="email" id="konx-wd-email" name="payment_email" value="<?php echo esc_attr( $aff->payment_email ); ?>" required>
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

					<button type="submit" class="konx-btn konx-btn-primary"><?php esc_html_e( 'Submit Withdrawal Request', 'konx-affiliate-dashboard' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( ! empty( $data['withdrawal_history']['entries'] ) ) : ?>
				<h4><?php esc_html_e( 'Withdrawal History', 'konx-affiliate-dashboard' ); ?></h4>
				<div class="konx-table-wrap">
					<table class="konx-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Date', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Amount', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Processed', 'konx-affiliate-dashboard' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $data['withdrawal_history']['entries'] as $wd ) : ?>
								<tr>
									<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $wd->requested_at ) ) ); ?></td>
									<td><strong>$<?php echo esc_html( $wd->amount ); ?></strong></td>
									<td><span class="konx-badge konx-badge-<?php echo esc_attr( $wd->status ); ?>"><?php echo esc_html( ucfirst( $wd->status ) ); ?></span></td>
									<td><?php echo $wd->processed_at ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $wd->processed_at ) ) ) : '—'; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<!-- Admin Fee Status -->
	<?php if ( $fee['unpaid_count'] > 0 || $fee['overdue_count'] > 0 ) : ?>
		<div class="konx-section">
			<h3><?php esc_html_e( 'Admin Fee Status', 'konx-affiliate-dashboard' ); ?></h3>
			<div class="konx-stats-grid" style="grid-template-columns: repeat(3, 1fr);">
				<div class="konx-stat">
					<span class="konx-stat-value"><?php echo esc_html( $fee['unpaid_count'] ); ?></span>
					<span class="konx-stat-label"><?php esc_html_e( 'Unpaid', 'konx-affiliate-dashboard' ); ?></span>
				</div>
				<div class="konx-stat">
					<span class="konx-stat-value"><?php echo esc_html( $fee['overdue_count'] ); ?></span>
					<span class="konx-stat-label"><?php esc_html_e( 'Overdue', 'konx-affiliate-dashboard' ); ?></span>
				</div>
				<div class="konx-stat">
					<span class="konx-stat-value">$<?php echo esc_html( $fee['total_outstanding'] ); ?></span>
					<span class="konx-stat-label"><?php esc_html_e( 'Outstanding', 'konx-affiliate-dashboard' ); ?></span>
				</div>
			</div>
			<p class="konx-muted"><?php esc_html_e( 'Please contact the administrator to resolve outstanding fees.', 'konx-affiliate-dashboard' ); ?></p>
		</div>
	<?php endif; ?>

	<!-- Commission Rate Card -->
	<div class="konx-section">
		<h3><?php esc_html_e( 'Your Commission Rates', 'konx-affiliate-dashboard' ); ?></h3>
		<p class="konx-muted" style="margin-bottom:12px;"><?php esc_html_e( 'These are the rates you earn based on your affiliate type.', 'konx-affiliate-dashboard' ); ?></p>
		<?php
		global $wpdb;
		$rules_table = $wpdb->prefix . 'konx_commission_rules';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$my_rules = $wpdb->get_results( $wpdb->prepare(
			"SELECT product_type, commission_type, rate FROM {$rules_table} WHERE affiliate_type = %s AND is_active = 1 ORDER BY commission_type, product_type",
			$aff->affiliate_type
		) );
		?>
		<?php if ( ! empty( $my_rules ) ) : ?>
			<div class="konx-table-wrap">
				<table class="konx-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Product', 'konx-affiliate-dashboard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'konx-affiliate-dashboard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Your Rate', 'konx-affiliate-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $my_rules as $rule ) : ?>
							<tr>
								<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $rule->product_type ) ) ); ?></td>
								<td><?php echo esc_html( 'recurring' === $rule->commission_type ? __( 'Recurring', 'konx-affiliate-dashboard' ) : __( 'One-Time', 'konx-affiliate-dashboard' ) ); ?></td>
								<td><strong><?php echo esc_html( number_format( (float) $rule->rate * 100, 0 ) ); ?>%</strong></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<!-- Profile Settings -->
	<div class="konx-section">
		<h3><?php esc_html_e( 'Profile Settings', 'konx-affiliate-dashboard' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="konx_update_profile">
			<?php wp_nonce_field( 'konx_update_profile_' . $aff->id, 'konx_profile_nonce' ); ?>

			<div class="konx-form-row">
				<label for="konx-profile-email"><?php esc_html_e( 'Wise Payment Email', 'konx-affiliate-dashboard' ); ?></label>
				<input type="email" id="konx-profile-email" name="payment_email" value="<?php echo esc_attr( $aff->payment_email ); ?>" style="max-width:400px;">
				<small><?php esc_html_e( 'This is the email address where your Wise payouts will be sent.', 'konx-affiliate-dashboard' ); ?></small>
			</div>

			<button type="submit" class="konx-btn konx-btn-primary"><?php esc_html_e( 'Save Profile', 'konx-affiliate-dashboard' ); ?></button>
		</form>
	</div>

</div>

<!-- Tab Switching & Copy Script -->
<script>
(function(){
	// Tabs
	document.querySelectorAll('.konx-tab').forEach(function(tab){
		tab.addEventListener('click', function(){
			document.querySelectorAll('.konx-tab').forEach(function(t){ t.classList.remove('active'); t.setAttribute('aria-selected','false'); });
			document.querySelectorAll('.konx-tab-content').forEach(function(c){ c.classList.remove('active'); });
			tab.classList.add('active');
			tab.setAttribute('aria-selected','true');
			var target = document.getElementById('tab-' + tab.getAttribute('data-tab'));
			if(target) target.classList.add('active');
		});
	});
})();

// Copy helper
function konxCopyText(text, btn){
	navigator.clipboard.writeText(text).then(function(){
		var orig = btn.textContent;
		btn.textContent = '<?php echo esc_js( __( 'Copied!', 'konx-affiliate-dashboard' ) ); ?>';
		btn.style.color = '#00a32a';
		setTimeout(function(){ btn.textContent = orig; btn.style.color = ''; }, 2000);
	});
}
</script>
