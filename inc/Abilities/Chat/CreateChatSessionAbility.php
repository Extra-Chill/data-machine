<?php
/**
 * Create Chat Session Ability
 *
 * Creates a new chat session. Extracted from duplicated session-creation
 * logic in handle_chat and handle_ping.
 *
 * @package DataMachine\Abilities\Chat
 * @since 0.31.0
 */

namespace DataMachine\Abilities\Chat;

defined( 'ABSPATH' ) || exit;

class CreateChatSessionAbility {

	use ChatSessionHelpers;

	public function __construct() {
		$this->initDatabase();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	/**
	 * Register the datamachine/create-chat-session ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/create-chat-session',
				array(
					'label'               => __( 'Create Chat Session', 'data-machine' ),
					'description'         => __( 'Create a new chat session for a user.', 'data-machine' ),
					'category'            => 'datamachine-chat',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'user_id'  => array(
								'type'        => 'integer',
								'description' => __( 'User ID who owns the session.', 'data-machine' ),
							),
							'agent_id' => array(
								'type'        => 'integer',
								'description' => __( 'First-class agent ID for this session.', 'data-machine' ),
							),
							'context'  => array(
								'type'        => 'string',
								'default'     => 'chat',
								'description' => __( 'Execution context (chat, pipeline, system).', 'data-machine' ),
							),
							'source'   => array(
								'type'        => 'string',
								'description' => __( 'Session source identifier (e.g. ping, chat).', 'data-machine' ),
							),
							'metadata' => array(
								'type'        => 'object',
								'description' => __( 'Additional metadata for the session.', 'data-machine' ),
							),
						),
						'required'   => array( 'user_id' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'session_id' => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
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
	 * Execute create-chat-session ability.
	 *
	 * @param array $input Input parameters with user_id, optional context, source, metadata.
	 * @return array Result with session_id on success.
	 */
	public function execute( array $input ): array {
		if ( empty( $input['user_id'] ) || ! is_numeric( $input['user_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'user_id is required and must be a positive integer.',
			);
		}

		$user_id  = (int) $input['user_id'];
		$agent_id = (int) ( $input['agent_id'] ?? 0 );
		$context  = ! empty( $input['context'] ) ? sanitize_text_field( $input['context'] ) : 'chat';
		$source   = ! empty( $input['source'] ) ? sanitize_text_field( $input['source'] ) : null;

		if ( ! $this->can_access_user_sessions( $user_id ) ) {
			return array(
				'success' => false,
				'error'   => 'session_access_denied',
			);
		}

		$session_metadata = array(
			'started_at'    => current_time( 'mysql', true ),
			'message_count' => 0,
		);

		if ( $source ) {
			$session_metadata['source'] = $source;
		}

		// Merge any additional metadata from input.
		if ( ! empty( $input['metadata'] ) && is_array( $input['metadata'] ) ) {
			$session_metadata = array_merge( $session_metadata, $input['metadata'] );
		}

		$session_id = $this->chat_db->create_session( $user_id, $agent_id, $session_metadata, $context );

		if ( empty( $session_id ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create chat session.',
			);
		}

		return array(
			'success'    => true,
			'session_id' => $session_id,
		);
	}
}
