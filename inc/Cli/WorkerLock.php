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
	 * @return array<string,int|string|bool> Lock state.
	 */
	public static function acquire( string $owner, int $ttl = self::DEFAULT_TTL ): array {
		$ttl = max( 60, $ttl );
		$now = time();

		$existing = self::snapshot( $now, $ttl );
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
		);

		if ( 'stale' === $existing['lock_status'] ) {
			delete_option( self::OPTION_NAME );
			if ( add_option( self::OPTION_NAME, $payload, '', 'no' ) ) {
				return self::formatSnapshot( $payload, $now, 'held', true );
			}

			$existing             = self::snapshot( $now, $ttl );
			$existing['acquired'] = false;
			return $existing;
		}

		if ( add_option( self::OPTION_NAME, $payload, '', 'no' ) ) {
			return self::formatSnapshot( $payload, $now, 'held', true );
		}

		$existing             = self::snapshot( $now, $ttl );
		$existing['acquired'] = false;
		return $existing;
	}

	/**
	 * Release the lock only when the caller still owns it.
	 *
	 * @param string $token Lock token returned by acquire().
	 */
	public static function release( string $token ): void {
		if ( '' === $token ) {
			return;
		}

		$payload = get_option( self::OPTION_NAME, array() );
		if ( is_array( $payload ) && hash_equals( (string) ( $payload['token'] ?? '' ), $token ) ) {
			delete_option( self::OPTION_NAME );
		}
	}

	/**
	 * Return a read-only lock snapshot for operator status surfaces.
	 *
	 * @param int|null $now Current timestamp for tests/callers that need stability.
	 * @param int      $ttl Stale-lock timeout in seconds.
	 * @return array<string,int|string|bool> Lock state.
	 */
	public static function snapshot( ?int $now = null, int $ttl = self::DEFAULT_TTL ): array {
		$now     = $now ?? time();
		$ttl     = max( 60, $ttl );
		$payload = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $payload ) || empty( $payload['started_at'] ) ) {
			return array(
				'lock_status'      => 'unlocked',
				'lock_owner'       => '',
				'lock_age_seconds' => 0,
				'lock_expires_at'  => 0,
				'lock_token'       => '',
				'acquired'         => false,
			);
		}

		$started_at = (int) ( $payload['started_at'] ?? 0 );
		$expires_at = (int) ( $payload['expires_at'] ?? ( $started_at + $ttl ) );
		$status     = $expires_at <= $now ? 'stale' : 'held';

		return self::formatSnapshot( $payload, $now, $status, false );
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
			'acquired'         => $acquired,
		);
	}
}
