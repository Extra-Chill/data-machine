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

use AgentsAPI\AI\AgentMessageEnvelope;

defined( 'ABSPATH' ) || exit;

class ConversationManager {

	/**
	 * Build standardized conversation message envelope.
	 *
	 * Content can be a plain string (text-only messages) or an array of content
	 * blocks for multi-modal messages (text + images). When an array is provided,
	 * each element should follow the content block format expected by AI providers:
	 *
	 *     [
	 *         ['type' => 'text', 'text' => 'Describe this image'],
	 *         ['type' => 'file', 'file_path' => '/path/to/image.jpg', 'mime_type' => 'image/jpeg'],
	 *     ]
	 *
	 * RequestBuilder currently supports string content for provider dispatch; callers
	 * should pass array content only when the active runtime can translate it.
	 *
	 * @since 0.2.1
	 * @since 0.53.0 Accepts array content for multi-modal messages.
	 *
	 * @param string       $role     Role identifier (user, assistant, system).
	 * @param string|array $content  Message content — string for text, array for multi-modal content blocks.
	 * @param array        $metadata Optional metadata for the message (e.g., type, tool_data, attachments).
	 * @return array Message envelope.
	 */
	public static function buildConversationMessage( string $role, $content, array $metadata = array() ): array {
		return AgentMessageEnvelope::text( $role, $content, array_merge( array( 'timestamp' => gmdate( 'c' ) ), $metadata ) );
	}

	/**
	 * Build multi-modal content blocks from text and attachments.
	 *
	 * Takes a text message and an array of attachment metadata, and produces
	 * the content block array format expected by AI providers.
	 *
	 * @since 0.53.0
	 *
	 * @param string $text        The text portion of the message.
	 * @param array  $attachments Array of attachment metadata, each with:
	 *                            - url (string)        Required. Public URL of the media.
	 *                            - file_path (string)   Optional. Local filesystem path (preferred for Anthropic file uploads).
	 *                            - mime_type (string)   Optional. MIME type (auto-detected from URL if omitted).
	 *                            - type (string)        Optional. 'image', 'video', or 'file'. Auto-detected from mime_type.
	 *                            - media_id (int)       Optional. WordPress attachment ID.
	 *                            - filename (string)    Optional. Original filename.
	 * @return array Content blocks array suitable for buildConversationMessage().
	 */
	public static function buildMultiModalContent( string $text, array $attachments ): array {
		$content_blocks = array();

		// Text block first.
		if ( '' !== $text ) {
			$content_blocks[] = array(
				'type' => 'text',
				'text' => $text,
			);
		}

		foreach ( $attachments as $attachment ) {
			$url       = $attachment['url'] ?? '';
			$file_path = $attachment['file_path'] ?? '';
			$mime_type = $attachment['mime_type'] ?? '';

			// Skip empty attachments.
			if ( empty( $url ) && empty( $file_path ) ) {
				continue;
			}

			// Auto-detect MIME type from file path if available.
			if ( empty( $mime_type ) && ! empty( $file_path ) && file_exists( $file_path ) ) {
				$mime_type = mime_content_type( $file_path );
			}

			// Prefer local file path for providers that support direct file upload (Anthropic).
			if ( ! empty( $file_path ) && file_exists( $file_path ) ) {
				$content_blocks[] = array(
					'type'      => 'file',
					'file_path' => $file_path,
					'mime_type' => $mime_type,
				);
			} elseif ( ! empty( $url ) ) {
				// Fall back to URL-based image reference.
				$content_blocks[] = array(
					'type'      => 'image_url',
					'image_url' => array( 'url' => $url ),
				);
			}
		}

		return $content_blocks;
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

		return AgentMessageEnvelope::toolCall(
			$message,
			$tool_name,
			$tool_parameters,
			$turn_count,
			array( 'timestamp' => gmdate( 'c' ) )
		);
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

		$payload = array(
			'success' => $tool_result['success'] ?? false,
			'turn'    => $turn_count,
		);

		if ( ! empty( $tool_result['data'] ) ) {
			$payload['tool_data'] = $tool_result['data'];

			// Still append to content for AI context, but frontend can use metadata to hide it
			if ( ! $is_handler_tool ) {
				$content .= "\n\n" . wp_json_encode( $tool_result['data'] );
			}
		}

		// Propagate media attachments from tool results to message metadata.
		// Tools can include a 'media' array in their result to signal renderable
		// media (images, videos) that the frontend should display inline.
		if ( ! empty( $tool_result['media'] ) ) {
			$payload['media'] = $tool_result['media'];
		}

		if ( isset( $tool_result['error'] ) ) {
			$payload['error'] = $tool_result['error'];
		}

		return AgentMessageEnvelope::toolResult( $content, $tool_name, $payload, array( 'timestamp' => gmdate( 'c' ) ) );
	}

	/**
	 * Generate success or failure message from tool result.
	 *
	 * @param string $tool_name Tool identifier
	 * @param array  $tool_result Tool execution result
	 * @param array  $tool_parameters Original tool parameters
	 * @return string Human-readable success/failure message
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Kept for callers that pass original tool params alongside result data.
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
	 * Validate if a tool call is a duplicate of any previous tool call in the conversation.
	 *
	 * Scans the ENTIRE conversation history (not just the most recent tool call)
	 * to catch non-consecutive duplicates. For example: AI calls upsert_event(A),
	 * then some other tool, then upsert_event(A) again — the old logic only checked
	 * the immediately previous call and would miss this. This broader check prevents
	 * wasted AI credits on duplicate tool executions.
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

		// Scan ALL previous tool_call messages, not just the most recent one.
		for ( $i = count( $conversation_messages ) - 1; $i >= 0; $i-- ) {
			$message = AgentMessageEnvelope::normalize( $conversation_messages[ $i ] );

			if ( 'assistant' !== $message['role'] ) {
				continue;
			}

			if ( AgentMessageEnvelope::TYPE_TOOL_CALL !== $message['type'] ) {
				continue;
			}

			$prev_tool_name  = $message['payload']['tool_name'] ?? null;
			$prev_parameters = $message['payload']['parameters'] ?? null;

			if ( ! is_string( $prev_tool_name ) || ! is_array( $prev_parameters ) ) {
				continue;
			}

			if ( $prev_tool_name === $tool_name && $prev_parameters === $tool_parameters ) {
				$correction_message = "You already called the {$tool_name} tool with these exact parameters earlier in this conversation. That call already executed successfully. Do not retry — move on to the next step or end the conversation.";
				return array(
					'is_duplicate' => true,
					'message'      => $correction_message,
				);
			}
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
		$envelope = AgentMessageEnvelope::normalize( $message );

		if ( AgentMessageEnvelope::TYPE_TOOL_CALL === $envelope['type'] ) {
			$tool_name  = $envelope['payload']['tool_name'] ?? null;
			$parameters = $envelope['payload']['parameters'] ?? null;

			if ( is_string( $tool_name ) && is_array( $parameters ) ) {
				return array(
					'tool_name'  => $tool_name,
					'parameters' => $parameters,
				);
			}
		}

		if ( 'assistant' !== $envelope['role'] || ! isset( $envelope['content'] ) ) {
			return null;
		}

		$content = $envelope['content'];

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
	 * The correction message is mode-aware. Chat-mode conversations end after a
	 * duplicate (the tool's result IS the answer to the user's question), but
	 * pipeline-mode conversations still have downstream work — typically a
	 * publish handler tool — so the AI must be told to keep going. Bridge mode
	 * is treated like chat-with-continuation since the conversation is with a
	 * remote user. Unknown modes fall back to chat semantics.
	 *
	 * @param string $tool_name  Tool name that was duplicated
	 * @param int    $turn_count Current conversation turn
	 * @param string $mode       Execution mode ('chat', 'pipeline', 'bridge', ...). Defaults to 'chat'.
	 * @return array Formatted tool result message
	 */
	public static function generateDuplicateToolCallMessage(
		string $tool_name,
		int $turn_count = 0,
		string $mode = 'chat'
	): array {
		$tool_result = array(
			'success' => false,
			'error'   => self::buildDuplicateToolCallError( $tool_name, $mode ),
		);

		return self::formatToolResultMessage( $tool_name, $tool_result, array(), false, $turn_count );
	}

	/**
	 * Build the mode-appropriate correction text for a duplicate tool call.
	 *
	 * @param string $tool_name Tool name that was duplicated.
	 * @param string $mode      Execution mode slug.
	 * @return string Correction message routed to the AI.
	 */
	private static function buildDuplicateToolCallError( string $tool_name, string $mode ): string {
		switch ( $mode ) {
			case 'pipeline':
				return "DUPLICATE REJECTED: You already called {$tool_name} with these exact parameters earlier in this conversation. Do NOT call it again. Move on — call the publish handler tool now to complete this step.";
			case 'bridge':
				return "DUPLICATE REJECTED: You already called {$tool_name} with these exact parameters. Do NOT call it again. Continue your response to the user using the result you already have.";
			case 'chat':
			default:
				return "DUPLICATE REJECTED: You already called {$tool_name} with these exact parameters earlier in this conversation and it succeeded. Do NOT call it again. Do NOT call skip_item about this. The task is done — end the conversation.";
		}
	}

	/**
	 * Build a standard media entry for tool results.
	 *
	 * Tools that produce media (images, videos) should include a 'media'
	 * array in their result using this format. The frontend detects media
	 * entries in tool result metadata and renders them inline.
	 *
	 * Usage in a tool:
	 *
	 *     return [
	 *         'success' => true,
	 *         'media'   => [
	 *             ConversationManager::buildMediaEntry( 'image', $url, $alt_text ),
	 *         ],
	 *     ];
	 *
	 * @since 0.53.0
	 *
	 * @param string $type     Media type: 'image', 'video', or 'file'.
	 * @param string $url      Public URL of the media.
	 * @param string $alt      Alt text or description.
	 * @param int    $media_id Optional WordPress attachment ID.
	 * @return array Standard media entry.
	 */
	public static function buildMediaEntry( string $type, string $url, string $alt = '', int $media_id = 0 ): array {
		$entry = array(
			'type' => $type,
			'url'  => $url,
		);

		if ( '' !== $alt ) {
			$entry['alt'] = $alt;
		}

		if ( $media_id > 0 ) {
			$entry['media_id'] = $media_id;
		}

		return $entry;
	}
}
