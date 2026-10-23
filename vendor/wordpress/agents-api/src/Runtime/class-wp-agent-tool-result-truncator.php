<?php
/**
 * Tool result truncator contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optionally truncates oversized mediated tool results before transcript storage.
 */
interface WP_Agent_Tool_Result_Truncator {

	/**
	 * Truncate a tool execution result when needed.
	 *
	 * Return shape:
	 * - `result` (array): Result to store in transcript/output.
	 * - `truncated` (bool): Whether the result was truncated.
	 * - `metadata` (array): Event metadata for observers.
	 *
	 * @param array<string, mixed> $result    Tool execution result.
	 * @param string               $tool_name Tool name.
	 * @param array<string, mixed> $context   Current tool context.
	 * @return array{result: array<string, mixed>, truncated: bool, metadata: array<string, mixed>}
	 */
	public function truncate_result( array $result, string $tool_name, array $context = array() ): array;
}
