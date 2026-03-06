<?php
/**
 * Logs REST API Endpoints
 *
 * Provides REST API access to log file operations and configuration.
 * Supports per-agent-type log files and levels.
 * Requires WordPress manage_options capability for all operations.
 *
 * Endpoints:
 * - GET /datamachine/v1/logs/agent-types - Get available agent types
 * - GET /datamachine/v1/logs - Get log metadata (requires agent_type param)
 * - GET /datamachine/v1/logs/content - Get log file content (requires agent_type param)
 * - DELETE /datamachine/v1/logs - Clear log file (requires agent_type param, or agent_type=all)
 * - PUT /datamachine/v1/logs/level - Update log level (requires agent_type param)
 *
 * @package DataMachine\Api
 */

namespace DataMachine\Api;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Abilities\LogAbilities;
use DataMachine\Engine\AI\AgentType;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Logs {

	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register all log-related REST endpoints
	 */
	public static function register_routes() {

		// GET /datamachine/v1/logs/agent-types - Get available agent types
		register_rest_route(
			'datamachine/v1',
			'/logs/agent-types',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_get_agent_types' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);

		// DELETE /datamachine/v1/logs - Clear logs
		register_rest_route(
			'datamachine/v1',
			'/logs',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'handle_clear_logs' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'agent_type' => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Agent type to clear logs for, or "all" to clear all logs', 'data-machine' ),
						'validate_callback' => function ( $param ) {
							return 'all' === $param || AgentType::isValid( $param );
						},
					),
				),
			)
		);

		// GET /datamachine/v1/logs/content - Get log content
		register_rest_route(
			'datamachine/v1',
			'/logs/content',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_get_content' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'agent_type'  => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Agent type to get logs for', 'data-machine' ),
						'validate_callback' => function ( $param ) {
							return AgentType::isValid( $param );
						},
					),
					'mode'        => array(
						'required'    => false,
						'type'        => 'string',
						'default'     => 'full',
						'description' => __( 'Content mode: full or recent', 'data-machine' ),
						'enum'        => array( 'full', 'recent' ),
					),
					'limit'       => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 200,
						'description'       => __( 'Number of recent entries (when mode=recent)', 'data-machine' ),
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0 && $param <= 10000;
						},
					),
					'job_id'      => array(
						'required'          => false,
						'type'              => 'integer',
						'description'       => __( 'Filter logs by job ID', 'data-machine' ),
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
					),
					'pipeline_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'description'       => __( 'Filter logs by pipeline ID', 'data-machine' ),
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
					),
					'flow_id'     => array(
						'required'          => false,
						'type'              => 'integer',
						'description'       => __( 'Filter logs by flow ID', 'data-machine' ),
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
					),
				),
			)
		);

		// GET /datamachine/v1/logs - Get log metadata
		register_rest_route(
			'datamachine/v1',
			'/logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_get_metadata' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'agent_type' => array(
						'required'          => false,
						'type'              => 'string',
						'description'       => __( 'Agent type to get metadata for. If omitted, returns metadata for all agent types.', 'data-machine' ),
						'validate_callback' => function ( $param ) {
							return empty( $param ) || AgentType::isValid( $param );
						},
					),
				),
			)
		);

		// PUT /datamachine/v1/logs/level - Update log level
		register_rest_route(
			'datamachine/v1',
			'/logs/level',
			array(
				'methods'             => array( 'PUT', 'POST' ),
				'callback'            => array( self::class, 'handle_update_level' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'agent_type' => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Agent type to set log level for', 'data-machine' ),
						'validate_callback' => function ( $param ) {
							return AgentType::isValid( $param );
						},
					),
					'level'      => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Log level to set', 'data-machine' ),
						'validate_callback' => function ( $param ) {
							$available_levels = datamachine_get_available_log_levels();
							return array_key_exists( $param, $available_levels );
						},
					),
				),
			)
		);
	}

	/**
	 * Check if user has permission to manage logs
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
	 * Handle get agent types request
	 *
	 * GET /datamachine/v1/logs/agent-types
	 */
	public static function handle_get_agent_types( $request ) {
		$request;
		$agent_types = AgentType::getAll();

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $agent_types,
			)
		);
	}

	/**
	 * Handle clear logs request
	 *
	 * DELETE /datamachine/v1/logs?agent_type=pipeline
	 * DELETE /datamachine/v1/logs?agent_type=all
	 */
	public static function handle_clear_logs( $request ) {
		$agent_type = $request->get_param( 'agent_type' );

		$result = LogAbilities::clear( array( 'agent_type' => $agent_type ) );

		if ( $result['success'] ) {
			if ( 'all' === $agent_type ) {
				return rest_ensure_response(
					array(
						'success' => true,
						'data'    => null,
						'message' => __( 'All logs cleared successfully.', 'data-machine' ),
					)
				);
			}

			$agent_types = AgentType::getAll();
			$agent_label = $agent_types[ $agent_type ]['label'] ?? ucfirst( $agent_type );

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => null,
					'message' => sprintf(
						/* translators: %s: agent type label (e.g., Pipeline, Chat, System) */
						__( '%s logs cleared successfully.', 'data-machine' ),
						$agent_label
					),
				)
			);
		}

		return new \WP_Error(
			'clear_logs_failed',
			$result['error'] ?? __( 'Failed to clear logs.', 'data-machine' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Handle get log content request
	 *
	 * GET /datamachine/v1/logs/content?agent_type=pipeline
	 */
	public static function handle_get_content( $request ) {
		$input = array(
			'agent_type' => $request->get_param( 'agent_type' ),
			'mode'       => $request->get_param( 'mode' ),
			'limit'      => $request->get_param( 'limit' ),
		);

		$job_id = $request->get_param( 'job_id' );
		if ( null !== $job_id ) {
			$input['job_id'] = (int) $job_id;
		}

		$pipeline_id = $request->get_param( 'pipeline_id' );
		if ( null !== $pipeline_id ) {
			$input['pipeline_id'] = (int) $pipeline_id;
		}

		$flow_id = $request->get_param( 'flow_id' );
		if ( null !== $flow_id ) {
			$input['flow_id'] = (int) $flow_id;
		}

		$result = LogAbilities::readLogs( $input );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				$result['error'],
				$result['message'],
				array( 'status' => 'log_file_not_found' === $result['error'] ? 404 : 500 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Handle get log metadata request
	 *
	 * GET /datamachine/v1/logs
	 * GET /datamachine/v1/logs?agent_type=pipeline
	 */
	public static function handle_get_metadata( $request ) {
		$agent_type = $request->get_param( 'agent_type' );

		$input = array();
		if ( ! empty( $agent_type ) ) {
			$input['agent_type'] = $agent_type;
		}

		return rest_ensure_response( LogAbilities::getMetadata( $input ) );
	}

	/**
	 * Handle update log level request
	 *
	 * PUT /datamachine/v1/logs/level
	 */
	public static function handle_update_level( $request ) {
		$input = array(
			'agent_type' => $request->get_param( 'agent_type' ),
			'level'      => $request->get_param( 'level' ),
		);

		$result = LogAbilities::setLevel( $input );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				$result['error'] ?? 'set_level_failed',
				$result['message'] ?? __( 'Failed to set log level.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $result );
	}
}
