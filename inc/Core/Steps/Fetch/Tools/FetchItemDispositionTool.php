<?php
/**
 * Fetch Item Disposition Tool
 *
 * Handler tool that allows the pipeline agent to explicitly reject or defer
 * a fetched source item. The terminal job lifecycle completes rejected claims
 * or releases deferred claims after the disposition status is committed.
 *
 * This provides a safety net when keyword exclusions or other filters
 * miss items that shouldn't be processed (e.g., non-music events).
 *
 * @package DataMachine\Core\Steps\Fetch\Tools
 * @since 0.9.7
 */

namespace DataMachine\Core\Steps\Fetch\Tools;

use DataMachine\Core\JobStatus;
use DataMachine\Core\RunMetrics;
use DataMachine\Core\EngineData;

defined( 'ABSPATH' ) || exit;

class FetchItemDispositionTool {

	private const DISPOSITION_REJECT_SOURCE = 'reject_source';
	private const DISPOSITION_DEFER_ITEM    = 'defer_item';
	private const MAX_DIAGNOSTIC_CHARS      = 1200;

	/**
	 * Handle the reject_source/defer_item tool call.
	 *
	 * @param array $parameters Tool parameters from AI (reason required).
	 * @param array $tool_def Tool definition with handler_config.
	 * @return array Tool result with success status.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$disposition = $this->getDisposition( $tool_def );
		if ( self::DISPOSITION_DEFER_ITEM === $disposition ) {
			return $this->deferItem( $parameters, $tool_def );
		}

		return $this->rejectSource( $parameters, $tool_def );
	}

	/**
	 * Mark the current source item as rejected.
	 *
	 * @param array $parameters Tool parameters from AI.
	 * @param array $tool_def Tool definition with handler_config.
	 * @return array Tool result with success status.
	 */
	private function rejectSource( array $parameters, array $tool_def ): array {
		unset( $tool_def );

		$reason    = trim( $parameters['reason'] ?? '' );
		$tool_name = self::DISPOSITION_REJECT_SOURCE;

		if ( empty( $reason ) ) {
			return array(
				'success'   => false,
				'error'     => 'reason parameter is required - explain why this source is being rejected',
				'tool_name' => $tool_name,
			);
		}

		$job_id = (int) ( $parameters['job_id'] ?? 0 );
		if ( ! $job_id ) {
			return array(
				'success'   => false,
				'error'     => 'job_id is required for reject_source operations',
				'tool_name' => $tool_name,
			);
		}

		// Dispositions are first-write-wins: never overwrite an earlier terminal disposition.
		$existing = $this->existingDisposition( $job_id );
		if ( null !== $existing ) {
			return $this->alreadyDispositionedResult( $tool_name, $existing, $job_id );
		}

		// Get engine data for item identification.
		$engine = $this->resolveEngineData( $parameters, $job_id );
		if ( ! $engine ) {
			return array(
				'success'   => false,
				'error'     => 'Engine context not available',
				'tool_name' => $tool_name,
			);
		}

		// Get item identifier and source type from engine data (set by fetch handler)
		$item_identifier = $engine->get( 'item_identifier' );
		$source_type     = $engine->get( 'source_type' );
		$flow_step_id    = $this->resolveFetchFlowStepId( $engine ) ?? ( $parameters['flow_step_id'] ?? $engine->get( 'flow_step_id' ) );
		$diagnostic      = $this->buildDispositionDiagnostic( self::DISPOSITION_REJECT_SOURCE, $tool_name, $reason, $flow_step_id, $item_identifier, $source_type, $parameters );

		// Set job status override for engine to use at completion
		$status = JobStatus::agentSkipped( 'source-rejected' );
		datamachine_merge_engine_data(
			$job_id,
			array(
				'job_status'             => $status->toString(),
				'disposition_diagnostic' => $diagnostic,
				'source_rejection'       => array(
					'reason'          => $reason,
					'flow_step_id'    => $flow_step_id,
					'item_identifier' => $item_identifier,
					'source_type'     => $source_type,
					'diagnostic'      => $diagnostic,
				),
			)
		);

		if ( $flow_step_id && class_exists( RunMetrics::class ) ) {
			RunMetrics::recordStepResult(
				$job_id,
				(string) $flow_step_id,
				array(
					'step_type'               => 'fetch',
					'result'                  => 'source_rejected',
					'packet_count'            => 0,
					'source_rejection_reason' => $reason,
					'source_type'             => $source_type,
					'item_identifier'         => $item_identifier,
				)
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'FetchItemDispositionTool: Job status set to source rejected',
			array(
				'job_id' => $job_id,
				'status' => $status->toString(),
				'reason' => $reason,
			)
		);

		return array(
			'success'         => true,
			'message'         => "Source rejected: {$reason}",
			'status'          => $status->toString(),
			'item_identifier' => $item_identifier,
			'tool_name'       => $tool_name,
			'disposition'     => self::DISPOSITION_REJECT_SOURCE,
			'reason'          => $reason,
		);
	}

	/**
	 * Defer the current source item without marking it processed.
	 *
	 * @param array $parameters Tool parameters from AI.
	 * @param array $tool_def Tool definition with handler_config.
	 * @return array Tool result with success status.
	 */
	private function deferItem( array $parameters, array $tool_def ): array {
		unset( $tool_def );

		$reason    = trim( $parameters['reason'] ?? '' );
		$tool_name = self::DISPOSITION_DEFER_ITEM;

		if ( empty( $reason ) ) {
			return array(
				'success'   => false,
				'error'     => 'reason parameter is required - explain why this item cannot be safely completed now',
				'tool_name' => $tool_name,
			);
		}

		$job_id = (int) ( $parameters['job_id'] ?? 0 );
		if ( ! $job_id ) {
			return array(
				'success'   => false,
				'error'     => 'job_id is required for defer_item operations',
				'tool_name' => $tool_name,
			);
		}

		// Dispositions are first-write-wins: defer_item must never downgrade an
		// earlier rejection (agent_skipped - source-rejected) to a failed status.
		$existing = $this->existingDisposition( $job_id );
		if ( null !== $existing ) {
			return $this->alreadyDispositionedResult( $tool_name, $existing, $job_id );
		}

		$engine = $this->resolveEngineData( $parameters, $job_id );
		if ( ! $engine ) {
			return array(
				'success'   => false,
				'error'     => 'Engine context not available',
				'tool_name' => $tool_name,
			);
		}

		$item_identifier = $engine->get( 'item_identifier' );
		$source_type     = $engine->get( 'source_type' );
		$flow_step_id    = $this->resolveFetchFlowStepId( $engine ) ?? ( $parameters['flow_step_id'] ?? $engine->get( 'flow_step_id' ) );
		$diagnostic      = $this->buildDispositionDiagnostic( self::DISPOSITION_DEFER_ITEM, $tool_name, $reason, $flow_step_id, $item_identifier, $source_type, $parameters );

		$status = JobStatus::failed( 'item-deferred' );
		datamachine_merge_engine_data(
			$job_id,
			array(
				'job_status'             => $status->toString(),
				'disposition_diagnostic' => $diagnostic,
				'item_deferral'          => array(
					'reason'          => $reason,
					'flow_step_id'    => $flow_step_id,
					'item_identifier' => $item_identifier,
					'source_type'     => $source_type,
					'diagnostic'      => $diagnostic,
				),
			)
		);

		if ( $flow_step_id && class_exists( RunMetrics::class ) ) {
			RunMetrics::recordStepResult(
				$job_id,
				(string) $flow_step_id,
				array(
					'step_type'       => 'fetch',
					'result'          => 'item_deferred',
					'packet_count'    => 0,
					'reason'          => 'item-deferred',
					'source_type'     => $source_type,
					'item_identifier' => $item_identifier,
				)
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'FetchItemDispositionTool: Item deferred for terminal claim release',
			array(
				'job_id'          => $job_id,
				'flow_step_id'    => $flow_step_id,
				'item_identifier' => $item_identifier,
				'source_type'     => $source_type,
				'reason'          => $reason,
				'status'          => $status->toString(),
			)
		);

		return array(
			'success'         => true,
			'message'         => "Item deferred for retry: {$reason}",
			'status'          => $status->toString(),
			'item_identifier' => $item_identifier,
			'tool_name'       => $tool_name,
			'disposition'     => self::DISPOSITION_DEFER_ITEM,
			'reason'          => $reason,
		);
	}

	/**
	 * Return the disposition already recorded for a job, if any.
	 *
	 * Reads the persisted engine snapshot rather than the runtime engine object
	 * because the runtime snapshot may predate an earlier disposition call in
	 * the same conversation.
	 *
	 * @param int $job_id Job ID.
	 * @return array{disposition:string,job_status:string,reason:string}|null
	 */
	private function existingDisposition( int $job_id ): ?array {
		$engine_data = EngineData::retrieve( $job_id );

		$diagnostic  = is_array( $engine_data['disposition_diagnostic'] ?? null ) ? $engine_data['disposition_diagnostic'] : array();
		$disposition = (string) ( $diagnostic['disposition'] ?? '' );

		if ( '' === $disposition && isset( $engine_data['source_rejection'] ) ) {
			$disposition = self::DISPOSITION_REJECT_SOURCE;
		}

		if ( '' === $disposition && isset( $engine_data['item_deferral'] ) ) {
			$disposition = self::DISPOSITION_DEFER_ITEM;
		}

		if ( '' === $disposition ) {
			return null;
		}

		$record = self::DISPOSITION_DEFER_ITEM === $disposition
			? ( is_array( $engine_data['item_deferral'] ?? null ) ? $engine_data['item_deferral'] : array() )
			: ( is_array( $engine_data['source_rejection'] ?? null ) ? $engine_data['source_rejection'] : array() );

		return array(
			'disposition' => $disposition,
			'job_status'  => (string) ( $engine_data['job_status'] ?? '' ),
			'reason'      => (string) ( $record['reason'] ?? $diagnostic['reason'] ?? '' ),
		);
	}

	/**
	 * Build the idempotent success result for an already-dispositioned job.
	 *
	 * Returned as success so the model is not pushed into error-retry loops;
	 * the original disposition remains authoritative (first-write-wins).
	 *
	 * @param string                                              $tool_name Tool that attempted the second disposition.
	 * @param array{disposition:string,job_status:string,reason:string} $existing  Existing disposition record.
	 * @param int                                                 $job_id    Job ID.
	 * @return array Tool result.
	 */
	private function alreadyDispositionedResult( string $tool_name, array $existing, int $job_id ): array {
		$label = self::DISPOSITION_DEFER_ITEM === $existing['disposition'] ? 'item-deferred' : 'source-rejected';

		do_action(
			'datamachine_log',
			'info',
			'FetchItemDispositionTool: Ignoring repeat disposition call - job already dispositioned',
			array(
				'job_id'               => $job_id,
				'attempted_tool'       => $tool_name,
				'existing_disposition' => $existing['disposition'],
				'existing_job_status'  => $existing['job_status'],
			)
		);

		return array(
			'success'              => true,
			'message'              => "Item already dispositioned ({$label}); no further action needed.",
			'status'               => $existing['job_status'],
			'tool_name'            => $tool_name,
			'disposition'          => $existing['disposition'],
			'reason'               => $existing['reason'],
			'already_dispositioned' => true,
		);
	}

	/**
	 * Return the disposition encoded by the resolved tool definition.
	 *
	 * @param array $tool_def Tool definition.
	 * @return string
	 */
	private function getDisposition( array $tool_def ): string {
		$disposition = $tool_def['disposition'] ?? self::DISPOSITION_REJECT_SOURCE;

		return self::DISPOSITION_DEFER_ITEM === $disposition ? self::DISPOSITION_DEFER_ITEM : self::DISPOSITION_REJECT_SOURCE;
	}

	/**
	 * Resolve engine data from explicit runtime context or persisted job state.
	 *
	 * @param array<string,mixed> $parameters Tool parameters.
	 * @param int                 $job_id Job ID.
	 * @return object|null Engine-like object exposing get(), or null.
	 */
	private function resolveEngineData( array $parameters, int $job_id ): ?object {
		$engine = $parameters['engine'] ?? null;
		if ( is_object( $engine ) && method_exists( $engine, 'get' ) ) {
			return $engine;
		}

		if ( $job_id <= 0 ) {
			return null;
		}

		return EngineData::forJob( $job_id );
	}

	/**
	 * Resolve the source fetch/event_import step ID from engine flow config.
	 *
	 * @param object $engine Engine data wrapper.
	 * @return string|null Fetch step ID, or null when unavailable.
	 */
	private function resolveFetchFlowStepId( object $engine ): ?string {
		$flow_config = $engine->get( 'flow_config' );
		if ( ! is_array( $flow_config ) ) {
			return null;
		}

		foreach ( $flow_config as $step_id => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}

			if ( in_array( $config['step_type'] ?? '', array( 'fetch', 'event_import' ), true ) ) {
				return (string) $step_id;
			}
		}

		return null;
	}

	/**
	 * Build bounded, generic evidence for skipped/deferred source decisions.
	 *
	 * @param string     $disposition     Disposition slug.
	 * @param string     $tool_name       Tool name.
	 * @param string     $reason          Operator-visible disposition reason.
	 * @param mixed      $flow_step_id    Flow step ID.
	 * @param mixed      $item_identifier Source item identifier.
	 * @param mixed      $source_type     Source type.
	 * @param array      $parameters      Tool parameters including current data packets.
	 * @return array<string,mixed>
	 */
	private function buildDispositionDiagnostic( string $disposition, string $tool_name, string $reason, mixed $flow_step_id, mixed $item_identifier, mixed $source_type, array $parameters ): array {
		$packets       = is_array( $parameters['data'] ?? null ) ? $parameters['data'] : array();
		$packet        = $this->selectDiagnosticPacket( $packets, $item_identifier );
		$packet_data   = is_array( $packet['data'] ?? null ) ? $packet['data'] : array();
		$metadata      = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();
		$excerpt       = $this->packetExcerpt( $packet_data );
		$bounded       = $this->boundAndRedact( $excerpt );
		$diagnostic    = array(
			'type'            => 'pipeline_disposition_diagnostic',
			'disposition'     => $disposition,
			'reason'          => $this->cleanScalar( $reason ),
			'tool_name'       => $this->cleanScalar( $tool_name ),
			'item_identifier' => $this->cleanScalar( $item_identifier ),
			'source_url'      => $this->sourceUrlFromMetadata( $metadata ),
			'provider'        => $this->providerFromMetadata( $metadata ),
			'source_type'     => $this->cleanScalar( $source_type ? $source_type : ( $metadata['source_type'] ?? '' ) ),
			'flow_step_id'    => $this->cleanScalar( $flow_step_id ),
			'packet_count'    => count( $packets ),
			'excerpt'         => $bounded['text'],
			'excerpt_chars'   => strlen( $bounded['text'] ),
			'excerpt_limit'   => self::MAX_DIAGNOSTIC_CHARS,
			'truncated'       => $bounded['truncated'],
		);

		if ( '' === $diagnostic['excerpt'] ) {
			$diagnostic['diagnostic'] = $this->packetShapeDiagnostic( $packet );
			unset( $diagnostic['excerpt'], $diagnostic['excerpt_chars'], $diagnostic['truncated'] );
		}

		return array_filter(
			$diagnostic,
			static fn( $value ) => null !== $value && '' !== $value && array() !== $value
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $packets Data packets.
	 * @param mixed                          $item_identifier Current item identifier.
	 * @return array<string,mixed>
	 */
	private function selectDiagnosticPacket( array $packets, mixed $item_identifier ): array {
		$item_identifier = (string) $item_identifier;
		foreach ( $packets as $packet ) {
			if ( ! is_array( $packet ) ) {
				continue;
			}

			$metadata = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();
			if ( '' !== $item_identifier && $item_identifier === (string) ( $metadata['item_identifier'] ?? '' ) ) {
				return $packet;
			}
		}

		$first = reset( $packets );
		return is_array( $first ) ? $first : array();
	}

	/**
	 * @param array<string,mixed> $data Packet data.
	 */
	private function packetExcerpt( array $data ): string {
		$parts = array();
		foreach ( array( 'title', 'body', 'content', 'text', 'description', 'summary' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || is_array( $data[ $key ] ) || is_object( $data[ $key ] ) ) {
				continue;
			}

			$value = trim( (string) $data[ $key ] );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}

		return trim( implode( "\n\n", $parts ) );
	}

	/**
	 * @return array{text:string,truncated:bool}
	 */
	private function boundAndRedact( string $text ): array {
		$text = wp_strip_all_tags( $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
		$text = preg_replace( '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/-]+=*/i', '$1 [redacted]', $text ) ?? $text;
		$text = preg_replace( '/\b(api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|password|secret)\b\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $text ) ?? $text;
		$text = preg_replace( '/([?&](?:token|access_token|key|api_key|secret|password)=)[^&\s]+/i', '$1[redacted]', $text ) ?? $text;

		$truncated = strlen( $text ) > self::MAX_DIAGNOSTIC_CHARS;
		if ( $truncated ) {
			$text = substr( $text, 0, self::MAX_DIAGNOSTIC_CHARS );
		}

		return array(
			'text'      => $text,
			'truncated' => $truncated,
		);
	}

	/**
	 * @param array<string,mixed> $metadata Packet metadata.
	 */
	private function sourceUrlFromMetadata( array $metadata ): string {
		foreach ( array( 'source_url', 'url', 'permalink', 'link', 'guid' ) as $key ) {
			if ( ! empty( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) ) {
				return $this->redactScalar( (string) $metadata[ $key ] );
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $metadata Packet metadata.
	 */
	private function providerFromMetadata( array $metadata ): string {
		foreach ( array( 'provider_id', 'provider_slug', 'provider', 'auth_provider', 'server_key', 'context_server_key', 'handler' ) as $key ) {
			if ( ! empty( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) ) {
				return $this->cleanScalar( (string) $metadata[ $key ] );
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $packet Packet data.
	 */
	private function packetShapeDiagnostic( array $packet ): string {
		$data     = is_array( $packet['data'] ?? null ) ? $packet['data'] : array();
		$metadata = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();

		return sprintf(
			'No textual packet excerpt available; packet keys: %s; metadata keys: %s',
			implode( ',', array_slice( array_map( 'strval', array_keys( $data ) ), 0, 12 ) ),
			implode( ',', array_slice( array_map( 'strval', array_keys( $metadata ) ), 0, 12 ) )
		);
	}

	private function cleanScalar( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( preg_replace( '/\s+/u', ' ', (string) $value ) ?? '' );
	}

	private function redactScalar( string $value ): string {
		$bounded = $this->boundAndRedact( $value );
		return $bounded['text'];
	}
}
