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

use DataMachine\Abilities\File\ScaffoldAbilities;
use DataMachine\Core\Database\Agents\AgentAccess;
use DataMachine\Core\Database\Agents\Agents;
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

		if ( ! class_exists( Agents::class ) ) {
			return $summary;
		}

		$repo = new Agents();

		foreach ( $definitions as $slug => $def ) {
			$existing = $repo->get_by_slug( $slug );
			if ( $existing ) {
				$summary['existing'][] = $slug;
				continue;
			}

			$owner_id = self::resolve_owner( $def );
			if ( $owner_id <= 0 ) {
				$summary['skipped'][] = $slug;
				continue;
			}

			$agent_id = $repo->create_if_missing(
				$slug,
				$def['label'],
				$owner_id,
				$def['default_config']
			);

			if ( $agent_id <= 0 ) {
				$summary['skipped'][] = $slug;
				continue;
			}

			// Bootstrap owner access (mirrors AgentAbilities::createAgent()).
			if ( class_exists( AgentAccess::class ) ) {
				( new AgentAccess() )->bootstrap_owner_access( $agent_id, $owner_id );
			}

			// Ensure agent directory exists.
			if ( class_exists( DirectoryManager::class ) ) {
				$dir_mgr   = new DirectoryManager();
				$agent_dir = $dir_mgr->get_agent_identity_directory( $slug );
				$dir_mgr->ensure_directory_exists( $agent_dir );
			}

			// Scaffold agent-layer memory files (SOUL.md, MEMORY.md, etc.).
			// The scaffold filter consults AgentRegistry for a matching
			// `memory_seeds` entry per filename and substitutes bundled
			// content when present.
			$scaffold = ScaffoldAbilities::get_ability();
			if ( $scaffold ) {
				$scaffold->execute(
					array(
						'layer'      => 'agent',
						'agent_slug' => $slug,
						'agent_id'   => $agent_id,
					)
				);
			}

			/**
			 * Fires after a registered agent is materialized into the database.
			 *
			 * @since 0.71.0
			 *
			 * @param int    $agent_id Newly created agent row ID.
			 * @param string $slug     Registered slug.
			 * @param array  $def      Full registration definition.
			 */
			do_action( 'datamachine_registered_agent_reconciled', $agent_id, $slug, $def );

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
