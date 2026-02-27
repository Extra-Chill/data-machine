<?php
/**
 * Abstract base class for all System Agent tasks.
 *
 * Provides standardized task execution interface with shared helpers for
 * job completion, failure handling, rescheduling, and undo. All async
 * system tasks must extend this class.
 *
 * ## Undo System
 *
 * Tasks that modify WordPress content can opt into undo support by:
 * 1. Recording effects during execution via the standardized effects array
 * 2. Returning `true` from `supportsUndo()`
 *
 * The base class provides generic undo handlers for common effect types
 * (post_content_modified, post_meta_set, attachment_created, featured_image_set).
 * Tasks only override `undo()` if they need custom reversal logic.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.22.4
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;

abstract class SystemTask {

	/**
	 * Execute the task for a specific job.
	 *
	 * @param int   $jobId  The job ID from DM Jobs table.
	 * @param array $params Task parameters from the job's engine_data.
	 */
	abstract public function execute( int $jobId, array $params ): void;

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

	/**
	 * Whether this task type supports undo.
	 *
	 * Tasks opt in by overriding this to return true and recording
	 * effects in their engine_data during execution.
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
	 * Reads the standardized effects array from engine_data and reverses
	 * each effect in reverse order. Tasks may override for custom logic.
	 *
	 * @param int   $jobId      The job ID to undo.
	 * @param array $engineData The job's full engine_data.
	 * @return array{success: bool, reverted: array, skipped: array, failed: array}
	 * @since 0.33.0
	 */
	public function undo( int $jobId, array $engineData ): array {
		$effects = $engineData['effects'] ?? array();

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
	 * Supported effect types:
	 * - post_content_modified: restores from WP revision
	 * - post_meta_set: restores previous value or deletes meta
	 * - attachment_created: deletes the attachment
	 * - featured_image_set: removes or restores previous thumbnail
	 *
	 * Unknown effect types are skipped (not failed) so tasks with mixed
	 * reversible/irreversible effects degrade gracefully.
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

		// Reverse order: undo last effect first.
		foreach ( array_reverse( $effects ) as $effect ) {
			$type   = $effect['type'] ?? '';
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
	 * @param array $effect Single effect entry from the effects array.
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
	 * @param array $effect Effect with revision_id and target.post_id.
	 * @return array
	 * @since 0.33.0
	 */
	protected function undoContentModification( array $effect ): array {
		$post_id     = $effect['target']['post_id'] ?? 0;
		$revision_id = $effect['revision_id'] ?? 0;

		if ( $post_id <= 0 ) {
			return array(
				'status' => 'failed',
				'type'   => 'post_content_modified',
				'reason' => 'Missing post_id in effect target',
			);
		}

		if ( $revision_id <= 0 ) {
			return array(
				'status' => 'failed',
				'type'   => 'post_content_modified',
				'reason' => "No revision_id recorded for post #{$post_id}",
			);
		}

		$revision = get_post( $revision_id );

		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return array(
				'status' => 'failed',
				'type'   => 'post_content_modified',
				'reason' => "Revision #{$revision_id} not found or not a revision",
			);
		}

		$restored = wp_restore_post_revision( $revision_id );

		if ( ! $restored ) {
			return array(
				'status' => 'failed',
				'type'   => 'post_content_modified',
				'reason' => "Failed to restore revision #{$revision_id} for post #{$post_id}",
			);
		}

		return array(
			'status'      => 'reverted',
			'type'        => 'post_content_modified',
			'post_id'     => $post_id,
			'revision_id' => $revision_id,
		);
	}

	/**
	 * Undo a post meta set operation.
	 *
	 * Restores the previous value if one was recorded, otherwise deletes the meta key.
	 *
	 * @param array $effect Effect with target.post_id, target.meta_key, and optional previous_value.
	 * @return array
	 * @since 0.33.0
	 */
	protected function undoMetaSet( array $effect ): array {
		$post_id  = $effect['target']['post_id'] ?? 0;
		$meta_key = $effect['target']['meta_key'] ?? '';

		if ( $post_id <= 0 || empty( $meta_key ) ) {
			return array(
				'status' => 'failed',
				'type'   => 'post_meta_set',
				'reason' => 'Missing post_id or meta_key in effect target',
			);
		}

		if ( array_key_exists( 'previous_value', $effect ) && null !== $effect['previous_value'] ) {
			update_post_meta( $post_id, $meta_key, $effect['previous_value'] );
		} else {
			delete_post_meta( $post_id, $meta_key );
		}

		return array(
			'status'   => 'reverted',
			'type'     => 'post_meta_set',
			'post_id'  => $post_id,
			'meta_key' => $meta_key,
		);
	}

	/**
	 * Undo an attachment creation by deleting the attachment.
	 *
	 * @param array $effect Effect with target.attachment_id.
	 * @return array
	 * @since 0.33.0
	 */
	protected function undoAttachmentCreated( array $effect ): array {
		$attachment_id = $effect['target']['attachment_id'] ?? 0;

		if ( $attachment_id <= 0 ) {
			return array(
				'status' => 'failed',
				'type'   => 'attachment_created',
				'reason' => 'Missing attachment_id in effect target',
			);
		}

		$deleted = wp_delete_attachment( $attachment_id, true );

		if ( ! $deleted ) {
			return array(
				'status' => 'failed',
				'type'   => 'attachment_created',
				'reason' => "Failed to delete attachment #{$attachment_id}",
			);
		}

		return array(
			'status'        => 'reverted',
			'type'          => 'attachment_created',
			'attachment_id' => $attachment_id,
		);
	}

	/**
	 * Undo a featured image set operation.
	 *
	 * Restores the previous thumbnail if one was recorded, otherwise removes it.
	 *
	 * @param array $effect Effect with target.post_id and optional previous_value.
	 * @return array
	 * @since 0.33.0
	 */
	protected function undoFeaturedImageSet( array $effect ): array {
		$post_id = $effect['target']['post_id'] ?? 0;

		if ( $post_id <= 0 ) {
			return array(
				'status' => 'failed',
				'type'   => 'featured_image_set',
				'reason' => 'Missing post_id in effect target',
			);
		}

		$previous = $effect['previous_value'] ?? 0;

		if ( $previous > 0 ) {
			set_post_thumbnail( $post_id, $previous );
		} else {
			delete_post_thumbnail( $post_id );
		}

		return array(
			'status'  => 'reverted',
			'type'    => 'featured_image_set',
			'post_id' => $post_id,
		);
	}

	/**
	 * Complete a job with successful results.
	 *
	 * Updates the job's engine_data with the result and marks it as completed.
	 *
	 * @param int   $jobId Job ID.
	 * @param array $result Result data to store in engine_data.
	 */
	protected function completeJob( int $jobId, array $result ): void {
		$jobs_db = new Jobs();

		// Store result in engine_data
		$jobs_db->store_engine_data( $jobId, $result );

		// Mark job as completed
		$jobs_db->complete_job( $jobId, JobStatus::COMPLETED );

		do_action(
			'datamachine_log',
			'info',
			"System Agent task completed successfully for job {$jobId}",
			array(
				'job_id'     => $jobId,
				'task_type'  => $this->getTaskType(),
				'agent_type' => 'system',
				'result'     => $result,
			)
		);
	}

	/**
	 * Fail a job with error reason.
	 *
	 * Updates the job's engine_data with error details and marks it as failed.
	 *
	 * @param int    $jobId  Job ID.
	 * @param string $reason Failure reason.
	 */
	protected function failJob( int $jobId, string $reason ): void {
		$jobs_db = new Jobs();

		// Store error in engine_data
		$error_data = array(
			'error'     => $reason,
			'failed_at' => current_time( 'mysql' ),
			'task_type' => $this->getTaskType(),
		);
		$jobs_db->store_engine_data( $jobId, $error_data );

		// Mark job as failed
		$jobs_db->complete_job( $jobId, JobStatus::failed( $reason )->toString() );

		do_action(
			'datamachine_log',
			'error',
			"System Agent task failed for job {$jobId}: {$reason}",
			array(
				'job_id'     => $jobId,
				'task_type'  => $this->getTaskType(),
				'agent_type' => 'system',
				'error'      => $reason,
			)
		);
	}

	/**
	 * Reschedule a job for later execution.
	 *
	 * Useful for polling scenarios where the task needs to check status again.
	 * Includes attempt tracking to prevent infinite rescheduling.
	 *
	 * @param int $jobId        Job ID.
	 * @param int $delaySeconds Delay in seconds before next execution.
	 */
	protected function reschedule( int $jobId, int $delaySeconds = 10 ): void {
		$jobs_db = new Jobs();

		// Get current engine_data to track attempts
		$job = $jobs_db->get_job( $jobId );
		if ( ! $job ) {
			$this->failJob( $jobId, 'Job not found for rescheduling' );
			return;
		}

		$engine_data  = $job['engine_data'] ?? array();
		$attempts     = ( $engine_data['attempts'] ?? 0 ) + 1;
		$max_attempts = $engine_data['max_attempts'] ?? 24; // Default 24 attempts

		// Check if we've exceeded max attempts
		if ( $attempts > $max_attempts ) {
			$this->failJob( $jobId, "Task exceeded maximum attempts ({$max_attempts})" );
			return;
		}

		// Update attempt count
		$engine_data['attempts']     = $attempts;
		$engine_data['last_attempt'] = current_time( 'mysql' );
		$jobs_db->store_engine_data( $jobId, $engine_data );

		// Schedule next execution
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$args = array(
				'job_id' => $jobId,
			);

			as_schedule_single_action(
				time() + $delaySeconds,
				'datamachine_system_agent_handle_task',
				$args,
				'data-machine'
			);

			do_action(
				'datamachine_log',
				'debug',
				"System Agent task rescheduled for job {$jobId} (attempt {$attempts}/{$max_attempts})",
				array(
					'job_id'        => $jobId,
					'task_type'     => $this->getTaskType(),
					'agent_type'    => 'system',
					'attempts'      => $attempts,
					'max_attempts'  => $max_attempts,
					'delay_seconds' => $delaySeconds,
				)
			);
		} else {
			$this->failJob( $jobId, 'Action Scheduler not available for rescheduling' );
		}
	}
}
