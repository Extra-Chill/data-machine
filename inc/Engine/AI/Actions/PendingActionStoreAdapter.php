<?php
/**
 * Agents API adapter for Data Machine pending-action storage.
 *
 * @package DataMachine\Engine\AI\Actions
 */

namespace DataMachine\Engine\AI\Actions;

use AgentsAPI\AI\Approvals\PendingActionStoreInterface;
use AgentsAPI\AI\Approvals\ApprovalDecision;
use AgentsAPI\AI\Approvals\PendingAction;

defined( 'ABSPATH' ) || exit;

/**
 * Implements the generic Agents API store contract without changing the
 * existing static Data Machine PendingActionStore facade.
 */
final class PendingActionStoreAdapter implements PendingActionStoreInterface {

	/**
	 * Persist a pending action record.
	 */
	public function store( PendingAction $action ): bool {
		$payload = self::payload_from_action( $action );
		return PendingActionStore::store( $action->get_action_id(), $payload );
	}

	/**
	 * Retrieve a pending action record.
	 */
	public function get( string $action_id, bool $include_resolved = false ): ?PendingAction {
		$payload = PendingActionStore::get( $action_id, $include_resolved );
		return is_array( $payload ) ? self::action_from_payload( $payload ) : null;
	}

	/**
	 * List durable pending action records.
	 *
	 * @return array<int,PendingAction>
	 */
	public function list( array $filters = array() ): array {
		return array_values( array_filter( array_map( array( self::class, 'action_from_payload' ), PendingActionStore::list( $filters ) ) ) );
	}

	/**
	 * Summarize durable pending action records.
	 *
	 * @return array<string,mixed>
	 */
	public function summary( array $filters = array() ): array {
		return PendingActionStore::summary( $filters );
	}

	/**
	 * Record a terminal resolution while retaining the action for audit.
	 *
	 * @param mixed|null $result Resolution result.
	 */
	public function record_resolution( string $action_id, ApprovalDecision $decision, string $resolver, $result = null, ?string $error = null, array $metadata = array() ): bool {
		unset( $resolver, $metadata );
		return PendingActionStore::record_resolution( $action_id, $decision->value(), $result, $error );
	}

	/**
	 * Mark due pending actions as expired.
	 */
	public function expire( ?string $before = null ): int {
		unset( $before );
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
	 * Convert an Agents API action into Data Machine's persisted payload shape.
	 *
	 * @return array<string,mixed>
	 */
	private static function payload_from_action( PendingAction $action ): array {
		$data = $action->to_array();
		return array(
			'kind'         => $data['kind'],
			'summary'      => $data['summary'],
			'preview_data' => $data['preview'],
			'preview'      => $data['preview'],
			'apply_input'  => $data['apply_input'],
			'agent_id'     => isset( $data['agent'] ) && is_numeric( $data['agent'] ) ? (int) $data['agent'] : 0,
			'created_by'   => isset( $data['creator'] ) && is_numeric( $data['creator'] ) ? (int) $data['creator'] : 0,
			'context'      => array(
				'workspace' => $data['workspace'] ?? null,
				'metadata'  => $data['metadata'] ?? array(),
			),
			'created_at'   => $data['created_at'],
			'expires_at'   => $data['expires_at'] ?? null,
		);
	}

	/**
	 * Convert a Data Machine payload into an Agents API pending-action record.
	 *
	 * @param array<string,mixed> $payload Pending action payload.
	 */
	private static function action_from_payload( array $payload ): ?PendingAction {
		try {
			$context = is_array( $payload['context'] ?? null ) ? $payload['context'] : array();
			return PendingAction::from_array(
				array(
					'action_id'           => (string) ( $payload['action_id'] ?? '' ),
					'kind'                => (string) ( $payload['kind'] ?? '' ),
					'summary'             => (string) ( $payload['summary'] ?? '' ),
					'preview'             => $payload['preview'] ?? ( $payload['preview_data'] ?? array() ),
					'apply_input'         => $payload['apply_input'] ?? array(),
					'workspace'           => $context['workspace'] ?? null,
					'agent'               => isset( $payload['agent_id'] ) && (int) $payload['agent_id'] > 0 ? (string) (int) $payload['agent_id'] : null,
					'creator'             => isset( $payload['created_by'] ) && (int) $payload['created_by'] > 0 ? (string) (int) $payload['created_by'] : null,
					'status'              => (string) ( $payload['status'] ?? PendingActionStore::STATUS_PENDING ),
					'created_at'          => self::timestamp_to_iso( $payload['created_at'] ?? null ),
					'expires_at'          => self::optional_timestamp_to_iso( $payload['expires_at'] ?? null ),
					'resolved_at'         => self::optional_timestamp_to_iso( $payload['resolved_at'] ?? null ),
					'resolver'            => isset( $payload['resolved_by'] ) && (int) $payload['resolved_by'] > 0 ? (string) (int) $payload['resolved_by'] : null,
					'resolution_result'   => $payload['resolution_result'] ?? null,
					'resolution_error'    => $payload['resolution_error'] ?? null,
					'resolution_metadata' => array(),
					'metadata'            => is_array( $context['metadata'] ?? null ) ? $context['metadata'] : array(),
				)
			);
		} catch ( \InvalidArgumentException $error ) {
			return null;
		}
	}

	/**
	 * Convert timestamp-like values into the required ISO-ish string.
	 *
	 * @param mixed $value Timestamp-like value.
	 */
	private static function timestamp_to_iso( $value ): string {
		return self::optional_timestamp_to_iso( $value ) ?? gmdate( 'c' );
	}

	/**
	 * Convert optional timestamp-like values into ISO-ish strings.
	 *
	 * @param mixed $value Timestamp-like value.
	 */
	private static function optional_timestamp_to_iso( $value ): ?string {
		if ( is_numeric( $value ) && (int) $value > 0 ) {
			return gmdate( 'c', (int) $value );
		}

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$timestamp = strtotime( $value );
			return false === $timestamp ? null : gmdate( 'c', $timestamp );
		}

		return null;
	}
}
