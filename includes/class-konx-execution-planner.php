<?php
/**
 * Execution planner for migration.
 *
 * Converts the Decision Matrix output into an ordered execution plan
 * with dependency resolution and batch grouping. This is a read-only
 * planning layer — no records are created, no users are written,
 * no migration is executed.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Execution_Planner
 */
class Konx_Execution_Planner {

	/**
	 * Default batch size.
	 */
	const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Estimated seconds per record for duration estimates.
	 */
	const SECONDS_PER_RECORD = 0.5;

	/**
	 * Planned action types and their execution priority.
	 * Lower numbers execute first.
	 *
	 * @var array
	 */
	private static $action_priority = array(
		'skip'      => 0,
		'invalid'   => 0,
		'link_konx' => 1,
		'link_wp'   => 2,
		'link_ca'   => 2,
		'create'    => 3,
	);

	// ------------------------------------------------------------------
	// Plan Building
	// ------------------------------------------------------------------

	/**
	 * Build an execution plan from decision matrix output.
	 *
	 * Takes the decisions array from build_decision_matrix() and
	 * produces an ordered execution plan with dependency tracking,
	 * batch grouping, and rollback metadata. No data is written.
	 *
	 * @param array $decisions Array of decision records from the Decision Matrix.
	 * @param int   $batch_size Number of records per batch.
	 * @return array {
	 *     @type string $plan_id       Unique plan identifier.
	 *     @type string $created_at    ISO 8601 timestamp.
	 *     @type array  $summary       Counts by action type.
	 *     @type int    $total_actions Total actionable records.
	 *     @type int    $total_skipped Total skipped/invalid records.
	 *     @type array  $phases        Ordered execution phases.
	 *     @type array  $batches       Batch groupings with metadata.
	 *     @type array  $dependencies  Dependency graph.
	 *     @type array  $rollback_meta Rollback strategy metadata.
	 *     @type array  $duration      Estimated duration.
	 * }
	 */
	public static function build( $decisions, $batch_size = self::DEFAULT_BATCH_SIZE ) {
		if ( empty( $decisions ) ) {
			return self::empty_plan();
		}

		$batch_size = max( 1, absint( $batch_size ) );
		$plan_id    = 'plan_' . gmdate( 'Ymd_His' ) . '_' . substr( bin2hex( random_bytes( 3 ) ), 0, 6 );

		// Classify each decision into a planned action.
		$plan_items    = array();
		$action_counts = array(
			'create'    => 0,
			'link_wp'   => 0,
			'link_ca'   => 0,
			'link_konx' => 0,
			'skip'      => 0,
			'invalid'   => 0,
		);

		$sponsor_index = self::build_sponsor_index( $decisions );

		foreach ( $decisions as $idx => $d ) {
			$action   = isset( $d['decision'] ) ? $d['decision'] : 'skip';
			$priority = isset( self::$action_priority[ $action ] ) ? self::$action_priority[ $action ] : 99;

			if ( isset( $action_counts[ $action ] ) ) {
				$action_counts[ $action ]++;
			}

			$deps = self::resolve_dependencies( $d, $sponsor_index );

			$plan_items[] = array(
				'index'           => $idx,
				'po10_id'         => isset( $d['po10_id'] ) ? $d['po10_id'] : null,
				'email'           => isset( $d['email'] ) ? $d['email'] : '',
				'team_name'       => isset( $d['team_name'] ) ? $d['team_name'] : '',
				'planned_action'  => $action,
				'priority'        => $priority,
				'dependencies'    => $deps,
				'execution_order' => 0,
				'reasons'         => isset( $d['reasons'] ) ? $d['reasons'] : array(),
				'wp_user_id'      => isset( $d['wp_user_id'] ) ? $d['wp_user_id'] : null,
				'ca_coupon'       => isset( $d['ca_coupon'] ) ? $d['ca_coupon'] : null,
				'konx_id'         => isset( $d['konx_id'] ) ? $d['konx_id'] : null,
				'sponsor_status'  => isset( $d['sponsor_status'] ) ? $d['sponsor_status'] : 'none',
				'rollback_action' => self::determine_rollback_action( $action ),
			);
		}

		// Assign execution order using topological sort by priority + dependencies.
		$plan_items = self::assign_execution_order( $plan_items );

		// Build phases (skip/invalid → link → create).
		$phases = self::build_phases( $plan_items );

		// Build batch groupings.
		$batches = self::build_batches( $plan_items, $batch_size );

		// Build dependency graph.
		$dep_graph = self::build_dependency_graph( $plan_items );

		// Build rollback metadata.
		$rollback_meta = self::build_rollback_metadata( $plan_items, $action_counts );

		// Estimate duration.
		$actionable    = $action_counts['create'] + $action_counts['link_wp'] + $action_counts['link_ca'] + $action_counts['link_konx'];
		$duration      = self::estimate_duration( $actionable, $batch_size );

		return array(
			'plan_id'        => $plan_id,
			'created_at'     => gmdate( 'c' ),
			'summary'        => $action_counts,
			'total_actions'  => $actionable,
			'total_skipped'  => $action_counts['skip'] + $action_counts['invalid'],
			'phases'         => $phases,
			'batches'        => $batches,
			'dependencies'   => $dep_graph,
			'rollback_meta'  => $rollback_meta,
			'duration'       => $duration,
			'items'          => $plan_items,
		);
	}

	// ------------------------------------------------------------------
	// Sponsor Dependency Resolution
	// ------------------------------------------------------------------

	/**
	 * Build an index mapping team_name to decision array index.
	 *
	 * @param array $decisions Decision records.
	 * @return array { lowercase_team_name => decision_index }.
	 */
	private static function build_sponsor_index( $decisions ) {
		$index = array();
		foreach ( $decisions as $idx => $d ) {
			$tn = isset( $d['team_name'] ) ? strtolower( trim( $d['team_name'] ) ) : '';
			if ( '' !== $tn ) {
				$index[ $tn ] = $idx;
			}
		}
		return $index;
	}

	/**
	 * Resolve dependencies for a single decision.
	 *
	 * A record depends on its sponsor being created first if the
	 * sponsor is also in the migration set with a 'create' action.
	 *
	 * @param array $decision       Single decision record.
	 * @param array $sponsor_index  team_name → index mapping.
	 * @return array Array of dependency indices (po10_ids).
	 */
	private static function resolve_dependencies( $decision, $sponsor_index ) {
		$deps = array();

		$sponsor = isset( $decision['sponsor'] ) ? strtolower( trim( $decision['sponsor'] ) ) : '';
		if ( '' !== $sponsor && isset( $sponsor_index[ $sponsor ] ) ) {
			$deps[] = $sponsor_index[ $sponsor ];
		}

		return $deps;
	}

	// ------------------------------------------------------------------
	// Execution Ordering
	// ------------------------------------------------------------------

	/**
	 * Assign execution order based on priority and dependencies.
	 *
	 * Uses a stable sort: priority first, then dependency depth,
	 * then original index for determinism.
	 *
	 * @param array $items Plan items.
	 * @return array Items with execution_order assigned.
	 */
	private static function assign_execution_order( $items ) {
		// Calculate dependency depth for each item.
		$depths = array();
		foreach ( $items as $idx => $item ) {
			$depths[ $idx ] = self::calculate_depth( $idx, $items, array() );
		}

		// Build sort keys: (priority * 100000) + (depth * 10000) + original_index.
		$sort_keys = array();
		foreach ( $items as $idx => $item ) {
			$sort_keys[ $idx ] = ( $item['priority'] * 100000 ) + ( $depths[ $idx ] * 10000 ) + $idx;
		}

		// Sort by composite key.
		asort( $sort_keys, SORT_NUMERIC );

		// Assign execution order.
		$order = 1;
		foreach ( $sort_keys as $idx => $key ) {
			$items[ $idx ]['execution_order'] = $order++;
		}

		return $items;
	}

	/**
	 * Calculate dependency depth recursively.
	 *
	 * @param int   $idx     Current item index.
	 * @param array $items   All plan items.
	 * @param array $visited Visited indices (cycle detection).
	 * @return int Depth (0 for no dependencies).
	 */
	private static function calculate_depth( $idx, $items, $visited ) {
		if ( in_array( $idx, $visited, true ) ) {
			return 0; // Circular — break.
		}

		if ( empty( $items[ $idx ]['dependencies'] ) ) {
			return 0;
		}

		$visited[] = $idx;
		$max_depth = 0;

		foreach ( $items[ $idx ]['dependencies'] as $dep_idx ) {
			if ( isset( $items[ $dep_idx ] ) ) {
				$depth     = 1 + self::calculate_depth( $dep_idx, $items, $visited );
				$max_depth = max( $max_depth, $depth );
			}
		}

		return $max_depth;
	}

	// ------------------------------------------------------------------
	// Phase Building
	// ------------------------------------------------------------------

	/**
	 * Build execution phases.
	 *
	 * Phase 0: Skip/Invalid (no-ops, logged only)
	 * Phase 1: Link existing (link_konx, link_wp, link_ca)
	 * Phase 2: Create new (create users + affiliates)
	 *
	 * @param array $items Plan items.
	 * @return array { phase_number => { name, description, count, actions[] } }.
	 */
	private static function build_phases( $items ) {
		$phases = array(
			0 => array(
				'name'        => 'skip',
				'label'       => __( 'Skip / Invalid', 'konx-affiliate-dashboard' ),
				'description' => __( 'Records that will be skipped or are invalid. No action taken.', 'konx-affiliate-dashboard' ),
				'count'       => 0,
				'actions'     => array( 'skip', 'invalid' ),
			),
			1 => array(
				'name'        => 'link',
				'label'       => __( 'Link Existing', 'konx-affiliate-dashboard' ),
				'description' => __( 'Link existing WordPress users or Coupon Affiliates to KonX profiles.', 'konx-affiliate-dashboard' ),
				'count'       => 0,
				'actions'     => array( 'link_konx', 'link_wp', 'link_ca' ),
			),
			2 => array(
				'name'        => 'create',
				'label'       => __( 'Create New', 'konx-affiliate-dashboard' ),
				'description' => __( 'Create new WordPress users and KonX affiliate profiles.', 'konx-affiliate-dashboard' ),
				'count'       => 0,
				'actions'     => array( 'create' ),
			),
		);

		foreach ( $items as $item ) {
			foreach ( $phases as $phase_num => &$phase ) {
				if ( in_array( $item['planned_action'], $phase['actions'], true ) ) {
					$phase['count']++;
					break;
				}
			}
		}

		return $phases;
	}

	// ------------------------------------------------------------------
	// Batch Grouping
	// ------------------------------------------------------------------

	/**
	 * Group actionable plan items into batches.
	 *
	 * Only actionable items (create, link_*) are batched.
	 * Skip/invalid are excluded from batch processing.
	 *
	 * @param array $items      Plan items.
	 * @param int   $batch_size Items per batch.
	 * @return array Array of batch metadata.
	 */
	private static function build_batches( $items, $batch_size ) {
		// Filter to actionable items, sorted by execution_order.
		$actionable = array();
		foreach ( $items as $item ) {
			if ( ! in_array( $item['planned_action'], array( 'skip', 'invalid' ), true ) ) {
				$actionable[] = $item;
			}
		}

		usort( $actionable, function ( $a, $b ) {
			return $a['execution_order'] - $b['execution_order'];
		} );

		$batches     = array();
		$batch_num   = 0;
		$total       = count( $actionable );

		for ( $offset = 0; $offset < $total; $offset += $batch_size ) {
			$batch_num++;
			$slice = array_slice( $actionable, $offset, $batch_size );

			$action_breakdown = array();
			foreach ( $slice as $item ) {
				$a = $item['planned_action'];
				if ( ! isset( $action_breakdown[ $a ] ) ) {
					$action_breakdown[ $a ] = 0;
				}
				$action_breakdown[ $a ]++;
			}

			$batches[] = array(
				'batch_number'     => $batch_num,
				'offset'           => $offset,
				'size'             => count( $slice ),
				'action_breakdown' => $action_breakdown,
				'estimated_seconds' => count( $slice ) * self::SECONDS_PER_RECORD,
				'status'           => 'pending',
			);
		}

		return $batches;
	}

	// ------------------------------------------------------------------
	// Dependency Graph
	// ------------------------------------------------------------------

	/**
	 * Build a dependency graph summary.
	 *
	 * @param array $items Plan items.
	 * @return array { total_with_deps, max_depth, chains[] }.
	 */
	private static function build_dependency_graph( $items ) {
		$with_deps = 0;
		$max_depth = 0;
		$chains    = array();

		foreach ( $items as $idx => $item ) {
			if ( ! empty( $item['dependencies'] ) ) {
				$with_deps++;
				$depth = self::calculate_depth( $idx, $items, array() );
				$max_depth = max( $max_depth, $depth );

				foreach ( $item['dependencies'] as $dep_idx ) {
					if ( isset( $items[ $dep_idx ] ) ) {
						$chains[] = array(
							'child'  => $item['po10_id'],
							'parent' => $items[ $dep_idx ]['po10_id'],
							'depth'  => $depth,
						);
					}
				}
			}
		}

		return array(
			'total_with_deps' => $with_deps,
			'max_depth'       => $max_depth,
			'chain_count'     => count( $chains ),
			'chains'          => array_slice( $chains, 0, 100 ), // Cap preview at 100.
		);
	}

	// ------------------------------------------------------------------
	// Rollback Metadata
	// ------------------------------------------------------------------

	/**
	 * Build rollback strategy metadata.
	 *
	 * Describes what rollback actions would be needed for each action
	 * type. No rollback implementation — metadata only.
	 *
	 * @param array $items         Plan items.
	 * @param array $action_counts Action type counts.
	 * @return array Rollback metadata.
	 */
	private static function build_rollback_metadata( $items, $action_counts ) {
		$strategies = array(
			'create' => array(
				'rollback_action'  => 'delete_user_and_affiliate',
				'description'      => __( 'Delete created WordPress user and KonX affiliate profile.', 'konx-affiliate-dashboard' ),
				'affected_tables'  => array( 'wp_users', 'wp_usermeta', 'wp_konx_affiliates' ),
				'estimated_count'  => $action_counts['create'],
				'reversible'       => true,
			),
			'link_wp' => array(
				'rollback_action'  => 'delete_affiliate_profile',
				'description'      => __( 'Delete KonX affiliate profile. WordPress user is preserved.', 'konx-affiliate-dashboard' ),
				'affected_tables'  => array( 'wp_konx_affiliates' ),
				'estimated_count'  => $action_counts['link_wp'],
				'reversible'       => true,
			),
			'link_ca' => array(
				'rollback_action'  => 'delete_affiliate_profile',
				'description'      => __( 'Delete KonX affiliate profile. WP user and Coupon Affiliate preserved.', 'konx-affiliate-dashboard' ),
				'affected_tables'  => array( 'wp_konx_affiliates' ),
				'estimated_count'  => $action_counts['link_ca'],
				'reversible'       => true,
			),
			'link_konx' => array(
				'rollback_action'  => 'revert_external_id',
				'description'      => __( 'Remove external_id from existing KonX affiliate. Profile preserved.', 'konx-affiliate-dashboard' ),
				'affected_tables'  => array( 'wp_konx_affiliates' ),
				'estimated_count'  => $action_counts['link_konx'],
				'reversible'       => true,
			),
		);

		$total_rollback = $action_counts['create'] + $action_counts['link_wp'] + $action_counts['link_ca'] + $action_counts['link_konx'];

		return array(
			'strategies'       => $strategies,
			'total_rollback'   => $total_rollback,
			'fully_reversible' => true,
			'requires_backup'  => ( $action_counts['create'] > 0 ),
			'note'             => __( 'Rollback implementation is not yet active. This is metadata only.', 'konx-affiliate-dashboard' ),
		);
	}

	/**
	 * Determine the rollback action for a given planned action.
	 *
	 * @param string $action Planned action.
	 * @return string|null Rollback action name or null if not applicable.
	 */
	private static function determine_rollback_action( $action ) {
		$map = array(
			'create'    => 'delete_user_and_affiliate',
			'link_wp'   => 'delete_affiliate_profile',
			'link_ca'   => 'delete_affiliate_profile',
			'link_konx' => 'revert_external_id',
		);

		return isset( $map[ $action ] ) ? $map[ $action ] : null;
	}

	// ------------------------------------------------------------------
	// Duration Estimation
	// ------------------------------------------------------------------

	/**
	 * Estimate execution duration.
	 *
	 * @param int $actionable_count Number of actionable records.
	 * @param int $batch_size       Batch size.
	 * @return array { seconds, formatted, batches, per_batch_seconds }.
	 */
	private static function estimate_duration( $actionable_count, $batch_size ) {
		$total_seconds   = (int) ceil( $actionable_count * self::SECONDS_PER_RECORD );
		$batch_count     = (int) ceil( $actionable_count / max( 1, $batch_size ) );
		$per_batch       = (int) ceil( $batch_size * self::SECONDS_PER_RECORD );

		// Add 2 seconds overhead per batch for AJAX round-trip.
		$total_seconds += $batch_count * 2;

		return array(
			'seconds'           => $total_seconds,
			'formatted'         => self::format_duration( $total_seconds ),
			'batches'           => $batch_count,
			'per_batch_seconds' => $per_batch + 2,
		);
	}

	/**
	 * Format seconds into human-readable duration.
	 *
	 * @param int $seconds Total seconds.
	 * @return string Formatted string (e.g., "2m 30s").
	 */
	private static function format_duration( $seconds ) {
		if ( $seconds < 60 ) {
			/* translators: %d: number of seconds */
			return sprintf( __( '%ds', 'konx-affiliate-dashboard' ), $seconds );
		}

		$minutes = (int) floor( $seconds / 60 );
		$secs    = $seconds % 60;

		if ( $secs > 0 ) {
			/* translators: 1: minutes, 2: seconds */
			return sprintf( __( '%1$dm %2$ds', 'konx-affiliate-dashboard' ), $minutes, $secs );
		}

		/* translators: %d: number of minutes */
		return sprintf( __( '%dm', 'konx-affiliate-dashboard' ), $minutes );
	}

	// ------------------------------------------------------------------
	// Empty Plan
	// ------------------------------------------------------------------

	/**
	 * Return an empty plan structure.
	 *
	 * @return array Empty plan with all required keys.
	 */
	private static function empty_plan() {
		return array(
			'plan_id'        => null,
			'created_at'     => gmdate( 'c' ),
			'summary'        => array(
				'create'    => 0,
				'link_wp'   => 0,
				'link_ca'   => 0,
				'link_konx' => 0,
				'skip'      => 0,
				'invalid'   => 0,
			),
			'total_actions'  => 0,
			'total_skipped'  => 0,
			'phases'         => array(),
			'batches'        => array(),
			'dependencies'   => array( 'total_with_deps' => 0, 'max_depth' => 0, 'chain_count' => 0, 'chains' => array() ),
			'rollback_meta'  => array( 'strategies' => array(), 'total_rollback' => 0, 'fully_reversible' => true, 'requires_backup' => false ),
			'duration'       => array( 'seconds' => 0, 'formatted' => '0s', 'batches' => 0, 'per_batch_seconds' => 0 ),
			'items'          => array(),
		);
	}
}
