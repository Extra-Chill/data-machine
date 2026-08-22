<?php
/**
 * Identical failure tracker contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks repeated identical failed tool calls during a loop run.
 */
interface WP_Agent_Identical_Failure_Tracker {

	/**
	 * Record a failed tool-call signature.
	 *
	 * Return a nudge message when the caller should redirect the model away from
	 * repeating the same failing call. Return null to continue silently.
	 *
	 * @param WP_Agent_Identical_Failure_Signature $signature Failure signature.
	 * @param array<string, mixed>                 $context   Current turn context.
	 * @return string|null Nudge content.
	 */
	public function record_failure( WP_Agent_Identical_Failure_Signature $signature, array $context = array() ): ?string;

	/** Current repeat count for diagnostics. */
	public function repeat_count(): int;

	/** Tracker threshold. */
	public function threshold(): int;
}
