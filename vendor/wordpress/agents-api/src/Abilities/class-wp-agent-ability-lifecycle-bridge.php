<?php
/**
 * Bridges Abilities API execution lifecycle filters into substrate observers
 * and envelopes.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Abilities;

use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Store;
use AgentsAPI\AI\WP_Agent_Message;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges WP_Ability execution lifecycle filters into substrate-level actions
 * and envelopes.
 *
 * WordPress 7.1 introduced four execution lifecycle filters on `WP_Ability`:
 * `wp_pre_execute_ability`, `wp_ability_normalize_input`,
 * `wp_ability_permission_result`, and `wp_ability_execute_result`. This bridge
 * adopts them slice by slice (see #94).
 *
 * Wired today:
 * - `wp_ability_invoked` -> `agents_api_ability_invoked` action. Fires at the
 *   top of `execute()` for every call, before any processing, so observers see
 *   invocations that never reach a result (validation failure, permission
 *   denial, pre-execute short-circuit).
 * - `wp_ability_execute_result` -> `agents_api_ability_executed` action.
 *   Telemetry without each ability author opting in.
 * - `wp_pre_execute_ability` -> decision-driven approval gate. Hosts decide
 *   via {@see self::FILTER_PRE_EXECUTE_DECISION}; the bridge handles the
 *   sentinel, minting, staging, and `approval_required` envelope.
 *
 * The invoked and execute-result observers return the filter/value input
 * unchanged. The pre-execute gate short-circuits with an `approval_required`
 * envelope when the host signals approval is needed, and passes through
 * otherwise.
 *
 * On WordPress < 7.1 the underlying filters are never applied, so registered
 * handlers stay idle.
 */
class WP_Agent_Ability_Lifecycle_Bridge {

	public const ACTION_ABILITY_INVOKED      = 'agents_api_ability_invoked';
	public const ACTION_ABILITY_EXECUTED     = 'agents_api_ability_executed';
	public const FILTER_PRE_EXECUTE_DECISION = 'agents_api_ability_pre_execute_decision';
	public const FILTER_PENDING_ACTION_STORE = 'wp_agent_pending_action_store';

	/**
	 * Register lifecycle-filter handlers with WordPress.
	 *
	 * Idempotent for repeated calls when WordPress de-duplicates filter
	 * registration through `has_filter()`. Callers that wire this from a host
	 * adapter should still call it once at bootstrap.
	 */
	public static function register(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_action( 'wp_ability_invoked', array( __CLASS__, 'observe_invoked' ), 10, 3 );
		add_filter( 'wp_ability_execute_result', array( __CLASS__, 'observe_execute_result' ), 10, 4 );
		add_filter( 'wp_pre_execute_ability', array( __CLASS__, 'gate_pre_execute' ), 10, 4 );
	}

	/**
	 * Observer for the `wp_ability_invoked` action.
	 *
	 * Re-emits `agents_api_ability_invoked` at the entry point of every ability
	 * call. Unlike {@see self::ACTION_ABILITY_EXECUTED}, which only fires on the
	 * registered execute_callback's success path, this fires for every call
	 * regardless of outcome, so observers can record invocations that fail input
	 * validation, fail the permission check, or get short-circuited before the
	 * callback runs. The input is the raw value passed to `execute()`, before
	 * normalization.
	 *
	 * @param string $ability_name Ability name.
	 * @param mixed  $input        Raw input passed to execute(), before normalization.
	 * @param object $ability      `WP_Ability` instance.
	 */
	public static function observe_invoked( string $ability_name, $input, $ability ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( self::ACTION_ABILITY_INVOKED, $ability_name, $input, $ability );
		}
	}

	/**
	 * Observer for the `wp_ability_execute_result` filter.
	 *
	 * Emits `agents_api_ability_executed` with the same shape exposed by the
	 * underlying filter, and returns the result unchanged so other handlers
	 * downstream see exactly what the registered execute_callback produced.
	 *
	 * @param mixed  $result       Result returned by the ability's execute_callback, or `WP_Error`.
	 * @param string $ability_name Ability name.
	 * @param mixed  $input        Normalized input passed to the ability.
	 * @param object $ability      `WP_Ability` instance.
	 * @return mixed The original result, unchanged.
	 */
	public static function observe_execute_result( $result, string $ability_name, $input, $ability ) {
		if ( function_exists( 'do_action' ) ) {
			do_action( self::ACTION_ABILITY_EXECUTED, $ability_name, $result, $input, $ability );
		}

		return $result;
	}

	/**
	 * Decision-driven handler for the `wp_pre_execute_ability` filter.
	 *
	 * Hosts opt into approval gating per call by hooking
	 * {@see self::FILTER_PRE_EXECUTE_DECISION}. The decision filter receives
	 * (null, ability_name, input, ability) and returns one of:
	 *
	 * - `null` to let the call proceed without approval.
	 * - A `WP_Agent_Pending_Action` instance the host already minted.
	 * - An array shape `WP_Agent_Pending_Action::from_array()` accepts.
	 *
	 * On a non-null decision the bridge mints (when needed), best-effort stages
	 * through {@see self::FILTER_PENDING_ACTION_STORE}, and returns the canonical
	 * `approval_required` envelope so
	 * `WP_Agent_Conversation_Loop::mediate_tool_calls()` can surface it as an
	 * approval pause instead of running the call.
	 *
	 * Sentinel pass-through is honored: when another consumer already
	 * short-circuited the filter (so `$pre` is not a `WP_Filter_Sentinel`), the
	 * bridge returns `$pre` untouched so stacked consumers can coexist.
	 *
	 * @param mixed  $pre          Sentinel from core, or another handler's short-circuit value.
	 * @param string $ability_name Ability name.
	 * @param mixed  $input        Raw input passed to execute().
	 * @param object $ability      `WP_Ability` instance.
	 * @return mixed Original `$pre`, or the approval_required envelope on short-circuit.
	 */
	public static function gate_pre_execute( $pre, string $ability_name, $input, $ability ) {
		if ( ! ( $pre instanceof \WP_Filter_Sentinel ) ) {
			return $pre;
		}

		if ( ! function_exists( 'apply_filters' ) ) {
			return $pre;
		}

		$decision = apply_filters( self::FILTER_PRE_EXECUTE_DECISION, null, $ability_name, $input, $ability );
		if ( null === $decision ) {
			return $pre;
		}

		$pending = $decision instanceof WP_Agent_Pending_Action
			? $decision
			: WP_Agent_Pending_Action::from_array( self::string_keyed_array( $decision ) );

		$store = apply_filters( self::FILTER_PENDING_ACTION_STORE, null );
		if ( $store instanceof WP_Agent_Pending_Action_Store ) {
			$store->store( $pending );
		}

		$pending_data = $pending->to_array();
		$summary      = $pending->get_summary();

		return WP_Agent_Message::approvalRequired(
			$summary,
			array(
				'action_id'  => $pending_data['action_id'],
				'kind'       => $pending_data['kind'],
				'summary'    => $pending_data['summary'],
				'preview'    => $pending_data['preview'],
				'expires_at' => $pending_data['expires_at'],
			),
			array(
				'ability_name' => $ability_name,
			)
		);
	}

	/**
	 * Normalize a filter-provided decision payload to string-keyed array input.
	 *
	 * @param mixed $value Raw filter value.
	 * @return array<string,mixed>
	 */
	private static function string_keyed_array( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}

		return $result;
	}
}
