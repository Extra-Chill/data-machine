<?php
/**
 * Scoped wp-ai-client transient cache implementation.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Transient-backed cache for WordPress' scoped wp-ai-client dependency namespace.
 */
class ScopedWpAiClientTransientCache extends WpAiClientTransientCacheBase implements \WordPress\AiClientDependencies\Psr\SimpleCache\CacheInterface {}
