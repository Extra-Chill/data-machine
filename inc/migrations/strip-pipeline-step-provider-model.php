<?php
/**
 * Data Machine — Strip dead `provider`/`model` keys from pipeline_config.
 *
 * Older REST + UI versions persisted `provider` and `model` on each AI step
 * inside pipeline_config. AIStep never read them — model/provider are resolved
 * exclusively through the mode system (PluginSettings::resolveModelForAgentMode).
 *
 * The surface (REST schema, React cache, FlowStepCard display) has been
 * removed, but existing pipelines still carry the stale keys and will keep
 * showing them in `GET /pipelines` responses and CLI inspections until
 * scrubbed. This one-shot migration removes them so inspection matches
 * execution.
 *
 * See: https://github.com/Extra-Chill/data-machine/issues/1180
 *
 * @package DataMachine
 * @since 0.71.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Strip `provider` and `model` keys from every step entry in every
 * pipeline_config row. Idempotent: guarded by the
 * `datamachine_pipeline_step_provider_model_stripped` option.
 *
 * @since 0.71.0
 */
function datamachine_strip_pipeline_step_provider_model(): void {
	if ( get_option( 'datamachine_pipeline_step_provider_model_stripped', false ) ) {
		return;
	}

	global $wpdb;
	$pipelines_table = $wpdb->prefix . 'datamachine_pipelines';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pipelines_table ) );
	if ( ! $table_exists ) {
		update_option( 'datamachine_pipeline_step_provider_model_stripped', true, true );
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL
	$rows = $wpdb->get_results( "SELECT pipeline_id, pipeline_config FROM {$pipelines_table}", ARRAY_A );

	$migrated = 0;

	if ( ! empty( $rows ) ) {
		foreach ( $rows as $row ) {
			$config = json_decode( $row['pipeline_config'] ?? '', true );
			if ( ! is_array( $config ) ) {
				continue;
			}

			$changed = false;

			foreach ( $config as $step_id => &$step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				if ( array_key_exists( 'provider', $step ) || array_key_exists( 'model', $step ) ) {
					unset( $step['provider'], $step['model'] );
					$changed = true;
				}
			}
			unset( $step );

			if ( $changed ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$pipelines_table,
					array( 'pipeline_config' => wp_json_encode( $config ) ),
					array( 'pipeline_id' => $row['pipeline_id'] ),
					array( '%s' ),
					array( '%d' )
				);
				++$migrated;
			}
		}
	}

	update_option( 'datamachine_pipeline_step_provider_model_stripped', true, true );

	if ( $migrated > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Stripped dead `provider`/`model` keys from pipeline_config (see data-machine#1180)',
			array(
				'pipelines_updated' => $migrated,
			)
		);
	}
}
