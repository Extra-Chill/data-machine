<?php
/**
 * Pipeline Memory Files Abilities
 *
 * Get and update the agent memory files attached to a pipeline.
 * Owns the behavior formerly exposed by the datamachine/v1
 * /pipelines/{id}/memory-files wrapper routes (see #3456).
 *
 * @package DataMachine\Abilities\Pipeline
 * @since 0.177.0
 */

namespace DataMachine\Abilities\Pipeline;

defined( 'ABSPATH' ) || exit;

class PipelineMemoryFilesAbility {

	use PipelineHelpers;

	public function __construct() {
		$this->initDatabases();

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function (): void {
			$this->registerGetAbility();
			$this->registerUpdateAbility();
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	private function registerGetAbility(): void {
		wp_register_ability(
			'datamachine/get-pipeline-memory-files',
			array(
				'label'               => __( 'Get Pipeline Memory Files', 'data-machine' ),
				'description'         => __( 'Get the agent memory filenames attached to a pipeline.', 'data-machine' ),
				'category'            => 'datamachine-pipeline',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'pipeline_id' ),
					'properties' => array(
						'pipeline_id' => array(
							'type'        => 'integer',
							'description' => __( 'Pipeline ID to get memory files for', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'pipeline_id'  => array( 'type' => 'integer' ),
						'memory_files' => array( 'type' => 'array' ),
						'error'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGet' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerUpdateAbility(): void {
		wp_register_ability(
			'datamachine/update-pipeline-memory-files',
			array(
				'label'               => __( 'Update Pipeline Memory Files', 'data-machine' ),
				'description'         => __( 'Replace the agent memory files attached to a pipeline.', 'data-machine' ),
				'category'            => 'datamachine-pipeline',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'pipeline_id', 'memory_files' ),
					'properties' => array(
						'pipeline_id'  => array(
							'type'        => 'integer',
							'description' => __( 'Pipeline ID to update memory files for', 'data-machine' ),
						),
						'memory_files' => array(
							'type'        => 'array',
							'description' => __( 'Full list of agent memory filenames to attach', 'data-machine' ),
							'items'       => array( 'type' => 'string' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'pipeline_id'  => array( 'type' => 'integer' ),
						'memory_files' => array( 'type' => 'array' ),
						'message'      => array( 'type' => 'string' ),
						'error'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeUpdate' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Execute get pipeline memory files ability.
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error Result with memory file names.
	 */
	public function executeGet( array $input ): array|\WP_Error {
		$pipeline_id = $input['pipeline_id'] ?? null;

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return new \WP_Error( 'invalid_pipeline_id', 'pipeline_id is required and must be a positive integer', array( 'status' => 400 ) );
		}

		$pipeline_id = (int) $pipeline_id;
		$pipeline    = $this->db_pipelines->get_pipeline( $pipeline_id );

		if ( ! $pipeline ) {
			return new \WP_Error( 'pipeline_not_found', 'Pipeline not found', array( 'status' => 404 ) );
		}

		return array(
			'success'      => true,
			'pipeline_id'  => $pipeline_id,
			'memory_files' => array_values( (array) $this->db_pipelines->get_pipeline_memory_files( $pipeline_id ) ),
		);
	}

	/**
	 * Execute update pipeline memory files ability.
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error Result with the saved memory file names.
	 */
	public function executeUpdate( array $input ): array|\WP_Error {
		$pipeline_id  = $input['pipeline_id'] ?? null;
		$memory_files = $input['memory_files'] ?? null;

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return new \WP_Error( 'invalid_pipeline_id', 'pipeline_id is required and must be a positive integer', array( 'status' => 400 ) );
		}

		if ( ! is_array( $memory_files ) ) {
			return new \WP_Error( 'invalid_memory_files', 'memory_files must be an array of filenames', array( 'status' => 400 ) );
		}

		$pipeline_id = (int) $pipeline_id;
		$pipeline    = $this->db_pipelines->get_pipeline( $pipeline_id );

		if ( ! $pipeline ) {
			return new \WP_Error( 'pipeline_not_found', 'Pipeline not found', array( 'status' => 404 ) );
		}

		// Sanitize filenames the same way the retired wrapper route did.
		$memory_files = array_map( 'sanitize_file_name', $memory_files );
		$memory_files = array_values( array_filter( $memory_files ) );

		$result = $this->db_pipelines->update_pipeline_memory_files( $pipeline_id, $memory_files );

		if ( ! $result ) {
			return new \WP_Error( 'update_failed', 'Failed to update memory files', array( 'status' => 500 ) );
		}

		return array(
			'success'      => true,
			'pipeline_id'  => $pipeline_id,
			'memory_files' => $memory_files,
			'message'      => __( 'Memory files updated successfully.', 'data-machine' ),
		);
	}
}
