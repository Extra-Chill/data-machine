<?php
/**
 * Mark Session Read Ability
 *
 * Sets last_read_at on a chat session to track unread messages.
 *
 * @package DataMachine\Abilities\Chat
 * @since 0.62.0
 */

namespace DataMachine\Abilities\Chat;

use DataMachine\Core\Admin\DateFormatter;

defined( 'ABSPATH' ) || exit;

class MarkSessionReadAbility {

	use ChatSessionHelpers;

	public function __construct() {
		$this->initDatabase();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	/**
	 * Register the datamachine/mark-session-read ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/mark-session-read',
				array(
					'label'               => __( 'Mark Session Read', 'data-machine' ),
					'description'         => __( 'Mark a chat session as read up to the current timestamp.', 'data-machine' ),
					'category'            => 'datamachine/chat',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'session_id' => array(
								'type'        => 'string',
								'description' => __( 'Session ID to mark as read.', 'data-machine' ),
							),
							'user_id'    => array(
								'type'        => 'integer',
								'description' => __( 'User ID for ownership verification.', 'data-machine' ),
							),
						),
						'required'   => array( 'session_id' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'last_read_at' => array( 'type' => 'string' ),
							'error'        => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => true,
					),
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
	 * Execute mark-session-read ability.
	 *
	 * @param array $input Input parameters with session_id and optional user_id.
	 * @return array Result with last_read_at timestamp.
	 */
	public function execute( array $input ): array {
		if ( empty( $input['session_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'session_id is required.',
			);
		}

		$session_id = sanitize_text_field( $input['session_id'] );
		$user_id    = ! empty( $input['user_id'] ) ? (int) $input['user_id'] : get_current_user_id();

		if ( $user_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'user_id is required and must be a positive integer.',
			);
		}

		if ( ! $this->can_access_user_sessions( $user_id ) ) {
			return array(
				'success' => false,
				'error'   => 'session_access_denied',
			);
		}

		$session = $this->verifySessionOwnership( $session_id, $user_id );

		if ( isset( $session['error'] ) ) {
			return array(
				'success' => false,
				'error'   => $session['error'],
			);
		}

		$last_read_at = $this->chat_db->mark_session_read( $session_id, $user_id );

		if ( false === $last_read_at ) {
			return array(
				'success' => false,
				'error'   => 'Failed to mark session as read.',
			);
		}

		return array(
			'success'      => true,
			'last_read_at' => DateFormatter::format_for_api( $last_read_at ),
		);
	}
}
