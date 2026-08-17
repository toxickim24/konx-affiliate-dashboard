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
		Konx_Admin_Fees::schedule_cron();
		self::create_default_pages();

		update_option( 'konx_affiliate_version', KONX_AFFILIATE_VERSION );
		update_option( 'konx_affiliate_db_version', KONX_AFFILIATE_DB_VERSION );

		// Schedule rewrite flush for My Account endpoint.
		Konx_My_Account::schedule_flush();

		// Trigger setup wizard on first activation.
		if ( class_exists( 'Konx_Setup_Wizard' ) ) {
			Konx_Setup_Wizard::set_activation_redirect();
		}
	}

	/**
	 * Create default registration and dashboard pages on activation.
	 *
	 * Only creates pages if they don't already exist. Stores page IDs
	 * in options so other parts of the plugin can reference them.
	 */
	private static function create_default_pages() {
		global $wpdb;

		// Registration page.
		$reg_page_id = get_option( 'konx_registration_page_id' );
		if ( ! $reg_page_id || ! get_post( $reg_page_id ) ) {
			// Check if a page with the shortcode already exists.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing = $wpdb->get_var(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%[konx_affiliate_register]%' LIMIT 1"
			);

			if ( $existing ) {
				$reg_page_id = (int) $existing;
			} else {
				$reg_page_id = wp_insert_post( array(
					'post_title'   => __( 'Affiliate Registration', 'konx-affiliate-dashboard' ),
					'post_content' => '[konx_affiliate_register]',
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_author'  => 1,
				) );

				if ( $reg_page_id && ! is_wp_error( $reg_page_id ) ) {
					update_post_meta( $reg_page_id, '_wp_page_template', 'elementor_header_footer' );
				}
			}

			if ( $reg_page_id && ! is_wp_error( $reg_page_id ) ) {
				update_option( 'konx_registration_page_id', $reg_page_id );
			}
		}

		// Dashboard page.
		$dash_page_id = get_option( 'konx_dashboard_page_id' );
		if ( ! $dash_page_id || ! get_post( $dash_page_id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing = $wpdb->get_var(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%[konx_affiliate_dashboard]%' LIMIT 1"
			);

			if ( $existing ) {
				$dash_page_id = (int) $existing;
			} else {
				$dash_page_id = wp_insert_post( array(
					'post_title'   => __( 'Affiliate Dashboard', 'konx-affiliate-dashboard' ),
					'post_content' => '[konx_affiliate_dashboard]',
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_author'  => 1,
				) );

				if ( $dash_page_id && ! is_wp_error( $dash_page_id ) ) {
					update_post_meta( $dash_page_id, '_wp_page_template', 'elementor_header_footer' );
				}
			}

			if ( $dash_page_id && ! is_wp_error( $dash_page_id ) ) {
				update_option( 'konx_dashboard_page_id', $dash_page_id );
			}
		}
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
	 * Return the CREATE TABLE SQL for all 18 custom tables.
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
			affiliate_type varchar(20) NOT NULL DEFAULT 'sales_agent',
			referral_code varchar(50) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			completed_sales int(10) unsigned NOT NULL DEFAULT 0,
			cached_balance decimal(12,2) NOT NULL DEFAULT 0.00,
			parent_affiliate_id bigint(20) unsigned DEFAULT NULL,
			payment_email varchar(255) DEFAULT NULL,
			external_id varchar(50) DEFAULT NULL,
			phone varchar(30) DEFAULT NULL,
			notes text,
			registered_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_user_id (user_id),
			UNIQUE KEY uq_referral_code (referral_code),
			KEY idx_affiliate_type (affiliate_type),
			KEY idx_status (status),
			KEY idx_parent_affiliate (parent_affiliate_id),
			KEY idx_external_id (external_id)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// Table 2: Referral Clicks
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_referral_clicks';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			referral_code varchar(50) NOT NULL,
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
			referral_code varchar(50) NOT NULL,
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

		// ---------------------------------------------------------------
		// Table 12: API Keys
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_api_keys';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			key_name varchar(100) NOT NULL,
			key_hash varchar(64) NOT NULL,
			key_prefix varchar(8) NOT NULL,
			permissions varchar(50) NOT NULL DEFAULT 'read_write',
			created_by bigint(20) unsigned NOT NULL,
			last_used_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			revoked_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_key_hash (key_hash),
			KEY idx_key_prefix (key_prefix),
			KEY idx_created_by (created_by)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// Table 13: API Request Log
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_api_log';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			api_key_id bigint(20) unsigned DEFAULT NULL,
			endpoint varchar(100) NOT NULL,
			request_method varchar(10) NOT NULL,
			request_ip_hash varchar(64) DEFAULT NULL,
			response_code smallint(5) unsigned NOT NULL,
			error_message varchar(500) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_api_key_id (api_key_id),
			KEY idx_endpoint (endpoint),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// Table 14: Migration Sessions
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_migration_sessions';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(30) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'planning',
			csv_filename varchar(255) NOT NULL,
			csv_hash varchar(64) NOT NULL,
			total_records int(10) unsigned NOT NULL DEFAULT 0,
			processed int(10) unsigned NOT NULL DEFAULT 0,
			succeeded int(10) unsigned NOT NULL DEFAULT 0,
			failed int(10) unsigned NOT NULL DEFAULT 0,
			skipped int(10) unsigned NOT NULL DEFAULT 0,
			initiated_by bigint(20) unsigned NOT NULL,
			started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_session_id (session_id),
			KEY idx_status (status),
			KEY idx_csv_hash (csv_hash),
			KEY idx_initiated_by (initiated_by)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// Table 15: Migration Log
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_migration_log';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			migration_id varchar(30) NOT NULL,
			external_id varchar(50) DEFAULT NULL,
			email varchar(255) NOT NULL,
			planned_action varchar(20) NOT NULL,
			execution_state varchar(20) NOT NULL DEFAULT 'pending',
			created_user_id bigint(20) unsigned DEFAULT NULL,
			created_affiliate_id bigint(20) unsigned DEFAULT NULL,
			rollback_action varchar(50) DEFAULT NULL,
			error_message varchar(500) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_migration_id (migration_id),
			KEY idx_external_id (external_id),
			KEY idx_email (email),
			KEY idx_execution_state (execution_state),
			KEY idx_created_user_id (created_user_id),
			KEY idx_created_affiliate_id (created_affiliate_id)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// Table 16: Migration Execution Sessions (Phase 24C-6B)
		//
		// Immutable execution sessions. Each session is a frozen snapshot
		// of the Final Migration Plan at the moment of approval. Once a
		// session reaches 'frozen' status, its identity fields (plan hash,
		// record counts, plugin/schema version) must not be changed.
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_migration_exec_sessions';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_uuid varchar(50) NOT NULL,
			source_type varchar(20) NOT NULL DEFAULT 'csv',
			source_filename varchar(255) DEFAULT NULL,
			source_hash varchar(64) DEFAULT NULL,
			source_hash_algorithm varchar(10) NOT NULL DEFAULT 'sha256',
			final_plan_hash varchar(64) NOT NULL,
			final_plan_record_count int(10) unsigned NOT NULL DEFAULT 0,
			decision_create_count int(10) unsigned NOT NULL DEFAULT 0,
			decision_link_wp_count int(10) unsigned NOT NULL DEFAULT 0,
			decision_link_ca_count int(10) unsigned NOT NULL DEFAULT 0,
			decision_invalid_count int(10) unsigned NOT NULL DEFAULT 0,
			decision_review_count int(10) unsigned NOT NULL DEFAULT 0,
			plugin_version varchar(20) NOT NULL DEFAULT '',
			database_schema_version varchar(20) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'draft',
			created_by bigint(20) unsigned DEFAULT NULL,
			approved_by bigint(20) unsigned DEFAULT NULL,
			approved_at datetime DEFAULT NULL,
			executed_by bigint(20) unsigned DEFAULT NULL,
			rollback_by bigint(20) unsigned DEFAULT NULL,
			validation_timestamp datetime DEFAULT NULL,
			dry_run_timestamp datetime DEFAULT NULL,
			backup_reference varchar(100) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			last_revalidated_at datetime DEFAULT NULL,
			revalidation_status varchar(20) DEFAULT NULL,
			revalidation_stale_count int(10) unsigned NOT NULL DEFAULT 0,
			revalidation_conflict_count int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_session_uuid (session_uuid),
			KEY idx_status (status),
			KEY idx_final_plan_hash (final_plan_hash),
			KEY idx_created_by (created_by)
		) {$charset_collate} ENGINE=InnoDB;";

		// ---------------------------------------------------------------
		// Table 17: Migration Execution Plan (Phase 24C-6B)
		//
		// Per-record frozen snapshot of the Final Migration Plan.
		// Each row captures the approved action and all decision context
		// required for safe, deterministic execution. The executor reads
		// only from this table — it never re-runs matching or reconciliation.
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_migration_execution_plan';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			source_system varchar(20) NOT NULL DEFAULT 'powerof10',
			source_record_id int(10) unsigned NOT NULL,
			source_email varchar(255) NOT NULL DEFAULT '',
			action varchar(20) NOT NULL,
			wp_user_id bigint(20) unsigned DEFAULT NULL,
			coupon_affiliate_id int(10) unsigned DEFAULT NULL,
			affiliate_type varchar(20) NOT NULL DEFAULT 'sales_agent',
			team_name varchar(50) DEFAULT NULL,
			sponsor_team_name varchar(50) DEFAULT NULL,
			source_payload text DEFAULT NULL,
			decision_payload text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_session_source (session_id,source_record_id),
			KEY idx_session_id (session_id),
			KEY idx_source_record_id (source_record_id),
			KEY idx_action (action),
			KEY idx_source_email (source_email)
		) {$charset_collate} ENGINE=InnoDB;";

		// ---------------------------------------------------------------
		// Table 18: Migration Execution Ledger (Phase 24C-6B)
		//
		// Per-record execution state. Tracks the lifecycle of each
		// migration record through: pending → processing → completed /
		// failed / skipped / rolled_back.
		//
		// IDEMPOTENCY: UNIQUE KEY uq_session_source prevents duplicate
		// execution entries per record per session. Cross-session
		// idempotency (blocking re-migration of completed records) is
		// enforced at execution time by the revalidation engine, NOT by
		// a global UNIQUE constraint, to allow failed sessions to be retried.
		// ---------------------------------------------------------------
		$table = $wpdb->prefix . 'konx_migration_execution_ledger';
		$tables[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			plan_id bigint(20) unsigned NOT NULL,
			source_system varchar(20) NOT NULL DEFAULT 'powerof10',
			source_record_id int(10) unsigned NOT NULL,
			approved_action varchar(20) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempt_count int(10) unsigned NOT NULL DEFAULT 0,
			wp_user_id bigint(20) unsigned DEFAULT NULL,
			wp_user_created tinyint(1) NOT NULL DEFAULT 0,
			affiliate_id bigint(20) unsigned DEFAULT NULL,
			affiliate_created tinyint(1) NOT NULL DEFAULT 0,
			error_code varchar(50) DEFAULT NULL,
			error_message varchar(500) DEFAULT NULL,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			rollback_status varchar(20) DEFAULT NULL,
			rolled_back_at datetime DEFAULT NULL,
			rolled_back_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_session_source (session_id,source_record_id),
			KEY idx_session_id (session_id),
			KEY idx_plan_id (plan_id),
			KEY idx_status (status),
			KEY idx_source_record_id (source_record_id),
			KEY idx_cross_session (source_record_id,source_system,status)
		) {$charset_collate} ENGINE=InnoDB;";

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
	 * Run database upgrade routines for versions below current.
	 *
	 * Called from the plugins_loaded hook when the stored DB version
	 * differs from KONX_AFFILIATE_DB_VERSION.
	 *
	 * @param string $installed_version The currently installed DB version.
	 */
	public static function maybe_upgrade( $installed_version ) {

		if ( version_compare( $installed_version, '1.1.0', '<' ) ) {
			self::upgrade_to_110();
		}

		// 1.2.0: Migration sessions + log tables — handled by create_tables() via dbDelta().

		// 1.3.0: Execution foundation tables (exec_sessions, execution_plan, execution_ledger)
		//        — handled by create_tables() via dbDelta(). No data migrations required.

		if ( version_compare( $installed_version, '1.4.0', '<' ) ) {
			self::upgrade_to_140();
		}

		if ( version_compare( $installed_version, '1.4.1', '<' ) ) {
			self::upgrade_to_141();
		}

		self::create_tables();
		update_option( 'konx_affiliate_db_version', KONX_AFFILIATE_DB_VERSION );
	}

	/**
	 * Upgrade to database version 1.1.0.
	 *
	 * Changes:
	 * - Widen referral_code to varchar(50) in affiliates, clicks, conversions.
	 * - Add external_id and phone columns to affiliates.
	 * - Change affiliate_type default from 'referral' to 'sales_agent'.
	 * - Create API keys and API log tables.
	 * - Update cookie duration default from 30 to 90 days.
	 * - Deactivate referral affiliate commission rules.
	 *
	 * Column widens and new tables are handled by dbDelta via create_tables().
	 * This method handles data migrations that dbDelta cannot do.
	 */
	private static function upgrade_to_110() {
		global $wpdb;

		// Deactivate referral affiliate commission rules (keep data, set inactive).
		$rules_table = $wpdb->prefix . 'konx_commission_rules';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$rules_table} SET is_active = 0, updated_at = %s WHERE affiliate_type = %s AND is_active = 1",
				current_time( 'mysql', true ),
				'referral'
			)
		);

		// Update cookie duration default to 90 days if still at old default.
		$referral_settings = get_option( 'konx_referral_settings', array() );
		if ( empty( $referral_settings['cookie_days'] ) || 30 === (int) $referral_settings['cookie_days'] ) {
			$referral_settings['cookie_days'] = 90;
			update_option( 'konx_referral_settings', $referral_settings );
		}
	}

	/**
	 * Upgrade to database version 1.4.0.
	 *
	 * Changes:
	 * - Add composite index idx_cross_session (source_record_id, source_system, status)
	 *   to wp_konx_migration_execution_ledger for efficient cross-session idempotency
	 *   queries in the revalidation engine.
	 * - Add revalidation tracking columns to wp_konx_migration_exec_sessions:
	 *   last_revalidated_at, revalidation_status, revalidation_stale_count,
	 *   revalidation_conflict_count.
	 *
	 * New columns are handled by dbDelta() via create_tables(). The index addition
	 * is handled explicitly here because dbDelta does not reliably add indexes to
	 * existing tables in all MySQL versions. The column additions via ALTER TABLE
	 * here are a belt-and-suspenders fallback in case dbDelta is unavailable.
	 */
	private static function upgrade_to_140() {
		global $wpdb;

		$ledger_table  = $wpdb->prefix . 'konx_migration_execution_ledger';
		$session_table = $wpdb->prefix . 'konx_migration_exec_sessions';

		// Add composite index for cross-session idempotency (if not already present).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$idx_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.STATISTICS
				 WHERE TABLE_SCHEMA = DATABASE()
				   AND TABLE_NAME = %s
				   AND INDEX_NAME = 'idx_cross_session'",
				$ledger_table
			)
		);

		if ( ! $idx_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( "ALTER TABLE {$ledger_table} ADD INDEX idx_cross_session (source_record_id, source_system, status)" );
		}

		// Add revalidation tracking columns to exec_sessions (if not already present).
		$revalidation_columns = array(
			'last_revalidated_at'         => "ALTER TABLE {$session_table} ADD COLUMN last_revalidated_at datetime DEFAULT NULL",
			'revalidation_status'         => "ALTER TABLE {$session_table} ADD COLUMN revalidation_status varchar(20) DEFAULT NULL",
			'revalidation_stale_count'    => "ALTER TABLE {$session_table} ADD COLUMN revalidation_stale_count int(10) unsigned NOT NULL DEFAULT 0",
			'revalidation_conflict_count' => "ALTER TABLE {$session_table} ADD COLUMN revalidation_conflict_count int(10) unsigned NOT NULL DEFAULT 0",
		);

		foreach ( $revalidation_columns as $col_name => $alter_sql ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$col_exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM information_schema.COLUMNS
					 WHERE TABLE_SCHEMA = DATABASE()
					   AND TABLE_NAME = %s
					   AND COLUMN_NAME = %s",
					$session_table,
					$col_name
				)
			);

			if ( ! $col_exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->query( $alter_sql );
			}
		}
	}

	/**
	 * Upgrade to database version 1.4.1.
	 *
	 * Changes:
	 * - Convert the three migration metadata tables to InnoDB storage engine:
	 *     wp_konx_migration_exec_sessions
	 *     wp_konx_migration_execution_plan
	 *     wp_konx_migration_execution_ledger
	 *
	 * InnoDB is required for transactional snapshot creation (START TRANSACTION /
	 * COMMIT / ROLLBACK). MyISAM does not support transactions. New installations
	 * will receive InnoDB directly from the CREATE TABLE statement (via 1.4.1
	 * schema). Existing 1.4.0 installations that used dbDelta (which may have
	 * defaulted to MyISAM) are converted here.
	 *
	 * Guard conditions:
	 *   1. InnoDB must be available on this server (SHOW ENGINES check).
	 *   2. Each table is only converted if it exists and is NOT already InnoDB.
	 *   3. Never hard-codes the 'wp_' prefix — always uses $wpdb->prefix.
	 */
	private static function upgrade_to_141() {
		global $wpdb;

		// Step 1: Verify InnoDB is available on this server.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$engines = $wpdb->get_results( 'SHOW ENGINES', ARRAY_A );

		$innodb_available = false;
		if ( is_array( $engines ) ) {
			foreach ( $engines as $engine ) {
				if (
					isset( $engine['Engine'], $engine['Support'] ) &&
					'InnoDB' === $engine['Engine'] &&
					in_array( $engine['Support'], array( 'YES', 'DEFAULT' ), true )
				) {
					$innodb_available = true;
					break;
				}
			}
		}

		if ( ! $innodb_available ) {
			error_log( 'KonX Affiliate Dashboard: InnoDB not available on this server. Migration metadata tables will remain in their current storage engine. Upgrade to 1.4.1 skipped.' );
			return;
		}

		// Step 2: Convert each of the three migration metadata tables to InnoDB
		// if the table exists and is not already using InnoDB.
		$tables_to_convert = array(
			$wpdb->prefix . 'konx_migration_exec_sessions',
			$wpdb->prefix . 'konx_migration_execution_plan',
			$wpdb->prefix . 'konx_migration_execution_ledger',
		);

		foreach ( $tables_to_convert as $table_name ) {
			// Check if the table exists and get its current engine.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_status = $wpdb->get_row(
				$wpdb->prepare(
					'SHOW TABLE STATUS WHERE Name = %s',
					$table_name
				)
			);

			if ( ! $table_status ) {
				// Table does not exist — will be created by create_tables() below.
				continue;
			}

			if ( 'InnoDB' === $table_status->Engine ) {
				// Already InnoDB — no action needed.
				continue;
			}

			// Convert to InnoDB.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->query( "ALTER TABLE `{$table_name}` ENGINE=InnoDB" );

			if ( false === $result ) {
				error_log( "KonX Affiliate Dashboard: Failed to convert {$table_name} to InnoDB. Error: " . $wpdb->last_error );
			}
		}
	}
}
