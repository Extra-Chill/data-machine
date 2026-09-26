<?php
/**
 * Tool execution result normalizer.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

use AgentsAPI\AI\WP_Agent_Citation_Metadata;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes tool execution results to a stable JSON-friendly shape.
 */
class WP_Agent_Tool_Result {

	/**
	 * Build a successful result.
	 *
	 * @param string $tool_name  Tool identifier.
	 * @param mixed  $result     Executor result payload.
	 * @param array<mixed>        $metadata Optional result metadata.
	 * @param array<string,mixed> $runtime  Optional runtime metadata.
	 * @return array<string, mixed>
	 */
	public static function success( string $tool_name, $result, array $metadata = array(), array $runtime = array() ): array {
		return self::normalize(
			array(
				'success'   => true,
				'tool_name' => $tool_name,
				'result'    => $result,
				'metadata'  => $metadata,
				'runtime'   => $runtime,
			)
		);
	}

	/**
	 * Build an error result.
	 *
	 * @param string $tool_name Tool identifier.
	 * @param string $error     Human-readable error.
	 * @param array<mixed>        $metadata Optional result metadata.
	 * @param array<string,mixed> $runtime  Optional runtime metadata.
	 * @return array<string, mixed>
	 */
	public static function error( string $tool_name, string $error, array $metadata = array(), array $runtime = array() ): array {
		return self::normalize(
			array(
				'success'   => false,
				'tool_name' => $tool_name,
				'error'     => $error,
				'metadata'  => $metadata,
				'runtime'   => $runtime,
			)
		);
	}

	/**
	 * Normalize arbitrary executor output.
	 *
	 * @param array<mixed> $result Raw result.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $result ): array {
		$tool_name = $result['tool_name'] ?? '';
		if ( ! is_string( $tool_name ) || '' === $tool_name ) {
			throw new \InvalidArgumentException( 'invalid_tool_execution_result: tool_name must be a non-empty string' );
		}

		$success = (bool) ( $result['success'] ?? false );
		$metadata = WP_Agent_Citation_Metadata::normalize_metadata( $result['metadata'] ?? array() );

		// Preserve a machine-readable error code. Executors may either place
		// error_type under metadata (the canonical home consumers read from) or
		// return it as a top-level field; promote a top-level code into metadata
		// so it survives normalization without clobbering an explicit metadata code.
		if ( ! isset( $metadata['error_type'] )
			&& isset( $result['error_type'] )
			&& is_string( $result['error_type'] )
			&& '' !== trim( $result['error_type'] )
		) {
			$metadata['error_type'] = trim( $result['error_type'] );
		}

		$runtime = WP_Agent_Tool_Declaration::normalizeRuntimeMetadata( $result['runtime'] ?? array() );

		$normalized = array(
			'success'   => $success,
			'tool_name' => $tool_name,
			'metadata'  => $metadata,
		);

		if ( ! empty( $runtime ) ) {
			$normalized['runtime'] = $runtime;
		}

		if ( isset( $result['status'] ) && is_string( $result['status'] ) && '' !== trim( $result['status'] ) ) {
			$normalized['status'] = trim( $result['status'] );
		}

		if ( isset( $result['runtime_tool_request'] ) && is_array( $result['runtime_tool_request'] ) ) {
			$normalized['runtime_tool_request'] = $result['runtime_tool_request'];
		}

		if ( $success ) {
			$normalized['result'] = $result['result'] ?? array();
			return $normalized;
		}

		$error = $result['error'] ?? 'Tool execution failed.';
		$normalized['error'] = is_string( $error ) && '' !== $error ? $error : 'Tool execution failed.';

		return $normalized;
	}
}
