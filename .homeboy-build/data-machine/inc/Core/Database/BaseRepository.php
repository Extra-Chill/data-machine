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
		$this->table_name = static::get_table_prefix() . static::TABLE_NAME;
	}

	/**
	 * Get the table prefix for this repository.
	 *
	 * Defaults to $wpdb->prefix (per-site). Network-scoped repositories
	 * (agents, tokens, access) override this to return $wpdb->base_prefix
	 * so their tables are shared across the multisite network, following
	 * the same pattern WordPress uses for wp_users and wp_usermeta.
	 *
	 * @return string Table prefix.
	 */
	protected static function get_table_prefix(): string {
		global $wpdb;
		return $wpdb->prefix;
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
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- Table name from $wpdb->prefix, not user input.
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table_name} WHERE {$id_column} = {$format}",
				$id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

		return $row ? $row : null;
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
	 * Check whether WordPress is running on SQLite (e.g. WordPress Studio).
	 *
	 * The SQLite Database Integration plugin defines this constant in its
	 * db.php drop-in. Checking it is the canonical way to detect SQLite at
	 * runtime — no autoload, no option lookup, no file sniffing required.
	 *
	 * @since 0.45.0
	 *
	 * @return bool True when the active database driver is SQLite.
	 */
	public static function is_sqlite(): bool {
		return defined( 'DATABASE_TYPE' ) && 'sqlite' === DATABASE_TYPE;
	}

	/**
	 * Check whether a column exists on a table.
	 *
	 * Uses `SHOW COLUMNS FROM <table> LIKE '<col>'` which the SQLite
	 * Database Integration translator already handles, avoiding the
	 * MySQL-only `information_schema.COLUMNS` + `DB_NAME` pattern that
	 * fatals on SQLite.
	 *
	 * @since 0.45.0
	 *
	 * @param string      $table_name Fully-qualified (prefixed) table name.
	 * @param string      $column     Column name to check.
	 * @param \wpdb|null  $wpdb       Optional wpdb instance (defaults to global).
	 * @return bool
	 */
	public static function column_exists( string $table_name, string $column, ?\wpdb $wpdb = null ): bool {
		if ( null === $wpdb ) {
			global $wpdb;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, $column )
		);

		return null !== $result;
	}

	/**
	 * Get column metadata from information_schema (MySQL only).
	 *
	 * Returns an object-keyed result set with DATA_TYPE,
	 * CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE per column. On SQLite this
	 * returns an empty array because the schema introspection queries
	 * that consume this data (MODIFY COLUMN, etc.) are MySQL-only
	 * operations anyway.
	 *
	 * @since 0.45.0
	 *
	 * @param string   $table_name  Fully-qualified (prefixed) table name.
	 * @param string[] $columns     Column names to inspect.
	 * @param \wpdb|null $wpdb      Optional wpdb instance.
	 * @return array<string,object> Column name → metadata object. Empty on SQLite.
	 */
	public static function get_column_meta( string $table_name, array $columns, ?\wpdb $wpdb = null ): array {
		if ( null === $wpdb ) {
			global $wpdb;
		}

		// On SQLite, MODIFY COLUMN is not supported and these migrations
		// are irrelevant — the columns were created with the correct types
		// from the start via dbDelta / CREATE TABLE.
		if ( self::is_sqlite() ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $columns ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
				 FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
				 AND COLUMN_NAME IN ({$placeholders})",
				DB_NAME,
				$table_name,
				...$columns
			),
			OBJECT_K
		);
		// phpcs:enable WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders

		return is_array( $results ) ? $results : array();
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
