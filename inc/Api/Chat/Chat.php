<?php
/**
 * Chat REST API Endpoint
 *
 * Conversational AI endpoint for building and executing Data Machine workflows
 * through natural language interaction.
 *
 * @package DataMachine\Api\Chat
 * @since 0.2.0
 */

namespace DataMachine\Api\Chat;

use DataMachine\Core\Database\Chat\Chat as ChatDatabase;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\ConversationManager;
use DataMachine\Engine\AI\AIConversationLoop;
use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\AgentType;
use DataMachine\Engine\AI\AgentContext;
use WP_REST_Response;
use WP_REST_Server;
use WP_REST_Request;
use WP_Error;

require_once __DIR__ . '/ChatPipelinesDirective.php';
require_once __DIR__ . '/ChatAgentDirective.php';

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat API Handler
 */
class Chat {

	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register chat endpoints
	 */
	public static function register_routes() {
		register_rest_route(
			'datamachine/v1',
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_chat' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'message'              => array(
						'type'              => 'string',
						'required'          => true,
						'description'       => __( 'User message', 'data-machine' ),
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'session_id'           => array(
						'type'              => 'string',
						'required'          => false,
						'description'       => __( 'Optional session ID for conversation continuity', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'provider'             => array(
						'type'              => 'string',
						'required'          => false,
						'validate_callback' => function ( $param ) {
							if ( empty( $param ) ) {
								return true;
							}
							$providers = apply_filters( 'chubes_ai_providers', array() );
							return isset( $providers[ $param ] );
						},
						'description'       => __( 'AI provider (optional, uses default if not provided)', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'model'                => array(
						'type'              => 'string',
						'required'          => false,
						'description'       => __( 'Model identifier (optional, uses default if not provided)', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'selected_pipeline_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'description'       => __( 'Currently selected pipeline ID for context', 'data-machine' ),
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/chat/continue',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_continue' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'session_id' => array(
						'type'              => 'string',
						'required'          => true,
						'description'       => __( 'Session ID to continue', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/chat/(?P<session_id>[a-f0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_session' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'session_id' => array(
						'type'              => 'string',
						'required'          => true,
						'description'       => __( 'Session ID', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/chat/(?P<session_id>[a-f0-9-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'delete_session' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'session_id' => array(
						'type'              => 'string',
						'required'          => true,
						'description'       => __( 'Session ID', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/chat/sessions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'list_sessions' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'limit'      => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 20,
						'description'       => __( 'Maximum sessions to return', 'data-machine' ),
						'sanitize_callback' => 'absint',
					),
					'offset'     => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'description'       => __( 'Pagination offset', 'data-machine' ),
						'sanitize_callback' => 'absint',
					),
					'agent_type' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => \DataMachine\Engine\AI\AgentType::CHAT,
						'description'       => __( 'Agent type filter (chat, pipeline, system)', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $param ) {
							return \DataMachine\Engine\AI\AgentType::isValid( $param );
						},
					),
				),
			)
		);
	}

	/**
	 * List all chat sessions for current user
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error Response data or error
	 */
	public static function list_sessions( WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$limit      = min( 100, max( 1, (int) $request->get_param( 'limit' ) ) );
		$offset     = max( 0, (int) $request->get_param( 'offset' ) );
		$agent_type = $request->get_param( 'agent_type' );

		$chat_db  = new ChatDatabase();
		$sessions = $chat_db->get_user_sessions( $user_id, $limit, $offset, $agent_type );
		$total    = $chat_db->get_user_session_count( $user_id, $agent_type );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'sessions'   => $sessions,
					'total'      => $total,
					'limit'      => $limit,
					'offset'     => $offset,
					'agent_type' => $agent_type,
				),
			)
		);
	}

	/**
	 * Delete a chat session
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error Response data or error
	 */
	public static function delete_session( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
		$user_id    = get_current_user_id();

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

		$deleted = $chat_db->delete_session( $session_id );

		if ( ! $deleted ) {
			return new WP_Error(
				'session_delete_failed',
				__( 'Failed to delete session', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'session_id' => $session_id,
					'deleted'    => true,
				),
			)
		);
	}

	/**
	 * Get existing chat session
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error Response data or error
	 */
	public static function get_session( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
		$user_id    = get_current_user_id();

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

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'session_id'   => $session['session_id'],
					'conversation' => $session['messages'],
					'metadata'     => $session['metadata'],
				),
			)
		);
	}

	/**
	 * Handle chat request
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error Response data or error
	 */
	public static function handle_chat( WP_REST_Request $request ) {
		$request_id = $request->get_header( 'X-Request-ID' );
		if ( $request_id ) {
			$request_id      = sanitize_text_field( $request_id );
			$cache_key       = 'datamachine_chat_request_' . $request_id;
			$cached_response = get_transient( $cache_key );
			if ( false !== $cached_response ) {
				return rest_ensure_response( $cached_response );
			}
		}

		$message = sanitize_textarea_field( wp_unslash( $request->get_param( 'message' ) ) );
		// Set agent context for logging - all logs during this request go to chat log
		AgentContext::set( AgentType::CHAT );

		// Get provider and model with defaults
		$provider  = $request->get_param( 'provider' );
		$model     = $request->get_param( 'model' );
		$max_turns = PluginSettings::get( 'max_turns', 12 );

		if ( empty( $provider ) ) {
			$provider = PluginSettings::get( 'default_provider', '' );
		}
		if ( empty( $model ) ) {
			$model = PluginSettings::get( 'default_model', '' );
		}

		$provider = sanitize_text_field( $provider );
		$model    = sanitize_text_field( $model );

		$session_id           = $request->get_param( 'session_id' );
		$selected_pipeline_id = (int) $request->get_param( 'selected_pipeline_id' );
		$user_id              = get_current_user_id();

		// Validate that we have provider and model
		if ( empty( $provider ) ) {
			return new WP_Error(
				'provider_required',
				__( 'AI provider is required. Please set a default provider in Data Machine settings or provide one in the request.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $model ) ) {
			return new WP_Error(
				'model_required',
				__( 'AI model is required. Please set a default model in Data Machine settings or provide one in the request.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$chat_db        = new ChatDatabase();
		$is_new_session = false;

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
			// Check for recent pending session to prevent duplicates from timeout retries
			// This handles the case where Cloudflare times out but PHP continues executing,
			// creating orphaned sessions. On retry, we reuse the pending session.
			$pending_session = $chat_db->get_recent_pending_session( $user_id, 600, AgentType::CHAT );

			if ( $pending_session ) {
				$session_id     = $pending_session['session_id'];
				$messages       = $pending_session['messages'];
				$is_new_session = false;

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
				$session_id = $chat_db->create_session(
					$user_id,
					array(
						'started_at'    => current_time( 'mysql', true ),
						'message_count' => 0,
					),
					AgentType::CHAT
				);

				if ( empty( $session_id ) ) {
					return new WP_Error(
						'session_creation_failed',
						__( 'Failed to create chat session', 'data-machine' ),
						array( 'status' => 500 )
					);
				}

				$messages       = array();
				$is_new_session = true;
			}
		}

		$messages[] = ConversationManager::buildConversationMessage( 'user', $message, array( 'type' => 'text' ) );

		// Persist user message immediately so it survives navigation away from page
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

		// Load available tools using ToolManager (filters out unconfigured/disabled tools)
		$tool_manager = new ToolManager();
		$all_tools    = $tool_manager->getAvailableToolsForChat();

		try {
			// Execute single turn (async turn-by-turn mode)
			// Client polls /chat/continue until completion
			$loop        = new AIConversationLoop();
			$loop_result = $loop->execute(
				$messages,
				$all_tools,
				$provider,
				$model,
				AgentType::CHAT,
				array(
					'session_id'           => $session_id,
					'selected_pipeline_id' => $selected_pipeline_id ? $selected_pipeline_id : null,
				),
				$max_turns,
				true // single_turn mode
			);

			// Check for errors
			if ( isset( $loop_result['error'] ) ) {
				// Update session with error status before returning
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
					'Chat AI loop returned error',
					array(
						'session_id' => $session_id,
						'error'      => $loop_result['error'],
						'agent_type' => AgentType::CHAT,
					)
				);

				return new WP_Error(
					'chubes_ai_request_failed',
					$loop_result['error'],
					array( 'status' => 500 )
				);
			}
		} catch ( \Throwable $e ) {
			// Log the error
			do_action(
				'datamachine_log',
				'error',
				'Chat AI loop failed with exception',
				array(
					'session_id' => $session_id,
					'error'      => $e->getMessage(),
					'file'       => $e->getFile(),
					'line'       => $e->getLine(),
					'agent_type' => AgentType::CHAT,
				)
			);

			// Update session with error status
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
			// Clear agent context after request completes
			AgentContext::clear();
		}

		// Use final conversation state from loop
		$messages      = $loop_result['messages'];
		$final_content = $loop_result['final_content'];
		$is_completed  = $loop_result['completed'] ?? false;

		$metadata = array(
			'status'            => $is_completed ? 'completed' : 'processing',
			'last_activity'     => current_time( 'mysql', true ),
			'message_count'     => count( $messages ),
			'current_turn'      => $loop_result['turn_count'] ?? 1,
			'has_pending_tools' => ! $is_completed,
		);

		// Store selected pipeline for continuation
		if ( $selected_pipeline_id ) {
			$metadata['selected_pipeline_id'] = $selected_pipeline_id;
		}

		$update_success = $chat_db->update_session(
			$session_id,
			$messages,
			$metadata,
			$provider,
			$model
		);

		// After successful session update, trigger title generation for new sessions
		if ( $update_success ) {
			$session = $chat_db->get_session( $session_id );
			if ( $session && empty( $session['title'] ) ) {
				$ability = wp_get_ability( 'datamachine/generate-session-title' );
				if ( $ability ) {
					$ability->execute( array( 'session_id' => $session_id ) );
				}
			}
		}

		$response_data = array(
			'session_id'   => $session_id,
			'response'     => $final_content,
			'tool_calls'   => $loop_result['last_tool_calls'],
			'conversation' => $messages,
			'metadata'     => $metadata,
			'completed'    => $is_completed,
			'max_turns'    => $max_turns,
			'turn_number'  => $loop_result['turn_count'] ?? 1,
		);

		if ( isset( $loop_result['warning'] ) ) {
			$response_data['warning'] = $loop_result['warning'];
		}

		if ( isset( $loop_result['max_turns_reached'] ) ) {
			$response_data['max_turns_reached'] = true;
		}

		$response = array(
			'success' => true,
			'data'    => $response_data,
		);

		if ( $request_id ) {
			$cache_key = 'datamachine_chat_request_' . $request_id;
			set_transient( $cache_key, $response, 60 );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Handle chat continue request (turn-by-turn execution)
	 *
	 * Loads an existing session with pending tool calls and executes one more turn.
	 * Client polls this endpoint until completed === true.
	 *
	 * @since 0.12.0
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error Response data or error
	 */
	public static function handle_continue( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
		$user_id    = get_current_user_id();

		// Set agent context for logging
		AgentContext::set( AgentType::CHAT );

		$chat_db = new ChatDatabase();
		$session = $chat_db->get_session( $session_id );

		if ( ! $session ) {
			AgentContext::clear();
			return new WP_Error(
				'session_not_found',
				__( 'Session not found', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		if ( (int) $session['user_id'] !== $user_id ) {
			AgentContext::clear();
			return new WP_Error(
				'session_access_denied',
				__( 'Access denied to this session', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$metadata = $session['metadata'] ?? array();

		// Check if session is already completed
		if ( isset( $metadata['status'] ) && 'completed' === $metadata['status'] && empty( $metadata['has_pending_tools'] ) ) {
			AgentContext::clear();
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'session_id'    => $session_id,
						'new_messages'  => array(),
						'final_content' => '',
						'tool_calls'    => array(),
						'completed'     => true,
						'turn_number'   => $metadata['current_turn'] ?? 0,
						'max_turns'     => PluginSettings::get( 'max_turns', 12 ),
					),
				)
			);
		}

		$messages  = $session['messages'] ?? array();
		$provider  = $session['provider'] ?? PluginSettings::get( 'default_provider', '' );
		$model     = $session['model'] ?? PluginSettings::get( 'default_model', '' );
		$max_turns = PluginSettings::get( 'max_turns', 12 );

		// Track message count before turn
		$message_count_before = count( $messages );

		// Load available tools
		$tool_manager = new ToolManager();
		$all_tools    = $tool_manager->getAvailableToolsForChat();

		// Get selected pipeline from metadata if available
		$selected_pipeline_id = $metadata['selected_pipeline_id'] ?? null;

		try {
			// Execute single turn
			$loop        = new AIConversationLoop();
			$loop_result = $loop->execute(
				$messages,
				$all_tools,
				$provider,
				$model,
				AgentType::CHAT,
				array(
					'session_id'           => $session_id,
					'selected_pipeline_id' => $selected_pipeline_id,
				),
				$max_turns,
				true // single_turn mode
			);

			if ( isset( $loop_result['error'] ) ) {
				$chat_db->update_session(
					$session_id,
					$messages,
					array(
						'status'        => 'error',
						'error_message' => $loop_result['error'],
						'last_activity' => current_time( 'mysql', true ),
					),
					$provider,
					$model
				);

				AgentContext::clear();
				return new WP_Error(
					'chubes_ai_request_failed',
					$loop_result['error'],
					array( 'status' => 500 )
				);
			}
		} catch ( \Throwable $e ) {
			do_action(
				'datamachine_log',
				'error',
				'Chat continue failed with exception',
				array(
					'session_id' => $session_id,
					'error'      => $e->getMessage(),
					'agent_type' => AgentType::CHAT,
				)
			);

			$chat_db->update_session(
				$session_id,
				$messages,
				array(
					'status'        => 'error',
					'error_message' => $e->getMessage(),
					'last_activity' => current_time( 'mysql', true ),
				),
				$provider,
				$model
			);

			AgentContext::clear();
			return new WP_Error(
				'chat_continue_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		} finally {
			AgentContext::clear();
		}

		// Extract new messages (added during this turn)
		$updated_messages  = $loop_result['messages'];
		$new_messages      = array_slice( $updated_messages, $message_count_before );
		$is_completed      = $loop_result['completed'] ?? false;
		$current_turn      = ( $metadata['current_turn'] ?? 0 ) + $loop_result['turn_count'];
		$max_turns_reached = $loop_result['max_turns_reached'] ?? ( $current_turn >= $max_turns );

		// Update session with new state
		$updated_metadata = array(
			'status'            => $is_completed ? 'completed' : 'processing',
			'last_activity'     => current_time( 'mysql', true ),
			'message_count'     => count( $updated_messages ),
			'current_turn'      => $current_turn,
			'has_pending_tools' => ! $is_completed,
		);

		if ( $selected_pipeline_id ) {
			$updated_metadata['selected_pipeline_id'] = $selected_pipeline_id;
		}

		$chat_db->update_session(
			$session_id,
			$updated_messages,
			$updated_metadata,
			$provider,
			$model
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'session_id'        => $session_id,
					'new_messages'      => $new_messages,
					'final_content'     => $loop_result['final_content'] ?? '',
					'tool_calls'        => $loop_result['last_tool_calls'] ?? array(),
					'completed'         => $is_completed,
					'turn_number'       => $current_turn,
					'max_turns'         => $max_turns,
					'max_turns_reached' => $max_turns_reached,
				),
			)
		);
	}
}
