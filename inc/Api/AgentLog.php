<?php
/**
 * REST API Agent Log Endpoint
 *
 * Provides queryable audit trail for agent actions.
 *
 * @package DataMachine\Api
 * @since 0.42.0
 */

namespace DataMachine\Api;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Database\Agents\AgentLog as AgentLogRepository;
use DataMachine\Core\Database\Agents\Agents as AgentsRepository;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AgentLog {

	/**
	 * Register REST API routes.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register /datamachine/v1/agents/<id>/log route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'datamachine/v1',
			'/agents/(?P<agent_id>\d+)/log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_list' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'agent_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'action'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'result'   => array(
						'type' => 'string',
						'enum' => array( 'allowed', 'denied', 'error' ),
					),
					'resource_type' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'period'   => array(
						'type'    => 'string',
						'default' => '7d',
						'enum'    => array( '1h', '24h', '7d', '30d', 'all' ),
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			)
		);
	}

	/**
	 * Permission callback — operators and admins can view agent logs.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public static function check_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to view agent logs.', 'data-machine' ),
				array( 'status' => 401 )
			);
		}

		$agent_id = (int) $request->get_param( 'agent_id' );
		if ( ! PermissionHelper::can_access_agent( $agent_id, 'operator' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view this agent\'s logs.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle GET /agents/{id}/log — list audit log entries.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function handle_list( WP_REST_Request $request ) {
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

		$filters = array(
			'per_page' => (int) $request->get_param( 'per_page' ),
			'page'     => (int) $request->get_param( 'page' ),
		);

		// Map period to since datetime.
		$period = $request->get_param( 'period' );
		if ( 'all' !== $period ) {
			$since = self::period_to_datetime( $period );
			if ( $since ) {
				$filters['since'] = $since;
			}
		}

		// Optional filters.
		$action = $request->get_param( 'action' );
		if ( $action ) {
			$filters['action'] = $action;
		}

		$result = $request->get_param( 'result' );
		if ( $result ) {
			$filters['result'] = $result;
		}

		$resource_type = $request->get_param( 'resource_type' );
		if ( $resource_type ) {
			$filters['resource_type'] = $resource_type;
		}

		$log_repo = new AgentLogRepository();
		$data     = $log_repo->get_for_agent( $agent_id, $filters );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data['items'],
				'meta'    => array(
					'total' => $data['total'],
					'page'  => $data['page'],
					'pages' => $data['pages'],
				),
			)
		);
	}

	/**
	 * Convert a period string to a UTC datetime.
	 *
	 * @param string $period Period string (1h, 24h, 7d, 30d).
	 * @return string|null UTC datetime string, or null for invalid input.
	 */
	private static function period_to_datetime( string $period ): ?string {
		$intervals = array(
			'1h'  => '-1 hour',
			'24h' => '-24 hours',
			'7d'  => '-7 days',
			'30d' => '-30 days',
		);

		if ( ! isset( $intervals[ $period ] ) ) {
			return null;
		}

		$dt = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$dt->modify( $intervals[ $period ] );

		return $dt->format( 'Y-m-d H:i:s' );
	}
}
