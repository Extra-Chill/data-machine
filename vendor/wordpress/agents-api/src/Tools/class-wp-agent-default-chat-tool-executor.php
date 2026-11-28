<?php
/**
 * Default chat tool executor router.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

use AgentsAPI\AI\WP_Agent_Runtime_Tool_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Routes client tools to the runtime-tool continuation boundary.
 */
class WP_Agent_Default_Chat_Tool_Executor implements WP_Agent_Tool_Executor {

	/** @var WP_Agent_Tool_Executor */
	private WP_Agent_Tool_Executor $host_executor;

	public function __construct( WP_Agent_Tool_Executor $host_executor ) {
		$this->host_executor = $host_executor;
	}

	/**
	 * Suspend client-owned tools; preserve host executor behavior unchanged.
	 *
	 * @param array<mixed> $tool_call       Normalized tool call.
	 * @param array<mixed> $tool_definition Selected declaration.
	 * @param array<mixed> $context         Runtime context.
	 * @return array<mixed>
	 */
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		if ( WP_Agent_Tool_Declaration::EXECUTOR_CLIENT !== ( $tool_definition['executor'] ?? null ) ) {
			return $this->host_executor->executeWP_Agent_Tool_Call( $tool_call, $tool_definition, $context );
		}

		$tool_call = WP_Agent_Tool_Call::normalize( $tool_call );
		$tool_name = is_string( $tool_call['tool_name'] ?? null ) ? $tool_call['tool_name'] : '';
		$tool_call_id = is_string( $tool_call['id'] ?? null ) ? $tool_call['id'] : '';
		$parameters = is_array( $tool_call['parameters'] ?? null )
			? \AgentsAPI\AI\agents_api_string_keyed_array( $tool_call['parameters'] )
			: array();
		$request   = WP_Agent_Runtime_Tool_Request::from_tool_call(
			$tool_name,
			$tool_call_id,
			$parameters,
			\AgentsAPI\AI\agents_api_string_keyed_array( $context ),
			WP_Agent_Tool_Declaration::normalizeRuntimeMetadata( $tool_definition['runtime'] ?? array() )
		);

		return array(
			'success'              => false,
			'tool_name'            => $tool_name,
			'error'                => 'Client tool execution is pending.',
			'status'               => WP_Agent_Runtime_Tool_Request::STATUS_PENDING,
			'runtime_tool_request' => $request,
		);
	}
}
