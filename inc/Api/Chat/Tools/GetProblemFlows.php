<?php
/**
 * Get Problem Flows Tool
 *
 * Identifies flows with issues that may need attention:
 * - Consecutive failures (something is broken)
 * - Consecutive no-items (source is slow/exhausted, consider lowering interval)
 *
 * Uses the problem_flow_threshold setting by default.
 * Delegates to JobAbilities for core logic.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\Tools\BaseTool;

class GetProblemFlows extends BaseTool {

	public function __construct() {
		$this->registerTool( 'get_problem_flows', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	/**
	 * Get tool definition.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		$default_threshold = PluginSettings::get( 'problem_flow_threshold', 3 );

		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => "Identify flows with issues: consecutive failures (broken) or consecutive no-items runs (source exhausted). Default threshold: {$default_threshold}.",
			'parameters'  => array(
				'threshold' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => "Minimum consecutive count to report (default: {$default_threshold} from settings)",
				),
			),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $parameters Tool call parameters
	 * @param array $tool_def Tool definition
	 * @return array Tool execution result
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$ability = wp_get_ability( 'datamachine/get-problem-flows' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Problem flows ability not available',
				'tool_name' => 'get_problem_flows',
			);
		}

		$input = array(
			'threshold' => $parameters['threshold'] ?? null,
		);

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => 'get_problem_flows',
			);
		}

		if ( ! ( $result['success'] ?? false ) ) {
			return array(
				'success'   => false,
				'error'     => $result['error'] ?? 'Failed to get problem flows',
				'tool_name' => 'get_problem_flows',
			);
		}

		return array(
			'success'   => true,
			'data'      => array(
				'problem_flows' => array_merge( $result['failing'] ?? array(), $result['idle'] ?? array() ),
				'total'         => $result['count'] ?? 0,
				'failing_count' => count( $result['failing'] ?? array() ),
				'idle_count'    => count( $result['idle'] ?? array() ),
				'threshold'     => $result['threshold'] ?? 3,
				'message'       => $result['message'] ?? '',
			),
			'tool_name' => 'get_problem_flows',
		);
	}
}
