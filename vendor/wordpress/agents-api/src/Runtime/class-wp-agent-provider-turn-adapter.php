<?php
/**
 * Provider-turn adapter interface.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-neutral adapter boundary for one model turn.
 *
 * Implementations own provider request construction and dispatch. The
 * conversation loop owns continuation, mediated tool execution, transcript
 * events, result normalization, and stop conditions.
 */
interface WP_Agent_Provider_Turn_Adapter {

	/**
	 * Run one provider turn.
	 *
	 * @param WP_Agent_Provider_Turn_Request $request Provider-turn request.
	 * @return array<string, mixed> Raw provider-turn result.
	 */
	public function run_turn( WP_Agent_Provider_Turn_Request $request ): array;
}
