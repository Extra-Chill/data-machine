<?php
/**
 * Deterministic tool-call mediation runner.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public primitive for normalizing and mediating one provider turn's tool calls.
 */
class WP_Agent_Tool_Mediation_Runner {

	/**
	 * Mediate tool calls from one deterministic provider turn.
	 *
	 * @param array<int, array<string, mixed>>            $transcript   Transcript before this mediated turn.
	 * @param array<string, mixed>                        $turn_result  Turn result with `tool_calls` and optional content/messages.
	 * @param WP_Agent_Tool_Executor                      $executor     Tool executor adapter.
	 * @param array<string, array<string, mixed>>         $declarations Tool declarations keyed by name.
	 * @param array<string, mixed>                        $options      Execution policy and observers.
	 * @return array{messages: array<int, array<string, mixed>>, tool_execution_results: array<int, array<string, mixed>>, tool_events: array<int, array<string, mixed>>, tool_audit_events: array<int, array<string, mixed>>, events: array<int, array<string, mixed>>, conversation_complete: bool, exceeded_budget: string|null, approval_required: array<string, mixed>|null, runtime_tool_pending: array<string, mixed>|null, spin_signatures: array<int, WP_Agent_Spin_Signature>}
	 */
	public static function run( array $transcript, array $turn_result, WP_Agent_Tool_Executor $executor, array $declarations, array $options = array() ): array {
		$turn_context       = isset( $options['turn_context'] ) && is_array( $options['turn_context'] ) ? self::normalize_assoc_array( $options['turn_context'] ) : array();
		$turn               = isset( $options['turn'] ) && is_int( $options['turn'] ) ? $options['turn'] : 1;
		$completion_policy  = $options['completion_policy'] ?? null;
		$failure_tracker    = $options['identical_failure_tracker'] ?? null;
		$result_truncator   = $options['tool_result_truncator'] ?? null;
		$runtime_tool_store = $options['runtime_tool_request_store'] ?? null;
		$budgets            = self::normalize_budgets( $options['budgets'] ?? array() );

		return WP_Agent_Conversation_Loop::mediate_tool_calls(
			self::normalize_assoc_array( $turn_result ),
			$executor,
			$declarations,
			$completion_policy instanceof WP_Agent_Conversation_Completion_Policy ? $completion_policy : null,
			$turn_context,
			max( 1, $turn ),
			is_callable( $options['on_event'] ?? null ) ? $options['on_event'] : null,
			$budgets,
			$failure_tracker instanceof WP_Agent_Identical_Failure_Tracker ? $failure_tracker : null,
			$result_truncator instanceof WP_Agent_Tool_Result_Truncator ? $result_truncator : null,
			WP_Agent_Message::normalize_many( $transcript ),
			is_callable( $options['pre_tool_mediator'] ?? null ) ? $options['pre_tool_mediator'] : null,
			isset( $options['prior_tool_results'] ) && is_array( $options['prior_tool_results'] ) ? self::normalize_array_list( $options['prior_tool_results'] ) : array(),
			is_callable( $options['post_tool_result_diagnostics'] ?? null ) ? $options['post_tool_result_diagnostics'] : null,
			$runtime_tool_store instanceof WP_Agent_Runtime_Tool_Request_Store ? $runtime_tool_store : null
		);
	}

	/**
	 * Normalize budget option values.
	 *
	 * @param mixed $value Raw budget option.
	 * @return array<string, WP_Agent_Iteration_Budget> Budgets keyed by name.
	 */
	private static function normalize_budgets( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$budgets = array();
		foreach ( $value as $budget ) {
			if ( $budget instanceof WP_Agent_Iteration_Budget ) {
				$budgets[ $budget->name() ] = $budget;
			}
		}

		return $budgets;
	}

	/**
	 * Normalize arbitrary associative arrays to string-keyed arrays.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, mixed> String-keyed array.
	 */
	private static function normalize_assoc_array( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $item;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize a list of arrays to a typed list shape.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, array<string, mixed>> List of string-keyed arrays.
	 */
	private static function normalize_array_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$normalized[] = self::normalize_assoc_array( $item );
			}
		}

		return $normalized;
	}
}
