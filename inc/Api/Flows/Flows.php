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

use DataMachine\Api\RestAccessGuard;
use DataMachine\Api\RestAbilityExecutor;
use DataMachine\Api\RestResultSpec;
use DataMachine\Core\AbilityResult;
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
					'output_mode' => array(
						'required'          => false,
						'type'              => 'string',
						'enum'              => array( 'full', 'list', 'summary', 'ids' ),
						'default'           => 'full',
						'sanitize_callback' => 'sanitize_key',
						'description'       => __( 'Output mode for returned flows: full, list, summary, or ids.', 'data-machine' ),
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
			'/flows/(?P<flow_id>\d+)/pause',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_pause_flow' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Flow ID to pause', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/(?P<flow_id>\d+)/resume',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_resume_flow' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Flow ID to resume', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/pause',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_bulk_pause' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'pipeline_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Pause all flows in this pipeline', 'data-machine' ),
					),
					'agent_id'    => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Pause all flows for this agent', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/resume',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_bulk_resume' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'pipeline_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Resume all flows in this pipeline', 'data-machine' ),
					),
					'agent_id'    => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Resume all flows for this agent', 'data-machine' ),
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
		return RestAccessGuard::for_action( 'manage_flows' )->check_permission( __( 'You do not have permission to create flows.', 'data-machine' ) );
	}

	/**
	 * Handle flow creation request
	 */
	public static function handle_create_flow( $request ) {
		$input = array(
			'pipeline_id' => (int) $request->get_param( 'pipeline_id' ),
			'flow_name'   => $request->get_param( 'flow_name' ) ?? 'Flow',
			'user_id'     => RestAccessGuard::for_action( 'manage_flows' )->acting_user_id(),
		);

		// Carry agent_id from body params or query string (agent interceptor).
		$scoped_agent_id = RestAccessGuard::for_action( 'manage_flows' )->resolve_scoped_agent_id( $request );
		if ( null !== $scoped_agent_id ) {
			$input['agent_id'] = $scoped_agent_id;
		}

		if ( $request->get_param( 'flow_config' ) ) {
			$input['flow_config'] = $request->get_param( 'flow_config' );
		}
		if ( $request->get_param( 'scheduling_config' ) ) {
			$input['scheduling_config'] = $request->get_param( 'scheduling_config' );
		}

		return RestAbilityExecutor::execute(
			'datamachine/create-flow',
			$input,
			RestResultSpec::item( null, null, 'flow_creation_failed', __( 'Failed to create flow.', 'data-machine' ), 400 )
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
		$access            = RestAccessGuard::for_action( 'manage_flows' )->authorize_agent_resource( $resource_agent_id, (int) ( $flow['user_id'] ?? 0 ), __( 'You do not have permission to delete this flow.', 'data-machine' ) );
		if ( $flow && is_wp_error( $access ) ) {
			return $access;
		}

		return RestAbilityExecutor::execute(
			'datamachine/delete-flow',
			array(
				'flow_id' => $flow_id,
			),
			RestResultSpec::item( null, null, 'flow_deletion_failed', __( 'Failed to delete flow.', 'data-machine' ), 400 )
		);
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
		$access            = RestAccessGuard::for_action( 'manage_flows' )->authorize_agent_resource( $resource_agent_id, (int) ( $flow['user_id'] ?? 0 ), __( 'You do not have permission to duplicate this flow.', 'data-machine' ) );
		if ( $flow && is_wp_error( $access ) ) {
			return $access;
		}

		return RestAbilityExecutor::execute(
			'datamachine/duplicate-flow',
			array(
				'source_flow_id' => $flow_id,
				'user_id'        => RestAccessGuard::for_action( 'manage_flows' )->acting_user_id(),
			),
			RestResultSpec::item( null, null, 'flow_duplication_failed', __( 'Failed to duplicate flow.', 'data-machine' ), 400 )
		);
	}

	/**
	 * Handle flows retrieval request with pagination support
	 */
	public static function handle_get_flows( $request ) {
		$pipeline_id     = $request->get_param( 'pipeline_id' );
		$per_page        = $request->get_param( 'per_page' ) ?? 20;
		$offset          = $request->get_param( 'offset' ) ?? 0;
		$output_mode     = $request->get_param( 'output_mode' ) ?? 'full';
		$access_guard    = RestAccessGuard::for_action( 'manage_flows' );
		$scoped_user_id  = $access_guard->resolve_scoped_user_id( $request );
		$scoped_agent_id = $access_guard->resolve_scoped_agent_id( $request );

		$ability = wp_get_ability( 'datamachine/get-flows' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$input = array(
			'pipeline_id' => $pipeline_id,
			'per_page'    => $per_page,
			'offset'      => $offset,
			'output_mode' => $output_mode,
		);
		if ( null !== $scoped_agent_id ) {
			$input['agent_id'] = $scoped_agent_id;
		} elseif ( null !== $scoped_user_id ) {
			$input['user_id'] = $scoped_user_id;
		}
		$result = $ability->execute( $input );

		if ( $pipeline_id ) {
			return AbilityResult::rest_collection_response(
				$result,
				'flows',
				array(
					'data_key'   => 'flows',
					'data_extra' => array( 'pipeline_id' => $pipeline_id ),
				),
				'ability_error'
			);
		}

		return AbilityResult::rest_collection_response( $result, 'flows', array(), 'ability_error' );
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

		$error = AbilityResult::failure_to_wp_error( $result, 'flow_not_found', __( 'Flow not found.', 'data-machine' ), 400 );
		if ( $error || empty( $result['flows'] ) ) {
			$status = 400;
			if ( $error && isset( $error->get_error_data()['status'] ) ) {
				$status = (int) $error->get_error_data()['status'];
			} elseif ( empty( $result['flows'] ) ) {
				$status = 404;
			}

			return $error ? $error : new \WP_Error( 'flow_not_found', __( 'Flow not found.', 'data-machine' ), array( 'status' => $status ) );
		}

		return AbilityResult::rest_item_response( $result, $result['flows'][0] );
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
		$access            = RestAccessGuard::for_action( 'manage_flows' )->authorize_agent_resource( $resource_agent_id, (int) ( $flow['user_id'] ?? 0 ), __( 'You do not have permission to update this flow.', 'data-machine' ) );
		if ( $flow && is_wp_error( $access ) ) {
			return $access;
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

		$error = AbilityResult::failure_to_wp_error( $result, 'update_failed', __( 'Failed to update flow', 'data-machine' ), 400 );
		if ( $error ) {
			return $error;
		}

		$flow_id = $result['flow_id'];

		$get_ability = wp_get_ability( 'datamachine/get-flows' );
		if ( $get_ability ) {
			$flow_result = $get_ability->execute( array( 'flow_id' => $flow_id ) );
			if ( ! is_wp_error( $flow_result ) && ( $flow_result['success'] ?? false ) && ! empty( $flow_result['flows'] ) ) {
				return rest_ensure_response(
					array(
						'success' => true,
						'data'    => $flow_result['flows'][0],
						'message' => __( 'Flow updated successfully', 'data-machine' ),
					)
				);
			}
		}

		return AbilityResult::rest_item_response( $result, $result['flow_data'] ?? array( 'flow_id' => $flow_id ), array( 'message' => __( 'Flow updated successfully', 'data-machine' ) ) );
	}

	/**
	 * Handle single flow pause request.
	 *
	 * POST /datamachine/v1/flows/{flow_id}/pause
	 *
	 * @since 0.59.0
	 */
	public static function handle_pause_flow( $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		return RestAbilityExecutor::execute(
			'datamachine/pause-flow',
			array( 'flow_id' => $flow_id ),
			RestResultSpec::item( null, null, 'pause_failed', __( 'Failed to pause flow.', 'data-machine' ), 400 )
		);
	}

	/**
	 * Handle single flow resume request.
	 *
	 * POST /datamachine/v1/flows/{flow_id}/resume
	 *
	 * @since 0.59.0
	 */
	public static function handle_resume_flow( $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		return RestAbilityExecutor::execute(
			'datamachine/resume-flow',
			array( 'flow_id' => $flow_id ),
			RestResultSpec::item( null, null, 'resume_failed', __( 'Failed to resume flow.', 'data-machine' ), 400 )
		);
	}

	/**
	 * Handle bulk pause request (by pipeline or agent).
	 *
	 * POST /datamachine/v1/flows/pause
	 *
	 * @since 0.59.0
	 */
	public static function handle_bulk_pause( $request ) {
		$pipeline_id = $request->get_param( 'pipeline_id' );
		$agent_id    = $request->get_param( 'agent_id' );

		if ( ! $pipeline_id && ! $agent_id ) {
			return new \WP_Error(
				'missing_scope',
				__( 'Must provide pipeline_id or agent_id.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$input = array();
		if ( $pipeline_id ) {
			$input['pipeline_id'] = (int) $pipeline_id;
		}
		if ( $agent_id ) {
			$input['agent_id'] = (int) $agent_id;
		}

		return RestAbilityExecutor::execute(
			'datamachine/pause-flow',
			$input,
			RestResultSpec::item( null, null, 'bulk_pause_failed', __( 'Failed to pause flows.', 'data-machine' ), 400 )
		);
	}

	/**
	 * Handle bulk resume request (by pipeline or agent).
	 *
	 * POST /datamachine/v1/flows/resume
	 *
	 * @since 0.59.0
	 */
	public static function handle_bulk_resume( $request ) {
		$pipeline_id = $request->get_param( 'pipeline_id' );
		$agent_id    = $request->get_param( 'agent_id' );

		if ( ! $pipeline_id && ! $agent_id ) {
			return new \WP_Error(
				'missing_scope',
				__( 'Must provide pipeline_id or agent_id.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$input = array();
		if ( $pipeline_id ) {
			$input['pipeline_id'] = (int) $pipeline_id;
		}
		if ( $agent_id ) {
			$input['agent_id'] = (int) $agent_id;
		}

		return RestAbilityExecutor::execute(
			'datamachine/resume-flow',
			$input,
			RestResultSpec::item( null, null, 'bulk_resume_failed', __( 'Failed to resume flows.', 'data-machine' ), 400 )
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

		$error = AbilityResult::failure_to_wp_error( $result, 'get_problem_flows_error', __( 'Failed to get problem flows', 'data-machine' ) );
		if ( $error ) {
			return $error;
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
		$access            = RestAccessGuard::for_action( 'manage_flows' )->authorize_agent_resource( $resource_agent_id, (int) ( $flow['user_id'] ?? 0 ), __( 'You do not have permission to access this flow.', 'data-machine' ) );
		if ( is_wp_error( $access ) ) {
			return $access;
		}

		$memory_files = $db_flows->get_flow_memory_files( $flow_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'memory_files' => $memory_files,
				),
			)
		);
	}

	/**
	 * Handle update memory files request for a flow.
	 *
	 * PUT/POST /datamachine/v1/flows/{flow_id}/memory-files
	 *
	 * @since 0.71.0 Dropped daily_memory parameter — daily memory is now a
	 *               virtual memory file governed by MemoryPolicy, no longer
	 *               configured per-flow.
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
		$access            = RestAccessGuard::for_action( 'manage_flows' )->authorize_agent_resource( $resource_agent_id, (int) ( $flow['user_id'] ?? 0 ), __( 'You do not have permission to update this flow.', 'data-machine' ) );
		if ( is_wp_error( $access ) ) {
			return $access;
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
				'data'    => array(
					'memory_files' => $memory_files,
				),
				'message' => __( 'Flow memory files updated successfully.', 'data-machine' ),
			)
		);
	}
}
