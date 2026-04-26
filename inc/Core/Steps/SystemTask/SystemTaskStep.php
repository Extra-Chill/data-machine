<?php
/**
 * System Task Step - Run registered system tasks inline in a pipeline.
 *
 * Bridges the System Agent task system into the pipeline engine. Allows
 * mechanical/deterministic tasks (internal linking, alt text generation)
 * to run as pipeline steps without a full agent turn.
 *
 * Configuration is at the flow step level via handler_config:
 *   task   - Task type identifier (e.g., 'internal_linking')
 *   params - Task-specific parameters merged with pipeline context
 *
 * Pipeline context (post_id from Publish step) is injected automatically
 * into the task params.
 *
 * @package DataMachine\Core\Steps\SystemTask
 * @since 0.34.0
 * @since 0.72.0 Calls executeTask() instead of execute().
 */

namespace DataMachine\Core\Steps\SystemTask;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Steps\Step;
use DataMachine\Core\Steps\StepTypeRegistrationTrait;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;
use DataMachine\Engine\Tasks\TaskRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SystemTaskStep extends Step {

	use StepTypeRegistrationTrait;

	/**
	 * Initialize System Task step.
	 */
	public function __construct() {
		parent::__construct( 'system_task' );

		self::registerStepType(
			slug: 'system_task',
			label: 'System Task',
			description: 'Run a registered system task (internal linking, alt text, etc.) inline in the pipeline',
			class_name: self::class,
			position: 70,
			usesHandler: false,
			hasPipelineConfig: false,
			consumeAllPackets: false,
			stepSettings: array(
				'config_type' => 'handler',
				'modal_type'  => 'configure-step',
				'button_text' => 'Configure',
				'label'       => 'System Task Configuration',
			),
			showSettingsDisplay: false
		);

		self::registerStepSettings();
	}

	/**
	 * Register System Task settings class for UI display.
	 */
	private static function registerStepSettings(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_filter(
			'datamachine_handler_settings',
			function ( $all_settings, $handler_slug = null ) {
				if ( null === $handler_slug || 'system_task' === $handler_slug ) {
					$all_settings['system_task'] = new SystemTaskSettings();
				}
				return $all_settings;
			},
			10,
			2
		);
	}

	/**
	 * Validate System Task step configuration.
	 *
	 * @return bool
	 */
	protected function validateStepConfiguration(): bool {
		$handler_config = $this->getHandlerConfig();
		$task_type      = $handler_config['task'] ?? '';

		if ( empty( $task_type ) ) {
			do_action(
				'datamachine_fail_job',
				$this->job_id,
				'system_task_missing_task_type',
				array(
					'flow_step_id'  => $this->flow_step_id,
					'error_message' => 'System Task step requires a task type in handler_config.',
				)
			);
			return false;
		}

		$handlers = TaskRegistry::getHandlers();

		if ( ! isset( $handlers[ $task_type ] ) ) {
			$available = implode( ', ', array_keys( $handlers ) );
			do_action(
				'datamachine_fail_job',
				$this->job_id,
				'system_task_unknown_type',
				array(
					'flow_step_id'  => $this->flow_step_id,
					'task_type'     => $task_type,
					'error_message' => "Unknown system task type '{$task_type}'. Available: {$available}",
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Execute System Task step logic.
	 *
	 * Creates a child DM job for tracking, resolves the task handler,
	 * and calls executeTask() synchronously.
	 *
	 * @return array Updated data packets.
	 */
	protected function executeStep(): array {
		$handler_config = $this->getHandlerConfig();
		$task_type      = $handler_config['task'];
		$task_params    = $handler_config['params'] ?? array();

		// Inject pipeline context into task params.
		$post_id = $this->engine->get( 'post_id' );
		if ( $post_id && ! isset( $task_params['post_id'] ) ) {
			$task_params['post_id'] = $post_id;
		}

		// Resolve the task handler class.
		$handlers      = TaskRegistry::getHandlers();
		$handler_class = $handlers[ $task_type ];

		// Create a child job for independent tracking.
		$jobs_db      = new Jobs();
		$job_context  = $this->engine->getJobContext();
		$child_job_id = $jobs_db->create_job(
			array(
				'pipeline_id'   => $job_context['pipeline_id'] ?? 'direct',
				'flow_id'       => $job_context['flow_id'] ?? 'direct',
				'source'        => 'pipeline_system_task',
				'label'         => ucfirst( str_replace( '_', ' ', $task_type ) ),
				'parent_job_id' => $this->job_id,
			)
		);

		if ( ! $child_job_id ) {
			$this->log( 'error', 'Failed to create child job for system task', array( 'task_type' => $task_type ) );

			$result_packet = new DataPacket(
				array(
					'title' => 'System Task Failed',
					'body'  => "Failed to create tracking job for task '{$task_type}'",
				),
				array(
					'source_type'  => 'system_task',
					'flow_step_id' => $this->flow_step_id,
					'task_type'    => $task_type,
					'success'      => false,
				),
				'system_task_result'
			);

			return $result_packet->addTo( $this->dataPackets );
		}

		// Carry parent's agent identity into the child engine_data so
		// task bodies (and any AI request they fire) can resolve the
		// correct agent's MEMORY.md / SOUL.md / per-agent model. This
		// mirrors the AIStep + PipelineBatchScheduler pattern (see
		// #1083, #1198, #1207). On agent-less flows the values are 0
		// and behaviour matches single-agent installs.
		$parent_job_snapshot = $this->engine->getJobContext();
		$parent_agent_id     = (int) ( $parent_job_snapshot['agent_id'] ?? 0 );
		$parent_user_id      = (int) ( $parent_job_snapshot['user_id'] ?? 0 );

		// Store task params in child job engine_data.
		$child_engine_data = array_merge( $task_params, array(
			'task_type'        => $task_type,
			'pipeline_job_id'  => $this->job_id,
			'pipeline_step_id' => $this->flow_step_id,
			'scheduled_at'     => current_time( 'mysql' ),
		) );

		// Propagate agent identity to the child. Both as flat keys (so
		// task bodies can read $params['agent_id'] / $params['user_id']
		// without rummaging through nested job snapshots) and under the
		// 'job' key (so any nested AIStep / engine consumer reads the
		// canonical engine_data['job'] shape).
		if ( $parent_agent_id > 0 ) {
			$child_engine_data['agent_id'] = $parent_agent_id;
		}
		if ( $parent_user_id > 0 ) {
			$child_engine_data['user_id'] = $parent_user_id;
		}
		$child_job_snapshot = array(
			'job_id'        => (int) $child_job_id,
			'user_id'       => $parent_user_id,
			'parent_job_id' => $this->job_id,
		);
		if ( $parent_agent_id > 0 ) {
			$child_job_snapshot['agent_id'] = $parent_agent_id;
		}
		$child_engine_data['job'] = $child_job_snapshot;

		// Inject additional pipeline context for tasks that need it (e.g. agent_ping).
		if ( 'agent_ping' === $task_type ) {
			$job_context                       = $this->engine->getJobContext();
			$child_engine_data['flow_id']      = $job_context['flow_id'] ?? null;
			$child_engine_data['flow_step_id'] = $this->flow_step_id;
			$child_engine_data['data_packets'] = $this->dataPackets;
			$child_engine_data['engine_data']  = $this->engine->all();
			$child_engine_data['job_id']       = $this->job_id;
			$child_engine_data['pipeline_id']  = $job_context['pipeline_id'] ?? null;

			$fsc                                = $this->flow_step_config ?? array();
			$child_engine_data['queue_enabled'] = ! empty( $fsc['queue_enabled'] );
		}
		$jobs_db->store_engine_data( (int) $child_job_id, $child_engine_data );
		$jobs_db->start_job( (int) $child_job_id, JobStatus::PROCESSING );

		$this->log(
			'info',
			"Executing system task '{$task_type}' as pipeline step (child job #{$child_job_id})",
			array(
				'task_type'    => $task_type,
				'child_job_id' => $child_job_id,
				'post_id'      => $post_id ?? null,
			)
		);

		// Establish agent execution context before firing the task body.
		//
		// SystemTaskStep runs inside the Action Scheduler queue under
		// whatever user the parent step set up (typically nothing in
		// pre-#1083 paths). System tasks frequently call abilities that
		// mutate WordPress content (wp_update_post, update_post_meta,
		// wp_save_post_revision) — those need a real user for proper
		// post_author resolution and capability checks. This mirrors
		// the AIStep envelope from #1083 so abilities fired inside
		// executeTask() see the right identity.
		$owner_id = 0;
		if ( $parent_agent_id > 0 ) {
			$agents_repo  = new Agents();
			$agent_record = $agents_repo->get_agent( $parent_agent_id );
			if ( $agent_record ) {
				$owner_id = (int) ( $agent_record['owner_id'] ?? 0 );
			}
		}
		if ( $owner_id <= 0 && $parent_user_id > 0 ) {
			// Legacy / agent-less flows: fall back to the flow's user_id.
			$owner_id = $parent_user_id;
		}

		$previous_user_id = get_current_user_id();
		$context_set      = false;

		// Execute the task synchronously via executeTask().
		$success   = true;
		$error_msg = '';

		try {
			if ( $owner_id > 0 ) {
				wp_set_current_user( $owner_id );
				if ( $parent_agent_id > 0 ) {
					PermissionHelper::set_agent_context( $parent_agent_id, $owner_id );
					$context_set = true;
				}
			}

			$handler = new $handler_class();
			$handler->executeTask( (int) $child_job_id, $child_engine_data );
		} catch ( \Throwable $e ) {
			$success   = false;
			$error_msg = $e->getMessage();

			$this->log(
				'error',
				"System task '{$task_type}' threw exception: {$error_msg}",
				array(
					'task_type'    => $task_type,
					'child_job_id' => $child_job_id,
					'exception'    => $error_msg,
				)
			);

			$child_job = $jobs_db->get_job( $child_job_id );
			$status    = $child_job['status'] ?? '';
			if ( 'PROCESSING' === $status ) {
				$jobs_db->complete_job( $child_job_id, JobStatus::failed( 'Exception: ' . $error_msg )->toString() );
			}
		} finally {
			if ( $context_set ) {
				PermissionHelper::clear_agent_context();
			}
			if ( $owner_id > 0 && $previous_user_id !== $owner_id ) {
				wp_set_current_user( $previous_user_id );
			}
		}

		// Read child job result to determine success.
		$child_job    = $jobs_db->get_job( $child_job_id );
		$child_status = $child_job['status'] ?? '';
		$child_data   = $child_job['engine_data'] ?? array();

		if ( $success && str_starts_with( $child_status, 'FAILED' ) ) {
			$success   = false;
			$error_msg = $child_data['error'] ?? 'Task reported failure';
		}

		$skipped = ! empty( $child_data['skipped'] );
		if ( $skipped ) {
			$success = true;
		}

		$body = $success
			? ( $skipped
				? "System task '{$task_type}' skipped: " . ( $child_data['reason'] ?? 'already processed' )
				: "System task '{$task_type}' completed successfully" )
			: "System task '{$task_type}' failed: {$error_msg}";

		$result_packet = new DataPacket(
			array(
				'title' => $success ? 'System Task Completed' : 'System Task Failed',
				'body'  => $body,
			),
			array(
				'source_type'  => 'system_task',
				'flow_step_id' => $this->flow_step_id,
				'task_type'    => $task_type,
				'child_job_id' => $child_job_id,
				'child_status' => $child_status,
				'skipped'      => $skipped,
				'success'      => $success,
			),
			'system_task_result'
		);

		return $result_packet->addTo( $this->dataPackets );
	}
}
