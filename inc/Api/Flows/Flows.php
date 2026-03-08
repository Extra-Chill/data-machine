<?php
/**
 * REST API Flows Endpoint
 *
 * Provides REST API access to flow CRUD operations.
 * Requires WordPress manage_options capability.
 *
 * @package DataMachine\Api\Flows
 */

namespace DataMachine\Api\Flows;

use DataMachine\Abilities\PermissionHelper;
use WP_REST_Server;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Flows {

	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register flow CRUD endpoints
	 */
	public static function register_routes() {
		register_rest_route(
			'datamachine/v1',
			'/flows',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_create_flow' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'pipeline_id'       => array(
						'required'          => true,
						'type'              => 'integer',
						'description'       => __( 'Parent pipeline ID', 'data-machine' ),
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
						'sanitize_callback' => function ( $param ) {
							return (int) $param;
						},
					),
					'flow_name'         => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'Flow',
						'description'       => __( 'Flow name', 'data-machine' ),
						'sanitize_callback' => function ( $param ) {
							return sanitize_text_field( $param );
						},
					),
					'flow_config'       => array(
						'required'    => false,
						'type'        => 'array',
						'description' => __( 'Flow configuration (handler settings per step)', 'data-machine' ),
					),
					'scheduling_config' => array(
						'required'    => false,
						'type'        => 'array',
						'description' => __( 'Scheduling configuration', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_get_flows' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'pipeline_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Optional pipeline ID to filter flows', 'data-machine' ),
					),
					'per_page'    => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
						'description'       => __( 'Number of flows per page', 'data-machine' ),
					),
					'offset'      => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
						'description'       => __( 'Offset for pagination', 'data-machine' ),
					),
					'user_id'     => array(
						'required'          => false,
						'type'              => 'integer',
						'description'       => __( 'Filter by user ID (admin only, non-admins always see own data)', 'data-machine' ),
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/(?P<flow_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'handle_get_single_flow' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'flow_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Flow ID to retrieve', 'data-machine' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'handle_delete_flow' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'flow_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Flow ID to delete', 'data-machine' ),
						),
					),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( self::class, 'handle_update_flow' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'flow_id'           => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Flow ID to update', 'data-machine' ),
						),
						'flow_name'         => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'New flow title', 'data-machine' ),
						),
						'scheduling_config' => array(
							'required'    => false,
							'type'        => 'object',
							'description' => __( 'Scheduling configuration', 'data-machine' ),
						),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/(?P<flow_id>\d+)/duplicate',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_duplicate_flow' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Source flow ID to duplicate', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/(?P<flow_id>\d+)/memory-files',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'handle_get_memory_files' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'flow_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Flow ID', 'data-machine' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'handle_update_memory_files' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'flow_id'      => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Flow ID', 'data-machine' ),
						),
						'memory_files' => array(
							'required'    => true,
							'type'        => 'array',
							'description' => __( 'Array of agent memory filenames', 'data-machine' ),
							'items'       => array(
								'type' => 'string',
							),
						),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/problems',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_get_problem_flows' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'threshold' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Minimum consecutive failures (defaults to problem_flow_threshold setting)', 'data-machine' ),
					),
				),
			)
		);
	}

	/**
	 * Check if user has permission to manage flows
	 */
	public static function check_permission( $request ) {
		$request;
		if ( ! PermissionHelper::can( 'manage_flows' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to create flows.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle flow creation request
	 */
	public static function handle_create_flow( $request ) {
		$ability = wp_get_ability( 'datamachine/create-flow' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$input = array(
			'pipeline_id' => (int) $request->get_param( 'pipeline_id' ),
			'flow_name'   => $request->get_param( 'flow_name' ) ?? 'Flow',
			'user_id'     => PermissionHelper::acting_user_id(),
		);

		if ( $request->get_param( 'flow_config' ) ) {
			$input['flow_config'] = $request->get_param( 'flow_config' );
		}
		if ( $request->get_param( 'scheduling_config' ) ) {
			$input['scheduling_config'] = $request->get_param( 'scheduling_config' );
		}

		$result = $ability->execute( $input );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'flow_creation_failed',
				$result['error'] ?? __( 'Failed to create flow.', 'data-machine' ),
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
	 * Handle flow deletion request
	 */
	public static function handle_delete_flow( $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		// Verify ownership before deleting.
		$db_flows = new \DataMachine\Core\Database\Flows\Flows();
		$flow     = $db_flows->get_flow( $flow_id );

		$resource_agent_id = isset( $flow['agent_id'] ) ? (int) $flow['agent_id'] : null;
		if ( $flow && ! PermissionHelper::owns_agent_resource( $resource_agent_id, (int) ( $flow['user_id'] ?? 0 ) ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to delete this flow.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$ability = wp_get_ability( 'datamachine/delete-flow' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$result = $ability->execute(
			array(
				'flow_id' => $flow_id,
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'flow_deletion_failed',
				$result['error'] ?? __( 'Failed to delete flow.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Handle flow duplication request
	 */
	public static function handle_duplicate_flow( $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		// Verify ownership before duplicating.
		$db_flows = new \DataMachine\Core\Database\Flows\Flows();
		$flow     = $db_flows->get_flow( $flow_id );

		$resource_agent_id = isset( $flow['agent_id'] ) ? (int) $flow['agent_id'] : null;
		if ( $flow && ! PermissionHelper::owns_agent_resource( $resource_agent_id, (int) ( $flow['user_id'] ?? 0 ) ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to duplicate this flow.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$ability = wp_get_ability( 'datamachine/duplicate-flow' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$result = $ability->execute(
			array(
				'source_flow_id' => $flow_id,
				'user_id'        => PermissionHelper::acting_user_id(),
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'flow_duplication_failed',
				$result['error'] ?? __( 'Failed to duplicate flow.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Handle flows retrieval request with pagination support
	 */
	public static function handle_get_flows( $request ) {
		$pipeline_id     = $request->get_param( 'pipeline_id' );
		$per_page        = $request->get_param( 'per_page' ) ?? 20;
		$offset          = $request->get_param( 'offset' ) ?? 0;
		$scoped_user_id  = PermissionHelper::resolve_scoped_user_id( $request );
		$scoped_agent_id = PermissionHelper::resolve_scoped_agent_id( $request );

		$ability = wp_get_ability( 'datamachine/get-flows' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$input = array(
			'pipeline_id' => $pipeline_id,
			'per_page'    => $per_page,
			'offset'      => $offset,
		);
		if ( null !== $scoped_agent_id ) {
			$input['agent_id'] = $scoped_agent_id;
		} elseif ( null !== $scoped_user_id ) {
			$input['user_id'] = $scoped_user_id;
		}
		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result['success'] ) {
			return new \WP_Error( 'ability_error', $result['error'], array( 'status' => 500 ) );
		}

		if ( $pipeline_id ) {
			return rest_ensure_response(
				array(
					'success'  => true,
					'data'     => array(
						'pipeline_id' => $pipeline_id,
						'flows'       => $result['flows'],
					),
					'total'    => $result['total'],
					'per_page' => $result['per_page'],
					'offset'   => $result['offset'],
				)
			);
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'data'     => $result['flows'],
				'total'    => $result['total'] ?? count( $result['flows'] ),
				'per_page' => $result['per_page'] ?? 20,
				'offset'   => $result['offset'] ?? 0,
			)
		);
	}

	/**
	 * Handle single flow retrieval request with scheduling metadata
	 */
	public static function handle_get_single_flow( $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		$ability = wp_get_ability( 'datamachine/get-flows' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$result = $ability->execute( array( 'flow_id' => $flow_id ) );

		if ( ! $result['success'] || empty( $result['flows'] ) ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'not found' ) || empty( $result['flows'] ) ) {
				$status = 404;
			}

			return new \WP_Error(
				'flow_not_found',
				$result['error'] ?? __( 'Flow not found.', 'data-machine' ),
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['flows'][0],
			)
		);
	}

	/**
	 * Handle flow update request (title and/or scheduling)
	 *
	 * PATCH /datamachine/v1/flows/{id}
	 */
	public static function handle_update_flow( $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		// Verify ownership before updating.
		$db_flows = new \DataMachine\Core\Database\Flows\Flows();
		$flow     = $db_flows->get_flow( $flow_id );

		$resource_agent_id = isset( $flow['agent_id'] ) ? (int) $flow['agent_id'] : null;
		if ( $flow && ! PermissionHelper::owns_agent_resource( $resource_agent_id, (int) ( $flow['user_id'] ?? 0 ) ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to update this flow.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$ability = wp_get_ability( 'datamachine/update-flow' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$input = array(
			'flow_id' => $flow_id,
		);

		$flow_name         = $request->get_param( 'flow_name' );
		$scheduling_config = $request->get_param( 'scheduling_config' );

		if ( null !== $flow_name ) {
			$input['flow_name'] = $flow_name;
		}
		if ( null !== $scheduling_config ) {
			$input['scheduling_config'] = $scheduling_config;
		}

		$result = $ability->execute( $input );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'update_failed',
				$result['error'] ?? __( 'Failed to update flow', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$flow_id = $result['flow_id'];

		$get_ability = wp_get_ability( 'datamachine/get-flows' );
		if ( $get_ability ) {
			$flow_result = $get_ability->execute( array( 'flow_id' => $flow_id ) );
			if ( ( $flow_result['success'] ?? false ) && ! empty( $flow_result['flows'] ) ) {
				return rest_ensure_response(
					array(
						'success' => true,
						'data'    => $flow_result['flows'][0],
						'message' => __( 'Flow updated successfully', 'data-machine' ),
					)
				);
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['flow_data'] ?? array( 'flow_id' => $flow_id ),
				'message' => __( 'Flow updated successfully', 'data-machine' ),
			)
		);
	}

	/**
	 * Handle problem flows retrieval request.
	 *
	 * Returns flows with consecutive failures at or above the threshold.
	 *
	 * GET /datamachine/v1/flows/problems
	 */
	public static function handle_get_problem_flows( $request ) {
		$threshold = $request->get_param( 'threshold' );

		$ability = wp_get_ability( 'datamachine/get-problem-flows' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$input = array();
		if ( null !== $threshold && $threshold > 0 ) {
			$input['threshold'] = (int) $threshold;
		}

		$result = $ability->execute( $input );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'get_problem_flows_error',
				$result['error'] ?? __( 'Failed to get problem flows', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		$problem_flows = array_merge( $result['failing'] ?? array(), $result['idle'] ?? array() );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'problem_flows' => $problem_flows,
					'total'         => $result['count'] ?? count( $problem_flows ),
					'threshold'     => $result['threshold'] ?? 3,
					'failing'       => $result['failing'] ?? array(),
					'idle'          => $result['idle'] ?? array(),
				),
			)
		);
	}

	/**
	 * Handle get memory files request for a flow.
	 *
	 * GET /datamachine/v1/flows/{flow_id}/memory-files
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function handle_get_memory_files( $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		$db_flows = new \DataMachine\Core\Database\Flows\Flows();
		$flow     = $db_flows->get_flow( $flow_id );

		if ( ! $flow ) {
			return new \WP_Error(
				'flow_not_found',
				__( 'Flow not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		$resource_agent_id = isset( $flow['agent_id'] ) ? (int) $flow['agent_id'] : null;
		if ( ! PermissionHelper::owns_agent_resource( $resource_agent_id, (int) ( $flow['user_id'] ?? 0 ) ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this flow.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$memory_files = $db_flows->get_flow_memory_files( $flow_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $memory_files,
			)
		);
	}

	/**
	 * Handle update memory files request for a flow.
	 *
	 * PUT/POST /datamachine/v1/flows/{flow_id}/memory-files
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function handle_update_memory_files( $request ) {
		$flow_id      = (int) $request->get_param( 'flow_id' );
		$params       = $request->get_json_params();
		$memory_files = $params['memory_files'] ?? array();

		$db_flows = new \DataMachine\Core\Database\Flows\Flows();
		$flow     = $db_flows->get_flow( $flow_id );

		if ( ! $flow ) {
			return new \WP_Error(
				'flow_not_found',
				__( 'Flow not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		$resource_agent_id = isset( $flow['agent_id'] ) ? (int) $flow['agent_id'] : null;
		if ( ! PermissionHelper::owns_agent_resource( $resource_agent_id, (int) ( $flow['user_id'] ?? 0 ) ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to update this flow.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		// Sanitize filenames.
		$memory_files = array_map( 'sanitize_file_name', $memory_files );
		$memory_files = array_values( array_filter( $memory_files ) );

		$result = $db_flows->update_flow_memory_files( $flow_id, $memory_files );

		if ( ! $result ) {
			return new \WP_Error(
				'update_failed',
				__( 'Failed to update memory files.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $memory_files,
				'message' => __( 'Flow memory files updated successfully.', 'data-machine' ),
			)
		);
	}
}
