<?php
/**
 * Manage Logs Tool
 *
 * Dedicated tool for managing Data Machine log configuration and storage.
 * Supports clearing logs and getting log metadata.
 *
 * @package DataMachine\Api\Chat\Tools
 * @since 0.8.2
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ManageLogs extends BaseTool {

	public function __construct() {
		$this->registerTool( 'manage_logs', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	/**
	 * Get tool definition.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => $this->buildDescription(),
			'parameters'  => array(
				'action'     => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action to perform: "clear" or "get_metadata"',
				),
				'agent_id'   => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Agent ID to target. Omit to target all logs.',
				),
				'context'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Deprecated label only. Use agent_id instead.',
				),
			),
		);
	}

	/**
	 * Build tool description.
	 *
	 * @return string Tool description
	 */
	private function buildDescription(): string {
		return 'Manage Data Machine logs.

ACTIONS:
- clear: Clear logs for a specific agent_id or all logs
- get_metadata: Get log metadata for a specific agent_id or all logs

NOTES:
- Logs are scoped by explicit agent_id
- Context names are presentation labels only and are not resolved here';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $parameters Tool call parameters
	 * @param array $tool_def Tool definition
	 * @return array Tool execution result
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$action   = $parameters['action'] ?? '';
		$agent_id = isset( $parameters['agent_id'] ) ? (int) $parameters['agent_id'] : null;

		switch ( $action ) {
			case 'clear':
				return $this->clearLogs( $agent_id );

			case 'get_metadata':
				return $this->getMetadata( $agent_id );

			default:
				return array(
					'success'   => false,
					'error'     => 'Invalid action. Use "clear" or "get_metadata"',
					'tool_name' => 'manage_logs',
				);
		}
	}

	/**
	 * Clear logs for a specific agent or all agents.
	 *
	 * @param int|null $agent_id Agent ID to clear, or null for all logs.
	 * @return array Result
	 */
	private function clearLogs( ?int $agent_id ): array {
		$ability = wp_get_ability( 'datamachine/clear-logs' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Clear logs ability not available',
				'tool_name' => 'manage_logs',
			);
		}

		$result = $ability->execute(
			null !== $agent_id && $agent_id > 0
				? array( 'agent_id' => $agent_id )
				: array()
		);

		if ( ! ( $result['success'] ?? false ) ) {
			return array(
				'success'   => false,
				'error'     => $result['error'] ?? $result['message'] ?? 'Failed to clear logs',
				'tool_name' => 'manage_logs',
			);
		}

		return array(
			'success'   => true,
			'data'      => array( 'message' => $result['message'] ?? 'Logs cleared' ),
			'tool_name' => 'manage_logs',
		);
	}

	/**
	 * Get log metadata for a specific agent or all agents.
	 *
	 * @param int|null $agent_id Agent ID, or null for all logs.
	 * @return array Result
	 */
	private function getMetadata( ?int $agent_id ): array {
		$ability = wp_get_ability( 'datamachine/get-log-metadata' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Get log metadata ability not available',
				'tool_name' => 'manage_logs',
			);
		}

		$result = $ability->execute(
			null !== $agent_id && $agent_id > 0
				? array( 'agent_id' => $agent_id )
				: array()
		);

		if ( ! ( $result['success'] ?? false ) ) {
			return array(
				'success'   => false,
				'error'     => $result['error'] ?? $result['message'] ?? 'Failed to get log metadata',
				'tool_name' => 'manage_logs',
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'manage_logs',
		);
	}
}
