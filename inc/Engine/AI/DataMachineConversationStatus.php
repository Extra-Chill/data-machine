<?php
/**
 * Conversation-result status vocabulary for the Data Machine conversation loop.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Named constants for the conversation-result status strings.
 *
 * The Agents API substrate surfaces a `status` string on the normalized
 * conversation result, and emits `completion_policy_*` loop events. Data Machine
 * reads those raw strings in several places to derive its own diagnostics
 * (completed / max_turns_reached / runtime_tool_pending / interrupted /
 * warning). Historically those reads compared against bare string literals
 * scattered across datamachine_run_conversation(), with no single source of
 * truth.
 *
 * These constants name the same wire strings without changing them: every
 * value here is byte-for-byte identical to the literal it replaces, so the
 * status format on the wire (consumed by chat response shaping, job artifacts,
 * and pipeline steps) is unchanged. This class is the single source of truth
 * for the status/finish vocabulary the loop reasons about.
 */
final class DataMachineConversationStatus {

	/**
	 * Turn budget (max turns) was exceeded — the substrate stopped the loop.
	 */
	public const BUDGET_EXCEEDED = 'budget_exceeded';

	/**
	 * A runtime tool call is awaiting asynchronous fulfillment; the loop paused.
	 */
	public const RUNTIME_TOOL_PENDING = 'runtime_tool_pending';

	/**
	 * The conversation completed normally.
	 */
	public const COMPLETED = 'completed';

	/**
	 * The conversation failed (provider/transport error or fatal condition).
	 */
	public const FAILED = 'failed';

	/**
	 * The conversation was interrupted by an external interrupt source.
	 */
	public const INTERRUPTED = 'interrupted';

	/**
	 * DM diagnostic: the max-turn ceiling was reached before completion.
	 *
	 * Not an upstream substrate status — derived by the normalizer and surfaced
	 * under metadata.datamachine — but kept here so the full status/finish
	 * vocabulary lives in one place.
	 */
	public const MAX_TURNS_REACHED = 'max_turns_reached';

	/**
	 * Loop-event type: the completion policy decided to stop the conversation.
	 */
	public const COMPLETION_POLICY_STOP = 'completion_policy_stop';

	/**
	 * Loop-event type: the completion policy decided to continue the conversation.
	 */
	public const COMPLETION_POLICY_CONTINUE = 'completion_policy_continue';

	/**
	 * Finish reason / error code for a provider-side request failure.
	 */
	public const PROVIDER_ERROR = 'provider_error';

	/**
	 * Statuses that mean the conversation did NOT complete successfully.
	 *
	 * Mirrors the historical inline check
	 * `! in_array( $status, array( 'budget_exceeded', 'interrupted', 'failed' ), true )`
	 * used to seed the `completed` diagnostic.
	 *
	 * @return string[]
	 */
	public static function incompleteStatuses(): array {
		return array(
			self::BUDGET_EXCEEDED,
			self::INTERRUPTED,
			self::FAILED,
		);
	}
}
