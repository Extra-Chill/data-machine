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

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/delete-pipeline',
				array(
					'label'               => __( 'Delete Pipeline', 'data-machine' ),
					'description'         => __( 'Delete a pipeline and all associated flows.', 'data-machine' ),
					'category'            => 'datamachine',
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

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
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
				'success' => false,
				'error'   => 'Pipeline not found',
			);
		}

		$pipeline_name  = $pipeline['pipeline_name'];
		$affected_flows = $this->db_flows->get_flows_for_pipeline( $pipeline_id );
		$flow_count     = count( $affected_flows );

		foreach ( $affected_flows as $flow ) {
			$flow_id = $flow['flow_id'] ?? null;
			if ( ! $flow_id ) {
				continue;
			}

			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'datamachine_run_flow_now', array( (int) $flow_id ), 'data-machine' );
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
}
