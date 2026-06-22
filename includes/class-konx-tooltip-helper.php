<?php
/**
 * Tooltip helper for inline help text.
 *
 * Provides a lightweight, accessible tooltip component using
 * CSS-only positioning with a JS fallback for touch devices.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Tooltip_Helper
 */
class Konx_Tooltip_Helper {

	/**
	 * Render a tooltip icon with text.
	 *
	 * @param string $text The tooltip text.
	 * @return string HTML for the tooltip.
	 */
	public static function render( $text ) {
		return sprintf(
			'<span class="konx-tooltip" tabindex="0" role="button" aria-label="%s">'
			. '<span class="konx-tooltip-icon">?</span>'
			. '<span class="konx-tooltip-text">%s</span>'
			. '</span>',
			esc_attr( $text ),
			esc_html( $text )
		);
	}

	/**
	 * Get tooltip definitions for common terms.
	 *
	 * @return array key => tooltip text.
	 */
	public static function get_definitions() {
		return array(
			// Affiliate dashboard.
			'available_balance'     => __( 'The amount you can withdraw right now. This is your total earnings minus withdrawals and reversals.', 'konx-affiliate-dashboard' ),
			'lifetime_earnings'     => __( 'The total amount of commissions and bonuses ever credited to your account.', 'konx-affiliate-dashboard' ),
			'total_withdrawn'       => __( 'The total amount you have withdrawn via Wise.', 'konx-affiliate-dashboard' ),
			'milestone_bonus'       => __( 'Every 100 completed sales, you earn a bonus equal to the total commissions from that 100-sale block.', 'konx-affiliate-dashboard' ),
			'referral_link'         => __( 'Share this unique link. When someone clicks it and makes a purchase, you earn a commission.', 'konx-affiliate-dashboard' ),

			// Admin settings.
			'commission_rate'       => __( 'The percentage of the product price that the affiliate earns as commission. Calculated from the full price before discounts and taxes.', 'konx-affiliate-dashboard' ),
			'recurring_commission'  => __( 'A flat commission rate earned on subscription renewal payments (conference rooms, eCard renewals). Applies to all affiliate types equally.', 'konx-affiliate-dashboard' ),
			'admin_fee'             => __( 'A monthly fee that affiliates must pay to keep earning commissions. If unpaid, new commissions are held until the fee is resolved.', 'konx-affiliate-dashboard' ),
			'min_withdrawal'        => __( 'The minimum amount an affiliate must have in their wallet before they can request a withdrawal.', 'konx-affiliate-dashboard' ),
			'cookie_duration'       => __( 'How many days the system remembers who referred a visitor. If the visitor purchases within this window, the affiliate gets credit.', 'konx-affiliate-dashboard' ),
			'ref_param'             => __( 'The URL parameter name used in referral links. Default is "ref" which creates links like yoursite.com/?ref=CODE. Most users should not change this.', 'konx-affiliate-dashboard' ),
			'dedup_window'          => __( 'If the same person clicks an affiliate link multiple times within this window, only the first click is counted. Prevents inflated click statistics.', 'konx-affiliate-dashboard' ),

			// Admin pages.
			'product_mapping'       => __( 'Connect your WooCommerce products to commission categories. Without mapping, the system does not know which commission rate to apply.', 'konx-affiliate-dashboard' ),
			'blocked_commission'    => __( 'A commission that was earned but not credited because the affiliate has unpaid admin fees. It will be released automatically when fees are paid.', 'konx-affiliate-dashboard' ),
			'withdrawal_status'     => __( 'Pending: awaiting your review. Approved: you have approved, payment in progress. Completed: paid via Wise. Rejected: declined with reason shown to affiliate.', 'konx-affiliate-dashboard' ),
			'pending_withdrawals'   => __( 'Withdrawal requests waiting for your review. Click to see the list and take action.', 'konx-affiliate-dashboard' ),
			'pack_commissions'      => __( 'Total commissions earned from one-time pack purchases (Starter, Pro, eCard Pack).', 'konx-affiliate-dashboard' ),
			'sub_commissions'       => __( 'Total commissions earned from subscription renewal payments (conference rooms, eCard renewals).', 'konx-affiliate-dashboard' ),
			'unpaid_balances'       => __( 'The total amount owed to all affiliates. This is money they have earned but not yet withdrawn.', 'konx-affiliate-dashboard' ),
			'balance_adjustment'    => __( 'Manually add or subtract funds from an affiliate wallet. Use for bonuses, corrections, or dispute resolution. All adjustments are logged.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Get a specific tooltip by key.
	 *
	 * @param string $key The tooltip key.
	 * @return string The tooltip HTML, or empty string if not found.
	 */
	public static function get( $key ) {
		$defs = self::get_definitions();
		if ( isset( $defs[ $key ] ) ) {
			return self::render( $defs[ $key ] );
		}
		return '';
	}
}
