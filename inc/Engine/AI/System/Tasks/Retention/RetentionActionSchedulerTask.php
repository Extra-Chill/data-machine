<?php

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\Tasks\TaskScheduler;

class RetentionActionSchedulerTask extends RetentionTask {

	public function getTaskType(): string {
		return RetentionCleanup::TASK_AS_ACTIONS;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Retention: Action Scheduler actions',
			'description'     => 'Deletes old completed, failed, and canceled Action Scheduler actions and logs.',
			'setting_key'     => 'retention_as_actions_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	protected function runRetentionCleanup(): array {
		$result = RetentionCleanup::cleanupActionSchedulerActions();

		$this->maybeScheduleCatchUp( $result );

		return $result;
	}

	/**
	 * Enqueue an immediate catch-up pass when a single run could not drain the
	 * backlog.
	 *
	 * A single retention pass is bounded by iteration + wall-clock budgets, so
	 * on installs where a producer floods Action Scheduler faster than one
	 * daily 60s pass can prune (the blog-7 30GB scenario), the daily cadence
	 * never catches up and the tables grow without bound. When a pass both
	 * made progress (deleted rows) AND hit its budget cap, we enqueue an async
	 * follow-up so the backlog drains over minutes instead of weeks.
	 *
	 * Guards against a hot loop: we only reschedule when the pass actually
	 * deleted rows. A pass that deleted nothing but still reports `hit_limit`
	 * (e.g. deletes blocked) does not trigger a follow-up — the next regular
	 * daily tick will retry.
	 *
	 * @param array $result Result from RetentionCleanup::cleanupActionSchedulerActions().
	 */
	private function maybeScheduleCatchUp( array $result ): void {
		$hit_limit = ! empty( $result['hit_limit'] );
		$deleted   = (int) ( $result['deleted'] ?? 0 );

		/**
		 * Filter whether an oversized table alone (no budget cap hit) should
		 * trigger a catch-up pass. Default false — only a budget-capped pass
		 * that made progress reschedules.
		 *
		 * @param bool  $on     Whether to chase a breached guardrail.
		 * @param array $result Cleanup result.
		 */
		$chase_breach = (bool) apply_filters( 'datamachine_as_retention_chase_breach', false, $result );
		$breached     = ! empty( $result['table_sizes']['breached'] );

		$should_catch_up = ( $hit_limit && $deleted > 0 ) || ( $chase_breach && $breached && $deleted > 0 );

		if ( ! $should_catch_up ) {
			return;
		}

		if ( ! class_exists( TaskScheduler::class ) ) {
			return;
		}

		$scheduled = TaskScheduler::schedule( RetentionCleanup::TASK_AS_ACTIONS, array() );

		do_action(
			'datamachine_log',
			'info',
			'Action Scheduler retention: enqueued catch-up pass (backlog not drained in one run)',
			array(
				'deleted'   => $deleted,
				'hit_limit' => $hit_limit,
				'breached'  => $breached,
				'scheduled' => false !== $scheduled,
			)
		);
	}
}
