<?php
/**
 * Context Conflict Resolver Interface
 *
 * Contract for resolving conflicts between retrieved context items.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\AI\Context;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Context_Conflict_Resolver {

	/**
	 * Resolve mutually conflicting context items.
	 *
	 * Authoritative facts must be resolved by authority tier: lower-scope memory
	 * cannot override a higher authority fact. Preferences may resolve by
	 * specificity, with authority used only as a tie-breaker.
	 *
	 * @param WP_Agent_Context_Item[] $items   Retrieved context items.
	 * @param array<mixed>                  $context Optional caller context.
	 * @return WP_Agent_Context_Conflict_Resolution[] Conflict resolutions keyed by conflict key.
	 */
	public function resolve( array $items, array $context = array() ): array;
}
