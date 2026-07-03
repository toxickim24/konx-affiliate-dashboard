<?php
/**
 * Batch processor for migration execution.
 *
 * Provides the AJAX framework for batch-based migration processing.
 * In this foundation phase, all endpoints return simulated responses.
 * No records are created, no users are written, no data is modified.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Batch_Processor
 */
class Konx_Batch_Processor {

	/**
	 * Default batch size.
	 */
	const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Register AJAX hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_konx_migration_build_plan', array( __CLASS__, 'ajax_build_plan' ) );
		add_action( 'wp_ajax_konx_migration_queue_status', array( __CLASS__, 'ajax_queue_status' ) );
		add_action( 'wp_ajax_konx_migration_simulate_batch', array( __CLASS__, 'ajax_simulate_batch' ) );
		add_action( 'wp_ajax_konx_migration_preflight', array( __CLASS__, 'ajax_preflight' ) );
		add_action( 'wp_ajax_konx_migration_create_backup', array( __CLASS__, 'ajax_create_backup' ) );
		add_action( 'wp_ajax_konx_migration_verify_backup', array( __CLASS__, 'ajax_verify_backup' ) );
		add_action( 'wp_ajax_konx_migration_verify_execution', array( __CLASS__, 'ajax_verify_execution' ) );
		add_action( 'wp_ajax_konx_migration_execution_readiness', array( __CLASS__, 'ajax_execution_readiness' ) );
		add_action( 'wp_ajax_konx_migration_execute_batch', array( __CLASS__, 'ajax_execute_batch' ) );
	}

	// ------------------------------------------------------------------
	// AJAX: Build Execution Plan
	// ------------------------------------------------------------------

	/**
	 * AJAX handler: build execution plan from current migration state.
	 *
	 * Reads the decision matrix from persisted migration state and
	 * generates a full execution plan. No data is written to
	 * production tables.
	 */
	public static function ajax_build_plan() {
		check_ajax_referer( 'konx_migration_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'konx-affiliate-dashboard' ) ), 403 );
		}

		$state = get_option( 'konx_migration_state', array() );
		if ( empty( $state ) || empty( $state['scan'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No migration scan found. Run the wizard first.', 'konx-affiliate-dashboard' ) ), 400 );
		}

		$batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : self::DEFAULT_BATCH_SIZE;
		$batch_size = max( 10, min( 200, $batch_size ) );

		// Build decision matrix.
		$decisions = self::get_decisions_from_state( $state );
		if ( empty( $decisions ) ) {
			wp_send_json_error( array( 'message' => __( 'No decisions available. Complete the Decision Matrix step first.', 'konx-affiliate-dashboard' ) ), 400 );
		}

		// Build execution plan.
		$plan = Konx_Execution_Planner::build( $decisions, $batch_size );

		// Create a session record.
		$csv_info = isset( $state['csv_info'] ) ? $state['csv_info'] : array();
		$session  = Konx_Migration_Session::create( array(
			'csv_filename'  => isset( $csv_info['filename'] ) ? $csv_info['filename'] : 'unknown.csv',
			'csv_hash'      => isset( $csv_info['hash'] ) ? $csv_info['hash'] : hash( 'sha256', wp_json_encode( $state['scan'] ) ),
			'total_records' => count( $decisions ),
			'initiated_by'  => get_current_user_id(),
		) );

		$session_id = is_wp_error( $session ) ? null : $session['session_id'];

		// Store plan in state (preview only).
		$state['execution_plan'] = array(
			'plan_id'    => $plan['plan_id'],
			'session_id' => $session_id,
			'summary'    => $plan['summary'],
			'total'      => count( $decisions ),
			'actionable' => $plan['total_actions'],
			'skipped'    => $plan['total_skipped'],
			'batches'    => count( $plan['batches'] ),
			'duration'   => $plan['duration'],
			'created_at' => $plan['created_at'],
		);
		update_option( 'konx_migration_state', $state );

		wp_send_json_success( array(
			'plan_id'       => $plan['plan_id'],
			'session_id'    => $session_id,
			'summary'       => $plan['summary'],
			'total_actions' => $plan['total_actions'],
			'total_skipped' => $plan['total_skipped'],
			'phases'        => $plan['phases'],
			'batches'       => $plan['batches'],
			'dependencies'  => $plan['dependencies'],
			'rollback_meta' => $plan['rollback_meta'],
			'duration'      => $plan['duration'],
		) );
	}

	// ------------------------------------------------------------------
	// AJAX: Queue Status
	// ------------------------------------------------------------------

	/**
	 * AJAX handler: return current queue status.
	 *
	 * Returns the execution plan status from migration state.
	 * Simulated — no actual queue is running.
	 */
	public static function ajax_queue_status() {
		check_ajax_referer( 'konx_migration_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'konx-affiliate-dashboard' ) ), 403 );
		}

		$state = get_option( 'konx_migration_state', array() );
		$plan  = isset( $state['execution_plan'] ) ? $state['execution_plan'] : null;

		if ( ! $plan ) {
			wp_send_json_success( array(
				'status'  => 'no_plan',
				'message' => __( 'No execution plan has been built yet.', 'konx-affiliate-dashboard' ),
			) );
			return;
		}

		// Simulated queue status.
		wp_send_json_success( array(
			'status'        => 'preview',
			'plan_id'       => $plan['plan_id'],
			'session_id'    => $plan['session_id'],
			'total'         => $plan['total'],
			'actionable'    => $plan['actionable'],
			'skipped'       => $plan['skipped'],
			'batch_count'   => $plan['batches'],
			'duration'      => $plan['duration'],
			'queue'         => array(
				'queued'     => $plan['actionable'],
				'processing' => 0,
				'completed'  => 0,
				'failed'     => 0,
			),
			'progress'      => array(
				'percent'    => 0,
				'current'    => 0,
				'remaining'  => $plan['actionable'],
			),
			'created_at'    => $plan['created_at'],
			'simulation'    => true,
		) );
	}

	// ------------------------------------------------------------------
	// AJAX: Simulate Batch
	// ------------------------------------------------------------------

	/**
	 * AJAX handler: simulate processing a single batch.
	 *
	 * Returns what would happen if the batch were executed.
	 * No records are created. No database writes. Preview only.
	 */
	public static function ajax_simulate_batch() {
		check_ajax_referer( 'konx_migration_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'konx-affiliate-dashboard' ) ), 403 );
		}

		$batch_number = isset( $_POST['batch_number'] ) ? absint( $_POST['batch_number'] ) : 1;
		$batch_size   = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : self::DEFAULT_BATCH_SIZE;
		$batch_size   = max( 10, min( 200, $batch_size ) );

		$state = get_option( 'konx_migration_state', array() );
		if ( empty( $state['scan'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No migration data found.', 'konx-affiliate-dashboard' ) ), 400 );
		}

		$decisions = self::get_decisions_from_state( $state );
		if ( empty( $decisions ) ) {
			wp_send_json_error( array( 'message' => __( 'No decisions available.', 'konx-affiliate-dashboard' ) ), 400 );
		}

		// Filter to actionable decisions only.
		$actionable = array_filter( $decisions, function ( $d ) {
			return ! in_array( $d['decision'], array( 'skip', 'invalid' ), true );
		} );
		$actionable = array_values( $actionable );

		$offset = ( $batch_number - 1 ) * $batch_size;
		$slice  = array_slice( $actionable, $offset, $batch_size );

		if ( empty( $slice ) ) {
			wp_send_json_success( array(
				'batch_number' => $batch_number,
				'status'       => 'empty',
				'message'      => __( 'No records in this batch range.', 'konx-affiliate-dashboard' ),
				'simulation'   => true,
			) );
			return;
		}

		// Build simulated results for each record.
		$results = array();
		foreach ( $slice as $record ) {
			$results[] = array(
				'po10_id'    => $record['po10_id'],
				'email'      => $record['email'],
				'action'     => $record['decision'],
				'would_do'   => self::describe_action( $record ),
				'simulated'  => true,
				'status'     => 'queued',
			);
		}

		$total_batches = (int) ceil( count( $actionable ) / $batch_size );

		wp_send_json_success( array(
			'batch_number'  => $batch_number,
			'total_batches' => $total_batches,
			'batch_size'    => count( $slice ),
			'results'       => $results,
			'queue'         => array(
				'queued'     => count( $actionable ) - $offset - count( $slice ),
				'processing' => count( $slice ),
				'completed'  => $offset,
			),
			'simulation'    => true,
		) );
	}

	// ------------------------------------------------------------------
	// AJAX: Preflight Checks
	// ------------------------------------------------------------------

	/**
	 * AJAX handler: run all preflight checks.
	 *
	 * Validates that all preconditions are met before migration can
	 * proceed. Read-only — no data is modified.
	 */
	public static function ajax_preflight() {
		check_ajax_referer( 'konx_migration_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'konx-affiliate-dashboard' ) ), 403 );
		}

		$results = Konx_Migration_Preflight::run();

		// Store preflight results in migration state.
		$state = get_option( 'konx_migration_state', array() );
		$state['preflight'] = array(
			'passed'  => $results['passed'],
			'summary' => array(
				'total' => $results['total'],
				'pass'  => $results['pass'],
				'fail'  => $results['fail'],
				'warn'  => $results['warn'],
			),
			'run_at'  => current_time( 'mysql', true ),
		);
		update_option( 'konx_migration_state', $state, false );

		wp_send_json_success( $results );
	}

	// ------------------------------------------------------------------
	// AJAX: Create Backup
	// ------------------------------------------------------------------

	/**
	 * AJAX handler: create a pre-migration backup.
	 *
	 * Exports all relevant database tables and matched user data
	 * to CSV files. Updates the migration session with backup metadata.
	 * Only writes backup files — no production data is modified.
	 */
	public static function ajax_create_backup() {
		check_ajax_referer( 'konx_migration_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'konx-affiliate-dashboard' ) ), 403 );
		}

		$state = get_option( 'konx_migration_state', array() );

		// Get or create session.
		$session_id = null;
		if ( ! empty( $state['execution_plan']['session_id'] ) ) {
			$session_id = $state['execution_plan']['session_id'];
		}

		if ( ! $session_id ) {
			$csv_info = isset( $state['csv_info'] ) ? $state['csv_info'] : array();
			$session  = Konx_Migration_Session::create( array(
				'csv_filename'  => isset( $csv_info['filename'] ) ? $csv_info['filename'] : 'unknown.csv',
				'csv_hash'      => isset( $csv_info['hash'] ) ? $csv_info['hash'] : hash( 'sha256', wp_json_encode( $state ) ),
				'total_records' => isset( $state['scan']['total'] ) ? $state['scan']['total'] : 0,
				'initiated_by'  => get_current_user_id(),
			) );
			$session_id = is_wp_error( $session ) ? 'manual_' . gmdate( 'Ymd_His' ) : $session['session_id'];
		}

		// Collect matched emails from decision matrix.
		$matched_emails = array();
		$decisions = self::get_decisions_from_state( $state );
		foreach ( $decisions as $d ) {
			if ( ! empty( $d['email'] ) ) {
				$matched_emails[] = strtolower( $d['email'] );
			}
		}
		$matched_emails = array_unique( $matched_emails );

		// Create backup.
		$manifest = Konx_Migration_Backup::create( $session_id, $matched_emails );

		if ( is_wp_error( $manifest ) ) {
			wp_send_json_error( array( 'message' => $manifest->get_error_message() ), 500 );
		}

		// Verify backup.
		$verification = Konx_Migration_Backup::verify( $manifest );

		// Build summary.
		$summary = Konx_Migration_Backup::summarize( $manifest, $verification );

		// Store backup metadata in migration state.
		$state['backup'] = array(
			'backup_id'  => $summary['backup_id'],
			'session_id' => $summary['session_id'],
			'created_at' => $summary['created_at'],
			'directory'  => $summary['directory'],
			'total_files' => $summary['total_files'],
			'total_rows' => $summary['total_rows'],
			'total_size' => $summary['total_size_fmt'],
			'verified'   => $summary['verified'],
			'warnings'   => $summary['warnings'],
		);
		update_option( 'konx_migration_state', $state, false );

		// Update session with backup info.
		if ( $session_id && ! str_starts_with( $session_id, 'manual_' ) ) {
			$session = Konx_Migration_Session::get( $session_id );
			if ( $session ) {
				Konx_Migration_Session::update_status( $session_id, 'approved' );
			}
		}

		wp_send_json_success( array(
			'summary'      => $summary,
			'verification' => $verification,
			'files'        => $manifest['files'],
		) );
	}

	// ------------------------------------------------------------------
	// AJAX: Verify Backup
	// ------------------------------------------------------------------

	/**
	 * AJAX handler: verify an existing backup.
	 *
	 * Re-reads the manifest and checks all files. Read-only.
	 */
	public static function ajax_verify_backup() {
		check_ajax_referer( 'konx_migration_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'konx-affiliate-dashboard' ) ), 403 );
		}

		$backup_id = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';

		if ( empty( $backup_id ) ) {
			// Try from state.
			$state = get_option( 'konx_migration_state', array() );
			$backup_id = isset( $state['backup']['backup_id'] ) ? $state['backup']['backup_id'] : '';
		}

		if ( empty( $backup_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No backup ID provided.', 'konx-affiliate-dashboard' ) ), 400 );
		}

		$backup_dir    = Konx_Migration_Backup::get_backup_path( $backup_id );
		$manifest_path = $backup_dir . '/manifest.json';

		if ( ! file_exists( $manifest_path ) ) {
			wp_send_json_error( array( 'message' => __( 'Backup manifest not found.', 'konx-affiliate-dashboard' ) ), 404 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$manifest = json_decode( file_get_contents( $manifest_path ), true );

		if ( ! $manifest ) {
			wp_send_json_error( array( 'message' => __( 'Invalid backup manifest.', 'konx-affiliate-dashboard' ) ), 500 );
		}

		$verification = Konx_Migration_Backup::verify( $manifest );
		$summary      = Konx_Migration_Backup::summarize( $manifest, $verification );

		wp_send_json_success( array(
			'summary'      => $summary,
			'verification' => $verification,
		) );
	}

	// ------------------------------------------------------------------
	// AJAX: Verify Execution
	// ------------------------------------------------------------------

	/**
	 * AJAX handler: run execution verification.
	 *
	 * Builds an execution plan, verifies every item against live
	 * database state, optionally populates the migration log with
	 * 'planned' entries. No production writes.
	 */
	public static function ajax_verify_execution() {
		check_ajax_referer( 'konx_migration_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'konx-affiliate-dashboard' ) ), 403 );
		}

		$state = get_option( 'konx_migration_state', array() );
		if ( empty( $state['scan'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No migration data found.', 'konx-affiliate-dashboard' ) ), 400 );
		}

		$decisions = self::get_decisions_from_state( $state );
		if ( empty( $decisions ) ) {
			wp_send_json_error( array( 'message' => __( 'No decisions available.', 'konx-affiliate-dashboard' ) ), 400 );
		}

		$batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 50;
		$batch_size = max( 10, min( 200, $batch_size ) );

		$populate_log = ! empty( $_POST['populate_log'] );

		// Build execution plan.
		$plan = Konx_Execution_Planner::build( $decisions, $batch_size );

		// Run verification.
		$report = Konx_Execution_Verifier::verify( $plan );

		// Populate migration log preview if requested.
		$log_result = null;
		if ( $populate_log ) {
			$session_id = isset( $state['execution_plan']['session_id'] )
				? $state['execution_plan']['session_id']
				: 'preview_' . gmdate( 'Ymd_His' );

			$log_result = Konx_Execution_Verifier::populate_log_preview( $session_id, $plan, $report );
		}

		// Store verification in state.
		$state['execution_verification'] = array(
			'plan_id'     => $report['plan_id'],
			'verified_at' => $report['verified_at'],
			'summary'     => $report['summary'],
			'can_execute' => $report['can_execute'],
			'rollback'    => $report['rollback'],
			'duration'    => $report['duration'],
			'log_preview' => $log_result,
		);
		update_option( 'konx_migration_state', $state, false );

		wp_send_json_success( array(
			'summary'     => $report['summary'],
			'can_execute' => $report['can_execute'],
			'items'       => $report['items'],
			'rollback'    => $report['rollback'],
			'duration'    => $report['duration'],
			'log_preview' => $log_result,
		) );
	}

	// ------------------------------------------------------------------
	// AJAX: Execution Readiness Dashboard
	// ------------------------------------------------------------------

	/**
	 * AJAX handler: return execution readiness summary.
	 *
	 * Aggregates preflight, backup, and verification status into
	 * a single readiness dashboard. Read-only.
	 */
	public static function ajax_execution_readiness() {
		check_ajax_referer( 'konx_migration_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'konx-affiliate-dashboard' ) ), 403 );
		}

		$state = get_option( 'konx_migration_state', array() );

		// Preflight status.
		$preflight = isset( $state['preflight'] ) ? $state['preflight'] : null;

		// Backup status.
		$backup = isset( $state['backup'] ) ? $state['backup'] : null;

		// Verification status.
		$verification = isset( $state['execution_verification'] ) ? $state['execution_verification'] : null;

		// Execution plan status.
		$plan = isset( $state['execution_plan'] ) ? $state['execution_plan'] : null;

		// Approval status.
		$approved    = ! empty( $state['approved'] );
		$approved_by = isset( $state['approved_by'] ) ? $state['approved_by'] : null;
		$approved_at = isset( $state['approved_at'] ) ? $state['approved_at'] : null;

		// Overall readiness.
		$gates = array(
			'preflight'    => $preflight && $preflight['passed'],
			'backup'       => $backup && $backup['verified'],
			'verification' => $verification && $verification['can_execute'],
			'plan'         => ! empty( $plan ),
			'approved'     => $approved,
		);

		$all_gates_passed = ! in_array( false, $gates, true );

		wp_send_json_success( array(
			'ready'        => $all_gates_passed,
			'gates'        => $gates,
			'preflight'    => $preflight,
			'backup'       => $backup ? array(
				'backup_id'   => $backup['backup_id'],
				'created_at'  => $backup['created_at'],
				'total_files' => $backup['total_files'],
				'total_rows'  => $backup['total_rows'],
				'total_size'  => $backup['total_size'],
				'verified'    => $backup['verified'],
			) : null,
			'verification' => $verification ? array(
				'summary'     => $verification['summary'],
				'can_execute' => $verification['can_execute'],
				'rollback'    => $verification['rollback'],
				'duration'    => $verification['duration'],
			) : null,
			'plan'         => $plan ? array(
				'plan_id'    => $plan['plan_id'],
				'total'      => $plan['total'],
				'actionable' => $plan['actionable'],
				'skipped'    => $plan['skipped'],
				'batches'    => $plan['batches'],
				'duration'   => $plan['duration'],
			) : null,
			'approval'     => array(
				'approved'    => $approved,
				'approved_by' => $approved_by,
				'approved_at' => $approved_at,
			),
		) );
	}

	// ------------------------------------------------------------------
	// AJAX: Execute Batch
	// ------------------------------------------------------------------

	/**
	 * AJAX handler: execute a single migration batch.
	 *
	 * Validates all safety gates, acquires the migration lock,
	 * and processes one batch of records. Returns progress.
	 *
	 * THIS IS THE ONLY ENDPOINT THAT CREATES USERS/AFFILIATES.
	 */
	public static function ajax_execute_batch() {
		check_ajax_referer( 'konx_migration_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'konx-affiliate-dashboard' ) ), 403 );
		}

		$state = get_option( 'konx_migration_state', array() );

		// Validate all safety gates.
		$gates = Konx_Migration_Executor::validate_gates( $state );
		if ( is_wp_error( $gates ) ) {
			wp_send_json_error( array(
				'message' => $gates->get_error_message(),
				'code'    => $gates->get_error_code(),
			), 403 );
		}

		$batch_number = isset( $_POST['batch_number'] ) ? absint( $_POST['batch_number'] ) : 1;
		$batch_size   = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 50;
		$batch_size   = max( 10, min( 200, $batch_size ) );

		$decisions = self::get_decisions_from_state( $state );
		if ( empty( $decisions ) ) {
			wp_send_json_error( array( 'message' => __( 'No decisions available.', 'konx-affiliate-dashboard' ) ), 400 );
		}

		// Build execution plan.
		$plan = Konx_Execution_Planner::build( $decisions, $batch_size );

		// Filter to actionable items only, sorted by execution order.
		$actionable = array();
		foreach ( $plan['items'] as $item ) {
			if ( ! in_array( $item['planned_action'], array( 'skip', 'invalid' ), true ) ) {
				$actionable[] = $item;
			}
		}
		usort( $actionable, function ( $a, $b ) {
			return $a['execution_order'] - $b['execution_order'];
		} );

		$total_batches = (int) ceil( count( $actionable ) / $batch_size );
		$offset        = ( $batch_number - 1 ) * $batch_size;
		$batch_items   = array_slice( $actionable, $offset, $batch_size );

		if ( empty( $batch_items ) ) {
			wp_send_json_success( array(
				'batch_number'  => $batch_number,
				'total_batches' => $total_batches,
				'status'        => 'complete',
				'message'       => __( 'All batches processed.', 'konx-affiliate-dashboard' ),
			) );
			return;
		}

		// Get or create session.
		$session_id = isset( $state['execution_plan']['session_id'] )
			? $state['execution_plan']['session_id']
			: null;

		if ( ! $session_id ) {
			$csv_info = isset( $state['csv_info'] ) ? $state['csv_info'] : array();
			$session  = Konx_Migration_Session::create( array(
				'csv_filename'  => isset( $csv_info['filename'] ) ? $csv_info['filename'] : 'migration.csv',
				'csv_hash'      => isset( $csv_info['hash'] ) ? $csv_info['hash'] : '',
				'total_records' => count( $decisions ),
				'initiated_by'  => get_current_user_id(),
			) );
			$session_id = is_wp_error( $session ) ? 'exec_' . gmdate( 'Ymd_His' ) : $session['session_id'];
		}

		// Acquire lock on first batch.
		if ( 1 === $batch_number ) {
			if ( ! Konx_Migration_Executor::acquire_lock( $session_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Could not acquire migration lock.', 'konx-affiliate-dashboard' ) ), 409 );
			}

			// Update session status.
			Konx_Migration_Session::update_status( $session_id, 'executing' );
		}

		// Execute the batch.
		$batch_result = Konx_Migration_Executor::execute_batch( $session_id, $batch_items, $state );

		// Update session progress.
		$session = Konx_Migration_Session::get( $session_id );
		if ( $session ) {
			$prev_processed = (int) $session->processed;
			$prev_succeeded = (int) $session->succeeded;
			$prev_failed    = (int) $session->failed;
			$prev_skipped   = (int) $session->skipped;

			Konx_Migration_Session::update_progress( $session_id, array(
				'processed' => $prev_processed + $batch_result['processed'],
				'succeeded' => $prev_succeeded + $batch_result['succeeded'],
				'failed'    => $prev_failed + $batch_result['failed'],
				'skipped'   => $prev_skipped + $batch_result['skipped'],
			) );
		}

		$is_last = ( $batch_number >= $total_batches );

		// Release lock and finalize on last batch.
		if ( $is_last ) {
			$final_status = ( $batch_result['failed'] > 0 || $batch_result['rolled_back'] ) ? 'failed' : 'completed';
			Konx_Migration_Session::update_status( $session_id, $final_status );
			Konx_Migration_Executor::release_lock();
		}

		wp_send_json_success( array(
			'batch_number'  => $batch_number,
			'total_batches' => $total_batches,
			'session_id'    => $session_id,
			'processed'     => $batch_result['processed'],
			'succeeded'     => $batch_result['succeeded'],
			'failed'        => $batch_result['failed'],
			'skipped'       => $batch_result['skipped'],
			'rolled_back'   => $batch_result['rolled_back'],
			'results'       => $batch_result['results'],
			'is_last'       => $is_last,
			'status'        => $is_last ? 'complete' : 'in_progress',
		) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Extract decisions from persisted migration state.
	 *
	 * Rebuilds the decision matrix from state using the wizard's
	 * build_decision_matrix pattern. If decisions are cached in state,
	 * returns those directly.
	 *
	 * @param array $state Migration state.
	 * @return array Decision records.
	 */
	private static function get_decisions_from_state( $state ) {
		// Check for cached decision matrix in state.
		if ( ! empty( $state['decision_matrix']['decisions'] ) ) {
			return $state['decision_matrix']['decisions'];
		}

		return array();
	}

	/**
	 * Describe what an action would do in human-readable form.
	 *
	 * @param array $record Decision record.
	 * @return string Description of the planned action.
	 */
	private static function describe_action( $record ) {
		$action = isset( $record['decision'] ) ? $record['decision'] : 'unknown';

		switch ( $action ) {
			case 'create':
				return sprintf(
					/* translators: %s: email address */
					__( 'Create WP user + KonX affiliate for %s', 'konx-affiliate-dashboard' ),
					$record['email']
				);

			case 'link_wp':
				return sprintf(
					/* translators: 1: email, 2: WP user ID */
					__( 'Create KonX affiliate for existing WP user %1$s (#%2$d)', 'konx-affiliate-dashboard' ),
					$record['email'],
					$record['wp_user_id']
				);

			case 'link_ca':
				return sprintf(
					/* translators: 1: email, 2: coupon code */
					__( 'Create KonX affiliate for existing Coupon Affiliate %1$s (coupon: %2$s)', 'konx-affiliate-dashboard' ),
					$record['email'],
					$record['ca_coupon']
				);

			case 'link_konx':
				return sprintf(
					/* translators: 1: email, 2: KonX affiliate ID */
					__( 'Link PO10 data to existing KonX affiliate %1$s (#%2$d)', 'konx-affiliate-dashboard' ),
					$record['email'],
					$record['konx_id']
				);

			default:
				return __( 'No action', 'konx-affiliate-dashboard' );
		}
	}
}
