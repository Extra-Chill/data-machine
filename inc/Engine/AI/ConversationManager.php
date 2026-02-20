<?php
/**
 * Universal AI conversation message building utilities.
 *
 * Provides standardized message formatting for all AI agents (pipeline and chat).
 * All methods are static with no state management.
 *
 * @package DataMachine\Engine\AI
 * @since 0.2.1
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class ConversationManager {

	/**
	 * Build standardized conversation message structure.
	 *
	 * @param string $role Role identifier (user, assistant, system)
	 * @param string $content Message content
	 * @param array  $metadata Optional metadata for the message (e.g., type, tool_data)
	 * @return array Message array with role, content, and metadata
	 */
	public static function buildConversationMessage( string $role, string $content, array $metadata = array() ): array {
		return array(
			'role'     => $role,
			'content'  => $content,
			'metadata' => array_merge( array( 'timestamp' => gmdate( 'c' ) ), $metadata ),
		);
	}

	/**
	 * Format tool call as conversation message with turn tracking.
	 *
	 * @param string $tool_name Tool identifier
	 * @param array  $tool_parameters Tool call parameters
	 * @param int    $turn_count Current conversation turn (0 = no turn display)
	 * @return array Formatted assistant message
	 */
	public static function formatToolCallMessage( string $tool_name, array $tool_parameters, int $turn_count ): array {
		$tool_display = ucwords( str_replace( '_', ' ', $tool_name ) );
		$message      = "AI ACTION (Turn {$turn_count}): Executing {$tool_display}";

		if ( ! empty( $tool_parameters ) ) {
			$params_str = array();
			foreach ( $tool_parameters as $key => $value ) {
				$params_str[] = "{$key}: " . ( is_string( $value ) ? $value : wp_json_encode( $value ) );
			}
			$message .= ' with parameters: ' . implode( ', ', $params_str );
		}

		$metadata = array(
			'type'       => 'tool_call',
			'tool_name'  => $tool_name,
			'parameters' => $tool_parameters,
			'turn'       => $turn_count,
		);

		return self::buildConversationMessage( 'assistant', $message, $metadata );
	}

	/**
	 * Format tool execution result as conversation message.
	 *
	 * @param string $tool_name Tool identifier
	 * @param array  $tool_result Tool execution result
	 * @param array  $tool_parameters Original tool parameters
	 * @param bool   $is_handler_tool Whether tool is handler-specific (affects data inclusion)
	 * @param int    $turn_count Current conversation turn (0 = no turn display)
	 * @return array Formatted user message
	 */
	public static function formatToolResultMessage( string $tool_name, array $tool_result, array $tool_parameters, bool $is_handler_tool = false, int $turn_count = 0 ): array {
		$human_message = self::generateSuccessMessage( $tool_name, $tool_result, $tool_parameters );

		if ( $turn_count > 0 ) {
			$content = "TOOL RESPONSE (Turn {$turn_count}): " . $human_message;
		} else {
			$content = $human_message;
		}

		$metadata = array(
			'type'      => 'tool_result',
			'tool_name' => $tool_name,
			'success'   => $tool_result['success'] ?? false,
			'turn'      => $turn_count,
		);

		if ( ! empty( $tool_result['data'] ) ) {
			$metadata['tool_data'] = $tool_result['data'];

			// Still append to content for AI context, but frontend can use metadata to hide it
			if ( ! $is_handler_tool ) {
				$content .= "\n\n" . wp_json_encode( $tool_result['data'] );
			}
		}

		if ( isset( $tool_result['error'] ) ) {
			$metadata['error'] = $tool_result['error'];
		}

		return self::buildConversationMessage( 'user', $content, $metadata );
	}

	/**
	 * Generate success or failure message from tool result.
	 *
	 * @param string $tool_name Tool identifier
	 * @param array  $tool_result Tool execution result
	 * @param array  $tool_parameters Original tool parameters
	 * @return string Human-readable success/failure message
	 */
	public static function generateSuccessMessage( string $tool_name, array $tool_result, array $tool_parameters ): string {
		$success = $tool_result['success'] ?? false;
		$data    = $tool_result['data'] ?? array();

		if ( ! $success ) {
			$error = $tool_result['error'] ?? 'Unknown error occurred';
			return "TOOL FAILED: {$tool_name} execution failed - {$error}";
		}

		// Use tool-provided message if available
		if ( ! empty( $data['message'] ) ) {
			$identifiers = self::extractKeyIdentifiers( $data );
			$prefix      = ! empty( $data['already_exists'] ) ? 'EXISTING' : 'SUCCESS';

			if ( ! empty( $identifiers ) ) {
				return "{$prefix}: {$identifiers}\n{$data['message']}";
			}

			return "{$prefix}: {$data['message']}";
		}

		// Default fallback for tools without custom message
		return 'SUCCESS: ' . ucwords( str_replace( '_', ' ', $tool_name ) ) . ' completed successfully.';
	}

	/**
	 * Extract key identifiers from tool result data for structured responses.
	 *
	 * @param array $data Tool result data
	 * @return string Formatted identifier string
	 */
	private static function extractKeyIdentifiers( array $data ): string {
		$parts = array();

		// Flow identifiers
		if ( isset( $data['flow_id'] ) ) {
			$name    = $data['flow_name'] ?? null;
			$parts[] = $name
				? "Flow \"{$name}\" (ID: {$data['flow_id']})"
				: "Flow ID: {$data['flow_id']}";
		}

		// Pipeline identifiers (only if no flow_id to avoid redundancy)
		if ( isset( $data['pipeline_id'] ) && ! isset( $data['flow_id'] ) ) {
			$name    = $data['pipeline_name'] ?? null;
			$parts[] = $name
				? "Pipeline \"{$name}\" (ID: {$data['pipeline_id']})"
				: "Pipeline ID: {$data['pipeline_id']}";
		}

		// Post identifiers
		if ( isset( $data['post_id'] ) ) {
			$parts[] = "Post ID: {$data['post_id']}";
		}

		// Job identifiers
		if ( isset( $data['job_id'] ) ) {
			$parts[] = "Job ID: {$data['job_id']}";
		}

		// Step counts
		if ( isset( $data['synced_steps'] ) ) {
			$parts[] = "{$data['synced_steps']} steps synced";
		}

		if ( isset( $data['steps_modified'] ) ) {
			$parts[] = "{$data['steps_modified']} steps modified";
		}

		return implode( ' | ', $parts );
	}

	/**
	 * Generate standardized failure message.
	 *
	 * @param string $tool_name Tool identifier
	 * @param string $error_message Error details
	 * @return string Formatted failure message
	 */
	public static function generateFailureMessage( string $tool_name, string $error_message ): string {
		$tool_display = ucwords( str_replace( '_', ' ', $tool_name ) );
		return "TOOL FAILED: {$tool_display} execution failed - {$error_message}. Please review the error and adjust your approach if needed.";
	}

	/**
	 * Validate if a tool call is a duplicate of the previous tool call.
	 *
	 * @param string $tool_name Tool name to validate
	 * @param array  $tool_parameters Tool parameters to validate
	 * @param array  $conversation_messages Conversation history
	 * @return array Validation result with is_duplicate and message
	 */
	public static function validateToolCall( string $tool_name, array $tool_parameters, array $conversation_messages ): array {
		if ( empty( $conversation_messages ) ) {
			return array(
				'is_duplicate' => false,
				'message'      => '',
			);
		}

		$previous_tool_call = null;
		for ( $i = count( $conversation_messages ) - 1; $i >= 0; $i-- ) {
			$message = $conversation_messages[ $i ];

			if ( 'assistant' !== $message['role'] ) {
				continue;
			}

			if ( ( $message['metadata']['type'] ?? null ) !== 'tool_call' ) {
				continue;
			}

			$prev_tool_name  = $message['metadata']['tool_name'] ?? null;
			$prev_parameters = $message['metadata']['parameters'] ?? null;

			if ( ! is_string( $prev_tool_name ) || ! is_array( $prev_parameters ) ) {
				continue;
			}

			$previous_tool_call = array(
				'tool_name'  => $prev_tool_name,
				'parameters' => $prev_parameters,
			);
			break;
		}

		if ( ! $previous_tool_call ) {
			return array(
				'is_duplicate' => false,
				'message'      => '',
			);
		}

		$is_duplicate = ( $previous_tool_call['tool_name'] === $tool_name ) &&
						( $previous_tool_call['parameters'] === $tool_parameters );

		if ( $is_duplicate ) {
			$correction_message = "You just called the {$tool_name} tool with the exact same parameters as your previous action. Please try a different approach or use different parameters instead.";
			return array(
				'is_duplicate' => true,
				'message'      => $correction_message,
			);
		}

		return array(
			'is_duplicate' => false,
			'message'      => '',
		);
	}

	/**
	 * Extract tool call details from a conversation message.
	 *
	 * Prefer metadata when available.
	 *
	 * @param array $message Conversation message
	 * @return array|null Tool call details or null if not a tool call message
	 */
	public static function extractToolCallFromMessage( array $message ): ?array {
		if ( ( $message['metadata']['type'] ?? null ) === 'tool_call' ) {
			$tool_name  = $message['metadata']['tool_name'] ?? null;
			$parameters = $message['metadata']['parameters'] ?? null;

			if ( is_string( $tool_name ) && is_array( $parameters ) ) {
				return array(
					'tool_name'  => $tool_name,
					'parameters' => $parameters,
				);
			}
		}

		if ( 'assistant' !== $message['role'] || ! isset( $message['content'] ) ) {
			return null;
		}

		$content = $message['content'];

		if ( ! preg_match( '/AI ACTION \(Turn \d+\): Executing (.+?)(?: with parameters: (.+))?$/', $content, $matches ) ) {
			return null;
		}

		$tool_display_name = trim( $matches[1] );
		$tool_name         = strtolower( str_replace( ' ', '_', $tool_display_name ) );

		$parameters = array();
		if ( isset( $matches[2] ) && ! empty( $matches[2] ) ) {
			$params_string = $matches[2];

			$param_pairs = explode( ', ', $params_string );
			foreach ( $param_pairs as $pair ) {
				if ( strpos( $pair, ': ' ) !== false ) {
					list($key, $value) = explode( ': ', $pair, 2 );
					$key               = trim( $key );
					$value             = trim( $value );

					$decoded = json_decode( $value, true );
					if ( json_last_error() === JSON_ERROR_NONE ) {
						$parameters[ $key ] = $decoded;
					} else {
						$parameters[ $key ] = $value;
					}
				}
			}
		}

		return array(
			'tool_name'  => $tool_name,
			'parameters' => $parameters,
		);
	}

	/**
	 * Generate a tool result message for duplicate tool call prevention.
	 *
	 * @param string $tool_name Tool name that was duplicated
	 * @param int    $turn_count Current conversation turn
	 * @return array Formatted tool result message
	 */
	public static function generateDuplicateToolCallMessage( string $tool_name, int $turn_count = 0 ): array {
		$tool_result = array(
			'success' => false,
			'error'   => 'Duplicate tool call - same parameters as previous action. Try a different approach.',
		);

		return self::formatToolResultMessage( $tool_name, $tool_result, array(), false, $turn_count );
	}
}

// AI library error logging - universal handler for all AI interactions (pipeline agents, chat agents)
add_action(
	'chubes_ai_library_error',
	function ( $error_data ) {
		do_action(
			'datamachine_log',
			'error',
			'AI Library Error: ' . $error_data['component'] . ' - ' . $error_data['message'],
			array(
				'component' => $error_data['component'],
				'message'   => $error_data['message'],
				'context'   => $error_data['context'],
				'timestamp' => $error_data['timestamp'],
			)
		);
	}
);
