//! helpers — extracted from JobsCommand.php.


	public function __construct() {
		$this->abilities = new JobAbilities();
	}

	/**
	 * Recover stuck jobs that have job_status in engine_data but status is 'processing'.
	 *
	 * Jobs can become stuck when the engine stores a status override (e.g., from skip_item)
	 * in engine_data but the main status column doesn't get updated. This command finds
	 * those jobs and completes them with their intended final status.
	 *
	 * Also recovers jobs that have been processing for longer than the timeout threshold
	 * without a status override, marking them as failed and potentially requeuing prompts.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be updated without making changes.
	 *
	 * [--flow=<flow_id>]
	 * : Only recover jobs for a specific flow ID.
	 *
	 * [--timeout=<hours>]
	 * : Hours before a processing job without status override is considered timed out.
	 * ---
	 * default: 2
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview stuck jobs recovery
	 *     wp datamachine jobs recover-stuck --dry-run
	 *
	 *     # Recover all stuck jobs
	 *     wp datamachine jobs recover-stuck
	 *
	 *     # Recover stuck jobs for a specific flow
	 *     wp datamachine jobs recover-stuck --flow=98
	 *
	 *     # Recover stuck jobs with custom timeout
	 *     wp datamachine jobs recover-stuck --timeout=4
	 *
	 * @subcommand recover-stuck
	 */
	public function recover_stuck( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$flow_id = isset( $assoc_args['flow'] ) ? (int) $assoc_args['flow'] : null;
		$timeout = isset( $assoc_args['timeout'] ) ? max( 1, (int) $assoc_args['timeout'] ) : 2;

		$result = $this->abilities->executeRecoverStuckJobs(
			array(
				'dry_run'       => $dry_run,
				'flow_id'       => $flow_id,
				'timeout_hours' => $timeout,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$jobs = $result['jobs'] ?? array();

		if ( empty( $jobs ) ) {
			WP_CLI::success( 'No stuck jobs found.' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d stuck jobs with job_status in engine_data.', count( $jobs ) ) );

		if ( $dry_run ) {
			WP_CLI::log( 'Dry run - no changes will be made.' );
			WP_CLI::log( '' );
		}

		foreach ( $jobs as $job ) {
			if ( 'skipped' === $job['status'] ) {
				WP_CLI::warning( sprintf( 'Job %d: %s', $job['job_id'], $job['reason'] ?? 'Unknown reason' ) );
			} elseif ( 'would_recover' === $job['status'] ) {
				$display_status = strlen( $job['target_status'] ) > 60 ? substr( $job['target_status'], 0, 60 ) . '...' : $job['target_status'];
				WP_CLI::log(
					sprintf(
						'Would update job %d (flow %d) to: %s',
						$job['job_id'],
						$job['flow_id'],
						$display_status
					)
				);
			} elseif ( 'recovered' === $job['status'] ) {
				$display_status = strlen( $job['target_status'] ) > 60 ? substr( $job['target_status'], 0, 60 ) . '...' : $job['target_status'];
				WP_CLI::log( sprintf( 'Updated job %d to: %s', $job['job_id'], $display_status ) );
			} elseif ( 'would_timeout' === $job['status'] ) {
				WP_CLI::log( sprintf( 'Would timeout job %d (flow %d)', $job['job_id'], $job['flow_id'] ) );
			} elseif ( 'timed_out' === $job['status'] ) {
				WP_CLI::log( sprintf( 'Timed out job %d (flow %d)', $job['job_id'], $job['flow_id'] ) );
			}
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Output job details in table format.
	 *
	 * @param array $job Job data.
	 */
	private function outputJobTable( array $job ): void {
		$parsed_status = $this->parseCompoundStatus( $job['status'] ?? '' );
		$source        = $job['source'] ?? 'pipeline';
		$is_system     = ( 'system' === $source );

		WP_CLI::log( sprintf( 'Job ID: %d', $job['job_id'] ?? 0 ) );

		if ( $is_system ) {
			WP_CLI::log( sprintf( 'Source: %s', $source ) );
			WP_CLI::log( sprintf( 'Label: %s', $job['label'] ?? $job['display_label'] ?? 'System Task' ) );
		} else {
			WP_CLI::log( sprintf( 'Flow: %s (ID: %s)', $job['flow_name'] ?? 'N/A', $job['flow_id'] ?? 'N/A' ) );
			WP_CLI::log( sprintf( 'Pipeline ID: %s', $job['pipeline_id'] ?? 'N/A' ) );
		}

		WP_CLI::log( sprintf( 'Status: %s', $parsed_status['type'] ) );

		if ( $parsed_status['reason'] ) {
			WP_CLI::log( sprintf( 'Reason: %s', $parsed_status['reason'] ) );
		}

		// Display structured error details for failed jobs (persisted by #536).
		if ( 'failed' === $parsed_status['type'] ) {
			$engine_data   = $job['engine_data'] ?? array();
			$error_message = $engine_data['error_message'] ?? null;
			$error_step_id = $engine_data['error_step_id'] ?? null;
			$error_trace   = $engine_data['error_trace'] ?? null;

			if ( $error_message ) {
				WP_CLI::log( '' );
				WP_CLI::log( WP_CLI::colorize( '%RError:%n ' . $error_message ) );

				if ( $error_step_id ) {
					WP_CLI::log( sprintf( '  Step: %s', $error_step_id ) );
				}

				if ( $error_trace ) {
					WP_CLI::log( '' );
					WP_CLI::log( '  Stack Trace (truncated):' );
					$trace_lines = explode( "\n", $error_trace );
					foreach ( array_slice( $trace_lines, 0, 10 ) as $line ) {
						WP_CLI::log( '    ' . $line );
					}
					if ( count( $trace_lines ) > 10 ) {
						WP_CLI::log( sprintf( '    ... (%d more lines, use --format=json for full trace)', count( $trace_lines ) - 10 ) );
					}
				}
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Created: %s', $job['created_at_display'] ?? $job['created_at'] ?? 'N/A' ) );
		WP_CLI::log( sprintf( 'Completed: %s', $job['completed_at_display'] ?? $job['completed_at'] ?? '-' ) );

		// Show Action Scheduler status for processing/pending jobs (#169).
		if ( in_array( $parsed_status['type'], array( 'processing', 'pending' ), true ) ) {
			$this->outputActionSchedulerStatus( (int) ( $job['job_id'] ?? 0 ) );
		}

		$engine_data = $job['engine_data'] ?? array();

		// Strip error keys already displayed in the error section above.
		unset( $engine_data['error_reason'], $engine_data['error_message'], $engine_data['error_step_id'], $engine_data['error_trace'] );

		if ( ! empty( $engine_data ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Engine Data:' );

			$summary    = $this->extractEngineDataSummary( $engine_data );
			$has_nested = false;

			foreach ( $summary as $key => $value ) {
				WP_CLI::log( sprintf( '  %s: %s', $key, $value ) );
				if ( str_starts_with( $value, 'array (' ) ) {
					$has_nested = true;
				}
			}

			if ( $has_nested ) {
				WP_CLI::log( '' );
				WP_CLI::log( '  Use --format=json for full engine data.' );
			}
		}
	}

	/**
	 * Output Action Scheduler status for a job.
	 *
	 * Queries the Action Scheduler tables to find the latest action
	 * and its logs for the given job ID. Helps diagnose stuck jobs
	 * where the AS action may have failed or timed out.
	 *
	 * @param int $job_id Job ID to look up.
	 */
	private function outputActionSchedulerStatus( int $job_id ): void {
		if ( $job_id <= 0 ) {
			return;
		}

		global $wpdb;
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$action = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT action_id, status, scheduled_date_gmt, last_attempt_gmt
				FROM %i
				WHERE hook = 'datamachine_execute_step'
				AND args LIKE %s
				ORDER BY action_id DESC
				LIMIT 1",
				$actions_table,
				'%"job_id":' . $job_id . '%'
			)
		);

		if ( ! $action ) {
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Action Scheduler:' );
		WP_CLI::log( sprintf( '  Action ID: %d', $action->action_id ) );
		WP_CLI::log( sprintf( '  AS Status: %s', $action->status ) );
		WP_CLI::log( sprintf( '  Scheduled: %s', $action->scheduled_date_gmt ) );
		WP_CLI::log( sprintf( '  Last Attempt: %s', $action->last_attempt_gmt ) );

		// Get the latest log message (usually contains failure reason).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$log = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT message, log_date_gmt
				FROM %i
				WHERE action_id = %d
				ORDER BY log_id DESC
				LIMIT 1',
				$logs_table,
				$action->action_id
			)
		);

		if ( $log && ! empty( $log->message ) ) {
			WP_CLI::log( sprintf( '  Last Log: %s (%s)', $log->message, $log->log_date_gmt ) );
		}
	}

	/**
	 * Parse compound status into type and reason.
	 *
	 * Handles formats like "agent_skipped - not a music event".
	 *
	 * @param string $status Raw status string.
	 * @return array With 'type' and 'reason' keys.
	 */
	private function parseCompoundStatus( string $status ): array {
		if ( strpos( $status, ' - ' ) !== false ) {
			$parts = explode( ' - ', $status, 2 );
			return array(
				'type'   => trim( $parts[0] ),
				'reason' => trim( $parts[1] ),
			);
		}

		return array(
			'type'   => $status,
			'reason' => '',
		);
	}

	/**
	 * Manually fail a processing job.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : The job ID to fail.
	 *
	 * [--reason=<reason>]
	 * : Reason for failure.
	 * ---
	 * default: manual
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Fail a stuck job
	 *     wp datamachine jobs fail 844
	 *
	 *     # Fail with a reason
	 *     wp datamachine jobs fail 844 --reason="timeout"
	 *
	 * @subcommand fail
	 */
	public function fail( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || ! is_numeric( $args[0] ) || (int) $args[0] <= 0 ) {
			WP_CLI::error( 'Job ID is required and must be a positive integer.' );
			return;
		}

		$result = $this->abilities->executeFailJob(
			array(
				'job_id' => (int) $args[0],
				'reason' => $assoc_args['reason'] ?? 'manual',
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Retry a failed or stuck job.
	 *
	 * Marks the job as failed and optionally requeues its prompt
	 * if a queued_prompt_backup exists in engine_data.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : The job ID to retry.
	 *
	 * [--force]
	 * : Allow retrying any status, not just failed/processing.
	 *
	 * ## EXAMPLES
	 *
	 *     # Retry a failed job
	 *     wp datamachine jobs retry 844
	 *
	 *     # Force retry a completed job
	 *     wp datamachine jobs retry 844 --force
	 *
	 * @subcommand retry
	 */
	public function retry( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || ! is_numeric( $args[0] ) || (int) $args[0] <= 0 ) {
			WP_CLI::error( 'Job ID is required and must be a positive integer.' );
			return;
		}

		$result = $this->abilities->executeRetryJob(
			array(
				'job_id' => (int) $args[0],
				'force'  => isset( $assoc_args['force'] ),
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		WP_CLI::success( $result['message'] );

		if ( ! empty( $result['prompt_requeued'] ) ) {
			WP_CLI::log( 'Prompt was requeued to the flow.' );
		}
	}
