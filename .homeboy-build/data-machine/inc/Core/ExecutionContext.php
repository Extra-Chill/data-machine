<?php
/**
 * Execution Context
 *
 * Encapsulates execution mode and provides a unified interface for all handler types.
 * Centralizes deduplication, engine data access, file storage context, and logging.
 *
 * Supports three execution modes:
 * - 'direct': Direct execution without database persistence (CLI tools, ephemeral workflows)
 * - 'flow': Standard flow-based execution with full pipeline/flow context
 * - 'standalone': Job execution without pipeline/flow context (system tasks, ad-hoc jobs).
 *   Uses the default model — no separate model override needed.
 *
 * In direct mode, pipeline_id and flow_id are set to the string 'direct' for consistent
 * end-to-end traceability throughout the system.
 *
 * @package DataMachine\Core
 * @since 0.9.16
 */

namespace DataMachine\Core;

use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\FilesRepository\RemoteFileDownloader;

defined( 'ABSPATH' ) || exit;

class ExecutionContext {

	public const MODE_DIRECT     = 'direct';
	public const MODE_FLOW       = 'flow';
	public const MODE_STANDALONE = 'standalone';

	private string $mode;
	private int|string|null $pipeline_id;
	private int|string|null $flow_id;
	private ?string $flow_step_id;
	private ?string $job_id;
	private ?int $agent_id;
	private string $handler_type;
	private ?EngineData $engine = null;

	/**
	 * Private constructor - use factory methods.
	 */
	private function __construct(
		string $mode,
		int|string|null $pipeline_id,
		int|string|null $flow_id,
		?string $flow_step_id,
		?string $job_id,
		string $handler_type = '',
		?int $agent_id = null
	) {
		$this->mode         = $mode;
		$this->pipeline_id  = $pipeline_id;
		$this->flow_id      = $flow_id;
		$this->flow_step_id = $flow_step_id;
		$this->job_id       = $job_id;
		$this->handler_type = $handler_type;
		$this->agent_id     = $agent_id;
	}

	/**
	 * Factory: Direct execution mode.
	 *
	 * Use for CLI tools, ephemeral workflows, and testing.
	 * Deduplication is disabled, no database persistence required.
	 * Sets pipeline_id and flow_id to 'direct' for consistent traceability.
	 *
	 * @param string $handler_type Handler type identifier for logging
	 * @return self
	 */
	public static function direct( string $handler_type = '' ): self {
		return new self(
			self::MODE_DIRECT,
			'direct',
			'direct',
			'direct_' . wp_generate_uuid4(),
			null,
			$handler_type
		);
	}

	/**
	 * Factory: Flow-based execution.
	 *
	 * Standard execution mode with full pipeline/flow context.
	 *
	 * @param int         $pipeline_id Pipeline ID
	 * @param int         $flow_id Flow ID
	 * @param string      $flow_step_id Flow step ID for deduplication
	 * @param string|null $job_id Job ID for engine data storage
	 * @param string      $handler_type Handler type identifier
	 * @param int|null    $agent_id Agent ID for agent-scoped execution
	 * @return self
	 */
	public static function fromFlow(
		int $pipeline_id,
		int $flow_id,
		string $flow_step_id,
		?string $job_id,
		string $handler_type = '',
		?int $agent_id = null
	): self {
		return new self(
			self::MODE_FLOW,
			$pipeline_id,
			$flow_id,
			$flow_step_id,
			$job_id,
			$handler_type,
			$agent_id
		);
	}

	/**
	 * Factory: Standalone execution (no pipeline/flow).
	 *
	 * Use for system tasks, ad-hoc jobs, and standalone operations that
	 * don't belong to a pipeline or flow.
	 *
	 * @param string|null $job_id Job ID
	 * @param string      $handler_type Handler type identifier
	 * @return self
	 */
	public static function standalone( ?string $job_id = null, string $handler_type = '' ): self {
		return new self(
			self::MODE_STANDALONE,
			null,
			null,
			null,
			$job_id,
			$handler_type
		);
	}

	/**
	 * Factory: Create from handler config array.
	 *
	 * Provides backward compatibility with existing config structures.
	 * Automatically detects direct execution mode from 'direct' sentinel values.
	 *
	 * @param array       $config Handler configuration array
	 * @param string|null $job_id Job ID
	 * @param string      $handler_type Handler type identifier
	 * @return self
	 */
	public static function fromConfig( array $config, ?string $job_id = null, string $handler_type = '' ): self {
		$flow_id     = $config['flow_id'] ?? null;
		$pipeline_id = $config['pipeline_id'] ?? null;
		$agent_id    = isset( $config['agent_id'] ) ? (int) $config['agent_id'] : null;

		if ( 'direct' === $flow_id || 'direct' === $pipeline_id ) {
			return new self(
				self::MODE_DIRECT,
				'direct',
				'direct',
				$config['flow_step_id'] ?? 'direct_' . wp_generate_uuid4(),
				$job_id,
				$handler_type,
				$agent_id
			);
		}

		// Standalone: both null — no pipeline/flow context.
		if ( null === $pipeline_id && null === $flow_id ) {
			return self::standalone( $job_id, $handler_type );
		}

		return self::fromFlow(
			(int) $pipeline_id,
			(int) $flow_id,
			$config['flow_step_id'] ?? '',
			$job_id,
			$handler_type,
			$agent_id
		);
	}

	/**
	 * Check if this is direct execution mode.
	 *
	 * @return bool
	 */
	public function isDirect(): bool {
		return self::MODE_DIRECT === $this->mode;
	}

	/**
	 * Check if this is flow-based execution mode.
	 *
	 * @return bool
	 */
	public function isFlow(): bool {
		return self::MODE_FLOW === $this->mode;
	}

	/**
	 * Check if this is standalone execution mode (no pipeline/flow).
	 *
	 * @return bool
	 */
	public function isStandalone(): bool {
		return self::MODE_STANDALONE === $this->mode;
	}

	/**
	 * Get execution mode.
	 *
	 * @return string 'direct', 'flow', or 'standalone'
	 */
	public function getMode(): string {
		return $this->mode;
	}

	/**
	 * Check if an item has been processed.
	 *
	 * In direct mode, always returns false (no deduplication).
	 *
	 * Applies the `datamachine_should_reprocess_item` filter so consumers
	 * can opt into time-windowed revisit semantics without every handler
	 * growing its own `--revisit-days` flag. The filter receives the
	 * default boolean skip decision and a context array, and returns a
	 * boolean: true to skip (default seen/not-seen behavior), false to
	 * process again despite the item being present in the table.
	 *
	 * @param string $item_identifier Item identifier
	 * @return bool True if already processed (and should be skipped).
	 */
	public function isItemProcessed( string $item_identifier ): bool {
		if ( $this->isDirect() || $this->isStandalone() || ! $this->flow_step_id ) {
			return false;
		}
		$db_processed_items = new ProcessedItems();
		$skip               = $db_processed_items->has_item_been_processed(
			$this->flow_step_id,
			$this->handler_type,
			$item_identifier
		);

		/**
		 * Filters whether an item should be reprocessed despite existing in processed_items.
		 *
		 * Default behavior (no filter): honor the boolean seen/not-seen check.
		 * Consumers can subscribe to reprocess stale items by returning false
		 * when `$skip` is true and the stored `processed_timestamp` is older
		 * than their revisit window.
		 *
		 * @since 0.71.0
		 *
		 * @param bool  $skip    Whether current logic says to skip (true = skip, false = process).
		 * @param array $context {
		 *     Context for the decision.
		 *
		 *     @type string $flow_step_id    Flow step ID.
		 *     @type string $source_type     Handler source type.
		 *     @type string $item_identifier Item identifier being checked.
		 *     @type int    $job_id          Current job ID (0 if unavailable).
		 * }
		 */
		$skip = (bool) apply_filters(
			'datamachine_should_reprocess_item',
			$skip,
			array(
				'flow_step_id'    => $this->flow_step_id,
				'source_type'     => $this->handler_type,
				'item_identifier' => $item_identifier,
				'job_id'          => (int) $this->job_id,
			)
		);

		return $skip;
	}

	/**
	 * Check if an item has an active in-flight claim.
	 *
	 * Active claims are separate from final processed rows and intentionally do
	 * not run through `datamachine_should_reprocess_item`; a claimed item is
	 * already being worked by another job and should not be scheduled again.
	 *
	 * @param string $item_identifier Item identifier.
	 * @return bool True if actively claimed.
	 */
	public function isItemClaimed( string $item_identifier ): bool {
		if ( $this->isDirect() || $this->isStandalone() || ! $this->flow_step_id ) {
			return false;
		}

		$db_processed_items = new ProcessedItems();
		return $db_processed_items->has_active_claim(
			$this->flow_step_id,
			$this->handler_type,
			$item_identifier
		);
	}

	/**
	 * Atomically claim an item for this job's in-flight processing.
	 *
	 * In direct/standalone modes there is no persisted dedupe surface, so claims
	 * are treated as successful no-ops.
	 *
	 * @param string $item_identifier Item identifier.
	 * @return bool True when the item may be scheduled.
	 */
	public function claimItemForProcessing( string $item_identifier ): bool {
		if ( $this->isDirect() || $this->isStandalone() || ! $this->flow_step_id ) {
			return true;
		}

		if ( empty( $this->job_id ) ) {
			return false;
		}

		$db_processed_items = new ProcessedItems();
		return $db_processed_items->claim_item(
			$this->flow_step_id,
			$this->handler_type,
			$item_identifier,
			(int) $this->job_id
		);
	}

	/**
	 * Mark an item as processed.
	 *
	 * In direct mode, does nothing (no deduplication tracking).
	 *
	 * @param string $item_identifier Item identifier
	 */
	public function markItemProcessed( string $item_identifier ): void {
		if ( $this->isDirect() || $this->isStandalone() || ! $this->flow_step_id ) {
			return;
		}
		do_action(
			'datamachine_mark_item_processed',
			$this->flow_step_id,
			$this->handler_type,
			$item_identifier,
			$this->job_id
		);
	}

	/**
	 * Get EngineData instance.
	 *
	 * Lazily instantiated on first access.
	 *
	 * @return EngineData
	 */
	public function getEngine(): EngineData {
		if ( null === $this->engine ) {
			$data         = $this->job_id ? datamachine_get_engine_data( (int) $this->job_id ) : array();
			$this->engine = new EngineData( $data, $this->job_id );
		}
		return $this->engine;
	}

	/**
	 * Store data in engine snapshot.
	 *
	 * @param array $data Data to merge into engine snapshot
	 */
	public function storeEngineData( array $data ): void {
		if ( $this->job_id ) {
			datamachine_merge_engine_data( (int) $this->job_id, $data );
			// Invalidate cached engine so next getEngine() fetches fresh data
			$this->engine = null;
		}
	}

	/**
	 * Get source URL from engine data.
	 *
	 * @return string|null
	 */
	public function getSourceUrl(): ?string {
		return $this->getEngine()->getSourceUrl();
	}

	/**
	 * Get image file path from engine data.
	 *
	 * @return string|null
	 */
	public function getImagePath(): ?string {
		return $this->getEngine()->getImagePath();
	}

	/**
	 * Get storage path for files.
	 *
	 * @return string
	 */
	public function getStoragePath(): string {
		if ( $this->isDirect() ) {
			return 'direct';
		}
		if ( $this->isStandalone() ) {
			return 'standalone' . ( $this->job_id ? "/job-{$this->job_id}" : '' );
		}
		return "pipeline-{$this->pipeline_id}/flow-{$this->flow_id}";
	}

	/**
	 * Get file context for downloads.
	 *
	 * In direct mode, returns 'direct' for IDs and names for consistent traceability.
	 *
	 * @return array Context array with pipeline/flow metadata
	 */
	public function getFileContext(): array {
		if ( $this->isDirect() ) {
			return array(
				'pipeline_id'   => 'direct',
				'pipeline_name' => 'direct',
				'flow_id'       => 'direct',
				'flow_name'     => 'direct',
			);
		}
		if ( $this->isStandalone() ) {
			return array(
				'pipeline_id'   => null,
				'pipeline_name' => 'standalone',
				'flow_id'       => null,
				'flow_name'     => 'standalone',
			);
		}
		return array(
			'pipeline_id'   => $this->pipeline_id,
			'pipeline_name' => "pipeline-{$this->pipeline_id}",
			'flow_id'       => $this->flow_id,
			'flow_name'     => "flow-{$this->flow_id}",
		);
	}

	/**
	 * Download a remote file to flow-isolated storage.
	 *
	 * @param string $url File URL
	 * @param string $filename Target filename
	 * @param array  $options Optional download options
	 * @return array|null Download result or null on failure
	 */
	public function downloadFile( string $url, string $filename, array $options = array() ): ?array {
		$downloader = new RemoteFileDownloader();
		return $downloader->download_remote_file( $url, $filename, $this->getFileContext(), $options );
	}

	/**
	 * Log message with automatic execution context.
	 *
	 * @param string $level Log level (debug, info, warning, error)
	 * @param string $message Log message
	 * @param array  $extra Additional context
	 */
	public function log( string $level, string $message, array $extra = array() ): void {
		$context = array_merge(
			array(
				'execution_mode' => $this->mode,
				'pipeline_id'    => $this->pipeline_id,
				'flow_id'        => $this->flow_id,
			),
			$extra
		);

		if ( $this->handler_type ) {
			$context['handler'] = $this->handler_type;
		}

		$agent_id = $this->getAgentId();
		if ( null !== $agent_id ) {
			$context['agent_id'] = $agent_id;
		}

		do_action( 'datamachine_log', $level, $message, $context );
	}

	/**
	 * Get pipeline ID.
	 *
	 * Returns 'direct' in direct execution mode.
	 *
	 * @return int|string|null
	 */
	public function getPipelineId(): int|string|null {
		return $this->pipeline_id;
	}

	/**
	 * Get flow ID.
	 *
	 * Returns 'direct' in direct execution mode.
	 *
	 * @return int|string|null
	 */
	public function getFlowId(): int|string|null {
		return $this->flow_id;
	}

	/**
	 * Get flow step ID.
	 *
	 * @return string|null
	 */
	public function getFlowStepId(): ?string {
		return $this->flow_step_id;
	}

	/**
	 * Get job ID.
	 *
	 * @return string|null
	 */
	public function getJobId(): ?string {
		return $this->job_id;
	}

	/**
	 * Get handler type.
	 *
	 * @return string
	 */
	public function getHandlerType(): string {
		return $this->handler_type;
	}

	/**
	 * Get agent ID.
	 *
	 * Returns the agent_id for this execution context. In flow mode this is
	 * resolved from the flow/pipeline's agent_id. Falls back to the engine
	 * data's job context if not set explicitly.
	 *
	 * @since 0.41.0
	 * @return int|null Agent ID or null if no agent scope.
	 */
	public function getAgentId(): ?int {
		if ( null !== $this->agent_id ) {
			return $this->agent_id;
		}

		// Fall back to engine data's job context if a job_id is present.
		if ( $this->job_id ) {
			return $this->getEngine()->getAgentId();
		}

		return null;
	}

	/**
	 * Create a new context with a different handler type.
	 *
	 * Useful when delegating to sub-handlers.
	 *
	 * @param string $handler_type New handler type
	 * @return self New context instance
	 */
	public function withHandlerType( string $handler_type ): self {
		$clone               = clone $this;
		$clone->handler_type = $handler_type;
		$clone->engine       = null; // Reset cached engine
		return $clone;
	}

	/**
	 * Create a new context with a job ID.
	 *
	 * Useful when job is created after context.
	 *
	 * @param string $job_id Job ID
	 * @return self New context instance
	 */
	public function withJobId( string $job_id ): self {
		$clone         = clone $this;
		$clone->job_id = $job_id;
		$clone->engine = null; // Reset cached engine
		return $clone;
	}
}
