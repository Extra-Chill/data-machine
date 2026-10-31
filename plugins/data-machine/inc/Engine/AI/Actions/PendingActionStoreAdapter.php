<?php
/**
 * Agents API adapter for Data Machine pending-action storage.
 *
 * @package DataMachine\Engine\AI\Actions
 */

namespace DataMachine\Engine\AI\Actions;

use AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Implements the generic Agents API store contract without changing the
 * existing static Data Machine PendingActionStore facade.
 */
final class PendingActionStoreAdapter implements WP_Agent_Pending_Action_Store {

	/**
	 * Persist a pending action payload.
	 *
	 * @param WP_Agent_Pending_Action $action Durable pending action record.
	 * @return bool
	 */
	public function store( WP_Agent_Pending_Action $action ): bool {
		return PendingActionStore::store_action( $action );
	}

	/**
	 * Retrieve a pending action payload.
	 *
	 * @param string $action_id        Durable action identifier.
	 * @param bool   $include_resolved Whether terminal audit rows may be returned.
	 * @return WP_Agent_Pending_Action|null
	 */
	public function get( string $action_id, bool $include_resolved = false ): ?WP_Agent_Pending_Action {
		return PendingActionStore::get_action( $action_id, $include_resolved );
	}

	/**
	 * List durable pending action records.
	 *
	 * @param array<string,mixed> $filters Query filters.
	 * @return array<int,WP_Agent_Pending_Action>
	 */
	public function list( array $filters = array() ): array {
		return PendingActionStore::list_actions( $filters );
	}

	/**
	 * Summarize pending actions for operator inspection.
	 *
	 * @param array<string,mixed> $filters Query filters.
	 * @return array<string,mixed>
	 */
	public function summary( array $filters = array() ): array {
		return PendingActionStore::summary( $filters );
	}

	/**
	 * Record a durable terminal resolution.
	 *
	 * @param string              $action_id Durable action identifier.
	 * @param WP_Agent_Approval_Decision    $decision  Resolution decision.
	 * @param string              $resolver  Resolver audit identifier.
	 * @param mixed|null          $result    Resolution result.
	 * @param string|null         $error     Resolution error.
	 * @param array<string,mixed> $metadata  Resolution metadata.
	 * @return bool
	 */
	public function record_resolution( string $action_id, WP_Agent_Approval_Decision $decision, string $resolver, $result = null, ?string $error = null, array $metadata = array() ): bool {
		return PendingActionStore::record_action_resolution( $action_id, $decision, $resolver, $result, $error, $metadata );
	}

	/**
	 * Expire due pending action records.
	 *
	 * @param string|null $before Timestamp boundary.
	 * @return int
	 */
	public function expire( ?string $before = null ): int {
		return PendingActionStore::expire_due_actions( $before );
	}

	/**
	 * Delete a pending action payload.
	 *
	 * @param string $action_id Durable action identifier.
	 * @return bool
	 */
	public function delete( string $action_id ): bool {
		return PendingActionStore::delete( $action_id );
	}
}
