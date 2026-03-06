<?php
/**
 * Centralized parameter building for AI tool execution.
 *
 * Universal parameter building infrastructure shared by Chat and Pipeline agents.
 *
 * @package DataMachine\Engine\AI\Tools
 * @since 0.2.0
 */

namespace DataMachine\Engine\AI\Tools;

defined( 'ABSPATH' ) || exit;

class ToolParameters {

	/**
	 * Build unified flat parameter structure for tool execution.
	 *
	 * @param array $ai_tool_parameters Parameters from AI
	 * @param array $payload Step payload (job_id, flow_step_id, data, flow_step_config)
	 * @param array $tool_definition Tool definition array
	 * @return array Complete parameters for tool handler
	 */
	public static function buildParameters(
		array $ai_tool_parameters,
		array $payload,
		array $tool_definition
	): array {
		$tool_definition;
		// Start with payload (contains job_id, flow_step_id, data, flow_step_config)
		$parameters = $payload;

		// Merge AI-provided parameters on top (content, title, query, etc.)
		foreach ( $ai_tool_parameters as $key => $value ) {
			$parameters[ $key ] = $value;
		}

		return $parameters;
	}
}
