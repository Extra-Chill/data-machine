<?php
/**
 * Agent bundle directory discovery registry.
 *
 * Resolves on-disk bundle directories shipped by integration plugins.
 * Plugins register their bundle directories through the
 * `datamachine_agent_bundle_directories` filter so that `agent diff`,
 * `agent status`, and `agent upgrade` can resolve a slug to its source
 * bundle without requiring an explicit filesystem path (#2860).
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Discover registered agent bundle directories by bundle slug.
 */
final class AgentBundleDirectoryRegistry {

	/**
	 * Collect registered bundle directories keyed by bundle slug.
	 *
	 * Integration plugins hook `datamachine_agent_bundle_directories` to
	 * declare where their shipped bundle directories live:
	 *
	 *   add_filter( 'datamachine_agent_bundle_directories', function ( $dirs ) {
	 *       $dirs['roadie'] = __DIR__ . '/bundles/roadie';
	 *       return $dirs;
	 *   } );
	 *
	 * Directories that do not exist on disk are silently dropped so a
	 * stale registration never surfaces as a false-positive resolve.
	 *
	 * @return array<string,string> Normalized bundle_slug => absolute directory path.
	 */
	public static function directories(): array {
		$registered = array();

		if ( function_exists( 'apply_filters' ) ) {
			/** @var mixed $filtered */
			$filtered = apply_filters( 'datamachine_agent_bundle_directories', array() );
			if ( is_array( $filtered ) ) {
				$registered = $filtered;
			}
		}

		$validated = array();
		foreach ( $registered as $slug => $path ) {
			$slug = PortableSlug::normalize( (string) $slug, 'bundle' );
			$path = rtrim( (string) $path, '/\\' );

			if ( '' === $slug || '' === $path || ! is_dir( $path ) ) {
				continue;
			}

			$validated[ $slug ] = $path;
		}

		return $validated;
	}

	/**
	 * Resolve a bundle directory path for a bundle slug.
	 *
	 * @param string $bundle_slug Bundle slug (will be normalized).
	 * @return string|null Absolute directory path, or null when unregistered.
	 */
	public static function resolve_for_bundle_slug( string $bundle_slug ): ?string {
		$slug = PortableSlug::normalize( $bundle_slug, 'bundle' );
		if ( '' === $slug ) {
			return null;
		}

		$dirs = self::directories();

		return $dirs[ $slug ] ?? null;
	}

	/**
	 * Resolve a bundle directory path for an installed agent row.
	 *
	 * Reads the agent's stored `datamachine_bundle.bundle_slug` and looks
	 * up the registered directory for that slug.
	 *
	 * @param array<string,mixed> $agent Agent row with agent_config.
	 * @return string|null Absolute directory path, or null when unregistered.
	 */
	public static function resolve_for_agent( array $agent ): ?string {
		$bundle_slug = (string) ( $agent['agent_config']['datamachine_bundle']['bundle_slug'] ?? '' );
		if ( '' === $bundle_slug ) {
			return null;
		}

		return self::resolve_for_bundle_slug( $bundle_slug );
	}
}
