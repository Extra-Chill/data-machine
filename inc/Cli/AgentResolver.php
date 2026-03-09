<?php
/**
 * CLI Agent Resolver
 *
 * Resolves a --agent flag value to an agent_id. Accepts agent slug
 * or numeric agent ID. Returns null when omitted (no agent filter).
 *
 * When --agent is provided, also resolves the associated user_id from
 * the agent's owner_id for commands that need both scoping dimensions.
 *
 * @package DataMachine\Cli
 * @since 0.40.0
 */

namespace DataMachine\Cli;

use WP_CLI;
use DataMachine\Core\Database\Agents\Agents;

defined( 'ABSPATH' ) || exit;

class AgentResolver {

	/**
	 * Resolve --agent flag to an agent_id.
	 *
	 * Returns null when no --agent flag is provided (unscoped).
	 * Accepts agent slug (string) or numeric agent ID.
	 *
	 * @param array $assoc_args Command arguments (checks for 'agent' key).
	 * @return int|null Agent ID, or null if not specified.
	 */
	public static function resolve( array $assoc_args ): ?int {
		$agent_value = $assoc_args['agent'] ?? null;

		if ( null === $agent_value || '' === $agent_value ) {
			return null;
		}

		$agents_repo = new Agents();

		// Numeric: treat as agent ID.
		if ( is_numeric( $agent_value ) ) {
			$agent = $agents_repo->get_agent( (int) $agent_value );
			if ( ! $agent ) {
				WP_CLI::error( sprintf( 'Agent ID %d not found.', (int) $agent_value ) );
			}
			return (int) $agent['agent_id'];
		}

		// String: treat as agent slug.
		$agent = $agents_repo->get_by_slug( sanitize_title( $agent_value ) );
		if ( ! $agent ) {
			// Suggest available agents.
			$all_agents = $agents_repo->get_all();
			$slugs      = array_column( $all_agents, 'agent_slug' );
			$hint       = ! empty( $slugs )
				? sprintf( ' Available: %s', implode( ', ', $slugs ) )
				: '';
			WP_CLI::error( sprintf( 'Agent "%s" not found.%s', $agent_value, $hint ) );
		}

		return (int) $agent['agent_id'];
	}

	/**
	 * Resolve --agent flag to full agent context.
	 *
	 * Returns an array with agent_id and owner_id (user_id), or null
	 * values when no --agent flag is provided.
	 *
	 * @param array $assoc_args Command arguments.
	 * @return array{agent_id: int|null, user_id: int|null}
	 */
	public static function resolveContext( array $assoc_args ): array {
		$agent_id = self::resolve( $assoc_args );

		if ( null === $agent_id ) {
			return array(
				'agent_id' => null,
				'user_id'  => null,
			);
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		return array(
			'agent_id' => $agent_id,
			'user_id'  => $agent ? (int) $agent['owner_id'] : null,
		);
	}

	/**
	 * Build scoping input from CLI flags.
	 *
	 * Resolves --agent (preferred) or --user (fallback) into input
	 * parameters suitable for ability calls. Agent scoping takes
	 * precedence over user scoping.
	 *
	 * @param array $assoc_args Command arguments.
	 * @return array Scoping parameters (agent_id and/or user_id keys).
	 */
	public static function buildScopingInput( array $assoc_args ): array {
		// --agent takes precedence.
		$agent_id = self::resolve( $assoc_args );
		if ( null !== $agent_id ) {
			return array( 'agent_id' => $agent_id );
		}

		// Fall back to --user.
		$user_id = UserResolver::resolve( $assoc_args );
		if ( $user_id > 0 ) {
			return array( 'user_id' => $user_id );
		}

		// No scoping — return empty (show all).
		return array();
	}
}
