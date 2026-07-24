<?php
/**
 * System Task Step - Run registered system tasks inline in a pipeline.
 *
 * Bridges the System Agent task system into the pipeline engine. Allows
 * mechanical/deterministic tasks (internal linking, alt text generation)
 * to run as pipeline steps without a full agent turn.
 *
 * Configuration is at the flow step level via flow_step_settings:
 *   task_type - Task type identifier (e.g., 'internal_linking')
 *   params    - Task-specific parameters merged with pipeline context
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
use DataMachine\Core\Agents\AgentIdentity;
use DataMachine\Core\Agents\AgentIdentityResolver;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\Step;
use DataMachine\Core\Steps\StepTypeRegistrationTrait;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
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
		$step_settings = $this->getHandlerConfig();
		$task_type     = self::getConfiguredTaskType( $step_settings );

		if ( empty( $task_type ) ) {
			$legacy_task_type = $step_settings['task'] ?? null;
			$failure_code     = 'system_task_missing_task_type';
			$error_message    = 'System Task step requires a task type in flow_step_settings.task_type.';

			if ( is_string( $legacy_task_type ) && '' !== $legacy_task_type ) {
				$failure_code  = 'system_task_legacy_task_field';
				$error_message = 'System Task step uses unsupported legacy field flow_step_settings.task; use flow_step_settings.task_type.';
			}

			do_action(
				'datamachine_fail_job',
				$this->job_id,
				$failure_code,
				array(
					'flow_step_id'  => $this->flow_step_id,
					'error_message' => $error_message,
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
		$task_type      = self::getConfiguredTaskType( $handler_config );
		$task_params    = $handler_config['params'] ?? array();

		// Inject pipeline context into task params.
		$post_id = $this->engine->get( 'post_id' );
		if ( $post_id && ! isset( $task_params['post_id'] ) ) {
			$task_params['post_id'] = $post_id;
		}

		// Resolve the task handler class.
		$handlers             = TaskRegistry::getHandlers();
		$handler_class        = $handlers[ $task_type ];
		$task_for_passthrough = new $handler_class();
		if ( ! $task_for_passthrough instanceof SystemTask ) {
			do_action(
				'datamachine_fail_job',
				$this->job_id,
				'system_task_invalid_handler',
				array(
					'flow_step_id'  => $this->flow_step_id,
					'task_type'     => $task_type,
					'error_message' => 'System task handler must extend SystemTask.',
				)
			);
			return array();
		}

		// Carry parent's agent identity into the child engine_data so
		// task bodies (and any AI request they fire) can resolve the
		// correct agent's MEMORY.md / SOUL.md / per-agent model. This
		// mirrors the AIStep + PipelineBatchScheduler pattern (see
		// #1083, #1198, #1207). Tasks default to requiring an agent ownership
		// envelope; pure system maintenance tasks must opt out explicitly via
		// SystemTask::requiresAgentContext().
		$parent_job_snapshot = $this->engine->getJobContext();
		$identity            = $this->resolveAgentIdentityForExecution( $parent_job_snapshot );
		if ( null === $identity && $task_for_passthrough->requiresAgentContext() ) {
			$this->failMissingAgentContext( $parent_job_snapshot, $task_type );
			return array();
		}

		$parent_agent_id   = null !== $identity ? $identity->agent_id : 0;
		$parent_agent_slug = null !== $identity ? $identity->agent_slug : '';
		$parent_user_id    = null !== $identity ? $identity->owner_id : 0;

		// Create a child job for independent tracking.
		$jobs_db      = new Jobs();
		$job_context  = $parent_job_snapshot;
		$child_job_id = (int) $jobs_db->create_job(
			array(
				'pipeline_id'   => $job_context['pipeline_id'] ?? 'direct',
				'flow_id'       => $job_context['flow_id'] ?? 'direct',
				'source'        => 'pipeline_system_task',
				'label'         => ucfirst( str_replace( '_', ' ', $task_type ) ),
				'parent_job_id' => $this->job_id,
			)
		);

		if ( $child_job_id <= 0 ) {
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
		if ( '' !== $parent_agent_slug ) {
			$child_engine_data['agent_slug'] = $parent_agent_slug;
		}
		if ( $parent_user_id > 0 ) {
			$child_engine_data['user_id'] = $parent_user_id;
		}
		$child_job_snapshot = array(
			'job_id'        => $child_job_id,
			'user_id'       => $parent_user_id,
			'parent_job_id' => $this->job_id,
		);
		if ( $parent_agent_id > 0 ) {
			$child_job_snapshot['agent_id'] = $parent_agent_id;
		}
		if ( '' !== $parent_agent_slug ) {
			$child_job_snapshot['agent_slug'] = $parent_agent_slug;
		}
		$child_engine_data['job'] = $child_job_snapshot;

		// Inject the standard pipeline-execution context bundle when the
		// task declares it needs it. Replaces the per-task `if` block
		// that hardcoded agent-specific knowledge into this step
		// (#1297). Tasks opt in via SystemTask::needsPipelineContext().
		if ( $task_for_passthrough->needsPipelineContext() ) {
			$pipeline_context                  = $this->engine->getJobContext();
			$child_engine_data['flow_id']      = $pipeline_context['flow_id'] ?? null;
			$child_engine_data['flow_step_id'] = $this->flow_step_id;
			$child_engine_data['data_packets'] = $this->dataPackets;
			$child_engine_data['engine_data']  = $this->engine->all();
			$child_engine_data['job_id']       = $this->job_id;
			$child_engine_data['pipeline_id']  = $pipeline_context['pipeline_id'] ?? null;
		}

		// Copy declared flow_step_config keys into engine_data so the
		// task can read them from $params at execution time. Tasks
		// opt in by listing key names from
		// SystemTask::getFlowStepConfigPassthrough().
		$fsc                   = $this->flow_step_config ?? array();
		$flow_step_config_keys = $task_for_passthrough->getFlowStepConfigPassthrough();
		foreach ( $flow_step_config_keys as $key ) {
			if ( '' === $key ) {
				continue;
			}
			if ( array_key_exists( $key, $fsc ) ) {
				$child_engine_data[ $key ] = $fsc[ $key ];
			}
		}
		$jobs_db->store_engine_data( $child_job_id, $child_engine_data );
		$jobs_db->start_job( $child_job_id, JobStatus::PROCESSING );

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
		// System tasks frequently call abilities that mutate WordPress content;
		// those abilities must see the agent owner plus per-agent capability
		// context, never a broad user-only fallback.
		$owner_id = $parent_user_id;

		$previous_user_id = get_current_user_id();
		$context_set      = false;

		// Execute the task synchronously via executeTask().
		$success   = true;
		$error_msg = '';

		try {
			if ( $owner_id > 0 && $parent_agent_id > 0 ) {
				wp_set_current_user( $owner_id );
				PermissionHelper::set_agent_context( $parent_agent_id, $owner_id );
				$context_set = true;
			}

			// Reuse the instance built earlier for passthrough resolution.
			$task_for_passthrough->executeTask( $child_job_id, $child_engine_data );
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
			if ( JobStatus::PROCESSING === $status ) {
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

		if ( $success && JobStatus::isStatusFailure( $child_status ) ) {
			$success   = false;
			$error_msg = $child_data['error'] ?? 'Task reported failure';
		}

		if ( ! empty( $child_data['job_status'] ) && is_string( $child_data['job_status'] ) ) {
			$this->engine->set( 'job_status', $child_data['job_status'] );
		}

		$skipped = ! empty( $child_data['skipped'] );
		if ( $skipped ) {
			$success = true;
		}

		$result = ! empty( $child_data['replace_data_packets'] ) ? array() : $this->dataPackets;
		foreach ( $this->normalizeOutputDataPackets( $child_data['output_data_packets'] ?? array(), $task_type, $child_job_id ) as $packet ) {
			$result = $packet->addTo( $result );
		}

		if ( ! empty( $child_data['suppress_result_packet'] ) ) {
			return $result;
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

		return $result_packet->addTo( $result );
	}

	/**
	 * Normalize task-emitted packet arrays into DataPacket objects.
	 *
	 * System tasks can set output_data_packets in child job engine_data to
	 * hand precise packets to downstream workflow steps. This keeps the
	 * pipeline handoff contract on DataPackets instead of task-specific fields.
	 *
	 * @param mixed  $packets      Raw output packet declarations.
	 * @param string $task_type    Task type for default metadata.
	 * @param int    $child_job_id Child job ID for default metadata.
	 * @return array<int, DataPacket>
	 */
	private function normalizeOutputDataPackets( $packets, string $task_type, int $child_job_id ): array {
		if ( ! is_array( $packets ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $packets as $packet ) {
			if ( ! is_array( $packet ) ) {
				continue;
			}

			$data     = is_array( $packet['data'] ?? null ) ? $packet['data'] : $packet;
			$type     = isset( $packet['type'] ) && is_string( $packet['type'] ) && '' !== $packet['type']
				? $packet['type']
				: 'system_task_output';
			$metadata = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();
			$metadata = array_merge(
				array(
					'source_type'  => 'system_task',
					'task_type'    => $task_type,
					'child_job_id' => $child_job_id,
					'success'      => true,
				),
				$metadata
			);

			$normalized[] = new DataPacket( $data, $metadata, $type );
		}

		return $normalized;
	}

	/**
	 * Resolve the configured task type for this step.
	 *
	 * `task_type` is the canonical field used by public task APIs and workflow
	 * step configuration.
	 *
	 * @param array $settings Flow step settings.
	 * @return string Configured task type, or empty string.
	 */
	private static function getConfiguredTaskType( array $settings ): string {
		$task_type = $settings['task_type'] ?? '';
		return is_string( $task_type ) ? $task_type : '';
	}

	/**
	 * Resolve the agent identity required for queued system-task execution.
	 *
	 * @param array $parent_job_snapshot Parent job snapshot from engine data.
	 * @return AgentIdentity|null Resolved identity, or null when the job is agent-less/invalid.
	 */
	private function resolveAgentIdentityForExecution( array $parent_job_snapshot ): ?AgentIdentity {
		if ( empty( $parent_job_snapshot['agent_slug'] ) && empty( $parent_job_snapshot['agent_id'] ) ) {
			return null;
		}

		try {
			return ( new AgentIdentityResolver() )->resolve_agent_identity( $parent_job_snapshot );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * Fail queued system-task execution when no real agent owner can be resolved.
	 *
	 * @param array  $parent_job_snapshot Parent job snapshot from engine data.
	 * @param string $task_type           System task type currently executing.
	 */
	private function failMissingAgentContext( array $parent_job_snapshot, string $task_type ): void {
		do_action(
			'datamachine_fail_job',
			$this->job_id,
			'system_task_agent_context_required',
			array(
				'parent_job_id' => $this->job_id,
				'flow_id'       => (int) ( $parent_job_snapshot['flow_id'] ?? 0 ),
				'pipeline_id'   => (int) ( $parent_job_snapshot['pipeline_id'] ?? 0 ),
				'flow_step_id'  => $this->flow_step_id,
				'task_type'     => $task_type,
				'error_message' => 'Queued system-task execution requires a valid agent_id or agent_slug with an owner user. Reassign unowned flows/pipelines before running this step.',
				'solution'      => 'Inspect with wp datamachine pipelines orphans and wp datamachine flows orphans, then reassign with --where-null.',
			)
		);
	}
}
