<?php
/**
 * WP-CLI Data Machine drain command.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use DataMachine\Cli\WorkerLock;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Drain due Data Machine Action Scheduler work.
 */
class DrainCommand extends BaseCommand {

	private const GROUP = 'data-machine';

	public const HOOK_BATCH_CHUNK = 'datamachine_pipeline_batch_chunk';

	public const HOOK_EXECUTE_STEP = 'datamachine_execute_step';

	/**
	 * Drain due Data Machine actions until empty or a budget is reached.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum actions to execute. 0 means no action-count limit.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--batch-size=<number>]
	 * : Maximum actions to ask Action Scheduler to claim per batch.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--time-limit=<seconds>]
	 * : Maximum wall-clock seconds to drain. 0 means no time limit.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--stop-before-timeout=<seconds>]
	 * : Stop this many seconds before the wall-clock limit so the drain exits
	 * cleanly before an external supervisor timeout. Only applies when
	 * --time-limit is greater than 0.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--job-id=<ids>]
	 * : Optional comma-separated Data Machine job IDs to drain. Useful when
	 * unrelated due work is blocked ahead of a known cleanup or retry run.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine drain
	 *     wp datamachine drain --limit=500 --time-limit=300 --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Keyed arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$stats = self::drain(
			array(
				'limit'               => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0,
				'batch_size'          => isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 25,
				'time_limit'          => isset( $assoc_args['time-limit'] ) ? (int) $assoc_args['time-limit'] : 0,
				'stop_before_timeout' => isset( $assoc_args['stop-before-timeout'] ) ? (int) $assoc_args['stop-before-timeout'] : 0,
				'job_ids'             => isset( $assoc_args['job-id'] ) ? (string) $assoc_args['job-id'] : '',
			)
		);

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $stats, JSON_PRETTY_PRINT ) );
			return;
		}

		$this->format_items( array( $stats ), array_keys( $stats ), array( 'format' => 'table' ) );
	}

	/**
	 * Drain due Data Machine actions and return a compact summary.
	 *
	 * Scheduler health checks count the full Data Machine Action Scheduler group,
	 * so this drain runs concrete due action IDs from that same group instead of
	 * a hand-maintained hook allow-list.
	 *
	 * @param array{limit?:int,batch_size?:int,time_limit?:int,stop_before_timeout?:int,hooks?:string[],job_ids?:string|int[],acquire_lock?:bool,lock_owner?:string} $options Drain options.
	 * @return array<string,int|string> Drain stats.
	 */
	public static function drain( array $options = array() ): array {
		$limit               = max( 0, (int) ( $options['limit'] ?? 0 ) );
		$batch_size          = max( 1, (int) ( $options['batch_size'] ?? 25 ) );
		$time_limit          = max( 0, (int) ( $options['time_limit'] ?? 0 ) );
		$stop_before_timeout = max( 0, (int) ( $options['stop_before_timeout'] ?? 0 ) );
		$hooks               = self::normalizeHooks( $options['hooks'] ?? null );
		$job_ids             = self::normalizeJobIds( $options['job_ids'] ?? null );
		$acquire_lock        = (bool) ( $options['acquire_lock'] ?? true );
		$lock                = array();

		if ( $acquire_lock ) {
			$lock_ttl = $time_limit > 0 ? $time_limit + max( 60, $stop_before_timeout ) : 600;
			$lock     = WorkerLock::acquire( (string) ( $options['lock_owner'] ?? self::defaultLockOwner( 'drain' ) ), $lock_ttl );
			if ( empty( $lock['acquired'] ) ) {
				return self::lockedStats( $hooks, $job_ids, $lock );
			}
		}

		$started_at    = time();
		$before_counts = self::getStatusCounts( $hooks, $job_ids );
		$batches       = 0;
		$warnings      = 0;
		$stop_reason   = 'empty';

		try {
			while ( self::getDuePendingCount( $hooks, $job_ids ) > 0 ) {
				$stats = self::buildStats( $before_counts, self::getStatusCounts( $hooks, $job_ids ), $batches, $warnings, $hooks, $job_ids, $stop_reason );
				if ( $limit > 0 && (int) $stats['actions_processed'] >= $limit ) {
					$stop_reason = 'limit';
					break;
				}

				if ( $time_limit > 0 && ( time() - $started_at ) >= $time_limit ) {
					$stop_reason = 'time_limit';
					break;
				}

				if ( $time_limit > 0 && ( $time_limit - ( time() - $started_at ) ) <= $stop_before_timeout ) {
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

				$due_before    = self::getDuePendingCount( $hooks, $job_ids );
				$status_before = self::getStatusCounts( $hooks, $job_ids );
				$result        = self::runActionSchedulerBatch( $current_batch_size, $hooks, $job_ids );
				++$batches;

				if ( 0 !== (int) ( $result->return_code ?? 1 ) ) {
					++$warnings;
					$message = trim( (string) ( $result->stderr ?? '' ) );
					WP_CLI::warning( '' === $message ? 'Action Scheduler CLI drain failed.' : $message );
					$stop_reason = 'warning';
					break;
				}

				$status_after = self::getStatusCounts( $hooks, $job_ids );
				$progress     = self::processedDelta( $status_before, $status_after );
				if ( 0 === $progress && self::getDuePendingCount( $hooks, $job_ids ) >= $due_before ) {
					++$warnings;
					WP_CLI::warning( 'Drain stopped because Action Scheduler made no observable progress.' );
					$stop_reason = 'no_progress';
					break;
				}
			}

			$stats = self::buildStats( $before_counts, self::getStatusCounts( $hooks, $job_ids ), $batches, $warnings, $hooks, $job_ids, $stop_reason );
			return self::withLockStatus( $stats, $lock );
		} finally {
			if ( $acquire_lock ) {
				WorkerLock::release( (string) ( $lock['lock_token'] ?? '' ) );
			}
		}
	}

	/**
	 * Return a read-only Data Machine Action Scheduler status snapshot.
	 *
	 * @param array{hooks?:string[],job_ids?:string|int[]} $options Status options.
	 * @return array<string,int|string> Status stats.
	 */
	public static function status( array $options = array() ): array {
		$hooks   = self::normalizeHooks( $options['hooks'] ?? null );
		$job_ids = self::normalizeJobIds( $options['job_ids'] ?? null );
		$lock    = WorkerLock::snapshot();

		return array(
			'due_pending'   => self::getDuePendingCount( $hooks, $job_ids ),
			'total_pending' => self::getPendingCount( $hooks, $job_ids ),
			'hooks'         => implode( ',', array_keys( self::getStatusCounts( $hooks, $job_ids ) ) ),
			'job_ids'       => implode( ',', $job_ids ),
		) + self::publicLockStatus( $lock );
	}

	/**
	 * Build a lock-skipped drain result.
	 *
	 * @param string[]|null                 $hooks   Hook scope.
	 * @param int[]                         $job_ids Job ID scope.
	 * @param array<string,int|string|bool> $lock    Lock state.
	 * @return array<string,int|string> Drain stats.
	 */
	private static function lockedStats( ?array $hooks, array $job_ids, array $lock ): array {
		return self::withLockStatus(
			array(
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
				'remaining_pending'          => self::getDuePendingCount( $hooks, $job_ids ),
				'total_pending'              => self::getPendingCount( $hooks, $job_ids ),
				'warnings'                   => 0,
				'stop_reason'                => 'locked',
				'hooks'                      => implode( ',', array_keys( self::getStatusCounts( $hooks, $job_ids ) ) ),
				'job_ids'                    => implode( ',', $job_ids ),
			),
			$lock
		);
	}

	/**
	 * Add operator-facing lock fields to stats.
	 *
	 * @param array<string,int|string>      $stats Stats.
	 * @param array<string,int|string|bool> $lock  Lock state.
	 * @return array<string,int|string> Stats with lock fields.
	 */
	private static function withLockStatus( array $stats, array $lock ): array {
		return $stats + self::publicLockStatus( $lock );
	}

	/**
	 * Strip private lock fields before CLI output.
	 *
	 * @param array<string,int|string|bool> $lock Lock state.
	 * @return array<string,int|string> Public lock state.
	 */
	private static function publicLockStatus( array $lock ): array {
		return array(
			'lock_status'      => (string) ( $lock['lock_status'] ?? 'unlocked' ),
			'lock_owner'       => (string) ( $lock['lock_owner'] ?? '' ),
			'lock_age_seconds' => (int) ( $lock['lock_age_seconds'] ?? 0 ),
			'lock_expires_at'  => (int) ( $lock['lock_expires_at'] ?? 0 ),
		);
	}

	/**
	 * Build a compact default owner string for lock diagnostics.
	 */
	private static function defaultLockOwner( string $command ): string {
		$pid = getmypid();

		return sprintf( '%s pid:%d host:%s', $command, false === $pid ? 0 : $pid, php_uname( 'n' ) );
	}

	/**
	 * Claim and run a batch of due Action Scheduler actions.
	 *
	 * @param int           $batch_size Maximum actions to claim.
	 * @param string[]|null $hooks      Hook scope, or null for all hooks in the group.
	 * @param int[]         $job_ids    Optional job ID scope.
	 * @return object Result object with return_code and stderr fields.
	 */
	private static function runActionSchedulerBatch( int $batch_size, ?array $hooks = null, array $job_ids = array() ): object {
		$store    = \ActionScheduler_Store::instance();
		$runner   = \ActionScheduler::runner();
		$warnings = array();
		$claim    = null;

		try {
			self::runActionSchedulerTimeoutCleanup( $store );
			$claim = $store->stake_claim( $batch_size, null, $hooks ?? array(), self::GROUP );
		} catch ( \Throwable $throwable ) {
			return (object) array(
				'return_code' => 1,
				'stdout'      => '',
				'stderr'      => sprintf( 'Action Scheduler claim failed during drain: %s', $throwable->getMessage() ),
			);
		}

		try {
			foreach ( $claim->get_actions() as $action_id ) {
				$action_id = absint( $action_id );
				if ( $action_id <= 0 || ! self::actionMatchesJobIds( $action_id, $job_ids ) ) {
					continue;
				}

				$claimed_action_ids = array_map( 'intval', $store->find_actions_by_claim_id( $claim->get_id() ) );
				if ( ! in_array( $action_id, $claimed_action_ids, true ) ) {
					$warnings[] = sprintf( 'Action Scheduler claim %d was lost during drain.', $claim->get_id() );
					break;
				}

				try {
					$runner->process_action( $action_id, 'Data Machine CLI drain' );
				} catch ( \Throwable $throwable ) {
					$warnings[] = sprintf( 'Action %d failed during drain: %s', $action_id, $throwable->getMessage() );
				} finally {
					self::flushRuntimeCache();
				}
			}
		} finally {
			$store->release_claim( $claim );
		}

		return (object) array(
			'return_code' => empty( $warnings ) ? 0 : 1,
			'stdout'      => '',
			'stderr'      => implode( "\n", $warnings ),
		);
	}

	/**
	 * Reset stale claims/running actions before staking a CLI drain claim.
	 *
	 * Action Scheduler's own runners call their protected run_cleanup() before
	 * claiming work. Data Machine claims a group-scoped batch directly, so it must
	 * perform the same timeout cleanup or old claims can leave due actions counted
	 * as pending but not claimable.
	 *
	 * @param \ActionScheduler_Store $store Action Scheduler store.
	 */
	private static function runActionSchedulerTimeoutCleanup( \ActionScheduler_Store $store ): void {
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
	 * Flush in-request object cache state after each CLI-drained action.
	 */
	private static function flushRuntimeCache(): void {
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) && function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
			return;
		}

		if ( ! function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}

	/**
	 * Check whether a claimed action belongs to the requested job scope.
	 *
	 * @param int   $action_id Action ID.
	 * @param int[] $job_ids   Optional job ID scope.
	 * @return bool True when the action is in scope.
	 */
	private static function actionMatchesJobIds( int $action_id, array $job_ids ): bool {
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
	 * Build operator-facing stats.
	 *
	 * @param array<string,array<string,int>> $before_counts Counts before drain.
	 * @param array<string,array<string,int>> $after_counts  Counts after drain.
	 * @param int                             $batches       Batch count.
	 * @param int                             $warnings      Warning count.
	 * @return array<string,int|string> Stats.
	 */
	private static function buildStats( array $before_counts, array $after_counts, int $batches, int $warnings, ?array $hooks = null, array $job_ids = array(), string $stop_reason = 'empty' ): array {
		$batch_completed   = self::delta( $before_counts, $after_counts, self::HOOK_BATCH_CHUNK, 'complete' );
		$batch_failed      = self::delta( $before_counts, $after_counts, self::HOOK_BATCH_CHUNK, 'failed' );
		$step_completed    = self::delta( $before_counts, $after_counts, self::HOOK_EXECUTE_STEP, 'complete' );
		$step_failed       = self::delta( $before_counts, $after_counts, self::HOOK_EXECUTE_STEP, 'failed' );
		$total_completed   = self::statusDelta( $before_counts, $after_counts, 'complete' );
		$total_failed      = self::statusDelta( $before_counts, $after_counts, 'failed' );
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
			'remaining_pending'          => self::getDuePendingCount( $hooks, $job_ids ),
			'total_pending'              => self::getPendingCount( $hooks, $job_ids ),
			'warnings'                   => $warnings,
			'stop_reason'                => $stop_reason,
			'hooks'                      => implode( ',', self::processedHooks( $before_counts, $after_counts ) ),
			'job_ids'                    => implode( ',', $job_ids ),
		);
	}

	/**
	 * Count status deltas that indicate an action was processed.
	 *
	 * @param array<string,array<string,int>> $before_counts Counts before a batch.
	 * @param array<string,array<string,int>> $after_counts  Counts after a batch.
	 * @return int Processed action count.
	 */
	private static function processedDelta( array $before_counts, array $after_counts ): int {
		return self::statusDelta( $before_counts, $after_counts, 'complete' ) + self::statusDelta( $before_counts, $after_counts, 'failed' );
	}

	/**
	 * Count status deltas across all Data Machine hooks.
	 *
	 * @param array<string,array<string,int>> $before_counts Counts before.
	 * @param array<string,array<string,int>> $after_counts  Counts after.
	 * @param string                          $status        Action status.
	 * @return int Non-negative delta.
	 */
	private static function statusDelta( array $before_counts, array $after_counts, string $status ): int {
		$total = 0;
		foreach ( self::allHooks( $before_counts, $after_counts ) as $hook ) {
			$total += self::delta( $before_counts, $after_counts, $hook, $status );
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
	private static function processedHooks( array $before_counts, array $after_counts ): array {
		$hooks = array();
		foreach ( self::allHooks( $before_counts, $after_counts ) as $hook ) {
			if ( self::delta( $before_counts, $after_counts, $hook, 'complete' ) > 0 || self::delta( $before_counts, $after_counts, $hook, 'failed' ) > 0 ) {
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
	private static function allHooks( array $before_counts, array $after_counts ): array {
		return array_values( array_unique( array_merge( array_keys( $before_counts ), array_keys( $after_counts ) ) ) );
	}

	/**
	 * Normalize hook-scope options.
	 *
	 * @param mixed $hooks Optional hook list.
	 * @return string[]|null Hook list, or null for all Data Machine hooks.
	 */
	private static function normalizeHooks( mixed $hooks ): ?array {
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
	 * Normalize an optional job-id scope.
	 *
	 * @param mixed $job_ids Optional comma-separated string or ID list.
	 * @return int[] Job IDs.
	 */
	private static function normalizeJobIds( mixed $job_ids ): array {
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
	 * @return array{sql:string,values:string[]} SQL fragment and placeholder values.
	 */
	private static function hookWhereSql( ?array $hooks, array $job_ids = array() ): array {
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
	 * @param string                          $hook          Hook name.
	 * @param string                          $status        Action status.
	 * @return int Non-negative delta.
	 */
	private static function delta( array $before_counts, array $after_counts, string $hook, string $status ): int {
		return max( 0, ( $after_counts[ $hook ][ $status ] ?? 0 ) - ( $before_counts[ $hook ][ $status ] ?? 0 ) );
	}

	/**
	 * Count due pending Data Machine actions.
	 *
	 * @return int Due pending count.
	 */
	private static function getDuePendingCount( ?array $hooks = null, array $job_ids = array() ): int {
		return self::countActions( true, $hooks, $job_ids );
	}

	/**
	 * Count all pending Data Machine actions.
	 *
	 * @return int Pending count.
	 */
	private static function getPendingCount( ?array $hooks = null, array $job_ids = array() ): int {
		return self::countActions( false, $hooks, $job_ids );
	}

	/**
	 * Count pending Data Machine actions.
	 *
	 * @param bool $due_only Whether to count only due actions.
	 * @return int Pending count.
	 */
	private static function countActions( bool $due_only, ?array $hooks = null, array $job_ids = array() ): int {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';
		$hook_sql      = self::hookWhereSql( $hooks, $job_ids );

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
	 * Get action counts grouped by hook and status.
	 *
	 * @return array<string,array<string,int>> Counts by hook and status.
	 */
	private static function getStatusCounts( ?array $hooks = null, array $job_ids = array() ): array {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';
		$hook_sql      = self::hookWhereSql( $hooks, $job_ids );
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
}
