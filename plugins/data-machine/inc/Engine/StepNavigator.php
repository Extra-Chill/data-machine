<?php
/**
 * Step Navigation Service
 *
 * Handles navigation logic for determining next/previous steps during flow execution.
 * Uses engine_data for optimal performance during execution.
 *
 * @package DataMachine\Engine
 * @since 0.2.1
 */

namespace DataMachine\Engine;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class StepNavigator {

	/**
	 * Load flow configuration from engine data storage.
	 */
	private function getFlowConfig( int $job_id ): array {
		if ( $job_id <= 0 ) {
			return array();
		}

		$engine_data = datamachine_get_engine_data( $job_id );
		return is_array( $engine_data['flow_config'] ?? null ) ? $engine_data['flow_config'] : array();
	}

	/**
	 * Build the execution plan for navigation, logging invalid plans once.
	 */
	private function getExecutionPlan( array $flow_config, int $job_id, string $flow_step_id ): ?ExecutionPlan {
		try {
			return ExecutionPlan::from_flow_config( $flow_config );
		} catch ( \InvalidArgumentException $e ) {
			do_action(
				'datamachine_log',
				'error',
				'Step navigation failed - invalid execution plan',
				array(
					'job_id'       => $job_id,
					'flow_step_id' => $flow_step_id,
					'error'        => $e->getMessage(),
				)
			);
		}

		return null;
	}

	/**
	 * Get next flow step ID based on execution order
	 *
	 * Uses centralized engine data for execution context.
	 *
	 * @param string $flow_step_id Current flow step ID
	 * @param array  $context Context containing job_id
	 * @return string|null Next flow step ID or null if none
	 */
	public function get_next_flow_step_id( string $flow_step_id, array $context = array() ): ?string {
		$job_id = (int) ( $context['job_id'] ?? 0 );
		if ( $job_id <= 0 ) {
			return null;
		}

		$flow_config = $this->getFlowConfig( $job_id );

		if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
			return null;
		}

		$plan = $this->getExecutionPlan( $flow_config, $job_id, $flow_step_id );
		return $plan ? $plan->next_step_id( $flow_step_id ) : null;
	}

	/**
	 * Get previous flow step ID based on execution order
	 *
	 * Uses centralized engine data for execution context.
	 *
	 * @param string $flow_step_id Current flow step ID
	 * @param array  $context Context containing job_id
	 * @return string|null Previous flow step ID or null if none
	 */
	public function get_previous_flow_step_id( string $flow_step_id, array $context = array() ): ?string {
		$job_id = (int) ( $context['job_id'] ?? 0 );
		if ( $job_id <= 0 ) {
			return null;
		}

		$flow_config = $this->getFlowConfig( $job_id );

		if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
			return null;
		}

		$plan = $this->getExecutionPlan( $flow_config, $job_id, $flow_step_id );
		return $plan ? $plan->previous_step_id( $flow_step_id ) : null;
	}
}
