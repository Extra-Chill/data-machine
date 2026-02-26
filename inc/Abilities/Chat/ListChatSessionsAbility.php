<?php
/**
 * List Chat Sessions Ability
 *
 * Lists chat sessions for a given user with pagination and agent type filtering.
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
					'description'         => __( 'List chat sessions for a user with pagination and agent type filtering.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'user_id'    => array(
								'type'        => 'integer',
								'description' => __( 'User ID to list sessions for.', 'data-machine' ),
							),
							'limit'      => array(
								'type'        => 'integer',
								'default'     => 20,
								'description' => __( 'Maximum sessions to return (1-100).', 'data-machine' ),
							),
							'offset'     => array(
								'type'        => 'integer',
								'default'     => 0,
								'description' => __( 'Pagination offset.', 'data-machine' ),
							),
							'agent_type' => array(
								'type'        => 'string',
								'description' => __( 'Agent type filter (chat, pipeline, system).', 'data-machine' ),
							),
						),
						'required'   => array( 'user_id' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'sessions'   => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'total'      => array( 'type' => 'integer' ),
							'limit'      => array( 'type' => 'integer' ),
							'offset'     => array( 'type' => 'integer' ),
							'agent_type' => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute list-chat-sessions ability.
	 *
	 * @param array $input Input parameters with user_id, optional limit, offset, agent_type.
	 * @return array Result with sessions list and total count.
	 */
	public function execute( array $input ): array {
		if ( empty( $input['user_id'] ) || ! is_numeric( $input['user_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'user_id is required and must be a positive integer.',
			);
		}

		$user_id    = (int) $input['user_id'];
		$limit      = min( 100, max( 1, (int) ( $input['limit'] ?? 20 ) ) );
		$offset     = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$agent_type = ! empty( $input['agent_type'] ) ? sanitize_text_field( $input['agent_type'] ) : null;

		$sessions = $this->chat_db->get_user_sessions( $user_id, $limit, $offset, $agent_type );
		$total    = $this->chat_db->get_user_session_count( $user_id, $agent_type );

		return array(
			'success'    => true,
			'sessions'   => $sessions,
			'total'      => $total,
			'limit'      => $limit,
			'offset'     => $offset,
			'agent_type' => $agent_type,
		);
	}
}
