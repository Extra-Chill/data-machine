<?php
/**
 * Generic addressable run-control primitive.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Storage-neutral helpers for run status and cancellation state.
 */
class WP_Agent_Run_Control {

	public const STATUS_QUEUED               = 'queued';
	public const STATUS_RUNNING              = 'running';
	public const STATUS_CANCELLING           = 'cancelling';
	public const STATUS_CANCELLED            = 'cancelled';
	public const STATUS_COMPLETED            = 'completed';
	public const STATUS_SUCCEEDED            = 'succeeded';
	public const STATUS_FAILED               = 'failed';
	public const STATUS_RUNTIME_TOOL_PENDING = 'runtime_tool_pending';
	public const STATUS_APPROVAL_REQUIRED    = 'approval_required';
	public const STATUS_BUDGET_EXCEEDED      = 'budget_exceeded';
	public const STATUS_STALLED              = 'stalled';
	public const STATUS_INTERRUPTED          = 'interrupted';

	private static ?WP_Agent_Run_Control_Store $store = null;

	/** @return string[] */
	public static function statuses(): array {
		return array(
			self::STATUS_QUEUED,
			self::STATUS_RUNNING,
			self::STATUS_CANCELLING,
			self::STATUS_CANCELLED,
			self::STATUS_COMPLETED,
			self::STATUS_SUCCEEDED,
			self::STATUS_FAILED,
			self::STATUS_RUNTIME_TOOL_PENDING,
			self::STATUS_APPROVAL_REQUIRED,
			self::STATUS_BUDGET_EXCEEDED,
			self::STATUS_STALLED,
			self::STATUS_INTERRUPTED,
		);
	}

	/**
	 * Generate an opaque client-addressable run ID.
	 */
	public static function generate_run_id( string $prefix = 'run_' ): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return $prefix . str_replace( '-', '', wp_generate_uuid4() );
		}

		try {
			return $prefix . bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			return $prefix . str_replace( '.', '', uniqid( '', true ) );
		}
	}

	/**
	 * Normalize status values while keeping the public vocabulary bounded.
	 */
	public static function normalize_status( mixed $status ): string {
		$status = is_string( $status ) ? strtolower( trim( $status ) ) : '';
		return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_RUNNING;
	}

	/**
	 * Normalize a generic run payload.
	 *
	 * @param array<string,mixed> $run Raw run status.
	 * @return array<string,mixed>
	 */
	public static function normalize_run( array $run ): array {
		$run_id = trim( self::string_value( $run['run_id'] ?? null ) );
		$status = self::normalize_status( $run['status'] ?? self::STATUS_RUNNING );

		if ( '' === $run_id ) {
			throw new \InvalidArgumentException( 'run_id must be a non-empty string' );
		}

		$normalized = array(
			'run_id'     => $run_id,
			'status'     => $status,
			'started_at' => self::string_value( $run['started_at'] ?? null ),
			'updated_at' => self::string_value( $run['updated_at'] ?? null ),
			'metadata'   => isset( $run['metadata'] ) && is_array( $run['metadata'] ) ? $run['metadata'] : array(),
		);

		foreach ( array( 'session_id', 'workflow_id', 'executor_id', 'queued_message_id' ) as $field ) {
			if ( isset( $run[ $field ] ) ) {
				$normalized[ $field ] = self::string_value( $run[ $field ] );
			}
		}

		if ( isset( $run['position'] ) ) {
			$normalized['position'] = max( 0, self::int_value( $run['position'] ) );
		}

		if ( isset( $run['cancelled'] ) ) {
			$normalized['cancelled'] = (bool) $run['cancelled'];
		}

		return $normalized;
	}

	/**
	 * Normalize a handler result into the generic run envelope.
	 *
	 * @param mixed  $result     Handler result.
	 * @param string $error_code Error code for invalid results.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function normalize_run_result( mixed $result, string $error_code ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) ) {
			return new \WP_Error( $error_code, 'Run-control handlers must return an array or WP_Error.' );
		}

		try {
			return self::normalize_run( self::string_keyed_array( $result ) );
		} catch ( \InvalidArgumentException $error ) {
			return new \WP_Error( $error_code, $error->getMessage() );
		}
	}

	/**
	 * Normalize a cancellation result and infer whether the request was accepted.
	 *
	 * @param mixed  $result     Handler result.
	 * @param string $error_code Error code for invalid results.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function normalize_cancel_result( mixed $result, string $error_code ) {
		$result = self::normalize_run_result( $result, $error_code );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status              = self::normalize_status( $result['status'] ?? self::STATUS_RUNNING );
		$result['status']    = $status;
		$result['cancelled'] = (bool) ( $result['cancelled'] ?? in_array(
			$status,
			array(
				self::STATUS_CANCELLING,
				self::STATUS_CANCELLED,
			),
			true
		) );

		return $result;
	}

	/**
	 * Normalize an event page for an addressable run.
	 *
	 * @param mixed  $result     Handler result.
	 * @param string $error_code Error code for invalid results.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function normalize_events_result( mixed $result, string $error_code ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) ) {
			return new \WP_Error( $error_code, 'Run event handlers must return an array or WP_Error.' );
		}

		try {
			$run = self::normalize_run( self::string_keyed_array( $result ) );
		} catch ( \InvalidArgumentException $error ) {
			return new \WP_Error( $error_code, $error->getMessage() );
		}

		$events = array();
		foreach ( is_array( $result['events'] ?? null ) ? array_values( $result['events'] ) : array() as $event ) {
			if ( is_array( $event ) ) {
				$events[] = self::normalize_event( self::string_keyed_array( $event ) );
			}
		}

		$run['events']   = $events;
		$run['cursor']   = self::string_value( $result['cursor'] ?? '' );
		$run['has_more'] = (bool) ( $result['has_more'] ?? false );

		return $run;
	}

	/**
	 * @param array<string,mixed> $event Raw event.
	 * @return array<string,mixed>
	 */
	public static function normalize_event( array $event ): array {
		$normalized = array(
			'id'         => self::string_value( $event['id'] ?? '' ),
			'type'       => self::string_value( $event['type'] ?? '' ),
			'created_at' => self::string_value( $event['created_at'] ?? '' ),
			'metadata'   => isset( $event['metadata'] ) && is_array( $event['metadata'] ) ? self::string_keyed_array( $event['metadata'] ) : array(),
		);

		if ( isset( $event['message'] ) ) {
			$normalized['message'] = self::string_value( $event['message'] );
		}

		return $normalized;
	}

	/**
	 * Start or update an addressable run in the selected store.
	 *
	 * @param string              $store_key Option key used by the backing store.
	 * @param string              $run_id    Run ID.
	 * @param array<string,mixed> $run       Run fields.
	 * @return array<string,mixed>
	 */
	public static function start_run( string $store_key, string $run_id, array $run = array() ): array {
		$now = self::now();
		$run = array_merge(
			$run,
			array(
				'run_id'     => $run_id,
				'status'     => self::STATUS_RUNNING,
				'started_at' => $run['started_at'] ?? $now,
				'updated_at' => $now,
				'metadata'   => isset( $run['metadata'] ) && is_array( $run['metadata'] ) ? $run['metadata'] : array(),
			)
		);

		$state                    = self::state( $store_key );
		$state['runs'][ $run_id ] = $run;
		$state                    = self::record_event_in_state( $state, $run_id, 'run_started', array( 'status' => self::STATUS_RUNNING ) );
		self::save_state( $store_key, $state );

		return self::normalize_run( $run );
	}

	/**
	 * Store a normalized run result.
	 *
	 * @param string              $store_key Option key used by the backing store.
	 * @param array<string,mixed> $run       Run payload.
	 * @return array<string,mixed>
	 */
	public static function save_run( string $store_key, array $run ): array {
		$normalized               = self::normalize_run( $run );
		$normalized['updated_at'] = '' !== $normalized['updated_at'] ? $normalized['updated_at'] : self::now();
		$run_id                   = self::string_value( $normalized['run_id'] );

		$state                    = self::state( $store_key );
		$state['runs'][ $run_id ] = $normalized;
		$state                    = self::record_event_in_state( $state, $run_id, 'run_updated', array( 'status' => $normalized['status'] ) );
		self::save_state( $store_key, $state );

		return $normalized;
	}

	/**
	 * Finish a stored run.
	 *
	 * @param string $store_key Option key used by the backing store.
	 * @param string $run_id    Run ID.
	 * @param string $status    Terminal status.
	 * @return array<string,mixed>|null
	 */
	public static function finish_run( string $store_key, string $run_id, string $status = self::STATUS_COMPLETED ): ?array {
		$state = self::state( $store_key );
		if ( ! isset( $state['runs'][ $run_id ] ) ) {
			return null;
		}

		$run               = $state['runs'][ $run_id ];
		$run['status']     = self::normalize_status( $status );
		$run['updated_at'] = self::now();
		if ( self::STATUS_CANCELLED === $run['status'] ) {
			$run['cancelled'] = true;
		}

		$state['runs'][ $run_id ] = $run;
		$state                    = self::record_event_in_state( $state, $run_id, 'run_finished', array( 'status' => $run['status'] ) );
		self::save_state( $store_key, $state );

		return self::normalize_run( $run );
	}

	/**
	 * Read a stored run.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_run( string $store_key, string $run_id ): ?array {
		$state = self::state( $store_key );
		$run   = $state['runs'][ $run_id ] ?? null;
		return is_array( $run ) ? self::normalize_run( $run ) : null;
	}

	/**
	 * Request cancellation of a stored run.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function request_cancel( string $store_key, string $run_id ): ?array {
		$state = self::state( $store_key );
		if ( ! isset( $state['runs'][ $run_id ] ) ) {
			return null;
		}

		$run               = $state['runs'][ $run_id ];
		$terminal          = in_array( self::normalize_status( $run['status'] ?? '' ), array( self::STATUS_COMPLETED, self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_BUDGET_EXCEEDED, self::STATUS_STALLED, self::STATUS_INTERRUPTED ), true );
		$run['status']     = $terminal ? self::normalize_status( $run['status'] ?? '' ) : self::STATUS_CANCELLING;
		$run['cancelled']  = ! $terminal;
		$run['updated_at'] = self::now();

		$state['runs'][ $run_id ] = $run;
		$state                    = self::record_event_in_state( $state, $run_id, 'cancel_requested', array( 'status' => $run['status'] ) );
		self::save_state( $store_key, $state );

		return self::normalize_run( $run );
	}

	public static function cancel_requested( string $store_key, string $run_id ): bool {
		$run = self::get_run( $store_key, $run_id );
		return null !== $run && self::STATUS_CANCELLING === ( $run['status'] ?? '' );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function list_events( string $store_key, string $run_id, string $cursor = '', int $limit = 100 ): ?array {
		$run = self::get_run( $store_key, $run_id );
		if ( null === $run ) {
			return null;
		}

		$state  = self::state( $store_key );
		$events = array_values( $state['events'][ $run_id ] ?? array() );
		$offset = max( 0, self::int_value( $cursor ) );
		$limit  = max( 1, min( 500, $limit ) );
		$page   = array_slice( $events, $offset, $limit );
		$next   = $offset + count( $page );

		return array_merge(
			$run,
			array(
				'events'   => array_map( array( self::class, 'normalize_event' ), $page ),
				'cursor'   => $next < count( $events ) ? (string) $next : '',
				'has_more' => $next < count( $events ),
			)
		);
	}

	public static function store(): WP_Agent_Run_Control_Store {
		if ( null === self::$store ) {
			self::$store = new WP_Agent_Option_Run_Control_Store();
		}

		return self::$store;
	}

	public static function set_store( WP_Agent_Run_Control_Store $store ): void {
		self::$store = $store;
	}

	public static function reset_store(): void {
		self::$store = null;
	}

	/** @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} */
	public static function state( string $store_key ): array {
		return self::store()->get_state( $store_key );
	}

	/**
	 * @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state
	 */
	public static function save_state( string $store_key, array $state ): void {
		self::store()->save_state( $store_key, $state );
	}

	public static function now(): string {
		return gmdate( 'c' );
	}

	public static function string_value( mixed $value ): string {
		return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (string) $value : '';
	}

	/**
	 * @param array<array-key,mixed> $value Raw array.
	 * @return array<string,mixed>
	 */
	public static function string_keyed_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}

		return $result;
	}

	private static function int_value( mixed $value ): int {
		return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (int) $value : 0;
	}

	/**
	 * @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state
	 * @param array<string,mixed> $metadata Event metadata.
	 * @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}
	 */
	private static function record_event_in_state( array $state, string $run_id, string $type, array $metadata = array() ): array {
		$events         = array_values( $state['events'][ $run_id ] ?? array() );
		$events[]       = array(
			'id'         => $run_id . ':' . count( $events ),
			'type'       => $type,
			'created_at' => self::now(),
			'metadata'   => self::string_keyed_array( $metadata ),
		);
		$state['events'][ $run_id ] = $events;

		return $state;
	}
}
