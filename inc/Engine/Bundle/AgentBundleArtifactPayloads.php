<?php
/**
 * Shared bundle artifact payload builders.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Builds comparable pipeline and flow payloads for bundle lifecycle checks.
 */
final class AgentBundleArtifactPayloads {

	public static function pipeline_payload( array $pipeline, string $portable_slug ): array {
		return array(
			'portable_slug'   => $portable_slug,
			'pipeline_name'   => (string) ( $pipeline['pipeline_name'] ?? '' ),
			'pipeline_config' => is_array( $pipeline['pipeline_config'] ?? null ) ? $pipeline['pipeline_config'] : array(),
		);
	}

	public static function flow_payload( array $flow, string $portable_slug, ?array $installed_payload = null ): array {
		$scheduling       = is_array( $flow['scheduling_config'] ?? null ) ? $flow['scheduling_config'] : array();
		$run_artifacts    = self::flow_run_artifacts( $flow, $scheduling );
		$scheduling_policy = self::flow_scheduling_policy( $scheduling );

		$payload = array(
			'portable_slug'     => $portable_slug,
			'flow_name'         => (string) ( $flow['flow_name'] ?? '' ),
			'flow_config'       => self::flow_config_without_runtime_queues( is_array( $flow['flow_config'] ?? null ) ? $flow['flow_config'] : array() ),
			'scheduling_policy' => $scheduling_policy,
			'queue_policy'      => 'create_seed_upgrade_preserve_existing',
			'runtime_overlays'  => self::flow_runtime_overlays( $flow, $installed_payload ),
		);

		if ( ! empty( $run_artifacts ) ) {
			$payload['run_artifacts'] = $run_artifacts;
		}

		return $payload;
	}

	private static function flow_run_artifacts( array $flow, array $scheduling ): array {
		return BundleSchema::normalize_run_artifact_egress_policy( $flow['run_artifacts'] ?? $scheduling['run_artifacts'] ?? array() );
	}

	private static function flow_scheduling_policy( array $config ): string {
		$interval = (string) ( $config['interval'] ?? 'manual' );
		$enabled  = array_key_exists( 'enabled', $config ) ? false !== $config['enabled'] : 'manual' !== $interval;

		if ( 'manual' === $interval || ! $enabled ) {
			return 'create_paused_upgrade_preserve_existing';
		}

		return 'create_bundle_schedule_upgrade_preserve_existing';
	}

	private static function flow_config_without_runtime_queues( array $flow_config ): array {
		foreach ( $flow_config as &$step ) {
			if ( is_array( $step ) ) {
				unset( $step['prompt_queue'], $step['config_patch_queue'], $step['queue_mode'], $step['_queue_consume_revision'] );
				unset( $step['handler_config']['max_items'] );
				if ( empty( $step['handler_config'] ) ) {
					unset( $step['handler_config'] );
				}
				if ( is_array( $step['handler_configs'] ?? null ) ) {
					foreach ( $step['handler_configs'] as $handler_slug => &$handler_config ) {
						if ( is_array( $handler_config ) ) {
							unset( $handler_config['max_items'] );
							if ( empty( $handler_config ) ) {
								unset( $step['handler_configs'][ $handler_slug ] );
							}
						}
					}
					unset( $handler_config );
					if ( empty( $step['handler_configs'] ) ) {
						unset( $step['handler_configs'] );
					}
				}
			}
		}
		unset( $step );

		return $flow_config;
	}

	private static function flow_runtime_overlays( array $flow, ?array $installed_payload = null ): array {
		if ( is_array( $installed_payload['runtime_overlays'] ?? null ) ) {
			return $installed_payload['runtime_overlays'];
		}

		$overlays    = array();
		$flow_config = is_array( $flow['flow_config'] ?? null ) ? $flow['flow_config'] : array();
		$steps       = array();

		foreach ( $flow_config as $flow_step_id => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$step_overlay = array();
			foreach ( array( 'prompt_queue', 'config_patch_queue', 'queue_mode', '_queue_consume_revision' ) as $field ) {
				if ( array_key_exists( $field, $step ) ) {
					$step_overlay[ $field ] = $step[ $field ];
				}
			}
			if ( array_key_exists( 'max_items', $step['handler_config'] ?? array() ) ) {
				$step_overlay['handler_config'] = array( 'max_items' => $step['handler_config']['max_items'] );
			}
			if ( is_array( $step['handler_configs'] ?? null ) ) {
				foreach ( $step['handler_configs'] as $handler_slug => $handler_config ) {
					if ( is_array( $handler_config ) && array_key_exists( 'max_items', $handler_config ) ) {
						$step_overlay['handler_configs'][ (string) $handler_slug ] = array( 'max_items' => $handler_config['max_items'] );
					}
				}
			}

			if ( ! empty( $step_overlay ) ) {
				ksort( $step_overlay, SORT_STRING );
				$steps[ (string) $flow_step_id ] = $step_overlay;
			}
		}

		if ( ! empty( $steps ) ) {
			ksort( $steps, SORT_STRING );
			$overlays['steps'] = $steps;
		}

		$scheduling = is_array( $flow['scheduling_config'] ?? null ) ? $flow['scheduling_config'] : array();
		unset( $scheduling['last_run'], $scheduling['next_run'], $scheduling['run_count'], $scheduling['run_artifacts'] );
		if ( ! empty( $scheduling ) ) {
			ksort( $scheduling, SORT_STRING );
			$overlays['scheduling_config'] = $scheduling;
		}

		ksort( $overlays, SORT_STRING );
		return $overlays;
	}
}
