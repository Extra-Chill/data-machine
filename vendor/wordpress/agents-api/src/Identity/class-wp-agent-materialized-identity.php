<?php
/**
 * Materialized Agent Identity
 *
 * Immutable value object describing a durable agent instance already resolved
 * by an identity store.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\Identity;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Materialized_Identity {

	/**
	 * @param int                 $id            Durable identity store ID.
	 * @param WP_Agent_Identity_Scope  $scope         Logical identity scope.
	 * @param array<string,mixed> $config        Materialized agent configuration.
	 * @param array<string,mixed> $meta          Store/product metadata.
	 * @param int|null            $created_at    Unix timestamp of first materialization, or null if unknown.
	 * @param int|null            $updated_at    Unix timestamp of last update, or null if unknown.
	 */
	public function __construct(
		public readonly int $id,
		public readonly WP_Agent_Identity_Scope $scope,
		public readonly array $config = array(),
		public readonly array $meta = array(),
		public readonly ?int $created_at = null,
		public readonly ?int $updated_at = null,
	) {
		if ( 1 > $this->id ) {
			throw new \InvalidArgumentException( 'Materialized agent identity id must be a positive integer.' );
		}
	}

	/**
	 * Returns a copy with replacement configuration.
	 *
	 * @param array<string,mixed> $config Replacement configuration.
	 * @return self
	 */
	public function with_config( array $config ): self {
		return new self( $this->id, $this->scope, $config, $this->meta, $this->created_at, $this->updated_at );
	}

	/**
	 * Returns a copy with replacement metadata.
	 *
	 * @param array<string,mixed> $meta Replacement metadata.
	 * @return self
	 */
	public function with_meta( array $meta ): self {
		return new self( $this->id, $this->scope, $this->config, $meta, $this->created_at, $this->updated_at );
	}

	/**
	 * Stable string key for cache/map lookups.
	 *
	 * @return string
	 */
	public function key(): string {
		return (string) $this->id;
	}

	/**
	 * Exports the normalized identity payload.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		$normalized_scope = $this->scope->normalize();

		return array(
			'id'            => $this->id,
			'agent_slug'    => $normalized_scope->agent_slug,
			'owner_user_id' => $normalized_scope->owner_user_id,
			'instance_key'  => $normalized_scope->instance_key,
			'config'        => $this->config,
			'meta'          => $this->meta,
			'created_at'    => $this->created_at,
			'updated_at'    => $this->updated_at,
		);
	}
}
