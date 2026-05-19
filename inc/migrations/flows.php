<?php
/**
 * Data Machine — Flow activation helpers.
 *
 * Re-schedules flows with non-manual scheduling on plugin activation.
 *
 * @package DataMachine
 * @since 0.60.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize legacy scalar handler fields in a persisted flow config array.
 *
 * Runtime readers intentionally consume only the canonical plural handler
 * fields. This helper is for one deploy-time storage migration only: old flow
 * rows that predate the strict workflow contract need their stored JSON moved
 * to the canonical shape before runtime fallback disappears.
 *
 * @param array $flow_config Flow configuration decoded from storage.
 * @return array Normalized flow configuration.
 */
function datamachine_normalize_stored_flow_legacy_handler_fields( array $flow_config ): array {
	foreach ( $flow_config as $flow_step_id => $step_config ) {
		if ( ! is_array( $step_config ) || ! isset( $step_config['step_type'] ) ) {
			continue;
		}

		$uses_handler = \DataMachine\Core\Steps\FlowStepConfig::usesHandler( $step_config );
		$legacy_slug  = '';
		foreach ( array( 'handler_slug', 'handler' ) as $legacy_slug_key ) {
			if ( isset( $step_config[ $legacy_slug_key ] ) && is_string( $step_config[ $legacy_slug_key ] ) && '' !== $step_config[ $legacy_slug_key ] ) {
				$legacy_slug = $step_config[ $legacy_slug_key ];
				break;
			}
		}

		$legacy_config = is_array( $step_config['handler_config'] ?? null ) ? $step_config['handler_config'] : array();

		unset( $step_config['handler'], $step_config['handler_slug'], $step_config['handler_config'] );

		if ( ! $uses_handler ) {
			unset( $step_config['handler_slugs'], $step_config['handler_configs'] );
			$flow_config[ $flow_step_id ] = $step_config;
			continue;
		}

		$handler_slugs = datamachine_sanitize_stored_flow_handler_slugs(
			is_array( $step_config['handler_slugs'] ?? null ) ? $step_config['handler_slugs'] : array()
		);

		if ( empty( $handler_slugs ) && '' !== $legacy_slug ) {
			$handler_slugs = array( $legacy_slug );
		}

		$handler_configs = is_array( $step_config['handler_configs'] ?? null ) ? $step_config['handler_configs'] : array();
		if ( ! empty( $legacy_config ) ) {
			$config_slug = $handler_slugs[0] ?? $legacy_slug;
			if ( '' !== $config_slug && ! array_key_exists( $config_slug, $handler_configs ) ) {
				$handler_configs[ $config_slug ] = $legacy_config;
			}
		}

		unset( $step_config['handler_slugs'], $step_config['handler_configs'] );

		if ( ! empty( $handler_slugs ) ) {
			$step_config['handler_slugs'] = $handler_slugs;
		}

		$handler_configs = array_filter( $handler_configs, 'is_array' );
		if ( ! empty( $handler_configs ) ) {
			$step_config['handler_configs'] = $handler_configs;
		}

		$flow_config[ $flow_step_id ] = $step_config;
	}

	return $flow_config;
}

/**
 * Normalize and de-duplicate handler slugs from stored JSON.
 *
 * @param array $slugs Raw slug values.
 * @return array<int, string> Clean slug list.
 */
function datamachine_sanitize_stored_flow_handler_slugs( array $slugs ): array {
	$clean = array();
	foreach ( $slugs as $slug ) {
		if ( ! is_string( $slug ) || '' === $slug ) {
			continue;
		}
		if ( ! in_array( $slug, $clean, true ) ) {
			$clean[] = $slug;
		}
	}
	return $clean;
}

/**
 * Migrate stored flow_config JSON away from legacy scalar handler fields.
 *
 * @return void
 */
function datamachine_migrate_stored_flow_legacy_handler_fields(): void {
	global $wpdb;

	$table_name = $wpdb->prefix . 'datamachine_flows';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
	$flows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT flow_id, flow_config FROM {$table_name}
			WHERE flow_config LIKE %s
			OR flow_config LIKE %s
			OR flow_config LIKE %s",
			'%' . $wpdb->esc_like( '"handler_slug"' ) . '%',
			'%' . $wpdb->esc_like( '"handler_config"' ) . '%',
			'%' . $wpdb->esc_like( '"handler"' ) . '%'
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL

	if ( empty( $flows ) ) {
		return;
	}

	$updated = 0;
	$failed  = 0;
	$invalid = 0;

	foreach ( $flows as $flow ) {
		$flow_id     = (int) $flow['flow_id'];
		$raw_config  = (string) $flow['flow_config'];
		$flow_config = json_decode( $raw_config, true );

		if ( ! is_array( $flow_config ) ) {
			++$invalid;
			continue;
		}

		$normalized = datamachine_normalize_stored_flow_legacy_handler_fields( $flow_config );
		$encoded    = wp_json_encode( $normalized );

		if ( false === $encoded || $encoded === $raw_config ) {
			continue;
		}

		$result = $wpdb->update(
			$table_name,
			array( 'flow_config' => $encoded ),
			array( 'flow_id' => $flow_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			++$failed;
			continue;
		}

		++$updated;
	}

	do_action(
		'datamachine_log',
		$failed > 0 || $invalid > 0 ? 'warning' : 'info',
		'Migrated stored flow legacy handler fields',
		array(
			'scanned' => count( $flows ),
			'updated' => $updated,
			'failed'  => $failed,
			'invalid' => $invalid,
		)
	);
}

/**
 * Re-schedule all flows with non-manual scheduling on plugin activation.
 *
 * Ensures scheduled flows resume after plugin reactivation.
 */
function datamachine_activate_scheduled_flows() {
	if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
		return;
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'datamachine_flows';

	// Check if table exists (fresh install won't have flows yet)
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$flows = $wpdb->get_results( $wpdb->prepare( 'SELECT flow_id, scheduling_config FROM %i', $table_name ), ARRAY_A );

	if ( empty( $flows ) ) {
		return;
	}

	$scheduled_count = 0;

	foreach ( $flows as $flow ) {
		$flow_id           = (int) $flow['flow_id'];
		$scheduling_config = json_decode( $flow['scheduling_config'], true );

		if ( empty( $scheduling_config ) || empty( $scheduling_config['interval'] ) ) {
			continue;
		}

		$interval = $scheduling_config['interval'];

		if ( 'manual' === $interval ) {
			continue;
		}

		// Delegate to FlowScheduling — single source of truth for scheduling
		// logic including stagger offsets, interval validation, and AS registration.
		$result = \DataMachine\Api\Flows\FlowScheduling::handle_scheduling_update(
			$flow_id,
			$scheduling_config
		);

		if ( ! is_wp_error( $result ) ) {
			++$scheduled_count;
		}
	}

	if ( $scheduled_count > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Flows re-scheduled on plugin activation',
			array(
				'scheduled_count' => $scheduled_count,
			)
		);
	}
}
