<?php
/**
 * WordPress action adapter for pending-action lifecycle events.
 *
 * @package DataMachine\Engine\AI\Actions
 */

namespace DataMachine\Engine\AI\Actions;

use AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Observer;

defined( 'ABSPATH' ) || exit;

final class WordPressActionDispatchObserver implements WP_Agent_Pending_Action_Observer {

	public function on_stored( WP_Agent_Pending_Action $action ): void {
		do_action( 'datamachine_pending_action_stored', $action );
	}

	public function on_resolved( WP_Agent_Pending_Action $action, WP_Agent_Approval_Decision $decision, string $resolver ): void {
		do_action(
			'datamachine_pending_action_resolved',
			$action,
			$decision,
			$resolver
		);
	}

	public function on_expired( WP_Agent_Pending_Action $action ): void {
		do_action( 'datamachine_pending_action_expired', $action );
	}
}
