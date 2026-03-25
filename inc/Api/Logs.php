<?php
/**
 * Logs REST API Endpoints
 *
 * Provides REST API access to database-backed log operations.
 * Supports agent_id scoping, structured filtering, and pagination.
 *
 * Endpoints:
 * - GET    /datamachine/v1/logs          - Get log entries (paginated, filterable)
 * - GET    /datamachine/v1/logs/metadata - Get log metadata (counts, time range)
 * - DELETE /datamachine/v1/logs          - Clear log entries
 *
 * @package DataMachine\Api
 */

namespace DataMachine\Api;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Abilities\LogAbilities;
use DataMachine\Api\Traits\HasRegister;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Logs {

	/**
	 * Register REST API routes.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register all log-related REST endpoints.
	 */
	public static function register_routes() {

		// GET /datamachine/v1/logs - Get log entries (paginated).
		register_rest_route(
			'datamachine/v1',
			'/logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_get_logs' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'agent_id'    => array(
						'required'          => false,
						'type'              => 'integer',
						'description'       => __( 'Filter by agent ID', 'data-machine' ),
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param >= 0;
						},
					),
					'level'       => array(
						'required' => false,
						'type'     => 'string',
						'enum'     => array( 'debug', 'info', 'warning', 'error', 'critical' ),
					),
					'since'       => array(
						'required' => false,
						'type'     => 'string',
					),
					'before'      => array(
						'required' => false,
						'type'     => 'string',
					),
					'job_id'      => array(
						'required'          => false,
						'type'              => 'integer',
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
					),
					'flow_id'     => array(
						'required'          => false,
						'type'              => 'integer',
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
					),
					'pipeline_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
					),
					'search'      => array(
						'required' => false,
						'type'     => 'string',
					),
					'per_page'    => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 50,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0 && $param <= 500;
						},
					),
					'page'        => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 1,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
					),
				),
			)
		);

		// GET /datamachine/v1/logs/metadata - Get log metadata.
		register_rest_route(
			'datamachine/v1',
			'/logs/metadata',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_get_metadata' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'agent_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'description'       => __( 'Agent ID to get metadata for. If omitted, returns global metadata.', 'data-machine' ),
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param >= 0;
						},
					),
				),
			)
		);

		// DELETE /datamachine/v1/logs - Clear logs.
		register_rest_route(
			'datamachine/v1',
			'/logs',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'handle_clear_logs' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'agent_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'description'       => __( 'Agent ID to clear logs for. If omitted, clears all logs.', 'data-machine' ),
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
					),
				),
			)
		);
	}

	/**
	 * Check if user has permission to manage logs.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return true|\WP_Error
	 */
	public static function check_permission( $request ) {
		$request;
		if ( ! PermissionHelper::can( 'view_logs' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage logs.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle get log entries request.
	 *
	 * GET /datamachine/v1/logs
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function handle_get_logs( $request ) {
		$input = array();

		$filter_keys = array( 'agent_id', 'level', 'since', 'before', 'job_id', 'flow_id', 'pipeline_id', 'search', 'per_page', 'page' );
		foreach ( $filter_keys as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$input[ $key ] = is_numeric( $value ) ? (int) $value : $value;
			}
		}

		$result = LogAbilities::readLogs( $input );

		return rest_ensure_response( $result );
	}

	/**
	 * Handle get log metadata request.
	 *
	 * GET /datamachine/v1/logs/metadata
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function handle_get_metadata( $request ) {
		$input = array();

		$agent_id = $request->get_param( 'agent_id' );
		if ( null !== $agent_id ) {
			$input['agent_id'] = (int) $agent_id;
		}

		return rest_ensure_response( LogAbilities::getMetadata( $input ) );
	}

	/**
	 * Handle clear logs request.
	 *
	 * DELETE /datamachine/v1/logs
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_clear_logs( $request ) {
		$input = array();

		$agent_id = $request->get_param( 'agent_id' );
		if ( null !== $agent_id ) {
			$input['agent_id'] = (int) $agent_id;
		}

		$result = LogAbilities::clear( $input );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'clear_logs_failed',
				$result['error'] ?? __( 'Failed to clear logs.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( $result );
	}
}
