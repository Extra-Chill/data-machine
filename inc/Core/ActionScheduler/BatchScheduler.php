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
use DataMachine\Core\Database\BatchItems\BatchItems;
use DataMachine\Core\DataPacketStore;
use DataMachine\Core\EngineData;
use DataMachine\Core\JobStatus;
use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

class BatchScheduler {
	public const STORAGE_VERSION = 2;

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
	 * Stores the worklist in BatchItems, records lightweight metadata on the
	 * parent, and schedules the first chunk through Action Scheduler.
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
	 *       checksum     => '...',
	 *       extra        => array(...),     // lightweight consumer metadata
	 *   ),
	 *
	 * @param int    $parent_job_id The parent job ID (becomes the batch parent).
	 * @param string $hook          Action Scheduler hook name to fire for each chunk.
	 * @param array  $items         Raw work items. Shape is consumer-defined.
	 * @param array  $extra         Lightweight per-batch state cloned to chunks.
	 * @param string $context       Consumer context, used for chunk-size/delay filter dispatch.
	 * @param string $completion_strategy Declared parent-completion strategy.
	 * @return array{parent_job_id:int,total:int,chunk_size:int,action_id:int,scheduled:bool,adopted:bool} Batch summary.
	 */
	public static function start(
		int $parent_job_id,
		string $hook,
		array $items,
		array $extra = array(),
		string $context = '',
		string $completion_strategy = ''
	): array {
		$cleanup_contexts = array_map(
			static fn( mixed $item ): array => (array) apply_filters( 'datamachine_batch_item_cleanup_context', array(), $item ),
			$items
		);
		$items            = array_map( array( DataPacketStore::class, 'reference_packet_collections_in_value' ), $items );
		$total            = count( $items );
		$chunk_size       = self::chunkSize( $context );
		if ( 0 === $total ) {
			return self::startResult( $parent_job_id, 0, $chunk_size );
		}
		$checksums = array();
		foreach ( $items as $item ) {
			$encoded = wp_json_encode( $item );
			if ( false === $encoded ) {
				self::discardStartItems( $items, $cleanup_contexts, $parent_job_id, $context );
				return self::startResult( $parent_job_id, $total, $chunk_size );
			}
			$checksums[] = hash( 'sha256', $encoded );
		}
		$cleanup_checksums = array();
		foreach ( $cleanup_contexts as $cleanup_context ) {
			$encoded = wp_json_encode( $cleanup_context );
			if ( false === $encoded ) {
				self::discardStartItems( $items, $cleanup_contexts, $parent_job_id, $context );
				return self::startResult( $parent_job_id, $total, $chunk_size );
			}
			$cleanup_checksums[] = hash( 'sha256', $encoded );
		}
		$contract = wp_json_encode(
			array(
				'items'               => $checksums,
				'cleanup'             => $cleanup_checksums,
				'hook'                => $hook,
				'extra'               => $extra,
				'context'             => $context,
				'completion_strategy' => $completion_strategy,
				'chunk_size'          => $chunk_size,
			)
		);
		if ( false === $contract ) {
			self::discardStartItems( $items, $cleanup_contexts, $parent_job_id, $context );
			return self::startResult( $parent_job_id, $total, $chunk_size );
		}
		$worklist_checksum = hash( 'sha256', $contract );
		$repository        = new BatchItems();
		$insert            = $repository->insert_batch( $parent_job_id, $items, $cleanup_contexts );
		if ( empty( $insert['success'] ) ) {
			if ( empty( $insert['existing'] ) ) {
				self::discardStartItems( $items, $cleanup_contexts, $parent_job_id, $context );
			}
			return self::startResult( $parent_job_id, $total, $chunk_size );
		}

		$mutation = EngineData::mutate(
			$parent_job_id,
			static function ( array $current ) use ( $total, $chunk_size, $context, $completion_strategy, $extra, $hook, $worklist_checksum ): ?array {
				if ( self::STORAGE_VERSION === (int) ( $current['batch_storage_version'] ?? 0 ) && is_array( $current['batch_state'] ?? null ) ) {
					$current_checksum = (string) ( $current['batch_state']['checksum'] ?? '' );
					if ( ! hash_equals( $worklist_checksum, $current_checksum ) ) {
						return null;
					}
					unset( $current['batch_schedule_failed'] );
					return (array) apply_filters( 'datamachine_batch_engine_adoption_state', $current, $context, $worklist_checksum );
				}
				$next = array_merge(
					$current,
					array(
						'batch'                     => true,
						'batch_storage_version'     => self::STORAGE_VERSION,
						'batch_total'               => $total,
						'batch_scheduled'           => 0,
						'batch_chunk_size'          => $chunk_size,
						'batch_context'             => $context,
						'batch_hook'                => $hook,
						'batch_completion_strategy' => $completion_strategy,
						'started_at'                => current_time( 'mysql' ),
						'batch_state'               => array(
							'offset'   => 0,
							'total'    => $total,
							'checksum' => $worklist_checksum,
							'extra'    => $extra,
							'hook'     => $hook,
						),
					)
				);
				return (array) apply_filters( 'datamachine_batch_engine_adoption_state', $next, $context, $worklist_checksum );
			},
			'batch_start_v2'
		);
		if ( empty( $mutation['success'] ) ) {
			$latest            = EngineData::retrieve( $parent_job_id );
			$adopted_elsewhere = self::STORAGE_VERSION === (int) ( $latest['batch_storage_version'] ?? 0 )
				&& hash_equals( $worklist_checksum, (string) ( $latest['batch_state']['checksum'] ?? '' ) );
			if ( ! $adopted_elsewhere && ! empty( $insert['created'] ) ) {
				if ( self::discardOwnedWorklist( $repository, $parent_job_id, (string) $insert['ownership_token'], $context ) ) {
					$repository->delete_owned_batch( $parent_job_id, (string) $insert['ownership_token'] );
				}
			}
			return self::startResult( $parent_job_id, $total, $chunk_size );
		}
		if ( ! empty( $insert['created'] ) ) {
			\DataMachine\Core\RunMetrics::start(
				$parent_job_id,
				array(
					'batch_context'             => $context,
					'batch_total'               => $total,
					'batch_completion_strategy' => $completion_strategy,
				)
			);
		}

		$action_id = self::ensureInitialChunkScheduled( $hook, $parent_job_id );

		if ( ! $action_id ) {
			$ownership_restored = false;
			$rollback           = EngineData::mutate(
				$parent_job_id,
				static function ( array $current ) use ( $context, $worklist_checksum, &$ownership_restored ): array {
					$had_transfer       = is_array( $current['packet_fanout_transfer'] ?? null );
					$current            = (array) apply_filters( 'datamachine_batch_engine_adoption_rollback_state', $current, $context, $worklist_checksum );
					$ownership_restored = $had_transfer && ! isset( $current['packet_fanout_transfer'] );
					unset( $current['batch_state'] );
					$current['batch_schedule_failed'] = true;
					return $current;
				},
				'batch_initial_schedule_failure'
			);
			if ( ! empty( $rollback['success'] ) && ! empty( $insert['created'] ) && $ownership_restored ) {
				$repository->delete_owned_batch( $parent_job_id, (string) $insert['ownership_token'] );
			} elseif ( ! empty( $rollback['success'] ) && ! empty( $insert['created'] ) ) {
				if ( self::discardOwnedWorklist( $repository, $parent_job_id, (string) $insert['ownership_token'], $context ) ) {
					$repository->delete_owned_batch( $parent_job_id, (string) $insert['ownership_token'] );
				}
			}
		}

		return self::startResult( $parent_job_id, $total, $chunk_size, (int) $action_id, null, (bool) $action_id );
	}

	/** Find or idempotently create the initial chunk action. */
	private static function ensureInitialChunkScheduled( string $hook, int $parent_job_id ): int {
		$args     = self::v2ChunkArgs( $parent_job_id, 0 );
		$existing = self::pendingChunkActionId( $hook, $args );
		if ( $existing > 0 ) {
			return $existing;
		}
		try {
			$action_id = (int) as_schedule_single_action( time(), $hook, $args, GroupRegistrar::GROUP );
			if ( $action_id > 0 ) {
				return $action_id;
			}
		} catch ( \Throwable $exception ) {
			do_action(
				'datamachine_log',
				'error',
				'Batch scheduler failed to schedule initial chunk',
				array(
					'parent_job_id' => $parent_job_id,
					'exception'     => $exception->getMessage(),
				)
			);
		}
		return self::pendingChunkActionId( $hook, $args );
	}

	/** Build the stable public start result. */
	private static function startResult( int $parent_job_id, int $total, int $chunk_size, int $action_id = 0, ?bool $scheduled = null, bool $adopted = false ): array {
		return array(
			'parent_job_id' => $parent_job_id,
			'total'         => $total,
			'chunk_size'    => $chunk_size,
			'action_id'     => $action_id,
			'scheduled'     => null === $scheduled ? (bool) $action_id : $scheduled,
			'adopted'       => $adopted,
		);
	}

	/** Release item-owned resources when no durable worklist adopted them. */
	private static function discardStartItems( array $items, array $cleanup_contexts, int $parent_job_id, string $context ): void {
		if ( $items ) {
			do_action( 'datamachine_batch_items_discarded', $items, $parent_job_id, $context, $cleanup_contexts );
		}
	}

	/**
	 * Process one chunk of a batch.
	 *
	 * Delegates per-item child creation to the supplied callback. Handles
	 * cancellation, offset bookkeeping, and chunk-rescheduling uniformly.
	 *
	 * V2 callbacks receive `(item, extra, parent_job_id, item_index,
	 * payload_checksum)`; legacy callbacks retain the original three arguments.
	 * The callback returns the
	 * created child id (or any truthy value) on success, falsy on failure.
	 * Falsy returns count toward `batch_scheduled` only when truthy.
	 *
	 * Returns false when the batch state is missing (caller should treat
	 * that as a fatal protocol error and complete the parent as failed).
	 *
	 * @param int      $parent_job_id Parent job ID.
	 * @param callable $createItem      fn(array $item, array $extra, int $parent_job_id): mixed
	 * @param int|null $expected_offset Offset key carried by the scheduler action.
	 * @return array{
	 *     scheduled:int,
	 *     offset:int,
	 *     total:int,
	 *     more:bool,
	 *     cancelled:bool,
	 *     missing:bool,
	 *     duplicate:bool,
	 *     schedule_failed?:bool
	 *     item_failed?:bool
	 * } Chunk result. `missing` is true only when the batch_state key
	 *   has been lost; consumer must fail the parent in that case.
	 *   `item_failed` is true when an item exhausted its attempt budget.
	 */
	public static function processChunk( int $parent_job_id, callable $createItem, ?int $expected_offset = null ): array {
		// A scheduled chunk starts in a separate request. Read through the jobs
		// table so stale persistent-cache state cannot hide a committed worklist.
		$parent_job    = ( new Jobs() )->get_job( $parent_job_id );
		$parent_engine = is_array( $parent_job['engine_data'] ?? null ) ? $parent_job['engine_data'] : array();
		if ( self::STORAGE_VERSION === (int) ( $parent_engine['batch_storage_version'] ?? 0 ) ) {
			return self::processV2Chunk( $parent_job_id, $createItem, $expected_offset, $parent_engine );
		}

		$result = self::processLegacyChunk( $parent_job_id, $createItem, $expected_offset, $parent_engine );
		if ( ! isset( $result['item_failed'] ) ) {
			$result['item_failed'] = false;
		}
		return $result;
	}

	/** Process a durable v2 worklist chunk. */
	private static function processV2Chunk( int $parent_job_id, callable $createItem, ?int $expected_offset, array $parent_engine ): array {
		$state = is_array( $parent_engine['batch_state'] ?? null ) ? $parent_engine['batch_state'] : null;
		$total = (int) ( $parent_engine['batch_total'] ?? 0 );
		if ( is_array( $state ) && ! empty( $state['worklist_complete'] ) ) {
			return self::chunkResult(
				0,
				(int) ( $parent_engine['batch_offset'] ?? $total ),
				$total,
				false,
				! empty( $parent_engine['cancelled'] ),
				false,
				false,
				! empty( $parent_engine['batch_schedule_failed'] ),
				! empty( $parent_engine['batch_item_failed'] )
			);
		}
		if ( null === $state ) {
			$cancelled = ! empty( $parent_engine['cancelled'] );
			if ( $cancelled ) {
				$repository = new BatchItems();
				if ( ! self::discardV2Outstanding( $repository, $parent_job_id, (string) ( $parent_engine['batch_context'] ?? '' ) ) ) {
					self::scheduleFinalizeRetry( $parent_engine, $parent_job_id );
					return self::chunkResult( 0, (int) ( $parent_engine['batch_offset'] ?? $total ), $total, true, true );
				}
				$completed = $repository->count_completed( $parent_job_id );
				if ( ! self::finishV2State( $parent_job_id, $completed, (int) ( $parent_engine['batch_offset'] ?? 0 ), true ) ) {
					throw new \RuntimeException( 'Cancelled batch cleanup could not persist parent state.' );
				}
			} else {
				self::scheduleFinalizeRetry( $parent_engine, $parent_job_id );
			}
			return self::chunkResult( 0, (int) ( $parent_engine['batch_offset'] ?? $total ), $total, false, $cancelled, ! $cancelled );
		}

		$context    = (string) ( $parent_engine['batch_context'] ?? '' );
		$repository = new BatchItems();
		if ( ! empty( $parent_engine['cancelled'] ) ) {
			if ( ! self::discardV2Outstanding( $repository, $parent_job_id, $context ) ) {
				self::scheduleFinalizeRetry( $parent_engine, $parent_job_id );
				return self::chunkResult( 0, (int) ( $state['offset'] ?? 0 ), $total, true, true );
			}
			$completed = $repository->count_completed( $parent_job_id );
			if ( ! self::finishV2State( $parent_job_id, $completed, (int) ( $state['offset'] ?? 0 ), true ) ) {
				throw new \RuntimeException( 'Cancelled batch cleanup could not persist parent state.' );
			}
			return self::chunkResult( 0, (int) ( $state['offset'] ?? 0 ), $total, false, true );
		}

		$offset     = null === $expected_offset ? (int) ( $state['offset'] ?? 0 ) : $expected_offset;
		$chunk_size = max( 1, (int) ( $parent_engine['batch_chunk_size'] ?? self::chunkSize( $context ) ) );
		$lease      = (int) apply_filters( 'datamachine_batch_item_lease_seconds', BatchItems::DEFAULT_LEASE_SECONDS, $context );
		$rows       = $repository->claim_chunk(
			$parent_job_id,
			$offset,
			$chunk_size,
			$lease,
			static fn(): bool => self::scheduleChunk( (string) $state['hook'], $parent_job_id, $offset, time() + max( 1, $lease ) )
		);
		$item_failed = ! empty( $parent_engine['batch_item_failed'] ) || $repository->count_failed( $parent_job_id ) > 0;
		if ( ! $rows ) {
			$outstanding = $repository->first_outstanding_index( $parent_job_id );
			if ( null === $outstanding ) {
				$completed = $repository->count_completed( $parent_job_id );
				if ( ! self::finishV2State( $parent_job_id, $completed, $total, true, false, $item_failed ) ) {
					throw new \RuntimeException( 'Terminal batch progress could not persist.' );
				}
				return self::chunkResult( 0, $total, $total, false, false, false, false, false, $item_failed );
			}
			return self::chunkResult( 0, $outstanding, $total, true, false, false, true, false, $item_failed );
		}

		$extra     = is_array( $state['extra'] ?? null ) ? $state['extra'] : array();
		$scheduled = 0;
		$cancelled = false;
		$max       = BatchItems::maxAttempts( $context );
		foreach ( $rows as $row ) {
			$latest = EngineData::retrieve( $parent_job_id );
			if ( ! empty( $latest['cancelled'] ) ) {
				$cancelled = true;
				if ( $repository->discard_cancel_pending( $parent_job_id, (int) $row['item_index'], (string) $row['lease_token'] ) ) {
					do_action( 'datamachine_batch_items_discarded', array( $row['payload'] ), $parent_job_id, $context, array( $row['cleanup_context'] ) );
				}
				continue;
			}

			$attempts = (int) ( $row['attempts'] ?? 0 );
			if ( $attempts > $max ) {
				if ( $repository->fail_claim( $parent_job_id, (int) $row['item_index'], (string) $row['lease_token'] ) ) {
					self::notifyDiscardedRows( array( $row ), $parent_job_id, $context );
					$item_failed = true;
				}
				continue;
			}

			$item = $row['payload'];
			if ( empty( $row['payload_valid'] ) ) {
				if ( $repository->discard_claim( $parent_job_id, (int) $row['item_index'], (string) $row['lease_token'] ) ) {
					self::notifyDiscardedRows( array( $row ), $parent_job_id, $context );
					do_action(
						'datamachine_log',
						'error',
						'Batch item payload is corrupt',
						array(
							'parent_job_id' => $parent_job_id,
							'item_index'    => (int) $row['item_index'],
						)
					);
				}
				continue;
			}
			$hydration = DataPacketStore::hydrate_packet_collections_with_status( $item );
			if ( empty( $hydration['success'] ) ) {
				if ( $repository->discard_claim( $parent_job_id, (int) $row['item_index'], (string) $row['lease_token'] ) ) {
					do_action( 'datamachine_batch_items_discarded', array( $hydration['value'] ), $parent_job_id, $context, array( $row['cleanup_context'] ) );
				}
				continue;
			}

			$result        = $createItem( $hydration['value'], $extra, $parent_job_id, (int) $row['item_index'], (string) $row['payload_checksum'] );
			$result_id     = is_scalar( $result ) ? $result : null;
			$item_finished = $result && $repository->complete( $parent_job_id, (int) $row['item_index'], (string) $row['lease_token'], $result_id );
			if ( $result && ! $item_finished && ! empty( EngineData::retrieve( $parent_job_id )['cancelled'] ) ) {
				$item_finished = $repository->complete_cancel_pending( $parent_job_id, (int) $row['item_index'], (string) $row['lease_token'], $result_id );
			}
			if ( $item_finished ) {
				++$scheduled;
			} elseif ( ! $result ) {
				if ( $attempts >= $max ) {
					if ( $repository->fail_claim( $parent_job_id, (int) $row['item_index'], (string) $row['lease_token'] ) ) {
						self::notifyDiscardedRows( array( $row ), $parent_job_id, $context );
						$item_failed = true;
					}
				} else {
					$repository->release( $parent_job_id, (int) $row['item_index'], (string) $row['lease_token'] );
				}
			}
			if ( ! empty( EngineData::retrieve( $parent_job_id )['cancelled'] ) ) {
				$cancelled = true;
			}
		}
		if ( $cancelled ) {
			$discarded = self::discardV2Outstanding( $repository, $parent_job_id, $context );
			$completed = $repository->count_completed( $parent_job_id );
			if ( ! $discarded || ! self::finishV2State( $parent_job_id, $completed, $offset, true ) ) {
				throw new \RuntimeException( 'Cancelled in-flight batch could not finish cleanup.' );
			}
			return self::chunkResult( $scheduled, $offset, $total, false, true );
		}

		$next_offset = $repository->first_outstanding_index( $parent_job_id );
		$more        = null !== $next_offset;
		$failed      = false;
		$item_failed = $item_failed || $repository->count_failed( $parent_job_id ) > 0;
		if ( $more && ! self::scheduleChunk( (string) $state['hook'], $parent_job_id, $next_offset, time() + self::chunkDelay( $context ) ) ) {
			if ( ! self::discardV2Outstanding( $repository, $parent_job_id, $context ) ) {
				return self::chunkResult( $scheduled, $offset, $total, true, false, false, true, false, $item_failed );
			}
			$more   = false;
			$failed = true;
		}

		$new_offset = $more ? (int) $next_offset : $total;
		$completed  = $repository->count_completed( $parent_job_id );
		$persisted  = self::finishV2State( $parent_job_id, $completed, $new_offset, ! $more, $failed, $item_failed );
		if ( ! $more && ! $persisted ) {
			throw new \RuntimeException( 'Terminal batch progress could not persist.' );
		}
		return self::chunkResult( $scheduled, $new_offset, $total, $more, false, false, false, $failed, $item_failed );
	}

	/** Build a stable chunk result for both consumers. */
	private static function chunkResult( int $scheduled, int $offset, int $total, bool $more, bool $cancelled = false, bool $missing = false, bool $duplicate = false, bool $schedule_failed = false, bool $item_failed = false ): array {
		return array(
			'scheduled'       => $scheduled,
			'offset'          => $offset,
			'total'           => $total,
			'more'            => $more,
			'cancelled'       => $cancelled,
			'missing'         => $missing,
			'duplicate'       => $duplicate,
			'schedule_failed' => $schedule_failed,
			'item_failed'     => $item_failed,
		);
	}

	/**
	 * Schedule one exact v2 chunk, adopting a pending Action Scheduler row first.
	 *
	 * Queries pending actions by exact hook, JSON-stable named args, and the
	 * Data Machine group. Integer and string argument encodings are both tried
	 * because Action Scheduler persists args as JSON.
	 */
	public static function scheduleChunk( string $hook, int $parent_job_id, int $offset, int $timestamp ): bool {
		if ( '' === $hook || $parent_job_id <= 0 || $offset < 0 ) {
			return false;
		}

		$args = self::v2ChunkArgs( $parent_job_id, $offset );
		if ( self::pendingChunkActionId( $hook, $args ) > 0 ) {
			return true;
		}

		try {
			$action_id = as_schedule_single_action(
				$timestamp,
				$hook,
				$args,
				GroupRegistrar::GROUP
			);
			if ( $action_id ) {
				return true;
			}
		} catch ( \Throwable $exception ) {
			do_action(
				'datamachine_log',
				'error',
				'Batch scheduler failed to schedule v2 chunk',
				array(
					'parent_job_id' => $parent_job_id,
					'offset'        => $offset,
					'exception'     => $exception->getMessage(),
				)
			);
		}

		if ( self::pendingChunkActionId( $hook, $args ) > 0 ) {
			return true;
		}

		$wp_args = array( $parent_job_id, $offset );
		if ( function_exists( 'wp_next_scheduled' ) && is_int( wp_next_scheduled( $hook, $wp_args ) ) ) {
			return true;
		}
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return false;
		}
		$result = wp_schedule_single_event( $timestamp, $hook, $wp_args, true );
		return ! is_wp_error( $result ) && true === $result;
	}

	/** @return array{parent_job_id:int,offset:int} */
	private static function v2ChunkArgs( int $parent_job_id, int $offset ): array {
		return array(
			'parent_job_id' => $parent_job_id,
			'offset'        => $offset,
		);
	}

	/** Find one pending action for the exact v2 chunk identity. */
	private static function pendingChunkActionId( string $hook, array $args ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		foreach ( self::v2ChunkArgVariants( $args ) as $candidate ) {
			$action_ids = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'args'     => $candidate,
					'group'    => GroupRegistrar::GROUP,
					'status'   => 'pending',
					'per_page' => 1,
				),
				'ids'
			);
			$action_id = is_array( $action_ids ) ? reset( $action_ids ) : 0;
			if ( is_numeric( $action_id ) && (int) $action_id > 0 ) {
				return (int) $action_id;
			}
		}

		return 0;
	}

	/**
	 * Exact Action Scheduler JSON encodings for one chunk identity.
	 *
	 * @return array<int,array{parent_job_id:int|string,offset:int|string}>
	 */
	private static function v2ChunkArgVariants( array $args ): array {
		$parent_job_id = (int) ( $args['parent_job_id'] ?? 0 );
		$offset        = (int) ( $args['offset'] ?? 0 );
		return array(
			array(
				'parent_job_id' => $parent_job_id,
				'offset'        => $offset,
			),
			array(
				'parent_job_id' => (string) $parent_job_id,
				'offset'        => (string) $offset,
			),
		);
	}

	private static function discardV2Outstanding( BatchItems $repository, int $parent_job_id, string $context ): bool {
		do {
			$result = $repository->discard_outstanding( $parent_job_id );
			if ( empty( $result['success'] ) ) {
				return false;
			}
			self::notifyDiscardedRows( $result['rows'], $parent_job_id, $context );
		} while ( ! empty( $result['remaining'] ) );
		return true;
	}

	/** Fence cancellation and release cleanup only for work not in a callback. */
	private static function requestV2Cancellation( BatchItems $repository, int $parent_job_id, string $context ): bool {
		do {
			$result = $repository->request_cancellation( $parent_job_id );
			if ( empty( $result['success'] ) ) {
				return false;
			}
			self::notifyDiscardedRows( $result['rows'], $parent_job_id, $context );
		} while ( ! empty( $result['remaining'] ) );
		return true;
	}

	private static function discardOwnedWorklist( BatchItems $repository, int $parent_job_id, string $token, string $context ): bool {
		do {
			$result = $repository->discard_owned( $parent_job_id, $token );
			if ( empty( $result['success'] ) ) {
				return false;
			}
			self::notifyDiscardedRows( $result['rows'], $parent_job_id, $context );
		} while ( ! empty( $result['remaining'] ) );
		return true;
	}

	private static function notifyDiscardedRows( array $rows, int $parent_job_id, string $context ): void {
		$rows = array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => BatchItems::STATE_CANCEL_PENDING !== (string) ( $row['state'] ?? '' )
			)
		);
		if ( empty( $rows ) ) {
			return;
		}
		do_action(
			'datamachine_batch_items_discarded',
			array_map( static fn( array $row ): array => is_array( $row['payload'] ?? null ) ? $row['payload'] : array(), $rows ),
			$parent_job_id,
			$context,
			array_map( static fn( array $row ): array => is_array( $row['cleanup_context'] ?? null ) ? $row['cleanup_context'] : array(), $rows )
		);
	}

	private static function finishV2State( int $parent_job_id, int $scheduled, int $offset, bool $finished, bool $failed = false, bool $item_failed = false ): bool {
		$result = EngineData::mutate(
			$parent_job_id,
			static function ( array $current ) use ( $scheduled, $offset, $finished, $failed, $item_failed ): array {
				$current['batch_scheduled'] = $scheduled;
				$current['batch_offset']    = $offset;
				if ( $finished ) {
					if ( isset( $current['batch_state'] ) ) {
						$current['batch_state']['offset']            = $offset;
						$current['batch_state']['worklist_complete'] = true;
					}
				} elseif ( isset( $current['batch_state'] ) ) {
					$current['batch_state']['offset'] = $offset;
				}
				if ( $failed ) {
					$current['batch_schedule_failed'] = true;
				}
				if ( $item_failed ) {
					$current['batch_item_failed'] = true;
				}
				return $current;
			},
			'batch_v2_progress'
		);
		return ! empty( $result['success'] );
	}

	/**
	 * Remove a terminal v2 worklist after its consumer durably terminalizes.
	 *
	 * Rows are deleted first. If the lightweight parent mutation then loses a
	 * race, the recovery action sees worklist_complete and safely retries this
	 * finalization without re-running an item.
	 */
	public static function finalize( int $parent_job_id ): bool {
		$current = EngineData::retrieve( $parent_job_id );
		$job     = ( new Jobs() )->get_job_metadata( $parent_job_id );
		if ( ! is_array( $job ) ) {
			global $wpdb;
			if ( '' !== (string) $wpdb->last_error ) {
				self::scheduleFinalizeRetry( $current, $parent_job_id );
				return false;
			}
		}
		if ( is_array( $job ) && ! JobStatus::isStatusFinal( (string) ( $job['status'] ?? '' ) ) ) {
			return false;
		}
		$repository = new BatchItems();
		$is_v2      = self::STORAGE_VERSION === (int) ( $current['batch_storage_version'] ?? 0 );
		$state      = is_array( $current['batch_state'] ?? null ) ? $current['batch_state'] : null;
		if ( ! $is_v2 || ! is_array( $state ) || empty( $state['worklist_complete'] ) ) {
			if ( ! self::discardV2Outstanding( $repository, $parent_job_id, (string) ( $current['batch_context'] ?? '' ) ) ) {
				self::scheduleFinalizeRetry( $current, $parent_job_id );
				return false;
			}
		}

		if ( ! $repository->delete_batch( $parent_job_id ) ) {
			self::scheduleFinalizeRetry( $current, $parent_job_id );
			return false;
		}
		if ( ! $is_v2 ) {
			return true;
		}
		$result    = ( new Jobs() )->remove_engine_data_key( $parent_job_id, 'batch_state' );
		$finalized = ! empty( $result['updated'] );
		if ( ! $finalized && ! empty( $result['retryable'] ) ) {
			self::scheduleFinalizeRetry( $current, $parent_job_id );
		}
		return $finalized;
	}

	/** Schedule an idempotent consumer replay when terminal cleanup is not durable. */
	private static function scheduleFinalizeRetry( array $engine, int $parent_job_id ): bool {
		$state = is_array( $engine['batch_state'] ?? null ) ? $engine['batch_state'] : array();
		$hook  = (string) ( $state['hook'] ?? $engine['batch_hook'] ?? '' );
		if ( '' === $hook ) {
			return false;
		}
		return self::scheduleChunk( $hook, $parent_job_id, (int) ( $state['offset'] ?? $engine['batch_offset'] ?? 0 ), time() + 60 );
	}

	/** Existing engine_data worklist processor for persisted v1 batches. */
	private static function processLegacyChunk( int $parent_job_id, callable $createItem, ?int $expected_offset, array $parent_engine ): array {
		$batch_state = $parent_engine['batch_state'] ?? null;

		if ( ! is_array( $batch_state ) ) {
			if ( null !== $expected_offset && ! empty( $parent_engine['batch'] ) ) {
				$total  = (int) ( $parent_engine['batch_total'] ?? 0 );
				$offset = (int) ( $parent_engine['batch_offset'] ?? $total );

				return array(
					'scheduled' => 0,
					'offset'    => $offset,
					'total'     => $total,
					'more'      => false,
					'cancelled' => ! empty( $parent_engine['cancelled'] ),
					'missing'   => false,
					'duplicate' => true,
				);
			}

			return array(
				'scheduled' => 0,
				'offset'    => 0,
				'total'     => 0,
				'more'      => false,
				'cancelled' => false,
				'missing'   => true,
				'duplicate' => false,
			);
		}

		$context    = $parent_engine['batch_context'] ?? '';
		$chunk_size = self::chunkSize( $context );
		$delay      = self::chunkDelay( $context );

		// Cancellation without an in-flight owner may discard from the durable
		// offset. An in-flight owner is responsible for its own chunk boundary.
		if ( ! empty( $parent_engine['cancelled'] ) ) {
			$duplicate = is_array( $batch_state['in_flight'] ?? null );
			if ( ! $duplicate && ! isset( $batch_state['discarded_from'] ) ) {
				$discard_from      = (int) ( $batch_state['offset'] ?? 0 );
				$remaining         = array_slice( is_array( $batch_state['items'] ?? null ) ? $batch_state['items'] : array(), $discard_from );
				$remaining_cleanup = array_slice( is_array( $batch_state['cleanup_contexts'] ?? null ) ? $batch_state['cleanup_contexts'] : array(), $discard_from );
				if ( $remaining ) {
					do_action( 'datamachine_batch_items_discarded', $remaining, $parent_job_id, (string) $context, $remaining_cleanup );
				}
			}

			if ( ! $duplicate ) {
				EngineData::mutate(
					$parent_job_id,
					static function ( array $current ): array {
						if ( ! is_array( $current['batch_state']['in_flight'] ?? null ) ) {
							unset( $current['batch_state'] );
						}
						return $current;
					},
					'batch_cancelled_cleanup'
				);
			}

			return array(
				'scheduled' => 0,
				'offset'    => (int) ( $batch_state['offset'] ?? 0 ),
				'total'     => (int) ( $batch_state['total'] ?? 0 ),
				'more'      => false,
				'cancelled' => true,
				'missing'   => false,
				'duplicate' => $duplicate,
			);
		}

		if ( null !== $expected_offset ) {
			$claim_error = null;
			$claim       = EngineData::mutate(
				$parent_job_id,
				static function ( array $current ) use ( $expected_offset, $chunk_size, &$claim_error ): ?array {
					$current_state = $current['batch_state'] ?? null;
					if ( ! is_array( $current_state ) ) {
						$claim_error = 'missing';
						return null;
					}

					if ( ! empty( $current['cancelled'] ) ) {
						$claim_error = 'cancelled';
						return null;
					}

					$current_offset = (int) ( $current_state['offset'] ?? 0 );
					if ( $current_offset !== $expected_offset ) {
						$claim_error = 'stale_offset';
						return null;
					}

					$claims = is_array( $current_state['claims'] ?? null ) ? $current_state['claims'] : array();
					if ( isset( $claims[ (string) $expected_offset ] ) ) {
						$claim_error = 'already_claimed';
						return null;
					}

					$current_state['claims']                              = $claims;
					$current_state['claims'][ (string) $expected_offset ] = current_time( 'mysql' );
					$current_state['in_flight']                           = array(
						'offset' => $expected_offset,
						'end'    => min( $expected_offset + $chunk_size, (int) ( $current_state['total'] ?? 0 ) ),
					);
					$current['batch_state']                               = $current_state;

					return $current;
				},
				'batch_chunk_claim'
			);

			if ( empty( $claim['success'] ) ) {
				$latest       = is_array( $claim['snapshot'] ?? null ) ? $claim['snapshot'] : datamachine_get_engine_data( $parent_job_id );
				$latest_state = is_array( $latest['batch_state'] ?? null ) ? $latest['batch_state'] : array();

				return array(
					'scheduled' => 0,
					'offset'    => (int) ( $latest_state['offset'] ?? ( $latest['batch_offset'] ?? 0 ) ),
					'total'     => (int) ( $latest_state['total'] ?? ( $latest['batch_total'] ?? 0 ) ),
					'more'      => ! empty( $latest['batch_state'] ),
					'cancelled' => 'cancelled' === $claim_error || ! empty( $latest['cancelled'] ),
					'missing'   => 'missing' === $claim_error && empty( $latest['batch'] ),
					'duplicate' => in_array( $claim_error, array( 'already_claimed', 'stale_offset', 'missing' ), true ),
				);
			}

			$parent_engine = $claim['snapshot'];
			$batch_state   = is_array( $parent_engine['batch_state'] ?? null ) ? $parent_engine['batch_state'] : $batch_state;
		}

		$total            = (int) ( $batch_state['total'] ?? 0 );
		$offset           = (int) ( $batch_state['offset'] ?? 0 );
		$items            = is_array( $batch_state['items'] ?? null ) ? $batch_state['items'] : array();
		$cleanup_contexts = is_array( $batch_state['cleanup_contexts'] ?? null ) ? $batch_state['cleanup_contexts'] : array();
		$extra            = is_array( $batch_state['extra'] ?? null ) ? $batch_state['extra'] : array();
		$hook             = (string) ( $batch_state['hook'] ?? '' );

		$chunk           = array_slice( $items, $offset, $chunk_size );
		$scheduled       = 0;
		$processed       = 0;
		$cancelled       = false;
		$schedule_failed = false;

		foreach ( $chunk as $index => $item ) {
			$latest = EngineData::retrieve( $parent_job_id );
			if ( ! empty( $latest['cancelled'] ) ) {
				$cancelled         = true;
				$remaining         = array_slice( $chunk, $index );
				$remaining_cleanup = array_slice( $cleanup_contexts, $offset + $index, count( $remaining ) );
				if ( $remaining ) {
					do_action( 'datamachine_batch_items_discarded', $remaining, $parent_job_id, (string) $context, $remaining_cleanup );
				}
				break;
			}

			$cleanup_context = $cleanup_contexts[ $offset + $index ] ?? array();
			$hydration       = DataPacketStore::hydrate_packet_collections_with_status( $item );
			$item            = $hydration['value'];
			if ( empty( $hydration['success'] ) ) {
				do_action( 'datamachine_batch_items_discarded', array( $item ), $parent_job_id, (string) $context, array( $cleanup_context ) );
				++$processed;
				continue;
			}
			$result = $createItem( $item, $extra, $parent_job_id );
			if ( $result ) {
				++$scheduled;
			} else {
				do_action( 'datamachine_batch_items_discarded', array( $item ), $parent_job_id, (string) $context, array( $cleanup_context ) );
			}
			++$processed;
		}

		$new_offset = $cancelled ? $offset + $processed : $offset + count( $chunk );

		$more = ! $cancelled && $new_offset < $total;

		EngineData::mutate(
			$parent_job_id,
			static function ( array $current ) use ( $scheduled, $new_offset, $total, $more, $expected_offset, &$cancelled ): array {
				$current['batch_scheduled'] = ( $current['batch_scheduled'] ?? 0 ) + $scheduled;
				$current['batch_offset']    = min( $new_offset, $total );

				if ( ! empty( $current['cancelled'] ) ) {
					$cancelled = true;
					unset( $current['batch_state'] );
				} elseif ( $more ) {
					if ( is_array( $current['batch_state'] ?? null ) ) {
						$current['batch_state']['offset'] = $new_offset;
						unset( $current['batch_state']['in_flight'] );
						if ( null !== $expected_offset && isset( $current['batch_state']['claims'][ (string) $expected_offset ] ) ) {
							unset( $current['batch_state']['claims'][ (string) $expected_offset ] );
						}
					}
				} else {
					unset( $current['batch_state'] );
				}

				return $current;
			},
			'batch_chunk_advance'
		);
		if ( $cancelled ) {
			$more = false;
		}

		if ( $more ) {
			try {
				$action_id = as_schedule_single_action(
					time() + $delay,
					$hook,
					array(
						'parent_job_id' => $parent_job_id,
						'offset'        => $new_offset,
					),
					GroupRegistrar::GROUP
				);
			} catch ( \Throwable $exception ) {
				$action_id = 0;
				do_action(
					'datamachine_log',
					'error',
					'Batch scheduler failed to schedule next chunk',
					array(
						'parent_job_id' => $parent_job_id,
						'offset'        => $new_offset,
						'exception'     => $exception->getMessage(),
					)
				);
			}

			if ( ! $action_id ) {
				$remaining         = array_slice( $items, $new_offset );
				$remaining_cleanup = array_slice( $cleanup_contexts, $new_offset );
				if ( $remaining ) {
					do_action( 'datamachine_batch_items_discarded', $remaining, $parent_job_id, (string) $context, $remaining_cleanup );
				}
				EngineData::mutate(
					$parent_job_id,
					static function ( array $current ): array {
						unset( $current['batch_state'] );
						$current['batch_schedule_failed'] = true;
						return $current;
					},
					'batch_chunk_schedule_failure'
				);
				$more            = false;
				$schedule_failed = true;
			}
		}

		return array(
			'scheduled'       => $scheduled,
			'offset'          => min( $new_offset, $total ),
			'total'           => $total,
			'more'            => $more,
			'cancelled'       => $cancelled,
			'missing'         => false,
			'duplicate'       => false,
			'schedule_failed' => $schedule_failed,
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
		$current = EngineData::retrieve( $parent_job_id );
		if ( self::STORAGE_VERSION === (int) ( $current['batch_storage_version'] ?? 0 ) ) {
			if ( empty( $current['batch'] ) ) {
				return false;
			}
			$mutation = EngineData::mutate(
				$parent_job_id,
				static function ( array $engine ): ?array {
					if ( empty( $engine['batch'] ) ) {
						return null;
					}
					$engine['cancelled']    = true;
					$engine['cancelled_at'] = current_time( 'mysql' );
					return $engine;
				},
				'batch_v2_cancel'
			);
			if ( empty( $mutation['success'] ) ) {
				return false;
			}
			$repository = new BatchItems();
			$context    = (string) ( $current['batch_context'] ?? '' );
			if ( ! self::requestV2Cancellation( $repository, $parent_job_id, $context ) ) {
				return false;
			}
			if ( null === $repository->first_outstanding_index( $parent_job_id ) ) {
				$completed = $repository->count_completed( $parent_job_id );
				$offset    = (int) ( $current['batch_state']['offset'] ?? 0 );
				if ( ! self::finishV2State( $parent_job_id, $completed, $offset, true ) ) {
					return false;
				}
			}
			return true;
		}

		$remaining         = array();
		$remaining_cleanup = array();
		$context           = '';
		$mutation          = EngineData::mutate(
			$parent_job_id,
			static function ( array $current ) use ( &$remaining, &$remaining_cleanup, &$context ): ?array {
				$remaining         = array();
				$remaining_cleanup = array();
				if ( empty( $current['batch'] ) ) {
					return null;
				}

				$current['cancelled']    = true;
				$current['cancelled_at'] = current_time( 'mysql' );
				$context                 = (string) ( $current['batch_context'] ?? '' );
				$state                   = is_array( $current['batch_state'] ?? null ) ? $current['batch_state'] : array();
				if ( $state && ! isset( $state['discarded_from'] ) ) {
					$in_flight               = is_array( $state['in_flight'] ?? null ) ? $state['in_flight'] : array();
					$discard_from            = $in_flight
						? (int) ( $in_flight['end'] ?? $state['offset'] ?? 0 )
						: (int) ( $state['offset'] ?? 0 );
					$remaining               = array_slice( is_array( $state['items'] ?? null ) ? $state['items'] : array(), $discard_from );
					$remaining_cleanup       = array_slice( is_array( $state['cleanup_contexts'] ?? null ) ? $state['cleanup_contexts'] : array(), $discard_from );
					$state['discarded_from'] = $discard_from;
					$current['batch_state']  = $state;
				}

				return $current;
			},
			'batch_cancel'
		);

		if ( empty( $mutation['success'] ) ) {
			return false;
		}

		if ( $remaining ) {
			do_action( 'datamachine_batch_items_discarded', $remaining, $parent_job_id, $context, $remaining_cleanup );
		}

		return true;
	}
}
