<?php
/**
 * Select flows due during an external cycle.
 *
 * @package DataMachine\Core\Flows
 */

namespace DataMachine\Core\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * Combines scheduler-due flows with explicit cycle-policy flows.
 */
class CycleFlowSelector {

	public const POLICY_EVERY_CYCLE = 'every_cycle';

	/**
	 * Select flows that should run during this cycle.
	 *
	 * Scheduled interval/cron flows are passed in already filtered by the existing
	 * jobs-table due logic. This selector adds manual flows that explicitly opt in
	 * to every-cycle execution.
	 *
	 * @param array<int,array<string,mixed>> $scheduled_ready_flows Non-manual flows already due by schedule.
	 * @param array<int,array<string,mixed>> $all_flows             All flows with decoded scheduling_config.
	 * @return array<int,array{flow:array<string,mixed>,reason:string}> Selected flows with reasons.
	 */
	public static function select_due_flows( array $scheduled_ready_flows, array $all_flows ): array {
		$selected = array();
		$seen     = array();

		foreach ( $scheduled_ready_flows as $flow ) {
			$flow_id = (int) ( $flow['flow_id'] ?? 0 );
			if ( $flow_id <= 0 ) {
				continue;
			}

			$selected[]       = array(
				'flow'   => $flow,
				'reason' => 'schedule_due',
			);
			$seen[ $flow_id ] = true;
		}

		foreach ( $all_flows as $flow ) {
			$flow_id = (int) ( $flow['flow_id'] ?? 0 );
			if ( $flow_id <= 0 || isset( $seen[ $flow_id ] ) ) {
				continue;
			}

			$scheduling = is_array( $flow['scheduling_config'] ?? null ) ? $flow['scheduling_config'] : array();
			if ( ! self::is_every_cycle_flow( $scheduling ) ) {
				continue;
			}

			$selected[]       = array(
				'flow'   => $flow,
				'reason' => 'cycle_policy:every_cycle',
			);
			$seen[ $flow_id ] = true;
		}

		return $selected;
	}

	/**
	 * Check whether a flow opts in to every external cycle.
	 *
	 * @param array<string,mixed> $scheduling Scheduling config.
	 * @return bool True when the flow should run every cycle.
	 */
	public static function is_every_cycle_flow( array $scheduling ): bool {
		if ( false === ( $scheduling['enabled'] ?? true ) ) {
			return false;
		}

		return 'manual' === (string) ( $scheduling['interval'] ?? 'manual' )
			&& self::POLICY_EVERY_CYCLE === (string) ( $scheduling['cycle_policy'] ?? '' );
	}
}
