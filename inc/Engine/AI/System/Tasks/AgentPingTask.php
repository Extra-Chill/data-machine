<?php
/**
 * Agent Ping System Task
 *
 * Sends pipeline context to external webhook endpoints (Discord, Slack, custom).
 * Delegates HTTP logic to SendPingAbility. Supports queue popping when running
 * as a pipeline step via SystemTaskStep.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.60.0
 * @since 0.72.0 Migrated to getWorkflow() + executeTask() contract.
 */

namespace DataMachine\Engine\AI\System\Tasks;

use DataMachine\Abilities\Flow\QueueAbility;

defined( 'ABSPATH' ) || exit;

class AgentPingTask extends SystemTask {

	/**
	 * Get the task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'agent_ping';
	}

	/**
	 * Get task metadata for admin UI and TaskRegistry.
	 *
	 * @return array
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Agent Ping',
			'description'     => 'Send context to external webhook endpoints (Discord, Slack, custom)',
			'setting_key'     => null,
			'default_enabled' => true,
			'trigger'         => 'On-demand via CLI, REST, or pipeline step',
			'trigger_type'    => 'manual',
			'supports_run'    => true,
		);
	}

	/**
	 * Agent ping forwards pipeline context to the webhook payload, so it
	 * needs the full engine bundle (flow_id, pipeline_id, flow_step_id,
	 * data_packets, engine_data, job_id) injected into engine_data by
	 * SystemTaskStep. Replaces the per-task `if` block in
	 * SystemTaskStep::execute_pipeline_step() (#1297).
	 *
	 * @return bool
	 * @since 0.84.0
	 */
	public function needsPipelineContext(): bool {
		return true;
	}

	/**
	 * Agent ping reads `queue_mode` (post-#1291) to decide whether to pop
	 * from the prompt queue (drain), rotate it (loop), or peek without
	 * mutating (static). Declared here so SystemTaskStep doesn't need to
	 * know that agent_ping cares about queue_mode (#1297).
	 *
	 * @return array<int, string>
	 * @since 0.84.0
	 */
	public function getFlowStepConfigPassthrough(): array {
		return array( 'queue_mode' );
	}

	/**
	 * Execute agent ping task.
	 *
	 * @param int   $jobId  DM Job ID.
	 * @param array $params Engine data with webhook_url, prompt, auth settings, etc.
	 */
	public function executeTask( int $jobId, array $params ): void {
		$webhook_url = $params['webhook_url'] ?? '';
		$prompt      = $params['prompt'] ?? '';

		if ( empty( $webhook_url ) ) {
			$this->failJob( $jobId, 'Missing webhook_url.' );
			return;
		}

		// Consume from flow queue when running as a pipeline step. The
		// `queue_mode` enum (#1291) decides the access pattern:
		//   - drain  → pop the head, discard. DB write.
		//   - loop   → pop the head, append to tail. DB write.
		//   - static → peek the head; do not mutate. No DB write.
		// Static mode + non-empty `prompt` argument falls through to use
		// the configured prompt directly. This preserves the pre-#1291
		// behaviour where running an agent_ping task without a queue
		// just sent the configured prompt every tick.
		//
		// #1299: shares `QueueAbility::consumeFromQueueSlot()` with
		// `QueueableTrait` (AIStep / FetchStep). Single source of truth
		// for the drain / loop / static semantics.
		$from_queue   = false;
		$flow_id      = (int) ( $params['flow_id'] ?? 0 );
		$flow_step_id = $params['flow_step_id'] ?? '';
		$queue_mode   = $params['queue_mode'] ?? 'static';
		if ( ! in_array( $queue_mode, array( 'drain', 'loop', 'static' ), true ) ) {
			$queue_mode = 'static';
		}

		if ( 'static' !== $queue_mode && $flow_id > 0 && ! empty( $flow_step_id ) ) {
			$queued_item = QueueAbility::consumeFromQueueSlot(
				$flow_id,
				$flow_step_id,
				QueueAbility::SLOT_PROMPT_QUEUE,
				$queue_mode
			);

			if ( $queued_item && ! empty( $queued_item['prompt'] ) ) {
				$prompt     = $queued_item['prompt'];
				$from_queue = true;

				// Back up the popped prompt to engine_data for retry on
				// failure. Mirrors `QueueableTrait::consumeOnceFromPromptQueue()`'s
				// `queued_prompt_backup` write so a SendPingAbility
				// failure after the pop doesn't lose the prompt. Static
				// mode never mutates so no rollback is needed.
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

				// `consumeFromQueueSlot` already logged the slot-level
				// pop/rotate event with the unified shape. This second
				// log line is the consumer-side announcement so an
				// operator searching by "agent_ping" still finds it.
				do_action(
					'datamachine_log',
					'info',
					'Agent ping task using prompt from queue',
					array(
						'job_id'       => $jobId,
						'flow_id'      => $flow_id,
						'flow_step_id' => $flow_step_id,
						'queue_mode'   => $queue_mode,
					)
				);
			} elseif ( empty( $prompt ) ) {
				do_action(
					'datamachine_log',
					'info',
					'Agent ping task skipped — queue mode requires per-tick prompt but queue is empty, no configured fallback',
					array(
						'job_id'     => $jobId,
						'queue_mode' => $queue_mode,
					)
				);

				$this->completeJob( $jobId, array(
					'skipped'      => true,
					'reason'       => sprintf( 'Queue mode "%s" but queue empty, no configured prompt', $queue_mode ),
					'completed_at' => current_time( 'mysql' ),
				) );
				return;
			}
		}

		$auth_header_name          = $params['auth_header_name'] ?? '';
		$auth_token                = $params['auth_token'] ?? '';
		$reply_to                  = $params['reply_to'] ?? '';
		$data_packets              = $params['data_packets'] ?? array();
		$engine_data               = $params['engine_data'] ?? array();
		$engine_data['from_queue'] = $from_queue;

		// Delegate to SendPingAbility.
		$ability = wp_get_ability( 'datamachine/send-ping' );

		if ( ! $ability ) {
			$this->failJob( $jobId, 'Ability datamachine/send-ping not registered.' );
			return;
		}

		$result = $ability->execute( array(
			'webhook_url'      => $webhook_url,
			'prompt'           => $prompt,
			'from_queue'       => $from_queue,
			'data_packets'     => $data_packets,
			'engine_data'      => $engine_data,
			'flow_id'          => $params['flow_id'] ?? null,
			'pipeline_id'      => $params['pipeline_id'] ?? null,
			'job_id'           => $params['job_id'] ?? $jobId,
			'auth_header_name' => $auth_header_name,
			'auth_token'       => $auth_token,
			'reply_to'         => $reply_to,
		) );

		if ( is_wp_error( $result ) ) {
			$this->failJob( $jobId, $result->get_error_message() );
			return;
		}

		$success = $result['success'] ?? false;

		if ( $success ) {
			$this->completeJob( $jobId, array(
				'message'      => $result['message'] ?? 'Webhook notification sent successfully',
				'results'      => $result['results'] ?? array(),
				'from_queue'   => $from_queue,
				'completed_at' => current_time( 'mysql' ),
			) );
		} else {
			$this->failJob( $jobId, $result['error'] ?? 'Webhook notification failed' );
		}
	}
}
