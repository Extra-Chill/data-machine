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
	private static int $open_scopes        = 0;
	private bool $active                   = true;

	private function __construct(
		private $wpdb,
		private string $type,
		private ?string $name = null
	) {}

	/**
	 * Release the nesting depth held by a scope that was never resolved.
	 *
	 * A caller that returns or throws without committing or rolling back would
	 * otherwise leave the depth raised for the rest of the request, and every
	 * later scope would nest inside a boundary that no longer exists.
	 */
	public function __destruct() {
		if ( $this->active ) {
			$this->release_depth();
		}
	}

	/** Open a transaction or a savepoint when the connection already has a boundary. */
	public static function begin( $wpdb ): ?self {
		// An enclosing scope from this class already owns a transaction. Server
		// variables cannot report this on every engine, so track it in process.
		$in_transaction = self::$open_scopes > 0;

		if ( ! $in_transaction ) {
			/*
			 * A caller that disabled autocommit owns the outer transaction.
			 *
			 * Engines that do not implement these MySQL server variables report
			 * NULL rather than a value. NULL casts to 0, which would otherwise
			 * be read as "autocommit disabled" and wrongly claim an enclosing
			 * transaction, so only a non-NULL zero counts.
			 */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$autocommit     = $wpdb->get_var( 'SELECT @@autocommit' );
			$in_transaction = null !== $autocommit && 0 === (int) $autocommit;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$has_transaction_variable = $wpdb->get_var( "SHOW VARIABLES LIKE 'in_transaction'" );
			if ( ! $in_transaction && 'in_transaction' === strtolower( (string) $has_transaction_variable ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
				$in_transaction = 1 === (int) $wpdb->get_var( 'SELECT @@in_transaction' );
			}
		}

		if ( $in_transaction ) {
			$name = 'datamachine_transaction_' . ( ++self::$savepoint_sequence );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $wpdb->query( "SAVEPOINT {$name}" ) ) {
				return null;
			}
			++self::$open_scopes;
			return new self( $wpdb, 'savepoint', $name );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return null;
		}
		++self::$open_scopes;
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
			$this->close();
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
			$this->close();
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		if ( false !== $this->wpdb->query( 'ROLLBACK' ) ) {
			$this->close();
		}
	}

	/** Mark this scope closed and release its nesting depth. */
	private function close(): void {
		$this->active = false;
		$this->release_depth();
	}

	/** Give back the nesting depth this scope claimed when it opened. */
	private function release_depth(): void {
		if ( self::$open_scopes > 0 ) {
			--self::$open_scopes;
		}
	}
}
