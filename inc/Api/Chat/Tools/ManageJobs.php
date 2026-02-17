<?php
/**
 * Manage Jobs Tool
 *
 * Chat tool for job management including listing, summary, deletion, failure, retry, and recovery.
 *
 * @package DataMachine\Api\Chat\Tools
 * @since 0.24.0
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ManageJobs extends BaseTool {

	public function __construct() {
		$this->registerTool( 'chat', 'manage_jobs', array( $this, 'getToolDefinition' ) );
	}

	/**
	 * Get tool definition.
	 *
	 * @since 0.24.0
	 * @return array Tool definition array.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => $this->buildDescription(),
			'parameters'  => array(
				'action'      => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action to perform: "list", "summary", "delete", "fail", "retry", or "recover"',
				),
				'flow_id'     => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Filter jobs by flow ID (for list action)',
				),
				'pipeline_id' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Filter jobs by pipeline ID (for list action)',
				),
				'status'      => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Filter jobs by status: pending, processing, completed, failed, completed_no_items, agent_skipped (for list action)',
				),
				'limit'       => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Number of jobs to return (for list action, default 50, max 100)',
				),
				'offset'      => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Offset for pagination (for list action)',
				),
				'type'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'For delete action: "all" or "failed". Required for delete.',
				),
				'job_id'      => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Job ID (for fail and retry actions)',
				),
				'reason'      => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Reason for failure (for fail action)',
				),
			),
		);
	}

	/**
	 * Build tool description.
	 *
	 * @since 0.24.0
	 * @return string Tool description.
	 */
	private function buildDescription(): string {
		return 'Manage Data Machine jobs.

ACTIONS:
- list: List jobs with optional filtering by flow_id, pipeline_id, or status. Supports pagination via limit/offset.
- summary: Get job counts grouped by status.
- delete: Delete jobs by type. Requires type parameter: "all" or "failed".
- fail: Manually fail a job (requires job_id, optional reason).
- retry: Retry a failed job (requires job_id).
- recover: Recover stuck processing jobs that have timed out.';
	}

	/**
	 * Execute the tool.
	 *
	 * @since 0.24.0
	 * @param array $parameters Tool call parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array Tool execution result.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$action = $parameters['action'] ?? '';

		$ability_map = array(
			'list'    => 'datamachine/get-jobs',
			'summary' => 'datamachine/get-jobs-summary',
			'delete'  => 'datamachine/delete-jobs',
			'fail'    => 'datamachine/fail-job',
			'retry'   => 'datamachine/retry-job',
			'recover' => 'datamachine/recover-stuck-jobs',
		);

		if ( ! isset( $ability_map[ $action ] ) ) {
			return array(
				'success'   => false,
				'error'     => 'Invalid action. Use "list", "summary", "delete", "fail", "retry", or "recover"',
				'tool_name' => 'manage_jobs',
			);
		}

		$ability = wp_get_ability( $ability_map[ $action ] );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => sprintf( '%s ability not available', $ability_map[ $action ] ),
				'tool_name' => 'manage_jobs',
			);
		}

		$input = $this->buildInput( $action, $parameters );

		if ( isset( $input['error'] ) ) {
			return $this->buildErrorResponse( $input['error'], 'manage_jobs' );
		}

		$result = $ability->execute( $input );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			$error = $this->getAbilityError( $result, sprintf( 'Failed to %s jobs', $action ) );
			return $this->buildErrorResponse( $error, 'manage_jobs' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'manage_jobs',
		);
	}

	/**
	 * Build input array for the ability based on action.
	 *
	 * @since 0.24.0
	 * @param string $action     The action being performed.
	 * @param array  $parameters Raw tool parameters.
	 * @return array Input for the ability.
	 */
	private function buildInput( string $action, array $parameters ): array {
		switch ( $action ) {
			case 'list':
				$input = array();
				if ( isset( $parameters['flow_id'] ) ) {
					$input['flow_id'] = $parameters['flow_id'];
				}
				if ( isset( $parameters['pipeline_id'] ) ) {
					$input['pipeline_id'] = $parameters['pipeline_id'];
				}
				if ( isset( $parameters['status'] ) ) {
					$input['status'] = $parameters['status'];
				}
				if ( isset( $parameters['limit'] ) ) {
					$input['per_page'] = $parameters['limit'];
				}
				if ( isset( $parameters['offset'] ) ) {
					$input['offset'] = $parameters['offset'];
				}
				return $input;

			case 'summary':
				return array();

			case 'delete':
				$type = $parameters['type'] ?? null;
				if ( ! in_array( $type, array( 'all', 'failed' ), true ) ) {
					return array( 'error' => 'delete action requires type parameter: "all" or "failed"' );
				}
				return array( 'type' => $type );

			case 'fail':
				$input = array(
					'job_id' => $parameters['job_id'] ?? null,
				);
				if ( isset( $parameters['reason'] ) ) {
					$input['reason'] = $parameters['reason'];
				}
				return $input;

			case 'retry':
				return array(
					'job_id' => $parameters['job_id'] ?? null,
				);

			case 'recover':
				return array();

			default:
				return array();
		}
	}
}
