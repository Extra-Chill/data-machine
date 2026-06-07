<?php
/**
 * Agents API adapter for Data Machine pending-action resolution.
 *
 * @package DataMachine\Engine\AI\Actions
 */

namespace DataMachine\Engine\AI\Actions;

use AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Implements the generic Agents API resolver contract through Data Machine's
 * concrete resolver implementation.
 */
final class PendingActionResolverAdapter implements WP_Agent_Pending_Action_Resolver {

	/**
	 * Resolve a pending action by identifier.
	 *
	 * @param string           $pending_action_id Stable pending-action identifier.
	 * @param WP_Agent_Approval_Decision $decision          Accepted/rejected decision.
	 * @param string           $resolver          Resolver audit identifier.
	 * @param array            $payload           Fresh resolver payload.
	 * @param array            $context           Optional caller context.
	 * @return mixed
	 */
	public function resolve_pending_action( string $pending_action_id, WP_Agent_Approval_Decision $decision, string $resolver, array $payload = array(), array $context = array() ): mixed {
		return ResolvePendingActionAbility::resolve_with_datamachine_handlers(
			array(
				'action_id' => $pending_action_id,
				'decision'  => $decision->value(),
				'resolver'  => $resolver,
				'payload'   => $payload,
				'context'   => $context,
			)
		);
	}
}
