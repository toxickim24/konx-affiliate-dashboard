<?php
/**
 * Migration record recovery inspector — Phase 24C-6F.
 *
 * Provides read-only classification of stuck 'processing' ledger rows and
 * test-only primitives for safe recovery operations.
 *
 * IMPORTANT:
 *   - inspect_processing_record() is READ-ONLY. It never mutates state.
 *   - safe_reset_to_pending() and reconcile_business_complete() are gated
 *     by KONX_MIGRATION_TEST_EXECUTION_ENABLED. They must never be called
 *     in production runtime.
 *   - The canonical session UUID is rejected by all mutation primitives.
 *   - No automatic/scheduled recovery is performed. All calls are explicit.
 *
 * Classifications returned by inspect_processing_record():
 *   safe_to_reset            — no business mutation; ledger can be reset to pending
 *   user_created_only        — WP user exists with proven migration ownership; no affiliate
 *   ownership_ambiguous      — WP user exists but ownership cannot be proven
 *   affiliate_created        — affiliate exists (link_wp/link_ca actions)
 *   business_complete_unledgered — both user+affiliate exist matching plan, ledger incomplete
 *   conflict                 — cross-session completion conflict
 *   manual_review_required   — any ambiguous/unsafe condition
 *
 * @package KonxAffiliateDashboard
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Migration_Record_Inspector
 */
class Konx_Migration_Record_Inspector {

	const CANONICAL_UUID = '395e2b79-1e0a-49e8-9ea6-1ae146c9a54d';

	// Classification constants.
	const CLASS_SAFE_TO_RESET              = 'safe_to_reset';
	const CLASS_USER_CREATED_ONLY          = 'user_created_only';
	const CLASS_OWNERSHIP_AMBIGUOUS        = 'ownership_ambiguous';
	const CLASS_AFFILIATE_CREATED          = 'affiliate_created';
	const CLASS_BUSINESS_COMPLETE          = 'business_complete_unledgered';
	const CLASS_CONFLICT                   = 'conflict';
	const CLASS_MANUAL_REVIEW              = 'manual_review_required';

	/**
	 * Inspect a processing ledger row and classify its recovery state.
	 *
	 * Read-only. Makes no DB mutations. Safe to call in any environment.
	 *
	 * @param string $session_uuid   Session UUID.
	 * @param int    $plan_record_id Plan table primary key.
	 * @return array {
	 *     @type string   $session_uuid
	 *     @type int      $plan_record_id
	 *     @type int      $source_record_id
	 *     @type string   $action
	 *     @type string   $classification
	 *     @type int|null $wp_user_id
	 *     @type int|null $affiliate_id
	 *     @type string   $reason
	 *     @type string   $error_code  (empty if OK)
	 * }
	 */
	public static function inspect_processing_record( $session_uuid, $plan_record_id ) {
		global $wpdb;

		$session_uuid   = sanitize_text_field( (string) $session_uuid );
		$plan_record_id = (int) $plan_record_id;

		// Canonical protection.
		if ( self::CANONICAL_UUID === $session_uuid ) {
			return self::make_result( $session_uuid, $plan_record_id, 0, '', self::CLASS_MANUAL_REVIEW, null, null, 'Canonical session cannot be inspected for recovery.' );
		}

		// Verify session exists.
		$session = Konx_Migration_Exec_Session::get( $session_uuid );
		if ( ! $session ) {
			return self::make_result( $session_uuid, $plan_record_id, 0, '', self::CLASS_MANUAL_REVIEW, null, null, 'Session not found.', 'session_not_found' );
		}

		// Load plan row.
		$plan_table = $wpdb->prefix . Konx_Migration_Execution_Plan::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$plan_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$plan_table} WHERE id = %d LIMIT 1", $plan_record_id )
		);
		if ( ! $plan_row ) {
			return self::make_result( $session_uuid, $plan_record_id, 0, '', self::CLASS_MANUAL_REVIEW, null, null, 'Plan record not found.', 'plan_not_found' );
		}

		if ( (int) $plan_row->session_id !== (int) $session->id ) {
			return self::make_result( $session_uuid, $plan_record_id, (int) $plan_row->source_record_id, (string) $plan_row->action, self::CLASS_MANUAL_REVIEW, null, null, 'Plan record does not belong to this session.', 'session_mismatch' );
		}

		$source_record_id = (int) $plan_row->source_record_id;
		$action           = (string) $plan_row->action;

		// Load ledger row.
		$ledger_table = $wpdb->prefix . Konx_Migration_Execution_Ledger::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ledger_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$ledger_table} WHERE plan_id = %d AND session_id = %d LIMIT 1",
				$plan_record_id,
				(int) $session->id
			)
		);

		if ( ! $ledger_row ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, $action, self::CLASS_MANUAL_REVIEW, null, null, 'Ledger row not found.', 'ledger_not_found' );
		}

		if ( 'processing' !== $ledger_row->status ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, $action, self::CLASS_MANUAL_REVIEW, null, null, sprintf( 'Ledger status is "%s", not processing.', $ledger_row->status ), 'not_processing' );
		}

		// Check cross-session completion.
		$already_done = Konx_Migration_Execution_Ledger::find_completed( $source_record_id, 'powerof10' );
		if ( $already_done ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, $action, self::CLASS_CONFLICT, null, null, sprintf( 'Source record %d already completed in session_id=%d (ledger_id=%d).', $source_record_id, $already_done->session_id, $already_done->id ), 'cross_session_conflict' );
		}

		// Dispatch to action-specific inspection.
		switch ( $action ) {
			case 'create':
				return self::inspect_create( $session_uuid, $plan_record_id, $source_record_id, $ledger_row, $plan_row );
			case 'link_wp':
				return self::inspect_link_wp( $session_uuid, $plan_record_id, $source_record_id, $ledger_row, $plan_row );
			case 'link_ca':
				return self::inspect_link_ca( $session_uuid, $plan_record_id, $source_record_id, $ledger_row, $plan_row );
			default:
				return self::make_result( $session_uuid, $plan_record_id, $source_record_id, $action, self::CLASS_MANUAL_REVIEW, null, null, sprintf( 'Non-actionable or unknown action "%s".', $action ) );
		}
	}

	// ------------------------------------------------------------------
	// Action-specific inspectors.
	// ------------------------------------------------------------------

	/** @param object $ledger_row Ledger DB row. @param object $plan_row Plan DB row. */
	private static function inspect_create( $session_uuid, $plan_record_id, $source_record_id, $ledger_row, $plan_row ) {
		global $wpdb;

		$konx_table = $wpdb->prefix . 'konx_affiliates';
		$email      = strtolower( trim( (string) $plan_row->source_email ) );

		// Evidence from ledger (written by persist_resource_evidence checkpoints).
		$ledger_user_id = $ledger_row->wp_user_id ? (int) $ledger_row->wp_user_id : null;
		$ledger_aff_id  = $ledger_row->affiliate_id ? (int) $ledger_row->affiliate_id : null;

		// Check ledger evidence first (most reliable — written by this executor).
		$wp_user_id = $ledger_user_id;

		// If ledger has no user evidence, fall back to email lookup.
		if ( ! $wp_user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wp_user_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE LOWER(user_email) = %s LIMIT 1", $email )
			);
			$wp_user_id = $wp_user_id ?: null;
		}

		if ( ! $wp_user_id ) {
			// No user found anywhere.
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'create', self::CLASS_SAFE_TO_RESET, null, null, 'No WP user found by ledger evidence or email lookup. Safe to reset.' );
		}

		// Verify user actually exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d LIMIT 1", $wp_user_id ) );
		if ( ! $user_exists ) {
			// Ledger had a user_id but the user no longer exists (was deleted).
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'create', self::CLASS_SAFE_TO_RESET, null, null, sprintf( 'User ID %d from ledger evidence no longer exists. Safe to reset.', $wp_user_id ) );
		}

		// Check ownership via migration meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_source = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'konx_source' LIMIT 1",
			$wp_user_id
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_po10_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'konx_migrated_po10_id' LIMIT 1",
			$wp_user_id
		) );

		$ownership_proven = ( 'migration' === (string) $meta_source && (string) $source_record_id === (string) $meta_po10_id );

		// Also accept ledger evidence as ownership proof (written by this executor before meta).
		$ledger_evidence_proven = ( null !== $ledger_user_id && (int) $ledger_user_id === $wp_user_id );

		if ( ! $ownership_proven && ! $ledger_evidence_proven ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'create', self::CLASS_OWNERSHIP_AMBIGUOUS, $wp_user_id, null, sprintf( 'WP user #%d exists but ownership cannot be proven via meta or ledger evidence.', $wp_user_id ) );
		}

		// Check for affiliate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affiliate_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$konx_table} WHERE user_id = %d LIMIT 1", $wp_user_id ) );
		if ( ! $affiliate_id ) {
			// Prefer ledger evidence affiliate ID.
			$affiliate_id = $ledger_aff_id ?: null;
		}

		if ( ! $affiliate_id ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'create', self::CLASS_USER_CREATED_ONLY, $wp_user_id, null, sprintf( 'WP user #%d exists with proven ownership. No affiliate. Can compensate+reset or resume.', $wp_user_id ) );
		}

		// Affiliate exists — verify it links to this user.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$aff_user_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$konx_table} WHERE id = %d LIMIT 1", $affiliate_id ) );
		if ( $aff_user_id !== $wp_user_id ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'create', self::CLASS_MANUAL_REVIEW, $wp_user_id, $affiliate_id, sprintf( 'Affiliate #%d user_id=%d does not match expected user #%d.', $affiliate_id, $aff_user_id, $wp_user_id ), 'affiliate_user_mismatch' );
		}

		return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'create', self::CLASS_BUSINESS_COMPLETE, $wp_user_id, $affiliate_id, sprintf( 'WP user #%d and affiliate #%d both exist with proven ownership. Ledger can be finalized.', $wp_user_id, $affiliate_id ) );
	}

	/** @param object $ledger_row Ledger DB row. @param object $plan_row Plan DB row. */
	private static function inspect_link_wp( $session_uuid, $plan_record_id, $source_record_id, $ledger_row, $plan_row ) {
		global $wpdb;

		$konx_table = $wpdb->prefix . 'konx_affiliates';
		$wp_user_id = (int) $plan_row->wp_user_id;

		if ( ! $wp_user_id ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'link_wp', self::CLASS_MANUAL_REVIEW, null, null, 'link_wp plan record has no wp_user_id.', 'missing_wp_user_id' );
		}

		// Verify user still exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d LIMIT 1", $wp_user_id ) );
		if ( ! $user_exists ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'link_wp', self::CLASS_SAFE_TO_RESET, null, null, sprintf( 'Frozen WP user #%d no longer exists. No mutation occurred. Safe to reset.', $wp_user_id ) );
		}

		// Check for affiliate.
		$ledger_aff_id = $ledger_row->affiliate_id ? (int) $ledger_row->affiliate_id : null;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affiliate_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$konx_table} WHERE user_id = %d LIMIT 1", $wp_user_id ) );

		if ( ! $affiliate_id ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'link_wp', self::CLASS_SAFE_TO_RESET, $wp_user_id, null, sprintf( 'WP user #%d exists (not created by us). No affiliate created. Safe to reset.', $wp_user_id ) );
		}

		// Affiliate exists.
		if ( $ledger_aff_id && $ledger_aff_id === $affiliate_id ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'link_wp', self::CLASS_BUSINESS_COMPLETE, $wp_user_id, $affiliate_id, sprintf( 'WP user #%d and affiliate #%d (ledger evidence) both exist. Ledger can be finalized.', $wp_user_id, $affiliate_id ) );
		}

		// Affiliate exists but ledger has no affiliate evidence.
		return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'link_wp', self::CLASS_AFFILIATE_CREATED, $wp_user_id, $affiliate_id, sprintf( 'Affiliate #%d exists for WP user #%d. Verify it was created by this session.', $affiliate_id, $wp_user_id ) );
	}

	/** @param object $ledger_row Ledger DB row. @param object $plan_row Plan DB row. */
	private static function inspect_link_ca( $session_uuid, $plan_record_id, $source_record_id, $ledger_row, $plan_row ) {
		global $wpdb;

		$konx_table          = $wpdb->prefix . 'konx_affiliates';
		$ca_table            = $wpdb->prefix . 'wcusage_register';
		$coupon_affiliate_id = (int) $plan_row->coupon_affiliate_id;
		$wp_user_id_frozen   = (int) $plan_row->wp_user_id;

		if ( ! $coupon_affiliate_id ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'link_ca', self::CLASS_MANUAL_REVIEW, null, null, 'link_ca plan record has no coupon_affiliate_id.', 'missing_ca_id' );
		}

		// Load CA row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ca_row = $wpdb->get_row( $wpdb->prepare( "SELECT id, userid FROM {$ca_table} WHERE id = %d LIMIT 1", $coupon_affiliate_id ) );
		if ( ! $ca_row ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'link_ca', self::CLASS_SAFE_TO_RESET, null, null, sprintf( 'CA row #%d no longer exists. Safe to reset.', $coupon_affiliate_id ) );
		}

		$ca_userid  = (int) $ca_row->userid;
		$wp_user_id = $wp_user_id_frozen > 0 ? $wp_user_id_frozen : $ca_userid;

		if ( $wp_user_id_frozen > 0 && $ca_userid !== $wp_user_id_frozen ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'link_ca', self::CLASS_MANUAL_REVIEW, null, null, sprintf( 'CA#%d userid changed. Frozen=%d Actual=%d.', $coupon_affiliate_id, $wp_user_id_frozen, $ca_userid ), 'ca_user_mismatch' );
		}

		// Verify WP user.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d LIMIT 1", $wp_user_id ) );
		if ( ! $user_exists ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'link_ca', self::CLASS_SAFE_TO_RESET, null, null, sprintf( 'WP user #%d no longer exists. Safe to reset.', $wp_user_id ) );
		}

		// Check for affiliate.
		$ledger_aff_id = $ledger_row->affiliate_id ? (int) $ledger_row->affiliate_id : null;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affiliate_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$konx_table} WHERE user_id = %d LIMIT 1", $wp_user_id ) );

		if ( ! $affiliate_id ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'link_ca', self::CLASS_SAFE_TO_RESET, $wp_user_id, null, sprintf( 'WP user #%d (CA#%d) exists but no affiliate created. Safe to reset.', $wp_user_id, $coupon_affiliate_id ) );
		}

		if ( $ledger_aff_id && $ledger_aff_id === $affiliate_id ) {
			return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'link_ca', self::CLASS_BUSINESS_COMPLETE, $wp_user_id, $affiliate_id, sprintf( 'WP user #%d and affiliate #%d (ledger evidence) exist. Ledger can be finalized.', $wp_user_id, $affiliate_id ) );
		}

		return self::make_result( $session_uuid, $plan_record_id, $source_record_id, 'link_ca', self::CLASS_AFFILIATE_CREATED, $wp_user_id, $affiliate_id, sprintf( 'Affiliate #%d exists for WP user #%d (CA#%d). Verify ownership.', $affiliate_id, $wp_user_id, $coupon_affiliate_id ) );
	}

	// ------------------------------------------------------------------
	// Recovery primitives (test-only gated).
	// ------------------------------------------------------------------

	/**
	 * Reset a processing ledger row back to pending.
	 *
	 * ONLY safe when classification === 'safe_to_reset'.
	 * Requires KONX_MIGRATION_TEST_EXECUTION_ENABLED.
	 * Canonical session rejected.
	 *
	 * @param string $session_uuid   Session UUID.
	 * @param int    $plan_record_id Plan record ID.
	 * @return true|WP_Error True on success.
	 */
	public static function safe_reset_to_pending( $session_uuid, $plan_record_id ) {
		if ( ! defined( 'KONX_MIGRATION_TEST_EXECUTION_ENABLED' ) || ! KONX_MIGRATION_TEST_EXECUTION_ENABLED ) {
			return new \WP_Error( 'gate_closed', 'KONX_MIGRATION_TEST_EXECUTION_ENABLED not active.' );
		}

		if ( self::CANONICAL_UUID === $session_uuid ) {
			return new \WP_Error( 'canonical_protected', 'Canonical session cannot be reset.' );
		}

		$inspection = self::inspect_processing_record( $session_uuid, $plan_record_id );
		if ( self::CLASS_SAFE_TO_RESET !== $inspection['classification'] ) {
			return new \WP_Error(
				'not_safe_to_reset',
				sprintf( 'Cannot reset: classification is "%s", not safe_to_reset.', $inspection['classification'] )
			);
		}

		global $wpdb;
		$session      = Konx_Migration_Exec_Session::get( $session_uuid );
		$ledger_table = $wpdb->prefix . Konx_Migration_Execution_Ledger::TABLE;
		$now          = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$ledger_table,
			array(
				'status'            => 'pending',
				'attempt_count'     => 0,
				'started_at'        => null,
				'completed_at'      => null,
				'wp_user_id'        => null,
				'wp_user_created'   => 0,
				'affiliate_id'      => null,
				'affiliate_created' => 0,
				'error_code'        => null,
				'error_message'     => null,
				'updated_at'        => $now,
			),
			array( 'plan_id' => $plan_record_id, 'session_id' => (int) $session->id, 'status' => 'processing' ),
			null,
			array( '%d', '%d', '%s' )
		);

		if ( false === $result || 0 === (int) $result ) {
			return new \WP_Error( 'reset_failed', sprintf( 'Failed to reset ledger for plan_id=%d (rows_affected=%s).', $plan_record_id, json_encode( $result ) ) );
		}

		return true;
	}

	/**
	 * Finalize the ledger for a record whose business mutations completed but ledger is stuck.
	 *
	 * ONLY safe when classification === 'business_complete_unledgered'.
	 * Requires KONX_MIGRATION_TEST_EXECUTION_ENABLED.
	 * Canonical session rejected.
	 * Does NOT re-run any business mutation.
	 *
	 * @param string $session_uuid   Session UUID.
	 * @param int    $plan_record_id Plan record ID.
	 * @return array{ok:true,completed_at:string}|WP_Error
	 */
	public static function reconcile_business_complete( $session_uuid, $plan_record_id ) {
		if ( ! defined( 'KONX_MIGRATION_TEST_EXECUTION_ENABLED' ) || ! KONX_MIGRATION_TEST_EXECUTION_ENABLED ) {
			return new \WP_Error( 'gate_closed', 'KONX_MIGRATION_TEST_EXECUTION_ENABLED not active.' );
		}

		if ( self::CANONICAL_UUID === $session_uuid ) {
			return new \WP_Error( 'canonical_protected', 'Canonical session cannot be reconciled.' );
		}

		$inspection = self::inspect_processing_record( $session_uuid, $plan_record_id );
		if ( self::CLASS_BUSINESS_COMPLETE !== $inspection['classification'] ) {
			return new \WP_Error(
				'not_business_complete',
				sprintf( 'Cannot reconcile: classification is "%s", not business_complete_unledgered.', $inspection['classification'] )
			);
		}

		$wp_user_id   = $inspection['wp_user_id'];
		$affiliate_id = $inspection['affiliate_id'];

		global $wpdb;
		$session      = Konx_Migration_Exec_Session::get( $session_uuid );
		$plan_table   = $wpdb->prefix . Konx_Migration_Execution_Plan::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$plan_row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$plan_table} WHERE id = %d LIMIT 1", $plan_record_id ) );
		$action       = $plan_row ? (string) $plan_row->action : 'create';
		$wp_user_created   = ( 'create' === $action );
		$affiliate_created = true;

		$ledger_table = $wpdb->prefix . Konx_Migration_Execution_Ledger::TABLE;
		$now          = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$ledger_table,
			array(
				'status'            => 'completed',
				'wp_user_id'        => $wp_user_id ? absint( $wp_user_id ) : null,
				'wp_user_created'   => $wp_user_created ? 1 : 0,
				'affiliate_id'      => $affiliate_id ? absint( $affiliate_id ) : null,
				'affiliate_created' => $affiliate_created ? 1 : 0,
				'completed_at'      => $now,
				'updated_at'        => $now,
			),
			array( 'plan_id' => $plan_record_id, 'session_id' => (int) $session->id, 'status' => 'processing' ),
			null,
			array( '%d', '%d', '%s' )
		);

		if ( false === $result || 0 === (int) $result ) {
			return new \WP_Error( 'reconcile_failed', sprintf( 'Failed to reconcile ledger for plan_id=%d (rows_affected=%s).', $plan_record_id, json_encode( $result ) ) );
		}

		return array( 'ok' => true, 'completed_at' => $now );
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Build a structured inspection result.
	 *
	 * @param string   $session_uuid
	 * @param int      $plan_record_id
	 * @param int      $source_record_id
	 * @param string   $action
	 * @param string   $classification
	 * @param int|null $wp_user_id
	 * @param int|null $affiliate_id
	 * @param string   $reason
	 * @param string   $error_code
	 * @return array
	 */
	private static function make_result( $session_uuid, $plan_record_id, $source_record_id, $action, $classification, $wp_user_id, $affiliate_id, $reason, $error_code = '' ) {
		return array(
			'session_uuid'     => $session_uuid,
			'plan_record_id'   => (int) $plan_record_id,
			'source_record_id' => (int) $source_record_id,
			'action'           => $action,
			'classification'   => $classification,
			'wp_user_id'       => $wp_user_id ? (int) $wp_user_id : null,
			'affiliate_id'     => $affiliate_id ? (int) $affiliate_id : null,
			'reason'           => sanitize_text_field( mb_substr( (string) $reason, 0, 500 ) ),
			'error_code'       => sanitize_text_field( mb_substr( (string) $error_code, 0, 50 ) ),
		);
	}
}
