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

final class DataMachineAgentToolPolicyProvider {

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
