<?php
/**
 * Abstract base class for fetch handlers
 *
 * Provides common functionality for all fetch handlers including config extraction,
 * filtering, HTTP utilities, and authentication.
 *
 * Uses ExecutionContext for deduplication, engine data, file storage, and logging.
 *
 * Also registers the skip_item tool available to all fetch-type handlers, allowing
 * the pipeline agent to explicitly skip items that don't meet processing criteria.
 *
 * @package    Data_Machine
 * @subpackage Core\Steps\Fetch\Handlers
 * @since      0.2.1
 */

namespace DataMachine\Core\Steps\Fetch\Handlers;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\DataPacket;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\FilesRepository\FileStorage;
use DataMachine\Core\HttpClient;
use DataMachine\Core\Steps\Fetch\Tools\SkipItemTool;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class FetchHandler {

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
		$config  = $this->extractConfig( $handler_config );
		$context = ExecutionContext::fromConfig( $handler_config, $job_id, $this->handler_type );

		$result = $this->executeFetch( $config, $context );

		if ( empty( $result ) || ! is_array( $result ) ) {
			return array();
		}

		$flow_id = $handler_config['flow_id'] ?? null;

		// Normalize: if handler returned { items: [...] }, use that list.
		// Otherwise treat the entire result as a single item.
		$items = ( isset( $result['items'] ) && is_array( $result['items'] ) )
			? $result['items']
			: array( $result );

		// Dedup: filter out already-processed items.
		// Items with metadata['dedup_key'] are checked against the processed items
		// database. Already-processed items are removed. New items are NOT yet
		// marked — marking happens after the max_items cap so we don't permanently
		// discard items that simply didn't fit in this batch.
		$items = $this->filterProcessed( $items, $context );

		// Apply max_items cap.
		// Default comes from getDefaultMaxItems() which subclasses can override.
		// Set to 0 for unlimited.
		$max_items = (int) ( $config['max_items'] ?? $this->getDefaultMaxItems() );
		if ( $max_items > 0 && count( $items ) > $max_items ) {
			$items = array_slice( $items, 0, $max_items );
		}

		// NOTE: Items are NOT marked as processed here. Marking is deferred
		// to ExecuteStepAbility::markCompletedItemProcessed() which runs when
		// the LAST step in the pipeline completes successfully for each item.
		// This prevents "dropped events" where a fetch marks an item as processed
		// but a downstream step (AI, update) fails — the item would never be
		// retried because the dedup filter would skip it on the next run.
		//
		// Items cut by max_items remain naturally unmarked and will be picked
		// up in future fetch cycles.

		return $this->toDataPackets( $items, $pipeline_id, $flow_id );
	}

	/**
	 * Filter out already-processed items WITHOUT marking new ones.
	 *
	 * Items with metadata['dedup_key'] are checked against the processed items
	 * database. Already-processed items are removed. New items pass through
	 * but are NOT marked as processed here — marking is deferred to
	 * ExecuteStepAbility::markCompletedItemProcessed() when the full pipeline
	 * completes successfully, so failed downstream steps don't cause dropped items.
	 *
	 * Items without dedup_key are not deduped and pass through unchanged.
	 *
	 * @param array            $items   Normalized items array.
	 * @param ExecutionContext $context Execution context.
	 * @return array Filtered items array (new items only).
	 */
	private function filterProcessed( array $items, ExecutionContext $context ): array {
		$result = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$dedup_key = $item['metadata']['dedup_key'] ?? null;

			// No dedup_key — pass through.
			if ( null === $dedup_key || '' === $dedup_key ) {
				$result[] = $item;
				continue;
			}

			// Already processed — skip.
			if ( $context->isItemProcessed( (string) $dedup_key ) ) {
				continue;
			}

			$result[] = $item;
		}

		return $result;
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

			$dedup_key = $item['metadata']['dedup_key'] ?? null;

			if ( null === $dedup_key || '' === $dedup_key ) {
				continue;
			}

			$context->markItemProcessed( (string) $dedup_key );

			// Hook for handler-specific side effects (e.g., storeItemContext).
			$this->onItemProcessed( $context, $item );
		}
	}

	/**
	 * Called after an item is marked as processed during dedup.
	 *
	 * Override in subclasses to add handler-specific side effects.
	 * For example, EventImportHandler stores item context in engine data
	 * for the skip_item AI tool.
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
	 * EventImportHandler override to 0 (unlimited) since structured
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
	 * Perform HTTP request with standardized handling
	 *
	 * @param string $method  HTTP method (GET, POST, PUT, DELETE, PATCH)
	 * @param string $url     Request URL
	 * @param array  $options Request options:
	 *                        - headers: array - Additional headers to merge
	 *                        - body: string|array - Request body (for POST/PUT/PATCH)
	 *                        - timeout: int - Request timeout (default 120)
	 *                        - browser_mode: bool - Use browser-like headers (default false)
	 *                        - context: string - Context for logging (defaults to handler_type)
	 * @return array{success: bool, data?: string, status_code?: int, headers?: array, response?: array, error?: string}
	 */
	protected function httpRequest( string $method, string $url, array $options = array() ): array {
		if ( ! isset( $options['context'] ) ) {
			$options['context'] = ucfirst( $this->handler_type );
		}
		return HttpClient::request( $method, $url, $options );
	}

	/**
	 * Perform HTTP GET request
	 *
	 * @param string $url     Request URL
	 * @param array  $options Request options
	 * @return array Response array
	 */
	protected function httpGet( string $url, array $options = array() ): array {
		return $this->httpRequest( 'GET', $url, $options );
	}

	/**
	 * Perform HTTP POST request
	 *
	 * @param string $url     Request URL
	 * @param array  $options Request options
	 * @return array Response array
	 */
	protected function httpPost( string $url, array $options = array() ): array {
		return $this->httpRequest( 'POST', $url, $options );
	}

	/**
	 * Perform HTTP DELETE request
	 *
	 * @param string $url     Request URL
	 * @param array  $options Request options
	 * @return array Response array
	 */
	protected function httpDelete( string $url, array $options = array() ): array {
		return $this->httpRequest( 'DELETE', $url, $options );
	}

	/**
	 * Initialize FetchHandler static functionality.
	 *
	 * Registers the skip_item tool filter for all fetch-type handlers.
	 * Called during plugin bootstrap after handlers are loaded.
	 *
	 * @since 0.9.7
	 */
	public static function init(): void {
		add_filter( 'chubes_ai_tools', array( self::class, 'registerSkipItemTool' ), 10, 4 );
	}

	/**
	 * Register skip_item tool for fetch-type handlers.
	 *
	 * The skip_item tool is available when the previous step (before the AI step)
	 * is a fetch-type handler. This allows the AI to explicitly skip items that
	 * don't meet processing criteria (e.g., non-music events).
	 *
	 * @param array       $tools          Current tools array
	 * @param string|null $handler_slug   Handler slug being queried
	 * @param array       $handler_config Handler configuration
	 * @param array       $engine_data    Engine data snapshot
	 * @return array Modified tools array
	 * @since 0.9.7
	 */
	public static function registerSkipItemTool( array $tools, ?string $handler_slug = null, array $handler_config = array(), array $engine_data = array() ): array {
		$engine_data;
		if ( empty( $handler_slug ) ) {
			return $tools;
		}

		// Check if this handler_slug is a fetch-type or event_import-type handler
		$fetch_handlers        = apply_filters( 'datamachine_handlers', array(), 'fetch' );
		$event_import_handlers = apply_filters( 'datamachine_handlers', array(), 'event_import' );
		$all_fetch_handlers    = array_merge( $fetch_handlers, $event_import_handlers );

		if ( ! isset( $all_fetch_handlers[ $handler_slug ] ) ) {
			return $tools;
		}

		// Register skip_item tool with handler association
		$tools['skip_item'] = self::getSkipItemToolDefinition( $handler_slug, $handler_config );

		return $tools;
	}

	/**
	 * Get the skip_item tool definition.
	 *
	 * @param string $handler_slug   Handler slug to associate with
	 * @param array  $handler_config Handler configuration
	 * @return array Tool definition
	 * @since 0.9.7
	 */
	private static function getSkipItemToolDefinition( string $handler_slug, array $handler_config ): array {
		return array(
			'class'          => SkipItemTool::class,
			'method'         => 'handle_tool_call',
			'handler'        => $handler_slug,
			'description'    => 'Skip processing this item. Use when the item does not meet quality or relevance criteria defined in the pipeline rules (RULES.md). The item will be marked as processed and will not be refetched on subsequent runs.',
			'parameters'     => array(
				'reason' => array(
					'type'        => 'string',
					'description' => 'Concise skip reason, 2-5 words max. Examples: "not a music event", "comedy show", "wrong location", "duplicate event", "past event". Do NOT write sentences — just the category.',
					'required'    => true,
				),
			),
			'handler_config' => $handler_config,
		);
	}
}
