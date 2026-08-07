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
use DataMachine\Core\Agents\AgentIdentityResolver;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\FilesRepository\DirectoryManager;

defined( 'ABSPATH' ) || exit;

class AgentResolver {

	private const ACTIVE_AGENT_META_KEY = 'datamachine_active_agent_slug';

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

		$resolver = new AgentIdentityResolver();

		try {
			return $resolver->resolve_agent_id( $agent_value );
		} catch ( \InvalidArgumentException $e ) {
			// Suggest available agents.
			$agents_repo = new Agents();
			$all_agents  = $agents_repo->get_all();
			$slugs       = array_column( $all_agents, 'agent_slug' );
			$hint        = ! empty( $slugs )
				? sprintf( ' Available: %s', implode( ', ', $slugs ) )
				: '';
			WP_CLI::error( $e->getMessage() . $hint );
		}
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
	 * Resolve effective agent context for agent-scoped operations.
	 *
	 * Explicit `--agent` remains authoritative. Without it, the Agents API
	 * effective-agent resolver may derive the agent from the execution principal
	 * or from a single unambiguous owner candidate. Ambiguous owner fallbacks fail
	 * closed instead of silently selecting the first owned agent.
	 *
	 * @param array $assoc_args Command arguments.
	 * @return array{agent_id: int|null, user_id: int|null, agent_slug: string|null}
	 */
	public static function resolveEffectiveContext( array $assoc_args ): array {
		$explicit = self::resolveContext( $assoc_args );
		if ( null !== $explicit['agent_id'] ) {
			$agents_repo = new Agents();
			$agent       = $agents_repo->get_agent( (int) $explicit['agent_id'] );

			return array(
				'agent_id'   => (int) $explicit['agent_id'],
				'user_id'    => null !== $explicit['user_id'] ? (int) $explicit['user_id'] : null,
				'agent_slug' => $agent ? (string) $agent['agent_slug'] : null,
			);
		}

		$directory_manager = new DirectoryManager();
		$user_id           = UserResolver::resolve( $assoc_args );
		$effective_user_id = $directory_manager->get_effective_user_id( $user_id );

		$agent_slug = self::resolvePrincipalAgentSlug();
		if ( '' === $agent_slug ) {
			$agent_slug = self::resolveEnvironmentAgentSlug();
		}
		if ( '' === $agent_slug ) {
			$agent_slug = self::resolveActiveAgentSlugForUser( $effective_user_id );
		}
		if ( '' === $agent_slug ) {
			$agent_slug = self::resolveOwnerFallbackAgentSlugForUser( $effective_user_id );
		}
		if ( '' === $agent_slug ) {
			return array(
				'agent_id'   => null,
				'user_id'    => $effective_user_id,
				'agent_slug' => null,
			);
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_by_slug( $agent_slug );
		if ( ! $agent ) {
			WP_CLI::error( sprintf( 'Resolved effective agent "%s" was not found.', $agent_slug ) );
		}

		return array(
			'agent_id'   => (int) $agent['agent_id'],
			'user_id'    => (int) $agent['owner_id'],
			'agent_slug' => (string) $agent['agent_slug'],
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

	/**
	 * Resolve the user's persisted active agent slug when it is still valid.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Active agent slug, or empty string when unset/invalid.
	 */
	private static function resolveActiveAgentSlugForUser( int $user_id ): string {
		if ( $user_id <= 0 || ! function_exists( 'get_user_meta' ) ) {
			return '';
		}

		$stored = get_user_meta( $user_id, self::ACTIVE_AGENT_META_KEY, true );
		$slug   = is_string( $stored ) ? sanitize_title( $stored ) : '';
		if ( '' === $slug ) {
			return '';
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_by_slug( $slug );
		if ( ! $agent ) {
			return '';
		}

		if ( (int) $agent['owner_id'] === $user_id ) {
			return $slug;
		}

		if ( ! class_exists( '\DataMachine\Core\Database\Agents\AgentAccess' ) ) {
			return '';
		}

		$grant = ( new \DataMachine\Core\Database\Agents\AgentAccess() )->get_access( (string) (int) $agent['agent_id'], $user_id );
		return $grant instanceof \WP_Agent_Access_Grant && $grant->role_meets( 'viewer' ) ? $slug : '';
	}

	/**
	 * Resolve an agent slug from the current Agents API execution principal.
	 *
	 * @return string Effective agent slug, or empty string when no principal exists.
	 */
	private static function resolvePrincipalAgentSlug(): string {
		if ( ! class_exists( '\\AgentsAPI\\AI\\WP_Agent_Effective_Agent_Resolver' ) ) {
			return '';
		}

		$principal_context = array();
		if ( class_exists( '\\AgentsAPI\\AI\\WP_Agent_Execution_Principal' ) ) {
			$principal_context['request_context'] = \AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_CLI;
		}

		try {
			return \AgentsAPI\AI\WP_Agent_Effective_Agent_Resolver::resolve(
				array(
					'resolve_principal' => true,
					'principal_context' => $principal_context,
				)
			);
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		return '';
	}

	/**
	 * Resolve an agent slug from the current CLI process environment.
	 *
	 * This gives coding-agent shells and scheduled CLI jobs a stable explicit
	 * context path without mutating the user's active-agent preference.
	 *
	 * @return string Effective agent slug, or empty string when unset/invalid.
	 */
	private static function resolveEnvironmentAgentSlug(): string {
		$env_slug = getenv( 'DATAMACHINE_AGENT_SLUG' );
		$slug     = is_string( $env_slug ) ? sanitize_title( $env_slug ) : '';
		if ( '' === $slug ) {
			return '';
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_by_slug( $slug );
		return $agent ? $slug : '';
	}

	/**
	 * Resolve an effective agent slug for an owner using the ambiguous-safe owner fallback.
	 *
	 * @param int $owner_user_id Owner WordPress user ID.
	 * @return string Effective agent slug, or empty string when none exists.
	 */
	private static function resolveOwnerFallbackAgentSlugForUser( int $owner_user_id ): string {
		if ( ! class_exists( '\\AgentsAPI\\AI\\WP_Agent_Effective_Agent_Resolver' ) ) {
			WP_CLI::error( 'Agents API effective agent resolver is unavailable. Update the agents-api dependency or pass --agent explicitly.' );
		}

		$agents_repo = new Agents();
		$owned       = $agents_repo->get_all_by_owner_id( $owner_user_id );
		$slugs       = array_values( array_filter( array_map( static fn( $agent ) => (string) ( $agent['agent_slug'] ?? '' ), $owned ) ) );

		try {
			return \AgentsAPI\AI\WP_Agent_Effective_Agent_Resolver::resolve(
				array(
					'owner_user_id'     => $owner_user_id,
					'owner_agent_slugs' => $slugs,
				)
			);
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		return '';
	}
}
