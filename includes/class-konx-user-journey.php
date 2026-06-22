<?php
/**
 * Affiliate user journey tracker.
 *
 * Dynamically detects which onboarding steps an affiliate has
 * completed and provides progress data for the dashboard.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_User_Journey
 */
class Konx_User_Journey {

	/**
	 * Get the full journey status for an affiliate.
	 *
	 * @param int $affiliate_id Affiliate table ID.
	 * @return array {
	 *     @type array $steps           Array of step arrays.
	 *     @type int   $completed       Number of completed steps.
	 *     @type int   $total           Total steps.
	 *     @type float $percent         Completion percentage.
	 *     @type array $next_action     The next recommended action.
	 * }
	 */
	public static function get_journey( $affiliate_id ) {
		$affiliate_id = absint( $affiliate_id );
		$affiliate    = Konx_Affiliate_Manager::get_affiliate( $affiliate_id );

		if ( ! $affiliate ) {
			return array( 'steps' => array(), 'completed' => 0, 'total' => 0, 'percent' => 0, 'next_action' => null );
		}

		$steps = self::build_steps( $affiliate );

		$completed = 0;
		foreach ( $steps as $step ) {
			if ( $step['done'] ) {
				$completed++;
			}
		}

		$total   = count( $steps );
		$percent = $total > 0 ? round( ( $completed / $total ) * 100, 0 ) : 0;

		// Find next action.
		$next_action = null;
		foreach ( $steps as $step ) {
			if ( ! $step['done'] ) {
				$next_action = $step;
				break;
			}
		}

		return array(
			'steps'       => $steps,
			'completed'   => $completed,
			'total'       => $total,
			'percent'     => $percent,
			'next_action' => $next_action,
		);
	}

	/**
	 * Build the journey steps with dynamic completion detection.
	 *
	 * @param object $affiliate The affiliate row.
	 * @return array Array of step arrays.
	 */
	private static function build_steps( $affiliate ) {
		global $wpdb;

		$aff_id  = (int) $affiliate->id;
		$clicks  = $wpdb->prefix . 'konx_referral_clicks';
		$comms   = $wpdb->prefix . 'konx_commissions';
		$wds     = $wpdb->prefix . 'konx_withdrawals';
		$miles   = $wpdb->prefix . 'konx_milestones';
		$fees    = $wpdb->prefix . 'konx_admin_fees';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$has_click      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$clicks} WHERE affiliate_id = %d", $aff_id ) ) > 0;
		$has_commission = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$comms} WHERE affiliate_id = %d AND status = 'approved'", $aff_id ) ) > 0;
		$has_withdrawal = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wds} WHERE affiliate_id = %d", $aff_id ) ) > 0;
		$has_milestone  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$miles} WHERE affiliate_id = %d", $aff_id ) ) > 0;
		$fee_paid       = Konx_Admin_Fees::can_affiliate_earn( $aff_id );

		$sale_count = (int) $affiliate->completed_sales;

		// phpcs:enable

		return array(
			array(
				'key'    => 'registered',
				'label'  => __( 'Account Registered', 'konx-affiliate-dashboard' ),
				'done'   => true, // Always true if we have an affiliate record.
				'hint'   => __( 'Your affiliate account has been created.', 'konx-affiliate-dashboard' ),
			),
			array(
				'key'    => 'activated',
				'label'  => __( 'Affiliate Activated', 'konx-affiliate-dashboard' ),
				'done'   => 'active' === $affiliate->status,
				'hint'   => __( 'Your account must be activated by an administrator.', 'konx-affiliate-dashboard' ),
			),
			array(
				'key'    => 'fee_paid',
				'label'  => __( 'Admin Fee Paid', 'konx-affiliate-dashboard' ),
				'done'   => $fee_paid,
				'hint'   => __( 'Pay your admin fee to enable commissions.', 'konx-affiliate-dashboard' ),
			),
			array(
				'key'    => 'referral_ready',
				'label'  => __( 'Referral Link Ready', 'konx-affiliate-dashboard' ),
				'done'   => ! empty( $affiliate->referral_code ),
				'hint'   => __( 'Share your referral link to start earning.', 'konx-affiliate-dashboard' ),
			),
			array(
				'key'    => 'first_click',
				'label'  => __( 'First Referral Click', 'konx-affiliate-dashboard' ),
				'done'   => $has_click,
				'hint'   => __( 'Share your referral link on social media or with friends.', 'konx-affiliate-dashboard' ),
			),
			array(
				'key'    => 'first_sale',
				'label'  => __( 'First Sale', 'konx-affiliate-dashboard' ),
				'done'   => $sale_count >= 1,
				'hint'   => __( 'When someone purchases through your link, you earn a commission.', 'konx-affiliate-dashboard' ),
			),
			array(
				'key'    => 'first_commission',
				'label'  => __( 'First Commission Earned', 'konx-affiliate-dashboard' ),
				'done'   => $has_commission,
				'hint'   => __( 'Your first commission will be credited to your wallet.', 'konx-affiliate-dashboard' ),
			),
			array(
				'key'    => 'first_withdrawal',
				'label'  => __( 'First Withdrawal Request', 'konx-affiliate-dashboard' ),
				'done'   => $has_withdrawal,
				'hint'   => __( 'Request a withdrawal when your balance reaches the minimum.', 'konx-affiliate-dashboard' ),
			),
			array(
				'key'    => 'milestone_100',
				'label'  => __( '100 Sales Milestone', 'konx-affiliate-dashboard' ),
				'done'   => $sale_count >= 100,
				'hint'   => __( 'Reach 100 sales to earn your first milestone bonus!', 'konx-affiliate-dashboard' ),
			),
			array(
				'key'    => 'milestone_bonus',
				'label'  => __( 'Milestone Bonus Earned', 'konx-affiliate-dashboard' ),
				'done'   => $has_milestone,
				'hint'   => __( 'Your milestone bonus equals the total commissions from your 100-sale block.', 'konx-affiliate-dashboard' ),
			),
		);
	}
}
