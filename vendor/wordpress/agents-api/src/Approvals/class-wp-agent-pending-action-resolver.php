<?php
/**
 * Generic pending-action resolver contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Approvals;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Pending_Action_Resolver {

	/**
	 * Resolve a pending action by identifier.
	 *
	 * Implementations own lookup, handler permission checks, handler dispatch, and
	 * resolution audit persistence. Callers own authentication before invoking the
	 * resolver.
	 *
	 * @param string           $pending_action_id Stable pending-action identifier.
	 * @param WP_Agent_Approval_Decision $decision          Accepted/rejected decision.
	 * @param string           $resolver          Resolver identifier, such as a user, token, or service actor.
	 * @param array<mixed>            $payload           Fresh resolver payload supplied with the decision.
	 * @param array<mixed>            $context           Optional caller context.
	 * @return mixed Generic resolver result.
	 */
	public function resolve_pending_action( string $pending_action_id, WP_Agent_Approval_Decision $decision, string $resolver, array $payload = array(), array $context = array() ): mixed;
}
