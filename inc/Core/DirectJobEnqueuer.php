<?php
/**
 * Durable enqueue coordinator for direct workflow jobs.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

use DataMachine\Core\Database\Jobs\Jobs;

defined( 'ABSPATH' ) || exit;

class DirectJobEnqueuer {

	private const HOOK  = 'datamachine_execute_step';
	private const GROUP = 'data-machine';

	private Jobs $jobs;
	private \Closure $scheduler;
	private \Closure $action_finder;

	public function __construct( ?Jobs $jobs = null, ?callable $scheduler = null, ?callable $action_finder = null ) {
		$this->jobs          = $jobs ?? new Jobs();
		$this->scheduler     = null !== $scheduler
			? \Closure::fromCallable( $scheduler )
			: static fn( int $run_at, string $hook, array $args, string $group ) => as_schedule_single_action( $run_at, $hook, $args, $group );
		$this->action_finder = null !== $action_finder
			? \Closure::fromCallable( $action_finder )
			: fn( array $args ): int => $this->findScheduledActionId( $args );
	}

	/**
	 * Durably enqueue one direct workflow step with crash recovery.
	 *
	 * @param int      $job_id       Job ID.
	 * @param string   $flow_step_id Flow step ID.
	 * @param int|null $timestamp    Optional future Unix timestamp.
	 * @return array{success:bool,action_id:int,state:string,error?:string}
	 */
	public function enqueue( int $job_id, string $flow_step_id, ?int $timestamp = null ): array {
		if ( $job_id <= 0 || '' === $flow_step_id ) {
			return $this->failure( 'invalid_enqueue_target' );
		}

		$args = array(
			'job_id'       => $job_id,
			'flow_step_id' => $flow_step_id,
		);
		$scheduled_action_id = $this->scheduledActionId( $args );
		if ( $scheduled_action_id > 0 ) {
			$this->jobs->finish_operation_enqueue( $job_id, 'enqueued', $scheduled_action_id );
			return $this->success( $scheduled_action_id );
		}

		$job = $this->jobs->get_job( $job_id );
		if ( 'enqueued' === ( $job['operation_state'] ?? '' ) && 'pending' === ( $job['status'] ?? '' ) ) {
			$this->jobs->reclaim_missing_operation_action( $job_id );
		}

		if ( ! $this->jobs->claim_operation_enqueue( $job_id ) ) {
			$job = $this->jobs->get_job( $job_id );
			if ( 'enqueued' === ( $job['operation_state'] ?? '' ) ) {
				return $this->success( (int) ( $job['operation_action_id'] ?? 0 ) );
			}

			return array(
				'success'   => true,
				'action_id' => 0,
				'state'     => 'enqueuing',
			);
		}

		// A previous submitter may have crashed after scheduling but before it
		// recorded success. Reconcile that action before creating another one.
		$scheduled_action_id = $this->scheduledActionId( $args );
		if ( $scheduled_action_id > 0 ) {
			$this->jobs->finish_operation_enqueue( $job_id, 'enqueued', $scheduled_action_id );
			return $this->success( $scheduled_action_id );
		}

		$run_at    = null !== $timestamp && $timestamp > time() ? $timestamp : time();
		$action_id = ( $this->scheduler )( $run_at, self::HOOK, $args, self::GROUP );
		if ( ! is_int( $action_id ) || $action_id <= 0 ) {
			$this->jobs->finish_operation_enqueue( $job_id, 'enqueue_failed' );
			return $this->failure( 'action_schedule_failed' );
		}

		if ( ! $this->jobs->finish_operation_enqueue( $job_id, 'enqueued', $action_id ) ) {
			return $this->failure( 'enqueue_state_persist_failed' );
		}

		return $this->success( $action_id );
	}

	/**
	 * Find an existing pending/in-progress action for this exact job step.
	 *
	 * @param array $args Action arguments.
	 * @return int Action ID, or 0 when none exists.
	 */
	private function scheduledActionId( array $args ): int {
		return max( 0, (int) ( $this->action_finder )( $args ) );
	}

	/**
	 * Query Action Scheduler for an exact pending/in-progress action.
	 *
	 * @param array $args Action arguments.
	 * @return int
	 */
	private function findScheduledActionId( array $args ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		foreach ( array( 'pending', 'in-progress' ) as $status ) {
			$action_ids = as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'args'     => $args,
					'status'   => $status,
					'per_page' => 1,
				),
				'ids'
			);
			if ( ! empty( $action_ids ) ) {
				return (int) reset( $action_ids );
			}
		}

		return 0;
	}

	private function success( int $action_id ): array {
		return array(
			'success'   => true,
			'action_id' => $action_id,
			'state'     => 'enqueued',
		);
	}

	private function failure( string $error ): array {
		return array(
			'success'   => false,
			'action_id' => 0,
			'state'     => 'enqueue_failed',
			'error'     => $error,
		);
	}
}
