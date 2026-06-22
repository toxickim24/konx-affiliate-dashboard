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
			'available_balance'     => __( 'The amount you can withdraw right now. This is your total earnings minus withdrawals and reversals.', 'konx-affiliate-dashboard' ),
			'lifetime_earnings'     => __( 'The total amount of commissions and bonuses ever credited to your account.', 'konx-affiliate-dashboard' ),
			'total_withdrawn'       => __( 'The total amount you have withdrawn via Wise.', 'konx-affiliate-dashboard' ),
			'milestone_bonus'       => __( 'Every 100 completed sales, you earn a bonus equal to the total commissions from that 100-sale block.', 'konx-affiliate-dashboard' ),
			'referral_link'         => __( 'Share this unique link. When someone clicks it and makes a purchase, you earn a commission.', 'konx-affiliate-dashboard' ),
			'commission_rate'       => __( 'The percentage of the product price that the affiliate earns as commission.', 'konx-affiliate-dashboard' ),
			'admin_fee'             => __( 'A monthly fee that must be paid to keep commission earnings active. Unpaid fees pause commissions.', 'konx-affiliate-dashboard' ),
			'product_mapping'       => __( 'Links a WooCommerce product to a commission category so the system knows which rate to apply.', 'konx-affiliate-dashboard' ),
			'blocked_commission'    => __( 'A commission that was earned but not credited because the affiliate has unpaid admin fees.', 'konx-affiliate-dashboard' ),
			'recurring_commission'  => __( 'A 10% commission earned on subscription renewal payments (conference rooms, eCard renewals).', 'konx-affiliate-dashboard' ),
			'withdrawal_status'     => __( 'Pending: awaiting review. Approved: payment in progress. Completed: paid via Wise. Rejected: declined with reason.', 'konx-affiliate-dashboard' ),
			'sale_sequence'         => __( 'A sequential number assigned to each commission. Used to calculate 100-sale milestone blocks.', 'konx-affiliate-dashboard' ),
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
