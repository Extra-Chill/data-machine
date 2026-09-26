<?php
/**
 * Generic task run-control primitives.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tasks;

use AgentsAPI\AI\WP_Agent_Run_Result_Envelope;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for canonical task execution contracts.
 */
class WP_Agent_Task_Run_Control {

	public const STATUS_QUEUED     = 'queued';
	public const STATUS_RUNNING    = 'running';
	public const STATUS_CANCELLING = 'cancelling';
	public const STATUS_CANCELLED  = 'cancelled';
	public const STATUS_SUCCEEDED  = 'succeeded';
	public const STATUS_FAILED     = 'failed';
	private const OPTION_KEY       = 'agents_api_task_run_control';

	/** @return string[] */
	public static function statuses(): array {
		return array(
			self::STATUS_QUEUED,
			self::STATUS_RUNNING,
			self::STATUS_CANCELLING,
			self::STATUS_CANCELLED,
			self::STATUS_SUCCEEDED,
			self::STATUS_FAILED,
		);
	}

	/**
	 * Generate an opaque client-addressable run ID.
	 */
	public static function generate_run_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'task_run_' . str_replace( '-', '', wp_generate_uuid4() );
		}

		try {
			return 'task_run_' . bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			return 'task_run_' . str_replace( '.', '', uniqid( '', true ) );
		}
	}

	/**
	 * Normalize a task run status/result payload returned by an executor.
	 *
	 * @param array<string,mixed> $run Raw run payload.
	 * @return array<string,mixed>
	 */
	public static function normalize_run( array $run ): array {
		$run_id      = trim( self::string_value( $run['run_id'] ?? null ) );
		$session_id  = trim( self::string_value( $run['session_id'] ?? null ) );
		$executor_id = trim( self::string_value( $run['executor_id'] ?? null ) );
		$status      = self::normalize_status( $run['status'] ?? self::STATUS_RUNNING );

		if ( '' === $run_id ) {
			throw new \InvalidArgumentException( 'run_id must be a non-empty string' );
		}

		if ( '' === $session_id ) {
			throw new \InvalidArgumentException( 'session_id must be a non-empty string' );
		}

		if ( '' === $executor_id ) {
			throw new \InvalidArgumentException( 'executor_id must be a non-empty string' );
		}

		return array(
			'schema'            => self::string_value( $run['schema'] ?? 'agents-api/task-result/v1' ),
			'run_id'            => $run_id,
			'session_id'        => $session_id,
			'status'            => $status,
			'executor_id'       => $executor_id,
			'owner'             => self::owner_value( $run['owner'] ?? array() ),
			'execution_metrics' => self::execution_metrics_value( $run['execution_metrics'] ?? array(), $executor_id ),
			'artifact_refs'     => self::list_value( $run['artifact_refs'] ?? array() ),
			'diagnostics'       => self::map_value( $run['diagnostics'] ?? array() ),
			'events'            => self::list_value( $run['events'] ?? array() ),
			'provenance'        => self::map_value( $run['provenance'] ?? array() ),
			'output'            => $run['output'] ?? null,
			'started_at'        => self::string_value( $run['started_at'] ?? null ),
			'updated_at'        => self::string_value( $run['updated_at'] ?? null ),
			'metadata'          => self::map_value( $run['metadata'] ?? array() ),
		);
	}

	/**
	 * Convert a task run/result payload to the canonical run result envelope.
	 *
	 * @param array<string,mixed> $run Raw or normalized run payload.
	 */
	public static function to_run_result_envelope( array $run ): WP_Agent_Run_Result_Envelope {
		$normalized  = self::normalize_run( $run );
		$task_status = self::string_value( $normalized['status'] );
		$status      = self::envelope_status( $task_status );

		return WP_Agent_Run_Result_Envelope::from_array(
			array(
				'run_id'        => self::string_value( $normalized['run_id'] ),
				'status'        => $status,
				'outputs'       => self::map_value( $normalized['output'] ?? array() ),
				'artifact_refs' => WP_Agent_Run_Result_Envelope::normalize_refs( $normalized['artifact_refs'] ?? array() ),
				'provenance'    => self::map_value( $normalized['provenance'] ?? array() ),
				'timestamps'    => array(
					'started_at' => $normalized['started_at'] ?? '',
					'updated_at' => $normalized['updated_at'] ?? '',
				),
				'error'         => self::error_envelope( $task_status, $normalized ),
				'cancellation'  => self::cancellation_envelope( $task_status ),
				'metadata'      => self::map_value( $normalized['metadata'] ?? array() ) + array(
					'session_id'        => $normalized['session_id'],
					'executor_id'       => $normalized['executor_id'],
					'execution_metrics' => $normalized['execution_metrics'],
					'diagnostics'       => $normalized['diagnostics'],
					'events'            => $normalized['events'],
				),
			)
		);
	}

	/** @param array<string,mixed> $run Raw or normalized run payload. */
	public static function to_canonical_envelope( array $run ): WP_Agent_Run_Result_Envelope {
		return self::to_run_result_envelope( $run );
	}

	/**
	 * Normalize status values while keeping the public vocabulary bounded.
	 */
	public static function normalize_status( mixed $status ): string {
		$status = is_string( $status ) ? strtolower( trim( $status ) ) : '';
		return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_RUNNING;
	}

	private static function envelope_status( string $status ): string {
		if ( self::STATUS_QUEUED === $status ) {
			return WP_Agent_Run_Result_Envelope::STATUS_QUEUED;
		}
		if ( self::STATUS_CANCELLING === $status ) {
			return WP_Agent_Run_Result_Envelope::STATUS_CANCELLING;
		}
		return WP_Agent_Run_Result_Envelope::normalize_status( $status );
	}

	/**
	 * Build the canonical error envelope for a failed run.
	 *
	 * Executors record failure details under `diagnostics` (see agents_run_task),
	 * so failed runs must surface that as the envelope's stable `error` field —
	 * mirroring how the workflow/runtime producers carry their own error map.
	 * Non-failed runs carry an empty error.
	 *
	 * @param string              $task_status Normalized task status.
	 * @param array<string,mixed> $normalized  Normalized run payload.
	 * @return array<string,mixed>
	 */
	private static function error_envelope( string $task_status, array $normalized ): array {
		if ( self::STATUS_FAILED !== $task_status ) {
			return array();
		}

		$diagnostics = self::map_value( $normalized['diagnostics'] ?? array() );
		$code        = self::non_empty_string_value( $diagnostics['error_code'] ?? null );
		$message     = self::non_empty_string_value( $diagnostics['error_message'] ?? null );

		$error = array(
			'code'    => $code ?? 'agents_task_run_failed',
			'message' => $message ?? 'The task run failed.',
		);
		if ( array() !== $diagnostics ) {
			$error['data'] = array( 'diagnostics' => $diagnostics );
		}

		return $error;
	}

	/**
	 * Build the cancellation envelope for a run whose cancellation was requested.
	 *
	 * @param string $task_status Normalized task status.
	 * @return array<string,mixed>
	 */
	private static function cancellation_envelope( string $task_status ): array {
		if ( ! in_array( $task_status, array( self::STATUS_CANCELLING, self::STATUS_CANCELLED ), true ) ) {
			return array();
		}

		return array(
			'requested' => true,
			'status'    => $task_status,
		);
	}

	/**
	 * Start an addressable task run in the default store.
	 *
	 * @param string              $run_id      Run ID.
	 * @param string              $session_id  Session ID.
	 * @param string              $executor_id Executor ID.
	 * @param array<string,mixed> $metadata    Run metadata.
	 * @param array<string,mixed> $owner       Owning principal tuple ({type,key}).
	 * @return array<string,mixed>|\WP_Error Normalized run, or an error when already claimed.
	 */
	public static function start_run( string $run_id, string $session_id, string $executor_id, array $metadata = array(), array $owner = array() ) {
		$now   = self::now();
		$state = self::state();
		$owner = self::owner_value( $owner );

		if ( isset( $state['runs'][ $run_id ] ) ) {
			return new \WP_Error( 'agents_task_run_already_started', 'The run_id has already been claimed for execution.' );
		}
		if ( function_exists( 'add_option' ) && ! add_option( self::claim_option_key( $run_id ), $now, '', false ) ) {
			return new \WP_Error( 'agents_task_run_already_started', 'The run_id has already been claimed for execution.' );
		}

		$run = array(
			'run_id'      => $run_id,
			'session_id'  => $session_id,
			'executor_id' => $executor_id,
			'status'      => self::STATUS_RUNNING,
			'owner'       => $owner,
			'started_at'  => $metadata['started_at'] ?? $now,
			'updated_at'  => $now,
			'metadata'    => $metadata,
		);

		$state['runs'][ $run_id ] = $run;
		self::save_state( $state );

		return self::normalize_run( $run );
	}

	/**
	 * Store a normalized executor result.
	 *
	 * @param array<string,mixed> $run Run/result payload.
	 * @return array<string,mixed> Normalized run.
	 */
	public static function save_run( array $run ): array {
		$normalized               = self::normalize_run( $run );
		$normalized['updated_at'] = '' !== $normalized['updated_at'] ? $normalized['updated_at'] : self::now();
		$run_id                   = self::string_value( $normalized['run_id'] );

		$state = self::state();

		// Ownership is server-bound at start_run and cannot be replaced by an executor.
		if ( isset( $state['runs'][ $run_id ]['owner'] ) ) {
			$normalized['owner'] = self::owner_value( $state['runs'][ $run_id ]['owner'] );
		}

		$state['runs'][ $run_id ] = $normalized;
		self::save_state( $state );

		return $normalized;
	}

	/**
	 * Read a stored task run.
	 *
	 * @param string $run_id Run ID.
	 * @return array<string,mixed>|null Normalized run, or null when absent.
	 */
	public static function get_run( string $run_id ): ?array {
		$state = self::state();
		$run   = $state['runs'][ $run_id ] ?? null;
		return is_array( $run ) ? self::normalize_run( $run ) : null;
	}

	/**
	 * Request cancellation of a stored task run.
	 *
	 * @param string $run_id Run ID.
	 * @return array<string,mixed>|null Normalized run, or null when absent.
	 */
	public static function request_cancel( string $run_id ): ?array {
		$state = self::state();
		if ( ! isset( $state['runs'][ $run_id ] ) ) {
			return null;
		}

		$run      = $state['runs'][ $run_id ];
		$terminal = in_array( self::normalize_status( $run['status'] ?? '' ), array( self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED ), true );

		$run['status']     = $terminal ? self::normalize_status( $run['status'] ?? '' ) : self::STATUS_CANCELLING;
		$run['cancelled']  = ! $terminal;
		$run['updated_at'] = self::now();

		$state['runs'][ $run_id ] = $run;
		self::save_state( $state );

		return self::normalize_run( $run ) + array( 'cancelled' => (bool) $run['cancelled'] );
	}

	/** @return array{runs:array<string,array<string,mixed>>} */
	private static function state(): array {
		$state = function_exists( 'get_option' ) ? get_option( self::OPTION_KEY, array() ) : array();
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array( 'runs' => self::stored_runs( $state['runs'] ?? array() ) );
	}

	private static function string_value( mixed $value ): string {
		return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (string) $value : '';
	}

	private static function claim_option_key( string $run_id ): string {
		return self::OPTION_KEY . '_claim_' . md5( $run_id );
	}

	/** @return array<string,mixed> */
	private static function map_value( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$map = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$map[ $key ] = $item;
			}
		}

		return $map;
	}

	/**
	 * Normalize a stored owning-principal tuple, dropping partial/invalid claims.
	 *
	 * @return array<string,mixed>
	 */
	private static function owner_value( mixed $value ): array {
		$owner = self::map_value( $value );
		$type  = self::non_empty_string_value( $owner['type'] ?? null );
		$key   = self::non_empty_string_value( $owner['key'] ?? null );
		if ( null === $type || null === $key ) {
			return array();
		}

		return array(
			'type' => $type,
			'key'  => $key,
		);
	}

	/** @return array<string,mixed> */
	private static function execution_metrics_value( mixed $value, string $executor_id ): array {
		$metrics = self::map_value( $value );
		if ( array() === $metrics ) {
			return array();
		}

		$metrics['schema'] = self::non_empty_string_value( $metrics['schema'] ?? null ) ?? 'agents-api/execution-metrics/v1';
		if ( ! isset( $metrics['executor_id'] ) && '' !== $executor_id ) {
			$metrics['executor_id'] = $executor_id;
		}

		foreach ( array( 'environment', 'executor_id', 'failure_class' ) as $field ) {
			if ( isset( $metrics[ $field ] ) ) {
				$value = self::non_empty_string_value( $metrics[ $field ] );
				if ( null === $value ) {
					unset( $metrics[ $field ] );
				} else {
					$metrics[ $field ] = $value;
				}
			}
		}

		foreach ( array( 'wall_time_ms', 'startup_time_ms', 'tool_call_count', 'payload_bytes_in', 'payload_bytes_out', 'artifact_bytes' ) as $field ) {
			if ( isset( $metrics[ $field ] ) ) {
				$value = self::non_negative_int_value( $metrics[ $field ] );
				if ( null === $value ) {
					unset( $metrics[ $field ] );
				} else {
					$metrics[ $field ] = $value;
				}
			}
		}

		if ( isset( $metrics['per_tool_timings_ms'] ) ) {
			$metrics['per_tool_timings_ms'] = self::non_negative_int_map_value( $metrics['per_tool_timings_ms'] );
		}
		if ( isset( $metrics['quality_signals'] ) ) {
			$metrics['quality_signals'] = self::map_value( $metrics['quality_signals'] );
		}
		if ( isset( $metrics['raw_refs'] ) ) {
			$metrics['raw_refs'] = self::list_value( $metrics['raw_refs'] );
		}

		return $metrics;
	}

	private static function non_empty_string_value( mixed $value ): ?string {
		$value = trim( self::string_value( $value ) );
		return '' === $value ? null : $value;
	}

	private static function non_negative_int_value( mixed $value ): ?int {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( $value ) ) ) {
			return null;
		}

		$value = (int) $value;
		return 0 <= $value ? $value : null;
	}

	/** @return array<string,int> */
	private static function non_negative_int_map_value( mixed $value ): array {
		$map        = self::map_value( $value );
		$normalized = array();
		foreach ( $map as $key => $item ) {
			$item = self::non_negative_int_value( $item );
			if ( null !== $item ) {
				$normalized[ $key ] = $item;
			}
		}

		return $normalized;
	}

	/** @return array<int,mixed> */
	private static function list_value( mixed $value ): array {
		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * @param mixed $runs Raw stored runs.
	 * @return array<string,array<string,mixed>>
	 */
	private static function stored_runs( mixed $runs ): array {
		if ( ! is_array( $runs ) ) {
			return array();
		}

		$stored = array();
		foreach ( $runs as $run_id => $run ) {
			if ( is_string( $run_id ) && is_array( $run ) ) {
				$stored[ $run_id ] = self::map_value( $run );
			}
		}

		return $stored;
	}

	/** @param array<string,mixed> $state State to persist. */
	private static function save_state( array $state ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $state, false );
		}
	}

	private static function now(): string {
		return gmdate( 'c' );
	}
}
