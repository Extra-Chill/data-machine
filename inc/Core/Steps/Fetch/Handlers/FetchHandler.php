<?php
/**
 * Abstract base class for fetch handlers
 *
 * Provides common functionality for all fetch handlers including config extraction,
 * filtering, HTTP utilities, and authentication.
 *
 * Uses ExecutionContext for deduplication, engine data, file storage, and logging.
 *
 * Also registers explicit reject/defer tools available to all fetch-type handlers,
 * allowing the pipeline agent to mark source rejections or release retriable items.
 *
 * @package    Data_Machine
 * @subpackage Core\Steps\Fetch\Handlers
 * @since      0.2.1
 */

namespace DataMachine\Core\Steps\Fetch\Handlers;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\FilesRepository\FileStorage;
use DataMachine\Core\RunMetrics;
use DataMachine\Core\Steps\Fetch\Tools\FetchItemDispositionTool;
use DataMachine\Core\Steps\Handlers\HttpRequestHelpers;
use DataMachine\Engine\AI\Tools\ToolManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class FetchHandler {

	use HttpRequestHelpers;

	/**
	 * Handler type identifier (e.g., 'rss', 'reddit', 'files')
	 */
	protected string $handler_type;

	public function __construct( string $handler_type ) {
		$this->handler_type = $handler_type;
	}

	/**
	 * Template method — final entry point for all fetch handlers.
	 *
	 * Creates ExecutionContext, delegates to child class, and wraps raw
	 * handler output into DataPacket objects. Handlers never need to know
	 * about DataPacket — they return plain arrays.
	 *
	 * Accepts any handler output shape and normalizes it into items:
	 *
	 * - `{ items: [ {title, content, metadata}, ... ] }` — explicit item list.
	 * - `{ title, content, metadata, file_info }` — single item (treated as list of one).
	 * - `[]` or non-array — empty result.
	 *
	 * @param int|string  $pipeline_id    Pipeline ID or 'direct' for direct execution.
	 * @param array       $handler_config Handler configuration array.
	 * @param string|null $job_id         Optional job ID.
	 * @return DataPacket[] Array of DataPackets (empty on failure/no data).
	 */
	final public function get_fetch_data( int|string $pipeline_id, array $handler_config, ?string $job_id = null ): array {
		$config              = $this->extractConfig( $handler_config );
		$context             = ExecutionContext::fromConfig( $handler_config, $job_id, $this->handler_type );
		$stages              = $this->initialFetchDiagnostics( $config, $context );
		$max_items           = (int) ( $config['max_items'] ?? $this->getDefaultMaxItems() );
		$stages['max_items'] = max( 0, $max_items );

		$result = $this->executeFetch( $config, $context );

		if ( empty( $result ) || ! is_array( $result ) ) {
			$this->recordFetchDiagnostics( $context, $stages );
			return array();
		}

		$flow_id = $handler_config['flow_id'] ?? null;

		// Normalize: if handler returned { items: [...] }, use that list.
		// Otherwise treat the entire result as a single item.
		$items                         = ( isset( $result['items'] ) && is_array( $result['items'] ) )
			? $result['items']
			: array( $result );
		$stages['source_materialized'] = count( $items );

		// Dedup: filter out already-processed and actively claimed items.
		// Items with metadata['item_identifier'] are checked against the processed items
		// database. Already-processed/claimed items are removed. New items are NOT yet
		// claimed — claim creation happens after the max_items cap so we don't block
		// items that simply didn't fit in this batch.
		$items = $this->filterProcessed( $items, $context, $stages );

		// Apply max_items cap.
		// Default comes from getDefaultMaxItems() which subclasses can override.
		// Set to 0 for unlimited.
		if ( $max_items > 0 && count( $items ) > $max_items ) {
			$stages['eligible_outside_max_items'] = count( $items ) - $max_items;
			$items                                = array_slice( $items, 0, $max_items );
		}
		$stages['selected_after_max_items'] = count( $items );

		// Claim only the items this fetch will actually schedule. Claims close the
		// race between parallel parent flow ticks while still deferring final
		// processed marking until the downstream pipeline completes successfully.
		$items = $this->claimItems( $items, $context, $stages );

		// NOTE: Items are NOT marked as processed here. Marking is deferred
		// to ExecuteStepAbility::markCompletedItemProcessed() which runs when
		// the LAST step in the pipeline completes successfully for each item.
		// This prevents "dropped events" where a fetch marks an item as processed
		// but a downstream step (AI, update) fails — the item would never be
		// retried because the dedup filter would skip it on the next run.
		//
		// Items cut by max_items remain naturally unmarked and will be picked
		// up in future fetch cycles.

		$packets                 = $this->toDataPackets( $items, $pipeline_id, $flow_id );
		$stages['final_packets'] = count( $packets );
		$this->recordFetchDiagnostics( $context, $stages );

		return $packets;
	}

	/**
	 * Filter out already-processed or actively claimed items WITHOUT marking new ones.
	 *
	 * Items with metadata['item_identifier'] are checked against the processed items
	 * database. Already-processed and actively claimed items are removed. New
	 * items pass through but are NOT marked as processed here — marking is deferred to
	 * ExecuteStepAbility::markCompletedItemProcessed() when the full pipeline
	 * completes successfully, so failed downstream steps don't cause dropped items.
	 *
	 * Items without item_identifier are not deduped and pass through unchanged.
	 *
	 * @param array            $items   Normalized items array.
	 * @param ExecutionContext $context Execution context.
	 * @param array|null       $diagnostics Optional aggregate diagnostics populated by reference.
	 * @return array Filtered items array (new items only).
	 */
	private function filterProcessed( array $items, ExecutionContext $context, ?array &$diagnostics = null ): array {
		$identifiers = array();
		$candidates  = array();
		$valid_items = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ! empty( $item['title'] ) || ! empty( $item['content'] ) || ! empty( $item['file_info'] ) ) {
				++$valid_items;
			}
			$item_identifier = $item['metadata']['item_identifier'] ?? null;
			$candidates[]    = array(
				'item'            => $item,
				'item_identifier' => $item_identifier,
			);
			if ( null !== $item_identifier && '' !== $item_identifier ) {
				$identifiers[] = (string) $item_identifier;
			}
		}

		$classification = $context->classifySourceItems( $identifiers );
		$decisions      = $classification['classifications'];
		$counts         = $classification['diagnostics'];
		$decision_index = 0;
		$result         = array();

		if ( is_array( $diagnostics ) ) {
			$identifierless_items                     = count( $candidates ) - count( $identifiers );
			$diagnostics['valid_items']               = $valid_items;
			$diagnostics['invalid_items']             = max( 0, (int) $diagnostics['source_materialized'] - $valid_items );
			$diagnostics['identified_items']          = count( $identifiers );
			$diagnostics['identifierless_items']      = $identifierless_items;
			$diagnostics['unique_identifiers']        = (int) ( $counts['unique'] ?? 0 );
			$diagnostics['duplicate_identifiers']     = (int) ( $counts['duplicates'] ?? 0 );
			$diagnostics['eligible_before_max_items'] = (int) ( $counts['eligible'] ?? 0 ) + $identifierless_items;
			$diagnostics['processed_skipped']         = (int) ( $counts['processed_skipped'] ?? 0 );
			$diagnostics['reprocess_eligible']        = (int) ( $counts['processed_reprocess_eligible'] ?? 0 );
			$diagnostics['actively_claimed']          = (int) ( $counts['actively_claimed'] ?? 0 );
		}

		foreach ( $candidates as $candidate ) {
			$item            = $candidate['item'];
			$item_identifier = $candidate['item_identifier'];

			// No item_identifier — pass through.
			if ( null === $item_identifier || '' === $item_identifier ) {
				$result[] = $item;
				continue;
			}

			$decision = $decisions[ $decision_index ] ?? null;
			++$decision_index;
			if ( ! is_array( $decision ) || ! $decision['selected'] ) {
				continue;
			}

			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Claim items that survived dedup and max_items selection.
	 *
	 * A failed claim means another parallel fetch won the race after our initial
	 * filter check. Drop that item from this run so only one child pipeline job
	 * is scheduled for the source item.
	 *
	 * @param array            $items   Items selected for scheduling.
	 * @param ExecutionContext $context Execution context.
	 * @param array|null       $diagnostics Optional aggregate diagnostics populated by reference.
	 * @return array Items whose claim was acquired, plus identifier-less items.
	 */
	private function claimItems( array $items, ExecutionContext $context, ?array &$diagnostics = null ): array {
		$result                = array();
		$attempted_identifiers = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item_identifier = $item['metadata']['item_identifier'] ?? null;

			// No item_identifier — no dedupe contract, pass through unchanged.
			if ( null === $item_identifier || '' === $item_identifier ) {
				$result[] = $item;
				continue;
			}

			if ( is_array( $diagnostics ) ) {
				++$diagnostics['claim_attempts'];
			}
			$is_duplicate                                       = isset( $attempted_identifiers[ (string) $item_identifier ] );
			$attempted_identifiers[ (string) $item_identifier ] = true;
			$claim = $context->claimItemOwnership( (string) $context->getFlowStepId(), (string) $item_identifier );
			if ( false === $claim ) {
				if ( is_array( $diagnostics ) ) {
					if ( $is_duplicate ) {
						++$diagnostics['duplicate_claim_conflicts'];
					} else {
						++$diagnostics['claim_conflicts'];
					}
				}
				continue;
			}
			if ( is_array( $diagnostics ) ) {
				++$diagnostics['claims_acquired'];
			}

			$item['metadata'][ ProcessedItems::CLAIM_METADATA_KEY ]          = $claim;
			$item['metadata'][ ProcessedItems::DISPOSITION_ID_METADATA_KEY ] = $claim['disposition_id'];

			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Build the bounded diagnostic envelope for one fetch invocation.
	 *
	 * @param array            $config  Effective handler configuration.
	 * @param ExecutionContext $context Execution context.
	 * @return array<string,int|string>
	 */
	private function initialFetchDiagnostics( array $config, ExecutionContext $context ): array {
		return array(
			'schema_version'             => 'datamachine.fetch_stages.v1',
			'handler_type'               => $this->handler_type,
			'execution_mode'             => $context->getMode(),
			'config_hash'                => $this->configHash( $config ),
			'source_materialized'        => 0,
			'valid_items'                => 0,
			'invalid_items'              => 0,
			'identified_items'           => 0,
			'identifierless_items'       => 0,
			'unique_identifiers'         => 0,
			'duplicate_identifiers'      => 0,
			'eligible_before_max_items'  => 0,
			'processed_skipped'          => 0,
			'reprocess_eligible'         => 0,
			'actively_claimed'           => 0,
			'max_items'                  => 0,
			'eligible_outside_max_items' => 0,
			'selected_after_max_items'   => 0,
			'claim_attempts'             => 0,
			'claims_acquired'            => 0,
			'claim_conflicts'            => 0,
			'duplicate_claim_conflicts'  => 0,
			'final_packets'              => 0,
		);
	}

	/**
	 * Persist aggregate fetch-stage evidence in the existing step result.
	 *
	 * @param ExecutionContext           $context Execution context.
	 * @param array<string,int|string>   $stages  Aggregate stage counts.
	 */
	private function recordFetchDiagnostics( ExecutionContext $context, array $stages ): void {
		$job_id       = (int) ( $context->getJobId() ?? 0 );
		$flow_step_id = (string) ( $context->getFlowStepId() ?? '' );
		if ( $job_id <= 0 || '' === $flow_step_id ) {
			return;
		}

		RunMetrics::recordStepResult(
			$job_id,
			$flow_step_id,
			array(
				'fetch_stages' => $stages,
			)
		);
	}

	/**
	 * Hash effective configuration without persisting secret-bearing values.
	 *
	 * @param array $config Effective handler configuration.
	 * @return string SHA-256 configuration hash.
	 */
	private function configHash( array $config ): string {
		$remaining  = 1000;
		$normalized = $this->normalizeConfigForHash( $config, $remaining );
		$encoded    = wp_json_encode( $normalized );
		return hash( 'sha256', false === $encoded ? '' : $encoded );
	}

	/**
	 * Build a bounded, secret-redacted configuration shape for hashing.
	 *
	 * @param mixed  $value     Configuration value.
	 * @param int    $remaining Remaining global traversal budget.
	 * @param int    $depth     Current nesting depth.
	 * @param string $key       Current configuration key.
	 * @return mixed Sorted value.
	 */
	private function normalizeConfigForHash( mixed $value, int &$remaining, int $depth = 0, string $key = '' ): mixed {
		if ( $remaining <= 0 ) {
			return '[entry-limit]';
		}
		--$remaining;

		$segmented_key  = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key ) ?? $key;
		$normalized_key = strtolower( str_replace( '-', '_', $segmented_key ) );
		if ( '' !== $normalized_key && ( 'key' === $normalized_key || str_ends_with( $normalized_key, '_key' ) || preg_match( '/(?:^|_)(?:auth|authentication|authorization|oauth|api_?key|private_key|signing_key|secret_key|access_key|bearer|cookie|passwd|password|passphrase|pat|secret|tokens?|credentials?)(?:_|$)/', $normalized_key ) ) ) {
			return '[redacted]';
		}
		if ( $depth >= 8 ) {
			return '[depth-limit]';
		}
		if ( ! is_array( $value ) ) {
			return is_string( $value ) && strlen( $value ) > 1024 ? substr( $value, 0, 1024 ) . '[truncated]' : $value;
		}

		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		$value      = array_slice( $value, 0, 200, true );
		$normalized = array();

		foreach ( $value as $child_key => $child ) {
			$normalized[ $child_key ] = $this->normalizeConfigForHash( $child, $remaining, $depth + 1, (string) $child_key );
		}

		return $normalized;
	}

	/**
	 * Mark items as processed and fire handler-specific side effects.
	 *
	 * Called AFTER max_items cap so only items that will actually be
	 * imported get marked. Items cut by the cap remain unmarked and
	 * will be picked up in future fetch cycles.
	 *
	 * @param array            $items   Items that survived filtering and capping.
	 * @param ExecutionContext $context Execution context.
	 */
	private function markProcessed( array $items, ExecutionContext $context ): void {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item_identifier = $item['metadata']['item_identifier'] ?? null;

			if ( null === $item_identifier || '' === $item_identifier ) {
				continue;
			}

			$context->markItemProcessed( (string) $item_identifier );

			// Hook for handler-specific side effects (e.g., storeItemContext).
			$this->onItemProcessed( $context, $item );
		}
	}

	/**
	 * Called after an item is marked as processed during dedup.
	 *
	 * Override in subclasses to add handler-specific side effects.
	 * For example, a specialized source handler may store item context in engine data
	 * for the fetch disposition AI tools.
	 *
	 * @param ExecutionContext $context Execution context.
	 * @param array            $item    The item that was just marked as processed.
	 */
	protected function onItemProcessed( ExecutionContext $context, array $item ): void {
		// No-op in base class. Subclasses override as needed.
	}

	/**
	 * Get the default max_items value when not explicitly configured.
	 *
	 * Base handlers default to 1 to prevent unbounded fan-out for
	 * AI-heavy pipelines (RSS, Reddit, etc.). Subclasses like
	 * Specialized source handlers may override to 0 (unlimited) when structured
	 * event scrapers produce clean data that is cheap to process.
	 *
	 * @return int Default max items. 0 = unlimited.
	 */
	protected function getDefaultMaxItems(): int {
		return 1;
	}

	/**
	 * Convert raw item arrays into DataPackets.
	 *
	 * One method handles any number of items — zero, one, or many.
	 * Each item is expected to have title, content, metadata, and/or file_info.
	 * Items with no content are silently dropped.
	 *
	 * @param array      $items       Array of raw item arrays.
	 * @param int|string $pipeline_id Pipeline ID.
	 * @param mixed      $flow_id     Flow ID.
	 * @return DataPacket[] Array of DataPackets.
	 */
	private function toDataPackets( array $items, int|string $pipeline_id, mixed $flow_id ): array {
		$packets = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$title     = $item['title'] ?? '';
			$content   = $item['content'] ?? '';
			$file_info = $item['file_info'] ?? null;
			$metadata  = $item['metadata'] ?? array();

			if ( empty( $title ) && empty( $content ) && empty( $file_info ) ) {
				continue;
			}

			$content_array = array(
				'title' => $title,
				'body'  => $content,
			);

			if ( $file_info ) {
				$content_array['file_info'] = $file_info;
			}

			$packet_metadata = array_merge(
				array(
					'source_type' => $this->handler_type,
					'pipeline_id' => $pipeline_id,
					'flow_id'     => $flow_id,
					'handler'     => $this->handler_type,
				),
				$metadata
			);

			$packets[] = new DataPacket( $content_array, $packet_metadata, 'fetch' );
		}

		return $packets;
	}

	/**
	 * Abstract method - child classes implement fetch logic
	 *
	 * @param array            $config  Handler-specific configuration
	 * @param ExecutionContext $context Execution context for deduplication, logging, etc.
	 * @return array Processed items array
	 */
	abstract protected function executeFetch( array $config, ExecutionContext $context ): array;

	/**
	 * Extract handler-specific config from handler config
	 *
	 * @param array $handler_config Handler configuration
	 * @return array Handler-specific configuration array
	 */
	protected function extractConfig( array $handler_config ): array {
		// handler_config is ALWAYS flat structure - no nesting
		return $handler_config;
	}

	/**
	 * Apply timeframe filtering to timestamp
	 *
	 * @param int    $timestamp       Item timestamp
	 * @param string $timeframe_limit Timeframe limit (all_time, last_24h, last_7d, etc.)
	 * @return bool True if item should be included
	 */
	protected function applyTimeframeFilter( int $timestamp, string $timeframe_limit ): bool {
		$cutoff_timestamp = apply_filters( 'datamachine_timeframe_limit', null, $timeframe_limit );

		if ( null === $cutoff_timestamp ) {
			return true;
		}

		return $timestamp >= $cutoff_timestamp;
	}

	/**
	 * Apply keyword search filtering
	 *
	 * @param string $text   Text to search
	 * @param string $search Search keywords
	 * @return bool True if text matches search criteria
	 */
	public function applyKeywordSearch( string $text, string $search ): bool {
		$search = trim( $search );

		if ( empty( $search ) ) {
			return true;
		}

		return apply_filters( 'datamachine_keyword_search_match', false, $text, $search );
	}

	/**
	 * Apply exclusion keyword filtering
	 *
	 * @param string $text             Text to search
	 * @param string $exclude_keywords Comma-separated keywords to exclude
	 * @return bool True if text should be EXCLUDED (matches a keyword)
	 */
	public function applyExcludeKeywords( string $text, string $exclude_keywords ): bool {
		$exclude_keywords = trim( $exclude_keywords );

		if ( empty( $exclude_keywords ) ) {
			return false;
		}

		$keywords = array_map( 'trim', explode( ',', $exclude_keywords ) );
		$keywords = array_filter( $keywords );

		if ( empty( $keywords ) ) {
			return false;
		}

		$text_lower = strtolower( $text );
		foreach ( $keywords as $keyword ) {
			if ( mb_stripos( $text_lower, strtolower( $keyword ) ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get file storage service
	 *
	 * @return FileStorage File storage instance
	 */
	protected function getFileStorage(): FileStorage {
		return new FileStorage();
	}

	/**
	 * Get OAuth provider instance
	 *
	 * @param string $provider_key Provider key (e.g., 'reddit', 'google_sheets')
	 * @return object|null Provider instance or null
	 */
	protected function getAuthProvider( string $provider_key ): ?object {
		$auth_abilities = new AuthAbilities();
		return $auth_abilities->getProvider( $provider_key );
	}

	/**
	 * Initialize FetchHandler static functionality.
	 *
	 * Registers fetch disposition tools in the unified `datamachine_tools` registry
	 * as a cross-cutting handler tool for adjacent source-category steps.
	 *
	 * @since 0.9.7
	 */
	public static function init(): void {
		add_filter(
			'datamachine_tools',
			static function ( array $tools ): array {
				$tools['__handler_tools_fetch_dispositions'] = ToolManager::handlerToolDeclaration(
					array( self::class, 'resolveFetchDispositionTools' ),
					array(
						'handler_categories'      => array( 'source' ),
						'client_context_bindings' => array( 'job_id' ),
					)
				);
				return $tools;
			}
		);
	}

	/**
	 * Resolve fetch disposition tools for a specific fetch-type handler.
	 *
	 * Invoked lazily by ToolManager::resolveHandlerTools() when an adjacent
	 * step handler belongs to the source category. The tool
	 * is re-shaped per-handler so the description can reference the concrete
	 * source in pipeline prompts.
	 *
	 * @param string $handler_slug   Resolved adjacent-step handler slug.
	 * @param array  $handler_config Handler configuration.
	 * @param array  $engine_data    Engine data snapshot (unused).
	 * @return array{reject_source: array, defer_item: array} Tool map with disposition definitions.
	 * @since 0.9.7
	 */
	public static function resolveFetchDispositionTools(
		string $handler_slug,
		array $handler_config,
		array $engine_data
	): array {
		unset( $engine_data );
		return array(
			'reject_source' => self::getRejectSourceToolDefinition( $handler_slug, $handler_config ),
			'defer_item'    => self::getDeferItemToolDefinition( $handler_slug, $handler_config ),
		);
	}

	/**
	 * Get the reject_source tool definition.
	 *
	 * @param string $handler_slug   Handler slug to associate with
	 * @param array  $handler_config Handler configuration
	 * @return array Tool definition
	 * @since 0.9.7
	 */
	private static function getRejectSourceToolDefinition( string $handler_slug, array $handler_config ): array {
		return array(
			'class'          => FetchItemDispositionTool::class,
			'method'         => 'handle_tool_call',
			'handler'        => $handler_slug,
			'disposition'    => 'reject_source',
			'runtime'        => array( 'completion_signal' => 'terminal' ),
			'description'    => 'Reject this fetched source item after reasoned content/source evaluation. Use when the source itself is irrelevant, too thin, duplicate, noisy, spammy, or otherwise fails the pipeline quality or relevance criteria. The source item will be marked as processed and will not normally be refetched.',
			'parameters'     => array(
				'type'       => 'object',
				'properties' => array(
					'reason' => array(
						'type'        => 'string',
						'description' => 'Concise categorical rejection reason, following the vocabulary defined in the pipeline system prompt or RULES.md.',
					),
				),
				'required'   => array( 'reason' ),
			),
			'handler_config' => $handler_config,
		);
	}

	/**
	 * Get the defer_item tool definition.
	 *
	 * @param string $handler_slug   Handler slug to associate with.
	 * @param array  $handler_config Handler configuration.
	 * @return array Tool definition.
	 * @since 0.9.7
	 */
	private static function getDeferItemToolDefinition( string $handler_slug, array $handler_config ): array {
		return array(
			'class'          => FetchItemDispositionTool::class,
			'method'         => 'handle_tool_call',
			'handler'        => $handler_slug,
			'disposition'    => 'defer_item',
			'runtime'        => array( 'completion_signal' => 'terminal' ),
			'description'    => 'Defer this fetched source item when the agent cannot safely complete processing now because of runtime failures, tool errors, missing context, uncertainty, or temporary limitations. The source claim will be released and the item will remain eligible to be fetched and retried later; it will not be marked processed.',
			'parameters'     => array(
				'type'       => 'object',
				'properties' => array(
					'reason' => array(
						'type'        => 'string',
						'description' => 'Concise reason explaining why processing cannot safely complete now and should be retried later.',
					),
				),
				'required'   => array( 'reason' ),
			),
			'handler_config' => $handler_config,
		);
	}
}
