<?php
/**
 * Pending Action Observer Interface
 *
 * Generic lifecycle observer contract for pending action stores. Concrete store
 * implementations choose how observers are registered and invoked.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\AI\Approvals;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Pending_Action_Observer {

	/**
	 * Called after a pending action has been stored successfully.
	 *
	 * @param WP_Agent_Pending_Action $action Durable pending action record.
	 * @return void
	 */
	public function on_stored( WP_Agent_Pending_Action $action ): void;

	/**
	 * Called after a pending action has been resolved.
	 *
	 * @param WP_Agent_Pending_Action    $action   Durable pending action record.
	 * @param WP_Agent_Approval_Decision $decision Accepted/rejected decision.
	 * @param string                     $resolver Resolver identifier, such as a user, token, or service actor.
	 * @return void
	 */
	public function on_resolved( WP_Agent_Pending_Action $action, WP_Agent_Approval_Decision $decision, string $resolver ): void;

	/**
	 * Called after a pending action expires without resolution.
	 *
	 * @param WP_Agent_Pending_Action $action Durable pending action record.
	 * @return void
	 */
	public function on_expired( WP_Agent_Pending_Action $action ): void;
}
