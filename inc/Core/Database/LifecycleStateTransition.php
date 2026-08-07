<?php
/**
 * Reusable lifecycle state transition helper.
 *
 * @package DataMachine\Core\Database
 */

namespace DataMachine\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small compare-and-set primitive for table-backed lifecycle state changes.
 */
class LifecycleStateTransition {

	/**
	 * Maximum number of attempts when a transition hits a transient deadlock.
	 *
	 * @var int
	 */
	private const MAX_DEADLOCK_ATTEMPTS = 5;

	/**
	 * Base backoff in microseconds between deadlock retries (grows linearly).
	 *
	 * @var int
	 */
	private const DEADLOCK_BACKOFF_BASE_US = 20000;

	/**
	 * Update a row only while it is still in the expected state.
	 *
	 * The helper keeps store-specific identity columns and payload fields in the
	 * caller while centralizing the guarded state transition shape used by claims,
	 * leases, pending decisions, and tracked-item state machines.
	 *
	 * Lifecycle transitions are the hottest concurrent writes in the system: many
	 * Action Scheduler runners can race to update the same status rows at once,
	 * which surfaces transient InnoDB deadlocks (MySQL error 1213) and lock-wait
	 * timeouts (1205). MySQL explicitly instructs callers to "try restarting
	 * transaction" in this case, so the failed statement is retried a bounded
	 * number of times with linear backoff before giving up. Non-deadlock errors
	 * are returned immediately without retry.
	 *
	 * @param \wpdb  $wpdb             WordPress database instance.
	 * @param string $table_name       Fully-qualified table name.
	 * @param array  $identity         Equality guards that identify the lifecycle row.
	 * @param string $state_column     Column that stores the lifecycle state.
	 * @param string $expected_state   State required before the transition.
	 * @param string $next_state       State to write when guards match.
	 * @param array  $updates          Additional columns to update with the transition.
	 * @param array  $identity_formats wpdb formats for identity guards.
	 * @param array  $update_formats   wpdb formats for additional updates.
	 * @return int|false Number of rows updated, or false on error.
	 */
	public static function compare_and_set(
		\wpdb $wpdb,
		string $table_name,
		array $identity,
		string $state_column,
		string $expected_state,
		string $next_state,
		array $updates = array(),
		array $identity_formats = array(),
		array $update_formats = array()
	): int|false {
		$data          = array_merge( $updates, array( $state_column => $next_state ) );
		$where         = array_merge( $identity, array( $state_column => $expected_state ) );
		$data_formats  = array_merge( $update_formats, array( '%s' ) );
		$where_formats = array_merge( $identity_formats, array( '%s' ) );

		for ( $attempt = 1; $attempt <= self::MAX_DEADLOCK_ATTEMPTS; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $table_name, $data, $where, $data_formats, $where_formats );

			if ( false !== $result ) {
				return (int) $result;
			}

			if ( ! self::is_deadlock_error( $wpdb->last_error ) || $attempt >= self::MAX_DEADLOCK_ATTEMPTS ) {
				break;
			}

			do_action(
				'datamachine_log',
				'warning',
				'Lifecycle transition deadlock; retrying',
				array(
					'table'          => $table_name,
					'state_column'   => $state_column,
					'expected_state' => $expected_state,
					'next_state'     => $next_state,
					'attempt'        => $attempt,
					'max_attempts'   => self::MAX_DEADLOCK_ATTEMPTS,
					'db_error'       => $wpdb->last_error,
				)
			);

			// Linear backoff with a little jitter to desynchronize racing runners.
			usleep( self::DEADLOCK_BACKOFF_BASE_US * $attempt + random_int( 0, self::DEADLOCK_BACKOFF_BASE_US ) );
		}

		return false;
	}

	/**
	 * Determine whether a wpdb error string represents a transient, retryable
	 * locking failure (InnoDB deadlock or lock-wait timeout).
	 *
	 * @param string $error The wpdb last_error string.
	 * @return bool True when the error is a deadlock or lock-wait timeout.
	 */
	private static function is_deadlock_error( string $error ): bool {
		if ( '' === $error ) {
			return false;
		}

		return false !== stripos( $error, 'Deadlock found' )
			|| false !== stripos( $error, 'Lock wait timeout exceeded' );
	}
}
