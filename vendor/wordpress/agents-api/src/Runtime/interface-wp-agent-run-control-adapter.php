<?php
/**
 * Generic run-control adapter contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Run_Control_Adapter {

	/**
	 * @param array<string,mixed> $input Run-control request input.
	 * @return array<string,mixed>|\WP_Error|null
	 */
	public function get_run( array $input );

	/**
	 * @param array<string,mixed> $input Run-control request input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function list_events( array $input );

	/**
	 * @param array<string,mixed> $input Run-control request input.
	 * @return array<string,mixed>|\WP_Error|null
	 */
	public function cancel_run( array $input );
}
