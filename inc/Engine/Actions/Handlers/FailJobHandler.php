<?php
/**
 * Handler for the datamachine_fail_job action.
 *
 * @package DataMachine\Engine\Actions\Handlers
 * @since   0.11.0
 */

namespace DataMachine\Engine\Actions\Handlers;

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

		// Persist structured error context into engine_data so it is
		// available from `wp datamachine jobs show` without grepping PHP logs.
		$error_data = array(
			'error_reason'  => $specific_reason,
			'error_step_id' => $context_data['flow_step_id'] ?? null,
			'error_message' => $context_data['exception_message']
				?? $context_data['error_message']
				?? $context_data['ai_error']
				?? $reason,
			'error_trace'   => isset( $context_data['exception_trace'] )
				? mb_substr( $context_data['exception_trace'], 0, 2000 )
				: null,
		);
		// Strip null values so we only store keys that carry information.
		$error_data = array_filter( $error_data, fn( $v ) => null !== $v );
		\datamachine_merge_engine_data( $job_id, $error_data );

		$success = $db_jobs->complete_job( $job_id, $status->toString() );

		// Re-queue logic: If a queued prompt was popped but the job failed, add it back.
		$engine_data = \datamachine_get_engine_data( $job_id );
		if ( isset( $engine_data['queued_prompt_backup'] ) && is_array( $engine_data['queued_prompt_backup'] ) ) {
			$backup = $engine_data['queued_prompt_backup'];
			if ( ! empty( $backup['prompt'] ) && ! empty( $backup['flow_id'] ) && ! empty( $backup['flow_step_id'] ) ) {
				$queue_ability = new \DataMachine\Abilities\Flow\QueueAbility();
				$result        = $queue_ability->executeQueueAdd(
					array(
						'flow_id'      => (int) $backup['flow_id'],
						'flow_step_id' => (string) $backup['flow_step_id'],
						'prompt'       => $backup['prompt'],
					)
				);

				if ( ! empty( $result['success'] ) ) {
					unset( $engine_data['queued_prompt_backup'] );
					\datamachine_set_engine_data( $job_id, $engine_data );
					do_action(
						'datamachine_log',
						'info',
						'Prompt re-queued to back due to job failure',
						array(
							'job_id'       => $job_id,
							'flow_id'      => (int) $backup['flow_id'],
							'flow_step_id' => (string) $backup['flow_step_id'],
							'prompt'       => $backup['prompt'],
						)
					);
				} else {
					do_action(
						'datamachine_log',
						'error',
						'Failed to re-queue prompt after job failure - backup retained in engine_data',
						array(
							'job_id'       => $job_id,
							'flow_id'      => (int) $backup['flow_id'],
							'flow_step_id' => (string) $backup['flow_step_id'],
							'prompt'       => $backup['prompt'],
							'queue_error'  => $result['error'] ?? 'unknown',
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
		self::releaseInFlightSourceClaims( $db_processed_items, $job_id, $engine_data );

		$cleanup_files = \DataMachine\Core\PluginSettings::get( 'cleanup_job_data_on_failure', true );
		$files_cleaned = false;

		if ( $cleanup_files ) {
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
			)
		);

		return true;
	}

	/**
	 * Release in-flight source-item claims for failed jobs.
	 *
	 * Parent fetch jobs own claims by job_id until their children are scheduled;
	 * child jobs carry item_identifier/source_type in engine_data. Release both
	 * shapes so failed downstream work can retry on the next fetch tick.
	 *
	 * @param \DataMachine\Core\Database\ProcessedItems\ProcessedItems $db_processed_items Processed items repository.
	 * @param int                                                        $job_id             Failed job ID.
	 * @param array                                                      $engine_data        Failed job engine data.
	 * @return void
	 */
	private static function releaseInFlightSourceClaims( \DataMachine\Core\Database\ProcessedItems\ProcessedItems $db_processed_items, int $job_id, array $engine_data ): void {
		$db_processed_items->release_claims_for_job( $job_id );

		$item_identifier = $engine_data['item_identifier'] ?? null;
		$source_type     = $engine_data['source_type'] ?? null;
		if ( empty( $item_identifier ) || empty( $source_type ) ) {
			return;
		}

		$fetch_flow_step_id = self::resolveFetchFlowStepId( $engine_data );
		if ( empty( $fetch_flow_step_id ) ) {
			return;
		}

		$db_processed_items->release_claim( $fetch_flow_step_id, (string) $source_type, (string) $item_identifier );
	}

	/**
	 * Resolve the fetch/event_import step ID from a job engine snapshot.
	 *
	 * @param array $engine_data Job engine data.
	 * @return string|null Fetch flow step ID, or null when unavailable.
	 */
	private static function resolveFetchFlowStepId( array $engine_data ): ?string {
		$flow_config = $engine_data['flow_config'] ?? array();
		if ( ! is_array( $flow_config ) ) {
			return null;
		}

		foreach ( $flow_config as $step_id => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}

			$step_type = $config['step_type'] ?? '';
			if ( in_array( $step_type, array( 'fetch', 'event_import' ), true ) ) {
				return (string) $step_id;
			}
		}

		return null;
	}
}
