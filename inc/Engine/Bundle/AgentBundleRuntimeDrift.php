<?php
/**
 * Runtime drift detection for bundle-owned flows.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Compares queue and scheduling state that bundle upgrades preserve by default.
 */
final class AgentBundleRuntimeDrift {

	private const QUEUE_FIELDS = array( 'prompt_queue', 'config_patch_queue', 'queue_mode' );

	/**
	 * Build a machine-readable drift preview for one existing flow.
	 */
	public static function preview( string $artifact_id, array $current_flow, array $target_flow, string $decision = 'preserve_existing' ): ?array {
		$current_flow_config = is_array( $current_flow['flow_config'] ?? null ) ? $current_flow['flow_config'] : array();
		$target_flow_config  = is_array( $target_flow['flow_config'] ?? null ) ? $target_flow['flow_config'] : array();
		$current_schedule    = self::scheduling_preview( is_array( $current_flow['scheduling_config'] ?? null ) ? $current_flow['scheduling_config'] : array() );
		$target_schedule     = self::scheduling_preview( is_array( $target_flow['scheduling_config'] ?? null ) ? $target_flow['scheduling_config'] : array() );
		$step_diffs          = self::step_diffs( $current_flow_config, $target_flow_config );
		$scheduling_changed  = $current_schedule !== $target_schedule;

		if ( empty( $step_diffs ) && ! $scheduling_changed ) {
			return null;
		}

		return array(
			'artifact_key'  => 'flow:' . $artifact_id,
			'artifact_type' => 'flow',
			'artifact_id'   => $artifact_id,
			'reason'        => 'runtime_queue_drift',
			'summary'       => sprintf( 'flow %s: preserved runtime queue or scheduling differs from bundle seed', $artifact_id ),
			'decision'      => $decision,
			'queue_depth'   => array(
				'current' => self::queue_depth( $current_flow_config ),
				'target'  => self::queue_depth( $target_flow_config ),
			),
			'queue_mode'    => array(
				'current' => self::queue_modes( $current_flow_config ),
				'target'  => self::queue_modes( $target_flow_config ),
			),
			'scheduling'    => array(
				'current' => $current_schedule,
				'target'  => $target_schedule,
				'changed' => $scheduling_changed,
			),
			'steps'         => $step_diffs,
		);
	}

	/**
	 * Replace only bundle-owned runtime queue fields on matching flow steps.
	 */
	public static function replace_runtime_queue_fields( array $incoming_flow_config, array $target_flow_config ): array {
		foreach ( $incoming_flow_config as $flow_step_id => &$step ) {
			if ( ! is_array( $step ) || ! is_array( $target_flow_config[ $flow_step_id ] ?? null ) ) {
				continue;
			}
			foreach ( self::QUEUE_FIELDS as $field ) {
				if ( array_key_exists( $field, $target_flow_config[ $flow_step_id ] ) ) {
					$step[ $field ] = $target_flow_config[ $flow_step_id ][ $field ];
				} else {
					unset( $step[ $field ] );
				}
			}
			unset( $step['_queue_consume_revision'] );
		}
		unset( $step );

		return $incoming_flow_config;
	}

	private static function step_diffs( array $current_flow_config, array $target_flow_config ): array {
		$diffs = array();
		$ids   = array_unique( array_merge( array_keys( $current_flow_config ), array_keys( $target_flow_config ) ) );
		sort( $ids, SORT_STRING );

		foreach ( $ids as $flow_step_id ) {
			$current_step = is_array( $current_flow_config[ $flow_step_id ] ?? null ) ? $current_flow_config[ $flow_step_id ] : array();
			$target_step  = is_array( $target_flow_config[ $flow_step_id ] ?? null ) ? $target_flow_config[ $flow_step_id ] : array();
			$current      = self::step_runtime_preview( $current_step );
			$target       = self::step_runtime_preview( $target_step );

			if ( $current === $target ) {
				continue;
			}

			$diffs[] = array(
				'flow_step_id' => (string) $flow_step_id,
				'current'      => $current,
				'target'       => $target,
			);
		}

		return $diffs;
	}

	private static function step_runtime_preview( array $step ): array {
		return array(
			'queue_mode'               => (string) ( $step['queue_mode'] ?? '' ),
			'prompt_queue_depth'       => self::list_depth( $step['prompt_queue'] ?? array() ),
			'config_patch_queue_depth' => self::list_depth( $step['config_patch_queue'] ?? array() ),
		);
	}

	private static function queue_depth( array $flow_config ): array {
		$depth = array(
			'prompt_queue'       => 0,
			'config_patch_queue' => 0,
			'total'              => 0,
		);

		foreach ( $flow_config as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$depth['prompt_queue']       += self::list_depth( $step['prompt_queue'] ?? array() );
			$depth['config_patch_queue'] += self::list_depth( $step['config_patch_queue'] ?? array() );
		}

		$depth['total'] = $depth['prompt_queue'] + $depth['config_patch_queue'];
		return $depth;
	}

	private static function queue_modes( array $flow_config ): array {
		$modes = array();
		foreach ( $flow_config as $flow_step_id => $step ) {
			if ( is_array( $step ) && array_key_exists( 'queue_mode', $step ) ) {
				$modes[ (string) $flow_step_id ] = (string) $step['queue_mode'];
			}
		}
		ksort( $modes, SORT_STRING );
		return $modes;
	}

	private static function scheduling_preview( array $scheduling ): array {
		return array(
			'enabled'   => (bool) ( $scheduling['enabled'] ?? false ),
			'interval'  => (string) ( $scheduling['interval'] ?? 'manual' ),
			'max_items' => is_array( $scheduling['max_items'] ?? null ) ? $scheduling['max_items'] : array(),
		);
	}

	private static function list_depth( mixed $value ): int {
		return is_array( $value ) && array_is_list( $value ) ? count( $value ) : 0;
	}
}
