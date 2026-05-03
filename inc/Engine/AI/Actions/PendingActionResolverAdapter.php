<?php
/**
 * Agents API adapter for Data Machine pending-action resolution.
 *
 * @package DataMachine\Engine\AI\Actions
 */

namespace DataMachine\Engine\AI\Actions;

use AgentsAPI\AI\Approvals\ApprovalDecision;
use AgentsAPI\AI\Approvals\PendingActionResolverInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Implements the generic Agents API resolver contract while preserving
 * `datamachine/resolve-pending-action` as the single Data Machine resolver.
 */
final class PendingActionResolverAdapter implements PendingActionResolverInterface {

	/**
	 * Resolve a pending action by identifier.
	 *
	 * @param string           $pending_action_id Stable pending-action identifier.
	 * @param ApprovalDecision $decision          Accepted/rejected decision.
	 * @param array            $payload           Fresh resolver payload.
	 * @param array            $context           Optional caller context.
	 * @return mixed
	 */
	public function resolve_pending_action( string $pending_action_id, ApprovalDecision $decision, array $payload = array(), array $context = array() ): mixed {
		return ResolvePendingActionAbility::execute(
			array(
				'action_id' => $pending_action_id,
				'decision'  => $decision->value(),
				'payload'   => $payload,
				'context'   => $context,
			)
		);
	}
}
