<?php
/**
 * Affiliate registration form view.
 *
 * Variables available:
 *   $feedback — Feedback array { type, message } or false.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_logged_in = is_user_logged_in();
$current_user = $is_logged_in ? wp_get_current_user() : null;
$types        = Konx_Registration::get_registration_types();

// Check for referral code in URL or cookie.
$ref_code = '';
if ( ! empty( $_GET['ref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$ref_code = strtoupper( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
} elseif ( ! empty( $_COOKIE['konx_ref'] ) ) {
	$ref_code = strtoupper( sanitize_text_field( $_COOKIE['konx_ref'] ) );
}
?>

<div class="konx-registration">

	<?php if ( $feedback ) : ?>
		<div class="konx-reg-notice konx-reg-notice-<?php echo esc_attr( $feedback['type'] ); ?>">
			<?php echo esc_html( $feedback['message'] ); ?>
		</div>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Join the KonX Affiliate Program', 'konx-affiliate-dashboard' ); ?></h3>

	<?php if ( $is_logged_in ) : ?>
		<p>
			<?php
			printf(
				/* translators: %s: user display name */
				esc_html__( 'Logged in as %s. Fill out the form below to apply as an affiliate.', 'konx-affiliate-dashboard' ),
				esc_html( $current_user->display_name )
			);
			?>
		</p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="konx-reg-form">
		<input type="hidden" name="action" value="konx_affiliate_register">
		<?php wp_nonce_field( 'konx_affiliate_register', 'konx_reg_nonce' ); ?>

		<?php if ( ! empty( $ref_code ) ) : ?>
			<input type="hidden" name="ref_code" value="<?php echo esc_attr( $ref_code ); ?>">
		<?php endif; ?>

		<?php if ( ! $is_logged_in ) : ?>
			<div class="konx-reg-row">
				<label for="konx-reg-first"><?php esc_html_e( 'First Name', 'konx-affiliate-dashboard' ); ?> <span class="required">*</span></label>
				<input type="text" id="konx-reg-first" name="first_name" required>
			</div>

			<div class="konx-reg-row">
				<label for="konx-reg-last"><?php esc_html_e( 'Last Name', 'konx-affiliate-dashboard' ); ?> <span class="required">*</span></label>
				<input type="text" id="konx-reg-last" name="last_name" required>
			</div>

			<div class="konx-reg-row">
				<label for="konx-reg-email"><?php esc_html_e( 'Email Address', 'konx-affiliate-dashboard' ); ?> <span class="required">*</span></label>
				<input type="email" id="konx-reg-email" name="email" required>
			</div>

			<div class="konx-reg-row">
				<label for="konx-reg-password"><?php esc_html_e( 'Password', 'konx-affiliate-dashboard' ); ?> <span class="required">*</span></label>
				<input type="password" id="konx-reg-password" name="password" minlength="8" required>
				<small><?php esc_html_e( 'Minimum 8 characters.', 'konx-affiliate-dashboard' ); ?></small>
			</div>
		<?php else : ?>
			<input type="hidden" name="first_name" value="<?php echo esc_attr( $current_user->first_name ); ?>">
			<input type="hidden" name="last_name" value="<?php echo esc_attr( $current_user->last_name ); ?>">
			<input type="hidden" name="email" value="<?php echo esc_attr( $current_user->user_email ); ?>">
		<?php endif; ?>

		<div class="konx-reg-row">
			<label for="konx-reg-type"><?php esc_html_e( 'Affiliate Type', 'konx-affiliate-dashboard' ); ?> <span class="required">*</span></label>
			<select id="konx-reg-type" name="affiliate_type" required>
				<?php foreach ( $types as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>">
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<small><?php esc_html_e( 'Referral Affiliates are activated immediately. Business Affiliates require a Starter Pack ($100), Pro Pack ($200), or eCard Pack ($500) purchase before activation.', 'konx-affiliate-dashboard' ); ?></small>
		</div>

		<div class="konx-reg-row">
			<label for="konx-reg-wise"><?php esc_html_e( 'Wise Email (optional)', 'konx-affiliate-dashboard' ); ?></label>
			<input type="email" id="konx-reg-wise" name="wise_email">
			<small><?php esc_html_e( 'For receiving commission payouts. Can be added later.', 'konx-affiliate-dashboard' ); ?></small>
		</div>

		<div class="konx-reg-row konx-reg-terms">
			<label>
				<input type="checkbox" name="terms" value="1" required>
				<?php esc_html_e( 'I agree to the terms and conditions of the affiliate program.', 'konx-affiliate-dashboard' ); ?>
				<span class="required">*</span>
			</label>
		</div>

		<button type="submit" class="konx-reg-btn">
			<?php esc_html_e( 'Register as Affiliate', 'konx-affiliate-dashboard' ); ?>
		</button>
	</form>
</div>
