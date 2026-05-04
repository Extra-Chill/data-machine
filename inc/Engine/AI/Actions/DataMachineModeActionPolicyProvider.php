<?php
/**
 * Data Machine mode-specific action policy provider.
 *
 * @package DataMachine\Engine\AI\Actions
 */

namespace DataMachine\Engine\AI\Actions;

use AgentsAPI\AI\Tools\ActionPolicy;

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( '\WP_Agent_Action_Policy_Provider_Interface' ) ) {
	require_once dirname( __DIR__, 4 ) . '/vendor/automattic/agents-api/src/Tools/class-wp-agent-action-policy-provider-interface.php';
}

/**
 * Preserves Data Machine's mode-specific tool defaults through Agents API.
 */
final class DataMachineModeActionPolicyProvider implements \WP_Agent_Action_Policy_Provider_Interface {

	/**
	 * Return a mode-specific tool action policy when declared.
	 *
	 * @param array $context Action policy context.
	 * @return string|null Action policy value, or null for no opinion.
	 */
	public function get_action_policy( array $context ): ?string {
		$mode     = (string) ( $context['mode'] ?? ActionPolicyResolver::MODE_CHAT );
		$tool_def = is_array( $context['tool_def'] ?? null ) ? $context['tool_def'] : array();
		$key      = 'action_policy_' . $mode;

		return ActionPolicy::normalize( $tool_def[ $key ] ?? null );
	}
}
