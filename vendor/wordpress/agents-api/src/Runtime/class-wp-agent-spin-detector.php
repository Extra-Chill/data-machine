<?php
/**
 * Spin detector contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects repeated tool-call signatures during a loop run.
 */
interface WP_Agent_Spin_Detector {

	/**
	 * Record a tool-call signature and report whether the loop is stalled.
	 *
	 * @param WP_Agent_Spin_Signature $signature Tool-call signature.
	 * @param array<string, mixed>    $context   Current turn context.
	 */
	public function record_signature( WP_Agent_Spin_Signature $signature, array $context = array() ): bool;

	/** Current repeat count for diagnostics. */
	public function repeat_count(): int;

	/** Detector threshold. */
	public function threshold(): int;
}
