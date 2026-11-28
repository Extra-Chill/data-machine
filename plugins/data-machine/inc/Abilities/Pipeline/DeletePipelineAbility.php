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
	public function execute( array $input ): array|\WP_Error {
		$pipeline_id = $input['pipeline_id'] ?? null;

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return new \WP_Error( 'invalid_pipeline_id', 'pipeline_id is required and must be a positive integer', array( 'status' => 400 ) );
		}

		$pipeline_id = (int) $pipeline_id;
		$pipeline    = $this->db_pipelines->get_pipeline( $pipeline_id );

		if ( ! $pipeline ) {
			do_action( 'datamachine_log', 'error', 'Pipeline not found for deletion', array( 'pipeline_id' => $pipeline_id ) );
			return new \WP_Error( 'pipeline_not_found', 'Pipeline not found', array( 'status' => 404 ) );
		}

		$pipeline_name  = $pipeline['pipeline_name'];
		$affected_flows = $this->db_flows->get_flows_for_pipeline( $pipeline_id );

		$deleted_flows = 0;
		foreach ( $affected_flows as $flow ) {
			$flow_id = (int) ( $flow['flow_id'] ?? 0 );
			if ( $flow_id <= 0 ) {
				continue;
			}

			// Cancel the flow's routine and one-time schedules first, then
			// delete the row. A failed row delete is logged and skipped; the
			// flow remains persisted but unscheduled, visible as manual.
			\DataMachine\Engine\Scheduling\FlowRoutines::unschedule( $flow_id );

			if ( ! $this->db_flows->delete_flow( $flow_id ) ) {
				do_action(
					'datamachine_log',
					'warning',
					'Flow row delete failed during pipeline deletion',
					array(
						'pipeline_id' => $pipeline_id,
						'flow_id'     => $flow_id,
					)
				);
				continue;
			}
			++$deleted_flows;
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
			return new \WP_Error( 'pipeline_deletion_failed', 'Failed to delete pipeline', array( 'status' => 500 ) );
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
			'success'          => true,
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

		return $result;
	}
}
