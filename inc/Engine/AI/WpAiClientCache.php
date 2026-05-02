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

/**
 * Shared implementation for transient-backed wp-ai-client caches.
 */
abstract class WpAiClientTransientCacheBase {

	/**
	 * Cache version option used to logically clear Data Machine's namespace.
	 */
	private const VERSION_OPTION = 'datamachine_wp_ai_client_cache_version';

	/**
	 * Prefix for transient keys.
	 */
	private const TRANSIENT_PREFIX = 'datamachine_wp_ai_client_';

	/**
	 * Fetches a value from the cache.
	 *
	 * @param string $key     Cache key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$this->validateKey( $key );

		$cached = get_transient( $this->transientKey( $key ) );
		if ( ! is_array( $cached ) || ! array_key_exists( 'value', $cached ) ) {
			return $default;
		}

		return $cached['value'];
	}

	/**
	 * Stores a value in the cache.
	 *
	 * @param string                 $key   Cache key.
	 * @param mixed                  $value Cache value.
	 * @param null|int|\DateInterval $ttl   TTL.
	 * @return bool
	 */
	public function set( $key, $value, $ttl = null ) {
		$this->validateKey( $key );

		$expiration = $this->ttlToSeconds( $ttl );
		if ( $expiration < 0 ) {
			return $this->delete( $key );
		}

		return set_transient( $this->transientKey( $key ), array( 'value' => $value ), $expiration );
	}

	/**
	 * Deletes a value from the cache.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function delete( $key ) {
		$this->validateKey( $key );

		return delete_transient( $this->transientKey( $key ) );
	}

	/**
	 * Clears this adapter's logical namespace.
	 *
	 * @return bool
	 */
	public function clear() {
		$version = (int) get_option( self::VERSION_OPTION, 1 );

		return update_option( self::VERSION_OPTION, max( 1, $version ) + 1, false );
	}

	/**
	 * Fetches multiple values from the cache.
	 *
	 * @param iterable $keys    Cache keys.
	 * @param mixed    $default Default value.
	 * @return iterable
	 */
	public function getMultiple( $keys, $default = null ) {
		$values = array();
		foreach ( $this->iterableKeys( $keys ) as $key ) {
			$values[ $key ] = $this->get( $key, $default );
		}

		return $values;
	}

	/**
	 * Stores multiple values in the cache.
	 *
	 * @param iterable               $values Key/value map.
	 * @param null|int|\DateInterval $ttl    TTL.
	 * @return bool
	 */
	public function setMultiple( $values, $ttl = null ) {
		$ok = true;
		foreach ( $this->iterableValues( $values ) as $key => $value ) {
			$ok = $this->set( $key, $value, $ttl ) && $ok;
		}

		return $ok;
	}

	/**
	 * Deletes multiple values from the cache.
	 *
	 * @param iterable $keys Cache keys.
	 * @return bool
	 */
	public function deleteMultiple( $keys ) {
		$ok = true;
		foreach ( $this->iterableKeys( $keys ) as $key ) {
			$ok = $this->delete( $key ) && $ok;
		}

		return $ok;
	}

	/**
	 * Determines whether a key exists in the cache.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function has( $key ) {
		$missing = new \stdClass();

		return $missing !== $this->get( $key, $missing );
	}

	/**
	 * Builds a WordPress-safe transient key from the wp-ai-client key.
	 *
	 * @param string $key Original cache key.
	 * @return string
	 */
	private function transientKey( string $key ): string {
		$version = (int) get_option( self::VERSION_OPTION, 1 );

		return self::TRANSIENT_PREFIX . max( 1, $version ) . '_' . md5( $key );
	}

	/**
	 * Converts a PSR-16 TTL to WordPress transient seconds.
	 *
	 * @param null|int|\DateInterval $ttl TTL.
	 * @return int
	 */
	private function ttlToSeconds( $ttl ): int {
		if ( null === $ttl ) {
			return 0;
		}

		if ( $ttl instanceof \DateInterval ) {
			$now    = new \DateTimeImmutable();
			$future = $now->add( $ttl );

			return $future->getTimestamp() - $now->getTimestamp();
		}

		if ( is_numeric( $ttl ) ) {
			return (int) $ttl;
		}

		throw new \InvalidArgumentException( 'Cache TTL must be null, an integer, or a DateInterval.' );
	}

	/**
	 * Validate a PSR-16 key.
	 *
	 * @param mixed $key Cache key.
	 * @return void
	 */
	private function validateKey( $key ): void {
		if ( ! is_string( $key ) || '' === $key ) {
			throw new \InvalidArgumentException( 'Cache key must be a non-empty string.' );
		}
	}

	/**
	 * Normalize iterable keys.
	 *
	 * @param mixed $keys Keys.
	 * @return iterable
	 */
	private function iterableKeys( $keys ): iterable {
		if ( ! is_iterable( $keys ) ) {
			throw new \InvalidArgumentException( 'Cache keys must be iterable.' );
		}

		foreach ( $keys as $key ) {
			$this->validateKey( $key );
			yield $key;
		}
	}

	/**
	 * Normalize iterable key/value pairs.
	 *
	 * @param mixed $values Values.
	 * @return iterable
	 */
	private function iterableValues( $values ): iterable {
		if ( ! is_iterable( $values ) ) {
			throw new \InvalidArgumentException( 'Cache values must be iterable.' );
		}

		foreach ( $values as $key => $value ) {
			$this->validateKey( $key );
			yield $key => $value;
		}
	}
}

if ( interface_exists( '\WordPress\AiClientDependencies\Psr\SimpleCache\CacheInterface' ) ) {
	/**
	 * Transient-backed cache for WordPress' scoped wp-ai-client dependency namespace.
	 */
	class WpAiClientTransientCache extends WpAiClientTransientCacheBase implements \WordPress\AiClientDependencies\Psr\SimpleCache\CacheInterface {}
} elseif ( interface_exists( '\Psr\SimpleCache\CacheInterface' ) ) {
	/**
	 * Transient-backed cache for unscoped php-ai-client installs.
	 */
	class WpAiClientTransientCache extends WpAiClientTransientCacheBase implements \Psr\SimpleCache\CacheInterface {}
}
