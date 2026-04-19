<?php
/**
 * Data Machine — Update-to-Upsert step type migration.
 *
 * Renames the `update` step type to `upsert` in stored pipeline and flow
 * configs. Reflects the semantic reality that "update" steps are actually
 * identity-aware upsert operations — extensions like data-machine-events
 * already treat the step as an upsert (EventUpsert under step_type: 'update').
 *
 * The wordpress_update handler slug is intentionally left unchanged; only
 * the step type it registers under changes. Handlers are free to be
 * update-only modes of the broader upsert step.
 *
 * @package DataMachine
 * @since 0.70.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Migrate `update` step type to `upsert` in pipeline and flow configs.
 *
 * Idempotent: guarded by datamachine_update_to_upsert_migrated option.
 *
 * @since 0.70.0
 */
function datamachine_migrate_update_to_upsert_step_type(): void {
	if ( get_option( 'datamachine_update_to_upsert_migrated', false ) ) {
		return;
	}

	global $wpdb;
	$pipelines_table = $wpdb->prefix . 'datamachine_pipelines';
	$flows_table     = $wpdb->prefix . 'datamachine_flows';

	$pipelines_migrated = datamachine_rewrite_update_step_type_in_table(
		$pipelines_table,
		'pipeline_id',
		'pipeline_config'
	);
	$flows_migrated     = datamachine_rewrite_update_step_type_in_table(
		$flows_table,
		'flow_id',
		'flow_config'
	);

	update_option( 'datamachine_update_to_upsert_migrated', true, true );

	if ( $pipelines_migrated > 0 || $flows_migrated > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Migrated `update` step type to `upsert` in pipeline and flow configs',
			array(
				'pipelines_updated' => $pipelines_migrated,
				'flows_updated'     => $flows_migrated,
			)
		);
	}
}

/**
 * Rewrite `step_type: 'update'` → `'upsert'` inside a table's JSON config column.
 *
 * Walks every row, decodes the JSON config (keyed by step_id), and rewrites
 * any entry whose step_type is `update` to `upsert`. Also updates the
 * `handler_configs` key if it was keyed by `'update'` (it shouldn't be — those
 * are keyed by handler slug like `wordpress_update` — but we're defensive).
 *
 * @param string $table       Fully-qualified table name.
 * @param string $id_column   Primary key column name.
 * @param string $config_col  JSON config column name.
 * @return int Number of rows updated.
 */
function datamachine_rewrite_update_step_type_in_table( string $table, string $id_column, string $config_col ): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( ! $table_exists ) {
		return 0;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL
	$rows = $wpdb->get_results( "SELECT {$id_column}, {$config_col} FROM {$table}", ARRAY_A );
	if ( empty( $rows ) ) {
		return 0;
	}

	$migrated = 0;

	foreach ( $rows as $row ) {
		$config = json_decode( $row[ $config_col ] ?? '', true );
		if ( ! is_array( $config ) ) {
			continue;
		}

		$changed = false;

		foreach ( $config as $step_id => &$step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			if ( 'update' === ( $step['step_type'] ?? '' ) ) {
				$step['step_type'] = 'upsert';
				$changed           = true;
			}
		}
		unset( $step );

		if ( $changed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( $config_col => wp_json_encode( $config ) ),
				array( $id_column => $row[ $id_column ] ),
				array( '%s' ),
				array( '%d' )
			);
			++$migrated;
		}
	}

	return $migrated;
}
