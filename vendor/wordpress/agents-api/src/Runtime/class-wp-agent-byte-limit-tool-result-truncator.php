<?php
/**
 * Byte-limit tool result truncator.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces oversized tool payloads with a compact excerpt and diagnostics.
 */
final class WP_Agent_Byte_Limit_Tool_Result_Truncator implements WP_Agent_Tool_Result_Truncator {

	private int $max_bytes;

	public function __construct( int $max_bytes = 8192 ) {
		$this->max_bytes = max( 1, $max_bytes );
	}

	/** @inheritDoc */
	public function truncate_result( array $result, string $tool_name, array $context = array() ): array {
		unset( $tool_name, $context );

		$encoded = wp_json_encode( $result );
		if ( false === $encoded ) {
			return array(
				'result'    => $result,
				'truncated' => false,
				'metadata'  => array( 'reason' => 'json_encode_failed' ),
			);
		}

		$original_bytes = strlen( (string) $encoded );
		if ( $original_bytes <= $this->max_bytes ) {
			return array(
				'result'    => $result,
				'truncated' => false,
				'metadata'  => array( 'original_bytes' => $original_bytes ),
			);
		}

		$excerpt            = substr( (string) $encoded, 0, $this->max_bytes );
		$metadata           = isset( $result['metadata'] ) && is_array( $result['metadata'] ) ? $result['metadata'] : array();
		$preserved          = $this->preserve_result_metadata( $result );
		$citations_dropped  = $preserved['citations_dropped'];
		$truncated          = $result;

		unset( $metadata['citations_dropped'] );
		if ( array_key_exists( WP_Agent_Citation_Metadata::KEY, $metadata ) ) {
			$bounded_metadata = $this->bound_citations( $metadata[ WP_Agent_Citation_Metadata::KEY ] );
			$citations_dropped += $bounded_metadata['citations_dropped'];
			if ( empty( $bounded_metadata['citations'] ) && $bounded_metadata['citations_dropped'] > 0 ) {
				unset( $metadata[ WP_Agent_Citation_Metadata::KEY ] );
			} else {
				$metadata[ WP_Agent_Citation_Metadata::KEY ] = $bounded_metadata['citations'];
			}
		}

		$truncated['result'] = array_merge(
			array(
				'truncated'      => true,
				'excerpt'        => $excerpt,
				'original_bytes' => $original_bytes,
				'excerpt_bytes'  => strlen( $excerpt ),
			),
			$preserved['metadata']
		);

		$truncated['metadata'] = array_merge(
			$metadata,
			array(
				'truncated'      => true,
				'original_bytes' => $original_bytes,
				'excerpt_bytes'  => strlen( $excerpt ),
			)
		);

		$out_metadata = array(
			'original_bytes' => $original_bytes,
			'excerpt_bytes'  => strlen( $excerpt ),
		);

		if ( $citations_dropped > 0 ) {
			$truncated['result']['citations_dropped']   = $citations_dropped;
			$truncated['metadata']['citations_dropped'] = $citations_dropped;
			$out_metadata['citations_dropped']          = $citations_dropped;
		}

		return array(
			'result'    => $truncated,
			'truncated' => true,
			'metadata'  => $out_metadata,
		);
	}

	/**
	 * Preserve compact result metadata from oversized result payloads.
	 *
	 * Citation metadata is attacker-influenceable and otherwise unbounded, so
	 * the normalized citations are capped to the truncator byte ceiling. The
	 * excerpt keeps its own allotment; trailing citations are dropped (failing
	 * closed to no citations key when even the first citation overflows) once
	 * the encoded list would exceed the budget derived from max_bytes.
	 *
	 * @param array<string,mixed> $result Tool execution result.
	 * @return array{metadata: array<string,mixed>, citations_dropped: int} Preserved metadata plus drop count.
	 */
	private function preserve_result_metadata( array $result ): array {
		$payload = isset( $result['result'] ) && is_array( $result['result'] ) ? $result['result'] : array();
		if ( ! array_key_exists( WP_Agent_Citation_Metadata::KEY, $payload ) ) {
			return array(
				'metadata'          => array(),
				'citations_dropped' => 0,
			);
		}

		$bounded = $this->bound_citations( $payload[ WP_Agent_Citation_Metadata::KEY ] );

		$metadata = array();
		if ( ! empty( $bounded['citations'] ) || 0 === $bounded['citations_dropped'] ) {
			$metadata[ WP_Agent_Citation_Metadata::KEY ] = $bounded['citations'];
		}

		return array(
			'metadata'          => $metadata,
			'citations_dropped' => $bounded['citations_dropped'],
		);
	}

	/**
	 * Normalize and retain the leading citations that fit the byte ceiling.
	 *
	 * @param mixed $citations Raw citation list.
	 * @return array{citations: array<int, array<string,mixed>>, citations_dropped: int}
	 */
	private function bound_citations( $citations ): array {
		if ( ! is_array( $citations ) ) {
			return array(
				'citations'         => array(),
				'citations_dropped' => 0,
			);
		}

		$kept          = array();
		$dropped       = 0;
		$encoded_bytes = 2; // JSON array brackets.
		$overflowed    = false;

		foreach ( $citations as $citation ) {
			if ( ! is_array( $citation ) ) {
				continue;
			}

			$citation = WP_Agent_Citation_Metadata::normalize( $citation );
			if ( empty( $citation ) ) {
				continue;
			}

			if ( $overflowed ) {
				++$dropped;
				continue;
			}

			$encoded   = wp_json_encode( $citation );
			$separator = empty( $kept ) ? 0 : 1;
			if ( false === $encoded || $encoded_bytes + $separator + strlen( (string) $encoded ) > $this->max_bytes ) {
				$overflowed = true;
				++$dropped;
				continue;
			}

			$kept[] = $citation;
			$encoded_bytes += $separator + strlen( (string) $encoded );
		}

		return array(
			'citations'         => $kept,
			'citations_dropped' => $dropped,
		);
	}
}
