<?php
/**
 * Send Message Ability
 *
 * Sends a user message to an AI agent within a chat session. Creates a new
 * session if none is provided. Wraps ChatOrchestrator::processChat() as the
 * canonical entry point for all interfaces (REST, CLI, bridge, tools).
 *
 * This is the ability that was missing — prior to this, the "send a message
 * and get an AI response" flow lived only in the Chat REST controller,
 * forcing the bridge plugin to use rest_do_request() internally.
 *
 * @package DataMachine\Abilities\Chat
 * @since 0.63.0
 */

namespace DataMachine\Abilities\Chat;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Api\Chat\ChatOrchestrator;
use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

class SendMessageAbility {

	public function __construct() {
		$this->registerAbility();
	}

	/**
	 * Register the datamachine/send-message ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/send-message',
				array(
					'label'               => __( 'Send Message', 'data-machine' ),
					'description'         => __( 'Send a user message to an AI agent and get a response. Creates a session if needed.', 'data-machine' ),
					'category'            => 'datamachine-chat',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'message'              => array(
								'type'        => 'string',
								'description' => __( 'The user message text.', 'data-machine' ),
							),
							'user_id'              => array(
								'type'        => 'integer',
								'description' => __( 'User ID sending the message. Defaults to acting user.', 'data-machine' ),
							),
							'agent_id'             => array(
								'type'        => 'integer',
								'description' => __( 'Agent ID to target. Used for model resolution and session scoping.', 'data-machine' ),
							),
							'session_id'           => array(
								'type'        => 'string',
								'description' => __( 'Existing session ID to continue. Omit to create a new session.', 'data-machine' ),
							),
							'provider'             => array(
								'type'        => 'string',
								'description' => __( 'AI provider identifier. Resolved from agent/defaults if omitted.', 'data-machine' ),
							),
							'model'                => array(
								'type'        => 'string',
								'description' => __( 'AI model identifier. Resolved from agent/defaults if omitted.', 'data-machine' ),
							),
							'selected_pipeline_id' => array(
								'type'        => 'integer',
								'description' => __( 'Currently selected pipeline ID for context.', 'data-machine' ),
							),
							'max_turns'            => array(
								'type'        => 'integer',
								'description' => __( 'Maximum conversation turns allowed.', 'data-machine' ),
							),
							'request_id'           => array(
								'type'        => 'string',
								'description' => __( 'Idempotency key for deduplication.', 'data-machine' ),
							),
							'attachments'          => array(
								'type'        => 'array',
								'description' => __( 'Media attachments for multi-modal messages.', 'data-machine' ),
							),
							'client_context'       => array(
								'type'        => 'object',
								'description' => __( 'Client-side context metadata (source, active tab, etc).', 'data-machine' ),
							),
						),
						'required'   => array( 'message' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'session_id'   => array( 'type' => 'string' ),
							'response'     => array( 'type' => 'string' ),
							'completed'    => array( 'type' => 'boolean' ),
							'tool_calls'   => array( 'type' => 'array' ),
							'conversation' => array( 'type' => 'array' ),
							'metadata'     => array( 'type' => 'object' ),
							'max_turns'    => array( 'type' => 'integer' ),
							'turn_number'  => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can( 'chat' );
	}

	/**
	 * Execute send-message ability.
	 *
	 * Resolves provider/model from agent context if not provided, then
	 * delegates to ChatOrchestrator::processChat() for the full AI
	 * conversation turn.
	 *
	 * @since 0.63.0
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error Response data from the orchestrator.
	 */
	public function execute( array $input ): array|\WP_Error {
		$message = $input['message'] ?? '';

		if ( empty( $message ) ) {
			return new \WP_Error(
				'empty_message',
				__( 'Message cannot be empty.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		// Resolve user ID: explicit > acting user.
		$user_id = (int) ( $input['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			$user_id = PermissionHelper::acting_user_id();
		}

		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'no_user',
				__( 'No user context available.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$agent_id = (int) ( $input['agent_id'] ?? 0 );

		// Resolve provider/model from input or agent context.
		$provider = $input['provider'] ?? '';
		$model    = $input['model'] ?? '';

		if ( empty( $provider ) || empty( $model ) ) {
			$agent_config = PluginSettings::resolveModelForAgentMode( $agent_id ?: null, 'chat' );
			if ( empty( $provider ) ) {
				$provider = $agent_config['provider'];
			}
			if ( empty( $model ) ) {
				$model = $agent_config['model'];
			}
		}

		if ( empty( $provider ) ) {
			return new \WP_Error(
				'provider_required',
				__( 'AI provider is required. Set a default in Data Machine settings or provide one in the request.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $model ) ) {
			return new \WP_Error(
				'model_required',
				__( 'AI model is required. Set a default in Data Machine settings or provide one in the request.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		return ChatOrchestrator::processChat(
			$message,
			sanitize_text_field( $provider ),
			sanitize_text_field( $model ),
			$user_id,
			array(
				'session_id'           => $input['session_id'] ?? null,
				'selected_pipeline_id' => (int) ( $input['selected_pipeline_id'] ?? 0 ),
				'max_turns'            => $input['max_turns'] ?? null,
				'request_id'           => $input['request_id'] ?? null,
				'agent_id'             => $agent_id,
				'attachments'          => $input['attachments'] ?? array(),
				'client_context'       => $input['client_context'] ?? array(),
			)
		);
	}
}
