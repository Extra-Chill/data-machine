<?php
/**
 * Update Pipeline Ability
 *
 * Handles pipeline updates including name changes.
 *
 * @package DataMachine\Abilities\Pipeline
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Pipeline;

defined( 'ABSPATH' ) || exit;

class UpdatePipelineAbility {

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
				'datamachine/update-pipeline',
				array(
					'label'               => __( 'Update Pipeline', 'data-machine' ),
					'description'         => __( 'Update pipeline name or configuration.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'pipeline_id' ),
						'properties' => array(
							'pipeline_id'   => array(
								'type'        => 'integer',
								'description' => __( 'Pipeline ID to update', 'data-machine' ),
							),
							'pipeline_name' => array(
								'type'        => 'string',
								'description' => __( 'New pipeline name', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'pipeline_id'   => array( 'type' => 'integer' ),
							'pipeline_name' => array( 'type' => 'string' ),
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
	 * Execute update pipeline ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with update status.
	 */
	public function execute( array $input ): array {
		$pipeline_id   = $input['pipeline_id'] ?? null;
		$pipeline_name = $input['pipeline_name'] ?? null;

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_id is required and must be a positive integer',
			);
		}

		$pipeline_id = (int) $pipeline_id;

		if ( null === $pipeline_name ) {
			return array(
				'success' => false,
				'error'   => 'Must provide pipeline_name to update',
			);
		}

		$pipeline = $this->db_pipelines->get_pipeline( $pipeline_id );
		if ( ! $pipeline ) {
			return array(
				'success' => false,
				'error'   => 'Pipeline not found',
			);
		}

		$pipeline_name = sanitize_text_field( wp_unslash( $pipeline_name ) );
		if ( empty( trim( $pipeline_name ) ) ) {
			return array(
				'success' => false,
				'error'   => 'Pipeline name cannot be empty',
			);
		}

		$success = $this->db_pipelines->update_pipeline(
			$pipeline_id,
			array( 'pipeline_name' => $pipeline_name )
		);

		if ( ! $success ) {
			return array(
				'success' => false,
				'error'   => 'Failed to update pipeline',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Pipeline updated via ability',
			array(
				'pipeline_id'   => $pipeline_id,
				'pipeline_name' => $pipeline_name,
			)
		);

		return array(
			'success'       => true,
			'pipeline_id'   => $pipeline_id,
			'pipeline_name' => $pipeline_name,
			'message'       => 'Pipeline updated successfully',
		);
	}
}
