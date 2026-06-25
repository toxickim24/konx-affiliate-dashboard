<?php
/**
 * Custom roles and capabilities for KonX Affiliate Dashboard.
 *
 * Creates four affiliate roles (one per affiliate type) and assigns
 * custom capabilities. Admin capabilities are added to the administrator role.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Roles
 */
class Konx_Roles {

	/**
	 * Affiliate roles: slug => display name.
	 *
	 * @var array
	 */
	private static $affiliate_roles = array(
		'konx_business_affiliate' => 'KonX Business Affiliate',
		'konx_team_agent'         => 'KonX Team Agent',
		'konx_marketing_agent'    => 'KonX Marketing Agent',
		'konx_sales_agent'        => 'KonX Sales Agent',
	);

	/**
	 * Capabilities granted to all affiliate roles.
	 *
	 * @var array
	 */
	private static $affiliate_caps = array(
		'view_konx_dashboard',
		'request_withdrawal',
		'view_commissions',
		'view_wallet',
	);

	/**
	 * Capabilities granted only to administrators.
	 *
	 * @var array
	 */
	private static $admin_caps = array(
		'manage_konx_affiliates',
		'manage_konx_commissions',
		'manage_konx_withdrawals',
		'manage_konx_settings',
	);

	/**
	 * Register all custom roles and capabilities.
	 */
	public static function add_roles() {
		$subscriber  = get_role( 'subscriber' );
		$base_caps   = $subscriber ? $subscriber->capabilities : array( 'read' => true );

		foreach ( self::$affiliate_caps as $cap ) {
			$base_caps[ $cap ] = true;
		}

		foreach ( self::$affiliate_roles as $slug => $name ) {
			if ( ! get_role( $slug ) ) {
				add_role( $slug, $name, $base_caps );
			}
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::$admin_caps as $cap ) {
				$admin->add_cap( $cap );
			}
			foreach ( self::$affiliate_caps as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove all custom roles and capabilities.
	 *
	 * Called on plugin deactivation. Does not remove user data —
	 * users retain the role slug in wp_usermeta but it becomes
	 * unrecognized until the plugin is reactivated.
	 */
	public static function remove_roles() {
		foreach ( self::$affiliate_roles as $slug => $name ) {
			remove_role( $slug );
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::$admin_caps as $cap ) {
				$admin->remove_cap( $cap );
			}
			foreach ( self::$affiliate_caps as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	/**
	 * Get all affiliate role slugs.
	 *
	 * @return array Indexed array of role slug strings.
	 */
	public static function get_affiliate_role_slugs() {
		return array_keys( self::$affiliate_roles );
	}

	/**
	 * Get all affiliate capabilities.
	 *
	 * @return array Indexed array of capability strings.
	 */
	public static function get_affiliate_caps() {
		return self::$affiliate_caps;
	}

	/**
	 * Get all admin capabilities.
	 *
	 * @return array Indexed array of capability strings.
	 */
	public static function get_admin_caps() {
		return self::$admin_caps;
	}

	/**
	 * Map a role slug to an affiliate_type value for the database.
	 *
	 * @param string $role_slug WordPress role slug.
	 * @return string|false The affiliate_type value, or false if not a KonX role.
	 */
	public static function role_to_affiliate_type( $role_slug ) {
		$map = array(
			'konx_business_affiliate' => 'business',
			'konx_team_agent'         => 'team_agent',
			'konx_marketing_agent'    => 'marketing_agent',
			'konx_sales_agent'        => 'sales_agent',
		);

		return isset( $map[ $role_slug ] ) ? $map[ $role_slug ] : false;
	}

	/**
	 * Map an affiliate_type value to a role slug.
	 *
	 * @param string $affiliate_type The affiliate_type value from the database.
	 * @return string|false The role slug, or false if not recognized.
	 */
	public static function affiliate_type_to_role( $affiliate_type ) {
		$map = array(
			'business'        => 'konx_business_affiliate',
			'team_agent'      => 'konx_team_agent',
			'marketing_agent' => 'konx_marketing_agent',
			'sales_agent'     => 'konx_sales_agent',
		);

		return isset( $map[ $affiliate_type ] ) ? $map[ $affiliate_type ] : false;
	}
}
