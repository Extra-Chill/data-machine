<?php
/**
 * A generic, scoped Action Scheduler drain — the foreground pump that completes a
 * fan-out on ANY runtime, whether or not WP-Cron ever fires.
 *
 * ## Why this exists
 *
 * The Action Scheduler branch executor ({@see WP_Agent_Workflow_Action_Scheduler_Branch_Executor})
 * dispatches each parallel branch as a claimed AS async action and then relies on
 * something EXTERNAL to pump the AS queue — WP-Cron, or a runtime cron heartbeat —
 * to actually claim and run those actions. That is fine on a runtime where WP-Cron
 * fires. It is NOT fine on a managed host that sets `DISABLE_WP_CRON` (e.g. WP
 * Cloud) or a core/CLI context with no cron heartbeat at all: the branches enqueue,
 * nothing claims them, and the run strands SUSPENDED forever even though the
 * branches are perfectly runnable. A manual `wp action-scheduler run` drains them —
 * proving the branches CAN run; they just need something to actively pump the queue.
 *
 * This service IS that pump, in-process. It synchronously drains a SCOPED set of AS
 * actions (by hook + group) until the scope is empty or a terminal condition is met,
 * with wall-clock / batch / memory budgets so it cannot run away. A foreground
 * orchestrator (a CLI, a `wp-cron.php` request, or an `await=true` foreground poll)
 * calls it to make its own fan-out complete without depending on WP-Cron.
 *
 * ## A generic, product-free primitive
 *
 * This is the generic core of a scoped-drain mechanism: a synchronous, in-process
 * pump over a caller-scoped set of Action Scheduler actions, with budget / memory /
 * timeout discipline and a terminal-status callback. It carries NO product- or
 * domain-specific coupling — no fixed group or hook constants, no job-id or
 * worker-lane scoping, no per-action config lookups, no product-prefixed filter
 * names. Scope is a caller-passed hook list + group (defaulting to the branch
 * executor's own hooks + group), tuning is via `agents_`-prefixed filters, and the
 * terminal condition is a caller-supplied callback. Every caller of the branch
 * executor can share this one mechanism; a caller that needs richer scoping layers
 * it on top rather than pushing it down here.
 *
 * ## CRITICAL — foreground / orchestrator context ONLY. Never from a branch worker.
 *
 * This MUST only be called from a FOREGROUND / orchestrator context:
 *
 *   - a CLI command awaiting the run,
 *   - a `wp-cron.php` request,
 *   - the `await=true` foreground poll of a suspended run.
 *
 * It MUST NEVER be called from inside a branch worker draining its own siblings. A
 * branch action runs while its worker HOLDS an Action Scheduler claim; if that
 * worker then tried to drain the sibling branches, it would hold a claim while
 * pumping the queue that the siblings themselves need claims from — re-introducing
 * the exact self-deadlock that dispatch-and-return was built to avoid (the parent
 * blocks on children it is itself starving). {@see drain()} DETECTS this: if it is
 * invoked while WordPress is already firing one of the scoped hooks (i.e. this
 * request is itself a claimed branch/resume action, observable via `doing_action()`),
 * it refuses and returns a `refused_reentrant` result rather than deadlocking. A
 * process-level re-entrancy guard additionally prevents a nested drain within one
 * request.
 *
 * @package AgentsAPI
 * @since   0.5.2
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

/**
 * Drain a caller-scoped set of Action Scheduler actions in-process, with budgets.
 */
final class WP_Agent_Workflow_Scoped_Drain {

	/**
	 * Process-level re-entrancy guard. A drain that is already running in this
	 * request must not be re-entered (e.g. a branch action that itself somehow
	 * reaches back into a drain). Paired with the `doing_action()` check in
	 * {@see self::guard_context()} this makes the self-deadlock unreachable.
	 *
	 * @since 0.5.2
	 * @var bool
	 */
	private static bool $draining = false;

	/**
	 * Whether Action Scheduler's in-process runner API is available. This — not the
	 * mere presence of the plugin — is the gate: the drain needs the store, the
	 * runner, and the query API all present to claim and run actions in-process.
	 *
	 * @since 0.5.2
	 */
	public static function is_available(): bool {
		return class_exists( '\ActionScheduler' )
			&& class_exists( '\ActionScheduler_Store' )
			&& function_exists( 'as_get_scheduled_actions' );
	}

	/**
	 * The default hook scope for a workflow fan-out drain: the executor's per-branch
	 * hook plus its resume hook, read from the executor constants (never hardcoded)
	 * so this class and the executor can never drift.
	 *
	 * @since 0.5.2
	 *
	 * @return array<int,string> Default scoped hooks.
	 */
	public static function default_hooks(): array {
		return array(
			WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK,
			WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK,
		);
	}

	/**
	 * The default AS group for a workflow fan-out drain, read from the executor
	 * (which shares its group with the cron bridge) so scope stays in one place.
	 *
	 * @since 0.5.2
	 */
	public static function default_group(): string {
		return WP_Agent_Workflow_Action_Scheduler_Branch_Executor::GROUP;
	}

	/**
	 * Synchronously drain the scoped Action Scheduler actions until the scope is
	 * empty, a budget is exhausted, or the terminal-status callback signals the run
	 * is done — whichever comes first.
	 *
	 * The loop, each iteration:
	 *   1. asks the terminal-status callback whether the run is already done (so a
	 *      completed run stops immediately, not after wasting a batch);
	 *   2. checks the memory soft limit and the wall-clock budget;
	 *   3. claims and runs ONE batch of due scoped actions in-process via
	 *      {@see self::run_batch()} — pure Action Scheduler mechanics
	 *      (`\ActionScheduler_Store::instance()->stake_claim()` +
	 *      `\ActionScheduler::runner()->process_action()`);
	 *   4. stops if a batch made no observable progress (guards against a stuck
	 *      scope spinning the loop).
	 *
	 * @since 0.5.2
	 *
	 * @param array{
	 *     hooks?:array<int,string>,
	 *     group?:string,
	 *     batch_size?:int,
	 *     limit?:int,
	 *     time_limit_ms?:int,
	 *     time_limit?:int,
	 *     stop_before_timeout_ms?:int,
	 *     stop_before_timeout?:int,
	 *     execution_context?:string,
	 *     terminal_status_callback?:callable
	 * } $options Drain options.
	 * @return array<string,int|string|bool> Drain stats.
	 */
	public function drain( array $options = array() ): array {
		$hooks             = $this->normalize_hooks( $options['hooks'] ?? null );
		$group             = isset( $options['group'] ) && '' !== (string) $options['group'] ? (string) $options['group'] : self::default_group();
		$batch_size        = max( 1, (int) ( $options['batch_size'] ?? 25 ) );
		$limit             = max( 0, (int) ( $options['limit'] ?? 0 ) );
		$time_limit_ms     = isset( $options['time_limit_ms'] )
			? max( 0, (int) $options['time_limit_ms'] )
			: max( 0, (int) ( $options['time_limit'] ?? 0 ) ) * 1000;
		$stop_before_ms    = isset( $options['stop_before_timeout_ms'] )
			? max( 0, (int) $options['stop_before_timeout_ms'] )
			: max( 0, (int) ( $options['stop_before_timeout'] ?? 0 ) ) * 1000;
		$execution_context = (string) ( $options['execution_context'] ?? 'agents-api scoped drain' );
		$terminal_callback = is_callable( $options['terminal_status_callback'] ?? null ) ? $options['terminal_status_callback'] : null;

		// SAFETY GATE 1: Action Scheduler's in-process runner must be present. When it
		// is not, this is a clean no-op — the caller's run drains through whatever
		// external pump the runtime does provide (or stays suspended until its budget),
		// exactly as before this class existed. Never fabricate progress.
		if ( ! self::is_available() ) {
			return $this->empty_stats( 'as_unavailable', $hooks, $group );
		}

		// SAFETY GATE 2: refuse to run from a branch-worker / re-entrant context. This
		// is the self-deadlock guard (see the class docblock): draining the scope from
		// inside a claimed action of that same scope would hold a claim while pumping
		// the queue its siblings need claims from. `doing_action()` on any scoped hook
		// means this very request is a claimed scoped action; the static flag catches a
		// nested drain within one request. Either way, refuse rather than deadlock.
		$refusal = $this->guard_context( $hooks );
		if ( '' !== $refusal ) {
			return $this->empty_stats( $refusal, $hooks, $group );
		}

		self::$draining = true;
		self::ensure_memory_limit();

		$started_at    = microtime( true );
		$before_counts = $this->status_counts( $hooks, $group );
		$batches       = 0;
		$processed     = 0;
		$warnings      = 0;
		$stop_reason   = 'empty';
		$terminal      = '';

		try {
			while ( $this->due_pending_count( $hooks, $group ) > 0 ) {
				// Stop the instant the run is done — do not waste a batch after
				// completion. The callback returns a non-empty terminal state (e.g.
				// 'succeeded' / 'failed') when the run is no longer suspended.
				if ( null !== $terminal_callback ) {
					$terminal = $this->scalar_string( $terminal_callback() );
					if ( '' !== $terminal ) {
						$stop_reason = 'terminal_status';
						break;
					}
				}

				if ( $this->memory_soft_limit_reached() ) {
					$stop_reason = 'memory_limit';
					break;
				}

				$elapsed_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );
				if ( $time_limit_ms > 0 && $elapsed_ms >= $time_limit_ms ) {
					$stop_reason = 'time_limit';
					break;
				}
				if ( $time_limit_ms > 0 && ( $time_limit_ms - $elapsed_ms ) <= $stop_before_ms ) {
					$stop_reason = 'timeout_margin';
					break;
				}

				$current_batch = $batch_size;
				if ( $limit > 0 ) {
					$current_batch = min( $batch_size, $limit - $processed );
					if ( $current_batch <= 0 ) {
						$stop_reason = 'limit';
						break;
					}
				}

				$deadline_at = 0.0;
				if ( $time_limit_ms > 0 ) {
					$deadline_at = $started_at + max( 0, $time_limit_ms - $stop_before_ms ) / 1000;
				}

				$due_before = $this->due_pending_count( $hooks, $group );
				$batch      = $this->run_batch( $current_batch, $hooks, $group, $deadline_at, $execution_context );
				++$batches;
				$processed += (int) $batch['processed'];
				$warnings  += (int) $batch['warnings'];

				if ( '' !== (string) $batch['stop_reason'] ) {
					$stop_reason = (string) $batch['stop_reason'];
					break;
				}

				// No observable progress AND the due set did not shrink → the scope is
				// stuck (a claim we cannot make, a perpetually-rescheduled action). Stop
				// rather than spin the loop against it.
				if ( 0 === (int) $batch['processed'] && $this->due_pending_count( $hooks, $group ) >= $due_before ) {
					$stop_reason = 'no_progress';
					break;
				}
			}

			// A run that reached terminal exactly as the scope emptied still reports the
			// terminal stop reason (more informative than a bare 'empty').
			if ( null !== $terminal_callback && '' === $terminal ) {
				$terminal = $this->scalar_string( $terminal_callback() );
				if ( '' !== $terminal && 'empty' === $stop_reason ) {
					$stop_reason = 'terminal_status';
				}
			}
		} finally {
			self::$draining = false;
		}

		return $this->build_stats( $before_counts, $this->status_counts( $hooks, $group ), $hooks, $group, $batches, $processed, $warnings, $stop_reason, $terminal );
	}

	/**
	 * Drain this executor's branch/resume scope until a suspended workflow run leaves
	 * the suspended state, or the supplied drain budget is exhausted.
	 *
	 * This is the generic foreground-await contract layered over {@see drain()}: a
	 * caller that already has the run id and recorder can pump its OWN suspended
	 * Action Scheduler fan-out without knowing the branch/resume hook names or
	 * reimplementing the terminal-status callback. Product code still owns WHEN it is
	 * safe to call this (foreground/status/CLI context, never from a branch worker).
	 *
	 * @since 0.5.2
	 *
	 * @param string                         $run_id   Suspended workflow run id.
	 * @param WP_Agent_Workflow_Run_Recorder $recorder Recorder that can reload the run.
	 * @param array<string,mixed>            $options  Drain options forwarded to {@see drain()}.
	 * @return array{result:?WP_Agent_Workflow_Run_Result,stats:array<string,int|string|bool>}
	 */
	public function drain_suspended_run( string $run_id, WP_Agent_Workflow_Run_Recorder $recorder, array $options = array() ): array {
		$terminal_status_callback = static function () use ( $recorder, $run_id ): string {
			$current = $recorder->find( $run_id );
			if ( null === $current ) {
				return 'gone';
			}
			return $current->is_suspended() ? '' : $current->get_status();
		};

		$drain_options = array(
			'terminal_status_callback' => $terminal_status_callback,
		);

		if ( isset( $options['hooks'] ) && is_array( $options['hooks'] ) ) {
			$drain_options['hooks'] = array_values(
				array_filter(
					$options['hooks'],
					static function ( $hook ): bool {
						return is_string( $hook );
					}
				)
			);
		}
		if ( isset( $options['group'] ) && is_scalar( $options['group'] ) && '' !== (string) $options['group'] ) {
			$drain_options['group'] = (string) $options['group'];
		}
		foreach ( array( 'batch_size', 'limit', 'time_limit_ms', 'time_limit', 'stop_before_timeout_ms', 'stop_before_timeout' ) as $int_key ) {
			if ( isset( $options[ $int_key ] ) && is_numeric( $options[ $int_key ] ) ) {
				$drain_options[ $int_key ] = (int) $options[ $int_key ];
			}
		}
		if ( isset( $options['execution_context'] ) && is_scalar( $options['execution_context'] ) ) {
			$drain_options['execution_context'] = (string) $options['execution_context'];
		}

		$stats = $this->drain( $drain_options );

		return array(
			'result' => $recorder->find( $run_id ),
			'stats'  => $stats,
		);
	}

	/**
	 * Claim and run one batch of due scoped actions in-process — pure Action
	 * Scheduler mechanics. Resets stale timeouts first, stakes a claim over the
	 * scoped hooks + group, then runs each claimed action through AS's own runner.
	 *
	 * @since 0.5.2
	 *
	 * @param int               $batch_size        Maximum actions to run this batch.
	 * @param array<int,string> $hooks             Hook scope (null-equivalent handled by caller).
	 * @param string            $group             AS group.
	 * @param float             $deadline_at       Unix timestamp (with microseconds); 0 = no deadline.
	 * @param string            $execution_context AS execution context label.
	 * @return array{processed:int,warnings:int,stop_reason:string} Batch result.
	 */
	private function run_batch( int $batch_size, array $hooks, string $group, float $deadline_at, string $execution_context ): array {
		/** @var \ActionScheduler_Store $store */
		$store       = \ActionScheduler_Store::instance();
		$runner      = \ActionScheduler::runner();
		$processed   = 0;
		$warnings    = 0;
		$stop_reason = '';
		$claim       = null;

		try {
			$this->reset_stale_timeouts( $store );
			// stake_claim( $max, $before_date, $hooks, $group ). Claiming over the
			// scoped hooks + group means we only ever run THIS caller's scope — never
			// an unrelated AS workload sharing the queue. HybridStore can transiently
			// throw "group does not exist" while actions for that group are readable;
			// in that case retry hook-scoped only rather than failing the foreground pump.
			$claim = $this->stake_claim( $store, $batch_size, $hooks, $group );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return array(
				'processed'   => 0,
				'warnings'    => 1,
				'stop_reason' => 'warning',
			);
		}

		try {
			foreach ( $claim->get_actions() as $action_id ) {
				if ( $deadline_at > 0 && microtime( true ) >= $deadline_at ) {
					$stop_reason = 'timeout_margin';
					break;
				}
				if ( $this->memory_soft_limit_reached() ) {
					$stop_reason = 'memory_limit';
					break;
				}

				$action_id = (int) $action_id;
				if ( $action_id <= 0 ) {
					continue;
				}

				try {
					$runner->process_action( $action_id, $execution_context );
					++$processed;
				} catch ( \Throwable $throwable ) {
					unset( $throwable );
					++$warnings;
				} finally {
					$this->flush_runtime_cache();
				}

				if ( $processed >= $batch_size ) {
					break;
				}
			}
		} finally {
			$store->release_claim( $claim );
		}

		return array(
			'processed'   => $processed,
			'warnings'    => $warnings,
			'stop_reason' => $stop_reason,
		);
	}

	/**
	 * Stake a scoped claim, falling back to hook-only scope when HybridStore cannot
	 * resolve an otherwise-readable group.
	 *
	 * @since 0.5.2
	 *
	 * @param \ActionScheduler_Store $store Action Scheduler store.
	 * @param int               $batch_size Maximum actions to claim.
	 * @param array<int,string> $hooks      Hook scope.
	 * @param string            $group      Group scope.
	 * @return \ActionScheduler_ActionClaim Action Scheduler claim.
	 */
	private function stake_claim( \ActionScheduler_Store $store, int $batch_size, array $hooks, string $group ): \ActionScheduler_ActionClaim {
		try {
			return $store->stake_claim( $batch_size, null, $hooks, $group );
		} catch ( \InvalidArgumentException $error ) {
			if ( '' === $group || false === stripos( $error->getMessage(), 'group' ) ) {
				throw $error;
			}
			return $store->stake_claim( $batch_size, null, $hooks, '' );
		}
	}

	/**
	 * Refuse the drain when it is invoked from a claimed-action or re-entrant
	 * context. Returns a non-empty refusal reason string when the drain must NOT
	 * run, or '' when the foreground/orchestrator context is safe.
	 *
	 * @since 0.5.2
	 *
	 * @param array<int,string> $hooks Scoped hooks.
	 * @return string '' when safe, else the refusal stop_reason.
	 */
	private function guard_context( array $hooks ): string {
		if ( self::$draining ) {
			return 'refused_reentrant';
		}

		// If WordPress is currently firing one of the scoped hooks, THIS request is a
		// claimed branch/resume action of the very scope we would drain — the
		// self-deadlock case. Refuse. `doing_action()` is present in any WP context
		// and reads the live action stack, so this is the exact, generic signal.
		if ( function_exists( 'doing_action' ) ) {
			foreach ( $hooks as $hook ) {
				if ( doing_action( $hook ) ) {
					return 'refused_in_claimed_action';
				}
			}
		}

		return '';
	}

	/**
	 * Reset stale AS claims/running actions before staking a direct drain claim, so a
	 * previously-abandoned claim does not hide a due action from this drain.
	 *
	 * @since 0.5.2
	 *
	 * @param object $store Action Scheduler store.
	 */
	private function reset_stale_timeouts( object $store ): void {
		if ( ! class_exists( '\ActionScheduler_QueueCleaner' ) || ! ( $store instanceof \ActionScheduler_Store ) ) {
			return;
		}

		$time_limit_raw = apply_filters( 'action_scheduler_queue_runner_time_limit', 30 );
		$time_limit     = is_numeric( $time_limit_raw ) ? (int) $time_limit_raw : 30;
		$timeout        = max( 1, $time_limit ) * 10;
		$cleaner    = new \ActionScheduler_QueueCleaner( $store );
		$cleaner->reset_timeouts( $timeout );
		$cleaner->mark_failures( $timeout );
	}

	/**
	 * Count due pending actions in scope (scheduled_date <= now).
	 *
	 * @since 0.5.2
	 *
	 * @param array<int,string> $hooks Scoped hooks.
	 * @param string            $group AS group.
	 */
	private function due_pending_count( array $hooks, string $group ): int {
		return $this->count_actions( $hooks, $group, true );
	}

	/**
	 * Count pending actions in scope, optionally only those that are due now.
	 *
	 * Sums per-hook because `as_get_scheduled_actions` filters by a single hook; the
	 * scope is a small hook list, so this is a handful of bounded indexed queries.
	 *
	 * @since 0.5.2
	 *
	 * @param array<int,string> $hooks    Scoped hooks.
	 * @param string            $group    AS group.
	 * @param bool              $due_only Whether to count only due actions.
	 */
	private function count_actions( array $hooks, string $group, bool $due_only ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $hooks as $hook ) {
			$query = array(
				'hook'     => $hook,
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1000,
			);
			if ( $due_only ) {
				// Only actions whose scheduled time has arrived are claimable now.
				$query['date']         = as_get_datetime_object();
				$query['date_compare'] = '<=';
			}

			$ids    = as_get_scheduled_actions( $query, 'ids' );
			$total += is_array( $ids ) ? count( $ids ) : 0;
		}

		return $total;
	}

	/**
	 * Get action counts grouped by hook and status for the scope — used to build the
	 * before/after processed deltas the stats report.
	 *
	 * @since 0.5.2
	 *
	 * @param array<int,string> $hooks Scoped hooks.
	 * @param string            $group AS group.
	 * @return array<string,array<string,int>> Counts by hook and status.
	 */
	private function status_counts( array $hooks, string $group ): array {
		$counts = array();
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return $counts;
		}

		$statuses = array(
			'pending'  => \ActionScheduler_Store::STATUS_PENDING,
			'complete' => \ActionScheduler_Store::STATUS_COMPLETE,
			'failed'   => \ActionScheduler_Store::STATUS_FAILED,
		);

		foreach ( $hooks as $hook ) {
			$counts[ $hook ] = array(
				'pending'  => 0,
				'complete' => 0,
				'failed'   => 0,
			);
			foreach ( $statuses as $key => $as_status ) {
				$ids                     = as_get_scheduled_actions(
					array(
						'hook'     => $hook,
						'group'    => $group,
						'status'   => $as_status,
						'per_page' => 1000,
					),
					'ids'
				);
				$counts[ $hook ][ $key ] = is_array( $ids ) ? count( $ids ) : 0;
			}
		}

		return $counts;
	}

	/**
	 * Build operator-facing drain stats from before/after status snapshots.
	 *
	 * @since 0.5.2
	 *
	 * @param array<string,array<string,int>> $before        Counts before.
	 * @param array<string,array<string,int>> $after         Counts after.
	 * @param array<int,string>               $hooks         Scoped hooks.
	 * @param string                          $group         AS group.
	 * @param int                             $batches       Batches run.
	 * @param int                             $processed     Actions processed.
	 * @param int                             $warnings      Warnings.
	 * @param string                          $stop_reason   Why the loop stopped.
	 * @param string                          $terminal      Terminal state from the callback.
	 * @return array<string,int|string|bool> Stats.
	 */
	private function build_stats( array $before, array $after, array $hooks, string $group, int $batches, int $processed, int $warnings, string $stop_reason, string $terminal ): array {
		$completions = 0;
		$failures    = 0;
		foreach ( $hooks as $hook ) {
			$completions += max( 0, ( $after[ $hook ]['complete'] ?? 0 ) - ( $before[ $hook ]['complete'] ?? 0 ) );
			$failures    += max( 0, ( $after[ $hook ]['failed'] ?? 0 ) - ( $before[ $hook ]['failed'] ?? 0 ) );
		}

		return array(
			'batches'           => $batches,
			'actions_processed' => $processed,
			'completions'       => $completions,
			'failures'          => $failures,
			'remaining_pending' => $this->due_pending_count( $hooks, $group ),
			'total_pending'     => $this->count_actions( $hooks, $group, false ),
			'warnings'          => $warnings,
			'stop_reason'       => $stop_reason,
			'terminal_state'    => $terminal,
			'hooks'             => implode( ',', $hooks ),
			'group'             => $group,
			'available'         => true,
		);
	}

	/**
	 * A stats shape for a drain that never ran a batch (unavailable / refused).
	 *
	 * @since 0.5.2
	 *
	 * @param string            $stop_reason Why no batch ran.
	 * @param array<int,string> $hooks       Scoped hooks.
	 * @param string            $group       AS group.
	 * @return array<string,int|string|bool> Stats.
	 */
	private function empty_stats( string $stop_reason, array $hooks, string $group ): array {
		$available = self::is_available();
		return array(
			'batches'           => 0,
			'actions_processed' => 0,
			'completions'       => 0,
			'failures'          => 0,
			'remaining_pending' => $available ? $this->due_pending_count( $hooks, $group ) : 0,
			'total_pending'     => $available ? $this->count_actions( $hooks, $group, false ) : 0,
			'warnings'          => 0,
			'stop_reason'       => $stop_reason,
			'terminal_state'    => '',
			'hooks'             => implode( ',', $hooks ),
			'group'             => $group,
			'available'         => $available,
		);
	}

	/**
	 * Normalize the caller's hook scope, defaulting to the executor's branch + resume
	 * hooks. A caller may narrow or widen the scope, but never to an empty set.
	 *
	 * @since 0.5.2
	 *
	 * @param mixed $hooks Optional hook list.
	 * @return array<int,string> Non-empty hook scope.
	 */
	private function normalize_hooks( $hooks ): array {
		if ( ! is_array( $hooks ) ) {
			return self::default_hooks();
		}

		$normalized = array();
		foreach ( $hooks as $hook ) {
			$hook = is_string( $hook ) ? trim( $hook ) : '';
			if ( '' !== $hook ) {
				$normalized[] = $hook;
			}
		}
		$normalized = array_values( array_unique( $normalized ) );

		return empty( $normalized ) ? self::default_hooks() : $normalized;
	}

	/**
	 * Coerce an opaque callback return (the terminal-status callback returns mixed)
	 * to a string terminal state, treating non-scalars as "no terminal state yet".
	 *
	 * @since 0.5.2
	 *
	 * @param mixed $value Callback return.
	 */
	private function scalar_string( $value ): string {
		if ( is_scalar( $value ) || $value instanceof \Stringable ) {
			return (string) $value;
		}
		return '';
	}

	/**
	 * Flush in-request object cache state after each drained action so a long drain
	 * does not accumulate per-action cache growth.
	 *
	 * @since 0.5.2
	 */
	private function flush_runtime_cache(): void {
		if ( function_exists( 'wp_cache_flush_runtime' )
			&& ( ! function_exists( 'wp_cache_supports' ) || wp_cache_supports( 'flush_runtime' ) ) ) {
			wp_cache_flush_runtime();
		}
	}

	/**
	 * Whether PHP memory usage is near the hard limit. Filterable ratio via a
	 * generic `agents_`-prefixed filter so no product-specific filter name leaks
	 * into the substrate.
	 *
	 * @since 0.5.2
	 */
	private function memory_soft_limit_reached(): bool {
		$limit = self::memory_limit_bytes();
		if ( $limit <= 0 ) {
			return false;
		}

		/**
		 * The fraction of the PHP memory limit at which the scoped drain stops before
		 * running the next action, so a long drain does not OOM.
		 *
		 * @since 0.5.2
		 *
		 * @param float $ratio Soft-limit ratio (clamped to 0.50–0.95). Default 0.80.
		 */
		$ratio = (float) apply_filters( 'agents_workflow_scoped_drain_memory_soft_limit_ratio', 0.80 );
		$ratio = max( 0.50, min( 0.95, $ratio ) );

		return memory_get_usage( true ) >= (int) floor( $limit * $ratio );
	}

	/**
	 * Raise the runtime memory floor for a large drain, mirroring Action Scheduler's
	 * own runner which raises to the admin memory limit before a batch.
	 *
	 * @since 0.5.2
	 */
	private static function ensure_memory_limit(): void {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
	}

	/**
	 * Return PHP memory_limit in bytes, or 0 for unlimited/unknown.
	 *
	 * @since 0.5.2
	 */
	private static function memory_limit_bytes(): int {
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
}
