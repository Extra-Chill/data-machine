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
	public const TOOL_OBSERVABILITY_VERSION          = 1;

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

		$result['tool_observability'] = self::tool_observability( $result['tool_events'], $result['tool_execution_results'] );
		$result['run_outcome'] = WP_Agent_Run_Outcome::normalize( $result['run_outcome'] ?? null, $result );

		return $result;
	}

	/**
	 * Project internal tool lifecycle data to a compact content-redacted contract.
	 *
	 * @param array<mixed> $events  Ordered tool lifecycle events.
	 * @param array<mixed> $results Tool execution results.
	 * @return array{version:int,calls:array<int,array<string,mixed>>}
	 */
	private static function tool_observability( array $events, array $results ): array {
		$calls       = array();
		$pending     = array();
		$result_uses = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$type         = $event['type'] ?? '';
			$tool_call_id = $event['tool_call_id'] ?? '';
			if ( ! is_string( $type ) || ! is_string( $tool_call_id ) || '' === $tool_call_id ) {
				continue;
			}

			if ( 'tool_call' === $type ) {
				$metadata   = is_array( $event['metadata'] ?? null ) ? $event['metadata'] : array();
				$parameters = is_array( $metadata['parameters'] ?? null ) ? $metadata['parameters'] : array();
				$keys       = array_map( 'strval', array_keys( $parameters ) );
				$calls[]    = array(
					'sequence'     => count( $calls ) + 1,
					'turn'         => is_int( $event['turn_count'] ?? null ) ? $event['turn_count'] : 0,
					'tool_call_id' => $tool_call_id,
					'tool_name'    => is_string( $event['tool_name'] ?? null ) ? $event['tool_name'] : '',
					'status'       => 'pending',
					'arguments'    => array(
						'keys'     => $keys,
						'count'    => count( $keys ),
						'redacted' => true,
					),
				);
				$pending[ $tool_call_id ][] = count( $calls ) - 1;
				continue;
			}

			if ( ! in_array( $type, array( 'tool_result', 'pending' ), true ) || empty( $pending[ $tool_call_id ] ) ) {
				continue;
			}

			if ( 'pending' === $type ) {
				continue;
			}
			$call_index = (int) array_shift( $pending[ $tool_call_id ] );

			$metadata = is_array( $event['metadata'] ?? null ) ? $event['metadata'] : array();
			$rejected = true === ( $metadata['rejected'] ?? false );
			$succeeded = true === ( $metadata['success'] ?? false );
			$calls[ $call_index ]['status'] = $rejected ? 'rejected' : ( $succeeded ? 'succeeded' : 'failed' );

			$result = self::matching_tool_result( $results, $tool_call_id, $result_uses );
			if ( is_array( $result ) ) {
				$execution_result = is_array( $result['result'] ?? null ) ? $result['result'] : array();
				$canonical_name   = $execution_result['tool_name'] ?? null;
				if ( is_string( $canonical_name ) && '' !== $canonical_name ) {
					$calls[ $call_index ]['tool_name'] = $canonical_name;
				}

				if ( $succeeded && array_key_exists( 'result', $execution_result ) ) {
					$calls[ $call_index ]['result'] = self::result_shape( $execution_result['result'] );
				}
			}

			if ( ! $succeeded ) {
				$calls[ $call_index ]['error'] = $rejected
					? array( 'code' => 'agents_api_tool_call_rejected', 'message' => 'Tool call was rejected.' )
					: array( 'code' => 'agents_api_tool_execution_failed', 'message' => 'Tool execution failed.' );
			}
		}

		return array(
			'version' => self::TOOL_OBSERVABILITY_VERSION,
			'calls'   => $calls,
		);
	}

	/**
	 * Find the next execution result for a tool-call id.
	 *
	 * @param array<mixed>       $results Tool results.
	 * @param string             $tool_call_id Tool call id.
	 * @param array<string, int> $uses Per-id result offsets.
	 * @return array<mixed>|null
	 */
	private static function matching_tool_result( array $results, string $tool_call_id, array &$uses ): ?array {
		$offset = $uses[ $tool_call_id ] ?? 0;
		$match  = 0;
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) || $tool_call_id !== ( $result['tool_call_id'] ?? null ) ) {
				continue;
			}
			if ( $match === $offset ) {
				$uses[ $tool_call_id ] = $offset + 1;
				return $result;
			}
			++$match;
		}

		return null;
	}

	/**
	 * Describe a result without exposing its content.
	 *
	 * @param mixed $value Result value.
	 * @return array<string,int|string>
	 */
	private static function result_shape( $value ): array {
		if ( is_array( $value ) ) {
			return array(
				'type'  => array_is_list( $value ) ? 'array' : 'object',
				'count' => count( $value ),
			);
		}

		if ( is_string( $value ) ) {
			return array( 'type' => 'string', 'size' => strlen( $value ) );
		}

		return array( 'type' => strtolower( gettype( $value ) ) );
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
