<?php
/**
 * Agent Materializer
 *
 * Data Machine-specific reconciliation for declarative agent definitions.
 *
 * @package DataMachine\Engine\Agents
 * @since   0.98.0
 */

namespace DataMachine\Engine\Agents;

use AgentsAPI\Core\Identity\WP_Agent_Identity_Scope;
use DataMachine\Core\Identity\AgentIdentityStoreAdapter;
use DataMachine\Core\FilesRepository\DirectoryManager;

defined( 'ABSPATH' ) || exit;

class AgentMaterializer {

	/**
	 * Reconcile registered definitions against the `datamachine_agents` table.
	 *
	 * For each registered slug:
	 *   - Row already exists -> leave it alone (mutable fields are DB-owned)
	 *   - Row missing        -> resolve owner, create row, bootstrap access,
	 *                          ensure agent directory, trigger scaffold ability
	 *
	 * Idempotent: safe to call multiple times per request. Returns a
	 * summary of what happened for logging / testing.
	 *
	 * @since 0.98.0
	 *
	 * @param array<string, array> $definitions Registered agent definitions keyed by slug.
	 * @return array{
	 *     created: string[],
	 *     existing: string[],
	 *     skipped: string[],
	 * }
	 */
	public static function reconcile( array $definitions ): array {
		$summary = array(
			'created'  => array(),
			'existing' => array(),
			'skipped'  => array(),
		);

		$store = new AgentIdentityStoreAdapter();

		foreach ( $definitions as $slug => $def ) {
			$owner_id = self::resolve_owner( $def );
			if ( $owner_id <= 0 ) {
				$summary['skipped'][] = $slug;
				continue;
			}

			$scope = new WP_Agent_Identity_Scope( $slug, $owner_id );
			if ( null !== $store->resolve( $scope ) ) {
				$summary['existing'][] = $slug;
				continue;
			}

			$agent = \wp_get_agent( $slug );
			if ( ! $agent instanceof \WP_Agent ) {
				$summary['skipped'][] = $slug;
				continue;
			}

			$identity = $store->materialize(
				$scope,
				$agent->get_default_config(),
				array_merge(
					is_array( $def['meta'] ?? null ) ? $def['meta'] : array(),
					array(
						'label'                  => (string) ( $def['label'] ?? $slug ),
						'datamachine_definition' => $def,
					)
				)
			);

			if ( ! $identity instanceof \AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity ) {
				$summary['skipped'][] = $slug;
				continue;
			}

			$summary['created'][] = $slug;
		}

		return $summary;
	}

	/**
	 * Resolve the owner user ID for a registered agent.
	 *
	 * Calls the registration's `owner_resolver` when provided and falls
	 * back to `DirectoryManager::get_default_agent_user_id()`.
	 *
	 * @param array $def Registration definition.
	 * @return int User ID, or 0 when resolution fails.
	 */
	private static function resolve_owner( array $def ): int {
		if ( isset( $def['owner_resolver'] ) && is_callable( $def['owner_resolver'] ) ) {
			$resolved = (int) call_user_func( $def['owner_resolver'] );
			if ( $resolved > 0 ) {
				return $resolved;
			}
		}

		if ( class_exists( DirectoryManager::class ) ) {
			return (int) DirectoryManager::get_default_agent_user_id();
		}

		return 0;
	}
}
