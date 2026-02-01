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

defined( 'ABSPATH' ) || exit;

class CreatePipelineAbility {

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
				'datamachine/create-pipeline',
				array(
					'label'               => __( 'Create Pipeline', 'data-machine' ),
					'description'         => __( 'Create a new pipeline with optional steps and flow configuration. Supports bulk mode via pipelines array.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'pipeline_name' => array(
								'type'        => 'string',
								'description' => __( 'Pipeline name (single mode)', 'data-machine' ),
							),
							'steps'         => array(
								'type'        => 'array',
								'description' => __( 'Optional steps configuration (each with step_type, optional label)', 'data-machine' ),
							),
							'flow_config'   => array(
								'type'        => 'object',
								'description' => __( 'Optional flow configuration (flow_name, scheduling_config)', 'data-machine' ),
							),
							'pipelines'     => array(
								'type'        => 'array',
								'description' => __( 'Bulk mode: create multiple pipelines. Each item: {name, steps?, flow_name?, scheduling_config?}', 'data-machine' ),
							),
							'template'      => array(
								'type'        => 'object',
								'description' => __( 'Shared config for bulk mode applied to all pipelines: {steps, scheduling_config}', 'data-machine' ),
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
							'pipeline_id'   => array( 'type' => 'integer' ),
							'pipeline_name' => array( 'type' => 'string' ),
							'flow_id'       => array( 'type' => 'integer' ),
							'flow_name'     => array( 'type' => 'string' ),
							'steps_created' => array( 'type' => 'integer' ),
							'flow_step_ids' => array( 'type' => 'array' ),
							'creation_mode' => array( 'type' => 'string' ),
							'created_count' => array( 'type' => 'integer' ),
							'failed_count'  => array( 'type' => 'integer' ),
							'created'       => array( 'type' => 'array' ),
							'errors'        => array( 'type' => 'array' ),
							'partial'       => array( 'type' => 'boolean' ),
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

		$steps       = $input['steps'] ?? array();
		$flow_config = $input['flow_config'] ?? array();

		$has_steps = ! empty( $steps ) && is_array( $steps );

		if ( $has_steps ) {
			$validation = $this->validateSteps( $steps );
			if ( true !== $validation ) {
				return array(
					'success' => false,
					'error'   => $validation,
				);
			}
		}

		$pipeline_id = $this->db_pipelines->create_pipeline(
			array(
				'pipeline_name'   => $pipeline_name,
				'pipeline_config' => array(),
			)
		);

		if ( ! $pipeline_id ) {
			do_action( 'datamachine_log', 'error', 'Failed to create pipeline', array( 'pipeline_name' => $pipeline_name ) );
			return array(
				'success' => false,
				'error'   => 'Failed to create pipeline',
			);
		}

		$pipeline_config = array();
		$steps_created   = 0;

		if ( $has_steps ) {
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

		$flow_name         = $flow_config['flow_name'] ?? $pipeline_name;
		$scheduling_config = $flow_config['scheduling_config'] ?? array( 'interval' => 'manual' );

		$create_flow_ability = wp_get_ability( 'datamachine/create-flow' );
		$flow_result         = null;
		if ( $create_flow_ability ) {
			$flow_result = $create_flow_ability->execute(
				array(
					'pipeline_id'       => $pipeline_id,
					'flow_name'         => $flow_name,
					'scheduling_config' => $scheduling_config,
				)
			);
		}

		if ( ! $flow_result || ! $flow_result['success'] ) {
			do_action( 'datamachine_log', 'error', "Failed to create flow for pipeline {$pipeline_id}" );
		}

		$flow_step_ids = array();
		if ( $flow_result && $flow_result['success'] && ! empty( $flow_result['flow_data']['flow_config'] ) ) {
			$flow_step_ids = array_keys( $flow_result['flow_data']['flow_config'] );
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
			'creation_mode' => $has_steps ? 'batch' : 'simple',
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

		// Pre-validation: check handler slugs in template
		$template_steps = $template['steps'] ?? array();
		if ( ! empty( $template_steps ) ) {
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

			// Validate per-pipeline steps if provided
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
					$validation_errors[] = array(
						'index'       => $index,
						'name'        => $name,
						'error'       => $handler_validation['error'],
						'remediation' => 'Use valid handler slugs from the list_handlers tool',
					);
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
				$name              = $pipeline_config['name'];
				$steps             = ! empty( $pipeline_config['steps'] ) ? $pipeline_config['steps'] : $template_steps;
				$flow_name         = $pipeline_config['flow_name'] ?? $name;
				$scheduling_config = $pipeline_config['scheduling_config'] ?? ( $template['scheduling_config'] ?? array( 'interval' => 'manual' ) );

				$preview[] = array(
					'name'       => $name,
					'flow_name'  => $flow_name,
					'steps'      => count( $steps ),
					'scheduling' => $scheduling_config['interval'] ?? 'manual',
				);
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

		foreach ( $pipelines as $index => $pipeline_config ) {
			$name              = $pipeline_config['name'];
			$steps             = ! empty( $pipeline_config['steps'] ) ? $pipeline_config['steps'] : $template_steps;
			$flow_name         = $pipeline_config['flow_name'] ?? $name;
			$scheduling_config = $pipeline_config['scheduling_config'] ?? ( $template['scheduling_config'] ?? array( 'interval' => 'manual' ) );

			$single_result = $this->executeSingle(
				array(
					'pipeline_name' => $name,
					'steps'         => $steps,
					'flow_config'   => array(
						'flow_name'         => $flow_name,
						'scheduling_config' => $scheduling_config,
					),
				)
			);

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
}
