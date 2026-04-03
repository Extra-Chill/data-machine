<?php
/**
 * Run Flow Tool
 *
 * Tool for executing existing flows immediately or scheduling delayed execution.
 * Delegates to datamachine/run-flow for immediate execution and
 * datamachine/schedule-flow for delayed/scheduled execution.
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
		$flow_id   = $parameters['flow_id'] ?? null;
		$count     = max( 1, min( 10, (int) ( $parameters['count'] ?? 1 ) ) );
		$timestamp = $parameters['timestamp'] ?? null;

		if ( ! $flow_id ) {
			return $this->buildErrorResponse( 'flow_id is required', 'run_flow' );
		}

		// Delayed execution → delegate to schedule-flow.
		if ( ! empty( $timestamp ) && is_numeric( $timestamp ) && (int) $timestamp > time() ) {
			if ( $count > 1 ) {
				return $this->buildErrorResponse(
					'Cannot schedule multiple runs with a timestamp. Use count only for immediate execution.',
					'run_flow'
				);
			}

			$ability = wp_get_ability( 'datamachine/schedule-flow' );
			if ( ! $ability ) {
				return $this->buildErrorResponse( 'Schedule flow ability not available', 'run_flow' );
			}

			$result = $ability->execute(
				array(
					'flow_id'               => (int) $flow_id,
					'interval_or_timestamp' => (int) $timestamp,
				)
			);

			if ( ! $this->isAbilitySuccess( $result ) ) {
				return $this->buildErrorResponse( $this->getAbilityError( $result, 'Failed to schedule flow' ), 'run_flow' );
			}

			return array(
				'success'   => true,
				'data'      => array(
					'flow_id'        => (int) $flow_id,
					'execution_type' => 'delayed',
					'scheduled_time' => $result['scheduled_time'] ?? null,
					'message'        => 'Flow scheduled for execution at the specified time.',
				),
				'tool_name' => 'run_flow',
			);
		}

		// Immediate execution → delegate to run-flow ability.
		$ability = wp_get_ability( 'datamachine/run-flow' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Run flow ability not available', 'run_flow' );
		}

		$job_ids = array();
		$errors  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$result = $ability->execute( array( 'flow_id' => (int) $flow_id ) );

			if ( $this->isAbilitySuccess( $result ) ) {
				$job_ids[] = $result['job_id'] ?? null;
			} else {
				$errors[] = $this->getAbilityError( $result, 'Failed to run flow' );
				if ( empty( $job_ids ) ) {
					return $this->buildErrorResponse( $errors[0], 'run_flow' );
				}
				break;
			}
		}

		$response_data = array(
			'flow_id'        => (int) $flow_id,
			'execution_type' => 'immediate',
			'message'        => 'Flow execution started',
		);

		if ( isset( $result['flow_name'] ) ) {
			$response_data['flow_name'] = $result['flow_name'];
		}

		if ( 1 === $count ) {
			$response_data['job_id'] = $job_ids[0] ?? null;
		} else {
			$response_data['job_ids'] = $job_ids;
			$response_data['count']   = count( $job_ids );
			$response_data['message'] = sprintf( 'Queued %d jobs for flow. Each job processes independently.', count( $job_ids ) );
		}

		return array(
			'success'   => true,
			'data'      => $response_data,
			'tool_name' => 'run_flow',
		);
	}
}
