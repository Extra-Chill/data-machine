<?php
/**
 * Flow Memory Files Abilities
 *
 * Get and update the agent memory files attached to a flow.
 * Owns the behavior formerly exposed by the datamachine/v1
 * /flows/{id}/memory-files wrapper routes (see #3456).
 *
 * @package DataMachine\Abilities\Flow
 * @since 0.177.0
 */

namespace DataMachine\Abilities\Flow;

defined( 'ABSPATH' ) || exit;

class FlowMemoryFilesAbility {

	use FlowHelpers;

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
			'datamachine/get-flow-memory-files',
			array(
				'label'               => __( 'Get Flow Memory Files', 'data-machine' ),
				'description'         => __( 'Get the agent memory filenames attached to a flow.', 'data-machine' ),
				'category'            => 'datamachine-flow',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_id' ),
					'properties' => array(
						'flow_id' => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID to get memory files for', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'flow_id'      => array( 'type' => 'integer' ),
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
			'datamachine/update-flow-memory-files',
			array(
				'label'               => __( 'Update Flow Memory Files', 'data-machine' ),
				'description'         => __( 'Replace the agent memory files attached to a flow.', 'data-machine' ),
				'category'            => 'datamachine-flow',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_id', 'memory_files' ),
					'properties' => array(
						'flow_id'      => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID to update memory files for', 'data-machine' ),
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
						'flow_id'      => array( 'type' => 'integer' ),
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
	 * Execute get flow memory files ability.
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error Result with memory file names.
	 */
	public function executeGet( array $input ): array|\WP_Error {
		$flow_id = $input['flow_id'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return new \WP_Error( 'invalid_flow_id', 'flow_id is required and must be a positive integer', array( 'status' => 400 ) );
		}

		$flow_id = (int) $flow_id;
		$flow    = $this->db_flows->get_flow( $flow_id );

		if ( ! $flow ) {
			return new \WP_Error( 'flow_not_found', 'Flow not found', array( 'status' => 404 ) );
		}

		return array(
			'success'      => true,
			'flow_id'      => $flow_id,
			'memory_files' => array_values( (array) $this->db_flows->get_flow_memory_files( $flow_id ) ),
		);
	}

	/**
	 * Execute update flow memory files ability.
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error Result with the saved memory file names.
	 */
	public function executeUpdate( array $input ): array|\WP_Error {
		$flow_id      = $input['flow_id'] ?? null;
		$memory_files = $input['memory_files'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return new \WP_Error( 'invalid_flow_id', 'flow_id is required and must be a positive integer', array( 'status' => 400 ) );
		}

		if ( ! is_array( $memory_files ) ) {
			return new \WP_Error( 'invalid_memory_files', 'memory_files must be an array of filenames', array( 'status' => 400 ) );
		}

		$flow_id = (int) $flow_id;
		$flow    = $this->db_flows->get_flow( $flow_id );

		if ( ! $flow ) {
			return new \WP_Error( 'flow_not_found', 'Flow not found', array( 'status' => 404 ) );
		}

		// Sanitize filenames the same way the retired wrapper route did.
		$memory_files = array_map( 'sanitize_file_name', $memory_files );
		$memory_files = array_values( array_filter( $memory_files ) );

		$result = $this->db_flows->update_flow_memory_files( $flow_id, $memory_files );

		if ( ! $result ) {
			return new \WP_Error( 'update_failed', 'Failed to update memory files', array( 'status' => 500 ) );
		}

		return array(
			'success'      => true,
			'flow_id'      => $flow_id,
			'memory_files' => $memory_files,
			'message'      => __( 'Flow memory files updated successfully.', 'data-machine' ),
		);
	}
}
