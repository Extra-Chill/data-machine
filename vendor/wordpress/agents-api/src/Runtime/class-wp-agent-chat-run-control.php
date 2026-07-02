<?php
/**
 * Generic chat run-control primitives.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for canonical chat run-control contracts.
 */
class WP_Agent_Chat_Run_Control {

	public const STATUS_QUEUED               = 'queued';
	public const STATUS_RUNNING              = 'running';
	public const STATUS_CANCELLING           = 'cancelling';
	public const STATUS_CANCELLED            = 'cancelled';
	public const STATUS_COMPLETED            = 'completed';
	public const STATUS_FAILED               = 'failed';
	public const STATUS_RUNTIME_TOOL_PENDING = 'runtime_tool_pending';
	public const STATUS_APPROVAL_REQUIRED    = 'approval_required';
	public const STATUS_BUDGET_EXCEEDED      = 'budget_exceeded';
	public const STATUS_STALLED              = 'stalled';
	public const STATUS_INTERRUPTED          = 'interrupted';
	private const OPTION_KEY                 = 'agents_api_chat_run_control';

	/** @return string[] */
	public static function statuses(): array {
		return array(
			self::STATUS_QUEUED,
			self::STATUS_RUNNING,
			self::STATUS_CANCELLING,
			self::STATUS_CANCELLED,
			self::STATUS_COMPLETED,
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
	public static function generate_run_id(): string {
		return WP_Agent_Run_Control::generate_run_id();
	}

	/**
	 * Normalize a run status payload returned by a runtime.
	 *
	 * @param array<string,mixed> $run Raw run status.
	 * @return array<string,mixed>
	 */
	public static function normalize_run( array $run ): array {
		$run_id     = trim( self::string_value( $run['run_id'] ?? null ) );
		$session_id = trim( self::string_value( $run['session_id'] ?? null ) );
		$status     = self::normalize_status( $run['status'] ?? self::STATUS_RUNNING );

		if ( '' === $run_id ) {
			throw new \InvalidArgumentException( 'run_id must be a non-empty string' );
		}

		if ( '' === $session_id ) {
			throw new \InvalidArgumentException( 'session_id must be a non-empty string' );
		}

		$normalized = array(
			'run_id'     => $run_id,
			'session_id' => $session_id,
			'status'     => $status,
			'started_at' => self::string_value( $run['started_at'] ?? null ),
			'updated_at' => self::string_value( $run['updated_at'] ?? null ),
			'metadata'   => isset( $run['metadata'] ) && is_array( $run['metadata'] ) ? $run['metadata'] : array(),
		);

		if ( isset( $run['queued_message_id'] ) ) {
			$normalized['queued_message_id'] = self::string_value( $run['queued_message_id'] );
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
	 * Normalize status values while keeping the public vocabulary bounded.
	 */
	public static function normalize_status( mixed $status ): string {
		$status = WP_Agent_Run_Control::normalize_status( $status );
		return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_RUNNING;
	}

	/**
	 * Start or update an addressable chat run in the default store.
	 *
	 * @param string              $run_id     Run ID.
	 * @param string              $session_id Session ID.
	 * @param array<string,mixed> $metadata   Run metadata.
	 * @return array<string,mixed> Normalized run.
	 */
	public static function start_run( string $run_id, string $session_id, array $metadata = array() ): array {
		return self::normalize_run( WP_Agent_Run_Control::start_run(
			self::OPTION_KEY,
			$run_id,
			array(
				'session_id' => $session_id,
				'metadata'   => $metadata,
			)
		) );
	}

	/**
	 * Complete a stored chat run.
	 *
	 * @param string $run_id Run ID.
	 * @param string $status Terminal status.
	 * @return array<string,mixed>|null Normalized run, or null when absent.
	 */
	public static function finish_run( string $run_id, string $status = self::STATUS_COMPLETED ): ?array {
		$run = WP_Agent_Run_Control::finish_run( self::OPTION_KEY, $run_id, $status );
		return null === $run ? null : self::normalize_run( $run );
	}

	/**
	 * Read a stored chat run.
	 *
	 * @param string $run_id Run ID.
	 * @return array<string,mixed>|null Normalized run, or null when absent.
	 */
	public static function get_run( string $run_id ): ?array {
		$run = WP_Agent_Run_Control::get_run( self::OPTION_KEY, $run_id );
		return null === $run ? null : self::normalize_run( $run );
	}

	/**
	 * Request cancellation of a stored chat run.
	 *
	 * @param string $run_id Run ID.
	 * @return array<string,mixed>|null Normalized run, or null when absent.
	 */
	public static function request_cancel( string $run_id ): ?array {
		$run = WP_Agent_Run_Control::request_cancel( self::OPTION_KEY, $run_id );
		return null === $run ? null : self::normalize_run( $run );
	}

	/**
	 * Return a cancellation interrupt when one was requested for the run.
	 *
	 * @param string $run_id     Run ID.
	 * @param string $session_id Session ID.
	 * @return array<string,mixed>|null Interrupt message or null.
	 */
	public static function cancellation_interrupt_for_run( string $run_id, string $session_id = '' ): ?array {
		$run = self::get_run( $run_id );
		if ( null === $run || self::STATUS_CANCELLING !== $run['status'] ) {
			return null;
		}

		$resolved_session_id = '' !== $session_id ? $session_id : self::string_value( $run['session_id'] ?? null );

		return self::cancellation_interrupt_message( $run_id, $resolved_session_id );
	}

	/**
	 * Queue a follow-up message for a chat session.
	 *
	 * @param array<string,mixed> $input Canonical queue input.
	 * @return array<string,mixed> Queue result.
	 */
	public static function queue_message( array $input ): array {
		$session_id = trim( self::string_value( $input['session_id'] ?? null ) );
		$run_id     = trim( self::string_value( $input['run_id'] ?? null ) );
		if ( '' === $session_id ) {
			throw new \InvalidArgumentException( 'session_id must be a non-empty string' );
		}

		$queued_id = 'queued_' . str_replace( 'run_', '', self::generate_run_id() );
		$item      = array(
			'queued_message_id' => $queued_id,
			'session_id'        => $session_id,
			'run_id'            => $run_id,
			'agent'             => sanitize_title( self::string_value( $input['agent'] ?? null ) ),
			'message'           => self::string_value( $input['message'] ?? null ),
			'attachments'       => is_array( $input['attachments'] ?? null ) ? $input['attachments'] : array(),
			'client_context'    => is_array( $input['client_context'] ?? null ) ? $input['client_context'] : array(),
			'created_at'        => self::now(),
		);

		$state                            = self::state();
		$state['queues'][ $session_id ]   = array_values( $state['queues'][ $session_id ] ?? array() );
		$state['queues'][ $session_id ][] = $item;
		$position                         = count( $state['queues'][ $session_id ] );
		self::save_state( $state );

		return self::normalize_run( array(
			'run_id'            => '' !== $run_id ? $run_id : self::generate_run_id(),
			'session_id'        => $session_id,
			'status'            => self::STATUS_QUEUED,
			'updated_at'        => self::now(),
			'queued_message_id' => $queued_id,
			'position'          => $position,
		) );
	}

	/**
	 * Claim queued messages for a session.
	 *
	 * @param string $session_id Session ID.
	 * @return array<int,array<string,mixed>> Queued items.
	 */
	public static function claim_queued_messages( string $session_id ): array {
		$state = self::state();
		$items = array_values( $state['queues'][ $session_id ] ?? array() );
		unset( $state['queues'][ $session_id ] );
		self::save_state( $state );
		return $items;
	}

	/**
	 * Build the interrupt message shape consumed by WP_Agent_Conversation_Loop.
	 *
	 * Runtimes that cannot abort an in-flight provider request immediately can
	 * persist this message for their loop-level `interrupt_source` to return.
	 *
	 * @param string              $run_id     Run to cancel.
	 * @param string              $session_id Session containing the run.
	 * @param array<string,mixed> $metadata   Additional runtime metadata.
	 * @return array<string,mixed>
	 */
	public static function cancellation_interrupt_message(
		string $run_id,
		string $session_id = '',
		array $metadata = array()
	): array {
		return WP_Agent_Message::text(
			'user',
			'Cancel this run.',
			array_merge(
				$metadata,
				array(
					'type'             => 'chat_run_interrupt',
					'interrupt_action' => 'cancel',
					'run_id'           => $run_id,
					'session_id'       => $session_id,
				)
			)
		);
	}

	/** @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>} */
	private static function state(): array {
		$state = function_exists( 'get_option' ) ? get_option( self::OPTION_KEY, array() ) : array();
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array(
			'runs'   => self::stored_runs( $state['runs'] ?? array() ),
			'queues' => self::stored_queues( $state['queues'] ?? array() ),
		);
	}

	private static function string_value( mixed $value ): string {
		return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (string) $value : '';
	}

	private static function int_value( mixed $value ): int {
		return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (int) $value : 0;
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
				$stored[ $run_id ] = self::assoc_array( $run );
			}
		}

		return $stored;
	}

	/**
	 * @param mixed $queues Raw stored queues.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function stored_queues( mixed $queues ): array {
		if ( ! is_array( $queues ) ) {
			return array();
		}

		$stored = array();
		foreach ( $queues as $session_id => $items ) {
			if ( ! is_string( $session_id ) || ! is_array( $items ) ) {
				continue;
			}

			$stored[ $session_id ] = array();
			foreach ( $items as $item ) {
				if ( is_array( $item ) ) {
					$stored[ $session_id ][] = self::assoc_array( $item );
				}
			}
		}

		return $stored;
	}

	/**
	 * @param array<mixed> $value Raw array.
	 * @return array<string,mixed>
	 */
	private static function assoc_array( array $value ): array {
		$assoc = array();
		foreach ( $value as $field => $field_value ) {
			if ( is_string( $field ) ) {
				$assoc[ $field ] = $field_value;
			}
		}

		return $assoc;
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
