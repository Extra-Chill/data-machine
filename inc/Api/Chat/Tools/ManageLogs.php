<?php
/**
 * Manage Logs Tool
 *
 * Dedicated tool for managing Data Machine log configuration and storage.
 * Supports clearing logs, setting log levels, and getting log metadata.
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
					'description' => 'Action to perform: "clear", "set_level", or "get_metadata"',
				),
				'agent_type' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Agent type: "pipeline", "chat", "system", or "all" (for clear action). Defaults to "pipeline"',
				),
				'level'      => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Log level for set_level action: "debug", "info", "warning", "error", "none"',
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
		return 'Manage Data Machine log configuration and storage.

ACTIONS:
- clear: Clear log file for specified agent_type (or "all" to clear all logs)
- set_level: Set log verbosity level for specified agent_type
- get_metadata: Get log file info (size, path, current level)

LOG LEVELS (for set_level action):
- debug: Most verbose, includes all messages
- info: Standard operational messages
- warning: Warnings and errors only
- error: Errors only
- none: Disable logging

AGENT TYPES:
- pipeline: Job/flow execution logs
- chat: Chat agent operation logs
- system: System infrastructure logs (database, OAuth, cleanup, services)
- all: All agent types (only valid for clear action)';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $parameters Tool call parameters
	 * @param array $tool_def Tool definition
	 * @return array Tool execution result
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$action     = $parameters['action'] ?? '';
		$agent_type = $parameters['agent_type'] ?? 'pipeline';

		switch ( $action ) {
			case 'clear':
				return $this->clearLogs( $agent_type );

			case 'set_level':
				$level = $parameters['level'] ?? '';
				return $this->setLevel( $agent_type, $level );

			case 'get_metadata':
				return $this->getMetadata( $agent_type );

			default:
				return array(
					'success'   => false,
					'error'     => 'Invalid action. Use "clear", "set_level", or "get_metadata"',
					'tool_name' => 'manage_logs',
				);
		}
	}

	/**
	 * Clear logs for specified agent type.
	 *
	 * @param string $agent_type Agent type to clear
	 * @return array Result
	 */
	private function clearLogs( string $agent_type ): array {
		$ability = wp_get_ability( 'datamachine/clear-logs' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Clear logs ability not available',
				'tool_name' => 'manage_logs',
			);
		}

		$result = $ability->execute( array( 'agent_type' => $agent_type ) );

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
	 * Set log level for specified agent type.
	 *
	 * @param string $agent_type Agent type
	 * @param string $level Log level to set
	 * @return array Result
	 */
	private function setLevel( string $agent_type, string $level ): array {
		if ( empty( $level ) ) {
			return array(
				'success'   => false,
				'error'     => 'level parameter is required for set_level action',
				'tool_name' => 'manage_logs',
			);
		}

		$ability = wp_get_ability( 'datamachine/set-log-level' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Set log level ability not available',
				'tool_name' => 'manage_logs',
			);
		}

		$result = $ability->execute(
			array(
				'agent_type' => $agent_type,
				'level'      => $level,
			)
		);

		if ( ! ( $result['success'] ?? false ) ) {
			return array(
				'success'   => false,
				'error'     => $result['error'] ?? $result['message'] ?? 'Failed to set log level',
				'tool_name' => 'manage_logs',
			);
		}

		return array(
			'success'   => true,
			'data'      => array(
				'agent_type' => $result['agent_type'] ?? $agent_type,
				'level'      => $result['level'] ?? $level,
				'message'    => $result['message'] ?? 'Log level updated',
			),
			'tool_name' => 'manage_logs',
		);
	}

	/**
	 * Get log metadata for specified agent type.
	 *
	 * @param string $agent_type Agent type (or 'all' for all types)
	 * @return array Result
	 */
	private function getMetadata( string $agent_type ): array {
		$ability = wp_get_ability( 'datamachine/get-log-metadata' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Get log metadata ability not available',
				'tool_name' => 'manage_logs',
			);
		}

		$input = array();
		if ( 'all' !== $agent_type ) {
			$input['agent_type'] = $agent_type;
		}

		$result = $ability->execute( $input );

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
