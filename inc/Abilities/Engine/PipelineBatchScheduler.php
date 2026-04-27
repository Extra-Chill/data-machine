<?php
/**
 * Pipeline Batch Scheduler
 *
 * Pipeline-specific consumer of {@see \DataMachine\Core\ActionScheduler\BatchScheduler}.
 * Fans out N DataPackets into N child *pipeline jobs* that each carry one
 * packet through the remaining pipeline steps independently.
 *
 * Owns:
 *   - createChildJob(): pipeline-specific glue (engine_data cloning,
 *     per-item engine data seeding from packet metadata, agent_id/user_id
 *     carry-over, datamachine_schedule_next_step dispatch).
 *   - onChildComplete(): wired to datamachine_job_complete; aggregates
 *     child status counts into the parent's final status.
 *
 * Does NOT own:
 *   - The chunking loop, state storage, cancellation, chunk_size/chunk_delay
 *     reads, or chunk re-scheduling. Those live in BatchScheduler and apply
 *     uniformly across pipeline + system-task fan-out.
 *
 * @package DataMachine\Abilities\Engine
 * @since 0.35.0
 * @since 0.82.0 Chunking loop extracted to BatchScheduler.
 */

namespace DataMachine\Abilities\Engine;

use DataMachine\Core\ActionScheduler\BatchScheduler;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;

defined( 'ABSPATH' ) || exit;

class PipelineBatchScheduler {

	/**
	 * Action Scheduler hook for processing batch chunks.
	 */
	const BATCH_HOOK = 'datamachine_pipeline_batch_chunk';

	/**
	 * Consumer context, used by BatchScheduler when reading chunk_size /
	 * chunk_delay so filter consumers can tell pipeline fan-out apart
	 * from system-task fan-out.
	 */
	const BATCH_CONTEXT = 'pipeline';

	/**
	 * @var Jobs
	 */
	private Jobs $db_jobs;

	public function __construct() {
		$this->db_jobs = new Jobs();
	}

	/**
	 * Fan out DataPackets into child jobs.
	 *
	 * Records the engine_snapshot on the parent's batch_state so each
	 * subsequently-scheduled chunk has the data it needs to spawn a
	 * pipeline-shaped child without re-reading the parent's full state.
	 *
	 * @param int    $parent_job_id     The current job ID (becomes the parent).
	 * @param string $next_flow_step_id The next step to execute on each child.
	 * @param array  $dataPackets       Array of DataPacket arrays from the fetch step.
	 * @param array  $engine_snapshot   The parent's engine_data to clone to children.
	 * @return array Result with batch details.
	 */
	public function fanOut(
		int $parent_job_id,
		string $next_flow_step_id,
		array $dataPackets,
		array $engine_snapshot
	): array {
		$total     = count( $dataPackets );
		$flow_name = $engine_snapshot['flow']['name'] ?? '';

		$result = BatchScheduler::start(
			$parent_job_id,
			self::BATCH_HOOK,
			$dataPackets,
			array(
				'next_flow_step_id' => $next_flow_step_id,
				'engine_snapshot'   => $engine_snapshot,
			),
			self::BATCH_CONTEXT,
			BatchScheduler::COMPLETION_STRATEGY_CHILDREN_COMPLETE
		);

		// Surface next_flow_step_id at the top level for legacy consumers
		// that read it without descending into batch_state. Parity with
		// the pre-extraction shape.
		datamachine_merge_engine_data(
			$parent_job_id,
			array( 'next_flow_step_id' => $next_flow_step_id )
		);

		do_action(
			'datamachine_log',
			'info',
			sprintf( 'Pipeline batch: fanning out %d items for flow "%s"', $total, $flow_name ),
			array(
				'parent_job_id'     => $parent_job_id,
				'pipeline_id'       => $engine_snapshot['job']['pipeline_id'] ?? 0,
				'flow_id'           => $engine_snapshot['job']['flow_id'] ?? 0,
				'total'             => $total,
				'next_flow_step_id' => $next_flow_step_id,
			)
		);

		return $result;
	}

	/**
	 * Process a chunk of the batch.
	 *
	 * Action Scheduler callback — delegates to BatchScheduler::processChunk
	 * with a pipeline-specific child-creation callback.
	 *
	 * @param int $parent_job_id The parent job ID.
	 */
	public function processChunk( int $parent_job_id ): void {
		$result = BatchScheduler::processChunk(
			$parent_job_id,
			array( $this, 'createChildJobFromBatch' )
		);

		if ( $result['missing'] ) {
			$this->failParentIfStillProcessing( $parent_job_id, 'batch_state_missing' );
			do_action(
				'datamachine_log',
				'error',
				'Pipeline batch: batch state missing from engine_data',
				array( 'parent_job_id' => $parent_job_id )
			);
			return;
		}

		if ( $result['cancelled'] ) {
			$this->db_jobs->complete_job(
				$parent_job_id,
				JobStatus::failed( 'batch cancelled' )->toString()
			);
			return;
		}

		do_action(
			'datamachine_log',
			'debug',
			sprintf(
				'Pipeline batch chunk: scheduled %d/%d (offset %d)',
				$result['scheduled'],
				$result['total'],
				$result['offset']
			),
			array(
				'parent_job_id' => $parent_job_id,
				'scheduled'     => $result['scheduled'],
				'offset'        => $result['offset'],
				'total'         => $result['total'],
			)
		);

		// Last chunk — verify at least one child was actually created
		// across the whole batch. Without this, a batch where every
		// createChildJob() returned false would silently complete with
		// no children, no error, and no clear failure mode.
		if ( ! $result['more'] ) {
			$child_count = $this->countChildren( $parent_job_id );
			if ( $child_count < 1 ) {
				$this->db_jobs->complete_job(
					$parent_job_id,
					JobStatus::failed( 'batch_no_children_scheduled' )->toString()
				);

				do_action(
					'datamachine_log',
					'error',
					'Pipeline batch: no child jobs were scheduled; parent marked failed',
					array(
						'parent_job_id' => $parent_job_id,
						'total'         => $result['total'],
					)
				);

				return;
			}

			do_action(
				'datamachine_log',
				'info',
				sprintf( 'Pipeline batch: all %d items scheduled', $result['total'] ),
				array( 'parent_job_id' => $parent_job_id )
			);
		}
	}

	/**
	 * BatchScheduler callback: spawn one child job for one DataPacket.
	 *
	 * Signature is (item, extra, parent_job_id) per the BatchScheduler
	 * contract; we forward to the existing createChildJob().
	 *
	 * @param array $single_packet  A single DataPacket array.
	 * @param array $extra          Per-batch state (engine_snapshot, next_flow_step_id).
	 * @param int   $parent_job_id  Parent job ID.
	 * @return int|false Child job ID or false on failure.
	 */
	public function createChildJobFromBatch( array $single_packet, array $extra, int $parent_job_id ): int|false {
		return $this->createChildJob(
			$parent_job_id,
			(string) ( $extra['next_flow_step_id'] ?? '' ),
			$single_packet,
			is_array( $extra['engine_snapshot'] ?? null ) ? $extra['engine_snapshot'] : array()
		);
	}

	/**
	 * Create a single child job for one DataPacket.
	 *
	 * Clones the parent's engine_data, seeds per-item engine data from
	 * the DataPacket's _engine_data metadata key, and schedules the next
	 * step via the normal engine path.
	 *
	 * @param int    $parent_job_id     Parent job ID.
	 * @param string $next_flow_step_id Next step to execute.
	 * @param array  $single_packet     A single DataPacket (the array structure, not the object).
	 * @param array  $engine_snapshot   Engine data to clone to child.
	 * @return int|false Child job ID or false on failure.
	 */
	private function createChildJob(
		int $parent_job_id,
		string $next_flow_step_id,
		array $single_packet,
		array $engine_snapshot
	): int|false {
		$pipeline_id = $engine_snapshot['job']['pipeline_id'] ?? null;
		$flow_id     = $engine_snapshot['job']['flow_id'] ?? null;
		$item_title  = $single_packet['data']['title'] ?? 'Untitled';

		// Normalize: 0 → null when no pipeline/flow context.
		$pipeline_id = ( empty( $pipeline_id ) && ! is_string( $pipeline_id ) ) ? null : $pipeline_id;
		$flow_id     = ( empty( $flow_id ) && ! is_string( $flow_id ) ) ? null : $flow_id;

		// Carry the parent's agent_id + user_id onto the child so it
		// runs under the same identity. Without this, child jobs lose
		// their agent binding and downstream consumers fall back to
		// the default-agent lookup (wrong agent's memory files, wrong
		// model resolution, wrong permission context).
		$parent_agent_id = (int) ( $engine_snapshot['job']['agent_id'] ?? 0 );
		$parent_user_id  = (int) ( $engine_snapshot['job']['user_id'] ?? 0 );

		$child_job_args = array(
			'pipeline_id'   => $pipeline_id,
			'flow_id'       => $flow_id,
			'source'        => $pipeline_id ? 'pipeline' : 'direct',
			'label'         => $item_title,
			'parent_job_id' => $parent_job_id,
		);

		if ( $parent_agent_id > 0 ) {
			$child_job_args['agent_id'] = $parent_agent_id;
		}
		if ( $parent_user_id > 0 ) {
			$child_job_args['user_id'] = $parent_user_id;
		}

		$child_job_id = $this->db_jobs->create_job( $child_job_args );

		if ( ! $child_job_id ) {
			do_action(
				'datamachine_log',
				'error',
				'Pipeline batch: failed to create child job',
				array(
					'parent_job_id' => $parent_job_id,
					'item_title'    => $item_title,
				)
			);
			return false;
		}

		// Clone engine_data to child, updating the job context. Preserves
		// agent_id and user_id (resolved above) so downstream consumers
		// like CoreMemoryFilesDirective resolve the correct agent's
		// MEMORY.md / SOUL.md instead of falling back to the user_id
		// default-agent lookup.
		$child_engine        = $engine_snapshot;
		$child_engine['job'] = array(
			'job_id'        => $child_job_id,
			'flow_id'       => $flow_id,
			'pipeline_id'   => $pipeline_id,
			'agent_id'      => $parent_agent_id > 0 ? $parent_agent_id : null,
			'user_id'       => $parent_user_id > 0 ? $parent_user_id : null,
			'created_at'    => current_time( 'mysql', true ),
			'parent_job_id' => $parent_job_id,
		);

		// Seed per-item engine data from DataPacket metadata.
		// Handlers put per-item context (venue data, source_url, etc.)
		// into metadata['_engine_data'] so each child job gets its own
		// copy instead of sharing the parent's (which would be the last
		// item's data overwriting all previous items).
		$item_engine_data = $single_packet['metadata']['_engine_data'] ?? array();
		if ( ! empty( $item_engine_data ) && is_array( $item_engine_data ) ) {
			$item_engine_data = $this->removeReservedEngineDataKeys( $item_engine_data, $parent_job_id );
			$child_engine = array_merge( $child_engine, $item_engine_data );
		}

		// Seed dedup context (item_identifier + source_type) from DataPacket metadata.
		// This enables deferred mark-as-processed: when the child job completes
		// its last step, ExecuteStepAbility reads these to mark the item as
		// processed. Previously set by FetchHandler::onItemProcessed() on the
		// parent, but now the fetch step no longer marks items eagerly.
		$item_identifier = $single_packet['metadata']['item_identifier'] ?? null;
		$source_type     = $single_packet['metadata']['source_type'] ?? null;
		if ( ! empty( $item_identifier ) ) {
			$child_engine['item_identifier'] = $item_identifier;
		}
		if ( ! empty( $source_type ) ) {
			$child_engine['source_type'] = $source_type;
		}

		datamachine_set_engine_data( $child_job_id, $child_engine );

		// Child job stays 'pending' until Action Scheduler actually picks it up.
		// ExecuteStepAbility transitions to 'processing' at execution time,
		// so recover-stuck only catches genuinely stuck jobs.

		// Schedule the next step with this single DataPacket.
		// Uses the normal engine path — the child is a real pipeline job.
		do_action(
			'datamachine_schedule_next_step',
			$child_job_id,
			$next_flow_step_id,
			array( $single_packet )
		);

		return $child_job_id;
	}

	/**
	 * Remove packet-provided keys that belong to the canonical engine snapshot.
	 *
	 * Packet metadata may provide per-item context, but it must not overwrite
	 * job/flow/pipeline/batch state cloned from the parent execution.
	 *
	 * @param array $engine_data   Packet-provided engine data.
	 * @param int   $parent_job_id Parent job ID for logging context.
	 * @return array Sanitized per-item engine data.
	 */
	private function removeReservedEngineDataKeys( array $engine_data, int $parent_job_id ): array {
		$reserved = array();

		foreach ( array_keys( $engine_data ) as $key ) {
			if ( $this->isReservedEngineDataKey( (string) $key ) ) {
				$reserved[] = (string) $key;
				unset( $engine_data[ $key ] );
			}
		}

		if ( $reserved ) {
			do_action(
				'datamachine_log',
				'warning',
				'Pipeline batch: dropped packet engine_data keys reserved for child job context',
				array(
					'parent_job_id' => $parent_job_id,
					'keys'          => $reserved,
				)
			);
		}

		return $engine_data;
	}

	/**
	 * Check whether a packet-provided engine_data key is reserved.
	 *
	 * @param string $key Engine data key.
	 * @return bool
	 */
	private function isReservedEngineDataKey( string $key ): bool {
		if ( str_starts_with( $key, 'batch' ) ) {
			return true;
		}

		return in_array(
			$key,
			array(
				'job',
				'flow',
				'pipeline',
				'flow_config',
				'pipeline_config',
			),
			true
		);
	}

	/**
	 * Handle child job completion.
	 *
	 * Called via datamachine_job_complete hook. Checks if all children
	 * of the parent are finished and updates the parent accordingly.
	 *
	 * @param int    $job_id Job ID that just completed.
	 * @param string $status The completion status.
	 */
	public static function onChildComplete( int $job_id, string $status ): void {
		$status;
		$jobs_db = new Jobs();
		$job     = $jobs_db->get_job( $job_id );

		if ( ! $job ) {
			return;
		}

		$parent_job_id = $job['parent_job_id'] ?? 0;

		if ( empty( $parent_job_id ) ) {
			return; // Not a child job.
		}

		// Check parent is a pipeline batch.
		$parent_engine = datamachine_get_engine_data( (int) $parent_job_id );
		if ( empty( $parent_engine['batch'] ) ) {
			return; // Not a pipeline batch parent.
		}

		// Pipeline-only — system-task batches use the same engine_data
		// shape but their parent is completed inline by TaskScheduler.
		$context = $parent_engine['batch_context'] ?? '';
		if ( '' !== $context && self::BATCH_CONTEXT !== $context ) {
			return;
		}

		// Count child statuses.
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total,
					SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
					SUM(CASE WHEN status LIKE 'failed%%' THEN 1 ELSE 0 END) as failed,
					SUM(CASE WHEN status LIKE 'agent_skipped%%' THEN 1 ELSE 0 END) as skipped,
					SUM(CASE WHEN status = 'processing' OR status = 'pending' THEN 1 ELSE 0 END) as active
				FROM {$table}
				WHERE parent_job_id = %d",
				$parent_job_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL

		if ( ! $counts ) {
			return;
		}

		$total_children = (int) $counts['total'];
		$active         = (int) $counts['active'];
		$batch_total    = (int) ( $parent_engine['batch_total'] ?? $total_children );

		// Still have active children or not all scheduled yet.
		if ( $active > 0 || $total_children < $batch_total ) {
			return;
		}

		// All children are done. Complete the parent.
		$completed = (int) $counts['completed'];
		$failed    = (int) $counts['failed'];
		$skipped   = (int) $counts['skipped'];

		if ( $completed > 0 ) {
			$parent_status = JobStatus::COMPLETED;
		} elseif ( $failed === $total_children ) {
			$parent_status = JobStatus::failed(
				sprintf( 'All %d child jobs failed', $total_children )
			)->toString();
		} else {
			$parent_status = JobStatus::COMPLETED_NO_ITEMS;
		}

		$parent_engine['batch_results'] = array(
			'completed' => $completed,
			'failed'    => $failed,
			'skipped'   => $skipped,
			'total'     => $total_children,
		);

		datamachine_set_engine_data( (int) $parent_job_id, $parent_engine );
		$jobs_db->complete_job( (int) $parent_job_id, $parent_status );

		$flow_name = $parent_engine['flow']['name'] ?? '';

		do_action(
			'datamachine_log',
			'info',
			sprintf(
				'Pipeline batch complete: %d/%d succeeded for flow "%s"',
				$completed,
				$total_children,
				$flow_name
			),
			array(
				'parent_job_id' => $parent_job_id,
				'completed'     => $completed,
				'failed'        => $failed,
				'skipped'       => $skipped,
				'total'         => $total_children,
			)
		);
	}

	/**
	 * Mark parent as failed when batch state is missing.
	 *
	 * @param int    $parent_job_id Parent job ID.
	 * @param string $reason        Failure reason suffix.
	 */
	private function failParentIfStillProcessing( int $parent_job_id, string $reason ): void {
		$job = $this->db_jobs->get_job( $parent_job_id );

		if ( ! $job ) {
			return;
		}

		$current_status = $job['status'] ?? '';
		if ( JobStatus::PROCESSING !== $current_status ) {
			return;
		}

		$this->db_jobs->complete_job( $parent_job_id, JobStatus::failed( $reason )->toString() );
	}

	/**
	 * Count child jobs for a parent job.
	 *
	 * @param int $parent_job_id Parent job ID.
	 * @return int
	 */
	private function countChildren( int $parent_job_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE parent_job_id = %d",
				$parent_job_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		return $count;
	}
}
