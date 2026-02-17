<?php
/**
 * Base Repository for shared database CRUD patterns.
 *
 * Provides common constructor logic (wpdb + table name), helper methods
 * for simple lookups, deletes, counts, and standardized error logging.
 *
 * @package DataMachine\Core\Database
 * @since   0.19.0
 */

namespace DataMachine\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for database repository classes.
 *
 * Child classes must define a TABLE_NAME constant with the unprefixed table name.
 */
abstract class BaseRepository {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	protected $wpdb;

	/**
	 * Full prefixed table name.
	 *
	 * @var string
	 */
	protected $table_name;

	/**
	 * Initialize wpdb and build the prefixed table name from the child's TABLE_NAME constant.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb       = $wpdb;
		$this->table_name = $wpdb->prefix . static::TABLE_NAME;
	}

	/**
	 * Get the full prefixed table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		return $this->table_name;
	}

	/**
	 * Find a single row by primary key column.
	 *
	 * @param string     $id_column Column name.
	 * @param int|string $id        Value to match.
	 * @return array|null Row as associative array or null.
	 */
	protected function find_by_id( string $id_column, $id ): ?array {
		$format = is_int( $id ) ? '%d' : '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table_name} WHERE {$id_column} = {$format}",
				$id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Delete a single row by primary key column.
	 *
	 * @param string     $id_column Column name.
	 * @param int|string $id        Value to match.
	 * @return bool True on success, false on failure.
	 */
	protected function delete_by_id( string $id_column, $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete(
			$this->table_name,
			array( $id_column => $id ),
			array( is_int( $id ) ? '%d' : '%s' )
		);

		return false !== $result;
	}

	/**
	 * Count rows with an optional WHERE clause.
	 *
	 * @param string $where        SQL WHERE clause (without "WHERE" keyword). Default '1=1'.
	 * @param array  $prepare_args Values for wpdb::prepare placeholders.
	 * @return int Row count.
	 */
	protected function count_rows( string $where = '1=1', array $prepare_args = array() ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where}";

		if ( ! empty( $prepare_args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $this->wpdb->prepare( $sql, ...$prepare_args );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * Log a database error if one occurred on the last query.
	 *
	 * @param string $context Description of the operation that failed.
	 * @param array  $extra   Additional context to include in the log entry.
	 * @return void
	 */
	protected function log_db_error( string $context, array $extra = array() ): void {
		if ( ! empty( $this->wpdb->last_error ) ) {
			do_action(
				'datamachine_log',
				'error',
				"DB error: {$context}",
				array_merge(
					array(
						'db_error' => $this->wpdb->last_error,
						'table'    => $this->table_name,
					),
					$extra
				)
			);
		}
	}
}
