<?php
/**
 * Shared WP-CLI worker/drain runtime lock.
 *
 * @package DataMachine\Cli
 */

namespace DataMachine\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Prevent overlapping external worker/drain invocations.
 */
class WorkerLock {

	private const OPTION_NAME = 'datamachine_worker_runtime_lock';

	private const DEFAULT_TTL = 600;

	/**
	 * Acquire the runtime lock.
	 *
	 * @param string $owner Operator-facing lock owner.
	 * @param int    $ttl   Stale-lock timeout in seconds.
	 * @param string $lane  Optional lock lane. Empty string keeps the legacy global lock.
	 * @return array<string,int|string|bool> Lock state.
	 */
	public static function acquire( string $owner, int $ttl = self::DEFAULT_TTL, string $lane = '' ): array {
		$ttl         = max( 60, $ttl );
		$now         = time();
		$option_name = self::optionName( $lane );

		$existing = self::snapshot( $now, $ttl, $lane );
		if ( 'held' === $existing['lock_status'] ) {
			$existing['acquired'] = false;
			return $existing;
		}

		$token   = wp_generate_uuid4();
		$payload = array(
			'token'      => $token,
			'owner'      => sanitize_text_field( $owner ),
			'started_at' => $now,
			'expires_at' => $now + $ttl,
			'ttl'        => $ttl,
			'lane'       => self::normalizeLane( $lane ),
		);

		if ( 'stale' === $existing['lock_status'] ) {
			delete_option( $option_name );
			if ( add_option( $option_name, $payload, '', false ) ) {
				return self::formatSnapshot( $payload, $now, 'held', true );
			}

			$after_delete = self::snapshot( $now, $ttl, $lane );
			if ( 'held' !== $after_delete['lock_status'] && update_option( $option_name, $payload, false ) ) {
				$current = get_option( $option_name, array() );
				if ( is_array( $current ) && hash_equals( $token, (string) ( $current['token'] ?? '' ) ) ) {
					return self::formatSnapshot( $payload, $now, 'held', true );
				}
			}

			$existing             = self::snapshot( $now, $ttl, $lane );
			$existing['acquired'] = false;
			return $existing;
		}

		if ( add_option( $option_name, $payload, '', false ) ) {
			return self::formatSnapshot( $payload, $now, 'held', true );
		}

		$existing             = self::snapshot( $now, $ttl, $lane );
		$existing['acquired'] = false;
		return $existing;
	}

	/**
	 * Release the lock only when the caller still owns it.
	 *
	 * @param string $token Lock token returned by acquire().
	 * @param string $lane  Optional lock lane. Empty string keeps the legacy global lock.
	 */
	public static function release( string $token, string $lane = '' ): void {
		if ( '' === $token ) {
			return;
		}

		$option_name = self::optionName( $lane );
		$payload     = get_option( $option_name, array() );
		if ( is_array( $payload ) && hash_equals( (string) ( $payload['token'] ?? '' ), $token ) ) {
			delete_option( $option_name );
		}
	}

	/**
	 * Return a read-only lock snapshot for operator status surfaces.
	 *
	 * @param int|null $now  Current timestamp for tests/callers that need stability.
	 * @param int      $ttl  Stale-lock timeout in seconds.
	 * @param string   $lane Optional lock lane. Empty string keeps the legacy global lock.
	 * @return array<string,int|string|bool> Lock state.
	 */
	public static function snapshot( ?int $now = null, int $ttl = self::DEFAULT_TTL, string $lane = '' ): array {
		$now     = $now ?? time();
		$ttl     = max( 60, $ttl );
		$payload = get_option( self::optionName( $lane ), array() );
		$lane    = self::normalizeLane( $lane );

		if ( ! is_array( $payload ) || empty( $payload['started_at'] ) ) {
			return array(
				'lock_status'      => 'unlocked',
				'lock_owner'       => '',
				'lock_age_seconds' => 0,
				'lock_expires_at'  => 0,
				'lock_token'       => '',
				'lock_lane'        => $lane,
				'acquired'         => false,
			);
		}

		$started_at = (int) $payload['started_at'];
		$expires_at = (int) ( $payload['expires_at'] ?? ( $started_at + $ttl ) );
		$status     = $expires_at <= $now ? 'stale' : 'held';

		return self::formatSnapshot( $payload, $now, $status, false );
	}

	/**
	 * Build the option name for a lock lane.
	 */
	private static function optionName( string $lane ): string {
		$lane = self::normalizeLane( $lane );
		if ( '' === $lane ) {
			return self::OPTION_NAME;
		}

		return self::OPTION_NAME . '_' . $lane;
	}

	/**
	 * Normalize lane identifiers for option names and output.
	 */
	private static function normalizeLane( string $lane ): string {
		$lane = strtolower( trim( $lane ) );
		$lane = (string) preg_replace( '/[^a-z0-9_\-]/', '', $lane );
		return $lane;
	}

	/**
	 * Normalize lock payload for CLI output.
	 *
	 * @param array<string,mixed> $payload  Stored payload.
	 * @param int                 $now      Current timestamp.
	 * @param string              $status   Lock status.
	 * @param bool                $acquired Whether this process acquired it.
	 * @return array<string,int|string|bool> Lock state.
	 */
	private static function formatSnapshot( array $payload, int $now, string $status, bool $acquired ): array {
		$started_at = (int) ( $payload['started_at'] ?? 0 );

		return array(
			'lock_status'      => $status,
			'lock_owner'       => (string) ( $payload['owner'] ?? '' ),
			'lock_age_seconds' => max( 0, $now - $started_at ),
			'lock_expires_at'  => (int) ( $payload['expires_at'] ?? 0 ),
			'lock_token'       => (string) ( $payload['token'] ?? '' ),
			'lock_lane'        => (string) ( $payload['lane'] ?? '' ),
			'acquired'         => $acquired,
		);
	}
}
