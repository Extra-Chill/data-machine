<?php
/**
 * Generic job retry/backoff policy.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Jobs\Jobs;

/**
 * Resolves retryability, backoff, throttling, and retry metadata for jobs.
 */
class JobRetryPolicy {

	/**
	 * Default maximum attempts for retryable failures.
	 */
	private const DEFAULT_MAX_ATTEMPTS = 3;

	/**
	 * Default base delay in seconds for exponential backoff.
	 */
	private const DEFAULT_BASE_DELAY = 60;

	/**
	 * Short base delay in seconds for cheap AI transport/connect retries.
	 */
	private const AI_TRANSPORT_BASE_DELAY = 15;

	/**
	 * Default maximum delay in seconds.
	 */
	private const DEFAULT_MAX_DELAY = 3600;

	/**
	 * Handle a failure before it is finalized.
	 *
	 * @param int    $job_id       Job ID.
	 * @param string $reason       Failure reason.
	 * @param array  $context_data Failure context.
	 * @param Jobs   $db_jobs      Jobs repository.
	 * @return array{retried:bool,exhausted:bool,attempt:int,max_attempts:int,next_retry_at?:string,retry_after?:int,reason?:string}
	 */
	public static function maybeRetry( int $job_id, string $reason, array $context_data, Jobs $db_jobs ): array {
		$engine_data = \datamachine_get_engine_data( $job_id );
		$engine_data = is_array( $engine_data ) ? $engine_data : array();
		$job         = $db_jobs->get_job( $job_id );
		$job         = is_array( $job ) ? $job : array();

		$policy = self::resolvePolicy( $job_id, $reason, $context_data, $engine_data, $job );

		$attempt      = self::currentAttempt( $engine_data ) + 1;
		$max_attempts = max( 1, (int) ( $policy['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS ) );

		if ( empty( $policy['retryable'] ) ) {
			return array(
				'retried'      => false,
				'exhausted'    => false,
				'attempt'      => $attempt,
				'max_attempts' => $max_attempts,
				'reason'       => 'not_retryable',
			);
		}

		if ( $attempt >= $max_attempts ) {
			self::recordPoisonItem( $job_id, $reason, $context_data, $engine_data, $attempt, $max_attempts );

			return array(
				'retried'      => false,
				'exhausted'    => true,
				'attempt'      => $attempt,
				'max_attempts' => $max_attempts,
				'reason'       => 'max_attempts_exhausted',
			);
		}

		$delay_seconds = self::resolveDelay( $attempt, $policy, $context_data );
		$timestamp     = time() + $delay_seconds;
		$flow_step_id  = (string) ( $context_data['flow_step_id'] ?? '' );

		// Direct/system tasks (e.g. daily_memory_generation) run through an
		// ephemeral single-step workflow with flow_id=pipeline_id='direct'.
		// They fail without a flow_step_id in $context_data, but their engine
		// snapshot still carries the ephemeral step definition under
		// flow_config — and datamachine_execute_step re-runs a step purely by
		// its flow_step_id against that snapshot. Resolve it so a transient
		// provider failure on a direct task is retryable through the same path
		// as a pipeline step, rather than dead-ending on missing_flow_step_id.
		if ( '' === $flow_step_id ) {
			$flow_step_id = self::resolveEphemeralFlowStepId( $engine_data );
		}

		if ( '' === $flow_step_id ) {
			return array(
				'retried'      => false,
				'exhausted'    => false,
				'attempt'      => $attempt,
				'max_attempts' => $max_attempts,
				'reason'       => 'missing_flow_step_id',
			);
		}

		$action_args = array(
			'job_id'       => $job_id,
			'flow_step_id' => $flow_step_id,
		);
		if ( 'direct' === (string) ( $job['flow_id'] ?? '' ) && (int) ( $job['operation_generation'] ?? 0 ) > 0 ) {
			$action_args['operation_generation'] = (int) $job['operation_generation'];
		}

		$action_id = as_schedule_single_action(
			$timestamp,
			'datamachine_execute_step',
			$action_args,
			'data-machine'
		);

		if ( false === $action_id ) {
			return array(
				'retried'      => false,
				'exhausted'    => false,
				'attempt'      => $attempt,
				'max_attempts' => $max_attempts,
				'reason'       => 'retry_schedule_failed',
			);
		}

		self::recordRetry( $job_id, $reason, $context_data, $engine_data, $attempt, $max_attempts, $delay_seconds, $timestamp, $policy );
		$db_jobs->update_job_status( $job_id, 'pending' );

		do_action(
			'datamachine_log',
			'warning',
			'Job retry scheduled by retry policy',
			array(
				'job_id'        => $job_id,
				'flow_step_id'  => $flow_step_id,
				'attempt'       => $attempt,
				'max_attempts'  => $max_attempts,
				'delay_seconds' => $delay_seconds,
				'retry_class'   => $policy['retry_class'] ?? null,
				'action_id'     => $action_id,
				'reason'        => $reason,
				'provider'      => $context_data['ai_provider'] ?? $engine_data['provider'] ?? null,
				'source_type'   => $engine_data['source_type'] ?? null,
			)
		);

		return array(
			'retried'       => true,
			'exhausted'     => false,
			'attempt'       => $attempt,
			'max_attempts'  => $max_attempts,
			'next_retry_at' => gmdate( 'c', $timestamp ),
			'retry_after'   => $delay_seconds,
			'retry_class'   => $policy['retry_class'] ?? 'generic',
		);
	}

	/**
	 * Resolve retry policy through generic hooks.
	 *
	 * @param int    $job_id       Job ID.
	 * @param string $reason       Failure reason.
	 * @param array  $context_data Failure context.
	 * @param array  $engine_data  Job engine data.
	 * @param array  $job          Job row.
	 * @return array
	 */
	private static function resolvePolicy( int $job_id, string $reason, array $context_data, array $engine_data, array $job ): array {
		$retry_class = self::classifyFailure( $reason, $context_data );
		$retryable   = self::isRetryableFailure( $reason, $context_data );

		/**
		 * Filter whether a job failure is retryable.
		 *
		 * @param bool   $retryable    Current retryable decision.
		 * @param int    $job_id       Job ID.
		 * @param string $reason       Failure reason.
		 * @param array  $context_data Failure context.
		 * @param array  $engine_data  Job engine data.
		 * @param array  $job          Job row.
		 */
		$retryable = (bool) apply_filters( 'datamachine_job_error_retryable', $retryable, $job_id, $reason, $context_data, $engine_data, $job );

		$policy = array(
			'retryable'    => $retryable,
			'max_attempts' => self::DEFAULT_MAX_ATTEMPTS,
			'base_delay'   => self::isShortTransportRetryClass( $retry_class ) ? self::AI_TRANSPORT_BASE_DELAY : self::DEFAULT_BASE_DELAY,
			'max_delay'    => self::DEFAULT_MAX_DELAY,
			'backoff'      => 'exponential',
			'jitter'       => 0,
			'retry_after'  => self::extractRetryAfter( $context_data ),
			'retry_class'  => $retry_class,
			'provider'     => $context_data['ai_provider'] ?? $engine_data['provider'] ?? null,
			'source_type'  => $engine_data['source_type'] ?? null,
			'pipeline_id'  => $job['pipeline_id'] ?? ( $engine_data['job']['pipeline_id'] ?? null ),
			'flow_id'      => $job['flow_id'] ?? ( $engine_data['job']['flow_id'] ?? null ),
			'flow_step_id' => $context_data['flow_step_id'] ?? null,
		);

		/**
		 * Filter retry policy for provider, pipeline, system, and source jobs.
		 *
		 * Return keys may include retryable, max_attempts, base_delay, max_delay,
		 * backoff, jitter, and retry_after.
		 *
		 * @param array  $policy       Current retry policy.
		 * @param int    $job_id       Job ID.
		 * @param string $reason       Failure reason.
		 * @param array  $context_data Failure context.
		 * @param array  $engine_data  Job engine data.
		 * @param array  $job          Job row.
		 */
		$policy = apply_filters( 'datamachine_job_retry_policy', $policy, $job_id, $reason, $context_data, $engine_data, $job );

		return is_array( $policy ) ? $policy : array( 'retryable' => false );
	}

	/**
	 * Determine a generic retryability default.
	 *
	 * @param string $reason       Failure reason.
	 * @param array  $context_data Failure context.
	 * @return bool
	 */
	private static function isRetryableFailure( string $reason, array $context_data ): bool {
		if ( isset( $context_data['retryable'] ) ) {
			return (bool) $context_data['retryable'];
		}

		if ( null !== self::extractRetryAfter( $context_data ) ) {
			return true;
		}

		if ( self::isShortTransportRetryClass( self::classifyFailure( $reason, $context_data ) ) ) {
			return true;
		}

		$message = strtolower( implode( ' ', array_filter( array(
			$reason,
			$context_data['error_message'] ?? '',
			$context_data['exception_message'] ?? '',
			$context_data['ai_error'] ?? '',
		) ) ) );

		foreach ( array( 'rate limit', '429', 'timeout', 'timed out', 'temporar', 'try again', 'throttle', 'overloaded', '503', '502', '504' ) as $needle ) {
			if ( str_contains( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Classify retryable failures so cheap transport blips can retry sooner.
	 *
	 * @param string $reason       Failure reason.
	 * @param array  $context_data Failure context.
	 * @return string
	 */
	private static function classifyFailure( string $reason, array $context_data ): string {
		$message = strtolower( implode( ' ', array_filter( array(
			$reason,
			$context_data['error_code'] ?? '',
			$context_data['error_message'] ?? '',
			$context_data['exception_message'] ?? '',
			$context_data['ai_error'] ?? '',
		) ) ) );

		if ( '' === $message ) {
			return 'generic';
		}

		foreach ( array( 'rate limit', 'rate-limit', 'too many requests', '429', 'throttle', 'throttled' ) as $needle ) {
			if ( str_contains( $message, $needle ) ) {
				return 'provider_rate_limit';
			}
		}

		foreach ( array( 'overloaded', 'overload', '503', '502', '504', 'service unavailable' ) as $needle ) {
			if ( str_contains( $message, $needle ) ) {
				return 'provider_overload';
			}
		}

		foreach ( array( 'could not resolve host', 'couldn\'t resolve host', 'name or service not known', 'curl error 6', 'dns' ) as $needle ) {
			if ( str_contains( $message, $needle ) ) {
				return 'transport_dns';
			}
		}

		foreach ( array( 'curl error 7', 'curl error 52', 'failed to connect', 'connection refused', 'connection reset', 'empty reply from server', 'network is unreachable', 'no route to host' ) as $needle ) {
			if ( str_contains( $message, $needle ) ) {
				return 'transport_network';
			}
		}

		if ( str_contains( $message, 'curl error 28' ) || str_contains( $message, 'connection timed out' ) || str_contains( $message, 'connect timed out' ) ) {
			return 'transport_connect_timeout';
		}

		return 'generic';
	}

	/**
	 * Whether a retry class should use the short AI transport delay.
	 *
	 * @param string $retry_class Retry classification.
	 * @return bool
	 */
	private static function isShortTransportRetryClass( string $retry_class ): bool {
		return in_array( $retry_class, array( 'transport_connect_timeout', 'transport_dns', 'transport_network' ), true );
	}

	/**
	 * Resolve delay with Retry-After and provider/source throttling hooks.
	 *
	 * @param int   $attempt      Attempt number being scheduled.
	 * @param array $policy       Retry policy.
	 * @param array $context_data Failure context.
	 * @return int
	 */
	private static function resolveDelay( int $attempt, array $policy, array $context_data ): int {
		$retry_after = isset( $policy['retry_after'] ) ? self::normalizeDelay( $policy['retry_after'] ) : null;
		if ( null === $retry_after ) {
			$retry_after = self::extractRetryAfter( $context_data );
		}

		$base_delay = max( 1, (int) ( $policy['base_delay'] ?? self::DEFAULT_BASE_DELAY ) );
		$max_delay  = max( $base_delay, (int) ( $policy['max_delay'] ?? self::DEFAULT_MAX_DELAY ) );
		$delay      = $base_delay;

		if ( 'exponential' === ( $policy['backoff'] ?? 'exponential' ) ) {
			$delay = $base_delay * ( 2 ** max( 0, $attempt - 1 ) );
		}

		$delay = min( $max_delay, $delay );
		if ( null !== $retry_after ) {
			$delay = max( $delay, $retry_after );
		}

		/**
		 * Filter per-provider/source throttle delay for retries.
		 *
		 * Return a delay in seconds. Data Machine uses the maximum of the backoff,
		 * Retry-After, and throttle delay so independent policies compose safely.
		 *
		 * @param int   $delay        Current delay in seconds.
		 * @param array $policy       Retry policy.
		 * @param int   $attempt      Attempt number being scheduled.
		 * @param array $context_data Failure context.
		 */
		$throttle_delay = apply_filters( 'datamachine_job_retry_throttle_delay', $delay, $policy, $attempt, $context_data );
		$delay          = max( $delay, self::normalizeDelay( $throttle_delay ) ?? 0 );

		$jitter = max( 0, (int) ( $policy['jitter'] ?? 0 ) );
		if ( $jitter > 0 ) {
			$delay += wp_rand( 0, $jitter );
		}

		return max( 0, $delay );
	}

	/**
	 * Extract Retry-After from provider/context data.
	 *
	 * @param array $context_data Failure context.
	 * @return int|null Delay in seconds.
	 */
	private static function extractRetryAfter( array $context_data ): ?int {
		foreach ( array( 'retry_after', 'retry_after_seconds', 'retry-after' ) as $key ) {
			if ( isset( $context_data[ $key ] ) ) {
				$delay = self::normalizeDelay( $context_data[ $key ] );
				if ( null !== $delay ) {
					return $delay;
				}
			}
		}

		$headers = $context_data['headers'] ?? array();
		if ( is_array( $headers ) ) {
			foreach ( array( 'retry-after', 'Retry-After' ) as $key ) {
				if ( isset( $headers[ $key ] ) ) {
					$delay = self::normalizeDelay( $headers[ $key ] );
					if ( null !== $delay ) {
						return $delay;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Normalize a delay value to seconds.
	 *
	 * @param mixed $value Delay value or HTTP date.
	 * @return int|null
	 */
	private static function normalizeDelay( mixed $value ): ?int {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		if ( is_numeric( $value ) ) {
			return max( 0, (int) $value );
		}

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$timestamp = strtotime( $value );
			if ( false !== $timestamp ) {
				return max( 0, $timestamp - time() );
			}
		}

		return null;
	}

	/**
	 * Resolve the flow_step_id a direct/system task should re-run on retry.
	 *
	 * Direct tasks (scheduled via TaskScheduler → execute-workflow) store their
	 * ephemeral steps under engine_data['flow_config'], keyed by flow_step_id.
	 * datamachine_execute_step re-runs a step purely by its flow_step_id against
	 * the engine snapshot, so resolving the right id is all that's needed to make
	 * a direct task retryable.
	 *
	 * Resolution is scoped by step count:
	 *
	 *   - Single-step ephemeral workflow (the default getWorkflow() for all 13
	 *     current system tasks): the step IS the whole task and is idempotent, so
	 *     re-running it is the retry. Returns that step's id.
	 *
	 *   - Multi-step ephemeral workflow: only resumed when the task opted in via
	 *     SystemTask::isResumable() (surfaced as engine_data['resumable'] = true).
	 *     For a resumable workflow the resume point is the FIRST INCOMPLETE step
	 *     in execution order — steps that already completed (recorded in
	 *     engine_data['step_results']) are skipped, so their already-applied
	 *     effects (engine_data['effects']) are never re-applied. A non-resumable
	 *     multi-step workflow returns '' and continues to fail fast, never
	 *     blindly re-running a step of a partially-executed flow.
	 *
	 *   - No flow_config (a genuine pipeline/flow job): returns '' so the normal
	 *     $context_data['flow_step_id'] path is used instead.
	 *
	 * @param array $engine_data Job engine data.
	 * @return string Resume flow_step_id, or '' when not resolvable.
	 */
	private static function resolveEphemeralFlowStepId( array $engine_data ): string {
		$flow_config = is_array( $engine_data['flow_config'] ?? null ) ? $engine_data['flow_config'] : array();
		$step_count  = count( $flow_config );

		if ( 0 === $step_count ) {
			return '';
		}

		if ( 1 === $step_count ) {
			return self::flowStepIdFor( $flow_config, (string) ( array_key_first( $flow_config ) ?? '' ) );
		}

		// Multi-step ephemeral workflow: only resumable workflows resolve a
		// resume point. Everything else fails fast (unchanged behavior).
		if ( empty( $engine_data['resumable'] ) ) {
			return '';
		}

		return self::resolveResumeStepId( $flow_config, $engine_data );
	}

	/**
	 * Resolve the safe resume step for an explicit direct-workflow retry.
	 *
	 * @param array $engine_data Job engine data.
	 * @return string Resume step ID, or empty string when retry is unsafe.
	 */
	public static function resolveDirectResumeStepId( array $engine_data ): string {
		return self::resolveEphemeralFlowStepId( $engine_data );
	}

	/**
	 * Resolve the first incomplete step id of a resumable multi-step workflow.
	 *
	 * Walks the flow_config in execution order and returns the first step that
	 * does not have a successful completion recorded in
	 * engine_data['step_results']. Completed steps are skipped so the retry never
	 * re-runs already-applied work. Returns '' when the order cannot be resolved
	 * (invalid execution_order) or when every step already completed (nothing to
	 * resume — the failure is not a resumable partial-execution case).
	 *
	 * @param array $flow_config Ephemeral flow config keyed by flow_step_id.
	 * @param array $engine_data Job engine data.
	 * @return string First incomplete flow_step_id, or '' when not resolvable.
	 */
	private static function resolveResumeStepId( array $flow_config, array $engine_data ): string {
		try {
			$plan = \DataMachine\Engine\ExecutionPlan::from_flow_config( $flow_config );
		} catch ( \InvalidArgumentException $e ) {
			// A workflow without a valid execution order cannot be safely
			// resumed; fall back to fail-fast rather than guess an order.
			return '';
		}

		$ordered      = $plan->ordered_step_ids();
		$step_results = is_array( $engine_data['step_results'] ?? null ) ? $engine_data['step_results'] : array();

		foreach ( $ordered as $flow_step_id ) {
			if ( ! self::stepCompletedSuccessfully( $step_results[ $flow_step_id ] ?? null ) ) {
				return self::flowStepIdFor( $flow_config, (string) $flow_step_id );
			}
		}

		return '';
	}

	/**
	 * Whether a recorded step result represents a successful completion.
	 *
	 * RunMetrics::recordStepResult stamps engine_data['step_results'][id] with a
	 * step_success boolean and a result/status string as each step runs. A step
	 * counts as completed (and must NOT be re-run on resume) when it reported
	 * step_success or a terminal-success result. Any other shape — failed,
	 * never-run (null), or empty — is an incomplete step to resume from.
	 *
	 * @param mixed $step_result Recorded step result entry, or null when absent.
	 * @return bool True when the step completed successfully.
	 */
	private static function stepCompletedSuccessfully( $step_result ): bool {
		if ( ! is_array( $step_result ) ) {
			return false;
		}

		if ( array_key_exists( 'step_success', $step_result ) ) {
			return (bool) $step_result['step_success'];
		}

		$outcome = (string) ( $step_result['result'] ?? ( $step_result['status'] ?? '' ) );

		return in_array(
			$outcome,
			array( 'completed', 'completed_override', 'inline_continuation', 'batch_scheduled', 'completed_no_items', 'waiting' ),
			true
		);
	}

	/**
	 * Resolve the canonical flow_step_id for an ephemeral flow_config entry.
	 *
	 * Prefers the entry's own `flow_step_id` field, falling back to its config
	 * key (the engine keys flow_config by flow_step_id).
	 *
	 * @param array  $flow_config  Ephemeral flow config keyed by flow_step_id.
	 * @param string $flow_step_id Candidate/key flow step id.
	 * @return string Resolved flow_step_id, or '' when not resolvable.
	 */
	private static function flowStepIdFor( array $flow_config, string $flow_step_id ): string {
		$step = is_array( $flow_config[ $flow_step_id ] ?? null ) ? $flow_config[ $flow_step_id ] : null;
		if ( is_array( $step ) ) {
			$explicit = (string) ( $step['flow_step_id'] ?? '' );
			if ( '' !== $explicit ) {
				return $explicit;
			}
		}

		return $flow_step_id;
	}

	/**
	 * Current recorded retry attempt count.
	 *
	 * @param array $engine_data Job engine data.
	 * @return int
	 */
	private static function currentAttempt( array $engine_data ): int {
		$retry = $engine_data['retry'] ?? array();
		if ( is_array( $retry ) && isset( $retry['attempts'] ) ) {
			return max( 0, (int) $retry['attempts'] );
		}

		return 0;
	}

	/**
	 * Record retry metadata on the job.
	 *
	 * @param int    $job_id        Job ID.
	 * @param string $reason        Failure reason.
	 * @param array  $context_data  Failure context.
	 * @param array  $engine_data   Job engine data.
	 * @param int    $attempt       Attempt number.
	 * @param int    $max_attempts  Maximum attempts.
	 * @param int    $delay_seconds Delay in seconds.
	 * @param int    $timestamp     Retry timestamp.
	 * @param array  $policy        Retry policy.
	 * @return void
	 */
	private static function recordRetry( int $job_id, string $reason, array $context_data, array $engine_data, int $attempt, int $max_attempts, int $delay_seconds, int $timestamp, array $policy ): void {
		$history   = is_array( $engine_data['retry']['history'] ?? null ) ? $engine_data['retry']['history'] : array();
		$history[] = array_filter(
			array(
				'attempt'       => $attempt,
				'max_attempts'  => $max_attempts,
				'reason'        => $reason,
				'flow_step_id'  => $context_data['flow_step_id'] ?? null,
				'provider'      => $policy['provider'] ?? null,
				'source_type'   => $policy['source_type'] ?? null,
				'retry_class'   => $policy['retry_class'] ?? null,
				'delay_seconds' => $delay_seconds,
				'next_retry_at' => gmdate( 'c', $timestamp ),
				'recorded_at'   => gmdate( 'c' ),
			),
			fn( $value ) => null !== $value
		);

		\datamachine_merge_engine_data(
			$job_id,
			array(
				'retry' => array(
					'attempts'       => $attempt,
					'max_attempts'   => $max_attempts,
					'next_retry_at'  => gmdate( 'c', $timestamp ),
					'delay_seconds'  => $delay_seconds,
					'last_reason'    => $reason,
					'last_retryable' => true,
					'retry_class'    => $policy['retry_class'] ?? null,
					'provider'       => $policy['provider'] ?? null,
					'source_type'    => $policy['source_type'] ?? null,
					'history'        => $history,
				),
			)
		);
	}

	/**
	 * Record poison-item metadata after retry exhaustion.
	 *
	 * @param int    $job_id       Job ID.
	 * @param string $reason       Failure reason.
	 * @param array  $context_data Failure context.
	 * @param array  $engine_data  Job engine data.
	 * @param int    $attempt      Attempt number.
	 * @param int    $max_attempts Maximum attempts.
	 * @return void
	 */
	private static function recordPoisonItem( int $job_id, string $reason, array $context_data, array $engine_data, int $attempt, int $max_attempts ): void {
		$retry_class = self::classifyFailure( $reason, $context_data );
		if ( ! self::shouldPoisonSourceItem( $retry_class ) ) {
			\datamachine_merge_engine_data(
				$job_id,
				array(
					'retry' => array(
						'attempts'       => $attempt,
						'max_attempts'   => $max_attempts,
						'last_reason'    => $reason,
						'last_retryable' => true,
						'retry_class'    => $retry_class,
						'exhausted'      => true,
					),
				)
			);

			do_action(
				'datamachine_log',
				'error',
				'Job retry policy exhausted provider/system retry without isolating source item',
				array_filter(
					array(
						'job_id'          => $job_id,
						'item_identifier' => $engine_data['item_identifier'] ?? null,
						'source_type'     => $engine_data['source_type'] ?? null,
						'flow_step_id'    => $context_data['flow_step_id'] ?? null,
						'reason'          => $reason,
						'retry_class'     => $retry_class,
						'attempts'        => $attempt,
						'max_attempts'    => $max_attempts,
						'recorded_at'     => gmdate( 'c' ),
					),
					fn( $value ) => null !== $value
				)
			);

			return;
		}

		$poison = array_filter(
			array(
				'isolated'        => true,
				'job_id'          => $job_id,
				'item_identifier' => $engine_data['item_identifier'] ?? null,
				'source_type'     => $engine_data['source_type'] ?? null,
				'flow_step_id'    => $context_data['flow_step_id'] ?? null,
				'reason'          => $reason,
				'attempts'        => $attempt,
				'max_attempts'    => $max_attempts,
				'recorded_at'     => gmdate( 'c' ),
			),
			fn( $value ) => null !== $value
		);

		\datamachine_merge_engine_data(
			$job_id,
			array(
				'retry'       => array(
					'attempts'       => $attempt,
					'max_attempts'   => $max_attempts,
					'last_reason'    => $reason,
					'last_retryable' => true,
					'exhausted'      => true,
				),
				'poison_item' => $poison,
			)
		);

		do_action(
			'datamachine_log',
			'error',
			'Job retry policy isolated poison item after retry exhaustion',
			$poison
		);
	}

	/**
	 * Whether retry exhaustion should isolate the current source item.
	 *
	 * Provider and transport failures are not evidence that the source item is
	 * malformed. Marking them as poison hides retryable source work behind an
	 * infrastructure outage.
	 *
	 * @param string $retry_class Retry classification.
	 * @return bool
	 */
	private static function shouldPoisonSourceItem( string $retry_class ): bool {
		return ! in_array(
			$retry_class,
			array( 'transport_connect_timeout', 'transport_dns', 'transport_network', 'provider_rate_limit', 'provider_overload' ),
			true
		);
	}
}
