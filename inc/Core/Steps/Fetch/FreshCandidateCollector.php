<?php
/**
 * Fresh candidate collector primitive for fetch handlers.
 *
 * Helps fetch handlers that paginate or scan an external source decide which
 * candidates are worth keeping at *selection time*. Handlers feed each
 * candidate identifier to the collector as they walk the source; the collector
 * filters out already-processed items and items currently claimed by another
 * in-flight job, honoring the `datamachine_should_reprocess_item` filter via
 * `ExecutionContext::isItemProcessed()`.
 *
 * Selection-time filtering only — the final centralized dedupe/claim/cap in
 * `FetchHandler::get_fetch_data()` remains authoritative. The collector lets a
 * handler stop scanning once it has enough fresh candidates, instead of
 * blindly returning the first N raw rows and losing visibility every time the
 * top-of-feed is already processed.
 *
 * Typical usage from a fetch handler:
 *
 *     $collector = new FreshCandidateCollector( $context, $max_items );
 *     foreach ( $this->paginate( $config ) as $candidate ) {
 *         $collector->offer( $candidate['id'], $candidate );
 *         if ( $collector->isFull() ) {
 *             break;
 *         }
 *     }
 *     // If pagination terminated naturally, tell the collector.
 *     $collector->markExhausted();
 *
 *     return array(
 *         'items' => $collector->getAccepted(),
 *     );
 *
 * Items returned from `getAccepted()` still flow through the standard
 * `FetchHandler` pipeline, where final dedupe + claim + max_items cap apply.
 *
 * @package DataMachine\Core\Steps\Fetch
 * @since 0.105.0
 */

namespace DataMachine\Core\Steps\Fetch;

use DataMachine\Core\ExecutionContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshCandidateCollector {

	public const SKIP_PROCESSED = 'processed';
	public const SKIP_CLAIMED   = 'claimed';
	public const SKIP_DUPLICATE = 'duplicate';

	private ExecutionContext $context;

	/**
	 * Target number of fresh candidates. 0 means unlimited — the handler
	 * decides when to stop scanning (typically by pagination exhaustion).
	 */
	private int $max_items;

	/** @var array<int,mixed> Accepted candidate payloads in the order they were offered. */
	private array $accepted = array();

	/** @var array<string,true> Identifier set to detect duplicates within the current scan. */
	private array $seen_identifiers = array();

	private int $raw_seen           = 0;
	private int $processed_skipped  = 0;
	private int $claimed_skipped    = 0;
	private int $duplicate_skipped  = 0;
	private int $reprocess_accepted = 0;
	private bool $source_exhausted  = false;

	public function __construct( ExecutionContext $context, int $max_items = 0 ) {
		$this->context   = $context;
		$this->max_items = max( 0, $max_items );
	}

	/**
	 * Offer a candidate identifier (and optional payload) to the collector.
	 *
	 * The collector does the exact same processed/claim checks as
	 * `FetchHandler::filterProcessed()` so that selection-time filtering and
	 * final fetch-time filtering agree on what counts as "fresh".
	 *
	 * @param string $identifier Stable, unique identifier for the source item.
	 * @param mixed  $payload    Optional candidate payload to retain. When omitted,
	 *                           the identifier itself is stored. Handlers commonly
	 *                           pass the normalized item shape that `FetchHandler`
	 *                           expects (`title`, `content`, `metadata`, ...).
	 * @return bool True when the candidate was accepted, false when skipped or full.
	 */
	public function offer( string $identifier, mixed $payload = null ): bool {
		if ( '' === $identifier ) {
			// Identifier-less items are never deduped at selection time —
			// they fall through to the regular FetchHandler path. Treat
			// the offer as "not collected" so the caller can decide.
			return false;
		}

		++$this->raw_seen;

		if ( $this->isFull() ) {
			return false;
		}

		if ( isset( $this->seen_identifiers[ $identifier ] ) ) {
			++$this->duplicate_skipped;
			return false;
		}

		if ( $this->context->isItemProcessed( $identifier ) ) {
			++$this->processed_skipped;
			$this->seen_identifiers[ $identifier ] = true;
			return false;
		}

		if ( $this->context->isItemClaimed( $identifier ) ) {
			++$this->claimed_skipped;
			$this->seen_identifiers[ $identifier ] = true;
			return false;
		}

		// Selection-time accept. If the row exists in the processed table and
		// only got past `isItemProcessed()` because the
		// `datamachine_should_reprocess_item` filter overrode the default
		// skip, surface that as a separate diagnostic so consumers can
		// distinguish "fresh discovery" from "scheduled revisit". Bounded by
		// `max_items` so cost stays predictable.
		if ( $this->rawIsProcessed( $identifier ) ) {
			++$this->reprocess_accepted;
		}

		$this->seen_identifiers[ $identifier ] = true;
		$this->accepted[]                      = ( null === $payload ) ? $identifier : $payload;
		return true;
	}

	/**
	 * True when the collector has reached its `max_items` target.
	 *
	 * Always false when `max_items` is 0 (unlimited).
	 */
	public function isFull(): bool {
		return $this->max_items > 0 && count( $this->accepted ) >= $this->max_items;
	}

	/**
	 * Mark the source as exhausted — used when the handler's pagination has
	 * walked every available candidate without filling the collector.
	 *
	 * Idempotent.
	 */
	public function markExhausted(): void {
		$this->source_exhausted = true;
	}

	/**
	 * Whether the source has been marked exhausted.
	 */
	public function isExhausted(): bool {
		return $this->source_exhausted;
	}

	/**
	 * Number of accepted (fresh) candidates so far.
	 */
	public function count(): int {
		return count( $this->accepted );
	}

	/**
	 * Configured target. 0 means unlimited.
	 */
	public function getMaxItems(): int {
		return $this->max_items;
	}

	/**
	 * Accepted candidate payloads in offer order.
	 *
	 * @return array<int,mixed>
	 */
	public function getAccepted(): array {
		return $this->accepted;
	}

	/**
	 * Diagnostic snapshot suitable for logging or returning alongside the
	 * accepted list. All counters are integers; `source_exhausted` is a bool.
	 *
	 * @return array{
	 *   raw_seen:int,
	 *   accepted:int,
	 *   processed_skipped:int,
	 *   claimed_skipped:int,
	 *   duplicate_skipped:int,
	 *   reprocess_accepted:int,
	 *   max_items:int,
	 *   source_exhausted:bool
	 * }
	 */
	public function getDiagnostics(): array {
		return array(
			'raw_seen'           => $this->raw_seen,
			'accepted'           => count( $this->accepted ),
			'processed_skipped'  => $this->processed_skipped,
			'claimed_skipped'    => $this->claimed_skipped,
			'duplicate_skipped'  => $this->duplicate_skipped,
			'reprocess_accepted' => $this->reprocess_accepted,
			'max_items'          => $this->max_items,
			'source_exhausted'   => $this->source_exhausted,
		);
	}

	/**
	 * Inspect the persisted processed-state without invoking the reprocess
	 * filter, so we can detect filter-driven overrides.
	 *
	 * Mirrors the early-out logic of `ExecutionContext::isItemProcessed()` for
	 * direct/standalone modes, where there is no persistence layer at all.
	 *
	 * Protected so tests can override without touching the database.
	 */
	protected function rawIsProcessed( string $identifier ): bool {
		if ( $this->context->isDirect() || $this->context->isStandalone() ) {
			return false;
		}

		$flow_step_id = $this->context->getFlowStepId();
		if ( ! $flow_step_id ) {
			return false;
		}

		$repo = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();
		return $repo->has_item_been_processed(
			$flow_step_id,
			$this->context->getHandlerType(),
			$identifier
		);
	}
}
