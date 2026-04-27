<?php
/**
 * Batch Scheduler — shared chunked fan-out primitive.
 *
 * Owns the chunked-creation loop that both pipeline fan-out and system-task
 * fan-out previously implemented twice. Persists batch state on the parent
 * job's engine_data (Redis-survivable) and reads chunk_size / chunk_delay
 * from the queue_tuning settings group so operators can tune both layers
 * (producer + consumer) from one place.
 *
 * Two consumers wire onto this:
 *
 * - {@see \DataMachine\Abilities\Engine\PipelineBatchScheduler} — fans out N
 *   DataPackets into N child *pipeline jobs* that continue to the next
 *   pipeline step. Owns the `datamachine_pipeline_batch_chunk` hook.
 *
 * - {@see \DataMachine\Engine\Tasks\TaskScheduler::scheduleBatch} — fans out
 *   N task param sets into N standalone *task jobs* via TaskScheduler::schedule.
 *   Owns the `datamachine_task_process_batch` hook.
 *
 * Producer-side knobs vs consumer-side knobs:
 *
 *   chunk_size + chunk_delay   → how DM creates child jobs (this primitive)
 *   concurrent_batches +
 *     batch_size +
 *     time_limit               → how Action Scheduler drains them
 *
 * All five live in the queue_tuning settings array and surface in the
 * General → Queue Performance settings tab.
 *
 * @package DataMachine\Core\ActionScheduler
 * @since 0.82.0
 */

namespace DataMachine\Core\ActionScheduler;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

class BatchScheduler {

	/**
	 * Parent completes after all child jobs complete.
	 */
	public const COMPLETION_STRATEGY_CHILDREN_COMPLETE = 'children_complete';

	/**
	 * Parent completes after all chunks have scheduled their work.
	 */
	public const COMPLETION_STRATEGY_CHUNKS_SCHEDULED = 'chunks_scheduled';

	/**
	 * Default chunk size when settings are unavailable.
	 *
	 * Used as a last-resort fallback only. The live value is read from
	 * the queue_tuning settings group via {@see chunkSize()}.
	 */
	public const DEFAULT_CHUNK_SIZE = 10;

	/**
	 * Default chunk delay (seconds) when settings are unavailable.
	 *
	 * Used as a last-resort fallback only. The live value is read from
	 * the queue_tuning settings group via {@see chunkDelay()}.
	 */
	public const DEFAULT_CHUNK_DELAY = 30;

	/**
	 * Resolve the configured chunk size.
	 *
	 * Reads queue_tuning.chunk_size and runs it through the
	 * `datamachine_batch_chunk_size` filter so consumers can override
	 * per-context (e.g. a pipeline could request smaller chunks for a
	 * memory-heavy step).
	 *
	 * @param string $context Consumer context, e.g. 'pipeline' or 'task'.
	 * @return int Chunk size, clamped to >= 1.
	 */
	public static function chunkSize( string $context = '' ): int {
		$tuning = PluginSettings::get( 'queue_tuning', array() );
		$size   = isset( $tuning['chunk_size'] ) ? absint( $tuning['chunk_size'] ) : self::DEFAULT_CHUNK_SIZE;

		if ( $size < 1 ) {
			$size = self::DEFAULT_CHUNK_SIZE;
		}

		/**
		 * Filter the chunk size for batch fan-out.
		 *
		 * @param int    $size    The resolved chunk size.
		 * @param string $context Consumer context ('pipeline', 'task', or custom).
		 */
		return (int) apply_filters( 'datamachine_batch_chunk_size', $size, $context );
	}

	/**
	 * Resolve the configured chunk delay (seconds).
	 *
	 * @param string $context Consumer context, e.g. 'pipeline' or 'task'.
	 * @return int Delay in seconds, clamped to >= 0.
	 */
	public static function chunkDelay( string $context = '' ): int {
		$tuning = PluginSettings::get( 'queue_tuning', array() );
		$delay  = isset( $tuning['chunk_delay'] ) ? absint( $tuning['chunk_delay'] ) : self::DEFAULT_CHUNK_DELAY;

		/**
		 * Filter the chunk delay (seconds) for batch fan-out.
		 *
		 * @param int    $delay   The resolved delay in seconds.
		 * @param string $context Consumer context ('pipeline', 'task', or custom).
		 */
		return (int) apply_filters( 'datamachine_batch_chunk_delay', $delay, $context );
	}

	/**
	 * Initialize a batch on the parent job's engine_data.
	 *
	 * Stores the full work list under engine_data['batch_state'], records
	 * top-level batch metadata, and schedules the first chunk via the
	 * caller-supplied Action Scheduler hook.
	 *
	 * Storage shape on parent's engine_data:
	 *
	 *   batch              => true,
	 *   batch_total        => N,
	 *   batch_scheduled    => 0,
	 *   batch_chunk_size   => 10,
	 *   batch_context      => 'pipeline' | 'task' | ...,
	 *   batch_completion_strategy => 'children_complete' | 'chunks_scheduled' | ...,
	 *   started_at         => 'YYYY-MM-DD HH:MM:SS',
	 *   batch_state        => array(
	 *       offset       => 0,
	 *       total        => N,
	 *       items        => array(...),     // raw work items, consumer-defined shape
	 *       extra        => array(...),     // arbitrary per-batch payload (engine_snapshot, task_type, ...)
	 *   ),
	 *
	 * @param int    $parent_job_id The parent job ID (becomes the batch parent).
	 * @param string $hook          Action Scheduler hook name to fire for each chunk.
	 * @param array  $items         Raw work items. Shape is consumer-defined.
	 * @param array  $extra         Arbitrary per-batch state cloned to chunks (engine_snapshot, task_type, ...).
	 * @param string $context       Consumer context, used for chunk-size/delay filter dispatch.
	 * @param string $completion_strategy Declared parent-completion strategy.
	 * @return array{parent_job_id:int,total:int,chunk_size:int} Batch summary.
	 */
	public static function start(
		int $parent_job_id,
		string $hook,
		array $items,
		array $extra = array(),
		string $context = '',
		string $completion_strategy = ''
	): array {
		$total      = count( $items );
		$chunk_size = self::chunkSize( $context );

		datamachine_merge_engine_data(
			$parent_job_id,
			array(
				'batch'                     => true,
				'batch_total'               => $total,
				'batch_scheduled'           => 0,
				'batch_chunk_size'          => $chunk_size,
				'batch_context'             => $context,
				'batch_completion_strategy' => $completion_strategy,
				'started_at'                => current_time( 'mysql' ),
				'batch_state'               => array(
					'offset' => 0,
					'total'  => $total,
					'items'  => $items,
					'extra'  => $extra,
					'hook'   => $hook,
				),
			)
		);

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time(),
				$hook,
				array( 'parent_job_id' => $parent_job_id ),
				'data-machine'
			);
		}

		return array(
			'parent_job_id' => $parent_job_id,
			'total'         => $total,
			'chunk_size'    => $chunk_size,
		);
	}

	/**
	 * Process one chunk of a batch.
	 *
	 * Delegates per-item child creation to the supplied callback. Handles
	 * cancellation, offset bookkeeping, and chunk-rescheduling uniformly.
	 *
	 * The callback receives `(item, extra, parent_job_id)` and returns the
	 * created child id (or any truthy value) on success, falsy on failure.
	 * Falsy returns count toward `batch_scheduled` only when truthy.
	 *
	 * Returns false when the batch state is missing (caller should treat
	 * that as a fatal protocol error and complete the parent as failed).
	 *
	 * @param int      $parent_job_id Parent job ID.
	 * @param callable $createItem    fn(array $item, array $extra, int $parent_job_id): mixed
	 * @return array{
	 *     scheduled:int,
	 *     offset:int,
	 *     total:int,
	 *     more:bool,
	 *     cancelled:bool,
	 *     missing:bool
	 * } Chunk result. `missing` is true only when the batch_state key
	 *   has been lost; consumer must fail the parent in that case.
	 */
	public static function processChunk( int $parent_job_id, callable $createItem ): array {
		$parent_engine = datamachine_get_engine_data( $parent_job_id );
		$batch_state   = $parent_engine['batch_state'] ?? null;

		if ( ! is_array( $batch_state ) ) {
			return array(
				'scheduled' => 0,
				'offset'    => 0,
				'total'     => 0,
				'more'      => false,
				'cancelled' => false,
				'missing'   => true,
			);
		}

		// Cancellation flag set on the parent's engine_data short-circuits
		// any further child creation.
		if ( ! empty( $parent_engine['cancelled'] ) ) {
			unset( $parent_engine['batch_state'] );
			datamachine_set_engine_data( $parent_job_id, $parent_engine );

			return array(
				'scheduled' => 0,
				'offset'    => (int) ( $batch_state['offset'] ?? 0 ),
				'total'     => (int) ( $batch_state['total'] ?? 0 ),
				'more'      => false,
				'cancelled' => true,
				'missing'   => false,
			);
		}

		$context    = $parent_engine['batch_context'] ?? '';
		$chunk_size = self::chunkSize( $context );
		$delay      = self::chunkDelay( $context );

		$total  = (int) ( $batch_state['total'] ?? 0 );
		$offset = (int) ( $batch_state['offset'] ?? 0 );
		$items  = is_array( $batch_state['items'] ?? null ) ? $batch_state['items'] : array();
		$extra  = is_array( $batch_state['extra'] ?? null ) ? $batch_state['extra'] : array();
		$hook   = (string) ( $batch_state['hook'] ?? '' );

		$chunk     = array_slice( $items, $offset, $chunk_size );
		$scheduled = 0;

		foreach ( $chunk as $item ) {
			$result = $createItem( $item, $extra, $parent_job_id );
			if ( $result ) {
				++$scheduled;
			}
		}

		$new_offset = $offset + $chunk_size;

		// Re-read engine_data — caller's createItem callback may have
		// merged its own keys (child links, deferred state, etc.).
		$parent_engine                    = datamachine_get_engine_data( $parent_job_id );
		$parent_engine['batch_scheduled'] = ( $parent_engine['batch_scheduled'] ?? 0 ) + $scheduled;
		$parent_engine['batch_offset']    = min( $new_offset, $total );

		$more = $new_offset < $total;

		if ( $more ) {
			$parent_engine['batch_state']['offset'] = $new_offset;
			datamachine_set_engine_data( $parent_job_id, $parent_engine );

			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time() + $delay,
					$hook,
					array( 'parent_job_id' => $parent_job_id ),
					'data-machine'
				);
			}
		} else {
			// Last chunk — drop batch_state to free row space. Top-level
			// batch_total / batch_scheduled / batch_offset stay so the
			// parent's status aggregation has what it needs.
			unset( $parent_engine['batch_state'] );
			datamachine_set_engine_data( $parent_job_id, $parent_engine );
		}

		return array(
			'scheduled' => $scheduled,
			'offset'    => min( $new_offset, $total ),
			'total'     => $total,
			'more'      => $more,
			'cancelled' => false,
			'missing'   => false,
		);
	}

	/**
	 * Mark a batch parent as cancelled.
	 *
	 * The next processChunk() call sees the flag and stops creating
	 * children. The flag is observable to consumer code as well.
	 *
	 * @param int $parent_job_id Parent job ID.
	 * @return bool True when the parent was a batch parent and the flag was set.
	 */
	public static function cancel( int $parent_job_id ): bool {
		$parent_engine = datamachine_get_engine_data( $parent_job_id );

		if ( empty( $parent_engine['batch'] ) ) {
			return false;
		}

		$parent_engine['cancelled']    = true;
		$parent_engine['cancelled_at'] = current_time( 'mysql' );
		datamachine_set_engine_data( $parent_job_id, $parent_engine );

		return true;
	}
}
