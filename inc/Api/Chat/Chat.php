<?php
/**
 * Chat REST API Controller
 *
 * Thin REST controller for chat endpoints. Handles route registration,
 * request parsing, response formatting, and idempotency. Business logic
 * is delegated to ChatOrchestrator and Chat Session abilities.
 *
 * @package DataMachine\Api\Chat
 * @since 0.2.0
 * @since 0.31.0 Refactored to thin controller; orchestration moved to ChatOrchestrator,
 *               session CRUD moved to Chat abilities.
 */

namespace DataMachine\Api\Chat;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\PluginSettings;
use WP_REST_Response;
use WP_REST_Server;
use WP_REST_Request;
use WP_Error;

require_once __DIR__ . '/ChatPipelinesDirective.php';

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat API Controller
 */
class Chat {

	/**
	 * Register REST API routes.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register chat endpoints.
	 */
	public static function register_routes() {
		$chat_permission_callback = function () {
			return PermissionHelper::can( 'chat' );
		};

		register_rest_route(
			'datamachine/v1',
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_chat' ),
				'permission_callback' => $chat_permission_callback,
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
					'attachments'          => array(
						'type'              => 'array',
						'required'          => false,
						'description'       => __( 'Media attachments for multi-modal messages. Each item: {url, media_id, mime_type, filename}.', 'data-machine' ),
						'items'             => array(
							'type'       => 'object',
							'properties' => array(
								'url'       => array( 'type' => 'string' ),
								'media_id'  => array( 'type' => 'integer' ),
								'mime_type' => array( 'type' => 'string' ),
								'filename'  => array( 'type' => 'string' ),
							),
						),
						'sanitize_callback' => array( self::class, 'sanitize_attachments' ),
					),
					'client_context'       => array(
						'type'              => 'object',
						'required'          => false,
						'description'       => __( 'Client-side context for the AI agent. Arbitrary key-value pairs describing what the user is currently doing (active tab, draft ID, screen, etc). Injected as a system message.', 'data-machine' ),
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
				'permission_callback' => $chat_permission_callback,
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
				'permission_callback' => $chat_permission_callback,
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
				'permission_callback' => $chat_permission_callback,
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
			'/chat/ping',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_ping' ),
				'permission_callback' => array( self::class, 'verify_ping_token' ),
				'args'                => array(
					'message' => array(
						'type'              => 'string',
						'required'          => true,
						'description'       => __( 'Message for the chat agent', 'data-machine' ),
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'prompt'  => array(
						'type'              => 'string',
						'required'          => false,
						'description'       => __( 'Optional system-level instructions for this ping', 'data-machine' ),
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'context' => array(
						'type'        => 'object',
						'required'    => false,
						'description' => __( 'Optional pipeline context (flow_id, pipeline_id, job_id, etc.)', 'data-machine' ),
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
				'permission_callback' => $chat_permission_callback,
				'args'                => array(
					'limit'    => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 20,
						'description'       => __( 'Maximum sessions to return', 'data-machine' ),
						'sanitize_callback' => 'absint',
					),
					'offset'   => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'description'       => __( 'Pagination offset', 'data-machine' ),
						'sanitize_callback' => 'absint',
					),
					'context'  => array(
						'type'              => 'string',
						'required'          => false,
						'description'       => __( 'Context filter (chat, pipeline, system)', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $param ) {
							return in_array( $param, array( 'chat', 'pipeline', 'system' ), true );
						},
					),
					'agent_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'description'       => __( 'Filter sessions by agent ID', 'data-machine' ),
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Verify bearer token for chat ping endpoint.
	 *
	 * @since 0.24.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public static function verify_ping_token( WP_REST_Request $request ) {
		$secret = PluginSettings::get( 'chat_ping_secret', '' );

		if ( empty( $secret ) ) {
			return new WP_Error(
				'ping_not_configured',
				__( 'Chat ping secret not configured.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$auth_header = $request->get_header( 'Authorization' );

		if ( empty( $auth_header ) ) {
			return new WP_Error(
				'missing_authorization',
				__( 'Authorization header required.', 'data-machine' ),
				array( 'status' => 401 )
			);
		}

		// Accept "Bearer <token>" format.
		$token = $auth_header;
		if ( str_starts_with( $auth_header, 'Bearer ' ) ) {
			$token = substr( $auth_header, 7 );
		}

		if ( ! hash_equals( $secret, $token ) ) {
			return new WP_Error(
				'invalid_token',
				__( 'Invalid authorization token.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle incoming chat ping from webhook.
	 *
	 * @since 0.24.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response data or error.
	 */
	public static function handle_ping( WP_REST_Request $request ) {
		$message = sanitize_textarea_field( wp_unslash( $request->get_param( 'message' ) ) );
		$prompt  = sanitize_textarea_field( wp_unslash( $request->get_param( 'prompt' ) ?? '' ) );
		$context = $request->get_param( 'context' ) ?? array();

		$agent_config = PluginSettings::resolveModelForAgentContext( $agent_id, 'chat' );
		$provider     = $agent_config['provider'];
		$model        = $agent_config['model'];

		if ( empty( $provider ) || empty( $model ) ) {
			return new WP_Error(
				'provider_required',
				__( 'Default AI provider and model must be configured.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		// Build the message with optional context.
		$full_message = $message;
		if ( ! empty( $prompt ) ) {
			$full_message = $prompt . "\n\n" . $message;
		}
		if ( ! empty( $context ) ) {
			$context_str   = wp_json_encode( $context, JSON_PRETTY_PRINT );
			$full_message .= "\n\n**Pipeline Context:**\n```json\n" . $context_str . "\n```";
		}

		$result = ChatOrchestrator::processPing( $full_message, $provider, $model );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * List all chat sessions for current user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response data.
	 */
	public static function list_sessions( WP_REST_Request $request ) {
		return self::execute_ability(
			'datamachine/list-chat-sessions',
			array(
				'user_id'  => get_current_user_id(),
				'agent_id' => PermissionHelper::resolve_scoped_agent_id( $request ),
				'limit'    => (int) $request->get_param( 'limit' ),
				'offset'   => (int) $request->get_param( 'offset' ),
				'context'  => $request->get_param( 'context' ),
			)
		);
	}

	/**
	 * Delete a chat session.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response data or error.
	 */
	public static function delete_session( WP_REST_Request $request ) {
		return self::execute_ability(
			'datamachine/delete-chat-session',
			array(
				'session_id' => sanitize_text_field( $request->get_param( 'session_id' ) ),
				'user_id'    => get_current_user_id(),
			)
		);
	}

	/**
	 * Get existing chat session.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response data or error.
	 */
	public static function get_session( WP_REST_Request $request ) {
		return self::execute_ability(
			'datamachine/get-chat-session',
			array(
				'session_id' => sanitize_text_field( $request->get_param( 'session_id' ) ),
				'user_id'    => get_current_user_id(),
			)
		);
	}

	/**
	 * Handle chat request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response data or error.
	 */
	public static function handle_chat( WP_REST_Request $request ) {
		// --- Idempotency check ---
		$request_id = $request->get_header( 'X-Request-ID' );
		if ( $request_id ) {
			$request_id      = sanitize_text_field( $request_id );
			$cache_key       = 'datamachine_chat_request_' . $request_id;
			$cached_response = get_transient( $cache_key );
			if ( false !== $cached_response ) {
				return rest_ensure_response( $cached_response );
			}
		}

		// --- Extract and resolve params ---
		$message  = sanitize_textarea_field( wp_unslash( $request->get_param( 'message' ) ) );
		$agent_id = (int) $request->get_param( 'agent_id' );

		$provider = $request->get_param( 'provider' );
		$model    = $request->get_param( 'model' );

		if ( empty( $provider ) || empty( $model ) ) {
			$agent_config = PluginSettings::resolveModelForAgentContext( $agent_id, 'chat' );
			if ( empty( $provider ) ) {
				$provider = $agent_config['provider'];
			}
			if ( empty( $model ) ) {
				$model = $agent_config['model'];
			}
		}

		$provider = sanitize_text_field( $provider );
		$model    = sanitize_text_field( $model );

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

		// --- Resolve attachments ---
		$attachments = $request->get_param( 'attachments' );
		if ( ! empty( $attachments ) && is_array( $attachments ) ) {
			$attachments = self::resolve_attachment_paths( $attachments );
		} else {
			$attachments = array();
		}

		// --- Resolve client context ---
		$client_context = $request->get_param( 'client_context' );
		if ( ! is_array( $client_context ) ) {
			$client_context = array();
		}

		// --- Delegate to orchestrator ---
		$result = ChatOrchestrator::processChat(
			$message,
			$provider,
			$model,
			get_current_user_id(),
			array(
				'session_id'           => $request->get_param( 'session_id' ),
				'selected_pipeline_id' => (int) $request->get_param( 'selected_pipeline_id' ),
				'request_id'           => $request_id,
				'agent_id'             => $agent_id,
				'attachments'          => $attachments,
				'client_context'       => $client_context,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// --- Format and cache response ---
		$response = array(
			'success' => true,
			'data'    => $result,
		);

		if ( $request_id ) {
			$cache_key = 'datamachine_chat_request_' . $request_id;
			set_transient( $cache_key, $response, 60 );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Handle chat continue request (turn-by-turn execution).
	 *
	 * @since 0.12.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response data or error.
	 */
	public static function handle_continue( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );

		$result = ChatOrchestrator::processContinue( $session_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * Sanitize attachments array from REST request.
	 *
	 * @since 0.53.0
	 *
	 * @param array $attachments Raw attachments from request.
	 * @return array Sanitized attachments.
	 */
	public static function sanitize_attachments( $attachments ): array {
		if ( ! is_array( $attachments ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $attachments as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}

			$item = array();

			if ( ! empty( $attachment['url'] ) ) {
				$item['url'] = esc_url_raw( $attachment['url'] );
			}

			if ( ! empty( $attachment['media_id'] ) ) {
				$item['media_id'] = absint( $attachment['media_id'] );
			}

			if ( ! empty( $attachment['mime_type'] ) ) {
				$item['mime_type'] = sanitize_mime_type( $attachment['mime_type'] );
			}

			if ( ! empty( $attachment['filename'] ) ) {
				$item['filename'] = sanitize_file_name( $attachment['filename'] );
			}

			// Must have at least a URL or media_id.
			if ( ! empty( $item['url'] ) || ! empty( $item['media_id'] ) ) {
				$sanitized[] = $item;
			}
		}

		return $sanitized;
	}

	/**
	 * Resolve attachment file paths from URLs or media IDs.
	 *
	 * Converts REST attachment metadata into the format expected by
	 * ConversationManager::buildMultiModalContent() — adding file_path
	 * when possible for providers that support direct file upload.
	 *
	 * @since 0.53.0
	 *
	 * @param array $attachments Sanitized attachments array.
	 * @return array Attachments with file_path resolved where possible.
	 */
	private static function resolve_attachment_paths( array $attachments ): array {
		$resolved = array();

		foreach ( $attachments as $attachment ) {
			$item = $attachment;

			// Resolve media_id to file path and URL.
			if ( ! empty( $attachment['media_id'] ) ) {
				$media_id  = (int) $attachment['media_id'];
				$file_path = get_attached_file( $media_id );

				if ( $file_path && file_exists( $file_path ) ) {
					$item['file_path'] = $file_path;
				}

				if ( empty( $item['url'] ) ) {
					$item['url'] = wp_get_attachment_url( $media_id );
				}

				if ( empty( $item['mime_type'] ) ) {
					$item['mime_type'] = get_post_mime_type( $media_id );
				}
			}

			// Resolve URL to local file path if it's a local upload.
			if ( empty( $item['file_path'] ) && ! empty( $item['url'] ) ) {
				$upload_dir = wp_get_upload_dir();
				$upload_url = $upload_dir['baseurl'];

				if ( strpos( $item['url'], $upload_url ) === 0 ) {
					$relative_path = str_replace( $upload_url, '', $item['url'] );
					$local_path    = $upload_dir['basedir'] . $relative_path;

					if ( file_exists( $local_path ) ) {
						$item['file_path'] = $local_path;
					}
				}
			}

			$resolved[] = $item;
		}

		return $resolved;
	}

	/**
	 * Execute an ability and return the REST response.
	 *
	 * Resolves the ability by slug, calls execute() with the given input,
	 * and converts the result into a REST response. Handles WP_Error returns
	 * from core's execute() pipeline (input validation, permissions, callback).
	 *
	 * For ability callbacks that still return { success: false, error: ... }
	 * arrays (legacy convention), those are mapped to WP_Error. New abilities
	 * should return WP_Error directly per core best practices.
	 *
	 * @since 0.62.0
	 *
	 * @see \WP_Ability::execute()
	 *
	 * @param string $slug  Ability slug (e.g. 'datamachine/get-chat-session').
	 * @param array  $input Input parameters for the ability.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function execute_ability( string $slug, array $input = array() ) {
		$ability = wp_get_ability( $slug );

		if ( ! $ability ) {
			return new WP_Error(
				'ability_not_found',
				/* translators: %s: ability slug */
				sprintf( __( 'Ability "%s" not registered.', 'data-machine' ), $slug ),
				array( 'status' => 500 )
			);
		}

		$result = $ability->execute( $input );

		// Core's execute() returns WP_Error for validation/permission/callback failures.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Legacy convention: ability callbacks return { success: false, error: ... }.
		// Map to WP_Error until all abilities are migrated to return WP_Error directly.
		// See: https://github.com/Extra-Chill/data-machine/issues/999
		if ( is_array( $result ) && isset( $result['success'] ) && ! $result['success'] ) {
			$error_code = $result['error'] ?? 'ability_failed';

			$status_map = array(
				'session_not_found'     => 404,
				'session_access_denied' => 403,
			);

			return new WP_Error(
				$error_code,
				$result['error'] ?? 'Ability execution failed.',
				array( 'status' => $status_map[ $error_code ] ?? 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}
}
