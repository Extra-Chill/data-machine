<?php
/**
 * Generic option-backed lease store.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates tokened option-row leases with TTL cleanup.
 */
class OptionLeaseStore {

	/**
	 * Acquire one option-row lease.
	 *
	 * @param string              $option_name               Option name.
	 * @param array<string,mixed> $payload Lease payload. Must include token and expires_at.
	 * @param int                 $ttl                       Stale fallback TTL in seconds.
	 * @param int|null            $now                       Current timestamp.
	 * @param callable|null       $is_stale                  Optional extra stale predicate.
	 * @param bool                $replace_stale_with_update Whether to update when stale delete/add races.
	 * @return array{acquired:bool,status:string,payload:array<string,mixed>,option_name:string}
	 */
	public static function acquire(
		string $option_name,
		array $payload,
		int $ttl,
		?int $now = null,
		?callable $is_stale = null,
		bool $replace_stale_with_update = false
	): array {
		$now      = $now ?? time();
		$existing = self::snapshot( $option_name, $ttl, $now, $is_stale );

		if ( 'held' === $existing['status'] ) {
			return array(
				'acquired'    => false,
				'status'      => 'held',
				'payload'     => $existing['payload'],
				'option_name' => $option_name,
			);
		}

		if ( 'stale' === $existing['status'] ) {
			delete_option( $option_name );
		}

		if ( add_option( $option_name, $payload, '', false ) ) {
			return array(
				'acquired'    => true,
				'status'      => 'held',
				'payload'     => $payload,
				'option_name' => $option_name,
			);
		}

		if ( 'stale' === $existing['status'] && $replace_stale_with_update ) {
			$after_delete = self::snapshot( $option_name, $ttl, $now, $is_stale );
			if ( 'held' !== $after_delete['status'] && update_option( $option_name, $payload, false ) ) {
				$current = get_option( $option_name, array() );
				if ( is_array( $current ) && hash_equals( (string) ( $payload['token'] ?? '' ), (string) ( $current['token'] ?? '' ) ) ) {
					return array(
						'acquired'    => true,
						'status'      => 'held',
						'payload'     => $payload,
						'option_name' => $option_name,
					);
				}
			}
		}

		$current = self::snapshot( $option_name, $ttl, $now, $is_stale );

		return array(
			'acquired'    => false,
			'status'      => $current['status'],
			'payload'     => $current['payload'],
			'option_name' => $option_name,
		);
	}

	/**
	 * Acquire the first available numbered slot in a scope.
	 *
	 * @param string              $prefix  Option-name prefix.
	 * @param string              $scope   Lease scope.
	 * @param int                 $limit   Maximum slots in scope.
	 * @param array<string,mixed> $payload Lease payload.
	 * @param int                 $ttl     Stale fallback TTL in seconds.
	 * @param int|null            $now     Current timestamp.
	 * @param callable|null       $is_stale Optional extra stale predicate.
	 * @return array{acquired:bool,limit:int,active:int,option_name?:string}
	 */
	public static function acquireSlot(
		string $prefix,
		string $scope,
		int $limit,
		array $payload,
		int $ttl,
		?int $now = null,
		?callable $is_stale = null
	): array {
		$active = 0;
		$now    = $now ?? time();

		for ( $slot = 1; $slot <= $limit; ++$slot ) {
			$option_name = self::slotOptionName( $prefix, $scope, $slot );
			$result      = self::acquire( $option_name, $payload, $ttl, $now, $is_stale );

			if ( $result['acquired'] ) {
				return array(
					'acquired'    => true,
					'limit'       => $limit,
					'active'      => $active + 1,
					'option_name' => $option_name,
				);
			}

			if ( 'held' === $result['status'] ) {
				++$active;
			}
		}

		return array(
			'acquired' => false,
			'limit'    => $limit,
			'active'   => $active,
		);
	}

	/**
	 * Return a read-only lease snapshot.
	 *
	 * @param string        $option_name Option name.
	 * @param int           $ttl         Stale fallback TTL in seconds.
	 * @param int|null      $now         Current timestamp.
	 * @param callable|null $is_stale    Optional extra stale predicate.
	 * @return array{status:string,payload:array<string,mixed>,option_name:string}
	 */
	public static function snapshot(
		string $option_name,
		int $ttl,
		?int $now = null,
		?callable $is_stale = null
	): array {
		$now     = $now ?? time();
		$payload = get_option( $option_name, array() );

		if ( ! is_array( $payload ) || empty( $payload ) ) {
			return array(
				'status'      => 'unlocked',
				'payload'     => array(),
				'option_name' => $option_name,
			);
		}

		$status = self::isStale( $payload, $ttl, $now, $is_stale ) ? 'stale' : 'held';

		return array(
			'status'      => $status,
			'payload'     => $payload,
			'option_name' => $option_name,
		);
	}

	/**
	 * Delete a lease only when the token still owns it.
	 */
	public static function release( string $option_name, string $token ): void {
		if ( '' === $token ) {
			return;
		}

		$payload = get_option( $option_name, array() );
		if ( is_array( $payload ) && hash_equals( (string) ( $payload['token'] ?? '' ), $token ) ) {
			delete_option( $option_name );
		}
	}

	/**
	 * Atomically extend a lease only while the caller still owns its token.
	 *
	 * @param string   $option_name Option name.
	 * @param string   $token       Current owner token.
	 * @param int      $ttl         Extension in seconds.
	 * @param int|null $now         Current timestamp.
	 */
	public static function refresh( string $option_name, string $token, int $ttl, ?int $now = null ): bool {
		if ( '' === $token ) {
			return false;
		}

		$current = get_option( $option_name, array() );
		if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
			return false;
		}

		$replacement               = $current;
		$replacement['expires_at'] = max(
			(int) ( $current['expires_at'] ?? 0 ) + 1,
			( $now ?? time() ) + max( 1, $ttl )
		);

		global $wpdb;
		if ( isset( $wpdb->options ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Conditional update is the fencing primitive.
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
					$wpdb->options,
					maybe_serialize( $replacement ),
					$option_name,
					maybe_serialize( $current )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			if ( 1 !== $updated ) {
				return false;
			}

			wp_cache_delete( $option_name, 'options' );
			return true;
		}

		// Lightweight test runtimes may not provide wpdb; retain token verification.
		if ( ! update_option( $option_name, $replacement, false ) ) {
			return false;
		}
		$stored = get_option( $option_name, array() );
		return is_array( $stored ) && hash_equals( $token, (string) ( $stored['token'] ?? '' ) );
	}

	/**
	 * Delete a stale lease when present.
	 */
	public static function cleanupStale(
		string $option_name,
		int $ttl,
		?int $now = null,
		?callable $is_stale = null
	): bool {
		$snapshot = self::snapshot( $option_name, $ttl, $now, $is_stale );
		if ( 'stale' !== $snapshot['status'] ) {
			return false;
		}

		return delete_option( $option_name );
	}

	/**
	 * Build the option name for a numbered scope slot.
	 */
	public static function slotOptionName( string $prefix, string $scope, int $slot ): string {
		return $prefix . md5( $scope ) . '_' . $slot;
	}

	/**
	 * Determine whether a stored lease is stale.
	 *
	 * @param array<string,mixed> $payload Stored lease payload.
	 */
	private static function isStale( array $payload, int $ttl, int $now, ?callable $is_stale ): bool {
		$started_at = (int) ( $payload['started_at'] ?? $payload['created_at'] ?? 0 );
		$expires_at = (int) ( $payload['expires_at'] ?? ( $started_at + $ttl ) );

		if ( $expires_at <= $now ) {
			return true;
		}

		return null !== $is_stale && (bool) $is_stale( $payload, $now );
	}
}
