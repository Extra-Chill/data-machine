<?php
/**
 * Mutable per-turn state for the Data Machine conversation loop.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Mutable per-turn state surfaced to the caller for the pre-substrate error path.
 *
 * The upstream conversation loop surfaces turn_count, final_content, usage, and
 * request_metadata on the FINAL result (agents-api#136). DM only needs to carry
 * these by reference for the case where the provider-turn adapter throws a
 * RuntimeException BEFORE the substrate can return its accumulated result — see
 * the catch block in datamachine_run_conversation(), which reconstructs a
 * best-effort error result from the last completed turn. Consolidating the
 * previously-separate by-ref params (latest_messages, latest_turn_count,
 * last_request_metadata, last_tool_calls, all_tool_calls, completion_nudges)
 * into one accumulator shrinks the provider-turn adapter's parameter surface
 * toward the #2803 decomposition target without changing behavior. The
 * tool-call and nudge accumulators are read after the loop by the result
 * normalizer and the failure-path error result; routing them through this
 * object makes that data flow local and traceable instead of reference-smuggled.
 */
final class DataMachineProviderTurnState {

	/** @var array Messages handed to the latest dispatched turn. */
	public array $latest_messages;

	/** @var int Turn number of the latest dispatched turn. */
	public int $latest_turn_count = 0;

	/** @var array Request metadata captured on the latest dispatched turn. */
	public array $last_request_metadata = array();

	/** @var array Tool calls from the latest dispatched turn (DM-flavored shape). */
	public array $last_tool_calls = array();

	/** @var array All tool calls accumulated across the run. */
	public array $all_tool_calls = array();

	/** @var array Completion nudge diagnostics accumulated across the run (DM-only). */
	public array $completion_nudges = array();

	/**
	 * @param array $initial_messages Initial conversation messages.
	 */
	public function __construct( array $initial_messages ) {
		$this->latest_messages = $initial_messages;
	}
}
