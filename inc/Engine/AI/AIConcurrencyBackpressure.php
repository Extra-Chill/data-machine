<?php
/**
 * Pure policy for pipeline AI concurrency backpressure.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use DataMachine\Core\EngineData;

defined( 'ABSPATH' ) || exit;

class AIConcurrencyBackpressure {
	public const RESUME_HOOK = 'datamachine_resume_ai_step';

	private const RESUME_GROUP_PREFIX = 'data-machine-ai-resume-';
	private const OWNERSHIP_KEY       = 'ai_concurrency_resume_ownership';
	private const SCHEDULE_ATTEMPTS   = 3;

	/**
	 * Resolve the next durable contention state.
	 *
	 * @param array  $existing     Existing throttle state.
	 * @param string $flow_step_id Current flow step ID.
	 * @param int    $now          Current Unix timestamp.
	 * @param int    $max_age      Maximum contention age in seconds.
	 * @return array<string,mixed>
	 */
	public static function nextState( array $existing, string $flow_step_id, int $now, int $max_age ): array {
		$same_step = (string) ( $existing['flow_step_id'] ?? '' ) === $flow_step_id;
		$attempts  = $same_step ? max( 0, (int) ( $existing['attempts'] ?? 0 ) ) + 1 : 1;

		$first_deferred_at = $same_step ? strtotime( (string) ( $existing['first_deferred_at'] ?? '' ) ) : false;
		if ( false === $first_deferred_at || $first_deferred_at > $now ) {
			$first_deferred_at = $now;
		}

		$age_seconds = max( 0, $now - $first_deferred_at );

		return array(
			'state'                 => $age_seconds >= $max_age ? 'stranded' : 'deferred',
			'flow_step_id'          => $flow_step_id,
			'attempts'              => $attempts,
			'first_deferred_at'     => gmdate( 'c', $first_deferred_at ),
			'last_deferred_at'      => gmdate( 'c', $now ),
			'defer_age_seconds'     => $age_seconds,
			'max_defer_age_seconds' => $max_age,
		);
	}

	/**
	 * Calculate capped exponential contention backoff.
	 */
	public static function delaySeconds( int $base_delay, int $prior_attempts, int $max_delay ): int {
		return min(
			max( 1, $max_delay ),
			max( 1, $base_delay ) * ( 2 ** min( max( 0, $prior_attempts ), 16 ) )
		);
	}

	/**
	 * Schedule one unique continuation, resolving a zero return only when the
	 * scheduler can prove that an equivalent pending action won the race.
	 *
	 * @return array{success:bool,action_id:int,action_status:string,reused:bool,attempts:int,retryable:bool,error_message:string}
	 */
	public static function scheduleContinuation( int $timestamp, array $args ): array {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return self::scheduleFailure( 0, 'Action Scheduler is unavailable.' );
		}

		$group         = self::continuationGroup( $args );
		$error_message = '';
		for ( $attempt = 1; $attempt <= self::SCHEDULE_ATTEMPTS; ++$attempt ) {
			$scheduled = as_schedule_single_action(
				$timestamp,
				self::RESUME_HOOK,
				$args,
				$group,
				true
			);
			$action_id = (int) $scheduled;
			if ( $action_id > 0 ) {
				return array(
					'success'       => true,
					'action_id'     => $action_id,
					'action_status' => 'pending',
					'reused'        => false,
					'attempts'      => $attempt,
					'retryable'     => false,
					'error_message' => '',
				);
			}

			global $wpdb;
			if ( isset( $wpdb ) && is_string( $wpdb->last_error ?? null ) && '' !== $wpdb->last_error ) {
				$error_message = $wpdb->last_error;
			}

			$active = self::activeContinuation( $args, $group );
			if ( $active['action_id'] > 0 ) {
				return array(
					'success'       => true,
					'action_id'     => $active['action_id'],
					'action_status' => $active['status'],
					'reused'        => true,
					'attempts'      => $attempt,
					'retryable'     => false,
					'error_message' => $error_message,
				);
			}

			if ( $attempt < self::SCHEDULE_ATTEMPTS ) {
				usleep( random_int( 10000, 50000 ) );
			}
		}

		return self::scheduleFailure(
			self::SCHEDULE_ATTEMPTS,
			'' !== $error_message ? $error_message : 'Action Scheduler returned no action ID.'
		);
	}

	/** Resolve the Action Scheduler uniqueness scope for one job/step. */
	public static function continuationGroup( array $args ): string {
		$job_id       = max( 0, (int) ( $args['job_id'] ?? 0 ) );
		$flow_step_id = (string) ( $args['flow_step_id'] ?? '' );
		$generation   = max( 0, (int) ( $args['ai_resume_generation'] ?? 0 ) );
		$scope        = $job_id . ':' . $flow_step_id . ':' . $generation;

		return self::RESUME_GROUP_PREFIX . $job_id . '-' . $generation . '-' . substr( hash( 'sha256', $scope ), 0, 16 );
	}

	/**
	 * Atomically claim or reuse the next resume generation.
	 *
	 * @return array{success:bool,owned:bool,generation:int,action_id:int,token:string}
	 */
	public static function claimNextGeneration( int $job_id, string $flow_step_id, int $source_generation, int $now ): array {
		$token  = bin2hex( random_bytes( 16 ) );
		$result = EngineData::mutate(
			$job_id,
			static function ( array $engine ) use ( $flow_step_id, $source_generation, $token, $now ): ?array {
				$current = is_array( $engine[ self::OWNERSHIP_KEY ] ?? null ) ? $engine[ self::OWNERSHIP_KEY ] : array();
				$next    = self::nextGenerationState( $current, $flow_step_id, $source_generation, $token, $now );
				if ( null === $next ) {
					return null;
				}

				$engine[ self::OWNERSHIP_KEY ] = $next;
				return $engine;
			},
			'ai_resume_generation_claim'
		);

		$state      = is_array( $result['snapshot'][ self::OWNERSHIP_KEY ] ?? null ) ? $result['snapshot'][ self::OWNERSHIP_KEY ] : array();
		$generation = max( 0, (int) ( $state['generation'] ?? 0 ) );
		$valid      = ! empty( $result['success'] )
			&& (string) ( $state['flow_step_id'] ?? '' ) === $flow_step_id
			&& (int) ( $state['source_generation'] ?? -1 ) === $source_generation
			&& 'scheduled' === (string) ( $state['status'] ?? '' );

		return array(
			'success'    => $valid,
			'owned'      => $valid && (string) ( $state['token'] ?? '' ) === $token,
			'generation' => $generation,
			'action_id'  => max( 0, (int) ( $state['action_id'] ?? 0 ) ),
			'token'      => $token,
		);
	}

	/**
	 * Resolve the next ownership projection without persistence.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function nextGenerationState( array $state, string $flow_step_id, int $source_generation, string $token, int $now ): ?array {
		$source_generation = max( 0, $source_generation );
		if ( empty( $state ) || (string) ( $state['flow_step_id'] ?? '' ) !== $flow_step_id ) {
			if ( 0 !== $source_generation ) {
				return null;
			}
			return self::newGenerationState( $flow_step_id, 0, $token, $now );
		}

		$current_generation = max( 0, (int) ( $state['generation'] ?? 0 ) );
		if ( $source_generation < $current_generation ) {
			$is_existing_next = (int) ( $state['source_generation'] ?? -1 ) === $source_generation
				&& 'scheduled' === (string) ( $state['status'] ?? '' );
			return $is_existing_next ? $state : null;
		}
		if ( 0 !== $current_generation - $source_generation || 'running' !== (string) ( $state['status'] ?? '' ) ) {
			return null;
		}

		return self::newGenerationState( $flow_step_id, $source_generation, $token, $now );
	}

	/** Mark an exact scheduled generation as running before executing it. */
	public static function beginGeneration( int $job_id, string $flow_step_id, int $generation, int $now ): bool {
		$result = EngineData::mutate(
			$job_id,
			static function ( array $engine ) use ( $flow_step_id, $generation, $now ): ?array {
				$current = is_array( $engine[ self::OWNERSHIP_KEY ] ?? null ) ? $engine[ self::OWNERSHIP_KEY ] : array();
				$next    = self::beginGenerationState( $current, $flow_step_id, $generation, $now );
				if ( null === $next ) {
					return null;
				}

				$engine[ self::OWNERSHIP_KEY ] = $next;
				return $engine;
			},
			'ai_resume_generation_begin'
		);

		return ! empty( $result['success'] );
	}

	/** @return array<string,mixed>|null */
	public static function beginGenerationState( array $state, string $flow_step_id, int $generation, int $now ): ?array {
		if ( 0 >= $generation
			|| (string) ( $state['flow_step_id'] ?? '' ) !== $flow_step_id
			|| (int) ( $state['generation'] ?? 0 ) !== $generation
			|| 'scheduled' !== (string) ( $state['status'] ?? '' )
		) {
			return null;
		}

		$state['status']     = 'running';
		$state['started_at'] = gmdate( 'c', $now );
		return $state;
	}

	/** Persist the Action Scheduler ID for the generation owned by this token. */
	public static function recordScheduledAction( int $job_id, string $flow_step_id, int $generation, string $token, int $action_id ): bool {
		$result = EngineData::mutate(
			$job_id,
			static function ( array $engine ) use ( $flow_step_id, $generation, $token, $action_id ): ?array {
				$state = is_array( $engine[ self::OWNERSHIP_KEY ] ?? null ) ? $engine[ self::OWNERSHIP_KEY ] : array();
				if ( (string) ( $state['flow_step_id'] ?? '' ) !== $flow_step_id
					|| (int) ( $state['generation'] ?? 0 ) !== $generation
					|| (string) ( $state['token'] ?? '' ) !== $token
					|| 'scheduled' !== (string) ( $state['status'] ?? '' )
				) {
					return null;
				}

				$state['action_id']            = $action_id;
				$engine[ self::OWNERSHIP_KEY ] = $state;
				return $engine;
			},
			'ai_resume_action_recorded'
		);

		return ! empty( $result['success'] );
	}

	/** Repoint the recorded Action Scheduler ID after a wake-up reschedule. */
	public static function repointScheduledAction( int $job_id, string $flow_step_id, int $generation, int $previous_action_id, int $next_action_id ): bool {
		if ( $previous_action_id <= 0 || $next_action_id <= 0 || $previous_action_id === $next_action_id ) {
			return false;
		}

		$result = EngineData::mutate(
			$job_id,
			static function ( array $engine ) use ( $flow_step_id, $generation, $previous_action_id, $next_action_id ): ?array {
				$state = is_array( $engine[ self::OWNERSHIP_KEY ] ?? null ) ? $engine[ self::OWNERSHIP_KEY ] : array();
				if ( (string) ( $state['flow_step_id'] ?? '' ) !== $flow_step_id
					|| (int) ( $state['generation'] ?? 0 ) !== $generation
					|| (int) ( $state['action_id'] ?? 0 ) !== $previous_action_id
					|| 'scheduled' !== (string) ( $state['status'] ?? '' )
				) {
					return null;
				}

				$state['action_id']            = $next_action_id;
				$engine[ self::OWNERSHIP_KEY ] = $state;
				return $engine;
			},
			'ai_resume_action_repointed'
		);

		return ! empty( $result['success'] );
	}

	/**
	 * Pull the earliest future-scheduled deferred continuation forward to now.
	 *
	 * Called on the release side of an AI concurrency lease: a slot just freed
	 * while waiters are still sleeping on exponential backoff. Best-effort —
	 * every failure mode resolves to a report array, never an exception, so a
	 * failed wake-up cannot break the releasing job.
	 *
	 * The wake replaces the waiter's pending action (schedule first, then
	 * cancel the predecessor, then repoint ownership) instead of mutating the
	 * existing action's scheduled date, because the Action Scheduler store has
	 * no scheduled-date update API. Uniqueness stays consistent: the
	 * replacement reuses the exact same args and continuation group, ownership
	 * is fenced by the previous action ID, and even a transient two-live-actions
	 * window is execution-fenced by beginGeneration().
	 *
	 * @return array{woke:bool,previous_action_id:int,action_id:int,job_id:int,flow_step_id:string,generation:int,reason:string}
	 */
	public static function wakeEarliestDeferred( int $now = 0 ): array {
		$now    = $now > 0 ? $now : time();
		$result = array(
			'woke'               => false,
			'previous_action_id' => 0,
			'action_id'          => 0,
			'job_id'             => 0,
			'flow_step_id'       => '',
			'generation'         => 0,
			'reason'             => '',
		);

		if ( ! self::isSchedulerReady() ) {
			$result['reason'] = 'scheduler_unavailable';
			return $result;
		}

		try {
			$actions = as_get_scheduled_actions(
				array(
					'hook'         => self::RESUME_HOOK,
					'status'       => 'pending',
					'date'         => new \DateTime( '@' . $now ),
					'date_compare' => '>',
					'orderby'      => 'date',
					'order'        => 'ASC',
					'per_page'     => 1,
				),
				'OBJECT'
			);
			$action  = reset( $actions );
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_id' ) || ! method_exists( $action, 'get_args' ) ) {
				$result['reason'] = 'no_deferred_waiter';
				return $result;
			}

			$args         = (array) $action->get_args();
			$job_id       = max( 0, (int) ( $args['job_id'] ?? 0 ) );
			$flow_step_id = (string) ( $args['flow_step_id'] ?? '' );
			$generation   = max( 0, (int) ( $args['ai_resume_generation'] ?? 0 ) );
			if ( $job_id <= 0 || '' === $flow_step_id || $generation <= 0 ) {
				$result['reason'] = 'unrecognized_waiter_args';
				return $result;
			}

			$previous_action_id           = (int) $action->get_id();
			$result['previous_action_id'] = $previous_action_id;
			$result['job_id']             = $job_id;
			$result['flow_step_id']       = $flow_step_id;
			$result['generation']         = $generation;

			// Schedule the replacement first (unique=false: the still-pending
			// predecessor would block a unique schedule), then cancel the
			// predecessor so the waiter never loses its continuation.
			$next_action_id = (int) as_schedule_single_action(
				$now,
				self::RESUME_HOOK,
				$args,
				self::continuationGroup( $args ),
				false
			);
			if ( $next_action_id <= 0 ) {
				$result['reason'] = 'reschedule_failed';
				return $result;
			}

			\ActionScheduler_Store::instance()->cancel_action( (string) $previous_action_id );
			self::repointScheduledAction( $job_id, $flow_step_id, $generation, $previous_action_id, $next_action_id );

			$result['woke']      = true;
			$result['action_id'] = $next_action_id;

			do_action(
				'datamachine_log',
				'info',
				'AI concurrency lease release woke the earliest deferred AI step',
				array(
					'job_id'             => $job_id,
					'flow_step_id'       => $flow_step_id,
					'resume_generation'  => $generation,
					'previous_action_id' => $previous_action_id,
					'action_id'          => $next_action_id,
				)
			);

			return $result;
		} catch ( \Throwable $wake_error ) {
			$result['reason'] = 'wake_failed: ' . $wake_error->getMessage();
			return $result;
		}
	}

	/**
	 * Snapshot the deferred AI continuation queue for operator status.
	 *
	 * @param int $sample_limit Maximum actions sampled for generation stats.
	 * @return array{available:bool,pending:int,sampled:int,sample_capped:bool,generation_median:?float,generation_max:?int}
	 */
	public static function deferredSnapshot( int $sample_limit = 500 ): array {
		$empty = array(
			'available'         => false,
			'pending'           => 0,
			'sampled'           => 0,
			'sample_capped'     => false,
			'generation_median' => null,
			'generation_max'    => null,
		);

		if ( ! self::isSchedulerReady() ) {
			return $empty;
		}

		try {
			$query   = array(
				'hook'   => self::RESUME_HOOK,
				'status' => 'pending',
			);
			$pending = (int) \ActionScheduler_Store::instance()->query_actions( $query, 'count' );
			if ( $pending <= 0 ) {
				return array( 'available' => true ) + $empty;
			}

			$sample_limit = max( 1, $sample_limit );
			$actions      = as_get_scheduled_actions(
				$query + array(
					'orderby'  => 'date',
					'order'    => 'ASC',
					'per_page' => $sample_limit,
				),
				'OBJECT'
			);

			$generations = array();
			foreach ( $actions as $action ) {
				$args = is_object( $action ) && method_exists( $action, 'get_args' ) ? (array) $action->get_args() : array();
				if ( (int) ( $args['ai_resume_generation'] ?? 0 ) > 0 ) {
					$generations[] = (int) $args['ai_resume_generation'];
				}
			}

			$median = null;
			$max    = null;
			if ( ! empty( $generations ) ) {
				sort( $generations );
				$count  = count( $generations );
				$middle = intdiv( $count, 2 );
				$median = 1 === $count % 2 ? (float) $generations[ $middle ] : ( $generations[ $middle - 1 ] + $generations[ $middle ] ) / 2;
				$max    = (int) end( $generations );
			}

			return array(
				'available'         => true,
				'pending'           => $pending,
				'sampled'           => count( $generations ),
				'sample_capped'     => $pending > $sample_limit,
				'generation_median' => $median,
				'generation_max'    => $max,
			);
		} catch ( \Throwable ) {
			return $empty;
		}
	}

	/** Check whether Action Scheduler can safely answer scheduled-action queries. */
	private static function isSchedulerReady(): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		if ( function_exists( 'did_action' ) && 0 === did_action( 'action_scheduler_init' ) ) {
			return false;
		}

		if ( ! class_exists( '\ActionScheduler_Store' ) ) {
			return false;
		}

		if ( class_exists( '\ActionScheduler' ) && method_exists( '\ActionScheduler', 'is_initialized' ) && ! \ActionScheduler::is_initialized() ) {
			return false;
		}

		return true;
	}

	/** Release an exact generation that never acquired scheduler ownership. */
	public static function releaseUnscheduledGeneration( int $job_id, string $flow_step_id, int $generation, string $token ): bool {
		$result = EngineData::mutate(
			$job_id,
			static function ( array $engine ) use ( $flow_step_id, $generation, $token ): ?array {
				$state = is_array( $engine[ self::OWNERSHIP_KEY ] ?? null ) ? $engine[ self::OWNERSHIP_KEY ] : array();
				if ( ! self::isReleasableUnscheduledGeneration( $state, $flow_step_id, $generation, $token ) ) {
					return null;
				}

				unset( $engine[ self::OWNERSHIP_KEY ] );
				return $engine;
			},
			'ai_resume_generation_schedule_failed'
		);

		return ! empty( $result['success'] );
	}

	/** Determine whether the caller owns an exact actionless scheduled generation. */
	public static function isReleasableUnscheduledGeneration( array $state, string $flow_step_id, int $generation, string $token ): bool {
		return (string) ( $state['flow_step_id'] ?? '' ) === $flow_step_id
			&& (int) ( $state['generation'] ?? 0 ) === $generation
			&& (string) ( $state['token'] ?? '' ) === $token
			&& 'scheduled' === (string) ( $state['status'] ?? '' )
			&& 0 === (int) ( $state['action_id'] ?? 0 );
	}

	/** Resolve scheduler or runner ownership after an ambiguous release. */
	public static function generationExecutionState( int $job_id, string $flow_step_id, int $generation, string $token ): string {
		$engine = EngineData::retrieve( $job_id );
		$state  = is_array( $engine[ self::OWNERSHIP_KEY ] ?? null ) ? $engine[ self::OWNERSHIP_KEY ] : array();

		if ( (string) ( $state['flow_step_id'] ?? '' ) !== $flow_step_id
			|| (int) ( $state['generation'] ?? 0 ) !== $generation
			|| (string) ( $state['token'] ?? '' ) !== $token
		) {
			return '';
		}

		if ( 'running' === (string) ( $state['status'] ?? '' ) ) {
			return 'running';
		}

		return (int) ( $state['action_id'] ?? 0 ) > 0 ? 'scheduled' : '';
	}

	private static function newGenerationState( string $flow_step_id, int $source_generation, string $token, int $now ): array {
		return array(
			'flow_step_id'      => $flow_step_id,
			'generation'        => $source_generation + 1,
			'source_generation' => $source_generation,
			'status'            => 'scheduled',
			'token'             => $token,
			'action_id'         => 0,
			'claimed_at'        => gmdate( 'c', $now ),
		);
	}

	/**
	 * Convert active contention into bounded historical evidence.
	 *
	 * @return array<string,mixed>
	 */
	public static function resolvedState( array $throttle, int $now ): array {
		$first_deferred = strtotime( (string) ( $throttle['first_deferred_at'] ?? '' ) );
		$age_seconds    = false === $first_deferred ? max( 0, (int) ( $throttle['defer_age_seconds'] ?? 0 ) ) : max( 0, $now - $first_deferred );

		return array(
			'state'             => 'resolved',
			'reason'            => (string) ( $throttle['reason'] ?? 'ai_concurrency_limit' ),
			'provider'          => (string) ( $throttle['provider'] ?? '' ),
			'flow_step_id'      => (string) ( $throttle['flow_step_id'] ?? '' ),
			'defer_count'       => max( 0, (int) ( $throttle['attempts'] ?? 0 ) ),
			'defer_age_seconds' => $age_seconds,
			'first_deferred_at' => $throttle['first_deferred_at'] ?? null,
			'last_deferred_at'  => $throttle['last_deferred_at'] ?? null,
			'resolved_at'       => gmdate( 'c', $now ),
		);
	}

	/** @return array{action_id:int,status:string} */
	private static function activeContinuation( array $args, string $group ): array {
		foreach ( array( 'pending', 'in-progress' ) as $status ) {
			$action_ids = as_get_scheduled_actions(
				array(
					'hook'     => self::RESUME_HOOK,
					'args'     => $args,
					'group'    => $group,
					'status'   => $status,
					'per_page' => 1,
				),
				'ids'
			);
			$action_id  = (int) reset( $action_ids );
			if ( $action_id > 0 ) {
				return array(
					'action_id' => $action_id,
					'status'    => $status,
				);
			}
		}

		return array(
			'action_id' => 0,
			'status'    => '',
		);
	}

	/** @return array{success:bool,action_id:int,action_status:string,reused:bool,attempts:int,retryable:bool,error_message:string} */
	private static function scheduleFailure( int $attempts, string $error_message ): array {
		return array(
			'success'       => false,
			'action_id'     => 0,
			'action_status' => '',
			'reused'        => false,
			'attempts'      => $attempts,
			'retryable'     => true,
			'error_message' => $error_message,
		);
	}
}
