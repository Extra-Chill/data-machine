<?php
/**
 * Persistent wp-ai-client cache integration.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Installs a WordPress-backed PSR-16 cache for wp-ai-client.
 */
class WpAiClientCache {

	/**
	 * Install the cache into wp-ai-client when available.
	 *
	 * @return void
	 */
	public static function install(): void {
		$ai_client_class = '\WordPress\AiClient\AiClient';
		if ( ! class_exists( $ai_client_class ) || ! method_exists( $ai_client_class, 'setCache' ) ) {
			return;
		}

		$cache_class = self::cache_class();
		if ( null === $cache_class ) {
			return;
		}

		if ( method_exists( $ai_client_class, 'getCache' ) ) {
			try {
				if ( null !== $ai_client_class::getCache() ) {
					return;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		try {
			$ai_client_class::setCache( new $cache_class() );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Return the cache implementation matching wp-ai-client's PSR namespace.
	 *
	 * Cache classes are resolved through the Composer autoloader (PSR-4
	 * `DataMachine\Engine\` => `inc/Engine/`). They are intentionally never
	 * hand-included: `require_once` dedupes by real path, so during a
	 * symlink-swap deploy the same class file can be reachable through two
	 * real paths in one request and a manual include fatals with
	 * "Cannot redeclare class".
	 */
	private static function cache_class(): ?string {
		if ( interface_exists( '\WordPress\AiClientDependencies\Psr\SimpleCache\CacheInterface' )
			&& class_exists( ScopedWpAiClientTransientCache::class )
		) {
			return ScopedWpAiClientTransientCache::class;
		}

		if ( interface_exists( '\Psr\SimpleCache\CacheInterface' )
			&& class_exists( PsrWpAiClientTransientCache::class )
		) {
			return PsrWpAiClientTransientCache::class;
		}

		return null;
	}
}
