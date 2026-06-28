<?php
/**
 * Normalizes a finished conversation-loop result into Data Machine diagnostics.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Derives the metadata.datamachine diagnostics from a finished loop result.
 *
 * The Agents API substrate returns a normalized conversation result carrying a
 * `status` string. Data Machine layers its own diagnostics on top — completed,
 * max_turns_reached, runtime_tool_pending, interrupted, completion-assertion
 * evaluation, and a human-readable warning. Historically that derivation was a
 * sequence of ~8 scattered if-blocks inside datamachine_run_conversation() that
 * re-read $result['status'] against bare string literals and computed the
 * `completed` flag in four different places.
 *
 * This class collapses that "result surgery" into a single decision path keyed
 * off DataMachineConversationStatus constants. It is a pure transform: given the
 * loop result plus the surrounding turn context, it returns the normalized
 * metadata and the (possibly overridden) status string. It does not mutate the
 * result in place and does not emit events — the caller owns those concerns.
 *
 * Behavior parity: the derived metadata and status are byte-for-byte identical
 * to the prior inline logic for every status path. This is a structure refactor,
 * not a behavior change.
 */
final class ConversationResultNormalizer {

	/**
	 * Normalize a finished loop result into Data Machine diagnostics.
	 *
	 * @param array                        $result                   Substrate-normalized conversation result.
	 * @param array                        $last_tool_calls          Tool calls from the latest turn.
	 * @param array                        $all_tool_calls           Tool calls accumulated across all turns.
	 * @param array                        $tool_execution_results   Enriched mediated tool-execution results.
	 * @param array                        $completion_nudges        Completion nudges recorded during the loop.
	 * @param bool                         $completion_policy_stopped Whether a completion_policy_stop event fired.
	 * @param int                          $turn_ceiling             Max-turn ceiling from the turn budget.
	 * @param DataMachineCompletionAssertions $assertions            Completion assertions for this run.
	 * @param array                        $loop_payload             Cleaned loop payload (assertion evaluation context).
	 * @return ConversationResultNormalization Normalized metadata and final status.
	 */
	public static function normalize(
		array $result,
		array $last_tool_calls,
		array $all_tool_calls,
		array $tool_execution_results,
		array $completion_nudges,
		bool $completion_policy_stopped,
		int $turn_ceiling,
		DataMachineCompletionAssertions $assertions,
		array $loop_payload
	): ConversationResultNormalization {
		$status            = (string) ( $result['status'] ?? '' );
		$status_overridden = false;

		$datamachine_metadata = array(
			'completed'       => ! isset( $result['error'] ) && ! in_array( $status, DataMachineConversationStatus::incompleteStatuses(), true ),
			'last_tool_calls' => $last_tool_calls,
			'tool_calls'      => $all_tool_calls,
		);
		if ( ! empty( $tool_execution_results ) ) {
			$datamachine_metadata['tool_execution_summary'] = datamachine_summarize_tool_execution_results( $tool_execution_results, false );
		}
		if ( DataMachineConversationStatus::INTERRUPTED === $status && isset( $result['interrupted'] ) ) {
			$datamachine_metadata['interrupted'] = $result['interrupted'];
		}
		$silent_max_turns_reached = ! empty( $last_tool_calls )
			&& (int) ( $result['turn_count'] ?? 0 ) >= $turn_ceiling
			&& DataMachineConversationStatus::BUDGET_EXCEEDED !== $status;
		if ( $silent_max_turns_reached ) {
			$datamachine_metadata['completed']         = false;
			$datamachine_metadata['max_turns_reached'] = true;
			$datamachine_metadata['warning']           = sprintf(
				'Maximum conversation turns (%d) reached. Response may be incomplete.',
				$turn_ceiling
			);
		}
		if ( DataMachineConversationStatus::RUNTIME_TOOL_PENDING === $status || ! empty( $result['runtime_tool_pending'] ) ) {
			$runtime_tool_requests                                 = is_array( $result['runtime_tool_pending'] ?? null ) ? array( $result['runtime_tool_pending'] ) : array();
			$datamachine_metadata['completed']                     = false;
			$datamachine_metadata['runtime_tool_pending']          = true;
			$datamachine_metadata['runtime_tool_pending_requests'] = $runtime_tool_requests;

			$status            = DataMachineConversationStatus::RUNTIME_TOOL_PENDING;
			$status_overridden = true;
		}
		if ( ! empty( $completion_nudges ) ) {
			$latest_nudge                                   = $completion_nudges[ count( $completion_nudges ) - 1 ];
			$datamachine_metadata['completion_nudge_count'] = count( $completion_nudges );
			$datamachine_metadata['completion_nudge']       = $latest_nudge['completion_nudge'] ?? '';
			$datamachine_metadata['completion_assertions_required']  = $latest_nudge['completion_assertions_required'] ?? array();
			$datamachine_metadata['completion_assertions_missing']   = $latest_nudge['completion_assertions_missing'] ?? array();
			$datamachine_metadata['completion_assertions_satisfied'] = $latest_nudge['completion_assertions_satisfied'] ?? array();
			if ( ! $completion_policy_stopped && (int) ( $result['turn_count'] ?? 0 ) >= $turn_ceiling ) {
				$datamachine_metadata['completed']         = false;
				$datamachine_metadata['max_turns_reached'] = true;
				$datamachine_metadata['warning']           = sprintf(
					'Maximum conversation turns (%d) reached before completion policy was satisfied.',
					$turn_ceiling
				);
			}
		}
		if ( $assertions->hasAssertions() ) {
			$evaluation_context = $loop_payload;
			$typed_artifacts    = datamachine_normalize_typed_artifact_outputs( $result );
			if ( ! empty( $typed_artifacts ) ) {
				$evaluation_engine_data                               = is_array( $evaluation_context['engine_data'] ?? null ) ? $evaluation_context['engine_data'] : array();
				$evaluation_engine_data['outputs']                    = is_array( $evaluation_engine_data['outputs'] ?? null ) ? $evaluation_engine_data['outputs'] : array();
				$evaluation_engine_data['outputs']['typed_artifacts'] = array_replace_recursive(
					is_array( $evaluation_engine_data['outputs']['typed_artifacts'] ?? null ) ? $evaluation_engine_data['outputs']['typed_artifacts'] : array(),
					$typed_artifacts
				);
				$evaluation_context['engine_data']                    = $evaluation_engine_data;
			}

			$evaluation = $assertions->evaluate( $evaluation_context, $result['final_content'] ?? '' );
			$datamachine_metadata['completion_assertions_required']  = $assertions->required();
			$datamachine_metadata['completion_assertions_missing']   = $evaluation['missing'];
			$datamachine_metadata['completion_assertions_satisfied'] = $evaluation['satisfied'];
			$datamachine_metadata['completion_assertions_complete']  = ! empty( $evaluation['complete'] );
			if ( ! empty( $evaluation['complete'] ) && DataMachineConversationStatus::BUDGET_EXCEEDED !== $status ) {
				$datamachine_metadata['completed'] = true;
			}
		}
		// Map upstream budget_exceeded status to DM's max-turn diagnostics for chat
		// response shaping without adding legacy aliases to the canonical result.
		if ( DataMachineConversationStatus::BUDGET_EXCEEDED === $status && in_array( $result['budget'] ?? '', array( 'conversation_turns', 'turns' ), true ) ) {
			$datamachine_metadata['max_turns_reached'] = true;
			$datamachine_metadata['completed']         = false;
			$datamachine_metadata['warning']           = sprintf(
				'Maximum conversation turns (%d) reached. Response may be incomplete.',
				$turn_ceiling
			);
		}

		return new ConversationResultNormalization( $datamachine_metadata, $status, $status_overridden );
	}
}
