<?php
/**
 * Data Machine agent tool policy provider.
 *
 * Adapts persisted Data Machine agent configuration into the generic tool
 * policy shape consumed by ToolPolicyResolver.
 *
 * @package DataMachine\Engine\AI\Tools\Policy
 */

namespace DataMachine\Engine\AI\Tools\Policy;

use DataMachine\Core\Database\Agents\Agents;

defined( 'ABSPATH' ) || exit;

final class DataMachineAgentToolPolicyProvider implements \WP_Agent_Tool_Access_Policy {

	/**
	 * Provide persisted Data Machine agent policy to Agents API.
	 *
	 * @param array $context Runtime context.
	 * @return array|null Tool policy fragment, or null for no opinion.
	 */
	public function get_tool_policy( array $context ): ?array {
		$agent_id = isset( $context['agent_id'] ) ? (int) $context['agent_id'] : 0;

		return $this->getForAgent( $agent_id );
	}

	/**
	 * Get tool policy from an agent's persisted config.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array|null Tool policy array with 'mode' and 'tools' keys, or null.
	 */
	public function getForAgent( int $agent_id ): ?array {
		if ( $agent_id <= 0 ) {
			return null;
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return null;
		}

		$config = $agent['agent_config'] ?? array();

		if ( empty( $config['tool_policy'] ) || ! is_array( $config['tool_policy'] ) ) {
			return null;
		}

		$policy = $config['tool_policy'];

		if ( ! isset( $policy['mode'] ) ) {
			return null;
		}

		if ( ! in_array( $policy['mode'], array( 'deny', 'allow' ), true ) ) {
			return null;
		}

		if ( ! isset( $policy['tools'] ) || ! is_array( $policy['tools'] ) ) {
			$policy['tools'] = array();
		}

		if ( isset( $policy['categories'] ) && ! is_array( $policy['categories'] ) ) {
			return null;
		}

		if ( empty( $policy['tools'] ) && empty( $policy['categories'] ?? array() ) && 'allow' !== $policy['mode'] ) {
			return null;
		}

		return $policy;
	}
}
