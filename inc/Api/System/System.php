<?php
/**
 * System REST API Endpoint
 *
 * System infrastructure operations for Data Machine.
 *
 * @package DataMachine\Api\System
 * @since   0.13.7
 */

namespace DataMachine\Api\System;

use WP_REST_Server;
use WP_REST_Request;
use WP_Error;
use DataMachine\Engine\AI\System\SystemAgent;
use DataMachine\Core\Database\Jobs\JobsOperations;

if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * System API Handler
 */
class System {


	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action('rest_api_init', array( self::class, 'register_routes' ));
	}

	/**
	 * Register system endpoints
	 */
	public static function register_routes() {
		// System status endpoint - could be useful for monitoring
		register_rest_route(
			'datamachine/v1',
			'/system/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_status' ),
				'permission_callback' => function () {
					return current_user_can('manage_options');
				},
			)
		);

		// System tasks registry for admin UI.
		register_rest_route(
			'datamachine/v1',
			'/system/tasks',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_tasks' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Get system status
	 *
	 * @param  WP_REST_Request $request Request object
	 * @return array|WP_Error Response data or error
	 */
	public static function get_status( WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'status'    => 'operational',
					'version'   => defined('DATAMACHINE_VERSION') ? DATAMACHINE_VERSION : 'unknown',
					'timestamp' => current_time('mysql', true),
				),
			)
		);
	}

	/**
	 * Get system tasks registry with metadata and last-run info.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 * @since 0.32.0
	 */
	public static function get_tasks( WP_REST_Request $request ) {
		$system_agent = SystemAgent::getInstance();
		$registry     = $system_agent->getTaskRegistry();
		$last_runs    = self::get_last_runs( array_keys( $registry ) );

		// Merge last-run data into each task entry.
		$tasks = array();
		foreach ( $registry as $task_type => $meta ) {
			$last_run = $last_runs[ $task_type ] ?? null;

			$tasks[] = array_merge( $meta, array(
				'last_run_at' => $last_run ? $last_run['completed_at'] : null,
				'last_status' => $last_run ? $last_run['status'] : null,
			) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => $tasks,
		) );
	}

	/**
	 * Get the most recent completed job for each task type.
	 *
	 * Queries the jobs table for system-sourced jobs, using
	 * JSON_EXTRACT to match task_type from engine_data.
	 *
	 * @param array $task_types List of task type identifiers.
	 * @return array<string, array> Task type => last job row.
	 * @since 0.32.0
	 */
	private static function get_last_runs( array $task_types ): array {
		if ( empty( $task_types ) ) {
			return array();
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'datamachine_jobs';
		$results = array();

		foreach ( $task_types as $task_type ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT job_id, status, created_at, completed_at
					 FROM {$table}
					 WHERE source = 'system'
					 AND JSON_UNQUOTE(JSON_EXTRACT(engine_data, '$.task_type')) = %s
					 ORDER BY job_id DESC
					 LIMIT 1",
					$task_type
				),
				ARRAY_A
			);

			if ( $row ) {
				$results[ $task_type ] = $row;
			}
		}

		return $results;
	}
}
