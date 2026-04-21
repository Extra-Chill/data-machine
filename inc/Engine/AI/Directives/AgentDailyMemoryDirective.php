<?php
/**
 * Agent Daily Memory Directive - Priority 35
 *
 * Injects recent daily memory archive files into the AI context for
 * agents that opt in. Each agent declares its own preference via
 * `agent_config.daily_memory` — this is NOT a global default.
 *
 * Only agents whose work benefits from session continuity (typically
 * the personal assistant agent) should opt in. Stateless agents
 * (alt-text generators, wiki builders, pipeline workers that have no
 * use for "what happened yesterday") leave it disabled so their
 * context window stays focused on the task at hand.
 *
 * This directive reads the real daily files on disk directly — there
 * is no stitched-together virtual file. Each day becomes its own
 * `system_text` block labelled with its actual date, so the AI can
 * tell files apart and reason about them temporally.
 *
 * Configuration shape in `agent_config`:
 *
 *     {
 *         "daily_memory": {
 *             "enabled":     true,   // default false
 *             "recent_days": 3       // default 3, clamped to [1, MAX_RECENT_DAYS]
 *         }
 *     }
 *
 * @package DataMachine\Engine\AI\Directives
 * @since   0.71.0
 */

namespace DataMachine\Engine\AI\Directives;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\FilesRepository\AgentMemory;
use DataMachine\Core\FilesRepository\DailyMemory;

defined( 'ABSPATH' ) || exit;

class AgentDailyMemoryDirective {

	/**
	 * Default days to inject when the agent opts in without specifying.
	 */
	const DEFAULT_RECENT_DAYS = 3;

	/**
	 * Hard ceiling on recent days — protects the context window from
	 * an agent config with a runaway value.
	 */
	const MAX_RECENT_DAYS = 14;

	/**
	 * Build directive outputs from recent daily files for opt-in agents.
	 *
	 * @param string      $provider_name AI provider identifier.
	 * @param array       $tools         Available tools.
	 * @param string|null $step_id       Pipeline step ID (null in chat).
	 * @param array       $payload       Request payload including agent_id, user_id, context.
	 * @return array Directive outputs (one system_text entry per daily file).
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$agent_id = (int) ( $payload['agent_id'] ?? 0 );
		$user_id  = (int) ( $payload['user_id'] ?? 0 );

		$config = self::resolve_config( $agent_id );
		if ( null === $config ) {
			return array();
		}

		$days = $config['recent_days'];
		if ( $days <= 0 ) {
			return array();
		}

		$daily = new DailyMemory( $user_id, $agent_id );

		$outputs    = array();
		$total_size = 0;
		$budget     = AgentMemory::MAX_FILE_SIZE;

		// Walk from newest to oldest so the freshest entries always
		// win the budget race when older days would push the total
		// past the threshold.
		for ( $offset = 0; $offset < $days; $offset++ ) {
			$date  = gmdate( 'Y-m-d', strtotime( "-{$offset} days" ) );
			$parts = DailyMemory::parse_date( $date );
			if ( ! $parts ) {
				continue;
			}

			if ( ! $daily->exists( $parts['year'], $parts['month'], $parts['day'] ) ) {
				continue;
			}

			$result = $daily->read( $parts['year'], $parts['month'], $parts['day'] );
			if ( empty( $result['success'] ) ) {
				continue;
			}

			$body = trim( $result['content'] ?? '' );
			if ( '' === $body ) {
				continue;
			}

			$block      = "## Daily Memory: {$date}\n\n{$body}";
			$block_size = strlen( $block );

			if ( $total_size > 0 && ( $total_size + $block_size ) > $budget ) {
				break;
			}

			$outputs[]   = array(
				'type'    => 'system_text',
				'content' => $block,
			);
			$total_size += $block_size;
		}

		return $outputs;
	}

	/**
	 * Resolve and normalize the daily memory config for an agent.
	 *
	 * Returns null when the agent is missing, hasn't opted in, or the
	 * configured recent_days is zero or negative. Callers treat null
	 * as "skip injection entirely."
	 *
	 * @param int $agent_id Agent ID from the execution payload.
	 * @return array{enabled: bool, recent_days: int}|null
	 */
	private static function resolve_config( int $agent_id ): ?array {
		if ( $agent_id <= 0 ) {
			return null;
		}

		$agents = new Agents();
		$agent  = $agents->get_agent( $agent_id );
		if ( ! $agent ) {
			return null;
		}

		$agent_config = isset( $agent['agent_config'] ) && is_array( $agent['agent_config'] )
			? $agent['agent_config']
			: array();

		$raw = $agent_config['daily_memory'] ?? array();
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$enabled = ! empty( $raw['enabled'] );
		if ( ! $enabled ) {
			return null;
		}

		$days = isset( $raw['recent_days'] ) ? (int) $raw['recent_days'] : self::DEFAULT_RECENT_DAYS;
		if ( $days < 1 ) {
			return null;
		}
		if ( $days > self::MAX_RECENT_DAYS ) {
			$days = self::MAX_RECENT_DAYS;
		}

		return array(
			'enabled'     => true,
			'recent_days' => $days,
		);
	}
}

// Self-register in the directive system.
// Priority 35 = after core memory files (20), before pipeline directives (40+).
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'    => AgentDailyMemoryDirective::class,
			'priority' => 35,
			'modes'    => array( 'chat', 'pipeline' ),
		);
		return $directives;
	}
);
