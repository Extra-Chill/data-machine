<?php
/**
 * Process-lifetime run-control claim capability.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Optional capability for work that must not overlap while a worker is alive.
 */
interface WP_Agent_Exclusive_Run_Control_Store {

	/**
	 * Execute while holding a claim released automatically when the connection dies.
	 *
	 * @return bool Whether the claim was acquired and the callback executed.
	 * @throws WP_Agent_Run_Control_Store_Exception When claim acquisition or release fails.
	 */
	public function execute_claimed( string $claim_key, callable $callback ): bool;
}
