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
	 * @return array{success:bool,action_id:int,state:string,generation:int,retryable?:bool,error?:string}
	 */
	public function enqueue( int $job_id, string $flow_step_id, ?int $timestamp = null ): array {
		if ( $job_id <= 0 || '' === $flow_step_id ) {
			return $this->failure( 'invalid_enqueue_target' );
		}

		$job        = $this->jobs->get_job( $job_id );
		$generation = max( 0, (int) ( $job['operation_generation'] ?? 0 ) );
		$token      = (string) ( $job['operation_claim_token'] ?? '' );
		$args       = $this->actionArgs( $job_id, $flow_step_id, $generation, $token );
		$scheduled_action_id = in_array( (string) ( $job['operation_state'] ?? '' ), array( 'enqueued', 'enqueuing' ), true )
			? $this->scheduledActionId( $args )
			: 0;
		if ( $scheduled_action_id > 0 ) {
			return $this->success( $scheduled_action_id, $generation );
		}

		if ( 'enqueued' === ( $job['operation_state'] ?? '' ) && 'pending' === ( $job['status'] ?? '' ) ) {
			$this->jobs->reclaim_missing_operation_action( $job_id );
		}

		$claim = $this->jobs->claim_operation_enqueue( $job_id );
		if ( false === $claim ) {
			$job                 = $this->jobs->get_job( $job_id );
			$current_generation  = max( 0, (int) ( $job['operation_generation'] ?? 0 ) );
			$current_token       = (string) ( $job['operation_claim_token'] ?? '' );
			$current_action_args = $this->actionArgs( $job_id, $flow_step_id, $current_generation, $current_token );
			$current_action_id   = $this->scheduledActionId( $current_action_args );
			if ( $current_action_id > 0 ) {
				return $this->success( $current_action_id, $current_generation );
			}

			return $this->inProgress( $current_generation );
		}

		$token      = $claim['token'];
		$generation = $claim['generation'];
		$args       = $this->actionArgs( $job_id, $flow_step_id, $generation, $token );

		// A previous submitter may have crashed after scheduling but before it
		// recorded success. Reconcile that action before creating another one.
		$scheduled_action_id = $this->scheduledActionId( $args );
		if ( $scheduled_action_id > 0 ) {
			if ( ! $this->jobs->finish_operation_enqueue( $job_id, 'enqueued', $scheduled_action_id, $token, $generation ) ) {
				return $this->failure( 'enqueue_claim_fenced', $generation, true, 'enqueuing' );
			}
			return $this->success( $scheduled_action_id, $generation );
		}

		if ( ! $this->jobs->owns_operation_enqueue_claim( $job_id, $token, $generation ) ) {
			return $this->failure( 'enqueue_claim_fenced', $generation, true, 'enqueuing' );
		}

		$run_at    = null !== $timestamp && $timestamp > time() ? $timestamp : time();
		$action_id = ( $this->scheduler )( $run_at, self::HOOK, $args, self::GROUP );
		if ( ! is_int( $action_id ) || $action_id <= 0 ) {
			$this->jobs->finish_operation_enqueue( $job_id, 'enqueue_failed', 0, $token, $generation );
			return $this->failure( 'action_schedule_failed', $generation, true );
		}

		if ( ! $this->jobs->finish_operation_enqueue( $job_id, 'enqueued', $action_id, $token, $generation ) ) {
			return $this->failure( 'enqueue_claim_fenced', $generation, true, 'enqueuing' );
		}

		return $this->success( $action_id, $generation );
	}

	public function hasLiveAction( int $job_id, string $flow_step_id, int $generation, string $token ): bool {
		return $this->scheduledActionId( $this->actionArgs( $job_id, $flow_step_id, $generation, $token ) ) > 0;
	}

	private function actionArgs( int $job_id, string $flow_step_id, int $generation, string $token ): array {
		return array(
			'job_id'               => $job_id,
			'flow_step_id'         => $flow_step_id,
			'operation_generation' => $generation,
			'operation_claim_token' => $token,
		);
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

	private function success( int $action_id, int $generation ): array {
		return array(
			'success'    => true,
			'action_id'  => $action_id,
			'state'      => 'enqueued',
			'generation' => $generation,
		);
	}

	private function inProgress( int $generation ): array {
		return array(
			'success'    => false,
			'action_id'  => 0,
			'state'      => 'enqueuing',
			'generation' => $generation,
			'retryable'  => true,
			'error'      => 'enqueue_in_progress',
		);
	}

	private function failure( string $error, int $generation = 0, bool $retryable = false, string $state = 'enqueue_failed' ): array {
		$result = array(
			'success'    => false,
			'action_id'  => 0,
			'state'      => $state,
			'generation' => $generation,
			'error'      => $error,
		);
		if ( $retryable ) {
			$result['retryable'] = true;
		}

		return $result;
	}
}
