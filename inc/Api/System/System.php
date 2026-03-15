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

use DataMachine\Abilities\PermissionHelper;
use WP_REST_Server;
use WP_REST_Request;
use WP_Error;
use DataMachine\Engine\Tasks\TaskRegistry;
use DataMachine\Engine\Tasks\TaskScheduler;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
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
					return PermissionHelper::can( 'manage_settings' );
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
					return PermissionHelper::can( 'manage_settings' );
				},
			)
		);

		// Run a system task immediately.
		register_rest_route(
			'datamachine/v1',
			'/system/tasks/(?P<task_type>[a-z_]+)/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'run_task' ),
				'permission_callback' => function () {
					return PermissionHelper::can( 'manage_settings' );
				},
				'args'                => array(
					'task_type' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// System task prompt definitions — list all editable prompts across tasks.
		register_rest_route(
			'datamachine/v1',
			'/system/tasks/prompts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_prompts' ),
				'permission_callback' => function () {
					return PermissionHelper::can( 'manage_settings' );
				},
			)
		);

		// Get/set/reset a specific prompt override.
		register_rest_route(
			'datamachine/v1',
			'/system/tasks/prompts/(?P<task_type>[a-z_]+)/(?P<prompt_key>[a-z_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_prompt' ),
					'permission_callback' => function () {
						return PermissionHelper::can( 'manage_settings' );
					},
					'args'                => array(
						'task_type'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'prompt_key' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( self::class, 'set_prompt' ),
					'permission_callback' => function () {
						return PermissionHelper::can( 'manage_settings' );
					},
					'args'                => array(
						'task_type'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'prompt_key' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'prompt'     => array(
							'required'    => true,
							'type'        => 'string',
							'description' => __( 'The prompt override text.', 'data-machine' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'reset_prompt' ),
					'permission_callback' => function () {
						return PermissionHelper::can( 'manage_settings' );
					},
					'args'                => array(
						'task_type'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'prompt_key' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
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
		$request;
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
		$request;
		$registry = TaskRegistry::getRegistry();
		$last_runs    = self::get_last_runs( array_keys( $registry ) );

		// Merge last-run data into each task entry.
		$tasks = array();
		foreach ( $registry as $task_type => $meta ) {
			$last_run = $last_runs[ $task_type ] ?? null;

			$tasks[] = array_merge( $meta, array(
				'last_run_at' => $last_run ? $last_run['completed_at'] : null,
				'last_status' => $last_run ? $last_run['status'] : null,
				'run_count'   => $last_run ? ( $last_run['run_count'] ?? 0 ) : 0,
			) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => $tasks,
		) );
	}

	/**
	 * Run a system task immediately.
	 *
	 * @param WP_REST_Request $request Request with task_type.
	 * @return \WP_REST_Response|WP_Error
	 * @since 0.42.0
	 */
	public static function run_task( WP_REST_Request $request ) {
		$task_type = $request->get_param( 'task_type' );

		if ( ! TaskRegistry::isRegistered( $task_type ) ) {
			return new WP_Error(
				'invalid_task_type',
				sprintf( __( 'Unknown task type: %s', 'data-machine' ), $task_type ),
				array( 'status' => 404 )
			);
		}

		$registry = TaskRegistry::getRegistry();
		$meta     = $registry[ $task_type ] ?? array();

		if ( empty( $meta['supports_run'] ) ) {
			return new WP_Error(
				'task_not_runnable',
				sprintf( __( 'Task "%s" does not support manual execution.', 'data-machine' ), $task_type ),
				array( 'status' => 400 )
			);
		}

		$job_id = TaskScheduler::schedule( $task_type, array(
			'source'       => 'admin_run_now',
			'triggered_by' => get_current_user_id(),
		) );

		if ( ! $job_id ) {
			return new WP_Error(
				'schedule_failed',
				__( 'Failed to schedule task.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'task_type' => $task_type,
				'job_id'    => $job_id,
				'message'   => sprintf( __( '%s scheduled (Job #%d).', 'data-machine' ), $meta['label'] ?? $task_type, $job_id ),
			),
		) );
	}

	/**
	 * Get all prompt definitions across all system tasks.
	 *
	 * Returns each task's editable prompts with their defaults,
	 * current overrides (if any), and available template variables.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 * @since 0.41.0
	 */
	public static function get_prompts( WP_REST_Request $request ) {
		$request;
		$handlers  = TaskRegistry::getHandlers();
		$overrides = SystemTask::getAllPromptOverrides();

		$prompts = array();

		foreach ( $handlers as $task_type => $handler_class ) {
			if ( ! class_exists( $handler_class ) ) {
				continue;
			}

			$task        = new $handler_class();
			$definitions = $task->getPromptDefinitions();

			if ( empty( $definitions ) ) {
				continue;
			}

			foreach ( $definitions as $prompt_key => $definition ) {
				$has_override = isset( $overrides[ $task_type ][ $prompt_key ] )
					&& '' !== $overrides[ $task_type ][ $prompt_key ];

				$prompts[] = array(
					'task_type'    => $task_type,
					'prompt_key'   => $prompt_key,
					'label'        => $definition['label'],
					'description'  => $definition['description'],
					'default'      => $definition['default'],
					'variables'    => $definition['variables'],
					'has_override' => $has_override,
					'override'     => $has_override ? $overrides[ $task_type ][ $prompt_key ] : null,
				);
			}
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => $prompts,
		) );
	}

	/**
	 * Get a specific prompt's definition and current value.
	 *
	 * @param WP_REST_Request $request Request with task_type and prompt_key.
	 * @return \WP_REST_Response|WP_Error
	 * @since 0.41.0
	 */
	public static function get_prompt( WP_REST_Request $request ) {
		$task_type  = $request->get_param( 'task_type' );
		$prompt_key = $request->get_param( 'prompt_key' );

		$definition = self::resolve_prompt_definition( $task_type, $prompt_key );

		if ( is_wp_error( $definition ) ) {
			return $definition;
		}

		$overrides    = SystemTask::getAllPromptOverrides();
		$has_override = isset( $overrides[ $task_type ][ $prompt_key ] )
			&& '' !== $overrides[ $task_type ][ $prompt_key ];

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'task_type'    => $task_type,
				'prompt_key'   => $prompt_key,
				'label'        => $definition['label'],
				'description'  => $definition['description'],
				'default'      => $definition['default'],
				'variables'    => $definition['variables'],
				'has_override' => $has_override,
				'override'     => $has_override ? $overrides[ $task_type ][ $prompt_key ] : null,
				'effective'    => $has_override ? $overrides[ $task_type ][ $prompt_key ] : $definition['default'],
			),
		) );
	}

	/**
	 * Set a prompt override for a specific task prompt.
	 *
	 * @param WP_REST_Request $request Request with task_type, prompt_key, and prompt.
	 * @return \WP_REST_Response|WP_Error
	 * @since 0.41.0
	 */
	public static function set_prompt( WP_REST_Request $request ) {
		$task_type  = $request->get_param( 'task_type' );
		$prompt_key = $request->get_param( 'prompt_key' );
		$prompt     = $request->get_param( 'prompt' );

		$definition = self::resolve_prompt_definition( $task_type, $prompt_key );

		if ( is_wp_error( $definition ) ) {
			return $definition;
		}

		if ( empty( $prompt ) ) {
			return new WP_Error(
				'empty_prompt',
				__( 'Prompt text cannot be empty. Use DELETE to reset to default.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$saved = SystemTask::setPromptOverride( $task_type, $prompt_key, $prompt );

		return rest_ensure_response( array(
			'success' => $saved,
			'data'    => array(
				'task_type'  => $task_type,
				'prompt_key' => $prompt_key,
				'override'   => $prompt,
			),
		) );
	}

	/**
	 * Reset a prompt override to default (remove the override).
	 *
	 * @param WP_REST_Request $request Request with task_type and prompt_key.
	 * @return \WP_REST_Response|WP_Error
	 * @since 0.41.0
	 */
	public static function reset_prompt( WP_REST_Request $request ) {
		$task_type  = $request->get_param( 'task_type' );
		$prompt_key = $request->get_param( 'prompt_key' );

		$definition = self::resolve_prompt_definition( $task_type, $prompt_key );

		if ( is_wp_error( $definition ) ) {
			return $definition;
		}

		// Set empty string to remove override.
		$reset = SystemTask::setPromptOverride( $task_type, $prompt_key, '' );

		return rest_ensure_response( array(
			'success' => $reset,
			'data'    => array(
				'task_type'  => $task_type,
				'prompt_key' => $prompt_key,
				'default'    => $definition['default'],
			),
		) );
	}

	/**
	 * Resolve and validate a prompt definition by task_type and prompt_key.
	 *
	 * @param string $task_type  Task type identifier.
	 * @param string $prompt_key Prompt key within the task.
	 * @return array|WP_Error The prompt definition or error.
	 * @since 0.41.0
	 */
	private static function resolve_prompt_definition( string $task_type, string $prompt_key ) {
		$handlers = TaskRegistry::getHandlers();

		if ( ! isset( $handlers[ $task_type ] ) ) {
			return new WP_Error(
				'invalid_task_type',
				sprintf( __( 'Unknown task type: %s', 'data-machine' ), $task_type ),
				array( 'status' => 404 )
			);
		}

		$handler_class = $handlers[ $task_type ];

		if ( ! class_exists( $handler_class ) ) {
			return new WP_Error(
				'task_class_missing',
				sprintf( __( 'Task handler class not found: %s', 'data-machine' ), $handler_class ),
				array( 'status' => 500 )
			);
		}

		$task        = new $handler_class();
		$definitions = $task->getPromptDefinitions();

		if ( ! isset( $definitions[ $prompt_key ] ) ) {
			return new WP_Error(
				'invalid_prompt_key',
				sprintf( __( 'Unknown prompt key "%s" for task type "%s".', 'data-machine' ), $prompt_key, $task_type ),
				array( 'status' => 404 )
			);
		}

		return $definitions[ $prompt_key ];
	}

	/**
	 * Get the most recent job and total run count for each task type.
	 *
	 * Queries both system and pipeline_system_task sources so the UI
	 * reflects all task executions regardless of trigger path.
	 *
	 * @param array $task_types List of task type identifiers.
	 * @return array<string, array> Task type => { last job row + run_count }.
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
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT job_id, status, created_at, completed_at
					 FROM {$table}
					 WHERE source IN ('system', 'pipeline_system_task')
					 AND JSON_UNQUOTE(JSON_EXTRACT(engine_data, '$.task_type')) = %s
					 ORDER BY job_id DESC
					 LIMIT 1",
					$task_type
				),
				ARRAY_A
			);

			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM {$table}
					 WHERE source IN ('system', 'pipeline_system_task')
					 AND JSON_UNQUOTE(JSON_EXTRACT(engine_data, '$.task_type')) = %s",
					$task_type
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( $row ) {
				$row['run_count'] = (int) $count;
				$results[ $task_type ] = $row;
			} elseif ( $count > 0 ) {
				$results[ $task_type ] = array(
					'job_id'       => null,
					'status'       => null,
					'created_at'   => null,
					'completed_at' => null,
					'run_count'    => (int) $count,
				);
			}
		}

		return $results;
	}
}
