//! delete — extracted from JobsCommand.php.


	/**
	 * Delete jobs by type.
	 *
	 * Removes job records from the database. Supports deleting all jobs
	 * or only failed jobs. Optionally cleans up processed items tracking
	 * for the deleted jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Which jobs to delete.
	 * ---
	 * default: failed
	 * options:
	 *   - all
	 *   - failed
	 * ---
	 *
	 * [--cleanup-processed]
	 * : Also clear processed items tracking for deleted jobs.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete failed jobs
	 *     wp datamachine jobs delete
	 *
	 *     # Delete all jobs
	 *     wp datamachine jobs delete --type=all
	 *
	 *     # Delete failed jobs and cleanup processed items
	 *     wp datamachine jobs delete --cleanup-processed
	 *
	 *     # Delete all jobs without confirmation
	 *     wp datamachine jobs delete --type=all --yes
	 *
	 * @subcommand delete
	 */
	public function delete( array $args, array $assoc_args ): void {
		$type              = $assoc_args['type'] ?? 'failed';
		$cleanup_processed = isset( $assoc_args['cleanup-processed'] );
		$skip_confirm      = isset( $assoc_args['yes'] );

		if ( ! in_array( $type, array( 'all', 'failed' ), true ) ) {
			WP_CLI::error( 'type must be "all" or "failed"' );
			return;
		}

		// Require confirmation for destructive operations.
		if ( ! $skip_confirm ) {
			$message = 'all' === $type
				? 'Delete ALL jobs? This cannot be undone.'
				: 'Delete all FAILED jobs?';

			if ( $cleanup_processed ) {
				$message .= ' Processed items tracking will also be cleared.';
			}

			WP_CLI::confirm( $message );
		}

		$result = $this->abilities->executeDeleteJobs(
			array(
				'type'              => $type,
				'cleanup_processed' => $cleanup_processed,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to delete jobs' );
			return;
		}

		WP_CLI::success( $result['message'] );

		if ( $cleanup_processed && ( $result['processed_items_cleaned'] ?? 0 ) > 0 ) {
			WP_CLI::log( sprintf( 'Processed items cleaned: %d', $result['processed_items_cleaned'] ) );
		}
	}

	/**
	 * Cleanup old jobs by status and age.
	 *
	 * Removes jobs matching a status that are older than a specified age.
	 * Useful for keeping the jobs table clean by purging stale failures,
	 * completed jobs, or other terminal statuses.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<duration>]
	 * : Delete jobs older than this duration. Accepts days (e.g., 30d),
	 *   weeks (e.g., 4w), or hours (e.g., 72h).
	 * ---
	 * default: 30d
	 * ---
	 *
	 * [--status=<status>]
	 * : Which job status to clean up. Uses prefix matching to catch
	 *   compound statuses (e.g., "failed" matches "failed - timeout").
	 * ---
	 * default: failed
	 * ---
	 *
	 * [--dry-run]
	 * : Show what would be deleted without making changes.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview cleanup of failed jobs older than 30 days
	 *     wp datamachine jobs cleanup --dry-run
	 *
	 *     # Delete failed jobs older than 30 days
	 *     wp datamachine jobs cleanup --yes
	 *
	 *     # Delete failed jobs older than 2 weeks
	 *     wp datamachine jobs cleanup --older-than=2w --yes
	 *
	 *     # Delete completed jobs older than 90 days
	 *     wp datamachine jobs cleanup --status=completed --older-than=90d --yes
	 *
	 *     # Delete agent_skipped jobs older than 1 week
	 *     wp datamachine jobs cleanup --status=agent_skipped --older-than=1w
	 *
	 * @subcommand cleanup
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		$duration_str = $assoc_args['older-than'] ?? '30d';
		$status       = $assoc_args['status'] ?? 'failed';
		$dry_run      = isset( $assoc_args['dry-run'] );
		$skip_confirm = isset( $assoc_args['yes'] );

		$days = $this->parseDurationToDays( $duration_str );
		if ( null === $days ) {
			WP_CLI::error( sprintf( 'Invalid duration format: "%s". Use format like 30d, 4w, or 72h.', $duration_str ) );
			return;
		}

		$db_jobs = new Jobs();
		$count   = $db_jobs->count_old_jobs( $status, $days );

		if ( 0 === $count ) {
			WP_CLI::success( sprintf( 'No "%s" jobs older than %s found. Nothing to clean up.', $status, $duration_str ) );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d "%s" job(s) older than %s (%d days).', $count, $status, $duration_str, $days ) );

		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'Dry run: %d job(s) would be deleted.', $count ) );
			return;
		}

		if ( ! $skip_confirm ) {
			WP_CLI::confirm( sprintf( 'Delete %d "%s" job(s) older than %s?', $count, $status, $duration_str ) );
		}

		$deleted = $db_jobs->delete_old_jobs( $status, $days );

		if ( false === $deleted ) {
			WP_CLI::error( 'Failed to delete jobs.' );
			return;
		}

		WP_CLI::success( sprintf( 'Deleted %d "%s" job(s) older than %s.', $deleted, $status, $duration_str ) );
	}

	/**
	 * Parse a human-readable duration string to days.
	 *
	 * Supports formats: 30d (days), 4w (weeks), 72h (hours).
	 *
	 * @param string $duration Duration string.
	 * @return int|null Number of days, or null if invalid.
	 */
	private function parseDurationToDays( string $duration ): ?int {
		if ( ! preg_match( '/^(\d+)(d|w|h)$/i', trim( $duration ), $matches ) ) {
			return null;
		}

		$value = (int) $matches[1];
		$unit  = strtolower( $matches[2] );

		if ( $value <= 0 ) {
			return null;
		}

		return match ( $unit ) {
			'd' => $value,
			'w' => $value * 7,
			'h' => max( 1, (int) ceil( $value / 24 ) ),
			default => null,
		};
	}

	/**
	 * Undo a completed job by reversing its recorded effects.
	 *
	 * Reads the standardized effects array from the job's engine_data and
	 * reverses each effect (restore content revision, delete meta, remove
	 * attachments, etc.). Only works on jobs whose task type supports undo.
	 *
	 * ## OPTIONS
	 *
	 * [<job_id>]
	 * : Specific job ID to undo.
	 *
	 * [--task-type=<type>]
	 * : Undo all completed jobs of this task type (e.g. internal_linking).
	 *
	 * [--dry-run]
	 * : Preview what would be undone without making changes.
	 *
	 * [--force]
	 * : Re-undo a job even if it was already undone.
	 *
	 * ## EXAMPLES
	 *
	 *     # Undo a single job
	 *     wp datamachine jobs undo 1632
	 *
	 *     # Preview batch undo of all internal linking jobs
	 *     wp datamachine jobs undo --task-type=internal_linking --dry-run
	 *
	 *     # Batch undo all internal linking jobs
	 *     wp datamachine jobs undo --task-type=internal_linking
	 *
	 * @subcommand undo
	 */
	public function undo( array $args, array $assoc_args ): void {
		$job_id    = ! empty( $args[0] ) && is_numeric( $args[0] ) ? (int) $args[0] : 0;
		$task_type = $assoc_args['task-type'] ?? '';
		$dry_run   = isset( $assoc_args['dry-run'] );
		$force     = isset( $assoc_args['force'] );

		if ( $job_id <= 0 && empty( $task_type ) ) {
			WP_CLI::error( 'Provide a job ID or --task-type to undo.' );
			return;
		}

		// Resolve jobs to undo.
		$jobs_db = new Jobs();
		$jobs    = array();

		if ( $job_id > 0 ) {
			$job = $jobs_db->get_job( $job_id );
			if ( ! $job ) {
				WP_CLI::error( "Job #{$job_id} not found." );
				return;
			}
			$jobs[] = $job;
		} else {
			$jobs = $this->findJobsByTaskType( $jobs_db, $task_type );
			if ( empty( $jobs ) ) {
				WP_CLI::warning( "No completed jobs found for task type '{$task_type}'." );
				return;
			}
			WP_CLI::log( sprintf( 'Found %d completed %s job(s).', count( $jobs ), $task_type ) );
		}

		// Resolve task handlers.
		$handlers = TaskRegistry::getHandlers();

		$total_reverted = 0;
		$total_skipped  = 0;
		$total_failed   = 0;

		foreach ( $jobs as $job ) {
			$jid         = $job['job_id'] ?? 0;
			$engine_data = $job['engine_data'] ?? array();
			$jtype       = $engine_data['task_type'] ?? '';

			// Check if already undone.
			if ( ! $force && ! empty( $engine_data['undo'] ) ) {
				WP_CLI::log( sprintf( '  Job #%d: already undone (use --force to re-undo).', $jid ) );
				++$total_skipped;
				continue;
			}

			// Check task supports undo.
			if ( ! isset( $handlers[ $jtype ] ) ) {
				WP_CLI::warning( sprintf( 'Job #%d: unknown task type "%s".', $jid, $jtype ) );
				++$total_skipped;
				continue;
			}

			$task = new $handlers[ $jtype ]();

			if ( ! $task->supportsUndo() ) {
				WP_CLI::log( sprintf( '  Job #%d: task type "%s" does not support undo.', $jid, $jtype ) );
				++$total_skipped;
				continue;
			}

			$effects = $engine_data['effects'] ?? array();
			if ( empty( $effects ) ) {
				WP_CLI::log( sprintf( '  Job #%d: no effects recorded.', $jid ) );
				++$total_skipped;
				continue;
			}

			// Dry run — just describe what would happen.
			if ( $dry_run ) {
				WP_CLI::log( sprintf( '  Job #%d (%s): would undo %d effect(s):', $jid, $jtype, count( $effects ) ) );
				foreach ( $effects as $effect ) {
					$type   = $effect['type'] ?? 'unknown';
					$target = $effect['target'] ?? array();
					WP_CLI::log( sprintf( '    - %s → %s', $type, wp_json_encode( $target ) ) );
				}
				continue;
			}

			// Execute undo.
			WP_CLI::log( sprintf( '  Job #%d (%s): undoing %d effect(s)...', $jid, $jtype, count( $effects ) ) );
			$result = $task->undo( $jid, $engine_data );

			foreach ( $result['reverted'] as $r ) {
				WP_CLI::log( sprintf( '    ✓ %s reverted', $r['type'] ) );
			}
			foreach ( $result['skipped'] as $s ) {
				WP_CLI::log( sprintf( '    - %s skipped: %s', $s['type'], $s['reason'] ?? '' ) );
			}
			foreach ( $result['failed'] as $f ) {
				WP_CLI::warning( sprintf( '    ✗ %s failed: %s', $f['type'], $f['reason'] ?? '' ) );
			}

			$total_reverted += count( $result['reverted'] );
			$total_skipped  += count( $result['skipped'] );
			$total_failed   += count( $result['failed'] );

			// Record undo metadata in engine_data.
			$engine_data['undo'] = array(
				'undone_at'        => current_time( 'mysql' ),
				'effects_reverted' => count( $result['reverted'] ),
				'effects_skipped'  => count( $result['skipped'] ),
				'effects_failed'   => count( $result['failed'] ),
			);
			$jobs_db->store_engine_data( $jid, $engine_data );
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'Dry run complete. %d job(s) would be undone.', count( $jobs ) ) );
			return;
		}

		WP_CLI::success( sprintf(
			'Undo complete: %d effect(s) reverted, %d skipped, %d failed.',
			$total_reverted,
			$total_skipped,
			$total_failed
		) );
	}

	/**
	 * Find completed jobs by task type.
	 *
	 * @param Jobs   $jobs_db  Jobs database instance.
	 * @param string $task_type Task type to filter by.
	 * @return array Array of job records.
	 */
	private function findJobsByTaskType( Jobs $jobs_db, string $task_type ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT job_id FROM {$table}
				WHERE status LIKE %s
				AND engine_data LIKE %s
				ORDER BY job_id DESC",
				'completed%',
				'%"task_type":"' . $wpdb->esc_like( $task_type ) . '"%'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( empty( $rows ) ) {
			return array();
		}

		$jobs = array();
		foreach ( $rows as $row ) {
			$job = $jobs_db->get_job( (int) $row->job_id );
			if ( $job ) {
				$jobs[] = $job;
			}
		}

		return $jobs;
	}
