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

		$deleted_flows     = 0;
		$schedule_failures = array();
		foreach ( $affected_flows as $flow ) {
			$flow_id = (int) ( $flow['flow_id'] ?? 0 );
			if ( $flow_id <= 0 ) {
				continue;
			}

			$schedule_result = \DataMachine\Engine\Tasks\RecurringScheduler::commitDesiredSchedule(
				'datamachine_run_flow_now',
				array( $flow_id ),
				'manual',
				array( 'generation_argument_index' => \DataMachine\Api\Flows\FlowScheduling::GENERATION_ARGUMENT_INDEX ),
				true,
				fn(): bool => $this->db_flows->delete_flow( $flow_id ),
				static function ( $result ) use ( $flow_id ): bool {
					if ( is_wp_error( $result ) ) {
						do_action(
							'datamachine_log',
							'error',
							'Deleted pipeline flow has schedule reconciliation drift',
							array_merge(
								array( 'flow_id' => $flow_id ),
								\DataMachine\Engine\Tasks\RecurringScheduler::errorMetadata( $result )
							)
						);
					}
					return true;
				}
			);
			if ( is_wp_error( $schedule_result ) ) {
				$schedule_failures[ $flow_id ] = array_merge(
					\DataMachine\Engine\Tasks\RecurringScheduler::errorMetadata( $schedule_result ),
					array( 'desired_state_committed' => null === $this->db_flows->get_flow( $flow_id ) )
				);
				continue;
			}
			++$deleted_flows;
		}

		$commit_failures = array_filter(
			$schedule_failures,
			static fn(array $failure): bool => empty( $failure['desired_state_committed'] )
		);
		if ( ! empty( $commit_failures ) ) {
			return array(
				'success'           => false,
				'error'             => 'Pipeline deletion did not commit every flow deletion.',
				'error_code'        => 'pipeline_flow_deletion_incomplete',
				'schedule_failures' => $schedule_failures,
				'deleted_flows'     => $deleted_flows,
			);
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
				'deleted_flows' => $deleted_flows,
			)
		);

		$result = array(
			'success'          => empty( $schedule_failures ),
			'pipeline_id'      => $pipeline_id,
			'pipeline_name'    => $pipeline_name,
			'deleted_flows'    => $deleted_flows,
			'pipeline_deleted' => true,
			'message'          => sprintf(
				'Pipeline "%s" deleted successfully. %d flows were also deleted.',
				$pipeline_name,
				$deleted_flows
			),
		);
		if ( ! empty( $schedule_failures ) ) {
			$result['error']             = 'Pipeline was deleted with retryable schedule reconciliation drift.';
			$result['error_code']        = 'pipeline_schedule_reconciliation_drift';
			$result['schedule_failures'] = $schedule_failures;
		}

		return $result;
	}
}
