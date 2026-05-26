<?php
/**
 * Tool execution result contract helpers.
 *
 * @package DataMachine\Engine\AI\Tools
 */

namespace DataMachine\Engine\AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes AI tool execution results at Data Machine boundaries.
 */
class ToolExecutionResult {

	/**
	 * Normalize a tool execution result payload.
	 *
	 * @param mixed  $result    Raw tool result.
	 * @param string $tool_name Tool name for scalar fallback payloads.
	 * @return array<string,mixed>
	 */
	public static function normalizeResult( $result, string $tool_name = '' ): array {
		if ( ! is_array( $result ) ) {
			$normalized = array(
				'success' => true,
				'result'  => $result,
			);

			if ( '' !== $tool_name ) {
				$normalized['tool_name'] = $tool_name;
			}

			return $normalized;
		}

		$normalized = $result;
		if ( ! array_key_exists( 'success', $normalized ) ) {
			$normalized['success'] = ! isset( $normalized['error'] );
		}

		if ( '' !== $tool_name && ! isset( $normalized['tool_name'] ) ) {
			$normalized['tool_name'] = $tool_name;
		}

		return $normalized;
	}

	/**
	 * Normalize a tool_execution_results entry.
	 *
	 * @param mixed $entry Raw tool execution result entry.
	 * @return array<string,mixed>
	 */
	public static function normalizeEntry( $entry ): array {
		$entry = is_array( $entry ) ? $entry : array();

		$tool_name  = isset( $entry['tool_name'] ) ? (string) $entry['tool_name'] : '';
		$normalized = array(
			'tool_name'  => $tool_name,
			'result'     => self::normalizeResult( $entry['result'] ?? array(), $tool_name ),
			'parameters' => is_array( $entry['parameters'] ?? null ) ? $entry['parameters'] : array(),
			'turn_count' => isset( $entry['turn_count'] ) ? (int) $entry['turn_count'] : 0,
		);

		if ( array_key_exists( 'is_handler_tool', $entry ) ) {
			$normalized['is_handler_tool'] = true === $entry['is_handler_tool'];
		}

		foreach ( array( 'runtime', 'tool_call_id' ) as $optional_key ) {
			if ( array_key_exists( $optional_key, $entry ) ) {
				$normalized[ $optional_key ] = $entry[ $optional_key ];
			}
		}

		return $normalized;
	}

	/**
	 * Return the data payload from a normalized tool result envelope.
	 *
	 * @param array<string,mixed> $tool_result Normalized tool result envelope.
	 * @return mixed
	 */
	public static function dataPayload( array $tool_result ) {
		if ( array_key_exists( 'data', $tool_result ) ) {
			return $tool_result['data'];
		}

		if ( array_key_exists( 'result', $tool_result ) ) {
			return $tool_result['result'];
		}

		return $tool_result;
	}

	/**
	 * Build explicit packet metadata for both envelope and data payload consumers.
	 *
	 * @param array<string,mixed> $tool_result        Normalized tool result envelope.
	 * @param string             $compatibility_shape Shape exposed through legacy metadata.tool_result.
	 * @return array<string,mixed>
	 */
	public static function packetResultMetadata( array $tool_result, string $compatibility_shape ): array {
		$data_payload = self::dataPayload( $tool_result );

		return array(
			'tool_success'          => true === ( $tool_result['success'] ?? false ),
			'tool_result'           => 'data' === $compatibility_shape ? $data_payload : $tool_result,
			'tool_result_shape'     => 'data' === $compatibility_shape ? 'data' : 'envelope',
			'tool_result_envelope'  => $tool_result,
			'tool_result_data'      => $data_payload,
			'tool_result_contract'  => 'datamachine_tool_result_v1',
			'tool_result_data_path' => 'data',
		);
	}
}
