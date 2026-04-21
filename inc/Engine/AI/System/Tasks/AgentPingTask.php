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

		// Pop from flow queue when running as a pipeline step.
		$from_queue    = false;
		$flow_id       = (int) ( $params['flow_id'] ?? 0 );
		$flow_step_id  = $params['flow_step_id'] ?? '';
		$queue_enabled = ! empty( $params['queue_enabled'] );

		if ( $queue_enabled && $flow_id > 0 && ! empty( $flow_step_id ) ) {
			$queued_item = QueueAbility::popFromQueue( $flow_id, $flow_step_id );

			if ( $queued_item && ! empty( $queued_item['prompt'] ) ) {
				$prompt     = $queued_item['prompt'];
				$from_queue = true;

				do_action(
					'datamachine_log',
					'info',
					'Agent ping task using prompt from queue',
					array(
						'job_id'       => $jobId,
						'flow_id'      => $flow_id,
						'flow_step_id' => $flow_step_id,
					)
				);
			} elseif ( empty( $prompt ) ) {
				do_action(
					'datamachine_log',
					'info',
					'Agent ping task skipped — queue enabled but empty, no configured prompt',
					array( 'job_id' => $jobId )
				);

				$this->completeJob( $jobId, array(
					'skipped'      => true,
					'reason'       => 'Queue enabled but empty, no configured prompt',
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
