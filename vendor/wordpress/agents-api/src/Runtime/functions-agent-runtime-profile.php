<?php
/**
 * Agent runtime profile helper.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_resolve_agent_runtime_profile' ) ) {
	/**
	 * Resolve the runtime provider/model profile for an agent.
	 *
	 * @param WP_Agent|string      $agent   Agent instance or registered agent slug.
	 * @param array<string,mixed> $context Runtime resolution context.
	 * @return \AgentsAPI\AI\WP_Agent_Runtime_Profile|null Runtime profile, or null when no binding exists.
	 */
	function wp_resolve_agent_runtime_profile( $agent, array $context = array() ): ?\AgentsAPI\AI\WP_Agent_Runtime_Profile {
		if ( is_string( $agent ) ) {
			$agent = function_exists( 'wp_get_agent' ) ? wp_get_agent( $agent ) : null;
		}

		if ( ! $agent instanceof WP_Agent ) {
			return null;
		}

		$resolver = new \AgentsAPI\AI\WP_Agent_Runtime_Profile_Resolver();
		return $resolver->resolve( $agent, $context );
	}
}
