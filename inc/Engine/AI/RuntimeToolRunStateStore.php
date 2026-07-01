<?php
/**
 * Durable runtime-tool run state storage.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use DataMachine\Core\EngineData;
use DataMachine\Core\Database\Jobs\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Stores deterministic runtime-tool pause/resume state on the request job.
 */
class RuntimeToolRunStateStore {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_FINALIZED = 'finalized';
	public const STATUS_RESUMED   = 'resumed';
	public const STATUS_TIMED_OUT = 'timed_out';

	/**
	 * Jobs repository.
	 *
	 * @var object
	 */
	private object $jobs;

	/**
	 * @param object|null $jobs Jobs-like repository with retrieve_engine_data() and store_engine_data().
	 */
	public function __construct( ?object $jobs = null ) {
		$this->jobs = $jobs ?? new Jobs();
	}

	/**
	 * Create pending runtime-tool run state for a request job.
	 *
	 * @param int                  $job_id Request job ID.
	 * @param array<string,mixed>  $state  Initial state fields.
	 * @return array<string,mixed>
	 */
	public function create( int $job_id, array $state ): array {
		$this->assert_job_id( $job_id );

		$run_state = array();
		$this->mutate_engine_data(
			$job_id,
			function ( array $engine_data ) use ( $state, &$run_state ): array {
				$existing = $this->normalize_existing( $engine_data['runtime_tool_run_state'] ?? null );
				if ( ! empty( $existing ) ) {
					$run_state = $existing;
					return $engine_data;
				}

				$created_at = $this->timestamp();
				$run_state  = array(
					'parent_job_id'           => max( 0, (int) ( $state['parent_job_id'] ?? 0 ) ),
					'runtime_tool_request_id' => $this->required_string( $state, 'runtime_tool_request_id' ),
					'tool_name'               => $this->required_string( $state, 'tool_name' ),
					'status'                  => self::STATUS_PENDING,
					'timeout_seconds'         => max( 0, (int) ( $state['timeout_seconds'] ?? 0 ) ),
					'deadline_at'             => (string) ( $state['deadline_at'] ?? '' ),
					'resume_payload'          => null,
					'finalize_payload'        => null,
					'created_at'              => $created_at,
					'updated_at'              => $created_at,
					'finalized_at'            => null,
					'resumed_at'              => null,
				);

				$engine_data['runtime_tool_run_state'] = $run_state;

				return $engine_data;
			},
			'runtime_tool_run_state_create'
		);

		return $run_state;
	}

	/**
	 * Create pending state from a canonical Agents API runtime-tool request.
	 *
	 * @param array<string,mixed> $request Canonical runtime-tool request.
	 * @return array<string,mixed>|null
	 */
	public function create_from_request( array $request ): ?array {
		$metadata = is_array( $request['metadata']['datamachine'] ?? null ) ? $request['metadata']['datamachine'] : array();
		$job_id   = max( 0, (int) ( $metadata['job_id'] ?? 0 ) );
		if ( $job_id <= 0 ) {
			return null;
		}

		return $this->create(
			$job_id,
			array(
				'parent_job_id'           => max( 0, (int) ( $metadata['parent_job_id'] ?? 0 ) ),
				'runtime_tool_request_id' => (string) ( $request['request_id'] ?? '' ),
				'tool_name'               => (string) ( $request['tool_name'] ?? '' ),
				'timeout_seconds'         => max( 0, (int) ( $metadata['timeout_seconds'] ?? 0 ) ),
				'deadline_at'             => (string) ( $request['timeout_at'] ?? $metadata['expires_at'] ?? '' ),
			)
		);
	}

	/**
	 * Read runtime-tool run state from a request job.
	 *
	 * @param int $job_id Request job ID.
	 * @return array<string,mixed>|null
	 */
	public function get( int $job_id ): ?array {
		$this->assert_job_id( $job_id );

		$state = $this->normalize_existing( $this->engine_data( $job_id )['runtime_tool_run_state'] ?? null );

		return empty( $state ) ? null : $state;
	}

	/**
	 * Persist a deterministic finalize payload once.
	 *
	 * @param int                 $job_id  Request job ID.
	 * @param array<string,mixed> $payload Finalize payload.
	 * @param string              $status  Finalized status.
	 * @return array<string,mixed>|null
	 */
	public function finalize( int $job_id, array $payload, string $status = self::STATUS_FINALIZED ): ?array {
		return $this->transition_once( $job_id, 'finalized_at', 'finalize_payload', $payload, $status );
	}

	/**
	 * Persist a deterministic resume payload once.
	 *
	 * @param int                 $job_id  Request job ID.
	 * @param array<string,mixed> $payload Resume payload.
	 * @return array<string,mixed>|null
	 */
	public function resume( int $job_id, array $payload ): ?array {
		return $this->transition_once( $job_id, 'resumed_at', 'resume_payload', $payload, self::STATUS_RESUMED );
	}

	/**
	 * Apply a one-time state transition.
	 *
	 * @param int                 $job_id      Request job ID.
	 * @param string              $timestamp_key Timestamp field name.
	 * @param string              $payload_key Payload field name.
	 * @param array<string,mixed> $payload     Payload to persist.
	 * @param string              $status      New status.
	 * @return array<string,mixed>|null
	 */
	private function transition_once( int $job_id, string $timestamp_key, string $payload_key, array $payload, string $status ): ?array {
		$this->assert_job_id( $job_id );

		$state = null;
		$this->mutate_engine_data(
			$job_id,
			function ( array $engine_data ) use ( $timestamp_key, $payload_key, $payload, $status, &$state ): array {
				$state = $this->normalize_existing( $engine_data['runtime_tool_run_state'] ?? null );
				if ( empty( $state ) || ! empty( $state[ $timestamp_key ] ) ) {
					return $engine_data;
				}

				$timestamp                             = $this->timestamp();
				$state['status']                       = $status;
				$state[ $payload_key ]                 = $payload;
				$state[ $timestamp_key ]               = $timestamp;
				$state['updated_at']                   = $timestamp;
				$engine_data['runtime_tool_run_state'] = $state;

				return $engine_data;
			},
			'runtime_tool_run_state_transition'
		);

		return $state;
	}

	/**
	 * @param int      $job_id Request job ID.
	 * @param callable $callback Receives current engine data and returns next engine data.
	 * @param string   $event_type Mutation event type.
	 * @return bool
	 */
	private function mutate_engine_data( int $job_id, callable $callback, string $event_type ): bool {
		if ( $this->jobs instanceof Jobs ) {
			$result = EngineData::mutate( $job_id, $callback, $event_type );

			return ! empty( $result['success'] );
		}

		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$current = $this->engine_data( $job_id );
			$next    = $callback( $current );
			if ( ! is_array( $next ) ) {
				return false;
			}

			if ( $next === $current ) {
				return true;
			}

			if ( method_exists( $this->jobs, 'compare_and_swap_engine_data' ) ) {
				$result = $this->jobs->compare_and_swap_engine_data( $job_id, $current, $next );
				if ( ! empty( $result['updated'] ) ) {
					return true;
				}

				// Retry on both logical conflicts and transient DB lock
				// contention (deadlock / lock-wait timeout); bail only on a
				// genuinely fatal persist failure.
				if ( empty( $result['conflict'] ) && empty( $result['retryable'] ) ) {
					return false;
				}

				if ( ! empty( $result['retryable'] ) && $attempt < 3 ) {
					usleep( wp_rand( 5000, 25000 ) );
				}

				continue;
			}

			return (bool) $this->jobs->store_engine_data( $job_id, $next );
		}

		return false;
	}

	/**
	 * @param int $job_id Request job ID.
	 * @return array<string,mixed>
	 */
	private function engine_data( int $job_id ): array {
		$engine_data = $this->jobs->retrieve_engine_data( $job_id );

		return is_array( $engine_data ) ? $engine_data : array();
	}

	/**
	 * @param mixed $state Raw state.
	 * @return array<string,mixed>
	 */
	private function normalize_existing( $state ): array {
		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param int $job_id Request job ID.
	 */
	private function assert_job_id( int $job_id ): void {
		if ( $job_id <= 0 ) {
			throw new \InvalidArgumentException( 'Runtime tool run state requires a positive job id.' );
		}
	}

	/**
	 * @param array<string,mixed> $state Initial state fields.
	 * @param string              $key Required key.
	 */
	private function required_string( array $state, string $key ): string {
		$value = trim( (string) ( $state[ $key ] ?? '' ) );
		if ( '' === $value ) {
			throw new \InvalidArgumentException( sprintf( 'Runtime tool run state requires %s.', esc_html( $key ) ) );
		}

		return $value;
	}

	private function timestamp(): string {
		return gmdate( 'c' );
	}
}
