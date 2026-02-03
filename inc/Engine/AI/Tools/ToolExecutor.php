<?php
/**
 * Universal AI tool execution infrastructure.
 *
 * Shared tool execution logic used by both Chat and Pipeline agents.
 * Handles tool discovery, validation, execution, and parameter building.
 *
 * @package DataMachine\Engine\AI\Tools
 * @since 0.2.0
 */

namespace DataMachine\Engine\AI\Tools;

defined( 'ABSPATH' ) || exit;

class ToolExecutor {

	/**
	 * Get available tools for AI agent execution.
	 * Used by both chat and pipeline agents.
	 *
	 * @param array|null  $previous_step_config Previous step configuration (pipeline only)
	 * @param array|null  $next_step_config Next step configuration (pipeline only)
	 * @param string|null $current_pipeline_step_id Current pipeline step ID (pipeline only)
	 * @param array       $engine_data Engine data snapshot for dynamic tool generation
	 * @return array Available tools array
	 */
	public static function getAvailableTools( ?array $previous_step_config = null, ?array $next_step_config = null, ?string $current_pipeline_step_id = null, array $engine_data = array() ): array {
		$available_tools = array();
		$tool_manager    = new ToolManager();

		if ( $previous_step_config ) {
			$prev_handler_slug   = $previous_step_config['handler_slug'] ?? null;
			$prev_handler_config = $previous_step_config['handler_config'] ?? array();

			if ( $prev_handler_slug ) {
				$prev_tools         = apply_filters( 'chubes_ai_tools', array(), $prev_handler_slug, $prev_handler_config, $engine_data );
				$prev_tools         = self::resolveTools( $prev_tools );
				$allowed_prev_tools = self::getAllowedTools( $prev_tools, $prev_handler_slug, $current_pipeline_step_id, $tool_manager );
				$available_tools    = array_merge( $available_tools, $allowed_prev_tools );
			}
		}

		if ( $next_step_config ) {
			$next_handler_slug   = $next_step_config['handler_slug'] ?? null;
			$next_handler_config = $next_step_config['handler_config'] ?? array();

			if ( $next_handler_slug ) {
				$next_tools         = apply_filters( 'chubes_ai_tools', array(), $next_handler_slug, $next_handler_config, $engine_data );
				$next_tools         = self::resolveTools( $next_tools );
				$allowed_next_tools = self::getAllowedTools( $next_tools, $next_handler_slug, $current_pipeline_step_id, $tool_manager );
				$available_tools    = array_merge( $available_tools, $allowed_next_tools );
			}
		}

		// Load global tools (available to all AI agents) - use ToolManager which resolves callables
		$global_tools         = $tool_manager->get_global_tools();
		$allowed_global_tools = self::getAllowedTools( $global_tools, null, $current_pipeline_step_id, $tool_manager );
		$available_tools      = array_merge( $available_tools, $allowed_global_tools );

		return array_unique( $available_tools, SORT_REGULAR );
	}

	/**
	 * Resolve tool definitions from callables to arrays.
	 *
	 * Tool definitions may be registered as callables for lazy evaluation
	 * (e.g., to defer translations until after init). This method invokes
	 * callables and returns the resolved array definitions.
	 *
	 * @param array $tools Raw tools array (may contain callables)
	 * @return array Resolved tools array with all definitions as arrays
	 */
	private static function resolveTools( array $tools ): array {
		$resolved = array();
		foreach ( $tools as $tool_id => $definition ) {
			if ( is_callable( $definition ) ) {
				$resolved[ $tool_id ] = $definition();
			} else {
				$resolved[ $tool_id ] = is_array( $definition ) ? $definition : array();
			}
		}
		return $resolved;
	}

	/**
	 * Get allowed tools based on enablement and configuration.
	 *
	 * @param array       $all_tools All available tools (must be resolved, not callables)
	 * @param string|null $handler_slug Handler slug for filtering
	 * @param string|null $pipeline_step_id Pipeline step ID (pipeline only, null for chat)
	 * @param ToolManager $tool_manager Tool manager instance for availability checks
	 * @return array Filtered allowed tools
	 */
	private static function getAllowedTools( array $all_tools, ?string $handler_slug, ?string $pipeline_step_id, ToolManager $tool_manager ): array {
		$allowed_tools = array();

		foreach ( $all_tools as $tool_name => $tool_config ) {
			// Skip if not a valid array definition
			if ( ! is_array( $tool_config ) ) {
				continue;
			}

			if ( isset( $tool_config['handler'] ) ) {
				if ( $tool_config['handler'] === $handler_slug ) {
					$allowed_tools[ $tool_name ] = $tool_config;
				}
				continue;
			}

			// Direct ToolManager call replaces filter
			if ( $tool_manager->is_tool_available( $tool_name, $pipeline_step_id ) ) {
				$allowed_tools[ $tool_name ] = $tool_config;
			}
		}

		return $allowed_tools;
	}

	/**
	 * Execute tool with parameter merging and comprehensive error handling.
	 * Builds complete parameters by combining AI parameters with step payload.
	 *
	 * @param string $tool_name Tool name to execute
	 * @param array  $tool_parameters Parameters from AI
	 * @param array  $available_tools Available tools array
	 * @param array  $payload Step payload (job_id, flow_step_id, data, flow_step_config)
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

		$validation = self::validateRequiredParameters( $tool_parameters, $tool_def );
		if ( ! $validation['valid'] ) {
			return array(
				'success'   => false,
				'error'     => sprintf(
					'%s requires the following parameters: %s. Please provide these parameters and try again.',
					ucwords( str_replace( '_', ' ', $tool_name ) ),
					implode( ', ', $validation['missing'] )
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
		if ( ! isset( $tool_def['class'] ) || empty( $tool_def['class'] ) ) {
			return array(
				'success'   => false,
				'error'     => "Tool '{$tool_name}' is missing required 'class' definition. This may indicate the tool was not properly resolved from a callable.",
				'tool_name' => $tool_name,
			);
		}

		$class_name = $tool_def['class'];
		if ( ! class_exists( $class_name ) ) {
			return array(
				'success'   => false,
				'error'     => "Tool class '{$class_name}' not found",
				'tool_name' => $tool_name,
			);
		}

		$tool_handler = new $class_name();
		$tool_result  = $tool_handler->handle_tool_call( $complete_parameters, $tool_def );

		return $tool_result;
	}

	/**
	 * Validate that all required parameters are present.
	 *
	 * @param array $tool_parameters Parameters from AI
	 * @param array $tool_def Tool definition with parameter specs
	 * @return array Validation result with 'valid', 'required', and 'missing' keys
	 */
	private static function validateRequiredParameters( array $tool_parameters, array $tool_def ): array {
		$required = array();
		$missing  = array();

		$param_defs = $tool_def['parameters'] ?? array();

		foreach ( $param_defs as $param_name => $param_config ) {
			if ( ! is_array( $param_config ) ) {
				continue;
			}

			if ( ! empty( $param_config['required'] ) ) {
				$required[] = $param_name;

				if ( ! isset( $tool_parameters[ $param_name ] ) || '' === $tool_parameters[ $param_name ] ) {
					$missing[] = $param_name;
				}
			}
		}

		return array(
			'valid'    => empty( $missing ),
			'required' => $required,
			'missing'  => $missing,
		);
	}
}
