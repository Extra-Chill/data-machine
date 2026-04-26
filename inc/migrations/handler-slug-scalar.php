<?php
/**
 * Data Machine — Handler slug scalar migration.
 *
 * Collapses handler storage to match step-type cardinality:
 * handler-free steps carry no handler slug field, single-handler steps use
 * handler_slug/handler_config, and multi-handler steps keep
 * handler_slugs/handler_configs.
 *
 * @package DataMachine
 * @since 0.85.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Migrate persisted step configs to the canonical handler shape.
 *
 * @return void
 */
function datamachine_migrate_handler_slug_scalar(): void {
	if ( get_option( 'datamachine_handler_slug_scalar_migrated', false ) ) {
		return;
	}

	global $wpdb;

	$flows_updated     = datamachine_migrate_handler_slug_scalar_table( $wpdb->prefix . 'datamachine_flows', 'flow_id', 'flow_config' );
	$pipelines_updated = datamachine_migrate_handler_slug_scalar_table( $wpdb->prefix . 'datamachine_pipelines', 'pipeline_id', 'pipeline_config' );

	update_option( 'datamachine_handler_slug_scalar_migrated', true, true );

	if ( $flows_updated > 0 || $pipelines_updated > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Migrated handler slug storage to canonical scalar/list shape',
			array(
				'flows_updated'     => $flows_updated,
				'pipelines_updated' => $pipelines_updated,
			)
		);
	}
}

/**
 * Migrate one config-bearing database table.
 *
 * @param string $table Table name.
 * @param string $id_column Primary ID column.
 * @param string $config_column JSON config column.
 * @return int Rows updated.
 */
function datamachine_migrate_handler_slug_scalar_table( string $table, string $id_column, string $config_column ): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	// phpcs:enable WordPress.DB.PreparedSQL
	if ( ! $table_exists ) {
		return 0;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:disable WordPress.DB.PreparedSQL -- Table/column names are internal constants from this migration.
	$rows = $wpdb->get_results( "SELECT {$id_column}, {$config_column} FROM {$table}", ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL
	if ( empty( $rows ) ) {
		return 0;
	}

	$updated = 0;
	foreach ( $rows as $row ) {
		$config = json_decode( $row[ $config_column ], true );
		if ( ! is_array( $config ) ) {
			continue;
		}

		$changed = false;
		foreach ( $config as $step_id => &$step ) {
			if ( ! is_array( $step ) || 'memory_files' === $step_id ) {
				continue;
			}

			$normalized = \DataMachine\Core\Steps\FlowStepConfig::normalizeHandlerShape( $step );
			if ( $normalized !== $step ) {
				$step    = $normalized;
				$changed = true;
			}
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
		++$updated;
	}

	return $updated;
}
