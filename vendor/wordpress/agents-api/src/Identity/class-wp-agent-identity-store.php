<?php
/**
 * Materialized Agent Identity Store Interface
 *
 * Generic persistence contract for durable agent instances. The contract only
 * resolves identity records; access grants, scoped policy, token binding, and
 * product-specific runtime behavior stay in higher-level callers.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\Identity;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Identity_Store {

	/**
	 * Resolve an already-materialized identity by logical scope.
	 *
	 * @param WP_Agent_Identity_Scope $scope Logical identity scope.
	 * @return WP_Agent_Materialized_Identity|null Identity, or null when not materialized.
	 */
	public function resolve( WP_Agent_Identity_Scope $scope ): ?WP_Agent_Materialized_Identity;

	/**
	 * Retrieve a materialized identity by durable store ID.
	 *
	 * @param int $identity_id Durable identity store ID.
	 * @return WP_Agent_Materialized_Identity|null Identity, or null when not found.
	 */
	public function get( int $identity_id ): ?WP_Agent_Materialized_Identity;

	/**
	 * Resolve an existing identity or create the durable identity record.
	 *
	 * Implementations MUST make this operation idempotent for the same normalized
	 * `(agent_slug, owner_user_id, instance_key)` tuple.
	 *
	 * @param WP_Agent_Identity_Scope  $scope          Logical identity scope.
	 * @param array<string,mixed> $default_config Initial config for first materialization only.
	 * @param array<string,mixed> $meta           Optional metadata for first materialization only.
	 * @return WP_Agent_Materialized_Identity
	 */
	public function materialize( WP_Agent_Identity_Scope $scope, array $default_config = array(), array $meta = array() ): WP_Agent_Materialized_Identity;

	/**
	 * Persist replacement config and metadata for an existing identity.
	 *
	 * @param WP_Agent_Materialized_Identity $identity Replacement identity value.
	 * @return WP_Agent_Materialized_Identity Updated persisted value.
	 */
	public function update( WP_Agent_Materialized_Identity $identity ): WP_Agent_Materialized_Identity;

	/**
	 * Delete a materialized identity. Idempotent for non-existent identities.
	 *
	 * @param WP_Agent_Identity_Scope $scope Logical identity scope.
	 * @return bool Whether the operation succeeded.
	 */
	public function delete( WP_Agent_Identity_Scope $scope ): bool;
}
