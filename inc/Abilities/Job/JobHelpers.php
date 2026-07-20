<?php
/**
 * Job Helpers Trait
 *
 * Shared helper methods used across all Job ability classes.
 * Provides database access, formatting, and utility operations.
 *
 * @package DataMachine\Abilities\Job
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Job;

use DataMachine\Abilities\Flow\QueueAbility;
use DataMachine\Abilities\ExecutionScope;
use DataMachine\Abilities\PermissionHelper;

use DataMachine\Core\Admin\DateFormatter;
use DataMachine\Core\JobArtifactSurfaces;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;

defined( 'ABSPATH' ) || exit;

trait JobHelpers {

	protected Jobs $db_jobs;
	protected Flows $db_flows;
	protected Pipelines $db_pipelines;
	protected ProcessedItems $db_processed_items;

	protected function initDatabases(): void {
		$this->db_jobs            = new Jobs();
		$this->db_flows           = new Flows();
		$this->db_pipelines       = new Pipelines();
		$this->db_processed_items = new ProcessedItems();
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Check row ownership while preserving capability-gated access to legacy jobs.
	 *
	 * Jobs created before ownership was persisted have user_id=0 and agent_id=NULL.
	 * Those rows retain the shipped manage-jobs behavior; owned rows require the
	 * matching user/agent or privileged operational access.
	 *
	 * @param array $job Job row.
	 * @return bool
	 */
	protected function canAccessJob( array $job ): bool {
		$scope    = ExecutionScope::current( 'manage_flows' );
		$user_id  = max( 0, (int) ( $job['user_id'] ?? 0 ) );
		$agent_id = isset( $job['agent_id'] ) && (int) $job['agent_id'] > 0 ? (int) $job['agent_id'] : null;

		if ( 0 === $user_id && null === $agent_id ) {
			$legacy_unowned = empty( $job['request_fingerprint'] ) && empty( $job['operation_state'] );
			return $legacy_unowned
				? $scope->can_action()
				: PermissionHelper::has_privileged_resource_access( 'manage_flows' );
		}

		return $scope->owns_agent_resource( $agent_id, $user_id );
	}

	/**
	 * Standard failed result for an inaccessible job row.
	 *
	 * @return array
	 */
	protected function jobAccessDenied(): array {
		return array(
			'success'    => false,
			'error_code' => 'job_access_denied',
			'error'      => 'You do not have permission to access this job.',
			'status'     => 403,
		);
	}

	/**
	 * Apply authoritative ownership constraints to a job collection query.
	 *
	 * @param int|null $requested_user_id  Caller-selected user filter.
	 * @param int|null $requested_agent_id Caller-selected agent filter.
	 * @return array{user_id?:int,agent_id?:int}|array{error:string}
	 */
	protected function jobCollectionScope( ?int $requested_user_id, ?int $requested_agent_id ): array {
		if ( PermissionHelper::has_privileged_resource_access( 'manage_flows' ) ) {
			if ( null !== $requested_agent_id ) {
				return array( 'agent_id' => $requested_agent_id );
			}
			return null !== $requested_user_id ? array( 'user_id' => $requested_user_id ) : array();
		}

		$acting_user_id = PermissionHelper::acting_user_id();
		if ( null !== $requested_agent_id ) {
			return PermissionHelper::can_access_agent( $requested_agent_id )
				? array( 'agent_id' => $requested_agent_id )
				: array( 'error' => 'You do not have permission to access jobs for this agent.' );
		}

		$acting_agent_id = PermissionHelper::get_acting_agent_id();
		if ( null !== $acting_agent_id ) {
			return array( 'agent_id' => $acting_agent_id );
		}

		if ( null !== $requested_user_id && $requested_user_id !== $acting_user_id ) {
			return array( 'error' => 'You do not have permission to access jobs for this user.' );
		}

		if ( $acting_user_id <= 0 ) {
			return array( 'error' => 'An authenticated acting caller is required to list owned jobs.' );
		}

		return array( 'user_id' => $acting_user_id );
	}

	/**
	 * Enrich jobs with pipeline_name and flow_name via batch lookup.
	 *
	 * The SQL JOIN in get_jobs_for_list_table should provide these, but if the
	 * JOIN returns NULL (type mismatch, missing data, etc.) this fills them in
	 * via direct lookups, batched to avoid N+1 queries.
	 *
	 * @param array $jobs Array of job rows.
	 * @return array Jobs with pipeline_name and flow_name populated.
	 */
	protected function enrichJobNames( array $jobs ): array {
		// Collect IDs that need lookup.
		$pipeline_ids = array();
		$flow_ids     = array();

		foreach ( $jobs as $job ) {
			if ( empty( $job['pipeline_name'] ) && ! empty( $job['pipeline_id'] ) && is_numeric( $job['pipeline_id'] ) ) {
				$pipeline_ids[ (int) $job['pipeline_id'] ] = true;
			}
			if ( empty( $job['flow_name'] ) && ! empty( $job['flow_id'] ) && is_numeric( $job['flow_id'] ) ) {
				$flow_ids[ (int) $job['flow_id'] ] = true;
			}
		}

		// Nothing to look up — JOIN worked fine.
		if ( empty( $pipeline_ids ) && empty( $flow_ids ) ) {
			return $jobs;
		}

		// Batch-fetch pipeline names.
		$pipeline_names = array();
		foreach ( array_keys( $pipeline_ids ) as $pid ) {
			$pipeline = $this->db_pipelines->get_pipeline( $pid );
			if ( $pipeline ) {
				$pipeline_names[ $pid ] = $pipeline['pipeline_name'] ?? '';
			}
		}

		// Batch-fetch flow names.
		$flow_names = array();
		foreach ( array_keys( $flow_ids ) as $fid ) {
			$flow = $this->db_flows->get_flow( $fid );
			if ( $flow ) {
				$flow_names[ $fid ] = $flow['flow_name'] ?? '';
			}
		}

		// Apply lookups.
		foreach ( $jobs as &$job ) {
			if ( empty( $job['pipeline_name'] ) && isset( $pipeline_names[ (int) ( $job['pipeline_id'] ?? 0 ) ] ) ) {
				$job['pipeline_name'] = $pipeline_names[ (int) $job['pipeline_id'] ];
			}
			if ( empty( $job['flow_name'] ) && isset( $flow_names[ (int) ( $job['flow_id'] ?? 0 ) ] ) ) {
				$job['flow_name'] = $flow_names[ (int) $job['flow_id'] ];
			}
		}
		unset( $job );

		return $jobs;
	}

	/**
	 * Add formatted display fields for timestamps.
	 *
	 * @param array $job Job data.
	 * @return array Job data with *_display fields added.
	 */
	protected function addDisplayFields( array $job ): array {
		if ( isset( $job['created_at'] ) ) {
			$job['created_at_display'] = DateFormatter::format_for_display( $job['created_at'] );
		}

		if ( isset( $job['completed_at'] ) ) {
			$job['completed_at_display'] = DateFormatter::format_for_display( $job['completed_at'] );
		}

		if ( is_array( $job['engine_data'] ?? null ) ) {
			$job_summary = JobArtifactSurfaces::summary( $job, $job['engine_data'] );
			if ( ! empty( $job_summary ) ) {
				$job['job_summary'] = $job_summary;
			}
		}

		// Compute display_label for UI
		if ( ! empty( $job['label'] ) ) {
			$job['display_label'] = $job['label'];
		} elseif ( ! empty( $job['pipeline_name'] ) && ! empty( $job['flow_name'] ) ) {
			$job['display_label'] = $job['pipeline_name'] . ' → ' . $job['flow_name'];
		} else {
			$source               = $job['source'] ?? 'unknown';
			$job['display_label'] = ucfirst( $source ) . ' Execution';
		}

		return $job;
	}

	/**
	 * Create a new job for a flow execution.
	 *
	 * @param int $flow_id Flow ID to execute.
	 * @param int $pipeline_id Pipeline ID (optional, will be looked up if not provided).
	 * @return int|null Job ID on success, null on failure.
	 */
	protected function createJob( int $flow_id, int $pipeline_id = 0 ): ?int {
		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			do_action( 'datamachine_log', 'error', 'Job creation failed - flow not found', array( 'flow_id' => $flow_id ) );
			return null;
		}

		if ( $pipeline_id <= 0 ) {
			$pipeline_id = (int) $flow['pipeline_id'];
		}

		$flow_name = $flow['flow_name'] ?? null;

		$job_id = $this->db_jobs->create_job(
			array(
				'pipeline_id' => $pipeline_id,
				'flow_id'     => $flow_id,
				'source'      => 'pipeline',
				'label'       => $flow_name,
			)
		);

		if ( ! $job_id ) {
			do_action(
				'datamachine_log',
				'error',
				'Job creation failed - database insert failed',
				array(
					'flow_id'     => $flow_id,
					'pipeline_id' => $pipeline_id,
				)
			);
			return null;
		}

		do_action(
			'datamachine_log',
			'debug',
			'Job created',
			array(
				'job_id'      => $job_id,
				'flow_id'     => $flow_id,
				'pipeline_id' => $pipeline_id,
			)
		);

		return $job_id;
	}

	/**
	 * Restore a queue item backup when retry/recovery needs to undo a drain-mode consume.
	 *
	 * Loop mode already rotates the consumed item back into the queue, and static mode never
	 * mutates queue storage, so only drain-mode backups are appended.
	 *
	 * @param int   $flow_id Flow ID containing the queued step.
	 * @param array $backup  queued_prompt_backup payload from job engine_data.
	 * @return bool Whether the backup was restored to the flow config.
	 */
	protected function restoreQueuedPromptBackup( int $flow_id, array $backup ): bool {
		return QueueAbility::restoreConsumedEntryBackup( $flow_id, $backup, $this->db_flows );
	}

	/**
	 * Apply queue-backup restoration semantics to an in-memory flow config.
	 *
	 * @param array $flow_config Flow config to mutate when restoration is needed.
	 * @param array $backup      queued_prompt_backup payload from job engine_data.
	 * @return bool Whether an entry was appended.
	 */
	protected function restoreQueuedPromptBackupToFlowConfig( array &$flow_config, array $backup ): bool {
		return QueueAbility::restoreConsumedEntryBackupToFlowConfig( $flow_config, $backup );
	}

	/**
	 * Delete jobs based on criteria.
	 *
	 * @param array $criteria Deletion criteria ('all' => true or 'failed' => true).
	 * @param bool  $cleanup_processed Whether to cleanup associated processed items.
	 * @return array Result with deleted count and cleanup info.
	 */
	protected function deleteJobs( array $criteria, bool $cleanup_processed = false ): array {
		$job_ids_to_delete = array();

		if ( $cleanup_processed ) {
			global $wpdb;
			$jobs_table = $wpdb->prefix . 'datamachine_jobs';

			if ( ! empty( $criteria['failed'] ) ) {
				$failed_pattern = $wpdb->esc_like( 'failed' ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$job_ids_to_delete = $wpdb->get_col( $wpdb->prepare( 'SELECT job_id FROM %i WHERE status LIKE %s', $jobs_table, $failed_pattern ) );
			} elseif ( ! empty( $criteria['all'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$job_ids_to_delete = $wpdb->get_col( $wpdb->prepare( 'SELECT job_id FROM %i', $jobs_table ) );
			}
		}

		$deleted_count = $this->db_jobs->delete_jobs( $criteria );

		if ( false === $deleted_count ) {
			return array(
				'success'                 => false,
				'jobs_deleted'            => 0,
				'processed_items_cleaned' => 0,
			);
		}

		if ( $cleanup_processed && ! empty( $job_ids_to_delete ) ) {
			foreach ( $job_ids_to_delete as $job_id ) {
				$this->db_processed_items->delete_processed_items( array( 'job_id' => (int) $job_id ) );
			}
		}

		return array(
			'success'                 => true,
			'jobs_deleted'            => $deleted_count,
			'processed_items_cleaned' => $cleanup_processed ? count( $job_ids_to_delete ) : 0,
		);
	}
}
