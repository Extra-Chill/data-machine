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
		unset( $resolver );

		$payload  = $this->legacy_payload( $action );
		$resolved = $action->to_array();

		do_action(
			'datamachine_pending_action_resolved',
			$decision->value(),
			$action->get_action_id(),
			$action->get_kind(),
			$payload,
			$resolved['resolution_result'] ?? null
		);
	}

	public function on_expired( WP_Agent_Pending_Action $action ): void {
		do_action( 'datamachine_pending_action_expired', $action );
	}

	/**
	 * Build the legacy Data Machine hook payload shape from the Agents API value.
	 */
	private function legacy_payload( WP_Agent_Pending_Action $action ): array {
		$data        = $action->to_array();
		$metadata    = is_array( $data['metadata'] ?? null ) ? $data['metadata'] : array();
		$datamachine = is_array( $metadata['datamachine'] ?? null ) ? $metadata['datamachine'] : array();

		return array(
			'action_id'           => $data['action_id'],
			'kind'                => $data['kind'],
			'summary'             => $data['summary'],
			'preview_data'        => $data['preview'],
			'apply_input'         => $data['apply_input'],
			'workspace'           => $data['workspace'],
			'agent'               => $data['agent'],
			'creator'             => $data['creator'],
			'agent_id'            => $datamachine['agent_id'] ?? 0,
			'created_by'          => $datamachine['created_by'] ?? 0,
			'context'             => $datamachine['context'] ?? array(),
			'metadata'            => $metadata,
			'status'              => $data['status'],
			'created_at'          => $data['created_at'],
			'expires_at'          => $data['expires_at'],
			'resolved_at'         => $data['resolved_at'],
			'resolver'            => $data['resolver'],
			'resolution_result'   => $data['resolution_result'],
			'resolution_error'    => $data['resolution_error'],
			'resolution_metadata' => $data['resolution_metadata'],
		);
	}
}
