<?php
/**
 * System Abilities
 *
 * WordPress 6.9 Abilities API primitives for system infrastructure operations.
 * Handles session title generation and other system-level tasks.
 *
 * @package DataMachine\Abilities
 * @since   0.13.7
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;

use DataMachine\Engine\AI\RequestBuilder;
use AgentsAPI\AI\WP_Agent_Message;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\Tasks\RecurringRejectionTracker;
use DataMachine\Engine\Tasks\RecurringScheduler;
use DataMachine\Engine\Tasks\TaskScheduler;
use DataMachine\Engine\Tasks\TaskRegistry;

defined('ABSPATH') || exit;

class SystemAbilities {


	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerSessionTitleAbility();
			$this->registerHealthCheckAbility();
			// registerGitHubIssueAbility moved to data-machine-code extension.
			$this->registerRunTaskAbility();
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	private function registerSessionTitleAbility(): void {
		wp_register_ability(
			'datamachine/generate-session-title',
			array(
				'label'               => 'Generate Session Title',
				'description'         => 'Generate an AI-powered title for a chat session based on conversation content',
				'category'            => 'datamachine-system',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'session_id' => array(
							'type'        => 'string',
							'description' => 'UUID of the chat session to generate title for',
						),
						'force'      => array(
							'type'        => 'boolean',
							'description' => 'Force regeneration even if title already exists',
							'default'     => false,
						),
					),
					'required'   => array( 'session_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'title'   => array( 'type' => 'string' ),
						'method'  => array(
							'type' => 'string',
							'enum' => array( 'ai', 'fallback', 'existing' ),
						),
						'message' => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'generateSessionTitle' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => false ),
			)
		);
	}

	private function registerHealthCheckAbility(): void {
		wp_register_ability(
			'datamachine/system-health-check',
			array(
				'label'               => __( 'System Health Check', 'data-machine' ),
				'description'         => __( 'Unified health diagnostics for Data Machine and extensions', 'data-machine' ),
				'category'            => 'datamachine-system',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'types'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Check types to run. Use "all" for all default checks, or specific type IDs.',
						),
						'options' => array(
							'type'        => 'object',
							'description' => 'Type-specific options (scope, limit, url, etc.)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'results'   => array( 'type' => 'object' ),
						'summary'   => array( 'type' => 'string' ),
						'available' => array(
							'type'        => 'array',
							'description' => 'List of available check types',
						),
					),
				),
				'execute_callback'    => array( $this, 'executeHealthCheck' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Get registered health check providers.
	 *
	 * Extensions register via filter:
	 * add_filter( 'datamachine_system_health_checks', function( $checks ) {
	 *     $checks['events'] = array(
	 *         'label'    => 'Event Health',
	 *         'callback' => array( EventHealthAbilities::class, 'executeHealthCheck' ),
	 *         'default'  => true, // included in 'all'
	 *     );
	 *     return $checks;
	 * } );
	 *
	 * @return array Registered health checks
	 */
	private function getRegisteredChecks(): array {
		$checks = array(
			'system'    => array(
				'label'    => __( 'System Diagnostics', 'data-machine' ),
				'callback' => array( $this, 'runSystemDiagnostics' ),
				'default'  => true,
			),
			'scheduler' => array(
				'label'    => __( 'Scheduler Health', 'data-machine' ),
				'callback' => array( $this, 'runSchedulerDiagnostics' ),
				'default'  => true,
			),
			'ownership' => array(
				'label'    => __( 'Agent Ownership', 'data-machine' ),
				'callback' => array( $this, 'runOwnershipDiagnostics' ),
				'default'  => true,
			),
		);

		return apply_filters( 'datamachine_system_health_checks', $checks );
	}

	/**
	 * Execute unified health check.
	 *
	 * @param array $input Input with optional 'types' and 'options'
	 * @return array Health check results
	 */
	public function executeHealthCheck( array $input ): array {
		$requested_types = $input['types'] ?? array( 'all' );
		$options         = $input['options'] ?? array();
		$checks          = $this->getRegisteredChecks();
		$results         = array();

		if ( empty( $requested_types ) ) {
			$requested_types = array( 'all' );
		}

		$run_all = in_array( 'all', $requested_types, true );

		foreach ( $checks as $type_id => $check ) {
			$should_run = $run_all
				? ( $check['default'] ?? true )
				: in_array( $type_id, $requested_types, true );

			if ( ! $should_run ) {
				continue;
			}

			$check_options       = $options[ $type_id ] ?? $options;
			$results[ $type_id ] = array(
				'label'  => $check['label'],
				'result' => call_user_func( $check['callback'], $check_options ),
			);
		}

		return array(
			'success'   => true,
			'results'   => $results,
			'summary'   => $this->buildSummary( $results ),
			'available' => array_keys( $checks ),
		);
	}

	/**
	 * Run core system diagnostics.
	 *
	 * @param array $options Optional check options
	 * @return array System diagnostic results
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Shared diagnostic callback signature receives per-check options.
	private function runSystemDiagnostics( array $options = array() ): array {
		return array(
			'version'     => defined( 'DATAMACHINE_VERSION' ) ? DATAMACHINE_VERSION : 'unknown',
			'php_version' => PHP_VERSION,
			'wp_version'  => get_bloginfo( 'version' ),
			'abilities'   => $this->listRegisteredAbilities(),
			'rest_status' => $this->checkRestApi(),
		);
	}

	/**
	 * Run scheduler diagnostics for Data Machine's Action Scheduler work.
	 *
	 * Data Machine owns schedule definitions and job execution, but local/dev
	 * installs still need something outside WordPress to invoke WP-Cron or the
	 * Action Scheduler runner. This check makes that wake-up gap visible without
	 * trying to install host-level cron from inside the plugin.
	 *
	 * @param array $options Optional check options.
	 * @return array Scheduler diagnostic results.
	 */
	private function runSchedulerDiagnostics( array $options = array() ): array {
		$stale_threshold = max( 60, (int) ( $options['stale_threshold_seconds'] ?? 900 ) );
		$now             = time();
		$wp_cron_next    = wp_next_scheduled( 'action_scheduler_run_queue' );
		$wp_cron_overdue = is_int( $wp_cron_next ) ? max( 0, $now - $wp_cron_next ) : null;
		$pending         = $this->getActionSchedulerGroupStats( 'pending' );
		$complete        = $this->getActionSchedulerGroupStats( 'complete' );
		$due             = $this->getActionSchedulerGroupStats( 'pending', true );
		$daily_memory    = $this->getActionSchedulerHookStats( 'datamachine_recurring_daily_memory_generation' );

		// Recurring bindings rejected by TaskScheduler::schedule() on every
		// tick are a silent, permanent failure: the task never runs and the
		// only signal is N identical error rows. RecurringRejectionTracker
		// correlates those rejections per schedule_id; a degraded schedule
		// here is the worst scheduler state, so it outranks 'stale'.
		$rejected_schedules = RecurringRejectionTracker::degraded();

		$status = 'ok';
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\\ActionScheduler' ) ) {
			$status = 'unavailable';
		} elseif ( ! empty( $rejected_schedules ) ) {
			$status = 'failing';
		} elseif ( $due['count'] > 0 || ( null !== $wp_cron_overdue && $wp_cron_overdue > $stale_threshold ) ) {
			$status = 'stale';
		}

		$message = match ( $status ) {
			'ok'          => __( 'scheduler current', 'data-machine' ),
			'failing'     => sprintf(
				/* translators: %d: number of recurring schedules rejected on every tick. */
				_n(
					'%d recurring schedule rejected on every tick and never running',
					'%d recurring schedules rejected on every tick and never running',
					count( $rejected_schedules ),
					'data-machine'
				),
				count( $rejected_schedules )
			),
			'stale'       => __( 'scheduler has overdue work; invoke WordPress cron, run wp datamachine drain, or run a specific task with wp datamachine system run <task_type> --wait', 'data-machine' ),
			'unavailable' => __( 'Action Scheduler is unavailable', 'data-machine' ),
			default       => __( 'scheduler status unknown', 'data-machine' ),
		};

		// Compact, render-friendly view of each persistently-rejected binding.
		$rejected_report = array();
		foreach ( $rejected_schedules as $schedule_id => $entry ) {
			$rejected_report[] = array(
				'schedule_id'        => $schedule_id,
				'task_type'          => $entry['task_type'] ?? '',
				'consecutive_count'  => (int) ( $entry['count'] ?? 0 ),
				'reason'             => $entry['reason'] ?? '',
				'first_rejected_gmt' => $entry['first_rejected_gmt'] ?? null,
				'last_rejected_gmt'  => $entry['last_rejected_gmt'] ?? null,
			);
		}

		return array(
			'status'                  => $status,
			'message'                 => $message,
			'stale_threshold_seconds' => $stale_threshold,
			'rejected_schedules'      => $rejected_report,
			'action_scheduler'        => array(
				'available'        => function_exists( 'as_get_scheduled_actions' ),
				'class_loaded'     => class_exists( '\\ActionScheduler' ),
				'is_initialized'   => class_exists( '\\ActionScheduler' ) && method_exists( '\\ActionScheduler', 'is_initialized' ) ? \ActionScheduler::is_initialized() : null,
				'group'            => RecurringScheduler::GROUP,
				'pending_count'    => $pending['count'],
				'due_count'        => $due['count'],
				'oldest_due_gmt'   => $due['oldest_gmt'],
				'next_pending_gmt' => $pending['oldest_gmt'],
				'last_attempt_gmt' => $complete['last_attempt_gmt'],
				'table_sizes'      => function_exists( 'as_get_scheduled_actions' ) && class_exists( '\\ActionScheduler' )
					? \DataMachine\Engine\AI\System\Tasks\Retention\RetentionCleanup::actionSchedulerTableSizes()
					: null,
			),
			'wp_cron'                 => array(
				'action_scheduler_run_queue_next_gmt' => is_int( $wp_cron_next ) ? gmdate( 'Y-m-d H:i:s', $wp_cron_next ) : null,
				'action_scheduler_run_queue_overdue_seconds' => $wp_cron_overdue,
			),
			'daily_memory'            => array(
				'next_pending_gmt' => $daily_memory['pending']['oldest_gmt'],
				'last_attempt_gmt' => $daily_memory['complete']['last_attempt_gmt'],
			),
			'recommendation'          => match ( $status ) {
				'failing' => __( 'One or more recurring schedules are rejected on every tick and never run. Inspect them with wp datamachine logs (filter error_code recurring_schedule_persistently_rejected) and fix the binding or its prerequisites (agent ownership, task registration, ability availability).', 'data-machine' ),
				'stale'   => __( 'For local or low-traffic installs, schedule an external wake-up such as wp cron event run --due-now. To remediate only daily memory for an affected agent, run wp datamachine system run daily_memory_generation --param=agent_slug=<slug> --wait; for all overdue work, run wp datamachine drain.', 'data-machine' ),
				default   => null,
			},
		);
	}

	/**
	 * Report rows that still execute through legacy agent-less ownership paths.
	 *
	 * @param array $options Optional check options.
	 * @return array Ownership diagnostic results.
	 */
	private function runOwnershipDiagnostics( array $options = array() ): array {
		unset( $options );

		$pipeline_count = ( new Pipelines() )->count_by_agent_id( null );
		$flow_count     = ( new Flows() )->count_by_agent_id( null );
		$status         = ( $pipeline_count > 0 || $flow_count > 0 ) ? 'warning' : 'ok';

		return array(
			'status'            => $status,
			'message'           => 'ok' === $status
				? __( 'all pipelines and flows have agent ownership', 'data-machine' )
				: __( 'unowned pipelines or flows can run outside the agent ownership envelope', 'data-machine' ),
			'unowned_pipelines' => $pipeline_count,
			'unowned_flows'     => $flow_count,
			'recommendation'    => 'warning' === $status
				? __( 'Inspect with wp datamachine pipelines orphans and wp datamachine flows orphans, then reassign with --where-null.', 'data-machine' )
				: null,
		);
	}

	/**
	 * Get Action Scheduler aggregate stats for Data Machine's group.
	 *
	 * @param string $status   Action Scheduler status.
	 * @param bool   $due_only Whether to count only actions due now.
	 * @return array{count:int,oldest_gmt:?string,newest_gmt:?string,last_attempt_gmt:?string}
	 */
	private function getActionSchedulerGroupStats( string $status, bool $due_only = false ): array {
		return $this->queryActionSchedulerStats(
			array(
				'group'    => RecurringScheduler::GROUP,
				'status'   => $status,
				'due_only' => $due_only,
			)
		);
	}

	/**
	 * Get pending and complete stats for an Action Scheduler hook.
	 *
	 * @param string $hook Action Scheduler hook.
	 * @return array{pending:array,complete:array}
	 */
	private function getActionSchedulerHookStats( string $hook ): array {
		return array(
			'pending'  => $this->queryActionSchedulerStats(
				array(
					'group'  => RecurringScheduler::GROUP,
					'hook'   => $hook,
					'status' => 'pending',
				)
			),
			'complete' => $this->queryActionSchedulerStats(
				array(
					'group'  => RecurringScheduler::GROUP,
					'hook'   => $hook,
					'status' => 'complete',
				)
			),
		);
	}

	/**
	 * Query Action Scheduler tables for aggregate stats.
	 *
	 * @param array $args Query args.
	 * @return array{count:int,oldest_gmt:?string,newest_gmt:?string,last_attempt_gmt:?string}
	 */
	private function queryActionSchedulerStats( array $args ): array {
		global $wpdb;

		$default = array(
			'count'            => 0,
			'oldest_gmt'       => null,
			'newest_gmt'       => null,
			'last_attempt_gmt' => null,
		);

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';
		$where         = array( 'a.status = %s' );
		$values        = array( (string) ( $args['status'] ?? 'pending' ) );

		if ( ! empty( $args['group'] ) ) {
			$where[]  = 'g.slug = %s';
			$values[] = (string) $args['group'];
		}

		if ( ! empty( $args['hook'] ) ) {
			$where[]  = 'a.hook = %s';
			$values[] = (string) $args['hook'];
		}

		if ( ! empty( $args['due_only'] ) ) {
			$where[]  = 'a.scheduled_date_gmt <= %s';
			$values[] = gmdate( 'Y-m-d H:i:s' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are derived from $wpdb->prefix.
		$sql = "SELECT COUNT(*) AS count, MIN(a.scheduled_date_gmt) AS oldest_gmt, MAX(a.scheduled_date_gmt) AS newest_gmt, MAX(a.last_attempt_gmt) AS last_attempt_gmt FROM {$actions_table} a INNER JOIN {$groups_table} g ON g.group_id = a.group_id WHERE " . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- SQL uses prepared placeholders assembled from fixed fragments.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $values ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return $default;
		}

		return array(
			'count'            => (int) ( $row['count'] ?? 0 ),
			'oldest_gmt'       => $this->normalizeNullableDate( $row['oldest_gmt'] ?? null ),
			'newest_gmt'       => $this->normalizeNullableDate( $row['newest_gmt'] ?? null ),
			'last_attempt_gmt' => $this->normalizeNullableDate( $row['last_attempt_gmt'] ?? null ),
		);
	}

	/**
	 * Normalize empty Action Scheduler date values.
	 *
	 * @param mixed $value Date value from the DB.
	 * @return string|null Normalized date, or null when unset.
	 */
	private function normalizeNullableDate( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}

		return $value;
	}

	/**
	 * List all datamachine abilities.
	 *
	 * @return array List of ability IDs
	 */
	private function listRegisteredAbilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$all = wp_get_abilities();

		return array_values(
			array_filter(
				array_keys( $all ),
				fn( $id ) => str_starts_with( $id, 'datamachine' )
			)
		);
	}

	/**
	 * Check REST API status.
	 *
	 * @return array REST API status info
	 */
	private function checkRestApi(): array {
		$server     = rest_get_server();
		$namespaces = $server->get_namespaces();

		return array(
			'namespace_registered' => in_array( 'datamachine/v1', $namespaces, true ),
		);
	}

	/**
	 * Build summary message from results.
	 *
	 * @param array $results Health check results
	 * @return string Summary message
	 */
	private function buildSummary( array $results ): string {
		$parts = array();

		foreach ( $results as $type_id => $data ) {
			$result = $data['result'] ?? array();

			if ( isset( $result['error'] ) ) {
				$parts[] = $data['label'] . ': error';
				continue;
			}

			if ( isset( $result['message'] ) ) {
				$parts[] = $data['label'] . ': ' . $result['message'];
			} else {
				$parts[] = $data['label'] . ': completed';
			}
		}

		return implode( '; ', $parts );
	}

	// registerGitHubIssueAbility() moved to data-machine-code extension.

	/**
	 * Register the run-task ability.
	 *
	 * Generic ability for manually triggering any registered system task
	 * that supports manual execution (supports_run: true in task meta).
	 *
	 * @since 0.42.0
	 */
	private function registerRunTaskAbility(): void {
		wp_register_ability(
			'datamachine/run-task',
			array(
				'label'               => __( 'Run System Task', 'data-machine' ),
				'description'         => __( 'Manually trigger a registered system task for immediate execution.', 'data-machine' ),
				'category'            => 'datamachine-system',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'task_type'   => array(
							'type'        => 'string',
							'description' => 'Registered task type identifier (e.g. alt_text_generation, daily_memory_generation).',
						),
						'task_params' => array(
							'type'        => 'object',
							'description' => 'Structured task parameters passed through to the scheduled SystemTask.',
						),
					),
					'required'   => array( 'task_type' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'task_type' => array( 'type' => 'string' ),
						'job_id'    => array( 'type' => array( 'integer', 'null' ) ),
						'message'   => array( 'type' => 'string' ),
						'error'     => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'runTask' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Execute the run-task ability.
	 *
	 * @param array $input { task_type: string, task_params?: array }
	 * @return array Result with success, job_id, message.
	 * @since 0.42.0
	 */
	public static function runTask( array $input ): array {
		$task_type   = $input['task_type'] ?? '';
		$task_params = is_array( $input['task_params'] ?? null ) ? $input['task_params'] : array();

		if ( empty( $task_type ) ) {
			return array(
				'success' => false,
				'error'   => 'task_type is required.',
				'message' => 'Specify which task to run.',
			);
		}

		if ( ! TaskRegistry::isRegistered( $task_type ) ) {
			return array(
				'success' => false,
				'error'   => "Unknown task type: {$task_type}",
				'message' => 'Task type is not registered.',
			);
		}

		$registry = TaskRegistry::getRegistry();
		$meta     = $registry[ $task_type ] ?? array();

		if ( empty( $meta['supports_run'] ) ) {
			return array(
				'success' => false,
				'error'   => "Task '{$task_type}' does not support manual execution.",
				'message' => 'This task can only be triggered by its configured event or schedule.',
			);
		}

		$task_context = self::extractRunTaskContext( $task_params );

		$validation = self::validateRunTaskParams( $task_type, $meta, $task_params );
		if ( ! $validation['success'] ) {
			return array(
				'success' => false,
				'error'   => $validation['error'],
				'message' => $validation['message'],
			);
		}

		$task_params = $validation['task_params'];

		$job_id = TaskScheduler::schedule( $task_type, array_merge( $task_params, array(
			'source'       => 'admin_run_now',
			'triggered_by' => get_current_user_id(),
		) ), $task_context );

		if ( ! $job_id ) {
			$scheduler_error = TaskScheduler::getLastScheduleError();
			if ( is_array( $scheduler_error ) && ! empty( $scheduler_error['message'] ) ) {
				return array(
					'success' => false,
					'error'   => $scheduler_error['error'],
					'message' => $scheduler_error['message'],
				);
			}

			return array(
				'success' => false,
				'error'   => 'Failed to schedule task.',
				'message' => 'TaskScheduler returned false — check logs for details.',
			);
		}

		$label = $meta['label'] ?? $task_type;

		return array(
			'success'   => true,
			'task_type' => $task_type,
			'job_id'    => $job_id,
			'message'   => "{$label} scheduled (Job #{$job_id}).",
		);
	}

	/**
	 * Extract scheduler context from manual run params.
	 *
	 * @param array $params Task params passed by reference.
	 * @return array Scheduler context.
	 */
	private static function extractRunTaskContext( array &$params ): array {
		$context = array();
		if ( array_key_exists( 'agent', $params ) ) {
			$agent = $params['agent'];
			unset( $params['agent'] );

			if ( is_int( $agent ) || ( is_string( $agent ) && is_numeric( $agent ) ) ) {
				$context['agent_id'] = (int) $agent;
			} elseif ( null !== $agent && '' !== trim( (string) $agent ) ) {
				$context['agent_slug'] = (string) $agent;
			}
		}

		foreach ( array( 'agent_id', 'agent_slug' ) as $key ) {
			if ( array_key_exists( $key, $params ) ) {
				$context[ $key ] = $params[ $key ];
				unset( $params[ $key ] );
			}
		}

		return $context;
	}

	/**
	 * Validate manual run params against task-declared safety metadata.
	 *
	 * The schema is intentionally small: task authors may declare accepted,
	 * required, and scope param names without dragging JSON Schema into the
	 * SystemTask contract.
	 *
	 * @param string $task_type Task type identifier.
	 * @param array  $meta      Normalized task registry metadata.
	 * @param array  $params    Proposed task params.
	 * @return array{success:false,error:string,message:string}|array{success:true,task_params:array}
	 */
	private static function validateRunTaskParams( string $task_type, array $meta, array $params ): array {
		$schema          = is_array( $meta['params_schema'] ?? null ) ? $meta['params_schema'] : array();
		$accepted_params = self::stringList( $schema['accepted'] ?? $schema['accepted_params'] ?? array() );
		$required_params = self::stringList( $schema['required'] ?? $schema['required_params'] ?? array() );
		$scope_params    = self::stringList( $schema['scope'] ?? $schema['scope_params'] ?? array() );

		$core_params = array( 'dry_run', 'apply', 'mode' );
		if ( ! empty( $accepted_params ) ) {
			$allowed = array_unique( array_merge( $accepted_params, $required_params, $scope_params, $core_params ) );
			$unknown = array_diff( array_keys( $params ), $allowed );
			if ( ! empty( $unknown ) ) {
				return array(
					'success' => false,
					'error'   => sprintf( "Task '%s' does not accept param(s): %s", $task_type, implode( ', ', $unknown ) ),
					'message' => 'Remove unsupported task params or update the task params_schema.',
				);
			}
		}

		$missing = array();
		foreach ( $required_params as $required_param ) {
			if ( ! self::hasNonEmptyParam( $params, $required_param ) ) {
				$missing[] = $required_param;
			}
		}
		if ( ! empty( $missing ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( "Task '%s' is missing required param(s): %s", $task_type, implode( ', ', $missing ) ),
				'message' => 'Provide the required task params and retry.',
			);
		}

		if ( ! empty( $meta['requires_scope'] ) ) {
			$scope_candidates = ! empty( $scope_params ) ? $scope_params : $required_params;
			if ( empty( $scope_candidates ) ) {
				return array(
					'success' => false,
					'error'   => sprintf( "Task '%s' declares requires_scope but no scope params_schema entries.", $task_type ),
					'message' => 'Declare params_schema.scope for tasks that require scope.',
				);
			}

			$has_scope = false;
			foreach ( $scope_candidates as $scope_param ) {
				if ( self::hasNonEmptyParam( $params, $scope_param ) ) {
					$has_scope = true;
					break;
				}
			}
			if ( ! $has_scope ) {
				return array(
					'success' => false,
					'error'   => sprintf( "Task '%s' requires an explicit scope param: %s", $task_type, implode( ', ', $scope_candidates ) ),
					'message' => 'Provide a scope param before scheduling this task.',
				);
			}
		}

		if ( ! empty( $meta['mutates'] ) && ! empty( $meta['supports_dry_run'] ) && empty( $params['apply'] ) && ! array_key_exists( 'dry_run', $params ) ) {
			$params['dry_run'] = true;
		}

		return array(
			'success'     => true,
			'task_params' => $params,
		);
	}

	/**
	 * Normalize a list of string param names.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	private static function stringList( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = array( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $value ), static fn( string $item ): bool => '' !== $item ) );
	}

	/**
	 * Whether a param exists with a non-empty value.
	 *
	 * @param array  $params Params array.
	 * @param string $key    Param key.
	 * @return bool
	 */
	private static function hasNonEmptyParam( array $params, string $key ): bool {
		return array_key_exists( $key, $params ) && null !== $params[ $key ] && '' !== $params[ $key ];
	}

	public static function generateSessionTitle( array $input ): array {
		$session_id = $input['session_id'];
		$force      = $input['force'] ?? false;

		$chat_db = ConversationStoreFactory::get();
		$session = $chat_db->get_session($session_id);

		if ( ! $session ) {
			return array(
				'success' => false,
				'error'   => 'Session not found',
				'message' => 'Unable to find chat session',
			);
		}

		// Check if title already exists and we're not forcing regeneration
		if ( ! empty($session['title']) && ! $force ) {
			return array(
				'success' => true,
				'title'   => $session['title'],
				'method'  => 'existing',
				'message' => 'Title already exists',
			);
		}

		$messages = $session['messages'] ?? array();
		if ( empty($messages) ) {
			return array(
				'success' => false,
				'error'   => 'No messages found',
				'message' => 'Session has no conversation messages',
			);
		}

		// Extract first user message and first assistant response
		$first_user_message       = null;
		$first_assistant_response = null;

		foreach ( $messages as $msg ) {
			$msg     = WP_Agent_Message::normalize( $msg );
			$role    = $msg['role'] ?? '';
			$content = $msg['content'] ?? '';
			$content = is_string( $content ) ? $content : wp_json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( 'user' === $role && null === $first_user_message && ! empty($content) ) {
				$first_user_message = $content;
			} elseif ( 'assistant' === $role && null === $first_assistant_response && ! empty($content) ) {
				$first_assistant_response = $content;
			}

			if ( null !== $first_user_message && null !== $first_assistant_response ) {
				break;
			}
		}

		if ( null === $first_user_message ) {
			return array(
				'success' => false,
				'error'   => 'No user message found',
				'message' => 'Session has no user messages to generate title from',
			);
		}

		// Check if AI titles are enabled
		$ai_titles_enabled = PluginSettings::get('chat_ai_titles_enabled', true);

		if ( ! $ai_titles_enabled ) {
			$title   = self::generateTruncatedTitle($first_user_message);
			$success = $chat_db->update_title($session_id, $title);

			return array(
				'success' => $success,
				'title'   => $title,
				'method'  => 'fallback',
				'message' => $success ? 'Title generated using fallback method' : 'Failed to update session title',
			);
		}

		// Try AI generation
		$title = self::generateAITitle($first_user_message, $first_assistant_response);

		if ( null === $title ) {
			$title  = self::generateTruncatedTitle($first_user_message);
			$method = 'fallback';
		} else {
			$method = 'ai';
		}

		$success = $chat_db->update_title($session_id, $title);

		if ( $success ) {
			do_action(
				'datamachine_log',
				'debug',
				'Session title generated',
				array(
					'session_id' => $session_id,
					'title'      => $title,
					'method'     => $method,
					'context'    => 'system',
				)
			);
		}

		return array(
			'success' => $success,
			'title'   => $title,
			'method'  => $method,
			'message' => $success ? 'Title generated successfully' : 'Failed to update session title',
		);
	}

	private static function generateAITitle( string $first_user_message, ?string $first_assistant_response ): ?string {
		$chat_defaults = PluginSettings::resolveModelForAgentMode( null, 'chat' );
		$provider      = $chat_defaults['provider'];
		$model         = $chat_defaults['model'];

		if ( empty($provider) || empty($model) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Session title AI generation skipped - no default provider/model configured',
				array( 'context' => 'system' )
			);
			return null;
		}

		$context = 'User: ' . mb_substr($first_user_message, 0, 500);
		if ( $first_assistant_response ) {
			$context .= "\n\nAssistant: " . mb_substr($first_assistant_response, 0, 500);
		}

		$default_prompt = "Generate a concise title (3-6 words) for this conversation. Return ONLY the title text, nothing else.\n\n" . $context;

		/**
		 * Filter the prompt used for AI session title generation.
		 *
		 * Allows plugins to customize or completely replace the title generation prompt.
		 * Return a different prompt to change title style (e.g., code names like 'azure-phoenix').
		 *
		 * @since 0.21.1
		 *
		 * @param string $prompt  The default prompt including conversation context.
		 * @param array  $context {
		 *     Context data for prompt customization.
		 *
		 *     @type string $first_user_message       The first user message (truncated to 500 chars).
		 *     @type string $first_assistant_response The first assistant response (truncated to 500 chars).
		 *     @type string $conversation_context     Formatted "User: ... Assistant: ..." context string.
		 * }
		 */
		/** @phpstan-ignore-next-line WordPress filters accept context arguments beyond the filtered value. */
		$prompt = apply_filters(
			'datamachine_session_title_prompt',
			$default_prompt,
			array(
				'first_user_message'       => $first_user_message,
				'first_assistant_response' => $first_assistant_response,
				'conversation_context'     => $context,
			)
		);

		$messages = array(
			\DataMachine\Engine\AI\ConversationManager::buildConversationMessage( 'user', $prompt ),
		);

		$request = array(
			'model'      => $model,
			'messages'   => $messages,
			'max_tokens' => 50,
		);

		try {
			$response = RequestBuilder::build(
				$messages,
				$provider,
				$model,
				array(), // No tools for title generation
				array( 'system' ), // Agent modes
				array() // No payload needed
			);

			if ( $response instanceof \WP_Error ) {
				do_action(
					'datamachine_log',
					'error',
					'Session title AI generation failed',
					array(
						'error'   => $response->get_error_message(),
						'context' => 'system',
					)
				);
					return null;
			}

			$content = RequestBuilder::resultText( $response );
			if ( empty($content) ) {
				return null;
			}

			// Clean up the response - remove quotes, trim, limit length
			$title = trim($content);
			$title = trim($title, '"\'');
			$title = mb_substr($title, 0, 100); // Max title length

			return $title;
		} catch ( \Exception $e ) {
			do_action(
				'datamachine_log',
				'error',
				'Session title AI generation exception',
				array(
					'exception' => $e->getMessage(),
					'context'   => 'system',
				)
			);
			return null;
		}
	}

	private static function generateTruncatedTitle( string $first_message ): string {
		$title = trim($first_message);

		// Remove newlines and excessive whitespace
		$title = preg_replace('/\s+/', ' ', $title);

		// Truncate to max length
		if ( mb_strlen($title) > 97 ) { // Leave room for "..."
			$title = mb_substr($title, 0, 97) . '...';
		}

		return $title;
	}
}
