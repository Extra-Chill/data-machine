<?php
/**
 * Universal AI tool execution infrastructure.
 *
 * Shared tool execution logic used by both Chat and Pipeline agents.
 * Handles tool discovery, validation, execution, and parameter building.
 *
 * @package DataMachine\Engine\AI\Tools
 * @since   0.2.0
 */

namespace DataMachine\Engine\AI\Tools;

defined('ABSPATH') || exit;

class ToolExecutor {


	/**
	 * Get available tools for AI agent execution.
	 *
	 * @deprecated 0.39.0 Use ToolPolicyResolver::resolve() with CONTEXT_PIPELINE instead.
	 *             Delegates to ToolPolicyResolver internally.
	 *
	 * @param  array|null  $previous_step_config     Previous step configuration (pipeline only)
	 * @param  array|null  $next_step_config         Next step configuration (pipeline only)
	 * @param  string|null $current_pipeline_step_id Current pipeline step ID (pipeline only)
	 * @param  array       $engine_data              Engine data snapshot for dynamic tool generation
	 * @return array Available tools array
	 */
	public static function getAvailableTools( ?array $previous_step_config = null, ?array $next_step_config = null, ?string $current_pipeline_step_id = null, array $engine_data = array() ): array {
		$resolver = new ToolPolicyResolver();

		return $resolver->resolve( array(
			'context'              => ToolPolicyResolver::CONTEXT_PIPELINE,
			'previous_step_config' => $previous_step_config,
			'next_step_config'     => $next_step_config,
			'pipeline_step_id'     => $current_pipeline_step_id,
			'engine_data'          => $engine_data,
		) );
	}

	/**
	 * Execute tool with parameter merging and comprehensive error handling.
	 * Builds complete parameters by combining AI parameters with step payload.
	 *
	 * @param  string $tool_name       Tool name to execute
	 * @param  array  $tool_parameters Parameters from AI
	 * @param  array  $available_tools Available tools array
	 * @param  array  $payload         Step payload (job_id, flow_step_id, data, flow_step_config)
	 * @return array Tool execution result
	 */
	public static function executeTool( string $tool_name, array $tool_parameters, array $available_tools, array $payload ): array {
		$tool_def = $available_tools[ $tool_name ] ?? null;
		if ( ! $tool_def ) {
			return array(
				'success'   => false,
				'error'     => "Tool '{$tool_name}' not found",
				'tool_name' => $tool_name,
			);
		}

		$validation = self::validateRequiredParameters($tool_parameters, $tool_def);
		if ( ! $validation['valid'] ) {
			return array(
				'success'   => false,
				'error'     => sprintf(
					'%s requires the following parameters: %s. Please provide these parameters and try again.',
					ucwords(str_replace('_', ' ', $tool_name)),
					implode(', ', $validation['missing'])
				),
				'tool_name' => $tool_name,
			);
		}

		$complete_parameters = ToolParameters::buildParameters(
			$tool_parameters,
			$payload,
			$tool_def
		);

		// Ensure tool definition has required 'class' key
		if ( ! isset($tool_def['class']) || empty($tool_def['class']) ) {
			return array(
				'success'   => false,
				'error'     => "Tool '{$tool_name}' is missing required 'class' definition. This may indicate the tool was not properly resolved from a callable.",
				'tool_name' => $tool_name,
			);
		}

		$class_name = $tool_def['class'];
		if ( ! class_exists($class_name) ) {
			return array(
				'success'   => false,
				'error'     => "Tool class '{$class_name}' not found",
				'tool_name' => $tool_name,
			);
		}

		$method      = $tool_def['method'] ?? 'handle_tool_call';
		$tool_handler = new $class_name();
		$tool_result  = $tool_handler->$method($complete_parameters, $tool_def);

		return $tool_result;
	}

	/**
	 * Validate that all required parameters are present.
	 *
	 * @param  array $tool_parameters Parameters from AI
	 * @param  array $tool_def        Tool definition with parameter specs
	 * @return array Validation result with 'valid', 'required', and 'missing' keys
	 */
	private static function validateRequiredParameters( array $tool_parameters, array $tool_def ): array {
		$required = array();
		$missing  = array();

		$param_defs = $tool_def['parameters'] ?? array();

		foreach ( $param_defs as $param_name => $param_config ) {
			if ( ! is_array($param_config) ) {
				continue;
			}

			if ( ! empty($param_config['required']) ) {
				$required[] = $param_name;

				if ( ! isset($tool_parameters[ $param_name ]) || '' === $tool_parameters[ $param_name ] ) {
					$missing[] = $param_name;
				}
			}
		}

		return array(
			'valid'    => empty($missing),
			'required' => $required,
			'missing'  => $missing,
		);
	}
}
