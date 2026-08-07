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
		$preserved_metadata = $this->preserve_result_metadata( $result );
		$truncated          = $result;

		$truncated['result']   = array_merge(
			array(
				'truncated'      => true,
				'excerpt'        => $excerpt,
				'original_bytes' => $original_bytes,
				'excerpt_bytes'  => strlen( $excerpt ),
			),
			$preserved_metadata
		);
		$truncated['metadata'] = array_merge(
			$metadata,
			array(
				'truncated'      => true,
				'original_bytes' => $original_bytes,
				'excerpt_bytes'  => strlen( $excerpt ),
			)
		);

		return array(
			'result'    => $truncated,
			'truncated' => true,
			'metadata'  => array(
				'original_bytes' => $original_bytes,
				'excerpt_bytes'  => strlen( $excerpt ),
			),
		);
	}

	/**
	 * Preserve compact result metadata from oversized result payloads.
	 *
	 * @param array<string,mixed> $result Tool execution result.
	 * @return array<string,mixed> Preserved metadata fields.
	 */
	private function preserve_result_metadata( array $result ): array {
		$payload = isset( $result['result'] ) && is_array( $result['result'] ) ? $result['result'] : array();
		if ( ! array_key_exists( WP_Agent_Citation_Metadata::KEY, $payload ) ) {
			return array();
		}

		return array(
			WP_Agent_Citation_Metadata::KEY => WP_Agent_Citation_Metadata::normalize_many( $payload[ WP_Agent_Citation_Metadata::KEY ] ),
		);
	}
}
