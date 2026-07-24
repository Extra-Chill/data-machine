<?php
/**
 * Default handlers for generic step lifecycle hooks.
 *
 * @package DataMachine\Engine\Actions\Handlers
 * @since   0.146.2
 */

namespace DataMachine\Engine\Actions\Handlers;

use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\DataPacketStore;
use DataMachine\Core\JobStatus;
use DataMachine\Core\PacketEngineData;
use DataMachine\Core\RunMetrics;

/**
 * Keeps source-ingestion processed-item behavior behind lifecycle hooks.
 */
class StepLifecycleHandler {

	/** @var array<int,int> Processed metrics deferred until terminal commit. */
	private static array $pending_processed_metrics = array();

	/**
	 * Seed lifecycle context before an inline continuation.
	 *
	 * @param int   $job_id           Job ID.
	 * @param array $flow_step_config Current flow step configuration.
	 * @param array $routed_packets   Packets routed to the next step.
	 */
	public static function handleInlineContinuation( int $job_id, array $flow_step_config, array $routed_packets ): void {
		$step_type = $flow_step_config['step_type'] ?? '';
		if ( ! self::isSourceIngestionStep( (string) $step_type ) || empty( $routed_packets ) ) {
			return;
		}

		$seed_data = array();
		$claims    = self::claimsFromEngine( \datamachine_get_engine_data( $job_id ) );
		foreach ( $routed_packets as $packet ) {
			$packet_meta = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();
			if ( empty( $seed_data ) && is_array( $packet_meta['_engine_data'] ?? null ) ) {
				$seed_data = PacketEngineData::sanitize( $packet_meta['_engine_data'], $job_id );
			}
			if ( ! isset( $seed_data['item_identifier'] ) && ! empty( $packet_meta['item_identifier'] ) ) {
				$seed_data['item_identifier'] = $packet_meta['item_identifier'];
			}
			if ( ! isset( $seed_data['source_type'] ) && ! empty( $packet_meta['source_type'] ) ) {
				$seed_data['source_type'] = $packet_meta['source_type'];
			}
			$claims = array_merge( $claims, self::normalizeClaims( $packet_meta ) );
		}
		if ( ! empty( $claims ) ) {
			$seed_data[ ProcessedItems::CLAIMS_METADATA_KEY ] = self::uniqueClaims( $claims );
			unset( $seed_data[ ProcessedItems::CLAIM_METADATA_KEY ] );
		}
		if ( ! empty( $seed_data ) ) {
			\datamachine_merge_engine_data( $job_id, $seed_data );
		}
	}

	/**
	 * Mark a completed job's source item as processed.
	 *
	 * @param int        $job_id      Completed job ID.
	 * @param array|null $engine_data Optional engine data snapshot.
	 * @param bool       $within_transaction Whether the caller owns the terminal transaction.
	 * @return bool Whether all owned descriptor and legacy claims completed.
	 */
	public static function handleCompleted( int $job_id, ?array $engine_data = null, bool $within_transaction = false ): bool {
		$engine_data = is_array( $engine_data ) ? $engine_data : \datamachine_get_engine_data( $job_id );
		$claims      = self::claimsFromEngine( $engine_data );
		$completed   = 0;
		if ( ! empty( $claims ) ) {
			foreach ( $claims as $claim ) {
				if ( ! self::completeClaim( $claim, $job_id, $within_transaction ) ) {
					unset( self::$pending_processed_metrics[ $job_id ] );
					return false;
				}
				++$completed;
			}
		}

		$item_identifier = $engine_data['item_identifier'] ?? null;
		$source_type     = $engine_data['source_type'] ?? null;
		if ( empty( $item_identifier ) || empty( $source_type ) ) {
			return self::recordCompletedMetrics( $job_id, $completed, $within_transaction );
		}

		$source_flow_step_id = self::resolveSourceIngestionFlowStepId( $engine_data );
		if ( empty( $source_flow_step_id ) ) {
			return self::recordCompletedMetrics( $job_id, $completed, $within_transaction );
		}

		$legacy_completed = ( new ProcessedItems() )->complete_claim_for_job(
			$source_flow_step_id,
			(string) $source_type,
			(string) $item_identifier,
			$job_id
		);
		if ( false === $legacy_completed ) {
			unset( self::$pending_processed_metrics[ $job_id ] );
			return false;
		}
		$completed += $legacy_completed;

		return self::recordCompletedMetrics( $job_id, $completed, $within_transaction );
	}

	/**
	 * Release source-item claims when a job fails.
	 *
	 * @param int        $job_id      Failed job ID.
	 * @param array|null $engine_data Optional engine data snapshot.
	 * @return bool Whether every descriptor and legacy claim was released.
	 */
	public static function handleFailed( int $job_id, ?array $engine_data = null ): bool {
		$engine_data = is_array( $engine_data ) ? $engine_data : \datamachine_get_engine_data( $job_id );
		$claims      = self::claimsFromEngine( $engine_data );
		$processed   = new ProcessedItems();
		if ( ! empty( $claims ) ) {
			foreach ( $claims as $claim ) {
				if ( false === self::releaseClaim( $claim, $processed ) ) {
					return false;
				}
			}
		}

		// Pre-descriptor and partially migrated jobs still own claims by job_id.
		// Reacquisition replaces job_id, so this cannot release a newer worker's row.
		return false !== $processed->release_claims_for_job( $job_id );
	}

	/**
	 * Apply claim lifecycle for every terminal job transition.
	 *
	 * @param int    $job_id Terminal job ID.
	 * @param string $status Final job status.
	 */
	public static function handleTerminal( int $job_id, string $status ): void {
		unset( $status );
		unset( self::$pending_processed_metrics[ $job_id ] );
	}

	/** Clear request-local completion state after a database rollback. */
	public static function handleTerminalRollback( int $job_id ): void {
		unset( self::$pending_processed_metrics[ $job_id ] );
	}

	/** Persist transaction-derived accounting before the terminal commit. */
	public static function filterTerminalAccountingContext( array $context, int $job_id, string $status ): array {
		$completed = self::$pending_processed_metrics[ $job_id ] ?? 0;
		unset( self::$pending_processed_metrics[ $job_id ] );
		$context['processed_claim_count'] = JobStatus::isStatusSuccess( $status ) ? max( 0, $completed ) : 0;
		return $context;
	}

	/**
	 * Complete owned claims before a successful terminal status is persisted.
	 *
	 * @param string $status Requested terminal status.
	 * @param int    $job_id Job ID.
	 * @param array  $job    Current job row.
	 * @return string|\WP_Error Original status or a rollback signal.
	 */
	public static function filterTerminalStatus( string $status, int $job_id, array $job ): string|\WP_Error {
		$engine_data = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$prepared    = JobStatus::isStatusSuccess( $status )
			? self::handleCompleted( $job_id, $engine_data, true )
			: self::handleFailed( $job_id, $engine_data );
		if ( $prepared ) {
			return $status;
		}

		$reason = JobStatus::isStatusSuccess( $status )
			? 'item_claim_completion_failed'
			: 'item_claim_release_failed';
		return new \WP_Error(
			$reason,
			'Item claim transition failed inside terminal ownership boundary.',
			array( 'status' => JobStatus::failed( $reason )->toString() )
		);
	}

	/**
	 * Release claims attached to batch items that will not be scheduled.
	 *
	 * @param array  $items         Discarded batch items.
	 * @param int    $parent_job_id Parent job ID.
	 * @param string $context       Batch consumer context.
	 * @param array  $cleanup_contexts Sidecar cleanup contexts captured before storage.
	 */
	public static function handleDiscardedPackets( array $items, int $parent_job_id, string $context, array $cleanup_contexts = array() ): void {
		unset( $parent_job_id, $context );
		foreach ( $items as $index => $item ) {
			$item   = DataPacketStore::hydrate_packet_collections_in_value( $item );
			$claims = array_merge(
				self::collectClaimsInValue( $item ),
				self::collectClaimsInValue( $cleanup_contexts[ $index ] ?? array() )
			);
			foreach ( $claims as $claim ) {
				self::releaseClaim( $claim );
			}
		}
	}

	/**
	 * Capture claim descriptors before batch content-addressing.
	 *
	 * @param array $context Existing cleanup context.
	 * @param mixed $item    Batch item before storage.
	 * @return array Cleanup context.
	 */
	public static function captureBatchItemCleanupContext( array $context, mixed $item ): array {
		$context[ ProcessedItems::CLAIMS_METADATA_KEY ] = self::collectClaimsInValue( $item );
		return $context;
	}

	/**
	 * Run an optional registered completion handler and transition the claim.
	 *
	 * @param array $claim  Validated claim descriptor.
	 * @param int   $job_id Completing job ID.
	 * @param bool  $within_transaction Whether the caller owns the terminal transaction.
	 * @return bool Whether the descriptor claim and its callback completed.
	 */
	private static function completeClaim( array $claim, int $job_id, bool $within_transaction = false ): bool {
		$processed  = new ProcessedItems();
		$completion = is_array( $claim['completion'] ?? null ) ? $claim['completion'] : array();
		$handler_id = is_string( $completion['handler'] ?? null ) ? $completion['handler'] : '';
		$payload    = is_array( $completion['payload'] ?? null ) ? $completion['payload'] : array();
		$callback   = null;

		if ( '' !== $handler_id ) {
			$handlers = apply_filters( 'datamachine_item_claim_completion_handlers', array() );
			$handler  = $handlers[ $handler_id ] ?? null;
			if ( ! is_callable( $handler ) ) {
				return false;
			}

			$callback = static fn(): bool => true === call_user_func( $handler, $payload, $job_id, $claim );
		}

		$retain_processed = true;
		if ( isset( $completion['retain_processed'] ) && false === $completion['retain_processed'] ) {
			$retain_processed = false;
		}
		$method = $within_transaction ? 'complete_owned_claim_in_transaction' : 'complete_owned_claim';
		$owned  = $processed->{$method}(
			$claim['identity_scope'],
			$claim['source_type'],
			$claim['item_identifier'],
			$claim['ownership_token'],
			$job_id,
			$callback,
			$retain_processed
		);
		if ( ! $owned ) {
			return false;
		}
		return true;
	}

	/**
	 * Persist metrics now or defer them until the outer transaction commits.
	 */
	private static function recordCompletedMetrics( int $job_id, int $completed, bool $within_transaction ): bool {
		if ( $within_transaction ) {
			self::$pending_processed_metrics[ $job_id ] = $completed;
		} elseif ( 0 < $completed ) {
			RunMetrics::increment( $job_id, 'processed', $completed );
		}

		return true;
	}

	/**
	 * Release one validated claim descriptor.
	 *
	 * @param array               $claim     Validated claim descriptor.
	 * @param ProcessedItems|null $processed Shared repository instance.
	 * @return int|false Number of released rows, or false on error.
	 */
	private static function releaseClaim( array $claim, ?ProcessedItems $processed = null ): int|false {
		$processed = $processed ?? new ProcessedItems();
		return $processed->release_owned_claim(
			$claim['identity_scope'],
			$claim['source_type'],
			$claim['item_identifier'],
			$claim['ownership_token']
		);
	}

	/**
	 * Read claim descriptors from engine data.
	 *
	 * @param array $engine_data Job engine snapshot.
	 * @return array<int,array<string,mixed>> Validated descriptors.
	 */
	private static function claimsFromEngine( array $engine_data ): array {
		return self::normalizeClaims( $engine_data );
	}

	/**
	 * Read singular and collection claim metadata from one container.
	 *
	 * @param array $container Engine data or packet metadata.
	 * @return array<int,array<string,mixed>> Validated descriptors.
	 */
	private static function normalizeClaims( array $container ): array {
		$claims = array();
		$single = self::normalizeClaim( $container[ ProcessedItems::CLAIM_METADATA_KEY ] ?? null );
		if ( null !== $single ) {
			$claims[] = $single;
		}

		$collection = is_array( $container[ ProcessedItems::CLAIMS_METADATA_KEY ] ?? null )
			? $container[ ProcessedItems::CLAIMS_METADATA_KEY ]
			: array();
		foreach ( $collection as $candidate ) {
			$claim = self::normalizeClaim( $candidate );
			if ( null !== $claim ) {
				$claims[] = $claim;
			}
		}

		return self::uniqueClaims( $claims );
	}

	/**
	 * Deduplicate descriptors by ownership token.
	 *
	 * @param array<int,array<string,mixed>> $claims Claim descriptors.
	 * @return array<int,array<string,mixed>> Unique descriptors.
	 */
	private static function uniqueClaims( array $claims ): array {
		$unique = array();
		foreach ( $claims as $claim ) {
			$unique[ $claim['ownership_token'] ] = $claim;
		}
		return array_values( $unique );
	}

	/**
	 * Recursively collect claim metadata from packets or sidecar context.
	 *
	 * @param mixed $value Candidate value.
	 * @return array<int,array<string,mixed>> Validated descriptors.
	 */
	private static function collectClaimsInValue( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$claims = self::normalizeClaims( $value );
		foreach ( $value as $child ) {
			if ( is_array( $child ) ) {
				$claims = array_merge( $claims, self::collectClaimsInValue( $child ) );
			}
		}
		return self::uniqueClaims( $claims );
	}

	/**
	 * Validate a claim lifecycle descriptor.
	 *
	 * @param mixed $claim Candidate descriptor.
	 * @return array|null Validated descriptor, or null.
	 */
	private static function normalizeClaim( mixed $claim ): ?array {
		if ( ! is_array( $claim ) || false === ( $claim['persisted'] ?? true ) ) {
			return null;
		}

		foreach ( array( 'identity_scope', 'source_type', 'item_identifier', 'ownership_token' ) as $key ) {
			if ( ! is_string( $claim[ $key ] ?? null ) || '' === $claim[ $key ] ) {
				return null;
			}
		}

		return $claim;
	}

	/**
	 * Resolve the source-ingestion step ID from a job engine snapshot.
	 *
	 * @param array $engine_data Job engine data.
	 * @return string|null Source ingestion flow step ID, or null when unavailable.
	 */
	public static function resolveSourceIngestionFlowStepId( array $engine_data ): ?string {
		$flow_config = $engine_data['flow_config'] ?? array();
		if ( ! is_array( $flow_config ) ) {
			return null;
		}

		foreach ( $flow_config as $step_id => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}

			$step_type = $config['step_type'] ?? '';
			if ( self::isSourceIngestionStep( (string) $step_type ) ) {
				return (string) $step_id;
			}
		}

		return null;
	}

	/**
	 * Determine whether a step owns source-ingestion dedupe lifecycle behavior.
	 *
	 * @param string $step_type Step type.
	 * @return bool
	 */
	private static function isSourceIngestionStep( string $step_type ): bool {
		return in_array( $step_type, array( 'fetch', 'event_import' ), true );
	}
}
