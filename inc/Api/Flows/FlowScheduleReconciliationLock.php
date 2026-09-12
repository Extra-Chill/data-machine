<?php
/**
 * Atomic per-site flow schedule reconciliation lock.
 *
 * Compare-and-set option lock that never trusts the persistent object
 * cache for the lock row itself. Every read this class needs to make a
 * locking decision goes straight to `wp_options` via `$wpdb`; every
 * mutation is a conditional `$wpdb->query()` against the exact DB-read
 * value, followed by an unconditional `wp_cache_delete()`.
 *
 * This is deliberately stricter than a plain add_option()/get_option()
 * compare-and-set. `add_option()` checks the object cache before it checks
 * the database, and `get_option()` serves the cached value when present.
 * If a killed request leaves this lock cached in Redis with no backing row
 * in `wp_options` (a normal outcome of a request dying mid-write, and the
 * exact failure mode observed on the underlying agents-api registry lock,
 * `agents_routine_reconcile_lock`, during the Extra-Chill/data-machine#3492
 * incident):
 *
 *  - `add_option()` always fails (cache hit), so the "fast path" acquire
 *    never succeeds again on this site;
 *  - `get_option()` keeps returning the cached payload as if it were live,
 *    so the staleness check sees an apparently-fresh lock for up to
 *    STALE_AFTER seconds even though nothing actually holds it;
 *  - once the cached value is finally old enough to be treated as stale,
 *    a compare-and-set UPDATE against it matches zero rows (there is no
 *    row to update), so acquisition fails forever — the lock is
 *    permanently stuck until an operator manually flushes the cache key.
 *
 * Reading and comparing against `wp_options` directly (never the cache)
 * closes that hole: the database, not Redis, is the only source of truth
 * for whether the lock row exists and what it currently says.
 *
 * Guards {@see \DataMachine\Engine\Scheduling\FlowRoutines::reconcile()}
 * before it calls `boot()`, so lock contention costs one direct DB read
 * instead of a full ~700-routine registration pass.
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

		$row = self::read_db_row();

		if ( null === $row ) {
			// No backing row. The object cache may still hold a stale
			// payload from a killed request — clear it before inserting so
			// a later get_option() elsewhere can't resurrect a phantom lock
			// with no row behind it.
			wp_cache_delete( self::OPTION_NAME, 'options' );

			if ( self::insert_new( $payload ) ) {
				return $token;
			}

			// Someone else's INSERT won the race between our read and our
			// insert attempt. Re-read and fall through to evaluate their lock
			// instead of assuming failure.
			$row = self::read_db_row();
			if ( null === $row ) {
				return new \WP_Error( 'flow_schedule_reconciliation_locked', 'Another flow schedule reconciliation acquired the lock first.' );
			}
		}

		if ( is_array( $row['value'] ) && (int) ( $row['value']['acquired_at'] ?? 0 ) > time() - self::STALE_AFTER ) {
			return new \WP_Error( 'flow_schedule_reconciliation_locked', 'Another flow schedule reconciliation is already running.' );
		}

		if ( self::compare_and_swap( $row['raw'], $payload ) ) {
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
		$row = self::read_db_row();
		if ( null === $row || ! is_array( $row['value'] ) || ! hash_equals( (string) ( $row['value']['token'] ?? '' ), $token ) ) {
			return false;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Conditional delete against the DB-read value provides token-safe lock release.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				self::OPTION_NAME,
				$row['raw']
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		// Clear the cache unconditionally: even a 0-row DELETE means someone
		// else already mutated the row out from under us, and either way a
		// stale cached copy must never survive this call.
		wp_cache_delete( self::OPTION_NAME, 'options' );

		return 1 === $deleted;
	}

	/**
	 * Atomically refresh a lock lease owned by the supplied token.
	 *
	 * @param string $token Active lock token.
	 * @return bool True when the exact owned lease was refreshed.
	 */
	public static function refresh( string $token ): bool {
		$row = self::read_db_row();
		if ( null === $row || ! is_array( $row['value'] ) || ! hash_equals( (string) ( $row['value']['token'] ?? '' ), $token ) ) {
			return false;
		}

		$replacement                = $row['value'];
		$replacement['acquired_at'] = max( time(), (int) ( $row['value']['acquired_at'] ?? 0 ) + 1 );

		return self::compare_and_swap( $row['raw'], $replacement );
	}

	/**
	 * Read the lock row straight from `wp_options`, bypassing the object cache.
	 *
	 * @return array{raw: string, value: mixed}|null Null when no row exists.
	 */
	private static function read_db_row(): ?array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Locking decisions must never be based on the cached option value.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT option_value FROM %i WHERE option_name = %s',
				$wpdb->options,
				self::OPTION_NAME
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
			return null;
		}

		return array(
			'raw'   => (string) $row['option_value'],
			'value' => maybe_unserialize( $row['option_value'] ),
		);
	}

	/**
	 * Insert the lock row only if no row exists yet (`INSERT IGNORE` on the
	 * unique `option_name` key), never through `add_option()`.
	 *
	 * @param array<string, mixed> $payload New lock payload.
	 * @return bool True when this call created the row.
	 */
	private static function insert_new( array $payload ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- INSERT IGNORE on the unique option_name key resolves concurrent first-acquire races.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
				$wpdb->options,
				self::OPTION_NAME,
				maybe_serialize( $payload ),
				'off'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		wp_cache_delete( self::OPTION_NAME, 'options' );

		return 1 === $inserted;
	}

	/**
	 * Atomically replace the exact expected raw (already-serialized) value.
	 *
	 * @param string                $expected_raw Raw `option_value` read directly from the DB.
	 * @param array<string, mixed>  $replacement  New lock payload.
	 * @return bool True when the expected owner was replaced.
	 */
	private static function compare_and_swap( string $expected_raw, array $replacement ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Conditional update against the DB-read value provides atomic stale-lock takeover.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				maybe_serialize( $replacement ),
				self::OPTION_NAME,
				$expected_raw
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		// Clear the cache unconditionally: a 0-row UPDATE means another
		// caller already replaced the row, and a resurrected stale cache
		// entry must never be allowed to survive either outcome.
		wp_cache_delete( self::OPTION_NAME, 'options' );

		return 1 === $updated;
	}
}
