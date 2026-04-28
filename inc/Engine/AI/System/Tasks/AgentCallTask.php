<?php
/**
 * Agent Call System Task.
 *
 * Dispatches a structured agent invocation through a target transport.
 * The first supported transport is webhook fire-and-forget delivery, which
 * preserves the historical agent_ping behaviour under the generic contract.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 */

namespace DataMachine\Engine\AI\System\Tasks;

use DataMachine\Abilities\Flow\QueueAbility;

defined( 'ABSPATH' ) || exit;

class AgentCallTask extends SystemTask {

	/**
	 * Get the task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'agent_call';
	}

	/**
	 * Get task metadata for admin UI and TaskRegistry.
	 *
	 * @return array
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Agent Call',
			'description'     => 'Call an agent target through a structured delivery contract. Webhook fire-and-forget delivery is supported now.',
			'setting_key'     => null,
			'default_enabled' => true,
			'trigger'         => 'On-demand via CLI, REST, or pipeline step',
			'trigger_type'    => 'manual',
			'supports_run'    => true,
		);
	}

	/**
	 * Agent calls can forward pipeline context to the delivery payload.
	 *
	 * @return bool
	 */
	public function needsPipelineContext(): bool {
		return true;
	}

	/**
	 * Agent calls read queue_mode to decide whether to pop, rotate, or peek.
	 *
	 * @return array<int, string>
	 */
	public function getFlowStepConfigPassthrough(): array {
		return array( 'queue_mode' );
	}

	/**
	 * Execute agent call task.
	 *
	 * @param int   $jobId  DM Job ID.
	 * @param array $params Canonical agent_call config plus pipeline context.
	 */
	public function executeTask( int $jobId, array $params ): void {
		$call = $this->resolveQueuedInput( $jobId, $params );
		if ( null === $call ) {
			return;
		}

		$ability = wp_get_ability( 'datamachine/agent-call' );
		if ( ! $ability ) {
			$this->failJob( $jobId, 'Ability datamachine/agent-call not registered.' );
			return;
		}

		$result = $ability->execute( $call );
		if ( is_wp_error( $result ) ) {
			$this->failJob( $jobId, $result->get_error_message() );
			return;
		}

		$status = $result['status'] ?? 'failed';
		if ( 'completed' === $status || 'pending' === $status ) {
			$this->completeJob( $jobId, array_merge(
				$result,
				array( 'completed_at' => current_time( 'mysql' ) )
			) );
			return;
		}

		$this->failJob( $jobId, $result['error'] ?? 'Agent call failed' );
	}

	/**
	 * Resolve task params and optional prompt-queue consumption into a call.
	 *
	 * @param int   $jobId  DM Job ID.
	 * @param array $params Task params.
	 * @return array|null Canonical call input, or null when the job was skipped.
	 */
	private function resolveQueuedInput( int $jobId, array $params ): ?array {
		$target   = is_array( $params['target'] ?? null ) ? $params['target'] : array();
		$input    = is_array( $params['input'] ?? null ) ? $params['input'] : array();
		$delivery = is_array( $params['delivery'] ?? null ) ? $params['delivery'] : array();

		$flow_id      = (int) ( $params['flow_id'] ?? 0 );
		$flow_step_id = $params['flow_step_id'] ?? '';
		$queue_mode   = $params['queue_mode'] ?? 'static';
		if ( ! in_array( $queue_mode, array( 'drain', 'loop', 'static' ), true ) ) {
			$queue_mode = 'static';
		}

		$from_queue = false;
		if ( 'static' !== $queue_mode && $flow_id > 0 && ! empty( $flow_step_id ) ) {
			$queued_item = QueueAbility::consumeFromQueueSlot(
				$flow_id,
				$flow_step_id,
				QueueAbility::SLOT_PROMPT_QUEUE,
				$queue_mode
			);

			if ( $queued_item && ! empty( $queued_item['prompt'] ) ) {
				$input['task'] = $queued_item['prompt'];
				$from_queue    = true;

				\datamachine_merge_engine_data(
					$jobId,
					array(
						'queued_prompt_backup' => array(
							'slot'         => QueueAbility::SLOT_PROMPT_QUEUE,
							'mode'         => $queue_mode,
							'prompt'       => $queued_item['prompt'],
							'flow_id'      => $flow_id,
							'flow_step_id' => $flow_step_id,
							'added_at'     => $queued_item['added_at'] ?? null,
						),
					)
				);

				do_action(
					'datamachine_log',
					'info',
					'Agent call task using input task from queue',
					array(
						'job_id'       => $jobId,
						'flow_id'      => $flow_id,
						'flow_step_id' => $flow_step_id,
						'queue_mode'   => $queue_mode,
					)
				);
			} elseif ( empty( $input['task'] ) ) {
				do_action(
					'datamachine_log',
					'info',
					'Agent call task skipped — queue mode requires per-tick input but queue is empty, no configured fallback',
					array(
						'job_id'     => $jobId,
						'queue_mode' => $queue_mode,
					)
				);

				$this->completeJob( $jobId, array(
					'skipped'      => true,
					'reason'       => sprintf( 'Queue mode "%s" but queue empty, no configured input task', $queue_mode ),
					'completed_at' => current_time( 'mysql' ),
				) );
				return null;
			}
		}

		$context = is_array( $input['context'] ?? null ) ? $input['context'] : array();
		$context = array_merge(
			$context,
			array(
				'data_packets'  => $params['data_packets'] ?? array(),
				'engine_data'   => $params['engine_data'] ?? array(),
				'flow_id'       => $params['flow_id'] ?? null,
				'pipeline_id'   => $params['pipeline_id'] ?? null,
				'job_id'        => $params['job_id'] ?? $jobId,
				'from_queue'    => $from_queue,
			)
		);
		$input['context'] = $context;

		return array(
			'target'   => $target,
			'input'    => $input,
			'delivery' => $delivery,
		);
	}
}
