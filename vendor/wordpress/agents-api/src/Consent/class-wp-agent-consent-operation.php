<?php
/**
 * Agent consent operation vocabulary.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes generic consent operation names.
 */
final class WP_Agent_Consent_Operation {

	/** Store consolidated agent memory. */
	public const STORE_MEMORY = 'store_memory';

	/** Use existing agent memory during a run. */
	public const USE_MEMORY = 'use_memory';

	/** Store a raw conversation transcript. */
	public const STORE_TRANSCRIPT = 'store_transcript';

	/** Share a raw conversation transcript outside its owning context. */
	public const SHARE_TRANSCRIPT = 'share_transcript';

	/** Escalate a run or transcript to a human/support adapter. */
	public const ESCALATE_TO_HUMAN = 'escalate_to_human';

	/**
	 * Return all valid operation values.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::STORE_MEMORY,
			self::USE_MEMORY,
			self::STORE_TRANSCRIPT,
			self::SHARE_TRANSCRIPT,
			self::ESCALATE_TO_HUMAN,
		);
	}

	/**
	 * Determine whether a raw value is a recognized operation.
	 *
	 * @param mixed $value Raw operation value.
	 * @return bool
	 */
	public static function isValid( $value ): bool {
		return null !== self::normalize( $value );
	}

	/**
	 * Normalize a raw operation value.
	 *
	 * @param mixed       $value    Raw operation value.
	 * @param string|null $fallback Optional fallback used when value is invalid.
	 * @return string|null One of the operation constants, or null when invalid.
	 */
	public static function normalize( $value, ?string $fallback = null ): ?string {
		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );
			if ( in_array( $normalized, self::all(), true ) ) {
				return $normalized;
			}
		}

		if ( null === $fallback ) {
			return null;
		}

		return self::normalize( $fallback );
	}
}
