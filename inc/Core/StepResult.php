<?php
/**
 * Portable step result envelope.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the canonical, packet-neutral result envelope for a single step.
 */
class StepResult {

	public const SCHEMA_VERSION = 'datamachine.step_result.v1';

	/**
	 * Build a portable step result envelope from the legacy execution result.
	 *
	 * @param array<string,mixed> $execution_result Legacy StepExecutionResult array.
	 * @param array<string,mixed> $context          Optional outputs/artifact refs/replay context.
	 * @return array<string,mixed>
	 */
	public static function fromExecutionResult( array $execution_result, array $context = array() ): array {
		$outputs       = self::normalizeAssociativeArray( $context['outputs'] ?? array() );
		$artifact_refs = self::normalizeList( $context['artifact_refs'] ?? ( $context['artifacts'] ?? array() ) );
		$packet_refs   = array_merge(
			self::packetRefsFromPackets( is_array( $execution_result['packets'] ?? null ) ? $execution_result['packets'] : array() ),
			self::normalizeList( $context['packet_refs'] ?? array() )
		);
		$diagnostics   = self::normalizeAssociativeArray( $execution_result['diagnostics'] ?? array() );

		if ( ! isset( $diagnostics['reason'] ) && is_scalar( $execution_result['reason'] ?? null ) ) {
			$diagnostics['reason'] = (string) $execution_result['reason'];
		}

		if ( ! isset( $diagnostics['error'] ) && is_scalar( $execution_result['error'] ?? null ) ) {
			$diagnostics['error'] = (string) $execution_result['error'];
		}

		if ( ! array_key_exists( 'packet_count', $outputs ) ) {
			$outputs['packet_count'] = count( is_array( $execution_result['packets'] ?? null ) ? $execution_result['packets'] : array() );
		}

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'status'         => is_scalar( $execution_result['status'] ?? null ) ? (string) $execution_result['status'] : 'failed',
			'outputs'        => $outputs,
			'artifact_refs'  => $artifact_refs,
			'packet_refs'    => $packet_refs,
			'diagnostics'    => $diagnostics,
			'replay'         => self::buildReplayMetadata( $outputs, $artifact_refs, $packet_refs, self::normalizeAssociativeArray( $context['replay'] ?? array() ) ),
		);
	}

	/**
	 * Build deterministic packet references without embedding transport packets.
	 *
	 * @param array<int,mixed> $packets Step output packets.
	 * @return array<int,array<string,mixed>>
	 */
	private static function packetRefsFromPackets( array $packets ): array {
		$refs = array();

		foreach ( array_values( $packets ) as $index => $packet ) {
			if ( ! is_array( $packet ) ) {
				continue;
			}

			$metadata = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();
			$ref      = array(
				'index'        => $index,
				'type'         => is_scalar( $packet['type'] ?? null ) ? (string) $packet['type'] : '',
				'content_hash' => self::contentHash( $packet ),
			);

			foreach ( array( 'source_type', 'source_id', 'source_item_id', 'job_id', 'flow_step_id' ) as $key ) {
				if ( is_scalar( $metadata[ $key ] ?? null ) ) {
					$ref[ $key ] = (string) $metadata[ $key ];
				}
			}

			if ( is_scalar( $packet['timestamp'] ?? null ) ) {
				$ref['timestamp'] = (string) $packet['timestamp'];
			}

			$refs[] = $ref;
		}

		return $refs;
	}

	/**
	 * Build replay metadata with deterministic content hashes where available.
	 *
	 * @param array<string,mixed>     $outputs       Normalized outputs.
	 * @param array<int,mixed>        $artifact_refs Artifact references.
	 * @param array<int,mixed>        $packet_refs   Packet references.
	 * @param array<string,mixed>     $replay        Caller-provided replay metadata.
	 * @return array<string,mixed>
	 */
	private static function buildReplayMetadata( array $outputs, array $artifact_refs, array $packet_refs, array $replay ): array {
		$content_hashes = self::normalizeAssociativeArray( $replay['content_hashes'] ?? array() );

		$content_hashes += array(
			'outputs'       => self::contentHash( $outputs ),
			'artifact_refs' => self::contentHash( $artifact_refs ),
			'packet_refs'   => self::contentHash( $packet_refs ),
		);

		$replay['content_hashes'] = $content_hashes;

		return $replay;
	}

	/**
	 * Compute a stable SHA-256 hash for JSON-serializable content.
	 *
	 * @param mixed $value Value to hash.
	 * @return string Content hash.
	 */
	private static function contentHash( $value ): string {
		$normalized = self::sortKeysRecursive( $value );
		$encoded    = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $encoded ) ) {
			$encoded = '';
		}

		return 'sha256:' . hash( 'sha256', $encoded );
	}

	/**
	 * Sort associative array keys recursively for stable hashes.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed Normalized value.
	 */
	private static function sortKeysRecursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sortKeysRecursive( $item );
		}

		return $value;
	}

	/**
	 * Normalize associative array input.
	 *
	 * @param mixed $value Input value.
	 * @return array<string,mixed>
	 */
	private static function normalizeAssociativeArray( $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Normalize list-like input.
	 *
	 * @param mixed $value Input value.
	 * @return array<int,mixed>
	 */
	private static function normalizeList( $value ): array {
		return is_array( $value ) ? array_values( $value ) : array();
	}
}
