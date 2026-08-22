<?php
/**
 * Directive Interface
 *
 * Standardized contract for directives that provide system message outputs.
 * Directives never mutate AI request structures directly.
 *
 * @package DataMachine\Engine\AI\Directives
 */

namespace DataMachine\Engine\AI\Directives;

defined( 'ABSPATH' ) || exit;

interface DirectiveInterface {

	/**
	 * Get directive outputs for the current request.
	 *
	 * @param string      $provider_name AI provider name
	 * @param array       $tools         Available tools (provider-agnostic)
	 * @param string|null $step_id       Step/session identifier (pipeline_step_id or session_id)
	 * @param array       $payload       Execution payload (job_id, flow_step_id, etc.)
	 * @return array Array of directive outputs
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array;
}
