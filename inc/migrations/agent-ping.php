<?php
/**
 * Data Machine — Agent ping migration.
 *
 * Migrates historical agent_ping config to agent_call system tasks in
 * flow configs and pipeline configs.
 *
 * @package DataMachine
 * @since 0.60.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build canonical agent_call handler config from historical agent_ping params.
 *
 * @param array $old_config Historical agent_ping config.
 * @return array Canonical system_task handler_config.
 */
function datamachine_agent_call_config_from_legacy_ping( array $old_config ): array {
	return array(
		'task'   => 'agent_call',
		'params' => array(
			'target'   => array(
				'type' => 'webhook',
				'id'   => $old_config['webhook_url'] ?? '',
				'auth' => array(
					'header_name' => $old_config['auth_header_name'] ?? '',
					'token'       => $old_config['auth_token'] ?? '',
				),
			),
			'input'    => array(
				'task'     => $old_config['prompt'] ?? '',
				'messages' => array(),
				'context'  => array(),
			),
			'delivery' => array(
				'mode'     => 'fire_and_forget',
				'timeout'  => 30,
				'reply_to' => $old_config['reply_to'] ?? '',
			),
		),
	);
}

/**
 * Migrate agent_ping step types to agent_call system_task steps in flow configs.
 *
 * Converts existing agent_ping steps to system_task steps with
 * task: 'agent_call' in handler_config. Preserves webhook_url, prompt,
 * auth settings, queue_enabled, and prompt_queue.
 *
 * Idempotent: guarded by datamachine_agent_ping_migrated option.
 *
 * @since 0.60.0
 */
function datamachine_migrate_agent_ping_to_system_task(): void {
	if ( get_option( 'datamachine_agent_ping_migrated', false ) ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'datamachine_flows';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	// phpcs:enable WordPress.DB.PreparedSQL
	if ( ! $table_exists ) {
		update_option( 'datamachine_agent_ping_migrated', true, true );
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$rows = $wpdb->get_results( "SELECT flow_id, flow_config FROM {$table}", ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL
	if ( empty( $rows ) ) {
		update_option( 'datamachine_agent_ping_migrated', true, true );
		return;
	}

	$migrated = 0;

	foreach ( $rows as $row ) {
		$flow_config = json_decode( $row['flow_config'], true );
		if ( ! is_array( $flow_config ) ) {
			continue;
		}

		$changed = false;
		foreach ( $flow_config as $step_id => &$step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			// Only convert steps with agent_ping step type.
			if ( 'agent_ping' !== ( $step['step_type'] ?? '' ) ) {
				continue;
			}

			// Extract existing agent_ping handler config.
			$old_config = $step['handler_configs']['agent_ping'] ?? array();

			// Build new system_task handler_config.
			$new_config = datamachine_agent_call_config_from_legacy_ping( $old_config );

			// Convert step type and handler references.
			$step['step_type']      = 'system_task';
			$step['handler_config'] = $new_config;
			unset( $step['handler_slug'], $step['handler_slugs'], $step['handler_configs'] );

			// queue_enabled and prompt_queue stay at their existing positions
			// in the step config — no changes needed, they're already there.

			$changed = true;
		}
		unset( $step );

		if ( $changed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'flow_config' => wp_json_encode( $flow_config ) ),
				array( 'flow_id' => $row['flow_id'] ),
				array( '%s' ),
				array( '%d' )
			);
			++$migrated;
		}
	}

	update_option( 'datamachine_agent_ping_migrated', true, true );

	if ( $migrated > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Migrated agent_ping steps to agent_call system_task steps in flow configs',
			array( 'flows_updated' => $migrated )
		);
	}
}

/**
 * Migrate agent_ping step types to agent_call system_task steps in pipeline configs.
 *
 * Follow-up to datamachine_migrate_agent_ping_to_system_task() which only
 * migrated flow configs. Pipeline configs were missed, leaving orphaned
 * agent_ping steps in the pipeline UI.
 *
 * Idempotent: guarded by datamachine_agent_ping_pipeline_migrated option.
 *
 * @since 0.73.0
 */
function datamachine_migrate_agent_ping_pipeline_to_system_task(): void {
	if ( get_option( 'datamachine_agent_ping_pipeline_migrated', false ) ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'datamachine_pipelines';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	// phpcs:enable WordPress.DB.PreparedSQL
	if ( ! $table_exists ) {
		update_option( 'datamachine_agent_ping_pipeline_migrated', true, true );
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$rows = $wpdb->get_results( "SELECT pipeline_id, pipeline_config FROM {$table}", ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL
	if ( empty( $rows ) ) {
		update_option( 'datamachine_agent_ping_pipeline_migrated', true, true );
		return;
	}

	$migrated = 0;

	foreach ( $rows as $row ) {
		$pipeline_config = json_decode( $row['pipeline_config'], true );
		if ( ! is_array( $pipeline_config ) ) {
			continue;
		}

		$changed = false;
		foreach ( $pipeline_config as $step_id => &$step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			// Only convert steps with agent_ping step type.
			if ( 'agent_ping' !== ( $step['step_type'] ?? '' ) ) {
				continue;
			}

			// Extract existing agent_ping handler config (if present).
			$old_config = $step['handler_configs']['agent_ping'] ?? array();

			// Build new system_task handler_config.
			$new_config = datamachine_agent_call_config_from_legacy_ping( $old_config );

			// Convert step type and handler references.
			$step['step_type']      = 'system_task';
			$step['handler_config'] = $new_config;
			unset( $step['handler_slug'], $step['handler_slugs'], $step['handler_configs'] );

			$changed = true;
		}
		unset( $step );

		if ( $changed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'pipeline_config' => wp_json_encode( $pipeline_config ) ),
				array( 'pipeline_id' => $row['pipeline_id'] ),
				array( '%s' ),
				array( '%d' )
			);
			++$migrated;
		}
	}

	update_option( 'datamachine_agent_ping_pipeline_migrated', true, true );

	if ( $migrated > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Migrated agent_ping steps to agent_call system_task steps in pipeline configs',
			array( 'pipelines_updated' => $migrated )
		);
	}
}

/**
 * Migrate already-system-task agent_ping configs to agent_call configs.
 *
 * This catches installs that already ran the older agent_ping→system_task
 * migration before agent_call became the canonical task vocabulary.
 *
 * Idempotent: guarded by datamachine_agent_ping_task_to_agent_call_migrated option.
 *
 * @since 0.87.0
 */
function datamachine_migrate_agent_ping_task_to_agent_call(): void {
	if ( get_option( 'datamachine_agent_ping_task_to_agent_call_migrated', false ) ) {
		return;
	}

	global $wpdb;
	$tables = array(
		array( $wpdb->prefix . 'datamachine_flows', 'flow_id', 'flow_config', 'flows_updated' ),
		array( $wpdb->prefix . 'datamachine_pipelines', 'pipeline_id', 'pipeline_config', 'pipelines_updated' ),
	);

	$updated = array();
	foreach ( $tables as $table_def ) {
		list( $table, $id_column, $config_column, $metric ) = $table_def;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		// phpcs:enable WordPress.DB.PreparedSQL
		if ( ! $table_exists ) {
			$updated[ $metric ] = 0;
			continue;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table/column names are constants from this migration.
		$rows = $wpdb->get_results( "SELECT {$id_column}, {$config_column} FROM {$table}", ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL

		$count = 0;
		foreach ( $rows as $row ) {
			$config = json_decode( $row[ $config_column ], true );
			if ( ! is_array( $config ) ) {
				continue;
			}

			$changed = false;
			foreach ( $config as &$step ) {
				if ( ! is_array( $step ) || 'system_task' !== ( $step['step_type'] ?? '' ) ) {
					continue;
				}

				$handler_config = $step['handler_config'] ?? array();
				if ( ! is_array( $handler_config ) || 'agent_ping' !== ( $handler_config['task'] ?? '' ) ) {
					continue;
				}

				$old_params              = is_array( $handler_config['params'] ?? null ) ? $handler_config['params'] : array();
				$step['handler_config'] = datamachine_agent_call_config_from_legacy_ping( $old_params );
				$changed                = true;
			}
			unset( $step );

			if ( ! $changed ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( $config_column => wp_json_encode( $config ) ),
				array( $id_column => $row[ $id_column ] ),
				array( '%s' ),
				array( '%d' )
			);
			++$count;
		}

		$updated[ $metric ] = $count;
	}

	update_option( 'datamachine_agent_ping_task_to_agent_call_migrated', true, true );

	if ( array_sum( $updated ) > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Migrated agent_ping system_task configs to agent_call configs',
			$updated
		);
	}
}
