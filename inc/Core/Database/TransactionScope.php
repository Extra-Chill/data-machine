<?php
/**
 * One database transaction boundary that preserves an enclosing transaction.
 *
 * @package DataMachine\Core\Database
 */

namespace DataMachine\Core\Database;

defined( 'ABSPATH' ) || exit;

final class TransactionScope {

	private static int $savepoint_sequence = 0;
	private bool $active = true;

	private function __construct(
		private $wpdb,
		private string $type,
		private ?string $name = null
	) {}

	/** Open a transaction or a savepoint when the connection already has a boundary. */
	public static function begin( $wpdb ): ?self {
		$in_transaction = BaseRepository::is_sqlite();
		if ( ! $in_transaction ) {
			// A caller that disabled autocommit owns the outer transaction.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$in_transaction = 0 === (int) $wpdb->get_var( 'SELECT @@autocommit' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$has_transaction_variable = $wpdb->get_var( "SHOW VARIABLES LIKE 'in_transaction'" );
			if ( ! $in_transaction && 'in_transaction' === strtolower( (string) $has_transaction_variable ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
				$in_transaction = 1 === (int) $wpdb->get_var( 'SELECT @@in_transaction' );
			}
		}

		if ( $in_transaction || BaseRepository::is_sqlite() ) {
			$name = 'datamachine_transaction_' . ( ++self::$savepoint_sequence );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $wpdb->query( "SAVEPOINT {$name}" ) ) {
				return null;
			}
			return new self( $wpdb, 'savepoint', $name );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return null;
		}
		return new self( $wpdb, 'transaction' );
	}

	/** Commit only this scope. */
	public function commit(): bool {
		if ( ! $this->active ) {
			return false;
		}

		if ( 'savepoint' === $this->type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The identifier is generated internally from a fixed prefix and integer sequence.
			$committed = false !== $this->wpdb->query( "RELEASE SAVEPOINT {$this->name}" );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$committed = false !== $this->wpdb->query( 'COMMIT' );
		}

		if ( $committed ) {
			$this->active = false;
		}
		return $committed;
	}

	/** Roll back only this scope. */
	public function rollback(): void {
		if ( ! $this->active ) {
			return;
		}

		if ( 'savepoint' === $this->type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The identifier is generated internally from a fixed prefix and integer sequence.
			if ( false === $this->wpdb->query( "ROLLBACK TO SAVEPOINT {$this->name}" ) ) {
				return;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The identifier is generated internally from a fixed prefix and integer sequence.
			if ( false === $this->wpdb->query( "RELEASE SAVEPOINT {$this->name}" ) ) {
				return;
			}
			$this->active = false;
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		if ( false !== $this->wpdb->query( 'ROLLBACK' ) ) {
			$this->active = false;
		}
	}
}
