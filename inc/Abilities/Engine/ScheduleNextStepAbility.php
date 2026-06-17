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
					'category'            => 'datamachine-jobs',
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

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
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

		// Store data by job_id (if present).
		if ( ! empty( $dataPackets ) ) {
			$engine_snapshot  = datamachine_get_engine_data( $job_id );
			$engine           = new EngineData( $engine_snapshot, $job_id );
			$flow_step_config = $engine->getFlowStepConfig( $flow_step_id );

			$raw_flow_id = $flow_step_config['flow_id'] ?? ( $engine->getJobContext()['flow_id'] ?? null );

			// Direct workflows do not have a numeric flow file context, so keep
			// step input packets on engine data for execute-step to reload.
			if ( 'direct' === $raw_flow_id ) {
				$direct_step_data_packets                  = is_array( $engine_snapshot['direct_step_data_packets'] ?? null ) ? $engine_snapshot['direct_step_data_packets'] : array();
				$direct_step_data_packets[ $flow_step_id ] = $dataPackets;
				datamachine_merge_engine_data(
					$job_id,
					array( 'direct_step_data_packets' => $direct_step_data_packets )
				);
			} elseif ( null !== $raw_flow_id ) {
				$flow_id = (int) $raw_flow_id;

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
					$this->failScheduling(
						$job_id,
						$flow_step_id,
						'missing_flow_id_during_data_storage',
						array( 'packet_count' => count( $dataPackets ) )
					);
					return array(
						'success' => false,
						'error'   => 'Flow ID missing during data storage.',
					);
				}

				$context = datamachine_get_file_context( $flow_id );

				$storage = new FileStorage();
				$result  = $storage->store_data_packet( $dataPackets, $job_id, $context );

				if ( false === $result ) {
					do_action(
						'datamachine_log',
						'error',
						'Failed to persist data packets to filesystem — step will have no input data',
						array(
							'job_id'       => $job_id,
							'flow_step_id' => $flow_step_id,
							'flow_id'      => $flow_id,
							'packet_count' => count( $dataPackets ),
						)
					);
				}
			}
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

		if ( false === $action_id ) {
			$this->failScheduling(
				$job_id,
				$flow_step_id,
				'next_step_schedule_failed',
				array( 'packet_count' => count( $dataPackets ) )
			);
		}

		return array(
			'success'   => false !== $action_id,
			'action_id' => $action_id,
		);
	}

	/**
	 * Route next-step scheduling failures through the normal job failure policy.
	 *
	 * The caller reaches this ability through do_action(), so a false return value
	 * is not observable by ExecuteStepAbility. Failing here preserves the liveness
	 * invariant: a job that cannot schedule its required next step is either
	 * requeued by JobRetryPolicy or completed as failed, never left processing
	 * without scheduler ownership.
	 *
	 * @param int    $job_id       Job ID.
	 * @param string $flow_step_id Next flow step that could not be scheduled.
	 * @param string $reason       Failure reason.
	 * @param array  $context      Additional failure context.
	 */
	private function failScheduling( int $job_id, string $flow_step_id, string $reason, array $context = array() ): void {
		if ( $job_id <= 0 || '' === $flow_step_id ) {
			return;
		}

		do_action(
			'datamachine_fail_job',
			$job_id,
			'step_execution_failure',
			array_merge(
				array(
					'flow_step_id'      => $flow_step_id,
					'next_flow_step_id' => $flow_step_id,
					'reason'            => $reason,
					'retryable'         => true,
				),
				$context
			)
		);
	}
}
