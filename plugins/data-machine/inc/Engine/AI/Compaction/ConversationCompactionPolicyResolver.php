<?php
/**
 * Conversation Compaction Policy Resolver.
 *
 * Resolves the per-turn conversation compaction policy for a live chat/system
 * conversation from the acting agent's persisted `agent_config`. Sibling to
 * ToolPolicyResolver (IF a tool is visible), MemoryPolicyResolver (WHICH memory
 * files inject), ActionPolicyResolver (HOW a tool executes), and
 * DirectivePolicyResolver (WHICH directives apply). Where those answer their
 * respective questions, this answers "should the running transcript be compacted
 * before each model dispatch, and with what policy?"
 *
 * The Agents API substrate (WP_Agent_Conversation_Compaction) owns the policy
 * contract and the safe transcript surgery; this resolver only reads the
 * agent's declarative opt-in and hands a normalized policy array to the loop.
 *
 * Backward compatibility: compaction is DISABLED by default. Agents that do not
 * configure `conversation_compaction_policy` (and do not flip
 * `supports_conversation_compaction`) resolve to a disabled policy, which makes
 * the substrate's maybe_compact() a strict no-op.
 *
 * Supported agent_config shape:
 *
 *   {
 *     "supports_conversation_compaction": true,
 *     "conversation_compaction_policy": {
 *       "enabled": true,
 *       "max_messages": 40,
 *       "recent_messages": 12,
 *       "summary_model": "...",
 *       "summary_provider": "...",
 *       ...
 *     }
 *   }
 *
 * @package DataMachine\Engine\AI\Compaction
 */

namespace DataMachine\Engine\AI\Compaction;

use AgentsAPI\AI\WP_Agent_Conversation_Compaction;
use DataMachine\Core\Database\Agents\Agents;

defined( 'ABSPATH' ) || exit;

class ConversationCompactionPolicyResolver {

	/**
	 * Resolve the normalized compaction policy for a conversation turn.
	 *
	 * Always returns a normalized policy array (never null) so callers can pass
	 * it straight to the loop. When the agent has not opted in, the returned
	 * policy has `enabled => false`, which the substrate treats as a no-op.
	 *
	 * @param array $args {
	 *     Resolution arguments describing the request.
	 *
	 *     @type int|null      $agent_id    Acting agent ID for per-agent policy.
	 *     @type array         $modes       Active agent mode slugs (for the filter).
	 *     @type array<string,mixed> $overrides Optional policy overrides merged last
	 *                                          (for example a resolved summary model).
	 * }
	 * @return array<string,mixed> Normalized compaction policy.
	 */
	public function resolve( array $args ): array {
		$agent_id  = isset( $args['agent_id'] ) ? (int) $args['agent_id'] : 0;
		$modes     = is_array( $args['modes'] ?? null ) ? $args['modes'] : array();
		$overrides = is_array( $args['overrides'] ?? null ) ? $args['overrides'] : array();

		$agent_policy = $agent_id > 0 ? $this->getAgentCompactionPolicy( $agent_id ) : null;

		$policy = WP_Agent_Conversation_Compaction::default_policy();
		if ( is_array( $agent_policy ) ) {
			$policy = array_merge( $policy, $agent_policy );
		}
		if ( ! empty( $overrides ) ) {
			$policy = array_merge( $policy, $overrides );
		}

		/**
		 * Filter the resolved conversation compaction policy.
		 *
		 * Lets eval/training runners or product code force-enable, force-disable,
		 * or retune compaction for a given request without editing agent config.
		 *
		 * @param array<string,mixed> $policy Resolved (pre-normalization) policy.
		 * @param array               $args   Resolution arguments.
		 */
		$policy = apply_filters( 'datamachine_resolved_conversation_compaction_policy', $policy, $args );

		// Normalize through the substrate contract so the loop always receives a
		// well-formed policy with safe defaults applied.
		$normalized = WP_Agent_Conversation_Compaction::normalize_policy( is_array( $policy ) ? $policy : array() );

		unset( $modes ); // Reserved for future mode-scoped resolution; kept for filter parity.

		return $normalized;
	}

	/**
	 * Read an agent's conversation compaction policy from agent_config.
	 *
	 * Returns null when the agent does not exist, has not opted in, or the
	 * configured policy is structurally invalid. Mirrors the null-for-no-op
	 * pattern used by the sibling policy resolvers.
	 *
	 * Opt-in is satisfied by either an explicit `conversation_compaction_policy`
	 * array with `enabled => true`, or the `supports_conversation_compaction`
	 * capability flag (which enables compaction with substrate defaults).
	 *
	 * @param int $agent_id Agent ID.
	 * @return array<string,mixed>|null Raw policy overrides, or null for no-op.
	 */
	public function getAgentCompactionPolicy( int $agent_id ): ?array {
		if ( $agent_id <= 0 ) {
			return null;
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return null;
		}

		$config = is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array();

		$supports = ! empty( $config['supports_conversation_compaction'] );
		$policy   = is_array( $config['conversation_compaction_policy'] ?? null )
			? $config['conversation_compaction_policy']
			: array();

		// No opt-in signal at all: leave compaction disabled (no-op).
		if ( ! $supports && empty( $policy ) ) {
			return null;
		}

		// The capability flag, when present, enables compaction unless the policy
		// explicitly turns it off. An explicit policy `enabled` key always wins.
		if ( ! array_key_exists( 'enabled', $policy ) ) {
			$policy['enabled'] = $supports;
		}

		// A policy that resolves to disabled is a no-op; report null so the
		// resolver falls back to the disabled default cleanly.
		if ( empty( $policy['enabled'] ) ) {
			return null;
		}

		return $policy;
	}
}
