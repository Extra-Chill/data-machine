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
		$owner      = $this->resolve_transcript_owner( $input, $user_id );
		if ( is_wp_error( $owner ) ) {
			return array(
				'success' => false,
				'error'   => $owner->get_error_code(),
			);
		}

		if ( ! $this->can_access_user_sessions( $user_id ) ) {
			return array(
				'success' => false,
				'error'   => 'session_access_denied',
			);
		}

		$session = $this->verifySessionOwnership( $session_id, $user_id, $owner );

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
