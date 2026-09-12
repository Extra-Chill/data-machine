<?php
/**
 * Context Authority Tier
 *
 * Generic vocabulary for retrieved-context authority ordering.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\AI\Context;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Context_Authority_Tier {

	public const PLATFORM_AUTHORITY     = 'platform_authority';
	public const SUPPORT_AUTHORITY      = 'support_authority';
	public const WORKSPACE_SHARED       = 'workspace_shared';
	public const USER_WORKSPACE_PRIVATE = 'user_workspace_private';
	public const USER_GLOBAL            = 'user_global';
	public const AGENT_IDENTITY         = 'agent_identity';
	public const AGENT_MEMORY           = 'agent_memory';
	public const CONVERSATION           = 'conversation';

	/**
	 * Highest authority first.
	 *
	 * @return string[]
	 */
	public static function ordered(): array {
		return array(
			self::PLATFORM_AUTHORITY,
			self::SUPPORT_AUTHORITY,
			self::WORKSPACE_SHARED,
			self::USER_WORKSPACE_PRIVATE,
			self::USER_GLOBAL,
			self::AGENT_IDENTITY,
			self::AGENT_MEMORY,
			self::CONVERSATION,
		);
	}

	/**
	 * Normalize and validate a tier string.
	 *
	 * @param string $tier Tier identifier.
	 * @return string
	 */
	public static function normalize( string $tier ): string {
		$normalized = strtolower( trim( $tier ) );
		if ( ! in_array( $normalized, self::ordered(), true ) ) {
			throw new InvalidArgumentException( 'Unknown context authority tier.' );
		}

		return $normalized;
	}

	/**
	 * Numeric authority rank. Larger values have higher authority.
	 *
	 * @param string $tier Tier identifier.
	 * @return int
	 */
	public static function authority_rank( string $tier ): int {
		$ordered = array_reverse( self::ordered() );
		$index   = array_search( self::normalize( $tier ), $ordered, true );

		return false === $index ? 0 : (int) $index + 1;
	}

	/**
	 * Numeric preference-specificity rank. Larger values are more specific.
	 *
	 * @param string $tier Tier identifier.
	 * @return int
	 */
	public static function specificity_rank( string $tier ): int {
		return match ( self::normalize( $tier ) ) {
			self::CONVERSATION           => 8,
			self::USER_WORKSPACE_PRIVATE => 7,
			self::WORKSPACE_SHARED       => 6,
			self::USER_GLOBAL            => 5,
			self::AGENT_MEMORY           => 4,
			self::AGENT_IDENTITY         => 3,
			self::SUPPORT_AUTHORITY      => 2,
			self::PLATFORM_AUTHORITY     => 1,
			default                      => 0,
		};
	}

	/**
	 * Whether the tier represents generic platform/support policy authority.
	 *
	 * These tiers are intentionally host- and mode-gated by consumers. Agents API
	 * provides vocabulary only, not a WP.com-specific source or enablement path.
	 *
	 * @param string $tier Tier identifier.
	 * @return bool
	 */
	public static function is_governance_authority( string $tier ): bool {
		return in_array(
			self::normalize( $tier ),
			array( self::PLATFORM_AUTHORITY, self::SUPPORT_AUTHORITY ),
			true
		);
	}
}
