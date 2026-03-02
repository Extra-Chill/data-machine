<?php
/**
 * Pipeline Batch Scheduler
 *
 * Handles fan-out of multiple DataPackets into child jobs. When a pipeline
 * step returns N DataPackets, this scheduler creates N child jobs that each
 * carry one DataPacket through the remaining pipeline steps independently.
 *
 * The original job becomes the parent and tracks overall progress. Child
 * jobs use the same engine_data (flow_config, pipeline_config) but operate
 * on their own DataPacket.
 *
 * This is not a special mode — it's how the engine works. A single DataPacket
 * is simply a batch of one.
 *
 * @package DataMachine\Abilities\Engine
 * @since 0.35.0
 */

namespace DataMachine\Abilities\Engine;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;

defined( 'ABSPATH' ) || exit;

class PipelineBatchScheduler {

	/**
	 * Number of child jobs to schedule per chunk.
	 *
	 * Between chunks, other Action Scheduler actions can run,
	 * preventing queue flooding.
	 */
	const CHUNK_SIZE = 10;

	/**
	 * Delay in seconds between scheduling chunks.
	 */
	const CHUNK_DELAY = 30;

	/**
	 * Action Scheduler hook for processing batch chunks.
	 */
	const BATCH_HOOK = 'datamachine_pipeline_batch_chunk';

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
	 * Converts the parent job into a batch parent and creates child jobs
	 * for each DataPacket. Each child continues through the remaining
	 * pipeline steps independently.
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
		$total       = count( $dataPackets );
		$pipeline_id = $engine_snapshot['job']['pipeline_id'] ?? 0;
		$flow_id     = $engine_snapshot['job']['flow_id'] ?? 0;
		$flow_name   = $engine_snapshot['flow']['name'] ?? '';

		// Store batch metadata on the parent job.
		datamachine_merge_engine_data( $parent_job_id, array(
			'batch'              => true,
			'batch_total'        => $total,
			'batch_scheduled'    => 0,
			'batch_chunk_size'   => self::CHUNK_SIZE,
			'next_flow_step_id'  => $next_flow_step_id,
			'started_at'         => current_time( 'mysql' ),
		) );

		do_action(
			'datamachine_log',
			'info',
			sprintf( 'Pipeline batch: fanning out %d items for flow "%s"', $total, $flow_name ),
			array(
				'parent_job_id'     => $parent_job_id,
				'pipeline_id'       => $pipeline_id,
				'flow_id'           => $flow_id,
				'total'             => $total,
				'next_flow_step_id' => $next_flow_step_id,
			)
		);

		// Store all DataPackets in a transient for chunked scheduling.
		$transient_key = 'dm_pipeline_batch_' . $parent_job_id;
		$batch_data    = array(
			'parent_job_id'     => $parent_job_id,
			'next_flow_step_id' => $next_flow_step_id,
			'engine_snapshot'   => $engine_snapshot,
			'data_packets'      => $dataPackets,
			'total'             => $total,
			'offset'            => 0,
		);

		set_transient( $transient_key, $batch_data, 4 * HOUR_IN_SECONDS );

		// Schedule first chunk immediately.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time(),
				self::BATCH_HOOK,
				array( 'parent_job_id' => $parent_job_id ),
				'data-machine'
			);
		}

		return array(
			'parent_job_id' => $parent_job_id,
			'total'         => $total,
			'chunk_size'    => self::CHUNK_SIZE,
		);
	}

	/**
	 * Process a chunk of the batch.
	 *
	 * Called by Action Scheduler. Creates child jobs for the current chunk,
	 * then schedules the next chunk with a delay.
	 *
	 * @param int $parent_job_id The parent job ID.
	 */
	public function processChunk( int $parent_job_id ): void {
		$transient_key = 'dm_pipeline_batch_' . $parent_job_id;
		$batch_data    = get_transient( $transient_key );

		if ( ! $batch_data ) {
			do_action(
				'datamachine_log',
				'warning',
				'Pipeline batch: transient expired or missing',
				array( 'parent_job_id' => $parent_job_id )
			);
			return;
		}

		// Check for cancellation.
		$parent_engine = datamachine_get_engine_data( $parent_job_id );
		if ( ! empty( $parent_engine['cancelled'] ) ) {
			delete_transient( $transient_key );
			$this->db_jobs->complete_job( $parent_job_id, 'cancelled' );
			return;
		}

		$next_flow_step_id = $batch_data['next_flow_step_id'];
		$engine_snapshot   = $batch_data['engine_snapshot'];
		$all_packets       = $batch_data['data_packets'];
		$total             = $batch_data['total'];
		$offset            = $batch_data['offset'];
		$chunk             = array_slice( $all_packets, $offset, self::CHUNK_SIZE );

		$scheduled = 0;

		foreach ( $chunk as $single_packet ) {
			$child_job_id = $this->createChildJob(
				$parent_job_id,
				$next_flow_step_id,
				$single_packet,
				$engine_snapshot
			);

			if ( $child_job_id ) {
				++$scheduled;
			}
		}

		$new_offset = $offset + self::CHUNK_SIZE;

		// Update parent progress.
		$parent_engine                    = datamachine_get_engine_data( $parent_job_id );
		$parent_engine['batch_scheduled'] = ( $parent_engine['batch_scheduled'] ?? 0 ) + $scheduled;
		$parent_engine['batch_offset']    = min( $new_offset, $total );
		datamachine_set_engine_data( $parent_job_id, $parent_engine );

		do_action(
			'datamachine_log',
			'debug',
			sprintf( 'Pipeline batch chunk: scheduled %d/%d (offset %d)', $scheduled, $total, $new_offset ),
			array(
				'parent_job_id' => $parent_job_id,
				'scheduled'     => $scheduled,
				'offset'        => $new_offset,
				'total'         => $total,
			)
		);

		if ( $new_offset < $total ) {
			// More items — schedule next chunk with delay.
			$batch_data['offset'] = $new_offset;
			set_transient( $transient_key, $batch_data, 4 * HOUR_IN_SECONDS );

			as_schedule_single_action(
				time() + self::CHUNK_DELAY,
				self::BATCH_HOOK,
				array( 'parent_job_id' => $parent_job_id ),
				'data-machine'
			);
		} else {
			// All items scheduled — clean up transient.
			delete_transient( $transient_key );

			do_action(
				'datamachine_log',
				'info',
				sprintf( 'Pipeline batch: all %d items scheduled', $total ),
				array( 'parent_job_id' => $parent_job_id )
			);
		}
	}

	/**
	 * Create a single child job for one DataPacket.
	 *
	 * Clones the parent's engine_data, stores the single DataPacket to
	 * the filesystem, and schedules the next step via the normal engine path.
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
		$pipeline_id = $engine_snapshot['job']['pipeline_id'] ?? 0;
		$flow_id     = $engine_snapshot['job']['flow_id'] ?? 0;
		$flow_name   = $engine_snapshot['flow']['name'] ?? '';
		$item_title  = $single_packet['data']['title'] ?? 'Untitled';

		// Create child job linked to parent.
		$child_job_id = $this->db_jobs->create_job( array(
			'pipeline_id'   => $pipeline_id,
			'flow_id'       => $flow_id,
			'source'        => 'pipeline',
			'label'         => $item_title,
			'parent_job_id' => $parent_job_id,
		) );

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

		// Clone engine_data to child, updating the job context.
		$child_engine            = $engine_snapshot;
		$child_engine['job']     = array(
			'job_id'        => $child_job_id,
			'flow_id'       => $flow_id,
			'pipeline_id'   => $pipeline_id,
			'created_at'    => current_time( 'mysql', true ),
			'parent_job_id' => $parent_job_id,
		);

		datamachine_set_engine_data( $child_job_id, $child_engine );
		$this->db_jobs->start_job( $child_job_id );

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
	 * Handle child job completion.
	 *
	 * Called via datamachine_job_complete hook. Checks if all children
	 * of the parent are finished and updates the parent accordingly.
	 *
	 * @param int    $job_id Job ID that just completed.
	 * @param string $status The completion status.
	 */
	public static function onChildComplete( int $job_id, string $status ): void {
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

		// Count child statuses.
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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
}
