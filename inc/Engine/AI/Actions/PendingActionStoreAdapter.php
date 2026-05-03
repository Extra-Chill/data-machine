<?php
/**
 * Agents API adapter for Data Machine pending-action storage.
 *
 * @package DataMachine\Engine\AI\Actions
 */

namespace DataMachine\Engine\AI\Actions;

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
	 * @param string $action_id Durable action identifier.
	 * @param array  $payload   JSON-serializable pending action payload.
	 * @return bool
	 */
	public function store( string $action_id, array $payload ): bool {
		return PendingActionStore::store( $action_id, $payload );
	}

	/**
	 * Retrieve a pending action payload.
	 *
	 * Agents API's merged contract is intentionally minimal and describes only
	 * the live pending lifecycle. Data Machine's richer inspect/list surfaces
	 * expose resolved audit rows separately.
	 *
	 * @param string $action_id Durable action identifier.
	 * @return array|null
	 */
	public function get( string $action_id ): ?array {
		return PendingActionStore::get( $action_id );
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
