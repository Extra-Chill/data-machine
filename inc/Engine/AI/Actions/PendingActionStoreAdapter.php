<?php
/**
 * Agents API adapter for Data Machine pending-action storage.
 *
 * @package DataMachine\Engine\AI\Actions
 */

namespace DataMachine\Engine\AI\Actions;

use AgentsAPI\AI\Approvals\ApprovalDecision;
use AgentsAPI\AI\Approvals\PendingAction;
use AgentsAPI\AI\Approvals\PendingActionStoreInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Implements the generic Agents API store contract without changing the
 * existing static Data Machine PendingActionStore facade.
 */
final class PendingActionStoreAdapter implements PendingActionStoreInterface {

	/**
	 * Persist a pending action payload.
	 *
	 * @param PendingAction $action Durable pending action record.
	 * @return bool
	 */
	public function store( PendingAction $action ): bool {
		$payload = $this->payload_from_action( $action );
		return PendingActionStore::store( $action->get_action_id(), $payload );
	}

	/**
	 * Retrieve a pending action payload.
	 *
	 * Agents API's merged contract is intentionally minimal and describes only
	 * the live pending lifecycle. Data Machine's richer inspect/list surfaces
	 * expose resolved audit rows separately.
	 *
	 * @param string $action_id        Durable action identifier.
	 * @param bool   $include_resolved Whether terminal audit rows may be returned.
	 * @return PendingAction|null
	 */
	public function get( string $action_id, bool $include_resolved = false ): ?PendingAction {
		$payload = PendingActionStore::get( $action_id, $include_resolved );
		return is_array( $payload ) ? $this->action_from_payload( $payload ) : null;
	}

	/**
	 * List durable pending action records.
	 *
	 * @param array<string,mixed> $filters Query filters.
	 * @return array<int,PendingAction>
	 */
	public function list( array $filters = array() ): array {
		return array_values( array_filter( array_map( array( $this, 'action_from_payload' ), PendingActionStore::list( $filters ) ) ) );
	}

	/**
	 * Summarize durable pending action records.
	 *
	 * @param array<string,mixed> $filters Query filters.
	 * @return array<string,mixed>
	 */
	public function summary( array $filters = array() ): array {
		return PendingActionStore::summary( $filters );
	}

	/**
	 * Record a terminal resolution while retaining audit data.
	 *
	 * @param string           $action_id Durable action identifier.
	 * @param ApprovalDecision $decision  Accepted/rejected decision.
	 * @param string           $resolver  Resolver identifier.
	 * @param mixed|null       $result    Resolution result.
	 * @param string|null      $error     Resolution error.
	 * @param array<string,mixed> $metadata Resolution metadata.
	 * @return bool
	 */
	public function record_resolution( string $action_id, ApprovalDecision $decision, string $resolver, $result = null, ?string $error = null, array $metadata = array() ): bool {
		return PendingActionStore::record_resolution( $action_id, $decision->value(), $result, $error );
	}

	/**
	 * Mark due pending actions as expired.
	 *
	 * @param string|null $before Timestamp boundary.
	 * @return int
	 */
	public function expire( ?string $before = null ): int {
		return PendingActionStore::expire_due_actions();
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

	/**
	 * Convert an Agents API record into Data Machine's durable payload shape.
	 *
	 * @param PendingAction $action Durable pending action record.
	 * @return array<string,mixed>
	 */
	private function payload_from_action( PendingAction $action ): array {
		$data = $action->to_array();

		$data['preview_data'] = $data['preview'];
		$data['agent_id']     = is_numeric( $data['agent'] ?? null ) ? (int) $data['agent'] : 0;
		$data['created_by']   = is_numeric( $data['creator'] ?? null ) ? (int) $data['creator'] : 0;
		$data['context']      = is_array( $data['metadata'] ?? null ) ? $data['metadata'] : array();

		return $data;
	}

	/**
	 * Convert Data Machine's payload shape into the Agents API value object.
	 *
	 * @param array<string,mixed> $payload Stored payload.
	 * @return PendingAction|null
	 */
	private function action_from_payload( array $payload ): ?PendingAction {
		try {
			return PendingAction::from_array(
				array(
					'action_id'           => (string) ( $payload['action_id'] ?? '' ),
					'kind'                => (string) ( $payload['kind'] ?? '' ),
					'summary'             => (string) ( $payload['summary'] ?? 'Pending action' ),
					'preview'             => $payload['preview'] ?? $payload['preview_data'] ?? array(),
					'apply_input'         => $payload['apply_input'] ?? array(),
					'agent'               => isset( $payload['agent_id'] ) ? (string) (int) $payload['agent_id'] : null,
					'creator'             => isset( $payload['created_by'] ) ? (string) (int) $payload['created_by'] : null,
					'status'              => (string) ( $payload['status'] ?? PendingActionStore::STATUS_PENDING ),
					'created_at'          => $this->iso_time( $payload['created_at'] ?? $payload['created_at_iso'] ?? null ),
					'expires_at'          => $this->optional_iso_time( $payload['expires_at'] ?? $payload['expires_at_iso'] ?? null ),
					'resolved_at'         => $this->optional_iso_time( $payload['resolved_at'] ?? null ),
					'resolver'            => ! empty( $payload['resolved_by'] ) ? (string) (int) $payload['resolved_by'] : null,
					'resolution_result'   => $payload['resolution_result'] ?? null,
					'resolution_error'    => $payload['resolution_error'] ?? null,
					'resolution_metadata' => array(),
					'metadata'            => isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : array(),
				)
			);
		} catch ( \InvalidArgumentException $error ) {
			return null;
		}
	}

	/**
	 * Normalize a required timestamp to ISO-8601.
	 *
	 * @param mixed $value Timestamp value.
	 * @return string
	 */
	private function iso_time( $value ): string {
		$timestamp = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
		return gmdate( 'c', false === $timestamp || $timestamp <= 0 ? time() : $timestamp );
	}

	/**
	 * Normalize an optional timestamp to ISO-8601.
	 *
	 * @param mixed $value Timestamp value.
	 * @return string|null
	 */
	private function optional_iso_time( $value ): ?string {
		if ( null === $value || '' === $value || 0 === $value || '0' === $value ) {
			return null;
		}

		return $this->iso_time( $value );
	}
}
