<?php
/**
 * Generic compaction conservation metadata.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates whether compacted, retained, and archived items conserve source bytes.
 */
class WP_Agent_Compaction_Conservation {

	/**
	 * Normalize conservation policy fields while preserving caller-owned keys.
	 *
	 * @param array<string, mixed> $policy Raw policy.
	 * @return array<string, mixed>
	 */
	public static function normalize_policy( array $policy ): array {
		$minimum_conserved_byte_ratio = $policy['minimum_conserved_byte_ratio'] ?? 1.0;
		if ( ! is_int( $minimum_conserved_byte_ratio ) && ! is_float( $minimum_conserved_byte_ratio ) && ! is_string( $minimum_conserved_byte_ratio ) ) {
			$minimum_conserved_byte_ratio = 1.0;
		}

		$policy['conservation_enabled']         = (bool) ( $policy['conservation_enabled'] ?? false );
		$policy['minimum_conserved_byte_ratio'] = max( 0.0, (float) $minimum_conserved_byte_ratio );
		$policy['fail_on_conservation_failure'] = (bool) ( $policy['fail_on_conservation_failure'] ?? true );

		return $policy;
	}

	/**
	 * Build provenance and conservation metadata for generic compaction items.
	 *
	 * @param array<string, mixed>    $policy          Compaction policy.
	 * @param array<int, mixed>       $original_items  Original items.
	 * @param array<int, mixed>       $compacted_items Compacted items.
	 * @param array<int, mixed>       $retained_items  Retained items.
	 * @param array<int, mixed>       $archived_items  Archived items.
	 * @param array<string, mixed>    $extra           Extra metadata.
	 * @param array<string, int>|null $original_stats  Optional precomputed original stats.
	 * @param array<string, int>|null $compacted_stats Optional precomputed compacted stats.
	 * @param array<string, int>|null $retained_stats  Optional precomputed retained stats.
	 * @param array<string, int>|null $archived_stats  Optional precomputed archived stats.
	 * @return array<string, mixed>
	 */
	public static function metadata( array $policy, array $original_items = array(), array $compacted_items = array(), array $retained_items = array(), array $archived_items = array(), array $extra = array(), ?array $original_stats = null, ?array $compacted_stats = null, ?array $retained_stats = null, ?array $archived_stats = null ): array {
		$policy          = self::normalize_policy( $policy );
		$original_stats  = self::normalize_stats( $original_stats ?? self::item_stats( $original_items ) );
		$compacted_stats = self::normalize_stats( $compacted_stats ?? self::item_stats( $compacted_items ) );
		$retained_stats  = self::normalize_stats( $retained_stats ?? self::item_stats( $retained_items ) );
		$archived_stats  = self::normalize_stats( $archived_stats ?? self::item_stats( $archived_items ) );

		$conserved_bytes = $compacted_stats['byte_count'] + $retained_stats['byte_count'] + $archived_stats['byte_count'];
		$minimum_ratio   = is_float( $policy['minimum_conserved_byte_ratio'] ) ? $policy['minimum_conserved_byte_ratio'] : 1.0;
		$enabled         = true === $policy['conservation_enabled'];
		$fail_closed     = true === $policy['fail_on_conservation_failure'];
		$required_bytes  = (int) ceil( $original_stats['byte_count'] * $minimum_ratio );
		$passed          = ! $enabled || $conserved_bytes >= $required_bytes;

		return array_merge(
			$extra,
			array(
				'policy'       => $policy,
				'provenance'   => array(
					'original'  => $original_stats,
					'compacted' => $compacted_stats,
					'retained'  => $retained_stats,
					'archived'  => $archived_stats,
				),
				'summarizer'   => array(
					'provider' => is_string( $policy['summary_provider'] ?? null ) ? $policy['summary_provider'] : '',
					'model'    => is_string( $policy['summary_model'] ?? null ) ? $policy['summary_model'] : '',
				),
				'conservation' => array(
					'enabled'                      => $enabled,
					'minimum_conserved_byte_ratio' => $minimum_ratio,
					'required_byte_count'          => $required_bytes,
					'conserved_byte_count'         => $conserved_bytes,
					'conserved_byte_ratio'         => 0 === $original_stats['byte_count'] ? 1.0 : $conserved_bytes / $original_stats['byte_count'],
					'passed'                       => $passed,
					'failed_closed'                => $enabled && $fail_closed && ! $passed,
				),
			)
		);
	}

	/**
	 * Determine whether metadata represents a fail-closed conservation failure.
	 *
	 * @param array<string, mixed> $metadata Compaction metadata.
	 * @return bool
	 */
	public static function failed_closed( array $metadata ): bool {
		$conservation = $metadata['conservation'] ?? array();
		if ( ! is_array( $conservation ) ) {
			return false;
		}

		return true === ( $conservation['failed_closed'] ?? false );
	}

	/**
	 * Count items and content bytes for generic provenance metadata.
	 *
	 * @param array<int, mixed> $items Items.
	 * @return array{item_count: int, byte_count: int}
	 */
	public static function item_stats( array $items ): array {
		$bytes = 0;

		foreach ( $items as $item ) {
			$bytes += self::item_bytes( $item );
		}

		return array(
			'item_count' => count( $items ),
			'byte_count' => $bytes,
		);
	}

	/**
	 * Normalize precomputed stats.
	 *
	 * @param array<string, int> $stats Stats.
	 * @return array{item_count: int, byte_count: int}
	 */
	private static function normalize_stats( array $stats ): array {
		return array(
			'item_count' => max( 0, (int) ( $stats['item_count'] ?? 0 ) ),
			'byte_count' => max( 0, (int) ( $stats['byte_count'] ?? 0 ) ),
		);
	}

	/**
	 * Count bytes for an item's durable content.
	 *
	 * @param mixed $item Item.
	 * @return int
	 */
	private static function item_bytes( $item ): int {
		$content = is_array( $item ) && array_key_exists( 'content', $item ) ? $item['content'] : $item;

		if ( is_string( $content ) ) {
			return strlen( $content );
		}

		$encoded = self::json_encode( $content );
		return false === $encoded ? 0 : strlen( $encoded );
	}

	/**
	 * Encode data with a pure-PHP fallback for smoke tests.
	 *
	 * @param mixed $data Data.
	 * @return string|false
	 */
	private static function json_encode( $data ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $data );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-PHP smoke tests run without WordPress loaded.
		return json_encode( $data );
	}
}
