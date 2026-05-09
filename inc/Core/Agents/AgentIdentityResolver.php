<?php
/**
 * Agent identity resolver.
 *
 * @package DataMachine\Core\Agents
 */

namespace DataMachine\Core\Agents;

use DataMachine\Core\Database\Agents\Agents as AgentsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves public agent slugs and internal agent IDs through one primitive.
 */
class AgentIdentityResolver {

	/**
	 * Agents repository.
	 *
	 * @var AgentsRepository
	 */
	private AgentsRepository $agents_repository;

	/**
	 * Constructor.
	 *
	 * @param AgentsRepository|null $agents_repository Optional repository override.
	 */
	public function __construct( ?AgentsRepository $agents_repository = null ) {
		$this->agents_repository = $agents_repository ?? new AgentsRepository();
	}

	/**
	 * Normalize an agent slug the same way Data Machine stores agent slugs.
	 *
	 * @param string $agent_slug Raw slug.
	 * @return string Normalized slug.
	 */
	public static function normalize_agent_slug( string $agent_slug ): string {
		return sanitize_title( trim( $agent_slug ) );
	}

	/**
	 * Resolve a mixed agent identifier into a canonical identity.
	 *
	 * @param int|string|array $agent Agent ID, agent slug, or context array.
	 * @return AgentIdentity Resolved identity.
	 */
	public function resolve_agent_identity( int|string|array $agent ): AgentIdentity {
		if ( is_array( $agent ) ) {
			return $this->resolve_from_context( $agent );
		}

		if ( is_int( $agent ) || is_numeric( $agent ) ) {
			return $this->resolve_from_id( (int) $agent );
		}

		return $this->resolve_from_slug( (string) $agent );
	}

	/**
	 * Resolve any scalar agent identifier to an internal agent ID.
	 *
	 * @param int|string $agent Agent ID or slug.
	 * @return int Internal agent ID.
	 */
	public function resolve_agent_id( int|string $agent ): int {
		return $this->resolve_agent_identity( $agent )->agent_id;
	}

	/**
	 * Resolve any scalar agent identifier to a public agent slug.
	 *
	 * @param int|string $agent Agent ID or slug.
	 * @return string Public agent slug.
	 */
	public function resolve_agent_slug( int|string $agent ): string {
		return $this->resolve_agent_identity( $agent )->agent_slug;
	}

	/**
	 * Resolve identity from a persisted or runtime context array.
	 *
	 * `agent_slug` is canonical for portable contexts. `agent_id` remains a
	 * fallback for older persisted contexts that predate slug snapshots.
	 *
	 * @param array $context Context containing agent_id and/or agent_slug.
	 * @return AgentIdentity Resolved identity.
	 */
	private function resolve_from_context( array $context ): AgentIdentity {
		$agent_id   = isset( $context['agent_id'] ) && is_numeric( $context['agent_id'] ) ? (int) $context['agent_id'] : 0;
		$agent_slug = isset( $context['agent_slug'] ) ? self::normalize_agent_slug( (string) $context['agent_slug'] ) : '';

		if ( '' !== $agent_slug ) {
			try {
				return $this->resolve_from_slug( $agent_slug );
			} catch ( \InvalidArgumentException $e ) {
				if ( $agent_id <= 0 ) {
					throw $e;
				}
			}
		}

		if ( $agent_id > 0 ) {
			return $this->resolve_from_id( $agent_id );
		}

		throw new \InvalidArgumentException( 'Agent identity requires agent_id or agent_slug.' );
	}

	/**
	 * Resolve identity from an internal agent ID.
	 *
	 * @param int $agent_id Internal agent ID.
	 * @return AgentIdentity Resolved identity.
	 */
	private function resolve_from_id( int $agent_id ): AgentIdentity {
		if ( $agent_id <= 0 ) {
			throw new \InvalidArgumentException( 'Agent ID must be a positive integer.' );
		}

		$agent = $this->agents_repository->get_agent( $agent_id );
		if ( ! $agent ) {
			throw new \InvalidArgumentException( sprintf( 'Agent ID %d not found.', absint( $agent_id ) ) );
		}

		return AgentIdentity::from_row( $agent );
	}

	/**
	 * Resolve identity from a public agent slug.
	 *
	 * @param string $agent_slug Public agent slug.
	 * @return AgentIdentity Resolved identity.
	 */
	private function resolve_from_slug( string $agent_slug ): AgentIdentity {
		$agent_slug = self::normalize_agent_slug( $agent_slug );
		if ( '' === $agent_slug ) {
			throw new \InvalidArgumentException( 'Agent slug must not be empty.' );
		}

		$agent = $this->agents_repository->get_by_slug( $agent_slug );
		if ( ! $agent ) {
			throw new \InvalidArgumentException( sprintf( 'Agent "%s" not found.', esc_html( $agent_slug ) ) );
		}

		return AgentIdentity::from_row( $agent );
	}
}
