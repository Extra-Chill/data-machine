<?php
/**
 * Create Pipeline Tool
 *
 * Focused tool for creating pipelines with optional predefined steps.
 * Automatically creates an associated flow for immediate configuration.
 * Uses PipelineAbilities API primitive for centralized logic.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Abilities\StepTypeAbilities;
use DataMachine\Engine\AI\Tools\BaseTool;

class CreatePipeline extends BaseTool {

	public function __construct() {
		$this->registerTool( 'chat', 'create_pipeline', array( $this, 'getToolDefinition' ) );
	}

	private static function getValidStepTypes(): array {
		$step_type_abilities = new StepTypeAbilities();
		return array_keys( $step_type_abilities->getAllStepTypes() );
	}

	/**
	 * Get tool definition.
	 * Called lazily when tool is first accessed to ensure translations are loaded.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		$valid_types = self::getValidStepTypes();
		$types_list  = ! empty( $valid_types ) ? implode( '|', $valid_types ) : 'fetch|ai|publish|update';
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Create a pipeline with optional steps. Automatically creates a flow - do NOT call create_flow afterward. Supports bulk mode via pipelines array.',
			'parameters'  => array(
				'pipeline_name'     => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Pipeline name (single mode - required unless using bulk mode)',
				),
				'steps'             => array(
					'type'        => 'array',
					'required'    => false,
					'description' => "Steps in execution order: {step_type: \"{$types_list}\", handler_slug, handler_config}. AI steps: add provider, model, system_prompt.",
				),
				'flow_name'         => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Flow name (defaults to pipeline_name)',
				),
				'scheduling_config' => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'Schedule: {interval: value}. Valid intervals:' . "\n" . SchedulingDocumentation::getIntervalsJson(),
				),
				'pipelines'         => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Bulk mode: create multiple pipelines. Each item: {name, steps?, flow_name?, scheduling_config?}. Uses template for shared config.',
				),
				'template'          => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'Shared config for bulk mode: {steps, scheduling_config}. Individual pipeline configs override template.',
				),
				'validate_only'     => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'Dry-run mode: validate configuration without creating. Returns what would be created.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		// Check for bulk mode
		if ( ! empty( $parameters['pipelines'] ) && is_array( $parameters['pipelines'] ) ) {
			return $this->handleBulkMode( $parameters );
		}

		$pipeline_name = $parameters['pipeline_name'] ?? null;

		if ( empty( $pipeline_name ) || ! is_string( $pipeline_name ) ) {
			return array(
				'success'   => false,
				'error'     => 'pipeline_name is required and must be a non-empty string',
				'tool_name' => 'create_pipeline',
			);
		}

		$steps             = $parameters['steps'] ?? array();
		$flow_name         = $parameters['flow_name'] ?? $pipeline_name;
		$scheduling_config = $parameters['scheduling_config'] ?? array( 'interval' => 'manual' );

		$scheduling_validation = $this->validateSchedulingConfig( $scheduling_config );
		if ( true !== $scheduling_validation ) {
			return array(
				'success'   => false,
				'error'     => $scheduling_validation,
				'tool_name' => 'create_pipeline',
			);
		}

		if ( ! empty( $steps ) ) {
			$steps_validation = $this->validateSteps( $steps );
			if ( true !== $steps_validation ) {
				return array(
					'success'   => false,
					'error'     => $steps_validation,
					'tool_name' => 'create_pipeline',
				);
			}

			$steps = $this->normalizeSteps( $steps );
		}

		$ability = wp_get_ability( 'datamachine/create-pipeline' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Create pipeline ability not available',
				'tool_name' => 'create_pipeline',
			);
		}

		$result = $ability->execute(
			array(
				'pipeline_name' => $pipeline_name,
				'steps'         => $steps,
				'flow_config'   => array(
					'flow_name'         => $flow_name,
					'scheduling_config' => $scheduling_config,
				),
			)
		);

		if ( ! ( $result['success'] ?? false ) ) {
			return array(
				'success'   => false,
				'error'     => $result['error'] ?? 'Failed to create pipeline. Check logs for details.',
				'tool_name' => 'create_pipeline',
			);
		}

		$flow_id       = $result['flow_id'] ?? null;
		$flow_step_ids = $result['flow_step_ids'] ?? array();
		$steps_created = $result['steps_created'] ?? 0;

		return array(
			'success'   => true,
			'data'      => array(
				'pipeline_id'   => $result['pipeline_id'],
				'pipeline_name' => $result['pipeline_name'],
				'flow_id'       => $flow_id,
				'flow_name'     => $flow_name,
				'steps_created' => $steps_created,
				'flow_step_ids' => $flow_step_ids,
				'scheduling'    => $scheduling_config['interval'],
				'message'       => 0 === $steps_created
					? "Pipeline and flow (ID: {$flow_id}) created. Use add_pipeline_step to add steps, then configure_flow_steps to configure handlers."
					: "Pipeline and flow (ID: {$flow_id}) created with {$steps_created} steps. Use configure_flow_steps with the flow_step_ids to set handler configurations.",
			),
			'tool_name' => 'create_pipeline',
		);
	}

	/**
	 * Handle bulk pipeline creation mode.
	 *
	 * @param array $parameters Tool parameters including pipelines array.
	 * @return array Tool response.
	 */
	private function handleBulkMode( array $parameters ): array {
		$pipelines     = $parameters['pipelines'];
		$template      = $parameters['template'] ?? array();
		$validate_only = ! empty( $parameters['validate_only'] );

		// Normalize template steps if provided
		$template_steps = $template['steps'] ?? array();
		if ( ! empty( $template_steps ) ) {
			$steps_validation = $this->validateSteps( $template_steps );
			if ( true !== $steps_validation ) {
				return array(
					'success'   => false,
					'error'     => 'Template steps validation failed: ' . $steps_validation,
					'tool_name' => 'create_pipeline',
				);
			}
			$template['steps'] = $this->normalizeSteps( $template_steps );
		}

		// Validate and normalize per-pipeline steps
		foreach ( $pipelines as $index => &$pipeline_config ) {
			if ( ! empty( $pipeline_config['steps'] ) ) {
				$steps_validation = $this->validateSteps( $pipeline_config['steps'] );
				if ( true !== $steps_validation ) {
					return array(
						'success'   => false,
						'error'     => "Pipeline at index {$index} steps validation failed: " . $steps_validation,
						'tool_name' => 'create_pipeline',
					);
				}
				$pipeline_config['steps'] = $this->normalizeSteps( $pipeline_config['steps'] );
			}
		}
		unset( $pipeline_config );

		$ability = wp_get_ability( 'datamachine/create-pipeline' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Create pipeline ability not available',
				'tool_name' => 'create_pipeline',
			);
		}

		$result = $ability->execute(
			array(
				'pipelines'     => $pipelines,
				'template'      => $template,
				'validate_only' => $validate_only,
			)
		);

		$result['tool_name'] = 'create_pipeline';

		if ( $result['success'] ?? false ) {
			if ( $validate_only ) {
				$result['data'] = array(
					'mode'         => 'validate_only',
					'would_create' => $result['would_create'] ?? array(),
					'message'      => $result['message'] ?? 'Validation passed.',
				);
				unset( $result['would_create'], $result['valid'], $result['mode'] );
			} else {
				$result['data'] = array(
					'created_count' => $result['created_count'],
					'failed_count'  => $result['failed_count'],
					'created'       => $result['created'],
					'errors'        => $result['errors'] ?? array(),
					'partial'       => $result['partial'] ?? false,
					'message'       => $result['message'] ?? 'Bulk creation completed.',
				);
				unset( $result['created_count'], $result['failed_count'], $result['created'], $result['errors'], $result['partial'], $result['creation_mode'] );
			}
		}

		return $result;
	}

	private function validateSchedulingConfig( array $config ): bool|string {
		if ( empty( $config ) ) {
			return true;
		}

		$interval = $config['interval'] ?? null;

		if ( null === $interval ) {
			return 'scheduling_config requires an interval property';
		}

		$intervals       = array_keys( apply_filters( 'datamachine_scheduler_intervals', array() ) );
		$valid_intervals = array_merge( array( 'manual', 'one_time' ), $intervals );
		if ( ! in_array( $interval, $valid_intervals, true ) ) {
			return 'Invalid interval. Must be one of: ' . implode( ', ', $valid_intervals );
		}

		if ( 'one_time' === $interval ) {
			$timestamp = $config['timestamp'] ?? null;
			if ( ! is_numeric( $timestamp ) || (int) $timestamp <= 0 ) {
				return 'one_time interval requires a valid unix timestamp';
			}
		}

		return true;
	}

	private function validateSteps( array $steps ): bool|string {
		foreach ( $steps as $index => $step ) {
			// Accept shorthand: "event_import" → {"step_type": "event_import"}
			if ( is_string( $step ) ) {
				$step = array( 'step_type' => $step );
			}

			if ( ! is_array( $step ) ) {
				return "Step at index {$index} must be a string or object";
			}

			$step_type = $step['step_type'] ?? null;
			if ( empty( $step_type ) ) {
				return "Step at index {$index} is missing required step_type";
			}

			$valid_types = self::getValidStepTypes();
			if ( ! in_array( $step_type, $valid_types, true ) ) {
				return "Step at index {$index} has invalid step_type '{$step_type}'. Must be one of: " . implode( ', ', $valid_types );
			}
		}

		return true;
	}

	private function normalizeSteps( array $steps ): array {
		$normalized = array();
		foreach ( $steps as $index => $step ) {
			// Accept shorthand: "event_import" → {"step_type": "event_import"}
			if ( is_string( $step ) ) {
				$step = array( 'step_type' => $step );
			}

			$handler_slug   = $step['handler_slug'] ?? null;
			$handler_config = $step['handler_config'] ?? array();

			$normalized_step = array(
				'step_type'       => $step['step_type'],
				'execution_order' => $step['execution_order'] ?? $index,
				'handler_slugs'   => ! empty( $handler_slug ) ? array( $handler_slug ) : array(),
				'handler_configs' => ! empty( $handler_slug ) ? array( $handler_slug => $handler_config ) : array(),
			);

			if ( isset( $step['provider'] ) ) {
				$normalized_step['provider'] = $step['provider'];
			}
			if ( isset( $step['model'] ) ) {
				$normalized_step['model'] = $step['model'];
			}
			if ( isset( $step['system_prompt'] ) ) {
				$normalized_step['system_prompt'] = $step['system_prompt'];
			}

			$normalized[] = $normalized_step;
		}
		return $normalized;
	}
}
