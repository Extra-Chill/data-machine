<?php
/**
 * Pending Action Store Interface
 *
 * Generic persistence contract for actions that must be resumed after an
 * external approval or rejection. The contract deliberately describes only the
 * pending-action payload lifecycle; concrete storage, routing, UI, and
 * scheduling behavior stay in consumers.
 *
 * Payloads MUST remain JSON-serializable. Consumers may store richer payloads
 * when they need additional context for approval prompts, diffs, audit data, or
 * continuation state.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\AI\Approvals;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Pending_Action_Store {

	/**
	 * Persist a pending action record.
	 *
	 * @param WP_Agent_Pending_Action $action Durable pending action record.
	 * @return bool Whether the payload was stored successfully.
	 */
	public function store( WP_Agent_Pending_Action $action ): bool;

	/**
	 * Retrieve a pending action by action ID.
	 *
	 * @param string $action_id        Durable action identifier.
	 * @param bool   $include_resolved Whether terminal audit rows may be returned.
	 * @return WP_Agent_Pending_Action|null Pending action, or null when not found.
	 */
	public function get( string $action_id, bool $include_resolved = false ): ?WP_Agent_Pending_Action;

	/**
	 * List durable pending action records for queue and audit surfaces.
	 *
	 * Supported filters are implementation-defined, but SHOULD include status,
	 * kind, workspace_type, workspace_id, agent, creator, resolver,
	 * created/resolved date ranges, limit, and offset when the backing store can
	 * express them.
	 *
	 * @param array<string,mixed> $filters Query filters.
	 * @return array<int,WP_Agent_Pending_Action>
	 */
	public function list( array $filters = array() ): array;

	/**
	 * Summarize durable pending action records for operator inspection.
	 *
	 * @param array<string,mixed> $filters Query filters.
	 * @return array<string,mixed>
	 */
	public function summary( array $filters = array() ): array;

	/**
	 * Record a terminal resolution while retaining the action for audit.
	 *
	 * @param string           $action_id Durable action identifier.
	 * @param WP_Agent_Approval_Decision $decision  Accepted/rejected decision.
	 * @param string           $resolver  Resolver identifier, such as a user, token, or service actor.
	 * @param mixed|null       $result    JSON-serializable resolution result.
	 * @param string|null      $error     Human-readable resolution error.
	 * @param array<string,mixed> $metadata JSON-serializable resolution metadata.
	 * @return bool Whether the audit update completed successfully.
	 */
	public function record_resolution( string $action_id, WP_Agent_Approval_Decision $decision, string $resolver, $result = null, ?string $error = null, array $metadata = array() ): bool;

	/**
	 * Mark due pending actions as expired.
	 *
	 * @param string|null $before Timestamp boundary; defaults to implementation time.
	 * @return int Number of actions expired.
	 */
	public function expire( ?string $before = null ): int;

	/**
	 * Delete a pending action by action ID.
	 *
	 * Implementations SHOULD retain an audit row with `deleted` status when the
	 * backing store supports durable audit history.
	 *
	 * @param string $action_id Durable action identifier.
	 * @return bool Whether the delete operation completed successfully.
	 */
	public function delete( string $action_id ): bool;
}
