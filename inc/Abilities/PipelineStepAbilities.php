<?php
/**
 * Pipeline Step Abilities
 *
 * Abilities API primitives for pipeline step operations.
 * Centralizes pipeline step CRUD logic for REST API, CLI, and Chat tools.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

class PipelineStepAbilities {

	private static bool $registered = false;

	private Pipelines $db_pipelines;
	private Flows $db_flows;
	private ProcessedItems $db_processed_items;

	public function __construct() {
		$this->db_pipelines       = new Pipelines();
		$this->db_flows           = new Flows();
		$this->db_processed_items = new ProcessedItems();

		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerGetPipelineStepsAbility();
			$this->registerAddPipelineStepAbility();
			$this->registerUpdatePipelineStepAbility();
			$this->registerDeletePipelineStepAbility();
			$this->registerReorderPipelineStepsAbility();
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	private function registerGetPipelineStepsAbility(): void {
		wp_register_ability(
			'datamachine/get-pipeline-steps',
			array(
				'label'               => __( 'Get Pipeline Steps', 'data-machine' ),
				'description'         => __( 'Get all steps for a pipeline, or a single step by ID.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'pipeline_id'      => array(
							'type'        => 'integer',
							'description' => __( 'Pipeline ID to get steps for (required unless pipeline_step_id provided)', 'data-machine' ),
						),
						'pipeline_step_id' => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Get a specific step by ID (ignores pipeline_id when provided)', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'steps'       => array( 'type' => 'array' ),
						'pipeline_id' => array( 'type' => 'integer' ),
						'step_count'  => array( 'type' => 'integer' ),
						'error'       => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetPipelineSteps' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerAddPipelineStepAbility(): void {
		$step_type_abilities = new StepTypeAbilities();
		$valid_types         = array_keys( $step_type_abilities->getAllStepTypes() );
		$types_list          = ! empty( $valid_types ) ? implode( ', ', $valid_types ) : 'fetch, ai, publish, update';

		wp_register_ability(
			'datamachine/add-pipeline-step',
			array(
				'label'               => __( 'Add Pipeline Step', 'data-machine' ),
				'description'         => __( 'Add a step to a pipeline. Automatically syncs to all flows on that pipeline.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'pipeline_id', 'step_type' ),
					'properties' => array(
						'pipeline_id' => array(
							'type'        => 'integer',
							'description' => __( 'Pipeline ID to add the step to', 'data-machine' ),
						),
						'step_type'   => array(
							'type'        => 'string',
							'enum'        => $valid_types,
							'description' => sprintf(
								/* translators: %s: comma-separated list of valid step types */
								__( 'Type of step: %s', 'data-machine' ),
								$types_list
							),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'          => array( 'type' => 'boolean' ),
						'pipeline_id'      => array( 'type' => 'integer' ),
						'pipeline_step_id' => array( 'type' => 'string' ),
						'step_type'        => array( 'type' => 'string' ),
						'flows_updated'    => array( 'type' => 'integer' ),
						'flow_step_ids'    => array( 'type' => 'array' ),
						'message'          => array( 'type' => 'string' ),
						'error'            => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeAddPipelineStep' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerUpdatePipelineStepAbility(): void {
		wp_register_ability(
			'datamachine/update-pipeline-step',
			array(
				'label'               => __( 'Update Pipeline Step', 'data-machine' ),
				'description'         => __( 'Update pipeline step configuration (system prompt, provider, model, enabled tools).', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'pipeline_step_id' ),
					'properties' => array(
						'pipeline_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Pipeline step ID to update', 'data-machine' ),
						),
						'system_prompt'    => array(
							'type'        => 'string',
							'description' => __( 'System prompt for AI step', 'data-machine' ),
						),
						'provider'         => array(
							'type'        => 'string',
							'description' => __( 'AI provider slug (e.g., "anthropic", "openai")', 'data-machine' ),
						),
						'model'            => array(
							'type'        => 'string',
							'description' => __( 'AI model identifier', 'data-machine' ),
						),
						'enabled_tools'    => array(
							'type'        => 'array',
							'description' => __( 'Array of enabled tool IDs for this step', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'          => array( 'type' => 'boolean' ),
						'pipeline_step_id' => array( 'type' => 'string' ),
						'pipeline_id'      => array( 'type' => 'integer' ),
						'updated_fields'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'message'          => array( 'type' => 'string' ),
						'error'            => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeUpdatePipelineStep' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerDeletePipelineStepAbility(): void {
		wp_register_ability(
			'datamachine/delete-pipeline-step',
			array(
				'label'               => __( 'Delete Pipeline Step', 'data-machine' ),
				'description'         => __( 'Remove a step from a pipeline. Removes step from all flows on the pipeline.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'pipeline_id', 'pipeline_step_id' ),
					'properties' => array(
						'pipeline_id'      => array(
							'type'        => 'integer',
							'description' => __( 'Pipeline ID containing the step', 'data-machine' ),
						),
						'pipeline_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Pipeline step ID to delete', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'          => array( 'type' => 'boolean' ),
						'pipeline_id'      => array( 'type' => 'integer' ),
						'pipeline_step_id' => array( 'type' => 'string' ),
						'affected_flows'   => array( 'type' => 'integer' ),
						'message'          => array( 'type' => 'string' ),
						'error'            => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeDeletePipelineStep' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerReorderPipelineStepsAbility(): void {
		wp_register_ability(
			'datamachine/reorder-pipeline-steps',
			array(
				'label'               => __( 'Reorder Pipeline Steps', 'data-machine' ),
				'description'         => __( 'Reorder steps within a pipeline.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'pipeline_id', 'step_order' ),
					'properties' => array(
						'pipeline_id' => array(
							'type'        => 'integer',
							'description' => __( 'Pipeline ID to reorder steps for', 'data-machine' ),
						),
						'step_order'  => array(
							'type'        => 'array',
							'description' => __( 'Array of step order objects: [{pipeline_step_id: "...", execution_order: 0}, ...]', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'pipeline_id' => array( 'type' => 'integer' ),
						'step_count'  => array( 'type' => 'integer' ),
						'message'     => array( 'type' => 'string' ),
						'error'       => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeReorderPipelineSteps' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Execute get pipeline steps ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with steps data.
	 */
	public function executeGetPipelineSteps( array $input ): array {
		$pipeline_id      = $input['pipeline_id'] ?? null;
		$pipeline_step_id = $input['pipeline_step_id'] ?? null;

		// Direct step lookup by ID - bypasses pipeline_id requirement.
		if ( $pipeline_step_id ) {
			if ( ! is_string( $pipeline_step_id ) || empty( $pipeline_step_id ) ) {
				return array(
					'success' => false,
					'error'   => 'pipeline_step_id must be a non-empty string',
				);
			}

			$step_config = $this->db_pipelines->get_pipeline_step_config( $pipeline_step_id );

			if ( empty( $step_config ) ) {
				return array(
					'success'     => true,
					'steps'       => array(),
					'pipeline_id' => null,
					'step_count'  => 0,
				);
			}

			return array(
				'success'     => true,
				'steps'       => array( $step_config ),
				'pipeline_id' => $step_config['pipeline_id'] ?? null,
				'step_count'  => 1,
			);
		}

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_id is required and must be a positive integer',
			);
		}

		$pipeline_id = (int) $pipeline_id;
		$pipeline    = $this->db_pipelines->get_pipeline( $pipeline_id );

		if ( ! $pipeline ) {
			return array(
				'success' => false,
				'error'   => 'Pipeline not found',
			);
		}

		$steps = $this->db_pipelines->get_pipeline_config( $pipeline_id );

		return array(
			'success'     => true,
			'steps'       => $steps,
			'pipeline_id' => $pipeline_id,
			'step_count'  => count( $steps ),
		);
	}

	/**
	 * Execute add pipeline step ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with created step data.
	 */
	public function executeAddPipelineStep( array $input ): array {
		$pipeline_id = $input['pipeline_id'] ?? null;
		$step_type   = $input['step_type'] ?? null;

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_id is required and must be a positive integer',
			);
		}

		if ( empty( $step_type ) || ! is_string( $step_type ) ) {
			return array(
				'success' => false,
				'error'   => 'step_type is required and must be a string',
			);
		}

		$step_type_abilities = new StepTypeAbilities();
		$valid_types         = array_keys( $step_type_abilities->getAllStepTypes() );

		if ( ! in_array( $step_type, $valid_types, true ) ) {
			return array(
				'success' => false,
				'error'   => "Invalid step_type '{$step_type}'. Must be one of: " . implode( ', ', $valid_types ),
			);
		}

		$pipeline_id = (int) $pipeline_id;
		$step_type   = sanitize_text_field( wp_unslash( $step_type ) );

		$step_type_config = $step_type_abilities->getStepType( $step_type );
		if ( ! $step_type_config ) {
			do_action( 'datamachine_log', 'error', 'Invalid step type for step creation', array( 'step_type' => $step_type ) );
			return array(
				'success' => false,
				'error'   => 'Invalid step type configuration',
			);
		}

		$current_steps        = $this->db_pipelines->get_pipeline_config( $pipeline_id );
		$next_execution_order = count( $current_steps );

		$new_step = array(
			'step_type'        => $step_type,
			'execution_order'  => $next_execution_order,
			'pipeline_step_id' => $pipeline_id . '_' . wp_generate_uuid4(),
			'label'            => $step_type_config['label'] ?? ucfirst( str_replace( '_', ' ', $step_type ) ),
		);

		if ( 'ai' === $step_type ) {
			$new_step['provider'] = PluginSettings::get( 'default_provider', '' );
			$new_step['model']    = PluginSettings::get( 'default_model', '' );
		}

		$pipeline_config = array();
		foreach ( $current_steps as $step ) {
			$pipeline_config[ $step['pipeline_step_id'] ] = $step;
		}
		$pipeline_config[ $new_step['pipeline_step_id'] ] = $new_step;

		$success = $this->db_pipelines->update_pipeline(
			$pipeline_id,
			array(
				'pipeline_config' => $pipeline_config,
			)
		);

		if ( ! $success ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to add step to pipeline',
				array(
					'pipeline_id' => $pipeline_id,
					'step_type'   => $step_type,
				)
			);
			return array(
				'success' => false,
				'error'   => 'Failed to add step. Verify the pipeline_id exists and you have sufficient permissions.',
			);
		}

		$pipeline_step_id = $new_step['pipeline_step_id'];
		$flows            = $this->db_flows->get_flows_for_pipeline( $pipeline_id );

		foreach ( $flows as $flow ) {
			$this->syncStepsToFlow( $flow['flow_id'], $pipeline_id, array( $new_step ), $pipeline_config );
		}

		$flow_step_ids = array();
		$updated_flows = $this->db_flows->get_flows_for_pipeline( $pipeline_id );
		foreach ( $updated_flows as $flow ) {
			$flow_config = $flow['flow_config'] ?? array();
			foreach ( $flow_config as $flow_step_id => $step_data ) {
				if ( isset( $step_data['pipeline_step_id'] ) && $step_data['pipeline_step_id'] === $pipeline_step_id ) {
					$flow_step_ids[] = array(
						'flow_id'      => $flow['flow_id'],
						'flow_step_id' => $flow_step_id,
					);
				}
			}
		}

		do_action(
			'datamachine_log',
			'info',
			'Pipeline step added via ability',
			array(
				'pipeline_id'      => $pipeline_id,
				'pipeline_step_id' => $pipeline_step_id,
				'step_type'        => $step_type,
				'flows_updated'    => count( $flows ),
			)
		);

		return array(
			'success'          => true,
			'pipeline_id'      => $pipeline_id,
			'pipeline_step_id' => $pipeline_step_id,
			'step_type'        => $step_type,
			'flows_updated'    => count( $flows ),
			'flow_step_ids'    => $flow_step_ids,
			'message'          => "Step '{$step_type}' added to pipeline. Use configure_flow_steps with the flow_step_ids to set handler configuration.",
		);
	}

	/**
	 * Execute update pipeline step ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with update status.
	 */
	public function executeUpdatePipelineStep( array $input ): array {
		$pipeline_step_id = $input['pipeline_step_id'] ?? null;

		if ( empty( $pipeline_step_id ) || ! is_string( $pipeline_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_step_id is required and must be a string',
			);
		}

		$system_prompt = $input['system_prompt'] ?? null;
		$provider      = $input['provider'] ?? null;
		$model         = $input['model'] ?? null;
		$enabled_tools = $input['enabled_tools'] ?? null;

		if ( null === $system_prompt && null === $provider && null === $model && null === $enabled_tools ) {
			return array(
				'success' => false,
				'error'   => 'At least one of system_prompt, provider, model, or enabled_tools is required',
			);
		}

		$step_config = $this->db_pipelines->get_pipeline_step_config( $pipeline_step_id );

		if ( empty( $step_config ) || empty( $step_config['pipeline_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Pipeline step not found',
			);
		}

		$pipeline_id = $step_config['pipeline_id'];
		$pipeline    = $this->db_pipelines->get_pipeline( $pipeline_id );

		if ( ! $pipeline ) {
			return array(
				'success' => false,
				'error'   => 'Pipeline not found',
			);
		}

		$pipeline_config = $pipeline['pipeline_config'] ?? array();
		$existing_config = $pipeline_config[ $pipeline_step_id ] ?? array();

		$step_config_data = array();
		$updated_fields   = array();

		if ( null !== $system_prompt ) {
			$step_config_data['system_prompt'] = wp_unslash( $system_prompt );
			$updated_fields[]                  = 'system_prompt';
		}

		if ( null !== $provider ) {
			$step_config_data['provider'] = sanitize_text_field( $provider );
			$updated_fields[]             = 'provider';
		}

		if ( null !== $model ) {
			$step_config_data['model'] = sanitize_text_field( $model );
			$updated_fields[]          = 'model';

			$provider_for_model = $provider ?? ( $existing_config['provider'] ?? '' );
			if ( ! empty( $provider_for_model ) ) {
				if ( ! isset( $step_config_data['providers'] ) ) {
					$step_config_data['providers'] = $existing_config['providers'] ?? array();
				}
				if ( ! isset( $step_config_data['providers'][ $provider_for_model ] ) ) {
					$step_config_data['providers'][ $provider_for_model ] = array();
				}
				$step_config_data['providers'][ $provider_for_model ]['model'] = sanitize_text_field( $model );
			}
		}

		if ( null !== $enabled_tools && is_array( $enabled_tools ) ) {
			$sanitized_tool_ids = array_map( 'sanitize_text_field', $enabled_tools );
			$tools_manager      = new \DataMachine\Engine\AI\Tools\ToolManager();

			$step_config_data['enabled_tools'] = $tools_manager->save_step_tool_selections( $pipeline_step_id, $sanitized_tool_ids );
			$updated_fields[]                  = 'enabled_tools';
		}

		$pipeline_config[ $pipeline_step_id ] = array_merge( $existing_config, $step_config_data );

		$success = $this->db_pipelines->update_pipeline(
			$pipeline_id,
			array( 'pipeline_config' => $pipeline_config )
		);

		if ( ! $success ) {
			return array(
				'success' => false,
				'error'   => 'Failed to update pipeline step configuration',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Pipeline step updated via ability',
			array(
				'pipeline_step_id' => $pipeline_step_id,
				'pipeline_id'      => $pipeline_id,
				'updated_fields'   => $updated_fields,
			)
		);

		return array(
			'success'          => true,
			'pipeline_step_id' => $pipeline_step_id,
			'pipeline_id'      => $pipeline_id,
			'updated_fields'   => $updated_fields,
			'message'          => 'Pipeline step configuration updated successfully. Fields updated: ' . implode( ', ', $updated_fields ),
		);
	}

	/**
	 * Execute delete pipeline step ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with deletion status.
	 */
	public function executeDeletePipelineStep( array $input ): array {
		$pipeline_id      = $input['pipeline_id'] ?? null;
		$pipeline_step_id = $input['pipeline_step_id'] ?? null;

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_id is required and must be a positive integer',
			);
		}

		if ( empty( $pipeline_step_id ) || ! is_string( $pipeline_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_step_id is required and must be a string',
			);
		}

		$pipeline_id      = absint( $pipeline_id );
		$pipeline_step_id = trim( sanitize_text_field( $pipeline_step_id ) );

		$pipeline = $this->db_pipelines->get_pipeline( $pipeline_id );
		if ( ! $pipeline ) {
			return array(
				'success' => false,
				'error'   => __( 'Pipeline not found.', 'data-machine' ),
			);
		}

		if ( ! isset( $pipeline['pipeline_name'] ) || empty( trim( $pipeline['pipeline_name'] ) ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Cannot delete pipeline step - pipeline missing or empty name',
				array(
					'pipeline_id'      => $pipeline_id,
					'pipeline_step_id' => $pipeline_step_id,
				)
			);
			return array(
				'success' => false,
				'error'   => __( 'Pipeline data is corrupted - missing name.', 'data-machine' ),
			);
		}

		$pipeline_name  = $pipeline['pipeline_name'];
		$affected_flows = $this->db_flows->get_flows_for_pipeline( $pipeline_id );
		$flow_count     = count( $affected_flows );

		$current_steps   = $this->db_pipelines->get_pipeline_config( $pipeline_id );
		$remaining_steps = array();
		$step_found      = false;

		foreach ( $current_steps as $step ) {
			if ( ( $step['pipeline_step_id'] ?? '' ) !== $pipeline_step_id ) {
				$remaining_steps[] = $step;
			} else {
				$step_found = true;
			}
		}

		if ( ! $step_found ) {
			return array(
				'success' => false,
				'error'   => __( 'Step not found in pipeline.', 'data-machine' ),
			);
		}

		$updated_steps = array();
		foreach ( $remaining_steps as $index => $step ) {
			$step['execution_order']                    = $index;
			$updated_steps[ $step['pipeline_step_id'] ] = $step;
		}

		$success = $this->db_pipelines->update_pipeline(
			$pipeline_id,
			array(
				'pipeline_config' => $updated_steps,
			)
		);

		if ( ! $success ) {
			return array(
				'success' => false,
				'error'   => __( 'Failed to delete step from pipeline.', 'data-machine' ),
			);
		}

		// Sync deletions to flows
		$affected_flows = $this->db_flows->get_flows_for_pipeline( $pipeline_id );
		foreach ( $affected_flows as $flow ) {
			$flow_config         = $flow['flow_config'] ?? array();
			$updated_flow_config = array();
			foreach ( $flow_config as $flow_step_id => $flow_step ) {
				if ( isset( $flow_step['pipeline_step_id'] ) && $flow_step['pipeline_step_id'] !== $pipeline_step_id ) {
					$updated_flow_config[ $flow_step_id ] = $flow_step;
				}
			}
			$this->db_flows->update_flow(
				$flow['flow_id'],
				array( 'flow_config' => $updated_flow_config )
			);
		}

		// Clean processed items
		$this->db_processed_items->delete_processed_items( array( 'pipeline_step_id' => $pipeline_step_id ) );

		do_action(
			'datamachine_log',
			'info',
			'Pipeline step deleted via ability',
			array(
				'pipeline_id'      => $pipeline_id,
				'pipeline_step_id' => $pipeline_step_id,
				'affected_flows'   => $flow_count,
			)
		);

		return array(
			'success'          => true,
			'pipeline_id'      => $pipeline_id,
			'pipeline_step_id' => $pipeline_step_id,
			'affected_flows'   => $flow_count,
			'message'          => sprintf(
				/* translators: 1: pipeline name, 2: number of flows affected */
				esc_html__( 'Step deleted successfully from pipeline "%1$s". %2$d flows were updated and processed items cleaned.', 'data-machine' ),
				$pipeline_name,
				$flow_count
			),
		);
	}

	/**
	 * Execute reorder pipeline steps ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with reorder status.
	 */
	public function executeReorderPipelineSteps( array $input ): array {
		$pipeline_id = $input['pipeline_id'] ?? null;
		$step_order  = $input['step_order'] ?? null;

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_id is required and must be a positive integer',
			);
		}

		if ( empty( $step_order ) || ! is_array( $step_order ) ) {
			return array(
				'success' => false,
				'error'   => 'step_order is required and must be an array',
			);
		}

		foreach ( $step_order as $index => $item ) {
			if ( ! is_array( $item ) ) {
				return array(
					'success' => false,
					'error'   => "Step order item at index {$index} must be an object",
				);
			}

			if ( ! isset( $item['pipeline_step_id'] ) || ! isset( $item['execution_order'] ) ) {
				return array(
					'success' => false,
					'error'   => 'Each step order item must have pipeline_step_id and execution_order',
				);
			}

			if ( ! is_string( $item['pipeline_step_id'] ) || ! is_numeric( $item['execution_order'] ) ) {
				return array(
					'success' => false,
					'error'   => 'pipeline_step_id must be string and execution_order must be numeric',
				);
			}
		}

		$pipeline_id    = (int) $pipeline_id;
		$pipeline_steps = $this->db_pipelines->get_pipeline_config( $pipeline_id );

		if ( empty( $pipeline_steps ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Pipeline not found', 'data-machine' ),
			);
		}

		$updated_steps = array();
		foreach ( $step_order as $item ) {
			$pipeline_step_id = sanitize_text_field( $item['pipeline_step_id'] );
			$execution_order  = (int) $item['execution_order'];

			$step_found = false;
			foreach ( $pipeline_steps as $step ) {
				if ( $step['pipeline_step_id'] === $pipeline_step_id ) {
					$step['execution_order'] = $execution_order;
					$updated_steps[]         = $step;
					$step_found              = true;
					break;
				}
			}

			if ( ! $step_found ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %s: pipeline step ID */
						__( 'Step %s not found in pipeline', 'data-machine' ),
						$pipeline_step_id
					),
				);
			}
		}

		if ( count( $updated_steps ) !== count( $pipeline_steps ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Step count mismatch during reorder', 'data-machine' ),
			);
		}

		$success = $this->db_pipelines->update_pipeline(
			$pipeline_id,
			array(
				'pipeline_config' => $updated_steps,
			)
		);

		if ( ! $success ) {
			return array(
				'success' => false,
				'error'   => __( 'Failed to save step order', 'data-machine' ),
			);
		}

		$flows = $this->db_flows->get_flows_for_pipeline( $pipeline_id );
		foreach ( $flows as $flow ) {
			$flow_id     = $flow['flow_id'];
			$flow_config = $flow['flow_config'] ?? array();

			foreach ( $flow_config as $flow_step_id => &$flow_step ) {
				if ( ! isset( $flow_step['pipeline_step_id'] ) || empty( $flow_step['pipeline_step_id'] ) ) {
					continue;
				}
				$pipeline_step_id = $flow_step['pipeline_step_id'];

				foreach ( $updated_steps as $updated_step ) {
					if ( $updated_step['pipeline_step_id'] === $pipeline_step_id ) {
						$flow_step['execution_order'] = $updated_step['execution_order'];
						break;
					}
				}
			}
			unset( $flow_step );

			$this->db_flows->update_flow(
				$flow_id,
				array(
					'flow_config' => $flow_config,
				)
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Pipeline steps reordered via ability',
			array(
				'pipeline_id' => $pipeline_id,
				'step_count'  => count( $updated_steps ),
			)
		);

		return array(
			'success'     => true,
			'pipeline_id' => $pipeline_id,
			'step_count'  => count( $updated_steps ),
			'message'     => 'Pipeline steps reordered successfully.',
		);
	}

	/**
	 * Sync pipeline steps to a flow's configuration.
	 *
	 * @param int   $flow_id Flow ID.
	 * @param int   $pipeline_id Pipeline ID.
	 * @param array $steps Array of pipeline step data.
	 * @param array $pipeline_config Full pipeline config.
	 * @return bool Success status.
	 */
	private function syncStepsToFlow( int $flow_id, int $pipeline_id, array $steps, array $pipeline_config = array() ): bool {
		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			do_action( 'datamachine_log', 'error', 'Flow not found for step sync', array( 'flow_id' => $flow_id ) );
			return false;
		}

		$flow_config = $flow['flow_config'] ?? array();

		foreach ( $steps as $step ) {
			$pipeline_step_id = $step['pipeline_step_id'] ?? null;
			if ( ! $pipeline_step_id ) {
				continue;
			}

			$flow_step_id = apply_filters( 'datamachine_generate_flow_step_id', '', $pipeline_step_id, $flow_id );

			$enabled_tools = $pipeline_config[ $pipeline_step_id ]['enabled_tools'] ?? array();

			$flow_config[ $flow_step_id ] = array(
				'flow_step_id'     => $flow_step_id,
				'step_type'        => $step['step_type'] ?? '',
				'pipeline_step_id' => $pipeline_step_id,
				'pipeline_id'      => $pipeline_id,
				'flow_id'          => $flow_id,
				'execution_order'  => $step['execution_order'] ?? 0,
				'enabled_tools'    => $enabled_tools,
				'handler'          => null,
			);
		}

		$success = $this->db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);

		if ( ! $success ) {
			do_action(
				'datamachine_log',
				'error',
				'Flow step sync failed - database update failed',
				array(
					'flow_id'     => $flow_id,
					'steps_count' => count( $steps ),
				)
			);
			return false;
		}

		return true;
	}
}
