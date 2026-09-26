<?php
/**
 * Unscoped PSR wp-ai-client transient cache implementation.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Transient-backed cache for unscoped php-ai-client installs.
 */
class PsrWpAiClientTransientCache extends WpAiClientTransientCacheBase implements \Psr\SimpleCache\CacheInterface {}
