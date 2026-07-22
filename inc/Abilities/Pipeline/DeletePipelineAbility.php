<?php
/**
 * Delete Pipeline Ability
 *
 * Handles pipeline deletion including cascade deletion of associated flows.
 *
 * @package DataMachine\Abilities\Pipeline
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Pipeline;

use DataMachine\Api\Flows\FlowScheduling;
use DataMachine\Core\FilesRepository\FileCleanup;

defined( 'ABSPATH' ) || exit;

class DeletePipelineAbility {

	use PipelineHelpers;

	public function __construct() {
		$this->initDatabases();

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/delete-pipeline',
				array(
					'label'               => __( 'Delete Pipeline', 'data-machine' ),
					'description'         => __( 'Delete a pipeline and all associated flows.', 'data-machine' ),
					'category'            => 'datamachine-pipeline',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'pipeline_id' ),
						'properties' => array(
							'pipeline_id' => array(
								'type'        => 'integer',
								'description' => __( 'Pipeline ID to delete', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'pipeline_id'   => array( 'type' => 'integer' ),
							'pipeline_name' => array( 'type' => 'string' ),
							'deleted_flows' => array( 'type' => 'integer' ),
							'message'       => array( 'type' => 'string' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Execute delete pipeline ability.
	 *
	 * @param array $input Input parameters with pipeline_id.
	 * @return array Result with deletion status.
	 */
	public function execute( array $input ): array {
		$pipeline_id = $input['pipeline_id'] ?? null;

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_id is required and must be a positive integer',
			);
		}

		$pipeline_id = (int) $pipeline_id;
		$pipeline    = $this->db_pipelines->get_pipeline( $pipeline_id );

		if ( ! $pipeline ) {
			do_action( 'datamachine_log', 'error', 'Pipeline not found for deletion', array( 'pipeline_id' => $pipeline_id ) );
			return array(
				'success'    => false,
				'error'      => 'Pipeline not found',
				'error_code' => 'pipeline_not_found',
				'status'     => 404,
			);
		}

		$pipeline_name  = $pipeline['pipeline_name'];
		$affected_flows = $this->db_flows->get_flows_for_pipeline( $pipeline_id );
		$flow_count     = count( $affected_flows );

		// Fence every recurrence before deleting any flow so a lock failure cannot
		// produce a partially deleted pipeline with surviving schedules.
		$fenced_flows = array();
		foreach ( $affected_flows as $flow ) {
			$flow_id = $flow['flow_id'] ?? null;
			if ( ! $flow_id ) {
				continue;
			}

			$fenced_flows[]  = $flow;
			$schedule_result = \DataMachine\Engine\Tasks\RecurringScheduler::ensureSchedule(
				'datamachine_run_flow_now',
				array( (int) $flow_id ),
				'manual'
			);
			if ( is_wp_error( $schedule_result ) ) {
				return array_merge(
					array(
						'success'                 => false,
						'flow_id'                 => (int) $flow_id,
						'schedule_reconciliation' => $this->reconcileFlowSchedules( $fenced_flows ),
					),
					\DataMachine\Engine\Tasks\RecurringScheduler::errorMetadata( $schedule_result )
				);
			}
		}

		foreach ( $affected_flows as $flow ) {
			$flow_id = $flow['flow_id'] ?? null;
			if ( ! $flow_id ) {
				continue;
			}
			$this->db_flows->delete_flow( (int) $flow_id );
		}

		$cleanup            = new FileCleanup();
		$filesystem_deleted = $cleanup->delete_pipeline_directory( $pipeline_id );

		if ( ! $filesystem_deleted ) {
			do_action(
				'datamachine_log',
				'warning',
				'Pipeline filesystem cleanup failed, but continuing with database deletion.',
				array( 'pipeline_id' => $pipeline_id )
			);
		}

		$success = $this->db_pipelines->delete_pipeline( $pipeline_id );

		if ( ! $success ) {
			do_action( 'datamachine_log', 'error', 'Failed to delete pipeline', array( 'pipeline_id' => $pipeline_id ) );
			return array(
				'success' => false,
				'error'   => 'Failed to delete pipeline',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Pipeline deleted via ability',
			array(
				'pipeline_id'   => $pipeline_id,
				'pipeline_name' => $pipeline_name,
				'deleted_flows' => $flow_count,
			)
		);

		return array(
			'success'       => true,
			'pipeline_id'   => $pipeline_id,
			'pipeline_name' => $pipeline_name,
			'deleted_flows' => $flow_count,
			'message'       => sprintf(
				'Pipeline "%s" deleted successfully. %d flows were also deleted.',
				$pipeline_name,
				$flow_count
			),
		);
	}

	/**
	 * Restore persisted schedules after a multi-flow preflight fails partway.
	 *
	 * @param array $flows Flows whose schedule may have been fenced already.
	 */
	private function reconcileFlowSchedules( array $flows ): array {
		$results = array();
		foreach ( $flows as $flow ) {
			$flow_id = (int) ( $flow['flow_id'] ?? 0 );
			if ( $flow_id <= 0 ) {
				continue;
			}

			$result              = FlowScheduling::handle_scheduling_update(
				$flow_id,
				$flow['scheduling_config'] ?? array( 'interval' => 'manual' ),
				true
			);
			$results[ $flow_id ] = is_wp_error( $result )
				? array_merge( array( 'success' => false ), \DataMachine\Engine\Tasks\RecurringScheduler::errorMetadata( $result ) )
				: array( 'success' => true );
		}

		return $results;
	}
}
