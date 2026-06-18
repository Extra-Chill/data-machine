<?php
/**
 * Portable run result envelope.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the canonical result envelope for a deterministic run.
 */
class RunResult {

	public const SCHEMA_VERSION = 'datamachine.run_result.v1';

	/**
	 * Build a run envelope from step result envelopes.
	 *
	 * @param array<int,array<string,mixed>> $step_results StepResult envelopes.
	 * @param array<string,mixed>            $context      Optional outputs/artifact refs/replay context.
	 * @return array<string,mixed>
	 */
	public static function fromStepResults( array $step_results, array $context = array() ): array {
		$steps         = array_values( array_filter( $step_results, fn( $step_result ) => is_array( $step_result ) ) );
		$status        = self::deriveStatus( $steps, $context['status'] ?? null );
		$outputs       = is_array( $context['outputs'] ?? null ) ? $context['outputs'] : array();
		$artifact_refs = self::mergeRefs( $steps, 'artifact_refs', $context['artifact_refs'] ?? ( $context['artifacts'] ?? array() ) );
		$packet_refs   = self::mergeRefs( $steps, 'packet_refs', $context['packet_refs'] ?? array() );
		$diagnostics   = is_array( $context['diagnostics'] ?? null ) ? $context['diagnostics'] : array();

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'status'         => $status,
			'outputs'        => $outputs,
			'artifact_refs'  => $artifact_refs,
			'packet_refs'    => $packet_refs,
			'diagnostics'    => $diagnostics,
			'replay'         => self::buildReplayMetadata( $steps, $outputs, $artifact_refs, $packet_refs, is_array( $context['replay'] ?? null ) ? $context['replay'] : array() ),
			'steps'          => $steps,
		);
	}

	/**
	 * Derive an aggregate run status from step envelopes.
	 *
	 * @param array<int,array<string,mixed>> $steps           Step envelopes.
	 * @param mixed                          $explicit_status Caller-provided status.
	 * @return string Run status.
	 */
	private static function deriveStatus( array $steps, $explicit_status ): string {
		if ( is_scalar( $explicit_status ) && '' !== trim( (string) $explicit_status ) ) {
			return trim( (string) $explicit_status );
		}

		foreach ( $steps as $step ) {
			if ( 'failed' === ( $step['status'] ?? '' ) || 'blocked' === ( $step['status'] ?? '' ) ) {
				return (string) $step['status'];
			}
		}

		return array() === $steps ? 'completed_no_items' : 'succeeded';
	}

	/**
	 * Merge caller refs with refs from every step envelope.
	 *
	 * @param array<int,array<string,mixed>> $steps Step envelopes.
	 * @param string                         $key   Envelope ref key.
	 * @param mixed                          $refs  Caller refs.
	 * @return array<int,mixed>
	 */
	private static function mergeRefs( array $steps, string $key, $refs ): array {
		$merged = is_array( $refs ) ? array_values( $refs ) : array();

		foreach ( $steps as $step ) {
			if ( is_array( $step[ $key ] ?? null ) ) {
				$merged = array_merge( $merged, array_values( $step[ $key ] ) );
			}
		}

		return $merged;
	}

	/**
	 * Build replay metadata with deterministic content hashes.
	 *
	 * @param array<int,array<string,mixed>> $steps         Step envelopes.
	 * @param array<string,mixed>            $outputs       Run outputs.
	 * @param array<int,mixed>               $artifact_refs Artifact refs.
	 * @param array<int,mixed>               $packet_refs   Packet refs.
	 * @param array<string,mixed>            $replay        Replay metadata.
	 * @return array<string,mixed>
	 */
	private static function buildReplayMetadata( array $steps, array $outputs, array $artifact_refs, array $packet_refs, array $replay ): array {
		$content_hashes  = is_array( $replay['content_hashes'] ?? null ) ? $replay['content_hashes'] : array();
		$content_hashes += array(
			'steps'         => self::contentHash( $steps ),
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
		$encoded = wp_json_encode( self::sortKeysRecursive( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

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
}
