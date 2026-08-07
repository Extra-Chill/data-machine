<?php
/**
 * Handler for the datamachine_fail_job action.
 *
 * @package DataMachine\Engine\Actions\Handlers
 * @since   0.11.0
 */

namespace DataMachine\Engine\Actions\Handlers;

use DataMachine\Abilities\Flow\QueueAbility;
use DataMachine\Core\JobRetryPolicy;

/**
 * Central job failure handling with cleanup, re-queue, and logging.
 */
class FailJobHandler {

	/**
	 * Handle the fail-job action.
	 *
	 * @param int    $job_id       Job ID to fail.
	 * @param string $reason       Failure reason.
	 * @param array  $context_data Optional context data.
	 * @return bool True on success, false on failure.
	 */
	public static function handle( $job_id, $reason, $context_data = array() ) {
		$job_id = (int) $job_id;

		if ( empty( $job_id ) || $job_id <= 0 ) {
			do_action(
				'datamachine_log',
				'error',
				'datamachine_fail_job called without valid job_id',
				array(
					'job_id' => $job_id,
					'reason' => $reason,
				)
			);
			return false;
		}

		$db_jobs            = new \DataMachine\Core\Database\Jobs\Jobs();
		$db_processed_items = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();

		$specific_reason = $context_data['reason'] ?? $reason;
		$status          = \DataMachine\Core\JobStatus::failed( $specific_reason );
		$retry_result    = JobRetryPolicy::maybeRetry( $job_id, (string) $specific_reason, is_array( $context_data ) ? $context_data : array(), $db_jobs );

		if ( ! empty( $retry_result['retried'] ) ) {
			return true;
		}

		// Persist structured error context into engine_data so it is
		// available from `wp datamachine jobs show` without grepping PHP logs.
		$error_data = array(
			'error_reason'      => $specific_reason,
			'error_step_id'     => $context_data['flow_step_id'] ?? null,
			'error_message'     => $context_data['exception_message']
				?? $context_data['error_message']
				?? $context_data['ai_error']
				?? $reason,
			'error_diagnostics' => is_array( $context_data['diagnostics'] ?? null )
				? $context_data['diagnostics']
				: null,
			'error_trace'       => isset( $context_data['exception_trace'] )
				? mb_substr( $context_data['exception_trace'], 0, 2000 )
				: null,
			'retry_result'      => $retry_result,
		);
		// Strip null values so we only store keys that carry information.
		$error_data = array_filter( $error_data, fn( $v ) => null !== $v );
		\datamachine_merge_engine_data( $job_id, $error_data );

		$success = $db_jobs->complete_job( $job_id, $status->toString() );

		// Restore a drain-mode queue entry if the job failed after consuming it.
		$engine_data = \datamachine_get_engine_data( $job_id );
		if ( isset( $engine_data['queued_prompt_backup'] ) && is_array( $engine_data['queued_prompt_backup'] ) ) {
			$backup = $engine_data['queued_prompt_backup'];
			if ( ! empty( $backup['flow_id'] ) && ! empty( $backup['flow_step_id'] ) ) {
				$restored = QueueAbility::restoreConsumedEntryBackup( (int) $backup['flow_id'], $backup );

				if ( $restored ) {
					unset( $engine_data['queued_prompt_backup'] );
					\datamachine_set_engine_data( $job_id, $engine_data );
					do_action(
						'datamachine_log',
						'info',
						'Queue entry restored due to job failure',
						array(
							'job_id'       => $job_id,
							'flow_id'      => (int) $backup['flow_id'],
							'flow_step_id' => (string) $backup['flow_step_id'],
							'slot'         => (string) ( $backup['slot'] ?? QueueAbility::SLOT_PROMPT_QUEUE ),
						)
					);
				} else {
					do_action(
						'datamachine_log',
						'error',
						'Failed to restore queue entry after job failure - backup retained in engine_data',
						array(
							'job_id'       => $job_id,
							'flow_id'      => (int) $backup['flow_id'],
							'flow_step_id' => (string) $backup['flow_step_id'],
							'slot'         => (string) ( $backup['slot'] ?? QueueAbility::SLOT_PROMPT_QUEUE ),
						)
					);
				}
			}
		}

		if ( ! $success ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to mark job as failed in database',
				array(
					'job_id' => $job_id,
					'reason' => $reason,
				)
			);
			return false;
		}

		$db_processed_items->delete_processed_items( array( 'job_id' => $job_id ) );

		$cleanup_files = \DataMachine\Core\PluginSettings::get( 'cleanup_job_data_on_failure', true );
		$files_cleaned = false;
		$terminal_job  = $db_jobs->get_job( $job_id );
		$retry_pending = self::hasPendingRetry( $engine_data, is_array( $terminal_job ) ? (string) ( $terminal_job['status'] ?? '' ) : '' );

		// Skip cleanup whenever a retry is already scheduled for this job.
		// JobRetryPolicy::recordRetry writes next_retry_at only after Action
		// Scheduler accepts the retry action. A non-empty value means the next
		// attempt has ownership of the packet file until retry exhausts.
		if ( $cleanup_files && ! $retry_pending ) {
			$job = $db_jobs->get_job( $job_id );
			if ( $job && function_exists( 'datamachine_get_file_context' ) && ! empty( $job['flow_id'] ) ) {
				$cleanup       = new \DataMachine\Core\FilesRepository\FileCleanup();
				$context       = datamachine_get_file_context( $job['flow_id'] );
				$deleted_count = $cleanup->cleanup_job_data_packets( $job_id, $context );
				$files_cleaned = $deleted_count > 0;
			}
		}

		do_action(
			'datamachine_log',
			'error',
			'Job marked as failed',
			array(
				'job_id'                  => $job_id,
				'failure_reason'          => $reason,
				'triggered_by'            => 'datamachine_fail_job',
				'context_data'            => $context_data,
				'processed_items_cleaned' => true,
				'files_cleanup_enabled'   => $cleanup_files,
				'files_cleaned'           => $files_cleaned,
				'retry_pending'           => $retry_pending,
			)
		);

		return true;
	}

	/**
	 * Detect whether the engine snapshot already reflects a scheduled retry.
	 *
	 * `JobRetryPolicy::recordRetry` stamps `engine_data['retry']['next_retry_at']`
	 * only after Action Scheduler accepts the next attempt. Any non-empty value
	 * means the policy has taken ownership of the failure path.
	 *
	 * @param array  $engine_data Engine snapshot read prior to fail finalization.
	 * @param string $job_status  Current persisted job status.
	 * @return bool
	 */
	private static function hasPendingRetry( array $engine_data, string $job_status ): bool {
		$retry = is_array( $engine_data['retry'] ?? null ) ? $engine_data['retry'] : array();
		return 'pending' === $job_status && ! empty( $retry['next_retry_at'] );
	}
}
