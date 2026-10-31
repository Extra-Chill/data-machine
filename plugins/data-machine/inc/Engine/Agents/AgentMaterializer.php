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
	 * Per-site option: fingerprint and time of the last clean reconcile.
	 */
	public const RECONCILED_OPTION = 'datamachine_agents_reconciled';

	/**
	 * Network option: bumped on every write that can remove or rename an
	 * agent row. The agents table is network-wide, so a delete on one site
	 * must invalidate every site's reconcile fingerprint.
	 */
	public const GENERATION_OPTION = 'datamachine_agents_generation';

	/**
	 * Re-run reconcile at least this often even when nothing changed, as a
	 * safety net for rows deleted outside Data Machine's write paths.
	 */
	public const RECONCILE_TTL = 3600;

	/**
	 * Whether these definitions were cleanly reconciled within the TTL.
	 *
	 * Steady-state requests read two cached options and issue no queries.
	 *
	 * @param array<string, array> $definitions Registered agent definitions keyed by slug.
	 */
	public static function is_reconciled( array $definitions ): bool {
		$recorded = get_option( self::RECONCILED_OPTION, array() );
		if ( ! is_array( $recorded ) ) {
			return false;
		}

		$age = time() - (int) ( $recorded['at'] ?? 0 );

		return ( $recorded['fingerprint'] ?? '' ) === self::fingerprint( $definitions )
			&& $age >= 0
			&& $age < self::RECONCILE_TTL;
	}

	/**
	 * Record a reconcile result.
	 *
	 * A run that materialized every definition is recorded; a run that
	 * skipped any definition is not, so it retries on the next request.
	 *
	 * @param array<string, array> $definitions Registered agent definitions keyed by slug.
	 * @param array                $summary     Result of reconcile().
	 */
	public static function record_reconciled( array $definitions, array $summary ): void {
		if ( ! empty( $summary['skipped'] ) ) {
			delete_option( self::RECONCILED_OPTION );
			return;
		}

		update_option(
			self::RECONCILED_OPTION,
			array(
				'fingerprint' => self::fingerprint( $definitions ),
				'at'          => time(),
			),
			true
		);
	}

	/**
	 * Invalidate every site's reconcile fingerprint.
	 *
	 * Call after deleting, renaming, or re-owning an agent row.
	 */
	public static function invalidate(): void {
		if ( ! function_exists( 'get_site_option' ) || ! function_exists( 'update_site_option' ) ) {
			return;
		}

		$generation = (int) get_site_option( self::GENERATION_OPTION, 0 );
		update_site_option( self::GENERATION_OPTION, $generation + 1 );
	}

	/**
	 * Stable fingerprint of the definitions plus the network agent generation.
	 *
	 * Callables (owner_resolver) and objects cannot be serialized stably, so
	 * they contribute only their presence; a changed resolver result is
	 * covered by the TTL.
	 *
	 * @param array<string, array> $definitions Registered agent definitions keyed by slug.
	 */
	public static function fingerprint( array $definitions ): string {
		$normalized = self::normalize_for_fingerprint( $definitions );

		return md5(
			serialize(
				array(
					(int) get_site_option( self::GENERATION_OPTION, 0 ),
					$normalized,
				)
			)
		);
	}

	/**
	 * Replace callables and objects with type markers, recursively.
	 *
	 * @param mixed $value Definition value.
	 * @return mixed
	 */
	private static function normalize_for_fingerprint( $value ) {
		if ( $value instanceof \Closure || is_object( $value ) ) {
			return '#' . get_class( $value );
		}

		if ( is_array( $value ) ) {
			if ( is_callable( $value ) && 2 === count( $value ) && isset( $value[0], $value[1] ) && is_object( $value[0] ) ) {
				return '#callable';
			}

			ksort( $value );
			return array_map( array( self::class, 'normalize_for_fingerprint' ), $value );
		}

		return $value;
	}

	/**
	 * Reconcile registered definitions against the `datamachine_agents` table.
	 *
	 * For each registered slug:
	 *   - Row already exists -> leave it alone (mutable fields are DB-owned)
	 *   - Row missing        -> resolve owner, create row, bootstrap access,
	 *                          ensure agent directory, scaffold memory files
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
	 *     definition_only: string[],
	 *     skipped: string[],
	 * }
	 */
	public static function reconcile( array $definitions ): array {
		$summary = array(
			'created'         => array(),
			'existing'        => array(),
			'definition_only' => array(),
			'skipped'         => array(),
		);

		$store = new AgentIdentityStoreAdapter();

		foreach ( $definitions as $slug => $def ) {
			$meta = is_array( $def['meta'] ?? null ) ? $def['meta'] : array();
			if ( false === ( $meta['datamachine_default_materialization'] ?? true ) ) {
				$summary['definition_only'][] = $slug;
				continue;
			}

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
					$meta,
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
