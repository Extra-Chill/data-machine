<?php
/**
 * Read Logs Tool
 *
 * Dedicated tool for reading Data Machine logs with filtering capabilities.
 * Supports filtering by job_id, pipeline_id, and flow_id for troubleshooting.
 *
 * @package DataMachine\Api\Chat\Tools
 * @since 0.8.2
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ReadLogs extends BaseTool {

	public function __construct() {
		$this->registerTool( 'read_logs', array( $this, 'getToolDefinition' ), array( 'chat' ) );
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
				'agent_id'    => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Agent ID to read logs for. Omit for all agents.',
				),
				'context'     => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Deprecated label only. Use agent_id instead.',
				),
				'mode'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Content mode: "recent" (default) or "full"',
				),
				'limit'       => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Max entries for recent mode (default: 200, max: 10000)',
				),
				'job_id'      => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Filter logs by job ID',
				),
				'pipeline_id' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Filter logs by pipeline ID',
				),
				'flow_id'     => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Filter logs by flow ID',
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
		return 'Read Data Machine logs for troubleshooting jobs, flows, pipelines, and system operations.

SCOPE:
- Filter by explicit agent_id when you want a single agent
- Omit agent_id to read across all agents

FILTERS (all optional, combined with AND logic):
- job_id: Filter to specific job execution
- pipeline_id: Filter to specific pipeline
- flow_id: Filter to specific flow

MODES:
- recent (default): Most recent entries first, limited by limit param
- full: All matching entries

TIPS:
- Start with job_id filter when troubleshooting a specific failed job
- Use flow_id to see all executions of a particular flow
- Check chat logs to review your own recent operations
- Check system logs for database issues, authentication errors, or service failures';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $parameters Tool call parameters
	 * @param array $tool_def Tool definition
	 * @return array Tool execution result
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$ability = wp_get_ability( 'datamachine/read-logs' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Read logs ability not available',
				'tool_name' => 'read_logs',
			);
		}

		$input = array(
			'mode'  => $parameters['mode'] ?? 'recent',
			'limit' => $parameters['limit'] ?? 200,
		);

		if ( isset( $parameters['agent_id'] ) && (int) $parameters['agent_id'] > 0 ) {
			$input['agent_id'] = (int) $parameters['agent_id'];
		}

		if ( ! empty( $parameters['job_id'] ) ) {
			$input['job_id'] = (int) $parameters['job_id'];
		}
		if ( ! empty( $parameters['pipeline_id'] ) ) {
			$input['pipeline_id'] = (int) $parameters['pipeline_id'];
		}
		if ( ! empty( $parameters['flow_id'] ) ) {
			$input['flow_id'] = (int) $parameters['flow_id'];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => 'read_logs',
			);
		}

		if ( ! ( $result['success'] ?? false ) ) {
			return array(
				'success'   => false,
				'error'     => $result['error'] ?? $result['message'] ?? 'Failed to read logs',
				'tool_name' => 'read_logs',
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'read_logs',
		);
	}
}
