<?php
/**
 * Persistent wp-ai-client cache integration.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/WpAiClientTransientCacheBase.php';

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

	/** Return the cache implementation matching wp-ai-client's PSR namespace. */
	private static function cache_class(): ?string {
		if ( interface_exists( '\WordPress\AiClientDependencies\Psr\SimpleCache\CacheInterface' ) ) {
			require_once __DIR__ . '/ScopedWpAiClientTransientCache.php';
			return ScopedWpAiClientTransientCache::class;
		}

		if ( interface_exists( '\Psr\SimpleCache\CacheInterface' ) ) {
			require_once __DIR__ . '/PsrWpAiClientTransientCache.php';
			return PsrWpAiClientTransientCache::class;
		}

		return null;
	}
}
