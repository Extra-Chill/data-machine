<?php
/**
 * Generic pending-action handler contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Approvals;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Pending_Action_Handler {

	/**
	 * Check whether the resolver may resolve a stored pending action.
	 *
	 * Resolver implementations SHOULD call this before applying or rejecting an
	 * action. Returning false denies resolution without encoding product policy in
	 * Agents API itself.
	 *
	 * @param WP_Agent_Pending_Action    $action   Stored pending action.
	 * @param WP_Agent_Approval_Decision $decision Accepted/rejected decision.
	 * @param array<mixed>            $payload  Fresh resolver payload supplied with the decision.
	 * @param array<mixed>            $context  Optional caller context.
	 * @return bool Whether resolution is allowed.
	 */
	public function can_resolve_pending_action( WP_Agent_Pending_Action $action, WP_Agent_Approval_Decision $decision, array $payload = array(), array $context = array() ): bool;

	/**
	 * Resolve a stored pending action with a caller-provided decision.
	 *
	 * Product-specific apply/reject behavior stays in consumer handlers. Agents API
	 * only defines the generic handoff shape.
	 *
	 * @param WP_Agent_Pending_Action    $action   Stored pending action.
	 * @param WP_Agent_Approval_Decision $decision Accepted/rejected decision.
	 * @param array<mixed>            $payload  Fresh resolver payload supplied with the decision.
	 * @param array<mixed>            $context  Optional caller context.
	 * @return mixed Generic implementation result.
	 */
	public function handle_pending_action( WP_Agent_Pending_Action $action, WP_Agent_Approval_Decision $decision, array $payload = array(), array $context = array() ): mixed;
}
