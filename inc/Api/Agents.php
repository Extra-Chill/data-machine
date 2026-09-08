<?php
/**
 * REST API Agents Endpoint
 *
 * Thin REST controller for agent CRUD and access management.
 * Retained access-management routes only. Everything else in this family moved to
 * REST-visible abilities (#3456). These three routes are held until Agents API ships
 * grant/revoke/list-users access abilities (Automattic/agents-api#537), at which point
 * the admin switches to `agents/*` slugs and this file is deleted.
 *
 * @package DataMachine\Api
 * @since 0.41.0
 * @since 0.43.0 Full CRUD + access management endpoints.
 */

namespace DataMachine\Api;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Agents\AgentIdentityResolver;
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

		$agent_access_routes = array(
			'/agents/(?P<agent>[A-Za-z0-9_-]+)/access',
			'/agents/(?P<agent_id>\d+)/access',
		);

		// Agent access management.
		foreach ( $agent_access_routes as $agent_access_route ) {
			register_rest_route(
			'datamachine/v1',
			$agent_access_route,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'handle_list_access' ),
					'permission_callback' => $manage_permission,
					'args'                => array(
						'agent' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'handle_grant_access' ),
					'permission_callback' => $manage_permission,
					'args'                => array(
						'agent'   => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'user_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'description'       => __( 'WordPress user ID to grant access to.', 'data-machine' ),
							'sanitize_callback' => 'absint',
						),
						'role'    => array(
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
		}

		$agent_revoke_routes = array(
			'/agents/(?P<agent>[A-Za-z0-9_-]+)/access/(?P<user_id>\d+)',
			'/agents/(?P<agent_id>\d+)/access/(?P<user_id>\d+)',
		);

		// Revoke access (DELETE with user_id in URL).
		foreach ( $agent_revoke_routes as $agent_revoke_route ) {
			register_rest_route(
			'datamachine/v1',
			$agent_revoke_route,
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'handle_revoke_access' ),
				'permission_callback' => $manage_permission,
				'args'                => array(
					'agent'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'user_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
			);
		}
	}

	/**
	 * Handle GET /agents/{agent_id}/access — list access grants.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function handle_list_access( WP_REST_Request $request ) {
		$agent_id = self::resolve_request_agent_id( $request );
		if ( is_wp_error( $agent_id ) ) {
			return $agent_id;
		}

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
		$grants      = $access_repo->get_users_for_agent( (string) $agent_id );

		// Enrich with user display names.
		$data = array();
		foreach ( $grants as $grant ) {
			$user   = get_user_by( 'id', $grant->user_id );
			$data[] = array(
				'user_id'      => $grant->user_id,
				'display_name' => $user ? $user->display_name : __( '(unknown user)', 'data-machine' ),
				'user_email'   => $user ? $user->user_email : '',
				'role'         => $grant->role,
				'granted_at'   => $grant->granted_at ?? '',
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
		$agent_id = self::resolve_request_agent_id( $request );
		if ( is_wp_error( $agent_id ) ) {
			return $agent_id;
		}

		$user_id = (int) $request->get_param( 'user_id' );
		$role    = $request->get_param( 'role' );

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
		try {
			$access_repo->grant_access( new \WP_Agent_Access_Grant( (string) $agent_id, $user_id, (string) $role ) );
			$ok = true;
		} catch ( \Throwable $e ) {
			$ok = false;
		}

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
		$agent_id = self::resolve_request_agent_id( $request );
		if ( is_wp_error( $agent_id ) ) {
			return $agent_id;
		}

		$user_id = (int) $request->get_param( 'user_id' );

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
		$ok          = $access_repo->revoke_access( (string) $agent_id, $user_id );

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
	// Token handlers
	// ---------------------------------------------------------------

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Resolve a REST route agent parameter to the internal agent ID.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return int|WP_Error Agent ID or REST error.
	 */
	private static function resolve_request_agent_id( WP_REST_Request $request ): int|WP_Error {
		$agent = $request->get_param( 'agent' );
		if ( null === $agent || '' === $agent ) {
			$agent = $request->get_param( 'agent_id' );
		}

		try {
			return ( new AgentIdentityResolver() )->resolve_agent_id( (string) $agent );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error(
				'agent_not_found',
				$e->getMessage(),
				array( 'status' => 404 )
			);
		}
	}
}
