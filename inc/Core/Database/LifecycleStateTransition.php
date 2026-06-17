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
	 * Update a row only while it is still in the expected state.
	 *
	 * The helper keeps store-specific identity columns and payload fields in the
	 * caller while centralizing the guarded state transition shape used by claims,
	 * leases, pending decisions, and tracked-item state machines.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $table_name, $data, $where, $data_formats, $where_formats );

		return false === $result ? false : (int) $result;
	}
}
