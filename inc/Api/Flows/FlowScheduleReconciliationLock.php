<?php
/**
 * Atomic per-site flow schedule reconciliation lock.
 *
 * Compare-and-set option lock: no add_option()/delete_option() cache-vs-DB
 * split. Every mutation is a conditional `$wpdb->query()` against the exact
 * expected serialized value, paired with an explicit `wp_cache_delete()` so
 * a stale object-cache entry can never survive a successful write and block
 * the lock forever (see Extra-Chill/data-machine#3492 for the failure mode
 * this is designed to avoid: an object-cache-only lock entry with no DB row
 * makes add_option() always fail and delete_option() never clear the cache).
 *
 * Guards {@see \DataMachine\Engine\Scheduling\FlowRoutines::reconcile()}
 * before it calls `boot()`, so lock contention costs one option read instead
 * of a full ~700-routine registration pass.
 *
 * @package DataMachine\Api\Flows
 */

namespace DataMachine\Api\Flows;

defined( 'ABSPATH' ) || exit;

class FlowScheduleReconciliationLock {

	private const OPTION_NAME = 'datamachine_flow_schedule_reconciliation_lock';
	private const STALE_AFTER = 1800;

	/**
	 * Acquire the reconcile lock, atomically replacing a stale owner when needed.
	 *
	 * @return string|\WP_Error Lock token or an error when another reconcile is active.
	 */
	public static function acquire() {
		$token   = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'dm-flow-reconcile-', true );
		$payload = array(
			'token'       => $token,
			'acquired_at' => time(),
		);

		if ( add_option( self::OPTION_NAME, $payload, '', false ) ) {
			return $token;
		}

		$current = get_option( self::OPTION_NAME, array() );
		if ( is_array( $current ) && (int) ( $current['acquired_at'] ?? 0 ) > time() - self::STALE_AFTER ) {
			return new \WP_Error( 'flow_schedule_reconciliation_locked', 'Another flow schedule reconciliation is already running.' );
		}

		if ( self::compare_and_swap( $current, $payload ) ) {
			return $token;
		}

		return new \WP_Error( 'flow_schedule_reconciliation_locked', 'Another flow schedule reconciliation acquired the stale lock first.' );
	}

	/**
	 * Release only the lock owned by the supplied token.
	 *
	 * @param string $token Lock token returned by acquire().
	 * @return bool True when this owner released the lock.
	 */
	public static function release( string $token ): bool {
		$current = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
			return false;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Conditional delete provides token-safe lock release.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				self::OPTION_NAME,
				maybe_serialize( $current )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( 1 === $deleted ) {
			wp_cache_delete( self::OPTION_NAME, 'options' );
			return true;
		}

		return false;
	}

	/**
	 * Atomically refresh a lock lease owned by the supplied token.
	 *
	 * @param string $token Active lock token.
	 * @return bool True when the exact owned lease was refreshed.
	 */
	public static function refresh( string $token ): bool {
		$current = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
			return false;
		}

		$replacement                = $current;
		$replacement['acquired_at'] = max( time(), (int) ( $current['acquired_at'] ?? 0 ) + 1 );
		return self::compare_and_swap( $current, $replacement );
	}

	/**
	 * Atomically replace the exact expected payload.
	 *
	 * @param mixed                $expected    Current expected lock payload.
	 * @param array<string, mixed> $replacement New lock payload.
	 * @return bool True when the expected owner was replaced.
	 */
	private static function compare_and_swap( $expected, array $replacement ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Conditional update provides atomic stale-lock takeover.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				maybe_serialize( $replacement ),
				self::OPTION_NAME,
				maybe_serialize( $expected )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( 1 === $updated ) {
			wp_cache_delete( self::OPTION_NAME, 'options' );
			return true;
		}

		return false;
	}
}
