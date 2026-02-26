<?php
/**
 * Delete Chat Session Ability
 *
 * Deletes a chat session after verifying ownership.
 *
 * @package DataMachine\Abilities\Chat
 * @since 0.31.0
 */

namespace DataMachine\Abilities\Chat;

defined( 'ABSPATH' ) || exit;

class DeleteChatSessionAbility {

	use ChatSessionHelpers;

	public function __construct() {
		$this->initDatabase();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	/**
	 * Register the datamachine/delete-chat-session ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/delete-chat-session',
				array(
					'label'               => __( 'Delete Chat Session', 'data-machine' ),
					'description'         => __( 'Delete a chat session after verifying ownership.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'session_id' => array(
								'type'        => 'string',
								'description' => __( 'Session ID to delete.', 'data-machine' ),
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
							'success'    => array( 'type' => 'boolean' ),
							'session_id' => array( 'type' => 'string' ),
							'deleted'    => array( 'type' => 'boolean' ),
							'error'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'annotations'         => array(
						'destructive' => true,
					),
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
	 * Execute delete-chat-session ability.
	 *
	 * @param array $input Input parameters with session_id and user_id.
	 * @return array Result with deletion status.
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

		$deleted = $this->chat_db->delete_session( $session_id );

		if ( ! $deleted ) {
			return array(
				'success' => false,
				'error'   => 'Failed to delete session.',
			);
		}

		return array(
			'success'    => true,
			'session_id' => $session_id,
			'deleted'    => true,
		);
	}
}
