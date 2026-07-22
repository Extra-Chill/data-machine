<?php
/**
 * Generic run lifecycle store.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

use DataMachine\Core\Database\Jobs\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Small run lifecycle facade backed by existing job storage.
 *
 * The first implementation intentionally maps a run to a job so deterministic
 * loops can share lifecycle semantics before a dedicated run table is needed.
 */
class RunLifecycleStore {

	private const META_KEY = 'run_lifecycle';

	private Jobs $jobs;

	public function __construct( ?Jobs $jobs = null ) {
		$this->jobs = $jobs ?? new Jobs();
	}

	/**
	 * Create a run and return its normalized lifecycle snapshot.
	 *
	 * @param string $run_type Generic run type.
	 * @param array  $args Optional job/lifecycle data.
	 * @return array|false Lifecycle snapshot, or false on failure.
	 */
	public function create_run( string $run_type, array $args = array() ): array|false {
		$run_type = $this->normalize_run_type( $run_type );
		if ( '' === $run_type ) {
			return false;
		}

		$job_data = is_array( $args['job_data'] ?? null ) ? $args['job_data'] : array();
		$job_data = array_merge(
			array(
				'pipeline_id' => null,
				'flow_id'     => null,
				'source'      => $run_type,
				'label'       => $args['label'] ?? null,
			),
			$job_data
		);

		$job_result = ! empty( $job_data['idempotency_key'] )
			? $this->jobs->create_or_get_job( $job_data )
			: $this->jobs->create_job( $job_data );

		$job_id = is_array( $job_result ) ? (int) ( $job_result['job_id'] ?? 0 ) : (int) $job_result;
		if ( $job_id <= 0 ) {
			return false;
		}

		$seed = array(
			'run_type'      => $run_type,
			'status'        => JobStatus::PENDING,
			'attempt'       => max( 1, (int) ( $args['attempt'] ?? 1 ) ),
			'replay_events' => is_array( $args['replay_events'] ?? null ) ? array_values( $args['replay_events'] ) : array(),
			'artifact_refs' => is_array( $args['artifact_refs'] ?? null ) ? array_values( $args['artifact_refs'] ) : array(),
		);

		$this->mark_job_created( $job_id, $seed );

		return $this->get_run( $this->run_id_for_job( $job_id ) );
	}

	/**
	 * Get a run by run_id. Numeric IDs are accepted as job IDs for adapters.
	 */
	public function get_run( int|string $run_id ): ?array {
		$job_id = $this->job_id_from_run_id( $run_id );
		if ( $job_id <= 0 ) {
			return null;
		}

		$job = $this->jobs->get_job( $job_id );
		if ( ! is_array( $job ) ) {
			return null;
		}

		$engine_data = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$meta        = is_array( $engine_data[ self::META_KEY ] ?? null ) ? $engine_data[ self::META_KEY ] : array();
		$run_id      = (string) ( $meta['run_id'] ?? $this->run_id_for_job( $job_id ) );

		return array_merge(
			array(
				'run_id'        => $run_id,
				'job_id'        => $job_id,
				'run_type'      => $this->normalize_run_type( $meta['run_type'] ?? ( $job['source'] ?? 'job' ) ),
				'status'        => (string) ( $job['status'] ?? ( $meta['status'] ?? JobStatus::PENDING ) ),
				'attempt'       => max( 1, (int) ( $meta['attempt'] ?? 1 ) ),
				'replay_events' => is_array( $meta['replay_events'] ?? null ) ? array_values( $meta['replay_events'] ) : array(),
				'artifact_refs' => is_array( $meta['artifact_refs'] ?? null ) ? array_values( $meta['artifact_refs'] ) : array(),
				'created_at'    => (string) ( $job['created_at'] ?? ( $meta['created_at'] ?? '' ) ),
				'completed_at'  => (string) ( $job['completed_at'] ?? ( $meta['completed_at'] ?? '' ) ),
			),
			$meta,
			array(
				'run_id' => $run_id,
				'job_id' => $job_id,
			)
		);
	}

	public function start_run( int|string $run_id, array $context = array() ): array|false {
		return $this->transition_run( $run_id, JobStatus::PROCESSING, 'started_at', $context );
	}

	public function wait_run( int|string $run_id, array $context = array() ): array|false {
		return $this->transition_run( $run_id, JobStatus::WAITING, 'waiting_at', $context );
	}

	public function resume_run( int|string $run_id, array $context = array() ): array|false {
		$context['attempt_delta'] = (int) ( $context['attempt_delta'] ?? 1 );
		return $this->transition_run( $run_id, JobStatus::PROCESSING, 'resumed_at', $context );
	}

	public function complete_run( int|string $run_id, array $context = array() ): array|false {
		return $this->transition_run( $run_id, JobStatus::COMPLETED, 'completed_at', $context, true );
	}

	public function fail_run( int|string $run_id, string $reason = '', array $context = array() ): array|false {
		$status = '' === trim( $reason ) ? JobStatus::FAILED : JobStatus::failed( $reason )->toString();
		return $this->transition_run( $run_id, $status, 'failed_at', $context, true );
	}

	public function cancel_run( int|string $run_id, array $context = array() ): array|false {
		return $this->transition_run( $run_id, JobStatus::CANCELLED, 'cancelled_at', $context, true );
	}

	public function add_artifact_ref( int|string $run_id, string $artifact_ref, array $metadata = array() ): array|false {
		$artifact_ref = sanitize_text_field( $artifact_ref );
		if ( '' === $artifact_ref ) {
			return false;
		}

		return $this->mutate_run(
			$run_id,
			static function ( array $meta ) use ( $artifact_ref, $metadata ): array {
				$refs = is_array( $meta['artifact_refs'] ?? null ) ? $meta['artifact_refs'] : array();
				if ( ! in_array( $artifact_ref, $refs, true ) ) {
					$refs[] = $artifact_ref;
				}
				$meta['artifact_refs'] = array_values( $refs );
				if ( ! empty( $metadata ) ) {
					$meta['artifact_ref_metadata'][ $artifact_ref ] = $metadata;
				}
				return $meta;
			}
		);
	}

	public function append_replay_event( int|string $run_id, string $event_type, array $payload = array() ): array|false {
		$event_type = sanitize_key( $event_type );
		if ( '' === $event_type ) {
			return false;
		}

		return $this->mutate_run(
			$run_id,
			function ( array $meta ) use ( $event_type, $payload ): array {
				$events                = is_array( $meta['replay_events'] ?? null ) ? $meta['replay_events'] : array();
				$events[]              = array(
					'type'       => $event_type,
					'payload'    => $payload,
					'created_at' => $this->now(),
				);
				$meta['replay_events'] = $events;
				return $meta;
			}
		);
	}

	/**
	 * Attach lifecycle metadata to an existing job without changing job behavior.
	 */
	public function mark_job_created( int $job_id, array $metadata = array() ): bool {
		if ( $job_id <= 0 ) {
			return false;
		}

		$result = $this->mutate_run(
			$job_id,
			function ( array $meta ) use ( $job_id, $metadata ): array {
				$created = array_merge(
					array(
						'run_id'        => $this->run_id_for_job( $job_id ),
						'job_id'        => $job_id,
						'run_type'      => $this->normalize_run_type( $metadata['run_type'] ?? 'job' ),
						'status'        => JobStatus::PENDING,
						'attempt'       => 1,
						'replay_events' => array(),
						'artifact_refs' => array(),
						'created_at'    => $this->now(),
					),
					$metadata
				);

				return array_merge(
					$created,
					$meta,
					array(
						'run_id' => $this->run_id_for_job( $job_id ),
						'job_id' => $job_id,
					)
				);
			}
		);

		return false !== $result;
	}

	/**
	 * Mirror an existing job status transition into generic run metadata.
	 */
	public function mark_job_status( int $job_id, string $status ): bool {
		if ( $job_id <= 0 || '' === $status ) {
			return false;
		}

		$result = $this->mutate_run(
			$job_id,
			function ( array $meta ) use ( $status ): array {
				if ( $status === ( $meta['status'] ?? null ) && ( ! JobStatus::isStatusFinal( $status ) || ! empty( $meta['completed_at'] ) ) ) {
					return $meta;
				}
				$meta['status']     = $status;
				$meta['updated_at'] = $this->now();
				if ( JobStatus::isStatusFinal( $status ) ) {
					$meta['completed_at'] = $meta['completed_at'] ?? $meta['updated_at'];
				}
				return $meta;
			}
		);

		return false !== $result;
	}

	private function transition_run( int|string $run_id, string $status, string $timestamp_key, array $context = array(), bool $is_final = false ): array|false {
		$job_id = $this->job_id_from_run_id( $run_id );
		if ( $job_id <= 0 ) {
			return false;
		}

		$transition = $this->jobs->transition_job_status_result( $job_id, $status, $is_final );
		if ( empty( $transition['success'] ) ) {
			return false;
		}

		return $this->mutate_run(
			$job_id,
			function ( array $meta ) use ( $status, $timestamp_key, $context ): array {
				$meta['status']          = $status;
				$meta[ $timestamp_key ]  = $meta[ $timestamp_key ] ?? $this->now();
				$meta['updated_at']      = $this->now();
				$meta['attempt']         = max( 1, (int) ( $meta['attempt'] ?? 1 ) + (int) ( $context['attempt_delta'] ?? 0 ) );
				$meta['last_transition'] = array(
					'status'  => $status,
					'context' => $context,
				);
				return $meta;
			}
		);
	}

	private function mutate_run( int|string $run_id, callable $callback ): array|false {
		$job_id = $this->job_id_from_run_id( $run_id );
		if ( $job_id <= 0 ) {
			return false;
		}

		$result = EngineData::mutate(
			$job_id,
			function ( array $snapshot ) use ( $job_id, $callback ): array {
				$meta                       = is_array( $snapshot[ self::META_KEY ] ?? null ) ? $snapshot[ self::META_KEY ] : array();
				$meta['run_id']             = (string) ( $meta['run_id'] ?? $this->run_id_for_job( $job_id ) );
				$meta['job_id']             = $job_id;
				$meta['attempt']            = max( 1, (int) ( $meta['attempt'] ?? 1 ) );
				$meta['replay_events']      = is_array( $meta['replay_events'] ?? null ) ? array_values( $meta['replay_events'] ) : array();
				$meta['artifact_refs']      = is_array( $meta['artifact_refs'] ?? null ) ? array_values( $meta['artifact_refs'] ) : array();
				$next                       = $callback( $meta );
				$next['run_id']             = $this->run_id_for_job( $job_id );
				$next['job_id']             = $job_id;
				$snapshot[ self::META_KEY ] = $next;
				return $snapshot;
			},
			'run_lifecycle'
		);

		return empty( $result['success'] ) ? false : $this->get_run( $job_id );
	}

	private function run_id_for_job( int $job_id ): string {
		return 'job:' . $job_id;
	}

	private function job_id_from_run_id( int|string $run_id ): int {
		if ( is_int( $run_id ) || ctype_digit( (string) $run_id ) ) {
			return absint( $run_id );
		}

		$run_id = trim( (string) $run_id );
		if ( str_starts_with( $run_id, 'job:' ) ) {
			return absint( substr( $run_id, 4 ) );
		}

		return 0;
	}

	private function normalize_run_type( mixed $run_type ): string {
		return sanitize_key( (string) $run_type );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
	}
}
