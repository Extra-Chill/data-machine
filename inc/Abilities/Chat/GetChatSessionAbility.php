<?php
/**
 * Get Chat Session Ability
 *
 * Retrieves a single chat session's conversation and metadata after
 * verifying ownership.
 *
 * @package DataMachine\Abilities\Chat
 * @since 0.31.0
 */

namespace DataMachine\Abilities\Chat;

defined( 'ABSPATH' ) || exit;

class GetChatSessionAbility {

	use ChatSessionHelpers;

	public function __construct() {
		$this->initDatabase();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	/**
	 * Register the datamachine/get-chat-session ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/get-chat-session',
				array(
					'label'               => __( 'Get Chat Session', 'data-machine' ),
					'description'         => __( 'Retrieve a chat session with conversation and metadata.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'session_id' => array(
								'type'        => 'string',
								'description' => __( 'Session ID to retrieve.', 'data-machine' ),
							),
							'user_id'    => array(
								'type'        => 'integer',
								'description' => __( 'User ID for ownership verification.', 'data-machine' ),
							),
						),
						'required'   => array( 'session_id', 'user_id' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'session_id'   => array( 'type' => 'string' ),
							'conversation' => array( 'type' => 'array' ),
							'metadata'     => array( 'type' => 'object' ),
							'error'        => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'annotations'         => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute get-chat-session ability.
	 *
	 * @param array $input Input parameters with session_id and user_id.
	 * @return array Result with session conversation and metadata.
	 */
	public function execute( array $input ): array {
		if ( empty( $input['session_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'session_id is required.',
			);
		}

		if ( empty( $input['user_id'] ) || ! is_numeric( $input['user_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'user_id is required and must be a positive integer.',
			);
		}

		$session_id = sanitize_text_field( $input['session_id'] );
		$user_id    = (int) $input['user_id'];

		$session = $this->verifySessionOwnership( $session_id, $user_id );

		if ( isset( $session['error'] ) ) {
			return array(
				'success' => false,
				'error'   => $session['error'],
			);
		}

		return array(
			'success'      => true,
			'session_id'   => $session['session_id'],
			'conversation' => $session['messages'],
			'metadata'     => $session['metadata'],
		);
	}
}
