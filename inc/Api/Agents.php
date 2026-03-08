<?php
/**
 * REST API Agents Endpoint
 *
 * Lists agents the current user has access to.
 * Used by the admin UI AgentSwitcher component.
 *
 * @package DataMachine\Api
 * @since 0.41.0
 */

namespace DataMachine\Api;

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
		register_rest_route(
			'datamachine/v1',
			'/agents',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_list' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);
	}

	/**
	 * Handle GET /agents — list agents the current user can access.
	 *
	 * Admins (manage_agents capability) see all agents.
	 * Other users see only agents they have access grants for.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function handle_list( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$agents_repo = new AgentsRepository();
		$user_id     = get_current_user_id();

		// Admins see all agents.
		if ( PermissionHelper::can( 'manage_agents' ) ) {
			$all_agents = $agents_repo->get_all();
		} else {
			// Non-admins see only agents they have access to.
			$access_repo     = new AgentAccess();
			$accessible_ids  = $access_repo->get_agent_ids_for_user( $user_id );

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

		// Shape output — exclude agent_config (may contain secrets).
		$data = array();
		foreach ( $all_agents as $agent ) {
			$data[] = array(
				'agent_id'   => (int) $agent['agent_id'],
				'agent_slug' => (string) $agent['agent_slug'],
				'agent_name' => (string) $agent['agent_name'],
				'owner_id'   => (int) $agent['owner_id'],
				'status'     => (string) $agent['status'],
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
