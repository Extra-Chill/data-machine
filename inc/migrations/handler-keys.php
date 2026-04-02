<?php
/**
 * Data Machine — Handler keys migration.
 *
 * Migrates flow_config JSON from legacy singular handler keys to plural.
 *
 * @package DataMachine
 * @since 0.60.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Migrate flow_config JSON from legacy singular handler keys to plural.
 *
 * Converts handler_slug → handler_slugs and handler_config → handler_configs
 * in every step of every flow's flow_config JSON. Idempotent: skips rows
 * that already use plural keys.
 *
 * @since 0.39.0
 */
function datamachine_migrate_handler_keys_to_plural() {
	$already_done = get_option( 'datamachine_handler_keys_migrated', false );
	if ( $already_done ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'datamachine_flows';

	// Check table exists (fresh installs won't have legacy data).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	// phpcs:enable WordPress.DB.PreparedSQL
	if ( ! $table_exists ) {
		update_option( 'datamachine_handler_keys_migrated', true, true );
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$rows = $wpdb->get_results( "SELECT flow_id, flow_config FROM {$table}", ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL

	if ( empty( $rows ) ) {
		update_option( 'datamachine_handler_keys_migrated', true, true );
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

			// Skip flow-level metadata keys.
			if ( 'memory_files' === $step_id ) {
				continue;
			}

			// Already has plural keys — check if singular leftovers need cleanup.
			if ( isset( $step['handler_slugs'] ) && is_array( $step['handler_slugs'] ) ) {
				// Ensure handler_configs exists when handler_slugs does.
				if ( ! isset( $step['handler_configs'] ) || ! is_array( $step['handler_configs'] ) ) {
					$primary                 = $step['handler_slugs'][0] ?? '';
					$config                  = $step['handler_config'] ?? array();
					$step['handler_configs'] = ! empty( $primary ) ? array( $primary => $config ) : array();
					$changed                 = true;
				}
				// Remove any leftover singular keys.
				if ( isset( $step['handler_slug'] ) ) {
					unset( $step['handler_slug'] );
					$changed = true;
				}
				if ( isset( $step['handler_config'] ) ) {
					unset( $step['handler_config'] );
					$changed = true;
				}
				continue;
			}

			// Convert singular to plural.
			$slug   = $step['handler_slug'] ?? '';
			$config = $step['handler_config'] ?? array();

			if ( ! empty( $slug ) ) {
				$step['handler_slugs']   = array( $slug );
				$step['handler_configs'] = array( $slug => $config );
			} else {
				// Self-configuring steps (agent_ping, webhook_gate, system_task).
				$step_type = $step['step_type'] ?? '';
				if ( ! empty( $step_type ) && ! empty( $config ) ) {
					$step['handler_slugs']   = array( $step_type );
					$step['handler_configs'] = array( $step_type => $config );
				} else {
					$step['handler_slugs']   = array();
					$step['handler_configs'] = array();
				}
			}

			unset( $step['handler_slug'], $step['handler_config'] );
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

	update_option( 'datamachine_handler_keys_migrated', true, true );

	if ( $migrated > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Migrated flow_config handler keys from singular to plural',
			array( 'flows_updated' => $migrated )
		);
	}
}
