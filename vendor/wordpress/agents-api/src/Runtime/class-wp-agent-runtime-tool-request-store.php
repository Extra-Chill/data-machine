<?php
/**
 * External runtime tool request store interface.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Host-provided persistence boundary for pending runtime tool requests.
 */
interface WP_Agent_Runtime_Tool_Request_Store {

	/**
	 * Create or replace a pending runtime tool request.
	 *
	 * @param array<string, mixed> $request Normalized runtime tool request.
	 */
	public function create( array $request ): void;

	/**
	 * Read a runtime tool request by id.
	 *
	 * Stores may retain terminal records after completion or timeout. Completed
	 * records that can expose the prior submitted result should keep that
	 * normalized result under `result` so duplicate submissions can return the
	 * original completion without overwriting it.
	 *
	 * @param string $request_id Runtime tool request id.
	 * @return array<string, mixed>|null Normalized request or null when absent.
	 */
	public function get( string $request_id ): ?array;

	/**
	 * Mark a pending request complete with a client-submitted result.
	 *
	 * Implementations should transition only pending records. Duplicate
	 * completions for terminal records must leave existing store data unchanged.
	 *
	 * @param string               $request_id Runtime tool request id.
	 * @param array<string, mixed> $result Normalized runtime tool result.
	 */
	public function complete( string $request_id, array $result ): void;

	/**
	 * Mark a pending request timed out.
	 *
	 * Implementations should transition only pending records.
	 *
	 * @param string $request_id Runtime tool request id.
	 */
	public function timeout( string $request_id ): void;

	/**
	 * Return recent pending requests for timeout scans or client polling.
	 *
	 * Implementations own concrete filtering semantics, but should support
	 * product-neutral query keys such as `run_id`, `tool_name`, `before`, and
	 * `limit` when they are meaningful for the host store.
	 *
	 * @param array<string, mixed> $query Product-neutral query hints.
	 * @return array<int, array<string, mixed>> Normalized pending requests.
	 */
	public function recent_pending( array $query = array() ): array;
}

/**
 * Atomic terminal-transition capability for exact-once lifecycle operations.
 *
 * Hosts using submit, timeout, or cancel must implement this additive contract.
 * The legacy store interface remains load-compatible for create and read paths.
 */
interface WP_Agent_Runtime_Tool_Request_Atomic_Store extends WP_Agent_Runtime_Tool_Request_Store {

	/**
	 * Conditionally transition a pending request to a terminal status.
	 *
	 * Implementations must use a compare-and-set write and return `true` only for
	 * the caller that changed the pending record. Completed transitions must retain
	 * the normalized result under `result` for duplicate resolution.
	 *
	 * @param string                    $request_id Runtime tool request id.
	 * @param string                    $status Target terminal request status.
	 * @param array<string, mixed>|null $result Normalized completion result, when completing.
	 * @return bool True when this call transitioned the pending record.
	 */
	public function transition_pending( string $request_id, string $status, ?array $result = null ): bool;
}
