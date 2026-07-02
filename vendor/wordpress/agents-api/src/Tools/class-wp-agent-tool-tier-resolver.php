<?php
/**
 * Tool tier resolver contract.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WP_Agent_Tool_Tier_Resolver' ) ) {
	/**
	 * Partitions visible tools into full-schema and discovery tiers.
	 */
	interface WP_Agent_Tool_Tier_Resolver {

		/**
		 * Resolve tool tiers from an already-visible tool set.
		 *
		 * @param array<string, array<string, mixed>> $tools   Visible tool declarations keyed by tool name.
		 * @param array<string, mixed>                $context Runtime context.
		 * @return array{tier_1: array<string, array<string, mixed>>, tier_2: array<string, array<string, mixed>>, manifest: array<int, array<string, mixed>>}
		 */
		public function resolve( array $tools, array $context = array() ): array;
	}
}
