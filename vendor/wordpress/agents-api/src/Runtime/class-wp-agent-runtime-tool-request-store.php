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
	 * completions for terminal records must leave existing store data unchanged;
	 * callers use `get()` before this method to return a retained prior result or
	 * reject the duplicate when no prior result is available.
	 *
	 * @param string               $request_id Runtime tool request id.
	 * @param array<string, mixed> $result Normalized runtime tool result.
	 */
	public function complete( string $request_id, array $result ): void;

	/**
	 * Mark a pending request timed out.
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
