<?php
/**
 * Schedule Next Step Ability
 *
 * Stores data packets in the file repository and schedules the next
 * step for execution via Action Scheduler.
 *
 * Backs the datamachine_schedule_next_step action hook.
 *
 * @package DataMachine\Abilities\Engine
 * @since 0.30.0
 */

namespace DataMachine\Abilities\Engine;

use DataMachine\Core\EngineData;
use DataMachine\Core\FilesRepository\FileStorage;

defined( 'ABSPATH' ) || exit;

class ScheduleNextStepAbility {

	use EngineHelpers;

	public function __construct() {
		$this->initDatabases();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	/**
	 * Register the datamachine/schedule-next-step ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/schedule-next-step',
				array(
					'label'               => __( 'Schedule Next Step', 'data-machine' ),
					'description'         => __( 'Store data packets and schedule the next pipeline step via Action Scheduler.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'job_id', 'flow_step_id' ),
						'properties' => array(
							'job_id'       => array(
								'type'        => 'integer',
								'description' => __( 'Job ID for the execution.', 'data-machine' ),
							),
							'flow_step_id' => array(
								'type'        => 'string',
								'description' => __( 'Flow step ID to schedule.', 'data-machine' ),
							),
							'data_packets' => array(
								'type'        => 'array',
								'default'     => array(),
								'description' => __( 'Data packets to pass to the next step.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'action_id' => array( 'type' => 'integer' ),
							'error'     => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => false,
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
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
	 * Execute the schedule-next-step ability.
	 *
	 * @param array $input Input with job_id, flow_step_id, and optional data_packets.
	 * @return array Result with success status and action_id.
	 */
	public function execute( array $input ): array {
		$job_id       = (int) ( $input['job_id'] ?? 0 );
		$flow_step_id = $input['flow_step_id'] ?? '';
		$dataPackets  = $input['data_packets'] ?? array();

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return array(
				'success' => false,
				'error'   => 'Action Scheduler not available.',
			);
		}

		// Store data by job_id (if present).
		if ( ! empty( $dataPackets ) ) {
			$engine_snapshot  = datamachine_get_engine_data( $job_id );
			$engine           = new EngineData( $engine_snapshot, $job_id );
			$flow_step_config = $engine->getFlowStepConfig( $flow_step_id );

			$flow_id = (int) ( $flow_step_config['flow_id'] ?? ( $engine->getJobContext()['flow_id'] ?? 0 ) );

			if ( $flow_id <= 0 ) {
				do_action(
					'datamachine_log',
					'error',
					'Flow ID missing during data storage',
					array(
						'job_id'       => $job_id,
						'flow_step_id' => $flow_step_id,
					)
				);
				return array(
					'success' => false,
					'error'   => 'Flow ID missing during data storage.',
				);
			}

			$context = datamachine_get_file_context( $flow_id );

			$storage = new FileStorage();
			$storage->store_data_packet( $dataPackets, $job_id, $context );
		}

		// Action Scheduler only receives IDs.
		$action_id = as_schedule_single_action(
			time(),
			'datamachine_execute_step',
			array(
				'job_id'       => $job_id,
				'flow_step_id' => $flow_step_id,
			),
			'data-machine'
		);

		if ( ! empty( $dataPackets ) ) {
			do_action(
				'datamachine_log',
				'debug',
				'Next step scheduled via Action Scheduler',
				array(
					'job_id'       => $job_id,
					'flow_step_id' => $flow_step_id,
					'action_id'    => $action_id,
					'success'      => ( false !== $action_id ),
				)
			);
		}

		return array(
			'success'   => false !== $action_id,
			'action_id' => $action_id,
		);
	}
}
