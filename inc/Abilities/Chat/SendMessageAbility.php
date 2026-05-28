<?php
/**
 * Send Message Ability
 *
 * Sends a user message to an AI agent within a chat session. Creates a new
 * session if none is provided. This is a Data Machine product facade over the
 * canonical Agents API `agents/chat` entry point.
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
							'session_owner'        => array(
								'type'        => 'object',
								'description' => __( 'Canonical opaque owner for persisted conversation sessions.', 'data-machine' ),
							),
							'provider'             => array(
								'type'        => 'string',
								'description' => __( 'AI provider identifier. Resolved from agent/defaults if omitted.', 'data-machine' ),
							),
							'model'                => array(
								'type'        => 'string',
								'description' => __( 'AI model identifier. Resolved from agent/defaults if omitted.', 'data-machine' ),
							),
							'mode'                 => array(
								'type'        => 'string',
								'description' => __( 'Execution mode for tool/context resolution. Defaults to chat.', 'data-machine' ),
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
	 * Delegates to the canonical Agents API `agents/chat` dispatcher. Data
	 * Machine-specific inputs are passed through for Data Machine's registered
	 * `wp_agent_chat_handler`; no chat runtime logic lives in this facade.
	 *
	 * @since 0.63.0
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error Response data from the orchestrator.
	 */
	public function execute( array $input ): array|\WP_Error {
		$message = trim( (string) ( $input['message'] ?? '' ) );

		if ( '' === $message ) {
			return new \WP_Error(
				'empty_message',
				__( 'Message cannot be empty.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new \WP_Error(
				'agents_chat_unavailable',
				__( 'Agents chat ability is unavailable.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		$ability = wp_get_ability( 'agents/chat' );
		if ( ! $ability ) {
			return new \WP_Error(
				'agents_chat_unavailable',
				__( 'Agents chat ability is not registered.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		$canonical_input = $this->toCanonicalInput( array_merge( $input, array( 'message' => $message ) ) );
		$result          = $ability->execute( $canonical_input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->toDataMachineOutput( is_array( $result ) ? $result : array() );
	}

	/**
	 * Convert Data Machine facade input to canonical Agents API chat input.
	 *
	 * @param array $input Facade input.
	 * @return array Canonical agents/chat input plus Data Machine adapter fields.
	 */
	private function toCanonicalInput( array $input ): array {
		$agent_id       = (int) ( $input['agent_id'] ?? 0 );
		$client_context = is_array( $input['client_context'] ?? null ) ? $input['client_context'] : array();

		$canonical = array(
			'agent'          => (string) ( $input['agent'] ?? ( $agent_id > 0 ? (string) $agent_id : '' ) ),
			'message'        => (string) $input['message'],
			'attachments'    => $input['attachments'] ?? array(),
			'client_context' => $client_context,
		);

		foreach ( array( 'session_id', 'session_owner', 'transcript_owner' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$canonical[ $key ] = $input[ $key ];
			}
		}

		foreach ( array( 'user_id', 'provider', 'model', 'mode', 'modes', 'selected_pipeline_id', 'max_turns', 'request_id' ) as $key ) {
			if ( array_key_exists( $key, $input ) && null !== $input[ $key ] && '' !== $input[ $key ] ) {
				$canonical[ $key ] = $input[ $key ];
			}
		}

		return $canonical;
	}

	/**
	 * Convert canonical Agents API chat output to Data Machine's facade shape.
	 *
	 * @param array $result Canonical agents/chat output.
	 * @return array Data Machine send-message output.
	 */
	private function toDataMachineOutput( array $result ): array {
		$metadata    = is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array();
		$datamachine = is_array( $metadata['datamachine'] ?? null ) ? $metadata['datamachine'] : array();

		return array(
			'session_id'   => (string) ( $result['session_id'] ?? '' ),
			'response'     => (string) ( $result['reply'] ?? '' ),
			'completed'    => (bool) ( $result['completed'] ?? true ),
			'tool_calls'   => $datamachine['tool_calls'] ?? array(),
			'conversation' => $datamachine['conversation'] ?? $result['messages'] ?? array(),
			'metadata'     => $metadata,
			'max_turns'    => (int) ( $datamachine['max_turns'] ?? 0 ),
			'turn_number'  => (int) ( $datamachine['turn_number'] ?? 0 ),
		);
	}
}
