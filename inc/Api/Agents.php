<?php
/**
 * REST API Agents Endpoint
 *
 * Thin REST controller for agent CRUD and access management.
 * Business logic is delegated to AgentAbilities and AgentAccess DB repository.
 *
 * @package DataMachine\Api
 * @since 0.41.0
 * @since 0.43.0 Full CRUD + access management endpoints.
 */

namespace DataMachine\Api;

use DataMachine\Abilities\AgentAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Database\Agents\Agents as AgentsRepository;
use DataMachine\Core\Database\Agents\AgentAccess;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Agents {

	/**
	 * Register REST API routes.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register /datamachine/v1/agents routes.
	 */
	public static function register_routes(): void {
		$manage_permission = function () {
			return PermissionHelper::can( 'manage_agents' );
		};

		// List agents (any logged-in user — scoped by access grants).
		register_rest_route(
			'datamachine/v1',
			'/agents',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'handle_list' ),
					'permission_callback' => array( self::class, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'handle_create' ),
					'permission_callback' => $manage_permission,
					'args'                => array(
						'agent_slug' => array(
							'type'              => 'string',
							'required'          => true,
							'description'       => __( 'Unique agent slug.', 'data-machine' ),
							'sanitize_callback' => 'sanitize_title',
						),
						'agent_name' => array(
							'type'              => 'string',
							'required'          => false,
							'description'       => __( 'Display name (defaults to slug).', 'data-machine' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'config'     => array(
							'type'        => 'object',
							'required'    => false,
							'description' => __( 'Agent configuration object.', 'data-machine' ),
						),
					),
				),
			)
		);

		// Single agent: get, update, delete.
		register_rest_route(
			'datamachine/v1',
			'/agents/(?P<agent_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'handle_get' ),
					'permission_callback' => $manage_permission,
					'args'                => array(
						'agent_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'handle_update' ),
					'permission_callback' => $manage_permission,
					'args'                => array(
						'agent_id'     => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'agent_name'   => array(
							'type'              => 'string',
							'required'          => false,
							'description'       => __( 'New display name.', 'data-machine' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'agent_config' => array(
							'type'        => 'object',
							'required'    => false,
							'description' => __( 'New configuration (replaces existing).', 'data-machine' ),
						),
						'status'       => array(
							'type'              => 'string',
							'required'          => false,
							'description'       => __( 'New status (active, inactive, archived).', 'data-machine' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'handle_delete' ),
					'permission_callback' => $manage_permission,
					'args'                => array(
						'agent_id'     => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'delete_files' => array(
							'type'        => 'boolean',
							'required'    => false,
							'default'     => false,
							'description' => __( 'Also delete filesystem directory.', 'data-machine' ),
						),
					),
				),
			)
		);

		// Agent access management.
		register_rest_route(
			'datamachine/v1',
			'/agents/(?P<agent_id>\d+)/access',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'handle_list_access' ),
					'permission_callback' => $manage_permission,
					'args'                => array(
						'agent_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'handle_grant_access' ),
					'permission_callback' => $manage_permission,
					'args'                => array(
						'agent_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'user_id'  => array(
							'type'              => 'integer',
							'required'          => true,
							'description'       => __( 'WordPress user ID to grant access to.', 'data-machine' ),
							'sanitize_callback' => 'absint',
						),
						'role'     => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'viewer',
							'description'       => __( 'Access role: admin, operator, viewer.', 'data-machine' ),
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $param ) {
								return in_array( $param, array( 'admin', 'operator', 'viewer' ), true );
							},
						),
					),
				),
			)
		);

		// Revoke access (DELETE with user_id in URL).
		register_rest_route(
			'datamachine/v1',
			'/agents/(?P<agent_id>\d+)/access/(?P<user_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'handle_revoke_access' ),
				'permission_callback' => $manage_permission,
				'args'                => array(
					'agent_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'user_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	// ---------------------------------------------------------------
	// Handlers
	// ---------------------------------------------------------------

	/**
	 * Handle GET /agents — list agents the current user can access.
	 *
	 * Admins see all agents. Other users see only agents they have access to.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function handle_list( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$agents_repo = new AgentsRepository();
		$user_id     = get_current_user_id();

		if ( PermissionHelper::can( 'manage_agents' ) ) {
			$all_agents = $agents_repo->get_all();
		} else {
			$access_repo    = new AgentAccess();
			$accessible_ids = $access_repo->get_agent_ids_for_user( $user_id );

			if ( empty( $accessible_ids ) ) {
				return rest_ensure_response(
					array(
						'success' => true,
						'data'    => array(),
					)
				);
			}

			$all_agents = array();
			foreach ( $accessible_ids as $agent_id ) {
				$agent = $agents_repo->get_agent( $agent_id );
				if ( $agent ) {
					$all_agents[] = $agent;
				}
			}
		}

		$data = array();
		foreach ( $all_agents as $agent ) {
			$data[] = self::shape_list_item( $agent );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * Handle POST /agents — create a new agent.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function handle_create( WP_REST_Request $request ) {
		$result = AgentAbilities::createAgent(
			array(
				'agent_slug' => $request->get_param( 'agent_slug' ),
				'agent_name' => $request->get_param( 'agent_name' ) ?? '',
				'owner_id'   => get_current_user_id(),
				'config'     => $request->get_param( 'config' ) ?? array(),
			)
		);

		if ( empty( $result['success'] ) ) {
			return new WP_Error(
				'agent_create_failed',
				$result['error'] ?? __( 'Failed to create agent.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * Handle GET /agents/{agent_id} — get single agent with details.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function handle_get( WP_REST_Request $request ) {
		$result = AgentAbilities::getAgent(
			array( 'agent_id' => (int) $request->get_param( 'agent_id' ) )
		);

		if ( empty( $result['success'] ) ) {
			return new WP_Error(
				'agent_not_found',
				$result['error'] ?? __( 'Agent not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['agent'],
			)
		);
	}

	/**
	 * Handle PUT/PATCH /agents/{agent_id} — update agent fields.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function handle_update( WP_REST_Request $request ) {
		$input = array( 'agent_id' => (int) $request->get_param( 'agent_id' ) );

		// Only include fields that were actually sent.
		$json_params = $request->get_json_params() ?? array();

		if ( array_key_exists( 'agent_name', $json_params ) ) {
			$input['agent_name'] = $json_params['agent_name'];
		}
		if ( array_key_exists( 'agent_config', $json_params ) ) {
			$input['agent_config'] = $json_params['agent_config'];
		}
		if ( array_key_exists( 'status', $json_params ) ) {
			$input['status'] = $json_params['status'];
		}

		$result = AgentAbilities::updateAgent( $input );

		if ( empty( $result['success'] ) ) {
			$status = 400;
			if ( isset( $result['error'] ) && str_contains( $result['error'], 'not found' ) ) {
				$status = 404;
			}

			return new WP_Error(
				'agent_update_failed',
				$result['error'] ?? __( 'Failed to update agent.', 'data-machine' ),
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['agent'],
			)
		);
	}

	/**
	 * Handle DELETE /agents/{agent_id} — delete an agent.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function handle_delete( WP_REST_Request $request ) {
		$result = AgentAbilities::deleteAgent(
			array(
				'agent_id'     => (int) $request->get_param( 'agent_id' ),
				'delete_files' => (bool) $request->get_param( 'delete_files' ),
			)
		);

		if ( empty( $result['success'] ) ) {
			return new WP_Error(
				'agent_delete_failed',
				$result['error'] ?? __( 'Failed to delete agent.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	// ---------------------------------------------------------------
	// Access management handlers
	// ---------------------------------------------------------------

	/**
	 * Handle GET /agents/{agent_id}/access — list access grants.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function handle_list_access( WP_REST_Request $request ) {
		$agent_id = (int) $request->get_param( 'agent_id' );

		// Verify agent exists.
		$agents_repo = new AgentsRepository();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return new WP_Error(
				'agent_not_found',
				__( 'Agent not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		$access_repo = new AgentAccess();
		$grants      = $access_repo->get_users_for_agent( $agent_id );

		// Enrich with user display names.
		$data = array();
		foreach ( $grants as $grant ) {
			$user   = get_user_by( 'id', $grant['user_id'] );
			$data[] = array(
				'user_id'      => (int) $grant['user_id'],
				'display_name' => $user ? $user->display_name : __( '(unknown user)', 'data-machine' ),
				'user_email'   => $user ? $user->user_email : '',
				'role'         => (string) $grant['role'],
				'granted_at'   => $grant['granted_at'] ?? '',
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * Handle POST /agents/{agent_id}/access — grant access to a user.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function handle_grant_access( WP_REST_Request $request ) {
		$agent_id = (int) $request->get_param( 'agent_id' );
		$user_id  = (int) $request->get_param( 'user_id' );
		$role     = $request->get_param( 'role' );

		// Verify agent exists.
		$agents_repo = new AgentsRepository();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return new WP_Error(
				'agent_not_found',
				__( 'Agent not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		// Verify user exists.
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error(
				'user_not_found',
				__( 'User not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		$access_repo = new AgentAccess();
		$ok          = $access_repo->grant_access( $agent_id, $user_id, $role );

		if ( ! $ok ) {
			return new WP_Error(
				'grant_failed',
				__( 'Failed to grant access.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'agent_id'     => $agent_id,
					'user_id'      => $user_id,
					'display_name' => $user->display_name,
					'role'         => $role,
				),
			)
		);
	}

	/**
	 * Handle DELETE /agents/{agent_id}/access/{user_id} — revoke access.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function handle_revoke_access( WP_REST_Request $request ) {
		$agent_id = (int) $request->get_param( 'agent_id' );
		$user_id  = (int) $request->get_param( 'user_id' );

		// Verify agent exists.
		$agents_repo = new AgentsRepository();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return new WP_Error(
				'agent_not_found',
				__( 'Agent not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		// Prevent revoking the owner's access.
		if ( $user_id === (int) $agent['owner_id'] ) {
			return new WP_Error(
				'cannot_revoke_owner',
				__( 'Cannot revoke the owner\'s access. Transfer ownership first.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$access_repo = new AgentAccess();
		$ok          = $access_repo->revoke_access( $agent_id, $user_id );

		if ( ! $ok ) {
			return new WP_Error(
				'revoke_failed',
				__( 'No access grant found for this user.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'agent_id' => $agent_id,
					'user_id'  => $user_id,
					'revoked'  => true,
				),
			)
		);
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Shape an agent row for list output (excludes config which may contain secrets).
	 *
	 * @param array $agent Agent database row.
	 * @return array Shaped output.
	 */
	private static function shape_list_item( array $agent ): array {
		return array(
			'agent_id'   => (int) $agent['agent_id'],
			'agent_slug' => (string) $agent['agent_slug'],
			'agent_name' => (string) $agent['agent_name'],
			'owner_id'   => (int) $agent['owner_id'],
			'status'     => (string) $agent['status'],
			'created_at' => $agent['created_at'] ?? '',
			'updated_at' => $agent['updated_at'] ?? '',
		);
	}

	/**
	 * Permission callback — any logged-in user can list their agents.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public static function check_permission( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to list agents.', 'data-machine' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}
}
