<?php
/**
 * External runtime tool continuation contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Host-provided callback boundary for resuming a paused runtime-tool run.
 */
interface WP_Agent_Runtime_Tool_Continuation {

	/**
	 * Resume the paused run after a runtime tool result or timeout.
	 *
	 * Agents API owns the normalized request/result handoff. Hosts own concrete
	 * session lookup, job scheduling, transcript storage, and provider dispatch.
	 *
	 * @param array<string, mixed> $request Normalized runtime tool request.
	 * @param array<string, mixed> $result Normalized runtime tool result or timeout result.
	 * @param array<string, mixed> $context Caller-owned continuation context.
	 * @return array<string, mixed> Host-owned resume result.
	 */
	public function resume( array $request, array $result, array $context = array() ): array;
}
