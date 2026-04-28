<?php
/**
 * Abstract base class for all System Agent tasks.
 *
 * Tasks declare their execution shape via getWorkflow() — returning a
 * step-list JSON that the engine executes through datamachine/execute-workflow.
 * This is the same engine contract used by persistent flows, chat-built
 * workflows, and system tasks. The only difference between them is where
 * the JSON lives: database row, chat context, or hardcoded PHP.
 *
 * ## Dual Contract
 *
 * - getWorkflow(array $params): array — public scheduling contract.
 *   Returns { steps: [...] } for datamachine/execute-workflow.
 *   TaskScheduler::schedule() calls this.
 *
 * - executeTask(int $jobId, array $params): void — imperative execution.
 *   Called by SystemTaskStep when running inline in a pipeline, or by
 *   the engine when processing a system_task step in a workflow.
 *   Contains the actual business logic (API calls, file ops, AI requests).
 *
 * Most tasks return a single system_task step from getWorkflow() that
 * points to their own task type. The engine routes this through
 * SystemTaskStep which calls executeTask(). Over time, tasks can be
 * decomposed into richer multi-step workflows.
 *
 * ## Undo System
 *
 * Tasks that modify WordPress content can opt into undo support by:
 * 1. Returning true from supportsUndo()
 * 2. Recording effects in engine_data during executeTask()
 *
 * The undo() method reads effects from child step jobs via
 * Jobs::get_children($parentJobId) and reverses them.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.22.4
 * @since 0.72.0 Replaced execute() with getWorkflow() + executeTask() contract.
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\PluginSettings;

abstract class SystemTask {

	/**
	 * Declare the task as a workflow step list.
	 *
	 * Returns an array with a "steps" key, where each step is an object
	 * matching the datamachine/execute-workflow JSON schema.
	 *
	 * Default implementation returns a single system_task step that
	 * references this task's own type — the engine routes through
	 * SystemTaskStep which calls executeTask(). Override to return
	 * richer multi-step workflows.
	 *
	 * @param array $params Task parameters from the scheduler.
	 * @return array Workflow definition with "steps" key.
	 * @since 0.72.0
	 */
	public function getWorkflow( array $params ): array {
		return array(
			'steps' => array(
				array(
					'type'           => 'system_task',
					'handler_config' => array(
						'task'   => $this->getTaskType(),
						'params' => $params,
					),
				),
			),
		);
	}

	/**
	 * Execute the task's imperative business logic.
	 *
	 * Called by SystemTaskStep when running as a pipeline/workflow step.
	 * Contains the actual work: API calls, file operations, AI requests,
	 * database writes, etc.
	 *
	 * Tasks MUST call completeJob() or failJob() before returning to
	 * signal the outcome to the engine.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters from engine_data.
	 * @since 0.72.0 Renamed from execute().
	 */
	abstract public function executeTask( int $jobId, array $params ): void;

	/**
	 * Get the task type identifier.
	 *
	 * @return string Task type identifier.
	 */
	abstract public function getTaskType(): string;

	/**
	 * Get task metadata for UI display.
	 *
	 * Override in concrete tasks to provide label, description, and
	 * optional setting key for the System Tasks admin tab.
	 *
	 * @return array{label: string, description: string, setting_key: ?string, default_enabled: bool}
	 * @since 0.32.0
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => '',
			'description'     => '',
			'setting_key'     => null,
			'default_enabled' => true,
		);
	}

	// ─── SystemTaskStep passthrough contract (#1297) ─────────────────
	//
	// SystemTaskStep::execute_pipeline_step() builds the child engine_data
	// that's passed to executeTask(). The universal merge already includes
	// task params, agent identity, and post_id. Tasks that need more
	// context (pipeline-execution snapshot, flow_step_config keys) declare
	// it here instead of forcing the step to bake task-specific knowledge
	// into a per-task `if` block.
	//
	// Default implementations return "no extra passthrough", so existing
	// tasks (InternalLinkingTask, AltTextTask, MetaDescriptionTask, etc.)
	// keep working unchanged. Only AgentCallTask needs the pipeline
	// context bundle today; future tasks can opt in.

	/**
	 * Whether this task needs the full pipeline-execution context bundled
	 * into engine_data when run as a pipeline step.
	 *
	 * When true, SystemTaskStep injects:
	 *   - flow_id            (from job context)
	 *   - pipeline_id        (from job context)
	 *   - flow_step_id       (the step that scheduled the task)
	 *   - data_packets       (the step's incoming packets)
	 *   - engine_data        (the engine's full key/value snapshot)
	 *   - job_id             (the parent job)
	 *
	 * Used by AgentCallTask to forward the in-flight pipeline state to
	 * the agent_call ability so the webhook receives the same shape it
	 * would see in a non-system-task path.
	 *
	 * @return bool
	 * @since 0.84.0
	 */
	public function needsPipelineContext(): bool {
		return false;
	}

	/**
	 * Declare flow_step_config keys that should be copied into the child
	 * engine_data when this task is run as a pipeline step.
	 *
	 * Each key is read from `flow_step_config[$key]` (when present) and
	 * placed at `engine_data[$key]` so executeTask() can read it from
	 * `$params[$key]` without rummaging through nested config blobs.
	 *
	 * Used by AgentCallTask for `queue_mode` (post-#1291) so the queue
	 * access pattern is available to the task at execution time without
	 * SystemTaskStep needing to know which tasks care about which
	 * flow_step_config fields.
	 *
	 * @return array<int, string> Flat list of flow_step_config key names.
	 * @since 0.84.0
	 */
	public function getFlowStepConfigPassthrough(): array {
		return array();
	}

	// ─── Job lifecycle helpers ────────────────────────────────────────
	// Used by executeTask() implementations to signal completion/failure.

	/**
	 * Mark a job as completed with result data.
	 *
	 * @param int   $jobId Job ID.
	 * @param array $data  Result data to store in engine_data.
	 */
	protected function completeJob( int $jobId, array $data ): void {
		$jobs_db     = new Jobs();
		$engine_data = $jobs_db->retrieve_engine_data( $jobId );
		$engine_data = array_merge( $engine_data, $data );
		$jobs_db->store_engine_data( $jobId, $engine_data );
		$jobs_db->complete_job( $jobId, 'completed' );
	}

	/**
	 * Mark a job as failed with an error message.
	 *
	 * @param int    $jobId   Job ID.
	 * @param string $message Error message.
	 */
	protected function failJob( int $jobId, string $message ): void {
		$jobs_db     = new Jobs();
		$engine_data = $jobs_db->retrieve_engine_data( $jobId );
		$engine_data['error'] = $message;
		$jobs_db->store_engine_data( $jobId, $engine_data );
		$jobs_db->complete_job( $jobId, 'failed: ' . $message );

		do_action(
			'datamachine_log',
			'error',
			"Task failed (job #{$jobId}): {$message}",
			array(
				'job_id'    => $jobId,
				'task_type' => $this->getTaskType(),
				'error'     => $message,
				'context'   => 'system',
			)
		);
	}

	/**
	 * Reschedule a job for later retry via Action Scheduler.
	 *
	 * Used by tasks with polling patterns (e.g. ImageGenerationTask).
	 *
	 * @param int $jobId        Job ID.
	 * @param int $delaySeconds Delay in seconds before next attempt.
	 */
	protected function reschedule( int $jobId, int $delaySeconds ): void {
		$jobs_db     = new Jobs();
		$engine_data = $jobs_db->retrieve_engine_data( $jobId );
		$attempts    = ( $engine_data['attempts'] ?? 0 ) + 1;
		$max         = $engine_data['max_attempts'] ?? 24;

		if ( $attempts >= $max ) {
			$this->failJob( $jobId, "Max attempts ({$max}) reached" );
			return;
		}

		$engine_data['attempts'] = $attempts;
		$jobs_db->store_engine_data( $jobId, $engine_data );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + $delaySeconds,
				'datamachine_task_retry',
				array( $jobId ),
				'data-machine'
			);
		}
	}

	// ─── Prompt system ────────────────────────────────────────────────

	/**
	 * Option key for storing system task prompt overrides.
	 *
	 * @since 0.41.0
	 */
	private const PROMPT_OVERRIDES_OPTION = 'datamachine_task_prompts';

	/**
	 * Cached prompt overrides (loaded once per request).
	 *
	 * @var array|null
	 * @since 0.41.0
	 */
	private static ?array $prompt_overrides_cache = null;

	/**
	 * Get prompt definitions for this task.
	 *
	 * @return array<string, array{label: string, description: string, default: string, variables: array<string, string>}>
	 * @since 0.41.0
	 */
	public function getPromptDefinitions(): array {
		return array();
	}

	/**
	 * Resolve a prompt by key — returns override if set, default otherwise.
	 *
	 * @param string $prompt_key The prompt key (from getPromptDefinitions).
	 * @return string The resolved prompt template.
	 * @since 0.41.0
	 */
	protected function resolvePrompt( string $prompt_key ): string {
		$definitions = $this->getPromptDefinitions();

		if ( ! isset( $definitions[ $prompt_key ] ) ) {
			return '';
		}

		$overrides = self::getAllPromptOverrides();
		$task_type = $this->getTaskType();

		if ( isset( $overrides[ $task_type ][ $prompt_key ] ) && '' !== $overrides[ $task_type ][ $prompt_key ] ) {
			return $overrides[ $task_type ][ $prompt_key ];
		}

		return $definitions[ $prompt_key ]['default'];
	}

	/**
	 * Interpolate context variables into a prompt template.
	 *
	 * @param string $template  Prompt template with {{variable}} placeholders.
	 * @param array  $variables Key-value pairs of variable_name => value.
	 * @return string Interpolated prompt text.
	 * @since 0.41.0
	 */
	protected function interpolatePrompt( string $template, array $variables ): string {
		foreach ( $variables as $key => $value ) {
			$template = str_replace( '{{' . $key . '}}', (string) $value, $template );
		}

		return $template;
	}

	/**
	 * Resolve and interpolate a prompt in one call.
	 *
	 * @param string $prompt_key The prompt key.
	 * @param array  $variables  Context variables for interpolation.
	 * @return string The final prompt text.
	 * @since 0.41.0
	 */
	protected function buildPromptFromTemplate( string $prompt_key, array $variables = array() ): string {
		$template  = $this->resolvePrompt( $prompt_key );
		$task_type = $this->getTaskType();

		/**
		 * Filter prompt template variables before interpolation.
		 *
		 * @since 0.44.0
		 * @param array  $variables  Key-value pairs of variable_name => value.
		 * @param string $task_type  The system task type.
		 * @param string $prompt_key The prompt key within the task.
		 */
		$variables = apply_filters( 'datamachine_task_prompt_variables', $variables, $task_type, $prompt_key );

		return $this->interpolatePrompt( $template, $variables );
	}

	/**
	 * Get all prompt overrides from the database.
	 *
	 * @return array
	 * @since 0.41.0
	 */
	public static function getAllPromptOverrides(): array {
		if ( null === self::$prompt_overrides_cache ) {
			self::$prompt_overrides_cache = get_option( self::PROMPT_OVERRIDES_OPTION, array() );
		}

		return self::$prompt_overrides_cache;
	}

	/**
	 * Set a prompt override.
	 *
	 * @param string $task_type  Task type identifier.
	 * @param string $prompt_key Prompt key within the task.
	 * @param string $prompt     The override prompt text. Empty string removes.
	 * @return bool True on success.
	 * @since 0.41.0
	 */
	public static function setPromptOverride( string $task_type, string $prompt_key, string $prompt ): bool {
		$overrides = self::getAllPromptOverrides();

		if ( '' === $prompt ) {
			unset( $overrides[ $task_type ][ $prompt_key ] );
			if ( isset( $overrides[ $task_type ] ) && empty( $overrides[ $task_type ] ) ) {
				unset( $overrides[ $task_type ] );
			}
		} else {
			if ( ! isset( $overrides[ $task_type ] ) ) {
				$overrides[ $task_type ] = array();
			}
			$overrides[ $task_type ][ $prompt_key ] = $prompt;
		}

		self::$prompt_overrides_cache = $overrides;
		return update_option( self::PROMPT_OVERRIDES_OPTION, $overrides );
	}

	/**
	 * Reset all prompt overrides for a task.
	 *
	 * @param string $task_type Task type identifier.
	 * @return bool True on success.
	 * @since 0.41.0
	 */
	public static function resetPromptOverrides( string $task_type ): bool {
		$overrides = self::getAllPromptOverrides();
		unset( $overrides[ $task_type ] );
		self::$prompt_overrides_cache = $overrides;
		return update_option( self::PROMPT_OVERRIDES_OPTION, $overrides );
	}

	/**
	 * Clear the prompt overrides cache.
	 *
	 * @since 0.41.0
	 */
	public static function clearPromptCache(): void {
		self::$prompt_overrides_cache = null;
	}

	/**
	 * Resolve the effective system-context model for this task.
	 *
	 * @param array $params Task params / engine_data.
	 * @return array{ provider: string, model: string }
	 */
	protected function resolveSystemModel( array $params ): array {
		$agent_id = (int) ( $params['agent_id'] ?? ( $params['context']['agent_id'] ?? 0 ) );
		return PluginSettings::resolveModelForAgentMode( $agent_id, 'system' );
	}

	// ─── Undo system ──────────────────────────────────────────────────

	/**
	 * Whether this task type supports undo.
	 *
	 * @return bool
	 * @since 0.33.0
	 */
	public function supportsUndo(): bool {
		return false;
	}

	/**
	 * Undo the effects of a completed job.
	 *
	 * Reads the standardized effects array from child step jobs via
	 * Jobs::get_children() and reverses each effect in reverse order.
	 *
	 * @param int   $jobId      The parent job ID to undo.
	 * @param array $engineData The job's full engine_data.
	 * @return array{success: bool, reverted: array, skipped: array, failed: array}
	 * @since 0.33.0
	 * @since 0.72.0 Reads effects from child step jobs instead of self.
	 */
	public function undo( int $jobId, array $engineData ): array {
		// First try effects from the job itself (backward compat for
		// jobs that completed before the migration).
		$effects = $engineData['effects'] ?? array();

		// If no self-effects, gather from child step jobs.
		if ( empty( $effects ) ) {
			$jobs_db  = new Jobs();
			$children = $jobs_db->get_children( $jobId );

			foreach ( $children as $child ) {
				$child_data    = $child['engine_data'] ?? array();
				$child_effects = $child_data['effects'] ?? array();
				$effects       = array_merge( $effects, $child_effects );
			}
		}

		if ( empty( $effects ) ) {
			return array(
				'success'  => false,
				'error'    => 'No effects recorded for this job',
				'reverted' => array(),
				'skipped'  => array(),
				'failed'   => array(),
			);
		}

		return $this->undoEffects( $jobId, $effects );
	}

	/**
	 * Generic undo handler — reverses standard effect types in reverse order.
	 *
	 * @param int   $jobId   Job ID (for logging).
	 * @param array $effects Effects array from engine_data.
	 * @return array{success: bool, reverted: array, skipped: array, failed: array}
	 * @since 0.33.0
	 */
	protected function undoEffects( int $jobId, array $effects ): array {
		$reverted = array();
		$skipped  = array();
		$failed   = array();

		foreach ( array_reverse( $effects ) as $effect ) {
			$result = $this->undoEffect( $effect );

			switch ( $result['status'] ) {
				case 'reverted':
					$reverted[] = $result;
					break;
				case 'skipped':
					$skipped[] = $result;
					break;
				default:
					$failed[] = $result;
			}
		}

		do_action(
			'datamachine_log',
			empty( $failed ) ? 'info' : 'warning',
			sprintf(
				'Job %d undo: %d reverted, %d skipped, %d failed',
				$jobId,
				count( $reverted ),
				count( $skipped ),
				count( $failed )
			),
			array(
				'job_id'    => $jobId,
				'task_type' => $this->getTaskType(),
				'reverted'  => count( $reverted ),
				'skipped'   => count( $skipped ),
				'failed'    => count( $failed ),
			)
		);

		return array(
			'success'  => empty( $failed ),
			'reverted' => $reverted,
			'skipped'  => $skipped,
			'failed'   => $failed,
		);
	}

	/**
	 * Undo a single effect by dispatching to the appropriate handler.
	 *
	 * @param array $effect Single effect entry.
	 * @return array{status: string, type: string, reason?: string}
	 * @since 0.33.0
	 */
	protected function undoEffect( array $effect ): array {
		$type = $effect['type'] ?? '';

		switch ( $type ) {
			case 'post_content_modified':
				return $this->undoContentModification( $effect );
			case 'post_meta_set':
				return $this->undoMetaSet( $effect );
			case 'post_field_set':
				return $this->undoPostFieldSet( $effect );
			case 'attachment_created':
				return $this->undoAttachmentCreated( $effect );
			case 'featured_image_set':
				return $this->undoFeaturedImageSet( $effect );
			default:
				return array(
					'status' => 'skipped',
					'type'   => $type,
					'reason' => "Unknown effect type: {$type}",
				);
		}
	}

	/**
	 * Undo a post content modification by restoring a WP revision.
	 *
	 * @param array $effect Effect data.
	 * @return array
	 */
	protected function undoContentModification( array $effect ): array {
		$post_id     = $effect['target']['post_id'] ?? 0;
		$revision_id = $effect['revision_id'] ?? 0;

		if ( $post_id <= 0 ) {
			return array( 'status' => 'failed', 'type' => 'post_content_modified', 'reason' => 'Missing post_id' );
		}
		if ( $revision_id <= 0 ) {
			return array( 'status' => 'failed', 'type' => 'post_content_modified', 'reason' => 'No revision_id' );
		}

		$revision = get_post( $revision_id );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return array( 'status' => 'failed', 'type' => 'post_content_modified', 'reason' => "Revision #{$revision_id} not found" );
		}

		$restored = wp_restore_post_revision( $revision_id );
		if ( ! $restored ) {
			return array( 'status' => 'failed', 'type' => 'post_content_modified', 'reason' => "Failed to restore revision #{$revision_id}" );
		}

		return array( 'status' => 'reverted', 'type' => 'post_content_modified', 'post_id' => $post_id, 'revision_id' => $revision_id );
	}

	/**
	 * Undo a post meta set operation.
	 *
	 * @param array $effect Effect data.
	 * @return array
	 */
	protected function undoMetaSet( array $effect ): array {
		$post_id  = $effect['target']['post_id'] ?? 0;
		$meta_key = $effect['target']['meta_key'] ?? '';

		if ( $post_id <= 0 || empty( $meta_key ) ) {
			return array( 'status' => 'failed', 'type' => 'post_meta_set', 'reason' => 'Missing post_id or meta_key' );
		}

		if ( array_key_exists( 'previous_value', $effect ) && null !== $effect['previous_value'] ) {
			update_post_meta( $post_id, $meta_key, $effect['previous_value'] );
		} else {
			delete_post_meta( $post_id, $meta_key );
		}

		return array( 'status' => 'reverted', 'type' => 'post_meta_set', 'post_id' => $post_id, 'meta_key' => $meta_key );
	}

	/**
	 * Undo a post field set operation (e.g. post_excerpt).
	 *
	 * @param array $effect Effect data.
	 * @return array
	 */
	protected function undoPostFieldSet( array $effect ): array {
		$post_id = $effect['target']['post_id'] ?? 0;
		$field   = $effect['target']['field'] ?? '';

		if ( $post_id <= 0 || empty( $field ) ) {
			return array( 'status' => 'failed', 'type' => 'post_field_set', 'reason' => 'Missing post_id or field' );
		}

		$update = array( 'ID' => $post_id );

		if ( array_key_exists( 'previous_value', $effect ) && null !== $effect['previous_value'] ) {
			$update[ $field ] = $effect['previous_value'];
		} else {
			$update[ $field ] = '';
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return array( 'status' => 'failed', 'type' => 'post_field_set', 'reason' => $result->get_error_message() );
		}

		return array( 'status' => 'reverted', 'type' => 'post_field_set', 'post_id' => $post_id, 'field' => $field );
	}

	/**
	 * Undo an attachment creation by deleting the attachment.
	 *
	 * @param array $effect Effect data.
	 * @return array
	 */
	protected function undoAttachmentCreated( array $effect ): array {
		$attachment_id = $effect['target']['attachment_id'] ?? 0;

		if ( $attachment_id <= 0 ) {
			return array( 'status' => 'failed', 'type' => 'attachment_created', 'reason' => 'Missing attachment_id' );
		}

		$deleted = wp_delete_attachment( $attachment_id, true );
		if ( ! $deleted ) {
			return array( 'status' => 'failed', 'type' => 'attachment_created', 'reason' => "Failed to delete attachment #{$attachment_id}" );
		}

		return array( 'status' => 'reverted', 'type' => 'attachment_created', 'attachment_id' => $attachment_id );
	}

	/**
	 * Undo a featured image set operation.
	 *
	 * @param array $effect Effect data.
	 * @return array
	 */
	protected function undoFeaturedImageSet( array $effect ): array {
		$post_id = $effect['target']['post_id'] ?? 0;

		if ( $post_id <= 0 ) {
			return array( 'status' => 'failed', 'type' => 'featured_image_set', 'reason' => 'Missing post_id' );
		}

		$previous = $effect['previous_value'] ?? 0;
		if ( $previous > 0 ) {
			set_post_thumbnail( $post_id, $previous );
		} else {
			delete_post_thumbnail( $post_id );
		}

		return array( 'status' => 'reverted', 'type' => 'featured_image_set', 'post_id' => $post_id );
	}
}
