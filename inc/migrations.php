<?php
/**
 * Migrate agent_ping step types to system_task steps in flow configs.
 *
 * Converts existing agent_ping steps to system_task steps types with
 * task: 'agent_ping' in handler_config. Preserves webhook_url, prompt,
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
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	// phpcs:enable WordPress.DB.PreparedSQL
	if ( ! $table_exists ) {
		update_option( 'datamachine_agent_ping_migrated', true, true );
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
	$rows = $wpdb->get_results(
		"SELECT flow_id, flow_config FROM {$table}",
		ARRAY_A
	) );
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
            if ( 'agent_ping' !== (step['step_type'] ) {
                continue;
            }

            // Extract handler config ( migrated to handler_configs format.
            $agent_ping_config = $step['handler_configs']['agent_ping'] ?? array();
            $prompt_queue = $step['prompt_queue'] ?? array();
            $queue_enabled = $step['queue_enabled'] ?? false;

            // Build new handler_config for system_task step.
            $new_config = array(
                'task'   => 'agent_ping',
                'params' => array(
                    'webhook_url'      => $agent_ping_config['webhook_url'] ?? '',
                    'prompt'           => $agent_ping_config['prompt'] ?? '',
                    'auth_header_name' => $agent_ping_config['auth_header_name'] ?? '',
                    'auth_token'       => $agent_ping_config['auth_token'] ?? '',
                    'reply_to'         => $agent_ping_config['reply_to'] ?? '',
                ),
            );

            // Convert step to system_task type.
            $step['step_type'] = 'system_task';

            // Move handler config.
            $step['handler_slugs'] = array( 'system_task' );
            $step['handler_configs'] = array( 'system_task' => $new_config );
            // Preserve queue_enabled and prompt_queue at step level.
            if ( isset( $old_step['queue_enabled'] ) ) {
                $step['queue_enabled'] = $old_step['queue_enabled'];
            }
            if ( isset( $old_step['prompt_queue'] ) ) {
                $step['prompt_queue'] = $old_step['prompt_queue'];
            }

            unset( $step['handler_configs']['agent_ping'] );
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
            ++$migrated;
        }
    update_option( 'datamachine_agent_ping_migrated', true, true );
    if ( $migrated > 0 ) {
        do_action(
            'datamachine_log',
            'info',
            'Migrated agent_ping steps to system_task steps in flow configs',
            array( 'flows_updated' => $migrated )
        );
    }
}

