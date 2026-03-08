<?php
/**
 * Flow Formatter Utility
 *
 * Centralized flow formatting for REST API and CLI/Chat contexts.
 * Provides consistent flow data representation across all interfaces.
 *
 * @package DataMachine\Core\Admin
 */

namespace DataMachine\Core\Admin;

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Core\Steps\FlowStepConfig;

defined( 'ABSPATH' ) || exit;

class FlowFormatter {

	/**
	 * Format a flow record with handler config and scheduling metadata.
	 *
	 * @param array      $flow       Flow data from database
	 * @param array|null $latest_job Latest job for this flow (optional, for batch efficiency)
	 * @return array Formatted flow data
	 */
	public static function format_flow_for_response( array $flow, ?array $latest_job = null ): array {
		$flow_config = $flow['flow_config'] ?? array();

		$handler_abilities = new HandlerAbilities();

		$settings_display_service = new \DataMachine\Core\Steps\Settings\SettingsDisplayService();

		foreach ( $flow_config as $flow_step_id => &$step_data ) {
			$step_type      = $step_data['step_type'] ?? '';
			$effective_slug = FlowStepConfig::getEffectiveSlug( $step_data );

			// Skip steps with no handler or step type
			if ( empty( $effective_slug ) ) {
				continue;
			}

			$step_data['settings_display'] = apply_filters(
				'datamachine_get_handler_settings_display',
				array(),
				$flow_step_id,
				$step_type
			);

			// Multi-handler: per-handler settings displays keyed by slug.
			$step_data['handler_settings_displays'] = $settings_display_service->getDisplaySettingsForHandlers(
				$flow_step_id,
				$step_type
			);

			// Apply defaults to the primary handler config.
			if ( ! empty( $effective_slug ) ) {
				$primary_config = $step_data['handler_configs'][ $effective_slug ] ?? array();
				$step_data['handler_configs'][ $effective_slug ] = $handler_abilities->applyDefaults(
					$effective_slug,
					$primary_config
				);
			}

			if ( ! empty( $step_data['settings_display'] ) && is_array( $step_data['settings_display'] ) ) {
				$display_parts                 = array_map(
					function ( $setting ) {
						return sprintf( '%s: %s', $setting['label'], $setting['display_value'] );
					},
					$step_data['settings_display']
				);
				$step_data['settings_summary'] = implode( ' | ', $display_parts );
			} else {
				$step_data['settings_summary'] = '';
			}
		}
		unset( $step_data );

		$scheduling_config = $flow['scheduling_config'] ?? array();
		$flow_id           = isset( $flow['flow_id'] ) ? (int) $flow['flow_id'] : null;

		$last_run_at     = $latest_job['created_at'] ?? null;
		$last_run_status = $latest_job['status'] ?? null;
		$is_running      = $latest_job && null === $latest_job['completed_at'];

		$next_run = self::get_next_run_time( $flow_id );

		return array(
			'flow_id'           => $flow_id,
			'flow_name'         => $flow['flow_name'] ?? '',
			'pipeline_id'       => isset( $flow['pipeline_id'] ) ? (int) $flow['pipeline_id'] : null,
			'flow_config'       => $flow_config,
			'scheduling_config' => $scheduling_config,
			'last_run'          => $last_run_at,
			'last_run_status'   => $last_run_status,
			'last_run_display'  => DateFormatter::format_for_display( $last_run_at),
			'is_running'        => $is_running,
			'next_run'          => $next_run,
			'next_run_display'  => DateFormatter::format_for_display( $next_run ),
		);
	}

	/**
	 * Determine next scheduled run time for a flow if Action Scheduler is available.
	 *
	 * @param int|null $flow_id Flow ID
	 * @return string|null MySQL datetime string or null
	 */
	private static function get_next_run_time( ?int $flow_id ): ?string {
		if ( ! $flow_id || ! function_exists( 'as_next_scheduled_action' ) ) {
			return null;
		}

		$next_timestamp = as_next_scheduled_action( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );

		return $next_timestamp ? wp_date( 'Y-m-d H:i:s', $next_timestamp, new \DateTimeZone( 'UTC' ) ) : null;
	}
}
