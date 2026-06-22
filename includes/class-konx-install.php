<?php
/**
 * Plugin activation and database installation.
 *
 * Creates all custom database tables via dbDelta(), seeds default
 * commission rules, generates the IP hash salt, and sets version options.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Install
 */
class Konx_Install {

	/**
	 * Run the full activation routine.
	 */
	public static function activate() {
		self::create_tables();
		Konx_Roles::add_roles();
		self::seed_commission_rules();
		self::generate_ip_hash_salt();

		update_option( 'konx_affiliate_version', KONX_AFFILIATE_VERSION );
		update_option( 'konx_affiliate_db_version', KONX_AFFILIATE_DB_VERSION );
	}

	/**
	 * Create or update all custom database tables.
	 *
	 * Safe to call on every activation and upgrade — dbDelta()
	 * only applies changes if the schema differs from what exists.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$tables = self::get_table_schemas( $charset_collate );

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Return the CREATE TABLE SQL for all 11 custom tables.
	 *
	 * @param string $charset_collate The charset/collate string from $wpdb.
	 * @return array Array of SQL CREATE TABLE statements.
	 */
	private static function get_table_schemas( $charset_collate ) {
		global $wpdb;

		$tables = array();

		// ---------------------------------------------------------------
		// Table 1: Affiliates
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_affiliates';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			affiliate_type varchar(20) NOT NULL DEFAULT 'referral',
			referral_code varchar(12) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			completed_sales int(10) unsigned NOT NULL DEFAULT 0,
			cached_balance decimal(12,2) NOT NULL DEFAULT 0.00,
			parent_affiliate_id bigint(20) unsigned DEFAULT NULL,
			payment_email varchar(255) DEFAULT NULL,
			notes text,
			registered_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_user_id (user_id),
			UNIQUE KEY uq_referral_code (referral_code),
			KEY idx_affiliate_type (affiliate_type),
			KEY idx_status (status),
			KEY idx_parent_affiliate (parent_affiliate_id)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// Table 2: Referral Clicks
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_referral_clicks';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			referral_code varchar(12) NOT NULL,
			ip_hash varchar(64) NOT NULL,
			user_agent varchar(500) DEFAULT NULL,
			landing_url varchar(2048) DEFAULT NULL,
			referrer_url varchar(2048) DEFAULT NULL,
			converted tinyint(1) NOT NULL DEFAULT 0,
			clicked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_affiliate_id (affiliate_id),
			KEY idx_clicked_at (clicked_at),
			KEY idx_affiliate_date (affiliate_id,clicked_at),
			KEY idx_ip_hash (ip_hash)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// Table 3: Referral Conversions
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_referral_conversions';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			customer_user_id bigint(20) unsigned DEFAULT NULL,
			referral_code varchar(12) NOT NULL,
			click_id bigint(20) unsigned DEFAULT NULL,
			order_total decimal(12,2) NOT NULL,
			is_subscription_renewal tinyint(1) NOT NULL DEFAULT 0,
			subscription_id bigint(20) unsigned DEFAULT NULL,
			converted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_order_id (order_id),
			KEY idx_affiliate_id (affiliate_id),
			KEY idx_customer_user_id (customer_user_id),
			KEY idx_subscription_id (subscription_id),
			KEY idx_converted_at (converted_at)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// Table 4: Commissions
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_commissions';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			conversion_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			order_item_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			product_type varchar(30) NOT NULL,
			affiliate_type_at_sale varchar(20) NOT NULL,
			product_price decimal(12,2) NOT NULL,
			commission_rate decimal(5,4) NOT NULL,
			commission_amount decimal(12,2) NOT NULL,
			commission_type varchar(20) NOT NULL,
			sale_sequence int(10) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			blocked_reason varchar(50) DEFAULT NULL,
			ledger_entry_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_order_item (order_id,order_item_id),
			UNIQUE KEY uq_affiliate_sequence (affiliate_id,sale_sequence),
			KEY idx_affiliate_id (affiliate_id),
			KEY idx_affiliate_status (affiliate_id,status),
			KEY idx_affiliate_sequence_range (affiliate_id,sale_sequence,status),
			KEY idx_conversion_id (conversion_id),
			KEY idx_order_id (order_id),
			KEY idx_status (status),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// Table 5: Wallet Ledger
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_wallet_ledger';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			entry_type varchar(30) NOT NULL,
			amount decimal(12,2) NOT NULL,
			running_balance decimal(12,2) NOT NULL,
			reference_type varchar(30) NOT NULL,
			reference_id bigint(20) unsigned DEFAULT NULL,
			description varchar(500) NOT NULL,
			created_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_affiliate_id (affiliate_id),
			KEY idx_affiliate_entry_type (affiliate_id,entry_type),
			KEY idx_reference (reference_type,reference_id),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// Table 6: Withdrawals
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_withdrawals';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			amount decimal(12,2) NOT NULL,
			payment_method varchar(50) NOT NULL DEFAULT 'wise',
			payment_email varchar(255) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			admin_user_id bigint(20) unsigned DEFAULT NULL,
			admin_note text,
			transaction_reference varchar(255) DEFAULT NULL,
			ledger_entry_id bigint(20) unsigned DEFAULT NULL,
			requested_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			processed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_affiliate_id (affiliate_id),
			KEY idx_affiliate_status (affiliate_id,status),
			KEY idx_status (status),
			KEY idx_requested_at (requested_at)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// Table 7: Admin Fees
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_admin_fees';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			fee_amount decimal(12,2) NOT NULL,
			fee_period varchar(20) NOT NULL,
			due_date date NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'unpaid',
			paid_date date DEFAULT NULL,
			paid_by_admin_id bigint(20) unsigned DEFAULT NULL,
			notes text,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_affiliate_period (affiliate_id,fee_period),
			KEY idx_affiliate_id (affiliate_id),
			KEY idx_status (status),
			KEY idx_due_date (due_date)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// Table 8: Milestones
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_milestones';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			milestone_number int(10) unsigned NOT NULL,
			sale_count_at_trigger int(10) unsigned NOT NULL,
			sale_block_start int(10) unsigned NOT NULL,
			sale_block_end int(10) unsigned NOT NULL,
			total_commissions_in_block decimal(12,2) NOT NULL,
			bonus_amount decimal(12,2) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'approved',
			ledger_entry_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_affiliate_milestone (affiliate_id,milestone_number),
			KEY idx_affiliate_id (affiliate_id),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// Table 9: Commission Rules
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_commission_rules';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_type varchar(20) NOT NULL,
			product_type varchar(30) NOT NULL,
			commission_type varchar(20) NOT NULL DEFAULT 'one_time',
			rate decimal(5,4) NOT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_rule (affiliate_type,product_type,commission_type),
			KEY idx_affiliate_type (affiliate_type),
			KEY idx_product_type (product_type)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// Table 10: Product Map
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_product_map';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			product_type varchar(30) NOT NULL,
			product_label varchar(100) NOT NULL,
			is_subscription tinyint(1) NOT NULL DEFAULT 0,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_product_id (product_id),
			KEY idx_product_type (product_type)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// Table 11: Audit Log
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_audit_log';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL,
			object_type varchar(30) NOT NULL,
			object_id bigint(20) unsigned DEFAULT NULL,
			actor_id bigint(20) unsigned DEFAULT NULL,
			old_value text,
			new_value text,
			description varchar(500) NOT NULL,
			ip_address varchar(45) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_event_type (event_type),
			KEY idx_object (object_type,object_id),
			KEY idx_actor_id (actor_id),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		return $tables;
	}

	/**
	 * Seed default commission rules if the table is empty.
	 *
	 * Only runs on first activation. If the admin has modified rules
	 * and then deactivates/reactivates, existing rules are preserved.
	 */
	private static function seed_commission_rules() {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_commission_rules';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > 0 ) {
			return;
		}

		$rules = array(
			// One-time: Business Affiliate.
			array( 'business', 'starter_pack', 'one_time', '0.4000' ),
			array( 'business', 'pro_pack', 'one_time', '0.4000' ),
			array( 'business', 'ecard_pack', 'one_time', '0.4000' ),

			// One-time: Referral Affiliate.
			array( 'referral', 'starter_pack', 'one_time', '0.2000' ),
			array( 'referral', 'pro_pack', 'one_time', '0.2000' ),
			array( 'referral', 'ecard_pack', 'one_time', '0.2000' ),

			// One-time: Team Agent.
			array( 'team_agent', 'starter_pack', 'one_time', '0.4000' ),
			array( 'team_agent', 'pro_pack', 'one_time', '0.4000' ),
			array( 'team_agent', 'ecard_pack', 'one_time', '0.4000' ),

			// One-time: Marketing Agent.
			array( 'marketing_agent', 'starter_pack', 'one_time', '0.4000' ),
			array( 'marketing_agent', 'pro_pack', 'one_time', '0.2000' ),
			array( 'marketing_agent', 'ecard_pack', 'one_time', '0.2000' ),

			// One-time: Sales Agent.
			array( 'sales_agent', 'starter_pack', 'one_time', '0.2000' ),
			array( 'sales_agent', 'pro_pack', 'one_time', '0.2000' ),
			array( 'sales_agent', 'ecard_pack', 'one_time', '0.2000' ),

			// Recurring: All types at 10%.
			array( 'business', 'subscription', 'recurring', '0.1000' ),
			array( 'referral', 'subscription', 'recurring', '0.1000' ),
			array( 'team_agent', 'subscription', 'recurring', '0.1000' ),
			array( 'marketing_agent', 'subscription', 'recurring', '0.1000' ),
			array( 'sales_agent', 'subscription', 'recurring', '0.1000' ),
		);

		foreach ( $rules as $rule ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				array(
					'affiliate_type'  => $rule[0],
					'product_type'    => $rule[1],
					'commission_type' => $rule[2],
					'rate'            => $rule[3],
				),
				array( '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Generate and store the IP hash salt if it does not already exist.
	 *
	 * The salt is used to hash visitor IPs in referral click tracking
	 * so that the raw IPv4 address space cannot be reversed via rainbow table.
	 */
	private static function generate_ip_hash_salt() {
		if ( get_option( 'konx_ip_hash_salt' ) ) {
			return;
		}

		$salt = wp_generate_password( 32, true, true );
		update_option( 'konx_ip_hash_salt', $salt, false );
	}

	/**
	 * Placeholder for future database upgrade routines.
	 *
	 * Called from the plugins_loaded hook when the stored DB version
	 * differs from KONX_AFFILIATE_DB_VERSION.
	 *
	 * @param string $installed_version The currently installed DB version.
	 */
	public static function maybe_upgrade( $installed_version ) {
		// Future upgrade routines will go here.
		// Example:
		// if ( version_compare( $installed_version, '1.1.0', '<' ) ) {
		//     self::upgrade_to_110();
		// }

		self::create_tables();
		update_option( 'konx_affiliate_db_version', KONX_AFFILIATE_DB_VERSION );
	}
}
