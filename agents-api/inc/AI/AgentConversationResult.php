<?php
/**
 * Agent conversation result contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and normalizes agent conversation result arrays.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
class AgentConversationResult {

	/**
	 * Validate and normalize a loop result.
	 *
	 * `tool_execution_results` is optional because a valid no-tool response has
	 * no tool output; when omitted it normalizes to an empty list.
	 *
	 * @param array $result Raw loop result.
	 * @return array Normalized loop result.
	 * @throws \InvalidArgumentException When the result shape is invalid.
	 */
	public static function normalize( array $result ): array {
		if ( ! array_key_exists( 'messages', $result ) || ! is_array( $result['messages'] ) ) {
			throw self::invalid( 'messages', 'must be an array' );
		}

		foreach ( $result['messages'] as $index => $message ) {
			$path = 'messages[' . $index . ']';

			if ( ! is_array( $message ) ) {
				throw self::invalid( $path, 'must be an array' );
			}

			try {
				$message = AgentMessageEnvelope::normalize( $message );
			} catch ( \InvalidArgumentException $e ) {
				throw self::invalid( $path, $e->getMessage() );
			}

			$result['messages'][ $index ] = $message;

			if ( array_key_exists( 'role', $message ) && ! is_string( $message['role'] ) ) {
				throw self::invalid( $path . '.role', 'must be a string when present' );
			}
		}

		if ( ! array_key_exists( 'tool_execution_results', $result ) ) {
			$result['tool_execution_results'] = array();
		}

		if ( ! is_array( $result['tool_execution_results'] ) ) {
			throw self::invalid( 'tool_execution_results', 'must be an array' );
		}

		foreach ( $result['tool_execution_results'] as $index => $tool_result ) {
			$path = 'tool_execution_results[' . $index . ']';

			if ( ! is_array( $tool_result ) ) {
				throw self::invalid( $path, 'must be an array' );
			}

			if ( ! array_key_exists( 'tool_name', $tool_result ) || ! is_string( $tool_result['tool_name'] ) || '' === $tool_result['tool_name'] ) {
				throw self::invalid( $path . '.tool_name', 'must be a non-empty string' );
			}

			if ( ! array_key_exists( 'result', $tool_result ) || ! is_array( $tool_result['result'] ) ) {
				throw self::invalid( $path . '.result', 'must be an array' );
			}

			if ( ! array_key_exists( 'parameters', $tool_result ) || ! is_array( $tool_result['parameters'] ) ) {
				throw self::invalid( $path . '.parameters', 'must be an array' );
			}

			if ( array_key_exists( 'is_handler_tool', $tool_result ) && ! is_bool( $tool_result['is_handler_tool'] ) ) {
				throw self::invalid( $path . '.is_handler_tool', 'must be a boolean when present' );
			}

			if ( ! array_key_exists( 'turn_count', $tool_result ) || ! is_int( $tool_result['turn_count'] ) ) {
				throw self::invalid( $path . '.turn_count', 'must be an integer' );
			}
		}

		return $result;
	}

	/**
	 * Build a machine-readable validation exception.
	 *
	 * @param string $path Field path.
	 * @param string $reason Failure reason.
	 * @return \InvalidArgumentException Validation exception.
	 */
	private static function invalid( string $path, string $reason ): \InvalidArgumentException {
		return new \InvalidArgumentException( 'invalid_agent_conversation_result: ' . $path . ' ' . $reason );
	}
}
