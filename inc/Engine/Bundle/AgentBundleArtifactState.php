<?php
/**
 * Installed bundle artifact state storage adapter.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

use DataMachine\Core\Database\BundleArtifacts\InstalledBundleArtifacts;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes installed bundle artifact reads/writes.
 */
final class AgentBundleArtifactState {

	/**
	 * Return installed artifact rows for an agent.
	 *
	 * The dedicated table is canonical. Legacy agent_config rows are used only as
	 * a compatibility fallback and are backfilled into the table when possible.
	 *
	 * @param array<string,mixed> $agent Agent row.
	 * @return array<int,array<string,mixed>>
	 */
	public static function installed_for_agent( array $agent ): array {
		$agent_id = (int) ( $agent['agent_id'] ?? 0 );
		if ( $agent_id <= 0 ) {
			return array_values( self::legacy_rows( $agent ) );
		}

		$store       = new InstalledBundleArtifacts();
		$bundle_slug = (string) ( $agent['agent_config']['datamachine_bundle']['bundle_slug'] ?? '' );
		$installed   = '' !== $bundle_slug ? $store->list_for_bundle( $bundle_slug, $agent_id ) : $store->list_for_agent( $agent_id );
		if ( ! empty( $installed ) ) {
			return array_map( static fn( AgentBundleInstalledArtifact $artifact ): array => $artifact->to_array(), $installed );
		}

		$legacy = array_values( self::legacy_rows( $agent ) );
		if ( ! empty( $legacy ) ) {
			self::persist_for_agent( $agent_id, $legacy );
		}

		return $legacy;
	}

	/**
	 * Persist installed artifact rows for an agent.
	 *
	 * @param int                                             $agent_id Agent ID.
	 * @param array<int,array<string,mixed>|AgentBundleInstalledArtifact> $artifacts Artifact rows.
	 * @return bool
	 */
	public static function persist_for_agent( int $agent_id, array $artifacts ): bool {
		if ( $agent_id <= 0 ) {
			return false;
		}

		$store = new InstalledBundleArtifacts();
		$ok    = true;
		foreach ( $artifacts as $artifact ) {
			try {
				$installed = $artifact instanceof AgentBundleInstalledArtifact ? $artifact : AgentBundleInstalledArtifact::from_array( (array) $artifact );
				$ok        = $store->upsert( $installed, $agent_id ) && $ok;
			} catch ( \Throwable $error ) {
				$ok = false;
				do_action(
					'datamachine_log',
					'warning',
					'Failed to persist installed bundle artifact state.',
					array(
						'agent_id' => $agent_id,
						'error'    => $error->getMessage(),
					)
				);
			}
		}

		return $ok;
	}

	/**
	 * @param array<string,mixed> $agent Agent row.
	 * @return array<string,array<string,mixed>>
	 */
	private static function legacy_rows( array $agent ): array {
		$rows = $agent['agent_config']['datamachine_bundle']['artifacts'] ?? array();
		return is_array( $rows ) ? array_filter( $rows, 'is_array' ) : array();
	}
}
