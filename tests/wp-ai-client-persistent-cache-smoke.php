<?php
/**
 * Smoke test for Data Machine's persistent wp-ai-client cache adapter.
 *
 * Run with: php tests/wp-ai-client-persistent-cache-smoke.php
 */

namespace Psr\SimpleCache {
	interface CacheInterface {
		public function get( $key, $default = null );
		public function set( $key, $value, $ttl = null );
		public function delete( $key );
		public function clear();
		public function getMultiple( $keys, $default = null );
		public function setMultiple( $values, $ttl = null );
		public function deleteMultiple( $keys );
		public function has( $key );
	}
}

namespace WordPress\AiClient {
	class AiClient {
		private static ?\Psr\SimpleCache\CacheInterface $cache = null;

		public static function setCache( ?\Psr\SimpleCache\CacheInterface $cache ): void {
			self::$cache = $cache;
		}

		public static function getCache(): ?\Psr\SimpleCache\CacheInterface {
			return self::$cache;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );

	$GLOBALS['datamachine_cache_smoke_transients'] = array();
	$GLOBALS['datamachine_cache_smoke_options']    = array();
	$GLOBALS['datamachine_cache_smoke_now']        = 1_700_000_000;

	function get_transient( string $key ) {
		$entry = $GLOBALS['datamachine_cache_smoke_transients'][ $key ] ?? null;
		if ( ! is_array( $entry ) ) {
			return false;
		}

		if ( 0 !== $entry['expires'] && $entry['expires'] <= $GLOBALS['datamachine_cache_smoke_now'] ) {
			unset( $GLOBALS['datamachine_cache_smoke_transients'][ $key ] );
			return false;
		}

		return $entry['value'];
	}

	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		$GLOBALS['datamachine_cache_smoke_transients'][ $key ] = array(
			'value'   => $value,
			'expires' => $expiration > 0 ? $GLOBALS['datamachine_cache_smoke_now'] + $expiration : 0,
		);

		return true;
	}

	function delete_transient( string $key ): bool {
		unset( $GLOBALS['datamachine_cache_smoke_transients'][ $key ] );

		return true;
	}

	function get_option( string $name, $default = false ) {
		return $GLOBALS['datamachine_cache_smoke_options'][ $name ] ?? $default;
	}

	function update_option( string $name, $value, bool $autoload = true ): bool {
		unset( $autoload );
		$GLOBALS['datamachine_cache_smoke_options'][ $name ] = $value;

		return true;
	}

	require_once __DIR__ . '/../inc/Engine/AI/WpAiClientCache.php';

	use DataMachine\Engine\AI\WpAiClientCache;

	$assertions = 0;
	$failures   = array();
	$assert     = function ( bool $condition, string $message ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = $message;
		}
	};

	WpAiClientCache::install();
	// @phpstan-ignore-next-line Smoke stub exposes getCache().
	$cache = \WordPress\AiClient\AiClient::getCache();

	$assert( $cache instanceof \Psr\SimpleCache\CacheInterface, 'Data Machine installs a PSR-16 cache into wp-ai-client' );

	$cache->set( 'ai_client_1.3.1_e723aa456086a7a24a491e8e87739a4b_models', array( 'models' => array( 'gpt-5' ) ), 60 );
	$assert( array( 'models' => array( 'gpt-5' ) ) === $cache->get( 'ai_client_1.3.1_e723aa456086a7a24a491e8e87739a4b_models' ), 'cache returns stored metadata payload' );

	$GLOBALS['datamachine_cache_smoke_now'] += 61;
	$assert( 'miss' === $cache->get( 'ai_client_1.3.1_e723aa456086a7a24a491e8e87739a4b_models', 'miss' ), 'cache respects TTL expiry' );

	$cache->set( 'false-value', false, 60 );
	$assert( $cache->has( 'false-value' ), 'cache can distinguish false values from misses' );

	$cache->set( 'clear-test', 'before-clear', 60 );
	$cache->clear();
	$assert( 'miss' === $cache->get( 'clear-test', 'miss' ), 'clear logically invalidates Data Machine namespace' );

	$existing = new class() implements \Psr\SimpleCache\CacheInterface {
		public function get( $key, $default = null ) { return $default; }
		public function set( $key, $value, $ttl = null ) { return true; }
		public function delete( $key ) { return true; }
		public function clear() { return true; }
		public function getMultiple( $keys, $default = null ) { return array(); }
		public function setMultiple( $values, $ttl = null ) { return true; }
		public function deleteMultiple( $keys ) { return true; }
		public function has( $key ) { return false; }
	};

	// @phpstan-ignore-next-line Smoke stub exposes setCache().
	\WordPress\AiClient\AiClient::setCache( $existing );
	WpAiClientCache::install();
	// @phpstan-ignore-next-line Smoke stub exposes getCache().
	$assert( $existing === \WordPress\AiClient\AiClient::getCache(), 'installer preserves an existing wp-ai-client cache' );

	if ( $failures ) {
		foreach ( $failures as $failure ) {
			fwrite( STDERR, "FAIL: {$failure}\n" );
		}
		exit( 1 );
	}

	echo "wp-ai-client persistent cache smoke passed ({$assertions} assertions)\n";
}
