<?php
/**
 * Agent Identity Scope
 *
 * Store-neutral value object for resolving a durable materialized agent
 * instance from a declarative agent slug, owner, and product-defined key.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\Identity;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Identity_Scope {

	/**
	 * @param string $agent_slug    Declarative agent slug registered with Agents API.
	 * @param int    $owner_user_id Effective WordPress owner user ID. 0 = shared/no owner.
	 * @param string $instance_key  Product-defined stable instance key. Defaults to 'default'.
	 */
	public function __construct(
		public readonly string $agent_slug,
		public readonly int $owner_user_id = 0,
		public readonly string $instance_key = 'default',
	) {
		if ( '' === self::normalize_agent_slug( $this->agent_slug ) ) {
			throw new \InvalidArgumentException( 'Agent identity scope agent_slug cannot be empty.' );
		}

		if ( 0 > $this->owner_user_id ) {
			throw new \InvalidArgumentException( 'Agent identity scope owner_user_id cannot be negative.' );
		}

		if ( '' === self::normalize_instance_key( $this->instance_key ) ) {
			throw new \InvalidArgumentException( 'Agent identity scope instance_key cannot be empty.' );
		}
	}

	/**
	 * Creates a normalized copy of the scope.
	 *
	 * @return self
	 */
	public function normalize(): self {
		return new self(
			self::normalize_agent_slug( $this->agent_slug ),
			$this->owner_user_id,
			self::normalize_instance_key( $this->instance_key )
		);
	}

	/**
	 * Stable string key for cache/map lookups.
	 *
	 * @return string
	 */
	public function key(): string {
		$normalized = $this->normalize();

		return sprintf( '%s:%d:%s', $normalized->agent_slug, $normalized->owner_user_id, $normalized->instance_key );
	}

	/**
	 * Normalizes a registered agent slug.
	 *
	 * @param string $agent_slug Raw slug.
	 * @return string
	 */
	public static function normalize_agent_slug( string $agent_slug ): string {
		if ( function_exists( 'sanitize_title' ) ) {
			return sanitize_title( $agent_slug );
		}

		$agent_slug = strtolower( $agent_slug );
		$agent_slug = preg_replace( '/[^a-z0-9]+/', '-', $agent_slug );

		return trim( (string) $agent_slug, '-' );
	}

	/**
	 * Normalizes a product-defined materialized instance key.
	 *
	 * @param string $instance_key Raw instance key.
	 * @return string
	 */
	public static function normalize_instance_key( string $instance_key ): string {
		$instance_key = strtolower( trim( $instance_key ) );
		$instance_key = preg_replace( '/\s*\/\s*/', '/', $instance_key );
		$instance_key = is_string( $instance_key ) ? $instance_key : '';
		$instance_key = preg_replace( '/[^a-z0-9_.:\/-]+/', '-', $instance_key );

		return trim( (string) $instance_key, '-/' );
	}
}
