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
class WP_Agent_Conversation_Result {

	public const SCHEMA  = 'agents-api.conversation-result';
	public const VERSION = 1;

	public const RUN_OUTCOME_SCHEMA  = WP_Agent_Run_Outcome::SCHEMA;
	public const RUN_OUTCOME_VERSION = WP_Agent_Run_Outcome::VERSION;

	public const OUTCOME_STATUS_COMPLETED            = WP_Agent_Run_Outcome::STATUS_COMPLETED;
	public const OUTCOME_STATUS_INCOMPLETE           = WP_Agent_Run_Outcome::STATUS_INCOMPLETE;
	public const OUTCOME_STATUS_FAILED               = WP_Agent_Run_Outcome::STATUS_FAILED;
	public const OUTCOME_STATUS_PENDING_RUNTIME_TOOL = WP_Agent_Run_Outcome::STATUS_RUNTIME_TOOL_PENDING;
	public const OUTCOME_STOP_NATURAL                = WP_Agent_Run_Outcome::STOP_NATURAL;
	public const OUTCOME_STOP_MAX_TURNS              = WP_Agent_Run_Outcome::STOP_MAX_TURNS;
	public const OUTCOME_STOP_PROVIDER_ERROR         = WP_Agent_Run_Outcome::STOP_PROVIDER_ERROR;

	/**
	 * Validate and normalize a loop result.
	 *
	 * `tool_execution_results` is optional because a valid no-tool response has
	 * no tool output; when omitted it normalizes to an empty list.
	 *
	 * @param array<mixed> $result Raw loop result.
	 * @return array<mixed> Normalized loop result.
	 * @throws \InvalidArgumentException When the result shape is invalid.
	 */
	public static function normalize( array $result ): array {
		if ( ! array_key_exists( 'schema', $result ) ) {
			$result['schema'] = self::SCHEMA;
		}

		if ( ! array_key_exists( 'version', $result ) ) {
			$result['version'] = self::VERSION;
		}

		if ( self::SCHEMA !== $result['schema'] ) {
			throw self::invalid( 'schema', 'must be ' . self::SCHEMA );
		}

		if ( ! is_int( $result['version'] ) || self::VERSION !== $result['version'] ) {
			throw self::invalid( 'version', 'must be ' . self::VERSION );
		}

		if ( ! array_key_exists( 'messages', $result ) || ! is_array( $result['messages'] ) ) {
			throw self::invalid( 'messages', 'must be an array' );
		}

		foreach ( $result['messages'] as $index => $message ) {
			$path = 'messages[' . $index . ']';

			if ( ! is_array( $message ) ) {
				throw self::invalid( $path, 'must be an array' );
			}

			try {
				$message = WP_Agent_Message::normalize( $message );
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

		if ( ! array_key_exists( 'tool_audit_events', $result ) ) {
			$result['tool_audit_events'] = array();
		}

		if ( ! array_key_exists( 'tool_events', $result ) ) {
			$result['tool_events'] = array();
		}

		if ( ! is_array( $result['tool_execution_results'] ) ) {
			throw self::invalid( 'tool_execution_results', 'must be an array' );
		}

		if ( ! is_array( $result['tool_audit_events'] ) ) {
			throw self::invalid( 'tool_audit_events', 'must be an array' );
		}

		if ( ! is_array( $result['tool_events'] ) ) {
			throw self::invalid( 'tool_events', 'must be an array' );
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

			if ( array_key_exists( 'runtime', $tool_result ) && ! is_array( $tool_result['runtime'] ) ) {
				throw self::invalid( $path . '.runtime', 'must be an array when present' );
			}

			if ( ! array_key_exists( 'turn_count', $tool_result ) || ! is_int( $tool_result['turn_count'] ) ) {
				throw self::invalid( $path . '.turn_count', 'must be an integer' );
			}
		}

		foreach ( $result['tool_audit_events'] as $index => $audit_event ) {
			$path = 'tool_audit_events[' . $index . ']';

			if ( ! is_array( $audit_event ) ) {
				throw self::invalid( $path, 'must be an array' );
			}

			foreach ( array( 'type', 'tool_name', 'tool_call_id', 'parameters_sha256', 'result_sha256' ) as $field ) {
				if ( ! array_key_exists( $field, $audit_event ) || ! is_string( $audit_event[ $field ] ) || '' === $audit_event[ $field ] ) {
					throw self::invalid( $path . '.' . $field, 'must be a non-empty string' );
				}
			}

			if ( ! array_key_exists( 'schema_version', $audit_event ) || ! is_int( $audit_event['schema_version'] ) ) {
				throw self::invalid( $path . '.schema_version', 'must be an integer' );
			}

			if ( ! array_key_exists( 'turn_count', $audit_event ) || ! is_int( $audit_event['turn_count'] ) ) {
				throw self::invalid( $path . '.turn_count', 'must be an integer' );
			}

			if ( ! array_key_exists( 'success', $audit_event ) || ! is_bool( $audit_event['success'] ) ) {
				throw self::invalid( $path . '.success', 'must be a boolean' );
			}

			if ( array_key_exists( 'error_type', $audit_event ) && ! is_string( $audit_event['error_type'] ) ) {
				throw self::invalid( $path . '.error_type', 'must be a string when present' );
			}
		}

		foreach ( $result['tool_events'] as $index => $tool_event ) {
			$path = 'tool_events[' . $index . ']';

			if ( ! is_array( $tool_event ) ) {
				throw self::invalid( $path, 'must be an array' );
			}

			foreach ( array( 'type', 'tool_name', 'tool_call_id' ) as $field ) {
				if ( ! array_key_exists( $field, $tool_event ) || ! is_string( $tool_event[ $field ] ) || '' === $tool_event[ $field ] ) {
					throw self::invalid( $path . '.' . $field, 'must be a non-empty string' );
				}
			}

			if ( ! array_key_exists( 'turn_count', $tool_event ) || ! is_int( $tool_event['turn_count'] ) ) {
				throw self::invalid( $path . '.turn_count', 'must be an integer' );
			}

			if ( array_key_exists( 'status', $tool_event ) && ! is_string( $tool_event['status'] ) ) {
				throw self::invalid( $path . '.status', 'must be a string when present' );
			}

			if ( array_key_exists( 'metadata', $tool_event ) && ! is_array( $tool_event['metadata'] ) ) {
				throw self::invalid( $path . '.metadata', 'must be an array when present' );
			}
		}

		// Validate optional budget-exceeded status fields.
		if ( array_key_exists( 'status', $result ) && ! is_string( $result['status'] ) ) {
			throw self::invalid( 'status', 'must be a string when present' );
		}

		if ( array_key_exists( 'budget', $result ) && ! is_string( $result['budget'] ) ) {
			throw self::invalid( 'budget', 'must be a string when present' );
		}

		// Validate optional observability fields surfaced by the loop.
		if ( array_key_exists( 'turn_count', $result ) && ! is_int( $result['turn_count'] ) ) {
			throw self::invalid( 'turn_count', 'must be an integer when present' );
		}

		if ( array_key_exists( 'final_content', $result ) && ! is_string( $result['final_content'] ) ) {
			throw self::invalid( 'final_content', 'must be a string when present' );
		}

		if ( array_key_exists( 'usage', $result ) && ! is_array( $result['usage'] ) ) {
			throw self::invalid( 'usage', 'must be an array when present' );
		}

		if ( array_key_exists( 'metadata', $result ) && ! is_array( $result['metadata'] ) ) {
			throw self::invalid( 'metadata', 'must be an array when present' );
		}

		if ( array_key_exists( 'request_metadata', $result ) && ! is_array( $result['request_metadata'] ) ) {
			throw self::invalid( 'request_metadata', 'must be an array when present' );
		}

		if ( array_key_exists( 'provider_diagnostics', $result ) && ! is_array( $result['provider_diagnostics'] ) ) {
			throw self::invalid( 'provider_diagnostics', 'must be an array when present' );
		}

		if ( array_key_exists( 'completed', $result ) && ! is_bool( $result['completed'] ) ) {
			throw self::invalid( 'completed', 'must be a boolean when present' );
		}

		if ( array_key_exists( 'failure', $result ) ) {
			if ( ! is_array( $result['failure'] ) ) {
				throw self::invalid( 'failure', 'must be an array when present' );
			}

			foreach ( array( 'type', 'message' ) as $field ) {
				if ( ! array_key_exists( $field, $result['failure'] ) || ! is_string( $result['failure'][ $field ] ) || '' === $result['failure'][ $field ] ) {
					throw self::invalid( 'failure.' . $field, 'must be a non-empty string' );
				}
			}

			if ( array_key_exists( 'turn_count', $result['failure'] ) && ! is_int( $result['failure']['turn_count'] ) ) {
				throw self::invalid( 'failure.turn_count', 'must be an integer when present' );
			}
		}

		if ( array_key_exists( 'runtime_tool_pending', $result ) && ! is_array( $result['runtime_tool_pending'] ) ) {
			throw self::invalid( 'runtime_tool_pending', 'must be an array when present' );
		}

		$result['run_outcome'] = WP_Agent_Run_Outcome::normalize( $result['run_outcome'] ?? null, $result );

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
