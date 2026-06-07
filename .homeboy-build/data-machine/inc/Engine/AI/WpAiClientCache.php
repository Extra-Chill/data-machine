<?php
/**
 * Persistent wp-ai-client cache integration.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/WpAiClientTransientCache.php';

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

		if ( ! class_exists( WpAiClientTransientCache::class ) ) {
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
			$ai_client_class::setCache( new WpAiClientTransientCache() );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
