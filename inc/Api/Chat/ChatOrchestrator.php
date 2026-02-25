<?php
/**
 * Chat Orchestrator
 *
 * AI conversation orchestration extracted from Chat.php. Handles the
 * multi-step business logic for chat, continue, and ping flows:
 * session lifecycle, conversation turn execution, and error persistence.
 *
 * This is intentionally NOT an ability — it coordinates multiple operations
 * (AgentContext, ToolManager, AIConversationLoop, session updates) and has
 * side effects. Composition happens here; flat primitives live in abilities.
 *
 * @package DataMachine\Api\Chat
 * @since 0.31.0
 */

namespace DataMachine\Api\Chat;

use DataMachine\Core\Database\Chat\Chat as ChatDatabase;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\ConversationManager;
use DataMachine\Engine\AI\AIConversationLoop;
use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\AgentType;
use DataMachine\Engine\AI\AgentContext;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class ChatOrchestrator {

	/**
	 * Process a new chat message.
	 *
	 * Handles session resolution (existing, pending dedup, or new), persists
	 * the user message, executes the AI conversation turn, updates session
	 * state, and triggers title generation for new sessions.
	 *
	 * @since 0.31.0
	 *
	 * @param string $message              User message text.
	 * @param string $provider             AI provider identifier.
	 * @param string $model                AI model identifier.
	 * @param int    $user_id              Current user ID.
	 * @param array  $options {
	 *     Optional settings.
	 *
	 *     @type string $session_id           Existing session ID to continue.
	 *     @type int    $selected_pipeline_id Currently selected pipeline ID.
	 *     @type int    $max_turns            Maximum turns allowed.
	 *     @type string $request_id           Idempotency request ID.
	 * }
	 * @return array|WP_Error Response data array or WP_Error on failure.
	 */
	public static function processChat(
		string $message,
		string $provider,
		string $model,
		int $user_id,
		array $options = array()
	): array|WP_Error {
		$session_id           = $options['session_id'] ?? null;
		$selected_pipeline_id = (int) ( $options['selected_pipeline_id'] ?? 0 );
		$max_turns            = $options['max_turns'] ?? PluginSettings::get( 'max_turns', 12 );
		$request_id           = $options['request_id'] ?? null;

		$chat_db = new ChatDatabase();

		// --- Session resolution ---
		if ( $session_id ) {
			$session = $chat_db->get_session( $session_id );

			if ( ! $session ) {
				return new WP_Error(
					'session_not_found',
					__( 'Session not found', 'data-machine' ),
					array( 'status' => 404 )
				);
			}

			if ( (int) $session['user_id'] !== $user_id ) {
				return new WP_Error(
					'session_access_denied',
					__( 'Access denied to this session', 'data-machine' ),
					array( 'status' => 403 )
				);
			}

			$messages = $session['messages'];
		} else {
			// Check for recent pending session to prevent duplicates from timeout retries.
			$pending_session = $chat_db->get_recent_pending_session( $user_id, 600, AgentType::CHAT );

			if ( $pending_session ) {
				$session_id = $pending_session['session_id'];
				$messages   = $pending_session['messages'];

				do_action(
					'datamachine_log',
					'info',
					'Chat: Reusing pending session (deduplication)',
					array(
						'session_id'          => $session_id,
						'user_id'             => $user_id,
						'original_created_at' => $pending_session['created_at'],
						'agent_type'          => AgentType::CHAT,
					)
				);
			} else {
				$create_result = self::createSession( $user_id );

				if ( is_wp_error( $create_result ) ) {
					return $create_result;
				}

				$session_id = $create_result;
				$messages   = array();
			}
		}

		// --- Persist user message immediately (survives navigation away) ---
		$messages[] = ConversationManager::buildConversationMessage( 'user', $message, array( 'type' => 'text' ) );

		$chat_db->update_session(
			$session_id,
			$messages,
			array(
				'status'        => 'processing',
				'started_at'    => current_time( 'mysql', true ),
				'message_count' => count( $messages ),
			),
			$provider,
			$model
		);

		// Set request_id transient BEFORE AI loop to prevent duplicate sessions
		// when retries arrive during processing.
		if ( $request_id ) {
			$cache_key = 'datamachine_chat_request_' . $request_id;
			set_transient(
				$cache_key,
				array(
					'session_id' => $session_id,
					'pending'    => true,
				),
				60
			);
		}

		// --- Execute AI conversation turn ---
		$result = self::executeConversationTurn(
			$session_id,
			$messages,
			$provider,
			$model,
			array(
				'single_turn'          => true,
				'max_turns'            => $max_turns,
				'selected_pipeline_id' => $selected_pipeline_id ? $selected_pipeline_id : null,
				'agent_type'           => AgentType::CHAT,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// --- Update session state ---
		$is_completed = $result['completed'];

		$metadata = array(
			'status'            => $is_completed ? 'completed' : 'processing',
			'last_activity'     => current_time( 'mysql', true ),
			'message_count'     => count( $result['messages'] ),
			'current_turn'      => $result['turn_count'],
			'has_pending_tools' => ! $is_completed,
		);

		if ( $selected_pipeline_id ) {
			$metadata['selected_pipeline_id'] = $selected_pipeline_id;
		}

		$update_success = $chat_db->update_session(
			$session_id,
			$result['messages'],
			$metadata,
			$provider,
			$model
		);

		// --- Title generation for new/untitled sessions ---
		if ( $update_success ) {
			$session = $chat_db->get_session( $session_id );
			if ( $session && empty( $session['title'] ) ) {
				$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/generate-session-title' ) : null;
				if ( $ability ) {
					$ability->execute( array( 'session_id' => $session_id ) );
				}
			}
		}

		// --- Build response data ---
		$response_data = array(
			'session_id'   => $session_id,
			'response'     => $result['final_content'],
			'tool_calls'   => $result['last_tool_calls'],
			'conversation' => $result['messages'],
			'metadata'     => $metadata,
			'completed'    => $is_completed,
			'max_turns'    => $max_turns,
			'turn_number'  => $result['turn_count'],
		);

		if ( isset( $result['warning'] ) ) {
			$response_data['warning'] = $result['warning'];
		}

		if ( isset( $result['max_turns_reached'] ) && $result['max_turns_reached'] ) {
			$response_data['max_turns_reached'] = true;
		}

		return $response_data;
	}

	/**
	 * Continue an existing chat session with pending tool calls.
	 *
	 * Loads the session, runs one more AI conversation turn, extracts
	 * only the new messages, and updates the session state.
	 *
	 * @since 0.31.0
	 *
	 * @param string $session_id Session ID to continue.
	 * @param int    $user_id    Current user ID for ownership check.
	 * @return array|WP_Error Response data array or WP_Error on failure.
	 */
	public static function processContinue( string $session_id, int $user_id ): array|WP_Error {
		$max_turns = PluginSettings::get( 'max_turns', 12 );

		$chat_db = new ChatDatabase();
		$session = $chat_db->get_session( $session_id );

		if ( ! $session ) {
			return new WP_Error(
				'session_not_found',
				__( 'Session not found', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		if ( (int) $session['user_id'] !== $user_id ) {
			return new WP_Error(
				'session_access_denied',
				__( 'Access denied to this session', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$metadata = $session['metadata'] ?? array();

		// Short-circuit if session is already completed.
		if ( isset( $metadata['status'] ) && 'completed' === $metadata['status'] && empty( $metadata['has_pending_tools'] ) ) {
			return array(
				'session_id'        => $session_id,
				'new_messages'      => array(),
				'final_content'     => '',
				'tool_calls'        => array(),
				'completed'         => true,
				'turn_number'       => $metadata['current_turn'] ?? 0,
				'max_turns'         => $max_turns,
				'max_turns_reached' => false,
			);
		}

		$messages             = $session['messages'] ?? array();
		$chat_defaults        = PluginSettings::getAgentModel( 'chat' );
		$provider             = $session['provider'] ?? $chat_defaults['provider'];
		$model                = $session['model'] ?? $chat_defaults['model'];
		$message_count_before = count( $messages );
		$selected_pipeline_id = $metadata['selected_pipeline_id'] ?? null;

		$result = self::executeConversationTurn(
			$session_id,
			$messages,
			$provider,
			$model,
			array(
				'single_turn'          => true,
				'max_turns'            => $max_turns,
				'selected_pipeline_id' => $selected_pipeline_id,
				'agent_type'           => AgentType::CHAT,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Extract new messages (added during this turn).
		$new_messages      = array_slice( $result['messages'], $message_count_before );
		$is_completed      = $result['completed'];
		$current_turn      = ( $metadata['current_turn'] ?? 0 ) + $result['turn_count'];
		$max_turns_reached = $result['max_turns_reached'] ?? ( $current_turn >= $max_turns );

		// Update session with new state.
		$updated_metadata = array(
			'status'            => $is_completed ? 'completed' : 'processing',
			'last_activity'     => current_time( 'mysql', true ),
			'message_count'     => count( $result['messages'] ),
			'current_turn'      => $current_turn,
			'has_pending_tools' => ! $is_completed,
		);

		if ( $selected_pipeline_id ) {
			$updated_metadata['selected_pipeline_id'] = $selected_pipeline_id;
		}

		$chat_db->update_session(
			$session_id,
			$result['messages'],
			$updated_metadata,
			$provider,
			$model
		);

		return array(
			'session_id'        => $session_id,
			'new_messages'      => $new_messages,
			'final_content'     => $result['final_content'],
			'tool_calls'        => $result['last_tool_calls'],
			'completed'         => $is_completed,
			'turn_number'       => $current_turn,
			'max_turns'         => $max_turns,
			'max_turns_reached' => $max_turns_reached,
		);
	}

	/**
	 * Process a webhook ping as a full multi-turn chat session.
	 *
	 * Creates an admin-owned session, runs the AI loop to completion,
	 * generates a title, and returns the result.
	 *
	 * @since 0.31.0
	 *
	 * @param string $message Full message text (with optional prompt/context prepended).
	 * @param string $provider AI provider identifier.
	 * @param string $model    AI model identifier.
	 * @return array|WP_Error Response data array or WP_Error on failure.
	 */
	public static function processPing( string $message, string $provider, string $model ): array|WP_Error {
		// Use admin user for session ownership since this is a system-level request.
		$admin_users = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		$user_id     = ! empty( $admin_users ) ? $admin_users[0]->ID : 1;

		$chat_db = new ChatDatabase();

		$session_id = self::createSession( $user_id, 'ping' );

		if ( is_wp_error( $session_id ) ) {
			return $session_id;
		}

		$messages   = array();
		$messages[] = ConversationManager::buildConversationMessage( 'user', $message, array( 'type' => 'text' ) );

		// Persist user message.
		$chat_db->update_session(
			$session_id,
			$messages,
			array(
				'status'        => 'processing',
				'started_at'    => current_time( 'mysql', true ),
				'message_count' => count( $messages ),
			),
			$provider,
			$model
		);

		$result = self::executeConversationTurn(
			$session_id,
			$messages,
			$provider,
			$model,
			array( 'agent_type' => AgentType::CHAT )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update session to completed with ping source.
		$chat_db->update_session(
			$session_id,
			$result['messages'],
			array(
				'status'        => 'completed',
				'last_activity' => current_time( 'mysql', true ),
				'message_count' => count( $result['messages'] ),
				'source'        => 'ping',
			),
			$provider,
			$model
		);

		// Generate title.
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/generate-session-title' ) : null;
		if ( $ability ) {
			$ability->execute( array( 'session_id' => $session_id ) );
		}

		do_action(
			'datamachine_log',
			'info',
			'Chat ping completed',
			array(
				'session_id' => $session_id,
				'turns'      => $result['turn_count'],
				'agent_type' => AgentType::CHAT,
			)
		);

		return array(
			'session_id' => $session_id,
			'response'   => $result['final_content'],
			'turns'      => $result['turn_count'],
			'completed'  => true,
		);
	}

	/**
	 * Create a new chat session.
	 *
	 * Delegates to the create-chat-session ability when available,
	 * falls back to direct ChatDatabase access.
	 *
	 * @since 0.31.0
	 *
	 * @param int    $user_id User ID who owns the session.
	 * @param string $source  Optional source identifier.
	 * @return string|WP_Error Session ID on success, WP_Error on failure.
	 */
	private static function createSession( int $user_id, string $source = '' ): string|WP_Error {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/create-chat-session' ) : null;

		if ( $ability ) {
			$input = array(
				'user_id'    => $user_id,
				'agent_type' => AgentType::CHAT,
			);

			if ( $source ) {
				$input['source'] = $source;
			}

			$result = \DataMachine\Abilities\PermissionHelper::run_as_authenticated(
				function () use ( $ability, $input ) {
					return $ability->execute( $input );
				}
			);

			if ( empty( $result['success'] ) ) {
				return new WP_Error(
					'session_creation_failed',
					$result['error'] ?? __( 'Failed to create chat session', 'data-machine' ),
					array( 'status' => 500 )
				);
			}

			return $result['session_id'];
		}

		// Fallback: direct DB access.
		$chat_db  = new ChatDatabase();
		$metadata = array(
			'started_at'    => current_time( 'mysql', true ),
			'message_count' => 0,
		);

		if ( $source ) {
			$metadata['source'] = $source;
		}

		$session_id = $chat_db->create_session( $user_id, $metadata, AgentType::CHAT );

		if ( empty( $session_id ) ) {
			return new WP_Error(
				'session_creation_failed',
				__( 'Failed to create chat session', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return $session_id;
	}

	/**
	 * Execute a single conversation turn with the AI loop.
	 *
	 * Encapsulates AgentContext management, tool loading, AIConversationLoop
	 * execution, error handling, and session error updates.
	 *
	 * @since 0.26.0
	 * @since 0.31.0 Moved from Chat.php to ChatOrchestrator.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $messages   Current conversation messages.
	 * @param string $provider   AI provider identifier.
	 * @param string $model      AI model identifier.
	 * @param array  $options    Optional settings {
	 *     @type bool   $single_turn          Whether to run single turn (default false).
	 *     @type int    $max_turns             Maximum turns allowed (default 12).
	 *     @type int    $selected_pipeline_id  Currently selected pipeline ID.
	 *     @type string $agent_type            Agent type for context (default AgentType::CHAT).
	 * }
	 * @return array|WP_Error Result array with messages, final_content, completed, turn_count,
	 *                        last_tool_calls, and optional warning/max_turns_reached keys.
	 *                        WP_Error on failure.
	 */
	public static function executeConversationTurn(
		string $session_id,
		array $messages,
		string $provider,
		string $model,
		array $options = array()
	): array|WP_Error {
		$single_turn          = $options['single_turn'] ?? false;
		$max_turns            = $options['max_turns'] ?? PluginSettings::get( 'max_turns', 12 );
		$selected_pipeline_id = $options['selected_pipeline_id'] ?? null;
		$agent_type           = $options['agent_type'] ?? AgentType::CHAT;

		$chat_db = new ChatDatabase();

		AgentContext::set( $agent_type );

		try {
			$tool_manager = new ToolManager();
			$all_tools    = $tool_manager->getAvailableToolsForChat();

			$loop_context = array( 'session_id' => $session_id );
			if ( $selected_pipeline_id ) {
				$loop_context['selected_pipeline_id'] = $selected_pipeline_id;
			}

			$loop        = new AIConversationLoop();
			$loop_result = $loop->execute(
				$messages,
				$all_tools,
				$provider,
				$model,
				$agent_type,
				$loop_context,
				$max_turns,
				$single_turn
			);

			if ( isset( $loop_result['error'] ) ) {
				$chat_db->update_session(
					$session_id,
					$messages,
					array(
						'status'        => 'error',
						'error_message' => $loop_result['error'],
						'last_activity' => current_time( 'mysql', true ),
						'message_count' => count( $messages ),
					),
					$provider,
					$model
				);

				do_action(
					'datamachine_log',
					'error',
					'AI loop returned error',
					array(
						'session_id' => $session_id,
						'error'      => $loop_result['error'],
						'agent_type' => $agent_type,
					)
				);

				return new WP_Error(
					'chubes_ai_request_failed',
					$loop_result['error'],
					array( 'status' => 500 )
				);
			}

			return array(
				'messages'          => $loop_result['messages'],
				'final_content'     => $loop_result['final_content'],
				'completed'         => $loop_result['completed'] ?? false,
				'turn_count'        => $loop_result['turn_count'] ?? 1,
				'last_tool_calls'   => $loop_result['last_tool_calls'] ?? array(),
				'warning'           => $loop_result['warning'] ?? null,
				'max_turns_reached' => $loop_result['max_turns_reached'] ?? false,
			);
		} catch ( \Throwable $e ) {
			do_action(
				'datamachine_log',
				'error',
				'AI loop failed with exception',
				array(
					'session_id' => $session_id,
					'error'      => $e->getMessage(),
					'agent_type' => $agent_type,
				)
			);

			$chat_db->update_session(
				$session_id,
				$messages,
				array(
					'status'        => 'error',
					'error_message' => $e->getMessage(),
					'last_activity' => current_time( 'mysql', true ),
					'message_count' => count( $messages ),
				),
				$provider,
				$model
			);

			return new WP_Error(
				'chat_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		} finally {
			AgentContext::clear();
		}
	}
}
