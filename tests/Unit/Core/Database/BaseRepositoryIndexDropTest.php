<?php
/**
 * Regression tests for portable index removal.
 *
 * @package DataMachine\Tests\Unit\Core\Database
 */

namespace {
	if ( ! class_exists( 'wpdb' ) ) {
		class wpdb {}
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}
}

namespace DataMachine\Tests\Unit\Core\Database {

use DataMachine\Core\Database\BaseRepository;
use PHPUnit\Framework\TestCase;

class IndexDropWpdb extends \wpdb {

	public $last_error = '';
	public $indexes = array();
	public $queries = array();
	public $reject_alter_table_drop_index = false;
	protected $dbh;

	public function __construct() {}

	public function __get( $name ) {
		return $this->$name;
	}

	public function __isset( $name ): bool {
		return isset( $this->$name );
	}

	public function set_driver( IndexDropDriver $driver ): void {
		$this->dbh = $driver;
	}

	public function prepare( $query, ...$args ): string {
		foreach ( $args as $argument ) {
			$query = preg_replace( '/%i/', '`' . str_replace( '`', '``', (string) $argument ) . '`', $query, 1 );
		}
		return $query;
	}

	public function get_results( $query = null, $output = null ): array {
		unset( $query, $output );
		return $this->indexes;
	}

	public function query( $query ) {
		$this->queries[] = $query;
		if ( $this->reject_alter_table_drop_index && str_contains( $query, 'ALTER TABLE' ) && str_contains( $query, 'DROP INDEX' ) ) {
			$this->last_error = 'SQLite parser rejected ALTER TABLE DROP INDEX';
			return false;
		}
		return 1;
	}
}

class IndexDropConnection {

	public function quote_identifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}
}

class IndexDropDriver {

	public $queries = array();
	public $fail = false;
	private \PDO $pdo;

	public function __construct( \PDO $pdo ) {
		$this->pdo = $pdo;
	}

	public function get_connection(): IndexDropConnection {
		return new IndexDropConnection();
	}

	public function execute_sqlite_query( string $sql ): void {
		$this->queries[] = $sql;
		if ( $this->fail ) {
			throw new \PDOException( 'native SQLite drop failed' );
		}
		$this->pdo->exec( $sql );
	}
}

class BaseRepositoryIndexDropTest extends TestCase {

	public function test_mysql_uses_the_existing_prepared_alter_table_contract(): void {
		$wpdb          = new IndexDropWpdb();
		$wpdb->indexes = array( array( 'Key_name' => 'legacy_slug' ) );

		$this->assertTrue( BaseRepository::drop_index( 'wp_records', 'legacy_slug', $wpdb ) );
		$this->assertSame( array( 'ALTER TABLE `wp_records` DROP INDEX `legacy_slug`' ), $wpdb->queries );
	}

	public function test_sqlite_bypasses_a_driver_that_rejects_alter_table_drop_index(): void {
		defined( 'DATABASE_TYPE' ) || define( 'DATABASE_TYPE', 'sqlite' );
		$pdo = new \PDO( 'sqlite::memory:' );
		$pdo->exec( 'CREATE TABLE records (slug TEXT, digest TEXT)' );
		$pdo->exec( 'CREATE UNIQUE INDEX legacy_slug ON records (slug)' );
		$pdo->exec( 'CREATE UNIQUE INDEX replacement_digest ON records (digest)' );

		$driver       = new IndexDropDriver( $pdo );
		$wpdb         = new IndexDropWpdb();
		$wpdb->set_driver( $driver );
		$wpdb->reject_alter_table_drop_index = true;
		$wpdb->indexes = array(
			array( 'Key_name' => 'legacy_slug' ),
			array( 'Key_name' => 'replacement_digest' ),
		);

		$this->assertTrue( BaseRepository::drop_index( 'records', 'legacy_slug', $wpdb ) );
		$this->assertSame( array( 'DROP INDEX `legacy_slug`' ), $driver->queries );
		$this->assertSame( array(), $wpdb->queries, 'Native SQLite execution must bypass the parser route.' );
		$this->assertFalse( (bool) $pdo->query( "SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'legacy_slug'" )->fetchColumn() );
		$this->assertSame( 'replacement_digest', $pdo->query( "SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'replacement_digest'" )->fetchColumn() );

		$wpdb->indexes = array( array( 'Key_name' => 'replacement_digest' ) );
		$this->assertTrue( BaseRepository::drop_index( 'records', 'legacy_slug', $wpdb ) );
		$this->assertCount( 1, $driver->queries, 'A missing index is an explicit no-op.' );
	}

	public function test_sqlite_drop_failure_is_surfaced(): void {
		$driver       = new IndexDropDriver( new \PDO( 'sqlite::memory:' ) );
		$driver->fail = true;
		$wpdb         = new IndexDropWpdb();
		$wpdb->set_driver( $driver );
		$wpdb->indexes = array( array( 'Key_name' => 'legacy_slug' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'native SQLite drop failed' );
		BaseRepository::drop_index( 'records', 'legacy_slug', $wpdb );
	}
}

}
