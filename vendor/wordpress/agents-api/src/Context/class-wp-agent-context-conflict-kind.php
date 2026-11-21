<?php
/**
 * Context Conflict Kind
 *
 * Generic vocabulary for conflict semantics in retrieved context.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\AI\Context;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Context_Conflict_Kind {

	public const AUTHORITATIVE_FACT = 'authoritative_fact';
	public const PREFERENCE         = 'preference';

	/**
	 * @return string[]
	 */
	public static function values(): array {
		return array( self::AUTHORITATIVE_FACT, self::PREFERENCE );
	}

	/**
	 * Normalize and validate a conflict kind string.
	 *
	 * @param string $kind Conflict kind.
	 * @return string
	 */
	public static function normalize( string $kind ): string {
		$normalized = strtolower( trim( $kind ) );
		if ( ! in_array( $normalized, self::values(), true ) ) {
			throw new InvalidArgumentException( 'Unknown context conflict kind.' );
		}

		return $normalized;
	}
}
