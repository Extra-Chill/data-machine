<?php
/**
 * Public wp-ai-client transient cache compatibility alias.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/WpAiClientTransientCacheBase.php';

if ( interface_exists( '\WordPress\AiClientDependencies\Psr\SimpleCache\CacheInterface' ) ) {
	require_once __DIR__ . '/ScopedWpAiClientTransientCache.php';
	class_alias( ScopedWpAiClientTransientCache::class, __NAMESPACE__ . '\\WpAiClientTransientCache' );
} elseif ( interface_exists( '\Psr\SimpleCache\CacheInterface' ) ) {
	require_once __DIR__ . '/PsrWpAiClientTransientCache.php';
	class_alias( PsrWpAiClientTransientCache::class, __NAMESPACE__ . '\\WpAiClientTransientCache' );
}
