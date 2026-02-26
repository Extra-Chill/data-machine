<?php
/**
 * Schedule Flow Ability
 *
 * Schedules flow execution for later. Handles manual (clear schedule),
 * one-time execution at specific timestamps, and recurring execution
 * at defined intervals.
 *
 * Backs the datamachine_run_flow_later action hook.
 *
 * @package DataMachine\Abilities\Engine
 * @since 0.30.0
 */

namespace DataMachine\Abilities\Engine;

defined( 'ABSPATH' ) || exit;

class ScheduleFlowAbility {

	use EngineHelpers;

	public function __construct() {
		$this->initDatabases();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	/**
	 * Register the datamachine/schedule-flow ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/schedule-flow',
				array(
					'label'               => __( 'Schedule Flow', 'data-machine' ),
					'description'         => __( 'Schedule flow execution: manual (clear), one-time timestamp, or recurring interval.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id', 'interval_or_timestamp' ),
						'properties' => array(
							'flow_id'               => array(
								'type'        => 'integer',
								'description' => __( 'Flow ID to schedule.', 'data-machine' ),
							),
							'interval_or_timestamp' => array(
								'type'        => array( 'string', 'integer' ),
								'description' => __( "Either 'manual', numeric timestamp, or interval key.", 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'flow_id'        => array( 'type' => 'integer' ),
							'schedule_type'  => array( 'type' => 'string' ),
							'action_id'      => array( 'type' => 'integer' ),
							'scheduled_time' => array( 'type' => 'string' ),
							'error'          => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => false,
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => true,
						),
					),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute the schedule-flow ability.
	 *
	 * @param array $input Input with flow_id and interval_or_timestamp.
	 * @return array Result with scheduling details.
	 */
	public function execute( array $input ): array {
		$flow_id               = (int) ( $input['flow_id'] ?? 0 );
		$interval_or_timestamp = $input['interval_or_timestamp'] ?? null;

		// Always unschedule existing to prevent duplicates.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
		}

		if ( 'manual' === $interval_or_timestamp ) {
			return $this->clearSchedule( $flow_id );
		}

		if ( is_numeric( $interval_or_timestamp ) ) {
			return $this->scheduleOneTime( $flow_id, (int) $interval_or_timestamp );
		}

		return $this->scheduleRecurring( $flow_id, $interval_or_timestamp );
	}

	/**
	 * Clear an existing schedule (set to manual).
	 *
	 * @param int $flow_id Flow ID.
	 * @return array Result.
	 */
	private function clearSchedule( int $flow_id ): array {
		$scheduling_config = array( 'interval' => 'manual' );
		$this->db_flows->update_flow_scheduling( $flow_id, $scheduling_config );

		do_action(
			'datamachine_log',
			'info',
			'Flow schedule cleared (set to manual)',
			array( 'flow_id' => $flow_id )
		);

		return array(
			'success'       => true,
			'flow_id'       => $flow_id,
			'schedule_type' => 'manual',
		);
	}

	/**
	 * Schedule a one-time execution at a specific timestamp.
	 *
	 * @param int $flow_id   Flow ID.
	 * @param int $timestamp Unix timestamp for execution.
	 * @return array Result.
	 */
	private function scheduleOneTime( int $flow_id, int $timestamp ): array {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return array(
				'success' => false,
				'error'   => 'Action Scheduler not available.',
			);
		}

		$action_id = as_schedule_single_action(
			$timestamp,
			'datamachine_run_flow_now',
			array( $flow_id ),
			'data-machine'
		);

		$scheduling_config = array(
			'interval'       => 'one_time',
			'timestamp'      => $timestamp,
			'scheduled_time' => wp_date( 'c', $timestamp ),
		);
		$this->db_flows->update_flow_scheduling( $flow_id, $scheduling_config );

		do_action(
			'datamachine_log',
			'info',
			'Flow scheduled for one-time execution',
			array(
				'flow_id'        => $flow_id,
				'timestamp'      => $timestamp,
				'scheduled_time' => wp_date( 'c', $timestamp ),
				'action_id'      => $action_id,
			)
		);

		return array(
			'success'        => true,
			'flow_id'        => $flow_id,
			'schedule_type'  => 'one_time',
			'action_id'      => $action_id,
			'scheduled_time' => wp_date( 'c', $timestamp ),
		);
	}

	/**
	 * Schedule recurring execution at a defined interval.
	 *
	 * @param int    $flow_id  Flow ID.
	 * @param string $interval Interval key from datamachine_scheduler_intervals filter.
	 * @return array Result.
	 */
	private function scheduleRecurring( int $flow_id, string $interval ): array {
		$intervals        = apply_filters( 'datamachine_scheduler_intervals', array() );
		$interval_seconds = $intervals[ $interval ]['seconds'] ?? null;

		if ( ! $interval_seconds ) {
			do_action(
				'datamachine_log',
				'error',
				'Invalid schedule interval',
				array(
					'flow_id'             => $flow_id,
					'interval'            => $interval,
					'available_intervals' => array_keys( $intervals ),
				)
			);
			return array(
				'success' => false,
				'error'   => sprintf( 'Invalid interval: %s', $interval ),
			);
		}

		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return array(
				'success' => false,
				'error'   => 'Action Scheduler not available.',
			);
		}

		$action_id = as_schedule_recurring_action(
			time() + $interval_seconds,
			$interval_seconds,
			'datamachine_run_flow_now',
			array( $flow_id ),
			'data-machine'
		);

		$scheduling_config = array(
			'interval'         => $interval,
			'interval_seconds' => $interval_seconds,
			'first_run'        => wp_date( 'c', time() + $interval_seconds ),
		);
		$this->db_flows->update_flow_scheduling( $flow_id, $scheduling_config );

		do_action(
			'datamachine_log',
			'info',
			'Flow scheduled for recurring execution',
			array(
				'flow_id'          => $flow_id,
				'interval'         => $interval,
				'interval_seconds' => $interval_seconds,
				'first_run'        => wp_date( 'c', time() + $interval_seconds ),
				'action_id'        => $action_id,
			)
		);

		return array(
			'success'        => true,
			'flow_id'        => $flow_id,
			'schedule_type'  => 'recurring',
			'action_id'      => $action_id,
			'scheduled_time' => wp_date( 'c', time() + $interval_seconds ),
		);
	}
}
