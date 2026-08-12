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
					'category'            => 'datamachine-chat',
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
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'destructive' => true,
						),
					),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Execute delete-chat-session ability.
	 *
	 * @param array $input Input parameters with session_id and user_id.
	 * @return array|\WP_Error Result with deletion status.
	 */
	public function execute( array $input ): array|\WP_Error {
		if ( empty( $input['session_id'] ) ) {
			return new \WP_Error( 'session_id_required', __( 'session_id is required.', 'data-machine' ), array( 'status' => 400 ) );
		}

		if ( empty( $input['user_id'] ) || ! is_numeric( $input['user_id'] ) ) {
			return new \WP_Error( 'invalid_user_id', __( 'user_id is required and must be a positive integer.', 'data-machine' ), array( 'status' => 400 ) );
		}

		$session_id = sanitize_text_field( $input['session_id'] );
		$user_id    = (int) $input['user_id'];
		$owner      = $this->resolve_transcript_owner( $input, $user_id );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}

		if ( ! $this->can_access_user_sessions( $user_id ) ) {
			return new \WP_Error( 'session_access_denied', __( 'You do not have access to this user\'s chat sessions.', 'data-machine' ), array( 'status' => 403 ) );
		}

		$session = $this->verifySessionOwnership( $session_id, $user_id, $owner );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$deleted = $this->chat_db->delete_session( $session_id );

		if ( ! $deleted ) {
			return new \WP_Error( 'chat_session_delete_failed', __( 'Failed to delete session.', 'data-machine' ), array( 'status' => 500 ) );
		}

		return array(
			'success'    => true,
			'session_id' => $session_id,
			'deleted'    => true,
		);
	}
}
