<?php
/**
 * Host-supplied agent runtime profile provider contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Allows hosts to provide a generic runtime provider/model binding for an agent.
 */
interface WP_Agent_Runtime_Profile_Provider {

	/**
	 * Resolve a runtime profile for the given agent and request context.
	 *
	 * @param \WP_Agent           $agent   Registered agent definition.
	 * @param array<string,mixed> $context Runtime resolution context.
	 * @return WP_Agent_Runtime_Profile|null Runtime profile, or null when this provider has no binding.
	 */
	public function resolve_agent_runtime_profile( \WP_Agent $agent, array $context ): ?WP_Agent_Runtime_Profile;
}
