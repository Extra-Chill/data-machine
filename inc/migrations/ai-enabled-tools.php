<?php
/**
 * Data Machine — AI enabled_tools migration.
 *
 * One-shot conversion of legacy AI step rows. Pre-#1205 Phase 2b, AI
 * step tool lists were stored under flow_step_config['handler_slugs'].
 * After Phase 2b, handler_slugs is single-purpose (the step's handler)
 * and AI tools live in flow_step_config['enabled_tools'].
 *
 * This migration moves AI rows over and clears handler_slugs so the
 * field overload is gone for good. Idempotent: rows that already have
 * `enabled_tools` populated are skipped.
 *
 * @package DataMachine
 * @since 0.81.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Move AI step tools from handler_slugs to enabled_tools across every
 * flow's flow_config JSON.
 *
 * Idempotent: skips rows where the AI step already has enabled_tools
 * populated, or where handler_slugs is empty.
 *
 * @since 0.81.0
 */
function datamachine_migrate_ai_enabled_tools(): void {
	$already_done = get_option( 'datamachine_ai_enabled_tools_migrated', false );
	if ( $already_done ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'datamachine_flows';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	// phpcs:enable WordPress.DB.PreparedSQL
	if ( ! $table_exists ) {
		update_option( 'datamachine_ai_enabled_tools_migrated', true, true );
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$rows = $wpdb->get_results( "SELECT flow_id, flow_config FROM {$table}", ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL

	if ( empty( $rows ) ) {
		update_option( 'datamachine_ai_enabled_tools_migrated', true, true );
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

			if ( 'ai' !== ( $step['step_type'] ?? '' ) ) {
				continue;
			}

			// Already migrated.
			if ( ! empty( $step['enabled_tools'] ) && is_array( $step['enabled_tools'] ) ) {
				if ( ! empty( $step['handler_slugs'] ) ) {
					$step['handler_slugs'] = array();
					$changed               = true;
				}
				continue;
			}

			$legacy = $step['handler_slugs'] ?? array();
			if ( empty( $legacy ) || ! is_array( $legacy ) ) {
				// Nothing to migrate; ensure the field exists for shape consistency.
				if ( ! isset( $step['enabled_tools'] ) ) {
					$step['enabled_tools'] = array();
					$changed               = true;
				}
				continue;
			}

			$step['enabled_tools'] = array_values( $legacy );
			$step['handler_slugs'] = array();
			$changed               = true;
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

	update_option( 'datamachine_ai_enabled_tools_migrated', true, true );

	if ( $migrated > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Migrated AI step tools from handler_slugs to enabled_tools',
			array( 'flows_updated' => $migrated )
		);
	}
}
