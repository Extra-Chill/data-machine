<?php
/**
 * Tool action policy vocabulary.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes generic tool action policy values.
 */
final class WP_Agent_Action_Policy {

	/** Execute the tool call immediately. */
	public const DIRECT = 'direct';

	/** Stage the tool call for user review before execution. */
	public const PREVIEW = 'preview';

	/** Refuse the tool call without execution. */
	public const FORBIDDEN = 'forbidden';

	/**
	 * Return all valid policy values.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::DIRECT,
			self::PREVIEW,
			self::FORBIDDEN,
		);
	}

	/**
	 * Determine whether a raw value is a recognized action policy.
	 *
	 * @param mixed $value Raw policy value.
	 * @return bool
	 */
	public static function isValid( $value ): bool {
		return null !== self::normalize( $value );
	}

	/**
	 * Normalize a raw policy value.
	 *
	 * @param mixed       $value    Raw policy value.
	 * @param string|null $fallback Optional fallback used when value is invalid.
	 * @return string|null One of the policy constants, or null when invalid.
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

	/**
	 * Whether the policy allows immediate execution.
	 *
	 * @param mixed $policy Raw or normalized policy value.
	 * @return bool
	 */
	public static function allowsDirectExecution( $policy ): bool {
		return self::DIRECT === self::normalize( $policy );
	}

	/**
	 * Whether the policy stages execution for approval.
	 *
	 * @param mixed $policy Raw or normalized policy value.
	 * @return bool
	 */
	public static function stagesApproval( $policy ): bool {
		return self::PREVIEW === self::normalize( $policy );
	}

	/**
	 * Whether the policy refuses execution.
	 *
	 * @param mixed $policy Raw or normalized policy value.
	 * @return bool
	 */
	public static function refusesExecution( $policy ): bool {
		return self::FORBIDDEN === self::normalize( $policy );
	}
}
