<?php
/**
 * Run Flow Tool
 *
 * Tool for executing existing flows immediately or scheduling delayed execution.
 * Delegates to JobAbilities for core logic.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class RunFlow extends BaseTool {

	public function __construct() {
		$this->registerTool( 'run_flow', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	/**
	 * Get tool definition.
	 * Called lazily when tool is first accessed to ensure translations are loaded.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Execute an existing flow immediately or schedule it for later. For IMMEDIATE execution: provide only flow_id (do NOT include timestamp). For SCHEDULED execution: provide flow_id AND a future Unix timestamp. Flows run asynchronously in the background. Use api_query with GET /datamachine/v1/jobs/{job_id} to check execution status.',
			'parameters'  => array(
				'flow_id'   => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Flow ID to execute',
				),
				'count'     => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Number of times to run the flow (1-10, default 1). Each run spawns an independent job. Use this to process multiple items from a source.',
				),
				'timestamp' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'ONLY for scheduled execution: a future Unix timestamp. OMIT this parameter entirely for immediate execution. Cannot be combined with count > 1.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$ability = wp_get_ability( 'datamachine/execute-workflow' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Execute workflow ability not available',
				'tool_name' => 'run_flow',
			);
		}

		$input = array(
			'flow_id'   => $parameters['flow_id'] ?? null,
			'count'     => $parameters['count'] ?? 1,
			'timestamp' => $parameters['timestamp'] ?? null,
		);

		$result = $ability->execute( $input );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			$error = $this->getAbilityError( $result, 'Failed to run flow' );
			return $this->buildErrorResponse( $error, 'run_flow' );
		}

		$response_data = array(
			'flow_id'        => $result['flow_id'] ?? $input['flow_id'],
			'execution_type' => $result['execution_type'] ?? 'immediate',
			'message'        => $result['message'] ?? 'Flow execution started',
		);

		if ( isset( $result['flow_name'] ) ) {
			$response_data['flow_name'] = $result['flow_name'];
		}

		if ( isset( $result['job_id'] ) ) {
			$response_data['job_id'] = $result['job_id'];
		}

		if ( isset( $result['job_ids'] ) ) {
			$response_data['job_ids'] = $result['job_ids'];
			$response_data['count']   = $result['count'] ?? count( $result['job_ids'] );
		}

		return array(
			'success'   => true,
			'data'      => $response_data,
			'tool_name' => 'run_flow',
		);
	}
}
