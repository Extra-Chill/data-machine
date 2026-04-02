<?php
/**
 * Data Machine — Agent ID backfill and orphaned resource assignment.
 *
 * Backfills agent_id on pipelines, flows, and jobs from user_id → owner_id
 * mapping. Assigns orphaned resources to the sole agent on single-agent installs.
 *
 * @package DataMachine
 * @since 0.60.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Backfill agent_id on pipelines, flows, and jobs from user_id → owner_id mapping.
 *
 * For existing rows that have user_id > 0 but no agent_id, looks up the agent
 * via Agents::get_by_owner_id() and sets agent_id. Also bootstraps agent_access
 * rows so owners have admin access to their agents.
 *
 * Idempotent: only processes rows where agent_id IS NULL and user_id > 0.
 * Skipped entirely on fresh installs (no rows to backfill).
 *
 * @since 0.41.0
 */
function datamachine_backfill_agent_ids(): void {
	if ( get_option( 'datamachine_agent_ids_backfilled', false ) ) {
		return;
	}

	global $wpdb;

	$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
	$access_repo = new \DataMachine\Core\Database\Agents\AgentAccess();

	$tables = array(
		$wpdb->prefix . 'datamachine_pipelines',
		$wpdb->prefix . 'datamachine_flows',
		$wpdb->prefix . 'datamachine_jobs',
	);

	// Cache of user_id → agent_id to avoid repeated lookups.
	$agent_map  = array();
	$backfilled = 0;

	foreach ( $tables as $table ) {
		// Check table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			continue;
		}

		// Check agent_id column exists (migration may not have run yet).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'agent_id'",
				DB_NAME,
				$table
			)
		);
		if ( null === $col ) {
			continue;
		}

		// Get distinct user_ids that need backfill.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$user_ids = $wpdb->get_col(
			"SELECT DISTINCT user_id FROM {$table} WHERE user_id > 0 AND agent_id IS NULL"
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( empty( $user_ids ) ) {
			continue;
		}

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;

			if ( ! isset( $agent_map[ $user_id ] ) ) {
				$agent = $agents_repo->get_by_owner_id( $user_id );
				if ( $agent ) {
					$agent_map[ $user_id ] = (int) $agent['agent_id'];

					// Bootstrap agent_access for owner.
					$access_repo->bootstrap_owner_access( (int) $agent['agent_id'], $user_id );
				} else {
					// Try to create agent for this user.
					$created_id            = datamachine_resolve_or_create_agent_id( $user_id );
					$agent_map[ $user_id ] = $created_id;

					if ( $created_id > 0 ) {
						$access_repo->bootstrap_owner_access( $created_id, $user_id );
					}
				}
			}

			$agent_id = $agent_map[ $user_id ];
			if ( $agent_id <= 0 ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET agent_id = %d WHERE user_id = %d AND agent_id IS NULL",
					$agent_id,
					$user_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false !== $updated ) {
				$backfilled += $updated;
			}
		}
	}

	update_option( 'datamachine_agent_ids_backfilled', true, true );

	if ( $backfilled > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Backfilled agent_id on existing pipelines, flows, and jobs',
			array(
				'rows_updated' => $backfilled,
				'agent_map'    => $agent_map,
			)
		);
	}
}

/**
 * Assign orphaned resources to the sole agent on single-agent installs.
 *
 * Handles the case where pipelines, flows, and jobs were created before
 * agent scoping existed (user_id=0, agent_id=NULL). If exactly one agent
 * exists, assigns all unowned resources to it.
 *
 * Idempotent: runs once per install, skipped if multi-agent (>1 agent).
 *
 * @since 0.41.0
 */
function datamachine_assign_orphaned_resources_to_sole_agent(): void {
	if ( get_option( 'datamachine_orphaned_resources_assigned', false ) ) {
		return;
	}

	global $wpdb;

	$agents_repo = new \DataMachine\Core\Database\Agents\Agents();

	// Only proceed for single-agent installs.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$agent_count = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->base_prefix . 'datamachine_agents' )
	);

	if ( 1 !== $agent_count ) {
		// 0 agents: nothing to assign to. >1 agents: ambiguous, skip.
		update_option( 'datamachine_orphaned_resources_assigned', true, true );
		return;
	}

	// Get the sole agent's ID.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$agent_id = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT agent_id FROM %i LIMIT 1', $wpdb->base_prefix . 'datamachine_agents' )
	);

	if ( $agent_id <= 0 ) {
		update_option( 'datamachine_orphaned_resources_assigned', true, true );
		return;
	}

	$tables = array(
		$wpdb->prefix . 'datamachine_pipelines',
		$wpdb->prefix . 'datamachine_flows',
		$wpdb->prefix . 'datamachine_jobs',
	);

	$total_assigned = 0;

	foreach ( $tables as $table ) {
		// Check table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			continue;
		}

		// Check agent_id column exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'agent_id'",
				DB_NAME,
				$table
			)
		);
		if ( null === $col ) {
			continue;
		}

		// Assign orphaned rows (agent_id IS NULL) to the sole agent.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET agent_id = %d WHERE agent_id IS NULL",
				$agent_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( false !== $updated ) {
			$total_assigned += $updated;
		}
	}

	update_option( 'datamachine_orphaned_resources_assigned', true, true );

	if ( $total_assigned > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Assigned orphaned resources to sole agent',
			array(
				'agent_id'     => $agent_id,
				'rows_updated' => $total_assigned,
			)
		);
	}
}
