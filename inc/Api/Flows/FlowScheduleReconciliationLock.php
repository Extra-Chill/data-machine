<?php
/**
 * Atomic per-site flow schedule reconciliation lock.
 *
 * @package DataMachine\Api\Flows
 */

namespace DataMachine\Api\Flows;

defined( 'ABSPATH' ) || exit;

class FlowScheduleReconciliationLock {

	private const OPTION_NAME = 'datamachine_flow_schedule_reconciliation_lock';
	private const STALE_AFTER = 1800;

	/**
	 * Acquire the apply lock, atomically replacing a stale owner when needed.
	 *
	 * @return string|\WP_Error Lock token or an error when another apply is active.
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
			return new \WP_Error( 'flow_schedule_reconciliation_locked', 'Another flow schedule reconciliation apply is already running.' );
		}

		if ( self::compareAndSwap( $current, $payload ) ) {
			return $token;
		}

		return new \WP_Error( 'flow_schedule_reconciliation_locked', 'Another flow schedule reconciliation apply acquired the stale lock first.' );
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
		return self::compareAndSwap( $current, $replacement );
	}

	/**
	 * Atomically replace the exact stale payload.
	 *
	 * @param mixed $expected Current stale lock payload.
	 * @param array $replacement New lock payload.
	 * @return bool True when the stale owner was replaced.
	 */
	private static function compareAndSwap( $expected, array $replacement ): bool {
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
