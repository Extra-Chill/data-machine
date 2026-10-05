<?php
/**
 * External runtime tool submitted result contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes client/transport-submitted runtime tool results.
 */
class WP_Agent_Runtime_Tool_Result {

	public const STATUS_SUBMITTED = 'runtime_tool_submitted';

	/**
	 * Normalize a submitted runtime tool result.
	 *
	 * @param array<string, mixed> $result Raw result payload.
	 * @return array<string, mixed> Normalized result payload.
	 */
	public static function normalize( array $result ): array {
		$request_id = $result['request_id'] ?? '';
		if ( ! is_string( $request_id ) || '' === trim( $request_id ) ) {
			throw new \InvalidArgumentException( 'invalid_runtime_tool_result: request_id must be a non-empty string' );
		}

		$tool_name = $result['tool_name'] ?? '';
		if ( ! is_string( $tool_name ) || '' === trim( $tool_name ) ) {
			throw new \InvalidArgumentException( 'invalid_runtime_tool_result: tool_name must be a non-empty string' );
		}

		$payload  = $result['result'] ?? array();
		$metadata = WP_Agent_Citation_Metadata::normalize_metadata( $result['metadata'] ?? array() );

		$normalized = array(
			'status'     => self::STATUS_SUBMITTED,
			'request_id' => trim( $request_id ),
			'tool_name'  => trim( $tool_name ),
			'success'    => (bool) ( $result['success'] ?? true ),
			'metadata'   => $metadata,
		);

		if ( $normalized['success'] ) {
			$normalized['result'] = is_array( $payload ) ? $payload : array( 'value' => $payload );
			return $normalized;
		}

		$error               = $result['error'] ?? 'Runtime tool execution failed.';
		$normalized['error'] = is_string( $error ) && '' !== trim( $error ) ? $error : 'Runtime tool execution failed.';

		return $normalized;
	}

	/**
	 * Normalize a submitted result against its stored pending request.
	 *
	 * @param array<string, mixed> $request Normalized runtime tool request.
	 * @param array<string, mixed> $result Raw submitted result.
	 * @return array<string, mixed> Normalized result payload.
	 */
	public static function from_request( array $request, array $result ): array {
		$request = WP_Agent_Runtime_Tool_Request::normalize( $request );

		return self::normalize( array_merge(
			$result,
			array(
				'request_id' => $result['request_id'] ?? $request['request_id'],
				'tool_name'  => $result['tool_name'] ?? $request['tool_name'],
			)
		) );
	}
}
