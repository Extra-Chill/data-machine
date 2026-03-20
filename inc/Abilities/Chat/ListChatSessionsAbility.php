<?php
/**
 * List Chat Sessions Ability
 *
	 * Lists chat sessions for a given user with pagination and context filtering.
 *
 * @package DataMachine\Abilities\Chat
 * @since 0.31.0
 */

namespace DataMachine\Abilities\Chat;

defined( 'ABSPATH' ) || exit;

class ListChatSessionsAbility {

	use ChatSessionHelpers;

	public function __construct() {
		$this->initDatabase();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	/**
	 * Register the datamachine/list-chat-sessions ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/list-chat-sessions',
				array(
					'label'               => __( 'List Chat Sessions', 'data-machine' ),
					'description'         => __( 'List chat sessions for a user with pagination and context filtering.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'user_id'  => array(
								'type'        => 'integer',
								'description' => __( 'User ID to list sessions for.', 'data-machine' ),
							),
							'agent_id' => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'Agent ID to filter sessions by. Null or omitted returns all agents.', 'data-machine' ),
							),
							'limit'    => array(
								'type'        => 'integer',
								'default'     => 20,
								'description' => __( 'Maximum sessions to return (1-100).', 'data-machine' ),
							),
							'offset'   => array(
								'type'        => 'integer',
								'default'     => 0,
								'description' => __( 'Pagination offset.', 'data-machine' ),
							),
							'context'  => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'Context filter (chat, pipeline, system). Null returns all contexts.', 'data-machine' ),
							),
						),
						'required'   => array( 'user_id' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'sessions' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'total'    => array( 'type' => 'integer' ),
							'limit'    => array( 'type' => 'integer' ),
							'offset'   => array( 'type' => 'integer' ),
							'context'  => array( 'type' => array( 'string', 'null' ) ),
							'error'    => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
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
	 * Execute list-chat-sessions ability.
	 *
	 * @param array $input Input parameters with user_id, optional limit, offset, context.
	 * @return array Result with sessions list and total count.
	 */
	public function execute( array $input ): array {
		if ( empty( $input['user_id'] ) || ! is_numeric( $input['user_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'user_id is required and must be a positive integer.',
			);
		}

		$user_id = (int) $input['user_id'];

		if ( ! $this->can_access_user_sessions( $user_id ) ) {
			return array(
				'success' => false,
				'error'   => 'session_access_denied',
			);
		}

		$limit    = min( 100, max( 1, (int) ( $input['limit'] ?? 20 ) ) );
		$offset   = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$context  = ! empty( $input['context'] ) ? sanitize_text_field( $input['context'] ) : null;
		$agent_id = isset( $input['agent_id'] ) && is_numeric( $input['agent_id'] ) ? (int) $input['agent_id'] : null;

		$sessions = $this->chat_db->get_user_sessions( $user_id, $limit, $offset, $context, $agent_id );
		$total    = $this->chat_db->get_user_session_count( $user_id, $context, $agent_id );

		return array(
			'success'  => true,
			'sessions' => $sessions,
			'total'    => $total,
			'limit'    => $limit,
			'offset'   => $offset,
			'context'  => $context,
		);
	}
}
