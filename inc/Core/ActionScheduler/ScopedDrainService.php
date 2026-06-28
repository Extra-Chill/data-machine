<?php
/**
 * Shared scoped Action Scheduler drain service.
 *
 * @package DataMachine\Core\ActionScheduler
 */

namespace DataMachine\Core\ActionScheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Drain scoped Data Machine Action Scheduler work with shared budgets and stats.
 */
class ScopedDrainService {

	private const GROUP = 'data-machine';

	public const HOOK_BATCH_CHUNK = 'datamachine_pipeline_batch_chunk';

	public const HOOK_EXECUTE_STEP = 'datamachine_execute_step';

	/**
	 * Drain due Data Machine actions and return a compact summary.
	 *
	 * @param array{limit?:int,batch_size?:int,time_limit?:int,time_limit_ms?:int,stop_before_timeout?:int,stop_before_timeout_ms?:int,hooks?:string[],job_ids?:string|int[],lane?:string,warning_callback?:callable,execution_context?:string,terminal_status_callback?:callable} $options Drain options.
	 * @return array<string,int|string> Drain stats.
	 */
	public function drain( array $options = array() ): array {
		$limit                  = max( 0, (int) ( $options['limit'] ?? 0 ) );
		$batch_size             = max( 1, (int) ( $options['batch_size'] ?? 25 ) );
		$time_limit_ms          = isset( $options['time_limit_ms'] ) ? max( 0, (int) $options['time_limit_ms'] ) : max( 0, (int) ( $options['time_limit'] ?? 0 ) ) * 1000;
		$stop_before_timeout_ms = isset( $options['stop_before_timeout_ms'] ) ? max( 0, (int) $options['stop_before_timeout_ms'] ) : max( 0, (int) ( $options['stop_before_timeout'] ?? 0 ) ) * 1000;
		$hooks                  = $this->normalizeHooks( $options['hooks'] ?? null );
		$job_ids                = $this->normalizeJobIds( $options['job_ids'] ?? null );
		$lane                   = $this->normalizeLane( $options['lane'] ?? '' );
		$hooks                  = $this->hooksForLane( $lane, $hooks );
		$warning_callback       = is_callable( $options['warning_callback'] ?? null ) ? $options['warning_callback'] : null;
		$execution_context      = (string) ( $options['execution_context'] ?? 'Data Machine drain' );
		$terminal_callback      = is_callable( $options['terminal_status_callback'] ?? null ) ? $options['terminal_status_callback'] : null;
		$terminal_state         = '';

		$started_at    = microtime( true );
		$before_counts = $this->getStatusCounts( $hooks, $job_ids, $lane );
		$batches       = 0;
		$warnings      = 0;
		$stop_reason   = 'empty';

		while ( $this->getDuePendingCount( $hooks, $job_ids, $lane ) > 0 ) {
			if ( null !== $terminal_callback ) {
				$terminal_state = (string) $terminal_callback();
				if ( '' !== $terminal_state ) {
					$stop_reason = 'terminal_status';
					break;
				}
			}

			$stats = $this->buildStats( $before_counts, $this->getStatusCounts( $hooks, $job_ids, $lane ), $batches, $warnings, $hooks, $job_ids, $stop_reason, $lane, $terminal_state );
			// @phpstan-ignore-next-line
			if ( $this->isMemorySoftLimitReached() ) {
				$stop_reason = 'memory_limit';
				break;
			}

			if ( $limit > 0 && (int) $stats['actions_processed'] >= $limit ) {
				$stop_reason = 'limit';
				break;
			}

			$elapsed_ms = $this->elapsedMs( $started_at );
			if ( $time_limit_ms > 0 && $elapsed_ms >= $time_limit_ms ) {
				$stop_reason = 'time_limit';
				break;
			}

			if ( $time_limit_ms > 0 && ( $time_limit_ms - $elapsed_ms ) <= $stop_before_timeout_ms ) {
				$stop_reason = 'timeout_margin';
				break;
			}

			$current_batch_size = $batch_size;
			if ( $limit > 0 ) {
				$current_batch_size = min( $batch_size, $limit - (int) $stats['actions_processed'] );
			}
			if ( $current_batch_size <= 0 ) {
				$stop_reason = 'limit';
				break;
			}

			$due_before    = $this->getDuePendingCount( $hooks, $job_ids, $lane );
			$status_before = $this->getStatusCounts( $hooks, $job_ids, $lane );
			$deadline_at   = 0.0;
			if ( $time_limit_ms > 0 ) {
				$deadline_at = $started_at + max( 0, $time_limit_ms - $stop_before_timeout_ms ) / 1000;
			}
			$result = $this->runActionSchedulerBatch( $current_batch_size, $hooks, $job_ids, $deadline_at, $lane, $execution_context );
			++$batches;

			if ( 0 !== (int) $result['return_code'] ) {
				++$warnings;
				$message = trim( (string) $result['stderr'] );
				if ( null !== $warning_callback ) {
					$warning_callback( '' === $message ? 'Action Scheduler drain failed.' : $message );
				}
				$stop_reason = 'memory_limit' === (string) $result['stop_reason'] ? 'memory_limit' : 'warning';
				break;
			}

			$status_after = $this->getStatusCounts( $hooks, $job_ids, $lane );
			$progress     = (int) ( $result['actions_processed'] ?? $this->processedDelta( $status_before, $status_after ) );
			if ( '' !== (string) $result['stop_reason'] ) {
				$stop_reason = (string) $result['stop_reason'];
				break;
			}
			if ( 0 === $progress && $this->getDuePendingCount( $hooks, $job_ids, $lane ) >= $due_before ) {
				++$warnings;
				if ( null !== $warning_callback ) {
					$warning_callback( 'Drain stopped because Action Scheduler made no observable progress.' );
				}
				$stop_reason = 'no_progress';
				break;
			}
		}

		if ( null !== $terminal_callback && '' === $terminal_state ) {
			$terminal_state = (string) $terminal_callback();
			if ( '' !== $terminal_state && 'empty' === $stop_reason ) {
				$stop_reason = 'terminal_status';
			}
		}

		return $this->buildStats( $before_counts, $this->getStatusCounts( $hooks, $job_ids, $lane ), $batches, $warnings, $hooks, $job_ids, $stop_reason, $lane, $terminal_state );
	}

	/**
	 * Return a read-only status snapshot for a drain scope.
	 *
	 * @param array{hooks?:string[],job_ids?:string|int[],lane?:string} $options Status options.
	 * @return array<string,int|string> Status stats.
	 */
	public function status( array $options = array() ): array {
		$hooks   = $this->normalizeHooks( $options['hooks'] ?? null );
		$job_ids = $this->normalizeJobIds( $options['job_ids'] ?? null );
		$lane    = $this->normalizeLane( $options['lane'] ?? '' );
		$hooks   = $this->hooksForLane( $lane, $hooks );

		return array(
			'due_pending'   => $this->getDuePendingCount( $hooks, $job_ids, $lane ),
			'total_pending' => $this->getPendingCount( $hooks, $job_ids, $lane ),
			'hooks'         => implode( ',', array_keys( $this->getStatusCounts( $hooks, $job_ids, $lane ) ) ),
			'job_ids'       => implode( ',', $job_ids ),
			'lane'          => $lane,
		);
	}

	/**
	 * Build a lock-skipped drain result for a drain scope.
	 *
	 * @param string[]|null $hooks   Hook scope.
	 * @param int[]         $job_ids Job ID scope.
	 * @return array<string,int|string> Drain stats.
	 */
	public function lockedStats( ?array $hooks, array $job_ids, string $lane = '' ): array {
		$hooks   = $this->hooksForLane( $this->normalizeLane( $lane ), $this->normalizeHooks( $hooks ) );
		$job_ids = $this->normalizeJobIds( $job_ids );
		$lane    = $this->normalizeLane( $lane );

		return array(
			'batches'                    => 0,
			'batch_chunks'               => 0,
			'step_executions'            => 0,
			'completions'                => 0,
			'failures'                   => 0,
			'batch_chunk_completions'    => 0,
			'batch_chunk_failures'       => 0,
			'step_execution_completions' => 0,
			'step_execution_failures'    => 0,
			'actions_processed'          => 0,
			'other_actions'              => 0,
			'remaining_pending'          => $this->getDuePendingCount( $hooks, $job_ids, $lane ),
			'total_pending'              => $this->getPendingCount( $hooks, $job_ids, $lane ),
			'warnings'                   => 0,
			'stop_reason'                => 'locked',
			'hooks'                      => implode( ',', array_keys( $this->getStatusCounts( $hooks, $job_ids, $lane ) ) ),
			'job_ids'                    => implode( ',', $job_ids ),
			'lane'                       => $lane,
			'terminal_state'             => '',
		);
	}

	/**
	 * Raise the runtime memory floor for large drains.
	 */
	public static function ensureMemoryLimit( int $minimum_bytes = 1073741824 ): void {
		$current = self::memoryLimitBytes();
		if ( 0 === $current || $current >= $minimum_bytes ) {
			return;
		}

		add_filter(
			'admin_memory_limit',
			static function () use ( $minimum_bytes ): string {
				return (string) $minimum_bytes;
			}
		);
		wp_raise_memory_limit( 'admin' );
	}

	/**
	 * Claim and run a batch of due Action Scheduler actions.
	 *
	 * @param int           $batch_size       Maximum actions to claim.
	 * @param string[]|null $hooks            Hook scope, or null for all hooks in the group.
	 * @param int[]         $job_ids          Optional job ID scope.
	 * @param float         $deadline_at      Unix timestamp with microseconds; 0 for no deadline.
	 * @param string        $lane             Optional worker lane.
	 * @param string        $execution_context Action Scheduler execution context.
	 * @return array{return_code:int,stdout:string,stderr:string,stop_reason:string,actions_processed:int} Result data.
	 */
	private function runActionSchedulerBatch( int $batch_size, ?array $hooks = null, array $job_ids = array(), float $deadline_at = 0.0, string $lane = '', string $execution_context = 'Data Machine drain' ): array {
		$store       = \ActionScheduler_Store::instance();
		$runner      = \ActionScheduler::runner();
		$warnings    = array();
		$claim       = null;
		$stop_reason = '';
		$claim_size  = $this->claimSizeForScope( $batch_size, $hooks, $job_ids, $lane );
		$processed   = 0;

		try {
			GroupRegistrar::ensureDataMachineGroup();
			$this->runActionSchedulerTimeoutCleanup( $store );
			$claim = $store->stake_claim( $claim_size, null, $hooks ?? array(), self::GROUP );
		} catch ( \Throwable $throwable ) {
			return array(
				'return_code'       => 1,
				'stdout'            => '',
				'stderr'            => sprintf( 'Action Scheduler claim failed during drain: %s', $throwable->getMessage() ),
				'stop_reason'       => 'warning',
				'actions_processed' => 0,
			);
		}

		try {
			foreach ( $claim->get_actions() as $action_id ) {
				if ( $deadline_at > 0 && microtime( true ) >= $deadline_at ) {
					$stop_reason = 'timeout_margin';
					break;
				}

				// @phpstan-ignore-next-line
				if ( $this->isMemorySoftLimitReached() ) {
					$warnings[] = 'Drain stopped before processing the next action because PHP memory usage reached the soft limit.';
					break;
				}

				$action_id = absint( $action_id );
				if ( $action_id <= 0 || ! $this->actionMatchesJobIds( $action_id, $job_ids ) || ! $this->actionMatchesLane( $action_id, $lane ) ) {
					continue;
				}

				$claimed_action_ids = array_map( 'intval', $store->find_actions_by_claim_id( $claim->get_id() ) );
				if ( ! in_array( $action_id, $claimed_action_ids, true ) ) {
					$warnings[] = sprintf( 'Action Scheduler claim %d was lost during drain.', $claim->get_id() );
					break;
				}

				try {
					$runner->process_action( $action_id, $execution_context );
					++$processed;
				} catch ( \Throwable $throwable ) {
					$warnings[] = sprintf( 'Action %d failed during drain: %s', $action_id, $throwable->getMessage() );
				} finally {
					$this->flushRuntimeCache();
				}

				if ( $processed >= $batch_size ) {
					break;
				}
			}
		} finally {
			$store->release_claim( $claim );
		}

		$stop_reason = $this->warningsContainMemoryLimit( $warnings ) ? 'memory_limit' : $stop_reason;

		return array(
			'return_code'       => empty( $warnings ) ? 0 : 1,
			'stdout'            => '',
			'stderr'            => implode( "\n", $warnings ),
			'stop_reason'       => $stop_reason,
			'actions_processed' => $processed,
		);
	}

	/**
	 * Determine how many due actions must be claimed to reach a scoped target.
	 *
	 * @param int           $batch_size Maximum matching actions to process.
	 * @param string[]|null $hooks      Hook scope, or null for all hooks in the group.
	 * @param int[]         $job_ids    Optional job ID scope.
	 */
	private function claimSizeForScope( int $batch_size, ?array $hooks, array $job_ids, string $lane ): int {
		$claim_size = '' === $lane ? $batch_size : max( $batch_size, min( 1000, $batch_size * 20 ) );

		if ( empty( $job_ids ) ) {
			return $claim_size;
		}

		return max( $claim_size, $this->claimSizeThroughFirstJobAction( $hooks, $job_ids ) );
	}

	/**
	 * Count due group actions through the first due action matching a job scope.
	 *
	 * @param string[]|null $hooks   Hook scope, or null for all hooks in the group.
	 * @param int[]         $job_ids Job ID scope.
	 */
	private function claimSizeThroughFirstJobAction( ?array $hooks, array $job_ids ): int {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';
		$hook_sql      = $this->hookWhereSql( $hooks, $job_ids );
		$now           = gmdate( 'Y-m-d H:i:s' );
		$values        = array_merge(
			array( $actions_table, $groups_table ),
			$hook_sql['values'],
			array( self::GROUP, $now )
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic hook/job scope is constructed from normalized placeholders and values.
		$target = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT a.action_id, a.scheduled_date_gmt, a.priority
				FROM %i a
				INNER JOIN %i g ON g.group_id = a.group_id
				WHERE ' . $hook_sql['sql'] . 'a.status = \'pending\'
				AND g.slug = %s
				AND a.scheduled_date_gmt <= %s
				ORDER BY a.scheduled_date_gmt ASC, a.priority ASC, a.action_id ASC
				LIMIT 1',
				$values
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_array( $target ) ) {
			return 0;
		}

		$hook_sql = $this->hookWhereSql( $hooks );
		$values   = array_merge(
			array( $actions_table, $groups_table ),
			$hook_sql['values'],
			array(
				self::GROUP,
				(string) $target['scheduled_date_gmt'],
				(string) $target['scheduled_date_gmt'],
				(int) $target['priority'],
				(string) $target['scheduled_date_gmt'],
				(int) $target['priority'],
				(int) $target['action_id'],
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic hook scope is constructed from normalized placeholders and values.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*)
				FROM %i a
				INNER JOIN %i g ON g.group_id = a.group_id
				WHERE ' . $hook_sql['sql'] . 'a.status = \'pending\'
				AND g.slug = %s
				AND (
					a.scheduled_date_gmt < %s
					OR (a.scheduled_date_gmt = %s AND a.priority < %d)
					OR (a.scheduled_date_gmt = %s AND a.priority = %d AND a.action_id <= %d)
				)',
				$values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * Reset stale claims/running actions before staking a direct drain claim.
	 *
	 * @param \ActionScheduler_Store $store Action Scheduler store.
	 */
	private function runActionSchedulerTimeoutCleanup( \ActionScheduler_Store $store ): void {
		if ( ! class_exists( '\ActionScheduler_QueueCleaner' ) ) {
			return;
		}

		$time_limit = absint( apply_filters( 'action_scheduler_queue_runner_time_limit', 30 ) );
		$timeout    = max( 1, $time_limit ) * 10;
		$cleaner    = new \ActionScheduler_QueueCleaner( $store );

		$cleaner->reset_timeouts( $timeout );
		$cleaner->mark_failures( $timeout );
	}

	/**
	 * Flush in-request object cache state after each drained action.
	 */
	private function flushRuntimeCache(): void {
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) && function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
			return;
		}

		if ( ! function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}

	/**
	 * Whether PHP memory usage is near the hard limit.
	 */
	private function isMemorySoftLimitReached(): bool {
		$limit = self::memoryLimitBytes();
		if ( $limit <= 0 ) {
			return false;
		}

		$ratio = (float) apply_filters( 'datamachine_cli_drain_memory_soft_limit_ratio', 0.80 );
		$ratio = max( 0.50, min( 0.95, $ratio ) );

		return memory_get_usage( true ) >= (int) floor( $limit * $ratio );
	}

	/**
	 * Return PHP memory_limit in bytes, or 0 for unlimited/unknown.
	 */
	private static function memoryLimitBytes(): int {
		$raw = trim( (string) ini_get( 'memory_limit' ) );
		if ( '' === $raw || '-1' === $raw ) {
			return 0;
		}

		$unit  = strtolower( substr( $raw, -1 ) );
		$value = (float) $raw;
		switch ( $unit ) {
			case 'g':
				$value *= 1024;
				// Fall through.
			case 'm':
				$value *= 1024;
				// Fall through.
			case 'k':
				$value *= 1024;
		}

		return max( 0, (int) $value );
	}

	/**
	 * Whether a batch warning was caused by the memory soft limit.
	 *
	 * @param string[] $warnings Warning messages.
	 */
	private function warningsContainMemoryLimit( array $warnings ): bool {
		foreach ( $warnings as $warning ) {
			if ( false !== strpos( $warning, 'memory usage reached the soft limit' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a claimed action belongs to the requested job scope.
	 *
	 * @param int   $action_id Action ID.
	 * @param int[] $job_ids   Optional job ID scope.
	 */
	private function actionMatchesJobIds( int $action_id, array $job_ids ): bool {
		if ( empty( $job_ids ) ) {
			return true;
		}

		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This checks the args for an already-claimed Action Scheduler row.
		$args = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT args FROM %i WHERE action_id = %d',
				$actions_table,
				$action_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $job_ids as $job_id ) {
			if (
				false !== strpos( $args, '"job_id":' . $job_id . ',' )
				|| false !== strpos( $args, '"job_id":' . $job_id . '}' )
				|| false !== strpos( $args, '"parent_job_id":' . $job_id . ',' )
				|| false !== strpos( $args, '"parent_job_id":' . $job_id . '}' )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a claimed action belongs to the requested lane.
	 */
	private function actionMatchesLane( int $action_id, string $lane ): bool {
		if ( '' === $lane ) {
			return true;
		}

		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This checks an already-claimed Action Scheduler row.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT hook, args FROM %i WHERE action_id = %d',
				$actions_table,
				$action_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $row ) ) {
			return false;
		}

		return $this->actionRowMatchesLane( (string) ( $row['hook'] ?? '' ), (string) ( $row['args'] ?? '' ), $lane );
	}

	/**
	 * Check an Action Scheduler row against a worker lane.
	 */
	private function actionRowMatchesLane( string $hook, string $args, string $lane ): bool {
		if ( '' === $lane ) {
			return true;
		}

		$is_publish = self::HOOK_EXECUTE_STEP === $hook && in_array( $this->stepTypeFromExecuteStepArgs( $args ), array( 'ai', 'upsert' ), true );
		if ( 'publish' === $lane ) {
			return $is_publish;
		}

		if ( 'background' === $lane ) {
			return ! $is_publish;
		}

		return true;
	}

	/**
	 * Resolve an execute-step action's configured step type.
	 */
	private function stepTypeFromExecuteStepArgs( string $args ): string {
		$decoded = json_decode( $args, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$job_id       = absint( $decoded['job_id'] ?? 0 );
		$flow_step_id = (string) ( $decoded['flow_step_id'] ?? '' );
		if ( $job_id <= 0 || '' === $flow_step_id || ! function_exists( 'datamachine_get_engine_data' ) ) {
			return '';
		}

		$engine_data = datamachine_get_engine_data( $job_id );
		$flow_config = is_array( $engine_data['flow_config'] ?? null ) ? $engine_data['flow_config'] : array();
		$step_config = is_array( $flow_config[ $flow_step_id ] ?? null ) ? $flow_config[ $flow_step_id ] : array();

		return (string) ( $step_config['step_type'] ?? '' );
	}

	/**
	 * Build operator-facing stats.
	 *
	 * @param array<string,array<string,int>> $before_counts Counts before drain.
	 * @param array<string,array<string,int>> $after_counts  Counts after drain.
	 * @param int                             $batches       Batch count.
	 * @param int                             $warnings      Warning count.
	 * @return array<string,int|string> Stats.
	 */
	private function buildStats( array $before_counts, array $after_counts, int $batches, int $warnings, ?array $hooks = null, array $job_ids = array(), string $stop_reason = 'empty', string $lane = '', string $terminal_state = '' ): array {
		$batch_completed   = $this->delta( $before_counts, $after_counts, self::HOOK_BATCH_CHUNK, 'complete' );
		$batch_failed      = $this->delta( $before_counts, $after_counts, self::HOOK_BATCH_CHUNK, 'failed' );
		$step_completed    = $this->delta( $before_counts, $after_counts, self::HOOK_EXECUTE_STEP, 'complete' );
		$step_failed       = $this->delta( $before_counts, $after_counts, self::HOOK_EXECUTE_STEP, 'failed' );
		$total_completed   = $this->statusDelta( $before_counts, $after_counts, 'complete' );
		$total_failed      = $this->statusDelta( $before_counts, $after_counts, 'failed' );
		$total_processed   = $total_completed + $total_failed;
		$tracked_processed = $batch_completed + $batch_failed + $step_completed + $step_failed;

		return array(
			'batches'                    => $batches,
			'batch_chunks'               => $batch_completed + $batch_failed,
			'step_executions'            => $step_completed + $step_failed,
			'completions'                => $total_completed,
			'failures'                   => $total_failed,
			'batch_chunk_completions'    => $batch_completed,
			'batch_chunk_failures'       => $batch_failed,
			'step_execution_completions' => $step_completed,
			'step_execution_failures'    => $step_failed,
			'actions_processed'          => $total_processed,
			'other_actions'              => max( 0, $total_processed - $tracked_processed ),
			'remaining_pending'          => $this->getDuePendingCount( $hooks, $job_ids, $lane ),
			'total_pending'              => $this->getPendingCount( $hooks, $job_ids, $lane ),
			'warnings'                   => $warnings,
			'stop_reason'                => $stop_reason,
			'hooks'                      => implode( ',', $this->processedHooks( $before_counts, $after_counts ) ),
			'job_ids'                    => implode( ',', $job_ids ),
			'lane'                       => $lane,
			'terminal_state'             => $terminal_state,
		);
	}

	/**
	 * Count status deltas that indicate an action was processed.
	 *
	 * @param array<string,array<string,int>> $before_counts Counts before a batch.
	 * @param array<string,array<string,int>> $after_counts  Counts after a batch.
	 */
	private function processedDelta( array $before_counts, array $after_counts ): int {
		return $this->statusDelta( $before_counts, $after_counts, 'complete' ) + $this->statusDelta( $before_counts, $after_counts, 'failed' );
	}

	/**
	 * Count status deltas across all Data Machine hooks.
	 *
	 * @param array<string,array<string,int>> $before_counts Counts before.
	 * @param array<string,array<string,int>> $after_counts  Counts after.
	 * @param string                          $status        Action status.
	 */
	private function statusDelta( array $before_counts, array $after_counts, string $status ): int {
		$total = 0;
		foreach ( $this->allHooks( $before_counts, $after_counts ) as $hook ) {
			$total += $this->delta( $before_counts, $after_counts, $hook, $status );
		}
		return $total;
	}

	/**
	 * Get hooks with observed processed deltas.
	 *
	 * @param array<string,array<string,int>> $before_counts Counts before.
	 * @param array<string,array<string,int>> $after_counts  Counts after.
	 * @return string[] Hook names.
	 */
	private function processedHooks( array $before_counts, array $after_counts ): array {
		$hooks = array();
		foreach ( $this->allHooks( $before_counts, $after_counts ) as $hook ) {
			if ( $this->delta( $before_counts, $after_counts, $hook, 'complete' ) > 0 || $this->delta( $before_counts, $after_counts, $hook, 'failed' ) > 0 ) {
				$hooks[] = $hook;
			}
		}
		return $hooks;
	}

	/**
	 * Get hooks seen in either status snapshot.
	 *
	 * @param array<string,array<string,int>> $before_counts Counts before.
	 * @param array<string,array<string,int>> $after_counts  Counts after.
	 * @return string[] Hook names.
	 */
	private function allHooks( array $before_counts, array $after_counts ): array {
		return array_values( array_unique( array_merge( array_keys( $before_counts ), array_keys( $after_counts ) ) ) );
	}

	/**
	 * Normalize hook-scope options.
	 *
	 * @param mixed $hooks Optional hook list.
	 * @return string[]|null Hook list, or null for all Data Machine hooks.
	 */
	private function normalizeHooks( mixed $hooks ): ?array {
		if ( ! is_array( $hooks ) ) {
			return null;
		}

		$hooks = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $hooks ),
					static fn( string $hook ): bool => '' !== $hook
				)
			)
		);

		return empty( $hooks ) ? null : $hooks;
	}

	/**
	 * Normalize a worker lane identifier.
	 */
	private function normalizeLane( mixed $lane ): string {
		$lane = is_string( $lane ) ? strtolower( trim( $lane ) ) : '';
		return in_array( $lane, array( 'publish', 'background' ), true ) ? $lane : '';
	}

	/**
	 * Restrict hook claims for lanes where hook-level selection is safe.
	 */
	private function hooksForLane( string $lane, ?array $hooks ): ?array {
		if ( 'publish' === $lane ) {
			return array( self::HOOK_EXECUTE_STEP );
		}

		return $hooks;
	}

	/**
	 * Normalize an optional job-id scope.
	 *
	 * @param mixed $job_ids Optional comma-separated string or ID list.
	 * @return int[] Job IDs.
	 */
	private function normalizeJobIds( mixed $job_ids ): array {
		if ( is_string( $job_ids ) ) {
			$job_ids = preg_split( '/\s*,\s*/', $job_ids, -1, PREG_SPLIT_NO_EMPTY );
		}

		if ( ! is_array( $job_ids ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $job_ids as $job_id ) {
			$job_id = absint( $job_id );
			if ( $job_id > 0 ) {
				$normalized[] = $job_id;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Build optional hook WHERE SQL for prepared Action Scheduler queries.
	 *
	 * @param string[]|null $hooks Hook scope, or null for all hooks in the group.
	 * @param int[]         $job_ids Optional job ID scope.
	 * @return array{sql:string,values:string[]} SQL fragment and placeholder values.
	 */
	private function hookWhereSql( ?array $hooks, array $job_ids = array() ): array {
		$values = array();
		$sql    = '';

		if ( ! empty( $hooks ) ) {
			$sql   .= 'a.hook IN (' . implode( ', ', array_fill( 0, count( $hooks ), '%s' ) ) . ') AND ';
			$values = array_merge( $values, $hooks );
		}

		if ( ! empty( $job_ids ) ) {
			$clauses = array();
			foreach ( $job_ids as $job_id ) {
				$clauses[] = '(a.args LIKE %s OR a.args LIKE %s OR a.args LIKE %s OR a.args LIKE %s)';
				$values[]  = '%"job_id":' . $job_id . ',%';
				$values[]  = '%"job_id":' . $job_id . '}%';
				$values[]  = '%"parent_job_id":' . $job_id . ',%';
				$values[]  = '%"parent_job_id":' . $job_id . '}%';
			}
			$sql .= '(' . implode( ' OR ', $clauses ) . ') AND ';
		}

		return array(
			'sql'    => $sql,
			'values' => $values,
		);
	}

	/**
	 * Get a status count delta.
	 *
	 * @param array<string,array<string,int>> $before_counts Counts before.
	 * @param array<string,array<string,int>> $after_counts  Counts after.
	 */
	private function delta( array $before_counts, array $after_counts, string $hook, string $status ): int {
		return max( 0, ( $after_counts[ $hook ][ $status ] ?? 0 ) - ( $before_counts[ $hook ][ $status ] ?? 0 ) );
	}

	/**
	 * Count due pending Data Machine actions.
	 */
	private function getDuePendingCount( ?array $hooks = null, array $job_ids = array(), string $lane = '' ): int {
		return $this->countActions( true, $hooks, $job_ids, $lane );
	}

	/**
	 * Count all pending Data Machine actions.
	 */
	private function getPendingCount( ?array $hooks = null, array $job_ids = array(), string $lane = '' ): int {
		return $this->countActions( false, $hooks, $job_ids, $lane );
	}

	/**
	 * Count pending Data Machine actions.
	 *
	 * @param bool $due_only Whether to count only due actions.
	 */
	private function countActions( bool $due_only, ?array $hooks = null, array $job_ids = array(), string $lane = '' ): int {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';
		$hook_sql      = $this->hookWhereSql( $hooks, $job_ids );

		if ( '' !== $lane ) {
			return $this->countLaneActions( $due_only, $hooks, $job_ids, $lane );
		}

		if ( $due_only ) {
			$values = array_merge(
				array( $actions_table, $groups_table ),
				$hook_sql['values'],
				array( self::GROUP, gmdate( 'Y-m-d H:i:s' ) )
			);

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic hook scope is constructed from normalized placeholders and values.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*)
					FROM %i a
					INNER JOIN %i g ON g.group_id = a.group_id
					WHERE ' . $hook_sql['sql'] . 'a.status = \'pending\'
					AND g.slug = %s
					AND a.scheduled_date_gmt <= %s',
					$values
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		}

		$values = array_merge(
			array( $actions_table, $groups_table ),
			$hook_sql['values'],
			array( self::GROUP )
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic hook scope is constructed from normalized placeholders and values.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*)
				FROM %i a
				INNER JOIN %i g ON g.group_id = a.group_id
				WHERE ' . $hook_sql['sql'] . 'a.status = \'pending\'
				AND g.slug = %s',
				$values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * Count pending actions that match a lane.
	 */
	private function countLaneActions( bool $due_only, ?array $hooks, array $job_ids, string $lane ): int {
		$count = 0;
		foreach ( $this->laneActionRows( $due_only, $hooks, $job_ids ) as $row ) {
			if ( $this->actionRowMatchesLane( (string) ( $row['hook'] ?? '' ), (string) ( $row['args'] ?? '' ), $lane ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Retrieve Action Scheduler rows for lane filtering.
	 *
	 * @return array<int,array<string,string>> Action rows.
	 */
	private function laneActionRows( bool $due_only, ?array $hooks, array $job_ids ): array {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';
		$hook_sql      = $this->hookWhereSql( $hooks, $job_ids );
		$status_sql    = "a.status = 'pending'";
		$date_sql      = $due_only ? 'AND a.scheduled_date_gmt <= %s' : '';
		$values        = array_merge(
			array( $actions_table, $groups_table ),
			$hook_sql['values'],
			array( self::GROUP )
		);

		if ( $due_only ) {
			$values[] = gmdate( 'Y-m-d H:i:s' );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic scope is constructed from normalized placeholders and values.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.action_id, a.hook, a.status, a.args
				FROM %i a
				INNER JOIN %i g ON g.group_id = a.group_id
				WHERE ' . $hook_sql['sql'] . $status_sql . '
				AND g.slug = %s
				' . $date_sql,
				$values
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get action counts grouped by hook and status.
	 *
	 * @return array<string,array<string,int>> Counts by hook and status.
	 */
	private function getStatusCounts( ?array $hooks = null, array $job_ids = array(), string $lane = '' ): array {
		global $wpdb;
		unset( $lane );

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';
		$hook_sql      = $this->hookWhereSql( $hooks, $job_ids );
		$values        = array_merge(
			array( $actions_table, $groups_table ),
			$hook_sql['values'],
			array( self::GROUP )
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic hook scope is constructed from normalized placeholders and values.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.hook, a.status, COUNT(*) AS count
				FROM %i a
				INNER JOIN %i g ON g.group_id = a.group_id
				WHERE ' . $hook_sql['sql'] . 'g.slug = %s
				GROUP BY a.hook, a.status',
				$values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$counts = array();

		foreach ( $rows as $row ) {
			$hook   = (string) $row->hook;
			$status = (string) $row->status;
			if ( ! isset( $counts[ $hook ] ) ) {
				$counts[ $hook ] = array(
					'pending'  => 0,
					'complete' => 0,
					'failed'   => 0,
				);
			}
			$counts[ $hook ][ $status ] = (int) $row->count;
		}

		return $counts;
	}

	/**
	 * Return elapsed wall-clock milliseconds.
	 */
	private function elapsedMs( float $started_at ): int {
		return (int) round( ( microtime( true ) - $started_at ) * 1000 );
	}
}
