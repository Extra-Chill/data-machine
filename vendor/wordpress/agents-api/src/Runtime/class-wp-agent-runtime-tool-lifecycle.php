<?php
/**
 * External runtime tool durable lifecycle service.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Product-neutral runtime-tool lifecycle operations.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
class WP_Agent_Runtime_Tool_Lifecycle {

	/**
	 * Persist a pending runtime tool request through a host-provided store.
	 *
	 * @param WP_Agent_Runtime_Tool_Request_Store $store Request store.
	 * @param array<string, mixed>                $request Raw or normalized request.
	 * @param array<string, mixed>                $context Caller-owned context.
	 * @return array<string, mixed> Normalized pending request.
	 */
	public static function create_pending_request( WP_Agent_Runtime_Tool_Request_Store $store, array $request, array $context = array() ): array {
		$normalized = WP_Agent_Runtime_Tool_Request::normalize( $request );
		$store->create( $normalized );

		do_action( 'agents_api_runtime_tool_request_created', $normalized, $context );

		return $normalized;
	}

	/**
	 * Submit a runtime tool result, complete the request, and optionally resume.
	 *
	 * @param WP_Agent_Runtime_Tool_Request_Store                    $store Request store.
	 * @param array<string, mixed>                                   $result Raw submitted result.
	 * @param WP_Agent_Runtime_Tool_Continuation|callable|null       $continuation Optional host continuation adapter.
	 * @param array<string, mixed>                                   $context Caller-owned context.
	 * @return array<string, mixed> Submission envelope.
	 */
	public static function submit_result( WP_Agent_Runtime_Tool_Request_Store $store, array $result, $continuation = null, array $context = array() ): array {
		$request_id = self::request_id_from_payload( $result, 'invalid_runtime_tool_result: request_id must be a non-empty string' );
		$completion = self::complete_if_pending( $store, $request_id, $result );

		$normalized_request = $completion['request'];
		$normalized_result  = $completion['result'];
		$is_duplicate       = $completion['duplicate'];

		if ( ! $is_duplicate ) {
			do_action( 'agents_api_runtime_tool_result_submitted', $normalized_request, $normalized_result, $context );
		}

		$envelope = array(
			'status'                => WP_Agent_Runtime_Tool_Result::STATUS_SUBMITTED,
			'request'               => $normalized_request,
			'result'                => $normalized_result,
			'duplicate'             => $is_duplicate,
			'tool_result_message'   => self::tool_result_message_payload( $normalized_request, $normalized_result ),
			'tool_execution_result' => self::tool_execution_result_payload( $normalized_request, $normalized_result ),
			'continuation_result'   => null,
		);

		if ( null !== $continuation && ! $is_duplicate ) {
			$envelope['continuation_result'] = self::resume( $continuation, $normalized_request, $normalized_result, $context );
		}

		return $envelope;
	}

	/**
	 * Complete a pending runtime tool request once, or return a retained result.
	 *
	 * Duplicate submissions for terminal records are idempotent only when the
	 * store can return the original submitted result from `get()` under `result`.
	 * Otherwise the duplicate is refused before any overwrite can occur.
	 *
	 * @param WP_Agent_Runtime_Tool_Request_Store $store Request store.
	 * @param string                              $request_id Runtime tool request id.
	 * @param array<string, mixed>                $result Raw submitted result.
	 * @return array{request: array<string, mixed>, result: array<string, mixed>, completed: bool, duplicate: bool} Completion envelope.
	 */
	public static function complete_if_pending( WP_Agent_Runtime_Tool_Request_Store $store, string $request_id, array $result ): array {
		$request_id = trim( $request_id );
		if ( '' === $request_id ) {
			throw new \InvalidArgumentException( 'invalid_runtime_tool_result: request_id must be a non-empty string' );
		}

		$request = $store->get( $request_id );
		if ( null === $request ) {
			throw new \InvalidArgumentException( 'invalid_runtime_tool_result: request not found' );
		}

		$status             = is_string( $request['status'] ?? null ) ? $request['status'] : '';
		$normalized_request = WP_Agent_Runtime_Tool_Request::normalize( $request );

		if ( '' !== $status && WP_Agent_Runtime_Tool_Request::STATUS_PENDING !== $status ) {
			$stored_result = self::stored_completion_result( $request, $normalized_request );
			if ( null === $stored_result ) {
				throw new \InvalidArgumentException( 'invalid_runtime_tool_result: request is not pending' );
			}

			return array(
				'request'   => $normalized_request,
				'result'    => $stored_result,
				'completed' => false,
				'duplicate' => true,
			);
		}

		$normalized_result = WP_Agent_Runtime_Tool_Result::from_request( $normalized_request, $result );
		$store->complete( self::string_field( $normalized_request, 'request_id' ), $normalized_result );

		return array(
			'request'   => $normalized_request,
			'result'    => $normalized_result,
			'completed' => true,
			'duplicate' => false,
		);
	}

	/**
	 * Mark a pending request timed out and optionally resume with a timeout result.
	 *
	 * @param WP_Agent_Runtime_Tool_Request_Store                    $store Request store.
	 * @param string                                                 $request_id Runtime tool request id.
	 * @param WP_Agent_Runtime_Tool_Continuation|callable|null       $continuation Optional host continuation adapter.
	 * @param array<string, mixed>                                   $context Caller-owned context.
	 * @return array<string, mixed> Timeout envelope.
	 */
	public static function timeout_request( WP_Agent_Runtime_Tool_Request_Store $store, string $request_id, $continuation = null, array $context = array() ): array {
		$request_id = trim( $request_id );
		if ( '' === $request_id ) {
			throw new \InvalidArgumentException( 'invalid_runtime_tool_timeout: request_id must be a non-empty string' );
		}

		$request = $store->get( $request_id );
		if ( null === $request ) {
			throw new \InvalidArgumentException( 'invalid_runtime_tool_timeout: request not found' );
		}

		$normalized_request = WP_Agent_Runtime_Tool_Request::normalize( $request );
		$timeout_request    = WP_Agent_Runtime_Tool_Request::timeout( $normalized_request );
		$timeout_result     = WP_Agent_Runtime_Tool_Result::from_request(
			$normalized_request,
			array(
				'success'  => false,
				'error'    => 'Runtime tool request timed out.',
				'metadata' => array( 'status' => WP_Agent_Runtime_Tool_Request::STATUS_TIMEOUT ),
			)
		);

		$store->timeout( self::string_field( $normalized_request, 'request_id' ) );

		do_action( 'agents_api_runtime_tool_request_timed_out', $timeout_request, $timeout_result, $context );

		$envelope = array(
			'status'                => WP_Agent_Runtime_Tool_Request::STATUS_TIMEOUT,
			'request'               => $timeout_request,
			'result'                => $timeout_result,
			'tool_result_message'   => self::tool_result_message_payload( $normalized_request, $timeout_result ),
			'tool_execution_result' => self::tool_execution_result_payload( $normalized_request, $timeout_result ),
			'continuation_result'   => null,
		);

		if ( null !== $continuation ) {
			$envelope['continuation_result'] = self::resume( $continuation, $normalized_request, $timeout_result, $context );
		}

		return $envelope;
	}

	/**
	 * Read recent pending requests from the configured store.
	 *
	 * @param WP_Agent_Runtime_Tool_Request_Store $store Request store.
	 * @param array<string, mixed>                $query Product-neutral query hints.
	 * @return array<int, array<string, mixed>> Normalized pending requests.
	 */
	public static function recent_pending_requests( WP_Agent_Runtime_Tool_Request_Store $store, array $query = array() ): array {
		$requests = array();
		foreach ( $store->recent_pending( $query ) as $request ) {
			$requests[] = WP_Agent_Runtime_Tool_Request::normalize( $request );
		}

		return $requests;
	}

	/**
	 * Resume through a host continuation adapter.
	 *
	 * @param WP_Agent_Runtime_Tool_Continuation|callable $continuation Host adapter.
	 * @param array<string, mixed>                        $request Normalized request.
	 * @param array<string, mixed>                        $result Normalized result.
	 * @param array<string, mixed>                        $context Caller-owned context.
	 * @return array<string, mixed> Host-owned resume result.
	 */
	private static function resume( $continuation, array $request, array $result, array $context ): array {
		if ( $continuation instanceof WP_Agent_Runtime_Tool_Continuation ) {
			$resume_result = $continuation->resume( $request, $result, $context );
		} elseif ( is_callable( $continuation ) ) {
			$resume_result = call_user_func( $continuation, $request, $result, $context );
		} else {
			throw new \InvalidArgumentException( 'invalid_runtime_tool_continuation: continuation must be callable or implement WP_Agent_Runtime_Tool_Continuation' );
		}

		if ( ! is_array( $resume_result ) ) {
			throw new \InvalidArgumentException( 'invalid_runtime_tool_continuation: resume result must be an array' );
		}

		$resume_result = self::normalize_assoc_array( $resume_result );

		do_action( 'agents_api_runtime_tool_request_resumed', $request, $result, $resume_result, $context );

		return $resume_result;
	}

	/**
	 * Return a retained normalized completion result from a terminal request.
	 *
	 * @param array<string, mixed> $request Raw stored request.
	 * @param array<string, mixed> $normalized_request Normalized request identity.
	 * @return array<string, mixed>|null Normalized prior result when available.
	 */
	private static function stored_completion_result( array $request, array $normalized_request ): ?array {
		if ( ! isset( $request['result'] ) || ! is_array( $request['result'] ) ) {
			return null;
		}

		/** @var array<string, mixed> $stored_result */
		$stored_result = $request['result'];

		return WP_Agent_Runtime_Tool_Result::from_request( $normalized_request, $stored_result );
	}

	/**
	 * Build the transcript-compatible tool-result message payload.
	 *
	 * @param array<string, mixed> $request Normalized request.
	 * @param array<string, mixed> $result Normalized result.
	 * @return array<string, mixed> Tool-result message data.
	 */
	private static function tool_result_message_payload( array $request, array $result ): array {
		return array(
			'tool_name'    => self::string_field( $request, 'tool_name' ),
			'tool_call_id' => self::string_field( $request, 'tool_call_id' ),
			'success'      => (bool) ( $result['success'] ?? false ),
			'status'       => self::string_field( $result, 'status' ),
			'result'       => $result,
		);
	}

	/**
	 * Build the loop-compatible tool execution result payload.
	 *
	 * @param array<string, mixed> $request Normalized request.
	 * @param array<string, mixed> $result Normalized result.
	 * @return array<string, mixed> Tool execution result data.
	 */
	private static function tool_execution_result_payload( array $request, array $result ): array {
		$metadata   = isset( $request['metadata'] ) && is_array( $request['metadata'] ) ? $request['metadata'] : array();
		$turn_count = isset( $metadata['turn_count'] ) && is_int( $metadata['turn_count'] ) ? $metadata['turn_count'] : 0;

		return array(
			'tool_name'    => self::string_field( $request, 'tool_name' ),
			'tool_call_id' => self::string_field( $request, 'tool_call_id' ),
			'parameters'   => is_array( $request['parameters'] ?? null ) ? $request['parameters'] : array(),
			'result'       => $result,
			'runtime'      => is_array( $request['runtime'] ?? null ) ? $request['runtime'] : array(),
			'turn_count'   => $turn_count,
		);
	}

	/**
	 * Extract a required request id from a payload.
	 *
	 * @param array<string, mixed> $payload Raw payload.
	 * @param string               $message Validation message.
	 * @return string Request id.
	 */
	private static function request_id_from_payload( array $payload, string $message ): string {
		$request_id = $payload['request_id'] ?? '';
		if ( ! is_string( $request_id ) || '' === trim( $request_id ) ) {
			throw new \InvalidArgumentException( $message );
		}

		return trim( $request_id );
	}

	/**
	 * Read a required string field from a normalized payload.
	 *
	 * @param array<string, mixed> $payload Normalized payload.
	 * @param string               $field Field name.
	 * @return string Field value.
	 */
	private static function string_field( array $payload, string $field ): string {
		$value = $payload[ $field ] ?? '';
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Normalize a mixed-key array into an associative string-key array.
	 *
	 * @param array<mixed, mixed> $value Raw array.
	 * @return array<string, mixed> Normalized array.
	 */
	private static function normalize_assoc_array( array $value ): array {
		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $item;
			}
		}

		return $normalized;
	}
}
