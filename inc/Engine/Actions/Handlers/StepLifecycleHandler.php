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
	 * @return bool Whether all owned descriptor and legacy claims completed.
	 */
	public static function handleCompleted( int $job_id, ?array $engine_data = null ): bool {
		$engine_data = is_array( $engine_data ) ? $engine_data : \datamachine_get_engine_data( $job_id );
		$claims      = self::claimsFromEngine( $engine_data );
		if ( ! empty( $claims ) ) {
			foreach ( $claims as $claim ) {
				if ( ! self::completeClaim( $claim, $job_id ) ) {
					return false;
				}
			}
		}

		$item_identifier = $engine_data['item_identifier'] ?? null;
		$source_type     = $engine_data['source_type'] ?? null;
		if ( empty( $item_identifier ) || empty( $source_type ) ) {
			return true;
		}

		$source_flow_step_id = self::resolveSourceIngestionFlowStepId( $engine_data );
		if ( empty( $source_flow_step_id ) ) {
			return true;
		}

		$legacy_completed = ( new ProcessedItems() )->complete_claim_for_job(
			$source_flow_step_id,
			(string) $source_type,
			(string) $item_identifier,
			$job_id
		);
		if ( false === $legacy_completed ) {
			return false;
		}
		if ( 0 < $legacy_completed ) {
			RunMetrics::increment( $job_id, 'processed' );
		}

		return true;
	}

	/**
	 * Release source-item claims when a job fails.
	 *
	 * @param int        $job_id      Failed job ID.
	 * @param array|null $engine_data Optional engine data snapshot.
	 */
	public static function handleFailed( int $job_id, ?array $engine_data = null ): void {
		$engine_data = is_array( $engine_data ) ? $engine_data : \datamachine_get_engine_data( $job_id );
		$claims      = self::claimsFromEngine( $engine_data );
		if ( ! empty( $claims ) ) {
			foreach ( $claims as $claim ) {
				self::releaseClaim( $claim );
			}
		}

		// Pre-descriptor and partially migrated jobs still own claims by job_id.
		// Reacquisition replaces job_id, so this cannot release a newer worker's row.
		( new ProcessedItems() )->release_claims_for_job( $job_id );
	}

	/**
	 * Apply claim lifecycle for every terminal job transition.
	 *
	 * @param int    $job_id Terminal job ID.
	 * @param string $status Final job status.
	 */
	public static function handleTerminal( int $job_id, string $status ): void {
		if ( JobStatus::isStatusSuccess( $status ) ) {
			return;
		}

		\do_action( 'datamachine_step_lifecycle_failed', $job_id, \datamachine_get_engine_data( $job_id ) );
	}

	/**
	 * Complete owned claims before a successful terminal status is persisted.
	 *
	 * @param string $status Requested terminal status.
	 * @param int    $job_id Job ID.
	 * @param array  $job    Current job row.
	 * @return string Original success status or an observable failure status.
	 */
	public static function filterTerminalStatus( string $status, int $job_id, array $job ): string {
		unset( $job );
		if ( ! JobStatus::isStatusSuccess( $status ) ) {
			return $status;
		}

		if ( self::handleCompleted( $job_id ) ) {
			return $status;
		}

		return JobStatus::failed( 'item_claim_completion_failed' )->toString();
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
	 * @return bool Whether the descriptor claim and its callback completed.
	 */
	private static function completeClaim( array $claim, int $job_id ): bool {
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

		$owned = $processed->complete_owned_claim(
			$claim['identity_scope'],
			$claim['source_type'],
			$claim['item_identifier'],
			$claim['ownership_token'],
			$job_id,
			$callback,
			false !== ( $completion['retain_processed'] ?? true )
		);
		if ( ! $owned ) {
			return false;
		}
		RunMetrics::increment( $job_id, 'processed' );
		return true;
	}

	/**
	 * Release one validated claim descriptor.
	 *
	 * @param array $claim Validated claim descriptor.
	 */
	private static function releaseClaim( array $claim ): void {
		( new ProcessedItems() )->release_owned_claim(
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
