<?php
/**
 * Create Pipeline Ability
 *
 * Handles pipeline creation including bulk mode and step configuration.
 *
 * @package DataMachine\Abilities\Pipeline
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Pipeline;

use DataMachine\Abilities\StepTypeAbilities;
use DataMachine\Core\Steps\WorkflowConfigFactory;

defined( 'ABSPATH' ) || exit;

class CreatePipelineAbility {

	use PipelineHelpers;

	public function __construct() {
		$this->initDatabases();

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/create-pipeline',
				array(
					'label'               => __( 'Create Pipeline', 'data-machine' ),
					'description'         => __( 'Create a new pipeline with optional steps. Pass flow_config to also create a flow; omit it for pipeline-only creation. Supports bulk mode via pipelines array.', 'data-machine' ),
					'category'            => 'datamachine-pipeline',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'pipeline_name' => array(
								'type'        => 'string',
								'description' => __( 'Pipeline name (single mode)', 'data-machine' ),
							),
							'agent_id'      => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'Agent ID to scope the pipeline to. When provided, the pipeline is owned by this agent.', 'data-machine' ),
							),
							'steps'         => array(
								'type'        => 'array',
								'description' => __( 'Optional steps configuration (each with step_type, optional label)', 'data-machine' ),
							),
							'workflow'      => array(
								'type'        => 'object',
								'description' => __( 'Optional persistent workflow scaffold using the same steps shape as datamachine/execute-workflow. When provided, Data Machine creates matching pipeline steps and a flow whose step configs preserve handler config, enabled tools, queue state, user_message prompt queue, disabled tools, labels, and execution order.', 'data-machine' ),
							),
							'flow_config'   => array(
								'type'        => 'object',
								'description' => __( 'Optional flow configuration (flow_name, scheduling_config)', 'data-machine' ),
							),
							'pipelines'     => array(
								'type'        => 'array',
								'description' => __( 'Bulk mode: create multiple pipelines. Each item: {name, workflow?, steps?, flow_name?, scheduling_config?}', 'data-machine' ),
							),
							'template'      => array(
								'type'        => 'object',
								'description' => __( 'Shared config for bulk mode applied to all pipelines: {workflow, steps, scheduling_config}', 'data-machine' ),
							),
							'validate_only' => array(
								'type'        => 'boolean',
								'description' => __( 'Dry-run mode: validate without executing', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'pipeline_id'   => array( 'type' => array( 'integer', 'null' ) ),
							'pipeline_name' => array( 'type' => array( 'string', 'null' ) ),
							'flow_id'       => array( 'type' => array( 'integer', 'null' ) ),
							'flow_name'     => array( 'type' => array( 'string', 'null' ) ),
							'steps_created' => array( 'type' => array( 'integer', 'null' ) ),
							'flow_step_ids' => array( 'type' => array( 'array', 'null' ) ),
							'creation_mode' => array( 'type' => array( 'string', 'null' ) ),
							'created_count' => array( 'type' => array( 'integer', 'null' ) ),
							'failed_count'  => array( 'type' => array( 'integer', 'null' ) ),
							'created'       => array( 'type' => array( 'array', 'null' ) ),
							'errors'        => array( 'type' => array( 'array', 'null' ) ),
							'partial'       => array( 'type' => array( 'boolean', 'null' ) ),
							'message'       => array( 'type' => array( 'string', 'null' ) ),
							'error'         => array( 'type' => array( 'string', 'null' ) ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
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
	 * Execute create pipeline ability.
	 *
	 * Supports two modes:
	 * - Single mode: Create one pipeline (pipeline_name required)
	 * - Bulk mode: Create multiple pipelines (pipelines array provided)
	 *
	 * @param array $input Input parameters.
	 * @return array Result with created pipeline data.
	 */
	public function execute( array $input ): array {
		// Check for bulk mode
		if ( ! empty( $input['pipelines'] ) && is_array( $input['pipelines'] ) ) {
			return $this->executeBulk( $input );
		}

		return $this->executeSingle( $input );
	}

	/**
	 * Execute single pipeline creation.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with created pipeline data.
	 */
	private function executeSingle( array $input ): array {
		$pipeline_name = $input['pipeline_name'] ?? null;

		if ( empty( $pipeline_name ) || ! is_string( $pipeline_name ) ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_name is required and must be a non-empty string',
			);
		}

		$pipeline_name = sanitize_text_field( wp_unslash( $pipeline_name ) );
		if ( empty( trim( $pipeline_name ) ) ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_name cannot be empty',
			);
		}

		$agent_id    = isset( $input['agent_id'] ) ? (int) $input['agent_id'] : null;
		$steps       = $input['steps'] ?? array();
		$workflow    = isset( $input['workflow'] ) && is_array( $input['workflow'] ) ? $input['workflow'] : array();
		$flow_config = $input['flow_config'] ?? array();

		$has_steps    = ! empty( $steps ) && is_array( $steps );
		$has_workflow = ! empty( $workflow );

		if ( $has_workflow ) {
			$validation = $this->validateWorkflow( $workflow );
			if ( true !== $validation ) {
				return array(
					'success' => false,
					'error'   => $validation,
				);
			}
		} elseif ( $has_steps ) {
			$validation = $this->validateSteps( $steps );
			if ( true !== $validation ) {
				return array(
					'success' => false,
					'error'   => $validation,
				);
			}
		}

		$pipeline_data = array(
			'pipeline_name'   => $pipeline_name,
			'pipeline_config' => array(),
		);

		if ( null !== $agent_id && $agent_id > 0 ) {
			$pipeline_data['agent_id'] = $agent_id;
		}

		$pipeline_id = $this->db_pipelines->create_pipeline( $pipeline_data );

		if ( ! $pipeline_id ) {
			do_action( 'datamachine_log', 'error', 'Failed to create pipeline', array( 'pipeline_name' => $pipeline_name ) );
			return array(
				'success' => false,
				'error'   => 'Failed to create pipeline',
			);
		}

		$pipeline_config = array();
		$steps_created   = 0;

		if ( $has_workflow ) {
			$pipeline_config = WorkflowConfigFactory::buildPersistentPipelineConfig( $workflow, $pipeline_id );
			$steps_created   = count( $pipeline_config );

			if ( ! empty( $pipeline_config ) ) {
				$this->db_pipelines->update_pipeline(
					$pipeline_id,
					array( 'pipeline_config' => $pipeline_config )
				);
			}
		} elseif ( $has_steps ) {
			$step_type_abilities = new StepTypeAbilities();

			foreach ( $steps as $index => $step_data ) {
				$step_type = sanitize_text_field( $step_data['step_type'] ?? '' );
				if ( empty( $step_type ) ) {
					continue;
				}

				$step_type_config = $step_type_abilities->getStepType( $step_type );
				if ( ! $step_type_config ) {
					continue;
				}

				$pipeline_step_id = $pipeline_id . '_' . wp_generate_uuid4();
				$label            = sanitize_text_field( $step_data['label'] ?? $step_type_config['label'] ?? ucfirst( str_replace( '_', ' ', $step_type ) ) );

				$pipeline_config[ $pipeline_step_id ] = array(
					'pipeline_step_id' => $pipeline_step_id,
					'step_type'        => $step_type,
					'execution_order'  => $index,
					'label'            => $label,
				);

				++$steps_created;
			}

			if ( ! empty( $pipeline_config ) ) {
				$this->db_pipelines->update_pipeline(
					$pipeline_id,
					array( 'pipeline_config' => $pipeline_config )
				);
			}
		}

		$flow_result   = null;
		$flow_name     = null;
		$flow_step_ids = array();

		if ( ! empty( $flow_config ) || $has_workflow ) {
			$flow_name         = $flow_config['flow_name'] ?? $pipeline_name;
			$scheduling_config = $flow_config['scheduling_config'] ?? array( 'interval' => 'manual' );

			$create_flow_ability = wp_get_ability( 'datamachine/create-flow' );
			if ( $create_flow_ability ) {
				$flow_input = array(
					'pipeline_id'       => $pipeline_id,
					'flow_name'         => $flow_name,
					'scheduling_config' => $scheduling_config,
				);

				if ( null !== $agent_id && $agent_id > 0 ) {
					$flow_input['agent_id'] = $agent_id;
				}

				$flow_result = $create_flow_ability->execute( $flow_input );
			}

			if ( ! $flow_result || ! $flow_result['success'] ) {
				do_action( 'datamachine_log', 'error', "Failed to create flow for pipeline {$pipeline_id}" );
			}

			if ( $flow_result && $flow_result['success'] && $has_workflow ) {
				$persistent_flow_config = WorkflowConfigFactory::buildPersistentFlowConfig(
					$workflow,
					$pipeline_id,
					(int) $flow_result['flow_id'],
					$pipeline_config
				);
				$this->db_flows->update_flow(
					(int) $flow_result['flow_id'],
					array( 'flow_config' => $persistent_flow_config )
				);
				$flow_result['flow_data']['flow_config'] = $persistent_flow_config;
			}

			if ( $flow_result && $flow_result['success'] && ! empty( $flow_result['flow_data']['flow_config'] ) ) {
				$flow_step_ids = array_keys( $flow_result['flow_data']['flow_config'] );
			}
		}

		do_action(
			'datamachine_log',
			'info',
			'Pipeline created via ability',
			array(
				'pipeline_id'   => $pipeline_id,
				'pipeline_name' => $pipeline_name,
				'steps_created' => $steps_created,
				'flow_id'       => $flow_result['flow_id'] ?? null,
			)
		);

		return array(
			'success'       => true,
			'pipeline_id'   => $pipeline_id,
			'pipeline_name' => $pipeline_name,
			'flow_id'       => $flow_result['flow_id'] ?? null,
			'flow_name'     => $flow_name,
			'steps_created' => $steps_created,
			'flow_step_ids' => $flow_step_ids,
			'creation_mode' => $has_workflow ? 'workflow' : ( $has_steps ? 'batch' : 'simple' ),
		);
	}

	/**
	 * Execute bulk pipeline creation.
	 *
	 * @param array $input Input parameters including pipelines array and optional template.
	 * @return array Result with created pipelines data and error tracking.
	 */
	private function executeBulk( array $input ): array {
		$pipelines     = $input['pipelines'];
		$template      = $input['template'] ?? array();
		$validate_only = ! empty( $input['validate_only'] );

		// Pre-validation: check handler slugs in template.
		$template_workflow = isset( $template['workflow'] ) && is_array( $template['workflow'] ) ? $template['workflow'] : array();
		$template_steps    = $template['steps'] ?? array();
		if ( ! empty( $template_workflow ) ) {
			$validation = $this->validateWorkflow( $template_workflow );
			if ( true !== $validation ) {
				return array(
					'success' => false,
					'error'   => 'Template workflow validation failed: ' . $validation,
				);
			}

			$handler_validation = $this->validateHandlerSlugs( $template_workflow['steps'] );
			if ( true !== $handler_validation ) {
				return $handler_validation;
			}
		} elseif ( ! empty( $template_steps ) ) {
			$validation = $this->validateSteps( $template_steps );
			if ( true !== $validation ) {
				return array(
					'success' => false,
					'error'   => 'Template steps validation failed: ' . $validation,
				);
			}

			$handler_validation = $this->validateHandlerSlugs( $template_steps );
			if ( true !== $handler_validation ) {
				return $handler_validation;
			}
		}

		// Pre-validation: validate all pipeline entries
		$validation_errors = array();
		foreach ( $pipelines as $index => $pipeline_config ) {
			$name = $pipeline_config['name'] ?? null;
			if ( empty( $name ) || ! is_string( $name ) ) {
				$validation_errors[] = array(
					'index'       => $index,
					'error'       => 'Pipeline name is required and must be a non-empty string',
					'remediation' => 'Provide a "name" property for each pipeline in the pipelines array',
				);
				continue;
			}

			$per_pipeline_workflow = isset( $pipeline_config['workflow'] ) && is_array( $pipeline_config['workflow'] ) ? $pipeline_config['workflow'] : array();

			// Per-pipeline workflow wins over per-pipeline steps.
			if ( ! empty( $per_pipeline_workflow ) ) {
				$workflow_validation = $this->validateWorkflow( $per_pipeline_workflow );
				if ( true !== $workflow_validation ) {
					$validation_errors[] = array(
						'index'       => $index,
						'name'        => $name,
						'error'       => 'Workflow validation failed: ' . $workflow_validation,
						'remediation' => 'Fix the workflow configuration for this pipeline',
					);
					continue;
				}

				$handler_validation = $this->validateHandlerSlugs( $per_pipeline_workflow['steps'] );
				if ( true !== $handler_validation ) {
					$validation_errors[] = $this->handlerValidationError( $index, $name, $handler_validation );
				}
				continue;
			}

			// Validate per-pipeline steps if provided.
			$per_pipeline_steps = $pipeline_config['steps'] ?? array();
			if ( ! empty( $per_pipeline_steps ) ) {
				$steps_validation = $this->validateSteps( $per_pipeline_steps );
				if ( true !== $steps_validation ) {
					$validation_errors[] = array(
						'index'       => $index,
						'name'        => $name,
						'error'       => 'Steps validation failed: ' . $steps_validation,
						'remediation' => 'Fix the step configuration for this pipeline',
					);
					continue;
				}

				$handler_validation = $this->validateHandlerSlugs( $per_pipeline_steps );
				if ( true !== $handler_validation ) {
					$validation_errors[] = $this->handlerValidationError( $index, $name, $handler_validation );
				}
			}
		}

		if ( ! empty( $validation_errors ) ) {
			return array(
				'success' => false,
				'error'   => 'Validation failed for ' . count( $validation_errors ) . ' pipeline(s)',
				'errors'  => $validation_errors,
			);
		}

		// Validate-only mode: return preview without executing
		if ( $validate_only ) {
			$preview = array();
			foreach ( $pipelines as $index => $pipeline_config ) {
				$name     = $pipeline_config['name'];
				$resolved = $this->resolveBulkPipelineSpec( $pipeline_config, $template_workflow, $template_steps );
				$workflow = $resolved['workflow'];
				$steps    = $resolved['steps'];

				$preview_item = array(
					'name'          => $name,
					'steps'         => ! empty( $workflow ) ? count( $workflow['steps'] ?? array() ) : count( $steps ),
					'creation_mode' => ! empty( $workflow ) ? 'workflow' : ( ! empty( $steps ) ? 'batch' : 'simple' ),
				);

				$has_flow = isset( $pipeline_config['flow_name'] ) || isset( $pipeline_config['scheduling_config'] ) || isset( $template['scheduling_config'] );
				if ( $has_flow ) {
					$scheduling_config          = $pipeline_config['scheduling_config'] ?? ( $template['scheduling_config'] ?? array( 'interval' => 'manual' ) );
					$preview_item['flow_name']  = $pipeline_config['flow_name'] ?? $name;
					$preview_item['scheduling'] = $scheduling_config['interval'] ?? 'manual';
				}

				$preview[] = $preview_item;
			}

			return array(
				'success'      => true,
				'valid'        => true,
				'mode'         => 'validate_only',
				'would_create' => $preview,
				'message'      => sprintf( 'Validation passed. Would create %d pipeline(s).', count( $pipelines ) ),
			);
		}

		// Execute bulk creation
		$created       = array();
		$errors        = array();
		$created_count = 0;
		$failed_count  = 0;

		$agent_id = isset( $input['agent_id'] ) ? (int) $input['agent_id'] : null;

		foreach ( $pipelines as $index => $pipeline_config ) {
			$name     = $pipeline_config['name'];
			$resolved = $this->resolveBulkPipelineSpec( $pipeline_config, $template_workflow, $template_steps );
			$workflow = $resolved['workflow'];
			$steps    = $resolved['steps'];

			$single_input = array(
				'pipeline_name' => $name,
			);

			if ( ! empty( $workflow ) ) {
				$single_input['workflow'] = $workflow;
			} else {
				$single_input['steps'] = $steps;
			}

			if ( null !== $agent_id && $agent_id > 0 ) {
				$single_input['agent_id'] = $agent_id;
			}

			// Only create a flow if flow_name or scheduling_config is explicitly provided.
			$has_flow_config = isset( $pipeline_config['flow_name'] ) || isset( $pipeline_config['scheduling_config'] ) || isset( $template['scheduling_config'] );
			if ( $has_flow_config ) {
				$single_input['flow_config'] = array(
					'flow_name'         => $pipeline_config['flow_name'] ?? $name,
					'scheduling_config' => $pipeline_config['scheduling_config'] ?? ( $template['scheduling_config'] ?? array( 'interval' => 'manual' ) ),
				);
			}

			$single_result = $this->executeSingle( $single_input );

			if ( $single_result['success'] ) {
				++$created_count;
				$created[] = array(
					'pipeline_id'   => $single_result['pipeline_id'],
					'pipeline_name' => $single_result['pipeline_name'],
					'flow_id'       => $single_result['flow_id'],
					'flow_name'     => $single_result['flow_name'],
					'steps_created' => $single_result['steps_created'],
					'flow_step_ids' => $single_result['flow_step_ids'],
				);
			} else {
				++$failed_count;
				$errors[] = array(
					'index'       => $index,
					'name'        => $name,
					'error'       => $single_result['error'],
					'remediation' => 'Check the error message and fix the pipeline configuration',
				);
			}
		}

		$partial = $created_count > 0 && $failed_count > 0;

		do_action(
			'datamachine_log',
			'info',
			'Bulk pipeline creation completed',
			array(
				'created_count' => $created_count,
				'failed_count'  => $failed_count,
				'partial'       => $partial,
			)
		);

		if ( 0 === $created_count ) {
			return array(
				'success'       => false,
				'error'         => 'All pipeline creations failed',
				'created_count' => 0,
				'failed_count'  => $failed_count,
				'errors'        => $errors,
			);
		}

		$message = sprintf( 'Created %d pipeline(s).', $created_count );
		if ( $failed_count > 0 ) {
			$message .= sprintf( ' %d failed.', $failed_count );
		}

		return array(
			'success'       => true,
			'created_count' => $created_count,
			'failed_count'  => $failed_count,
			'created'       => $created,
			'errors'        => $errors,
			'partial'       => $partial,
			'message'       => $message,
			'creation_mode' => 'bulk',
		);
	}

	/**
	 * Resolve the effective step source for a bulk pipeline entry.
	 *
	 * @param array $pipeline_config Pipeline entry config.
	 * @param array $template_workflow Shared workflow template.
	 * @param array $template_steps Shared legacy steps template.
	 * @return array{workflow: array, steps: array}
	 */
	private function resolveBulkPipelineSpec( array $pipeline_config, array $template_workflow, array $template_steps ): array {
		$workflow = isset( $pipeline_config['workflow'] ) && is_array( $pipeline_config['workflow'] ) && ! empty( $pipeline_config['workflow'] )
			? $pipeline_config['workflow']
			: array();
		$steps    = array();

		if ( empty( $workflow ) ) {
			if ( ! empty( $pipeline_config['steps'] ) ) {
				$steps = $pipeline_config['steps'];
			} elseif ( ! empty( $template_workflow ) ) {
				$workflow = $template_workflow;
			} else {
				$steps = $template_steps;
			}
		}

		return array(
			'workflow' => $workflow,
			'steps'    => $steps,
		);
	}

	/**
	 * Build a bulk validation error for invalid handler slugs.
	 *
	 * @param int   $index Pipeline entry index.
	 * @param string $name Pipeline name.
	 * @param array $handler_validation Handler validation result.
	 * @return array<string,mixed> Validation error item.
	 */
	private function handlerValidationError( int $index, string $name, array $handler_validation ): array {
		return array(
			'index'       => $index,
			'name'        => $name,
			'error'       => $handler_validation['error'],
			'remediation' => 'Use valid handler slugs from the list_handlers tool',
		);
	}
}
