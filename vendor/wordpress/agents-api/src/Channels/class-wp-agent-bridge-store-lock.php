<?php
/**
 * Cross-process serialization lock for option-backed bridge store mutations.
 *
 * @package AgentsAPI
 * @since   0.104.0
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * MySQL advisory lock scoped to one bridge-store option.
 *
 * The lock is connection-owned, so MySQL releases it if a request terminates.
 * This avoids both an unrecoverable lock row and unsafe TTL-based lease takeover.
 */
final class WP_Agent_Bridge_Store_Lock {

	private const LOCK_PREFIX       = 'wp_agent_bridge_';
	private const LOCK_WAIT_SECONDS = 5;

	/**
	 * Run one callback while holding an exclusive lock for the option.
	 *
	 * A consumer may return a callable from `wp_agent_bridge_store_lock` to
	 * replace the default runner with an infrastructure-specific primitive.
	 *
	 * @template T
	 * @param string       $option_name Shared option row being guarded.
	 * @param callable():T $critical    Critical section to run under the lock.
	 * @return T Callback result.
	 */
	public static function with_lock( string $option_name, callable $critical ) {
		$override = self::filtered_runner( $option_name, $critical );
		if ( null !== $override ) {
			return $override();
		}

		$db = self::database();
		if ( null === $db ) {
			// Pure-PHP harnesses are single-process and have no WordPress database.
			return $critical();
		}

		$lock_name = self::LOCK_PREFIX . md5( $option_name );
		$acquired  = $db->get_var( $db->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, self::LOCK_WAIT_SECONDS ) );
		if ( '1' !== (string) $acquired ) {
			throw new \RuntimeException( 'Bridge store lock acquisition timed out.' );
		}

		try {
			self::clear_option_cache( $option_name );
			return $critical();
		} finally {
			$db->get_var( $db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * @param string   $option_name Shared option row being guarded.
	 * @param callable $critical    Critical section supplied to an override.
	 */
	private static function filtered_runner( string $option_name, callable $critical ): ?callable {
		if ( ! function_exists( 'apply_filters' ) ) {
			return null;
		}
		$runner = apply_filters( 'wp_agent_bridge_store_lock', null, $option_name, $critical );
		return is_callable( $runner ) ? $runner : null;
	}

	private static function database(): ?\wpdb {
		global $wpdb;
		return $wpdb instanceof \wpdb ? $wpdb : null;
	}

	private static function clear_option_cache( string $option_name ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}
