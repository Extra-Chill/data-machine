<?php
/**
 * Option-backed lease fencing integration tests.
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use DataMachine\Core\OptionLeaseStore;
use WP_UnitTestCase;

final class OptionLeaseStoreTest extends WP_UnitTestCase {

	private string $lease_name;
	private string $target_name;

	public function set_up(): void {
		parent::set_up();
		$suffix            = wp_generate_uuid4();
		$this->lease_name  = 'datamachine_test_lease_' . $suffix;
		$this->target_name = 'datamachine_test_generation_' . $suffix;
	}

	public function tear_down(): void {
		delete_option( $this->lease_name );
		delete_option( $this->target_name );
		parent::tear_down();
	}

	public function test_owned_compare_and_swap_updates_the_expected_generation(): void {
		$lease = $this->lease();
		add_option( $this->lease_name, $lease, '', false );
		add_option( $this->target_name, 'generation-1', '', false );

		$this->assertTrue( OptionLeaseStore::compareAndSwapWhileOwned( $this->target_name, 'generation-1', 'generation-2', $this->lease_name, $lease ) );
		$this->assertSame( 'generation-2', get_option( $this->target_name ) );
	}

	public function test_stale_generation_cannot_be_replaced(): void {
		$lease = $this->lease();
		add_option( $this->lease_name, $lease, '', false );
		add_option( $this->target_name, 'generation-2', '', false );

		$this->assertFalse( OptionLeaseStore::compareAndSwapWhileOwned( $this->target_name, 'generation-1', 'generation-3', $this->lease_name, $lease ) );
		$this->assertSame( 'generation-2', get_option( $this->target_name ) );
	}

	public function test_wrong_owner_cannot_replace_the_generation(): void {
		$lease = $this->lease();
		add_option( $this->lease_name, $lease, '', false );
		add_option( $this->target_name, 'generation-1', '', false );
		$wrong_owner          = $lease;
		$wrong_owner['token'] = 'other-owner';

		$this->assertFalse( OptionLeaseStore::compareAndSwapWhileOwned( $this->target_name, 'generation-1', 'generation-2', $this->lease_name, $wrong_owner ) );
		$this->assertSame( 'generation-1', get_option( $this->target_name ) );
	}

	public function test_acquire_replaces_an_exact_stale_lease(): void {
		$now   = time();
		$stale = array(
			'token'      => 'stale-owner',
			'started_at' => $now - 600,
			'expires_at' => $now - 300,
		);
		$fresh = array(
			'token'      => 'fresh-owner',
			'started_at' => $now,
			'expires_at' => $now + 300,
		);
		add_option( $this->lease_name, $stale, '', false );

		$result = OptionLeaseStore::acquire( $this->lease_name, $fresh, 300, $now );

		$this->assertTrue( $result['acquired'] );
		$this->assertSame( $fresh, get_option( $this->lease_name ) );
	}

	public function test_two_mysql_sessions_allow_only_one_stale_lease_takeover(): void {
		if ( ! class_exists( '\mysqli' ) || ! defined( 'MYSQLI_ASYNC' ) ) {
			$this->markTestSkipped( 'MySQLi async support is unavailable.' );
		}

		$first  = $this->open_mysql_connection();
		$second = $this->open_mysql_connection();
		if ( ! $first instanceof \mysqli || ! $second instanceof \mysqli ) {
			$this->markTestSkipped( 'Two direct test database connections are unavailable.' );
		}

		$now   = time();
		$stale = array(
			'token'      => 'stale-owner',
			'started_at' => $now - 600,
			'expires_at' => $now - 300,
		);
		$winner = array(
			'token'      => 'first-owner',
			'started_at' => $now,
			'expires_at' => $now + 300,
		);
		$loser = array(
			'token'      => 'second-owner',
			'started_at' => $now,
			'expires_at' => $now + 300,
		);
		$table      = $this->options_table();
		$lease_name = $first->real_escape_string( $this->lease_name );

		try {
			$this->assertTrue( $first->query( 'SET SESSION innodb_lock_wait_timeout = 2' ) );
			$this->assertTrue( $second->query( 'SET SESSION innodb_lock_wait_timeout = 2' ) );
			$this->assertTrue(
				$first->query(
					sprintf(
						"INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES ('%s', '%s', 'no')",
						$lease_name,
						$first->real_escape_string( maybe_serialize( $stale ) )
					)
				)
			);
			$this->assertTrue( $first->query( 'START TRANSACTION' ) );
			$this->assertTrue( $first->query( $this->takeover_query( $first, $stale, $winner ) ) );
			$this->assertSame( 1, $first->affected_rows );
			$this->assertTrue( $second->query( $this->takeover_query( $second, $stale, $loser ), MYSQLI_ASYNC ) );
			$read   = array( $second );
			$error  = array();
			$reject = array();
			$this->assertSame( 0, \mysqli_poll( $read, $error, $reject, 0, 100000 ), 'The competing takeover must wait for the lease row lock.' );

			$this->assertTrue( $first->query( 'COMMIT' ) );
			$ready = 0;
			for ( $attempt = 0; $attempt < 20 && 0 === $ready; ++$attempt ) {
				$read   = array( $second );
				$error  = array();
				$reject = array();
				$ready  = \mysqli_poll( $read, $error, $reject, 0, 100000 );
			}

			$this->assertSame( 1, $ready, 'The competing takeover should resume after the winner commits.' );
			$this->assertTrue( $second->reap_async_query() );
			$this->assertSame( 0, $second->affected_rows );
			$result = $first->query( "SELECT option_value FROM `{$table}` WHERE option_name = '{$lease_name}'" );
			$this->assertInstanceOf( \mysqli_result::class, $result );
			$this->assertSame( $winner, maybe_unserialize( $result->fetch_assoc()['option_value'] ) );
		} finally {
			$first->query( 'ROLLBACK' );
			$second->query( 'ROLLBACK' );
			$first->close();
			$second->close();
		}
	}

	public function test_two_mysql_sessions_allow_only_one_generation_replacement(): void {
		if ( ! class_exists( '\mysqli' ) || ! defined( 'MYSQLI_ASYNC' ) ) {
			$this->markTestSkipped( 'MySQLi async support is unavailable.' );
		}

		$first  = $this->open_mysql_connection();
		$second = $this->open_mysql_connection();
		if ( ! $first instanceof \mysqli || ! $second instanceof \mysqli ) {
			$this->markTestSkipped( 'Two direct test database connections are unavailable.' );
		}

		$lease       = $this->lease();
		$table       = $this->options_table();
		$lease_name  = $first->real_escape_string( $this->lease_name );
		$target_name = $first->real_escape_string( $this->target_name );

		try {
			$this->assertTrue( $first->query( 'SET SESSION innodb_lock_wait_timeout = 2' ) );
			$this->assertTrue( $second->query( 'SET SESSION innodb_lock_wait_timeout = 2' ) );
			$this->assertTrue(
				$first->query(
					sprintf(
						"INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES ('%s', '%s', 'no'), ('%s', '%s', 'no')",
						$lease_name,
						$first->real_escape_string( maybe_serialize( $lease ) ),
						$target_name,
						$first->real_escape_string( maybe_serialize( 'generation-1' ) )
					)
				)
			);
			$this->assertTrue( $first->query( 'START TRANSACTION' ) );
			$this->assertTrue( $first->query( $this->cas_query( $first, $lease, 'generation-a' ) ) );
			$this->assertSame( 1, $first->affected_rows );
			$this->assertTrue( $second->query( $this->cas_query( $second, $lease, 'generation-b' ), MYSQLI_ASYNC ) );
			$read   = array( $second );
			$error  = array();
			$reject = array();
			$this->assertSame( 0, \mysqli_poll( $read, $error, $reject, 0, 100000 ), 'The competing CAS must wait for the target row lock.' );

			$this->assertTrue( $first->query( 'COMMIT' ) );
			$ready = 0;
			for ( $attempt = 0; $attempt < 20 && 0 === $ready; ++$attempt ) {
				$read   = array( $second );
				$error  = array();
				$reject = array();
				$ready  = \mysqli_poll( $read, $error, $reject, 0, 100000 );
			}

			$this->assertSame( 1, $ready, 'The competing CAS should resume after the winner commits.' );
			$this->assertTrue( $second->reap_async_query() );
			$this->assertSame( 0, $second->affected_rows );
			$result = $first->query( "SELECT option_value FROM `{$table}` WHERE option_name = '{$target_name}'" );
			$this->assertInstanceOf( \mysqli_result::class, $result );
			$this->assertSame( 'generation-a', maybe_unserialize( $result->fetch_assoc()['option_value'] ) );
		} finally {
			$first->query( 'ROLLBACK' );
			$second->query( 'ROLLBACK' );
			$first->close();
			$second->close();
		}
	}

	/** @return array{token:string,started_at:int,expires_at:int} */
	private function lease(): array {
		return array(
			'token'      => 'owner-token',
			'started_at' => time(),
			'expires_at' => time() + 300,
		);
	}

	private function cas_query( \mysqli $connection, array $lease, string $replacement ): string {
		$table = $this->options_table();

		return sprintf(
			"UPDATE `{$table}` AS target INNER JOIN `{$table}` AS lease ON lease.option_name = '%s' AND lease.option_value = '%s' SET target.option_value = '%s' WHERE target.option_name = '%s' AND target.option_value = '%s'",
			$connection->real_escape_string( $this->lease_name ),
			$connection->real_escape_string( maybe_serialize( $lease ) ),
			$connection->real_escape_string( maybe_serialize( $replacement ) ),
			$connection->real_escape_string( $this->target_name ),
			$connection->real_escape_string( maybe_serialize( 'generation-1' ) )
		);
	}

	private function takeover_query( \mysqli $connection, array $stale, array $replacement ): string {
		$table = $this->options_table();

		return sprintf(
			"UPDATE `{$table}` SET option_value = '%s' WHERE option_name = '%s' AND option_value = '%s'",
			$connection->real_escape_string( maybe_serialize( $replacement ) ),
			$connection->real_escape_string( $this->lease_name ),
			$connection->real_escape_string( maybe_serialize( $stale ) )
		);
	}

	private function options_table(): string {
		global $wpdb;

		return str_replace( '`', '``', $wpdb->options );
	}

	private function open_mysql_connection(): ?\mysqli {
		$host   = DB_HOST;
		$port   = null;
		$socket = null;
		if ( preg_match( '/^([^:]+):(\d+)$/', $host, $matches ) ) {
			$host = $matches[1];
			$port = (int) $matches[2];
		} elseif ( preg_match( '/^([^:]+):(.+)$/', $host, $matches ) ) {
			$host   = $matches[1];
			$socket = $matches[2];
		}

		$connection = \mysqli_init();
		if ( false === $connection || ! @$connection->real_connect( $host, DB_USER, DB_PASSWORD, DB_NAME, $port, $socket ) ) {
			return null;
		}

		return $connection;
	}
}
