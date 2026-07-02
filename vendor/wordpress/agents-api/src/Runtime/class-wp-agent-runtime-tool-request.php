<?php
/**
 * External runtime tool pending request contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes pending external/client tool requests.
 */
class WP_Agent_Runtime_Tool_Request {

	public const STATUS_PENDING   = 'runtime_tool_pending';
	public const STATUS_COMPLETED = 'runtime_tool_completed';
	public const STATUS_TIMEOUT   = 'runtime_tool_timeout';

	/**
	 * Normalize a pending runtime tool request.
	 *
	 * @param array<string, mixed> $request Raw request payload.
	 * @return array<string, mixed> Normalized request payload.
	 */
	public static function normalize( array $request ): array {
		$tool_name = $request['tool_name'] ?? '';
		if ( ! is_string( $tool_name ) || '' === trim( $tool_name ) ) {
			throw new \InvalidArgumentException( 'invalid_runtime_tool_request: tool_name must be a non-empty string' );
		}

		$tool_call_id = $request['tool_call_id'] ?? '';
		if ( ! is_string( $tool_call_id ) || '' === trim( $tool_call_id ) ) {
			throw new \InvalidArgumentException( 'invalid_runtime_tool_request: tool_call_id must be a non-empty string' );
		}

		$request_id = $request['request_id'] ?? '';
		if ( ! is_string( $request_id ) || '' === trim( $request_id ) ) {
			$request_id = self::request_id( $tool_name, $tool_call_id, $request['run_id'] ?? '' );
		}

		$parameters = $request['parameters'] ?? array();
		$runtime    = $request['runtime'] ?? array();
		$metadata   = $request['metadata'] ?? array();

		return array(
			'status'       => self::STATUS_PENDING,
			'request_id'   => $request_id,
			'tool_name'    => trim( $tool_name ),
			'tool_call_id' => trim( $tool_call_id ),
			'parameters'   => is_array( $parameters ) ? $parameters : array(),
			'run_id'       => is_string( $request['run_id'] ?? null ) ? $request['run_id'] : '',
			'timeout_at'   => is_string( $request['timeout_at'] ?? null ) ? $request['timeout_at'] : '',
			'runtime'      => is_array( $runtime ) ? $runtime : array(),
			'metadata'     => is_array( $metadata ) ? $metadata : array(),
		);
	}

	/**
	 * Build a pending request from a loop-mediated tool call.
	 *
	 * @param string               $tool_name    Tool identifier.
	 * @param string               $tool_call_id Tool call identifier.
	 * @param array<string, mixed> $parameters   Tool parameters.
	 * @param array<string, mixed> $context      Turn context.
	 * @param array<string, mixed> $runtime      Runtime metadata.
	 * @param array<string, mixed> $metadata     Host metadata.
	 * @return array<string, mixed> Normalized request payload.
	 */
	public static function from_tool_call( string $tool_name, string $tool_call_id, array $parameters, array $context = array(), array $runtime = array(), array $metadata = array() ): array {
		return self::normalize( array(
			'tool_name'    => $tool_name,
			'tool_call_id' => $tool_call_id,
			'parameters'   => $parameters,
			'run_id'       => is_string( $context['run_id'] ?? null ) ? $context['run_id'] : '',
			'timeout_at'   => is_string( $context['runtime_tool_timeout_at'] ?? null ) ? $context['runtime_tool_timeout_at'] : '',
			'runtime'      => $runtime,
			'metadata'     => $metadata,
		) );
	}

	/**
	 * Mark a normalized request as timed out without changing its identity.
	 *
	 * @param array<string, mixed> $request Raw or normalized runtime tool request.
	 * @return array<string, mixed> Timeout request payload.
	 */
	public static function timeout( array $request ): array {
		$normalized           = self::normalize( $request );
		$normalized['status'] = self::STATUS_TIMEOUT;

		return $normalized;
	}

	/**
	 * Build a stable request id when the host did not supply one.
	 *
	 * @param string $tool_name Tool name.
	 * @param string $tool_call_id Tool call id.
	 * @param mixed  $run_id Optional run id.
	 * @return string Request id.
	 */
	private static function request_id( string $tool_name, string $tool_call_id, $run_id ): string {
		$parts = array_filter( array( is_string( $run_id ) ? $run_id : '', $tool_name, $tool_call_id ) );
		return 'runtime-tool-' . hash( 'sha256', implode( '|', $parts ) );
	}
}
