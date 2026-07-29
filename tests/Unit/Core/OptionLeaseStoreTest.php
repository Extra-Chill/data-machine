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

	public function test_two_mysql_sessions_allow_only_one_generation_replacement(): void {
		if ( ! class_exists( '\mysqli' ) || ! defined( 'MYSQLI_ASYNC' ) ) {
			$this->markTestSkipped( 'MySQLi async support is unavailable.' );
		}

		$first  = $this->open_mysql_connection();
		$second = $this->open_mysql_connection();
		if ( ! $first instanceof \mysqli || ! $second instanceof \mysqli ) {
			$this->markTestSkipped( 'Two direct test database connections are unavailable.' );
		}

		$lease = $this->lease();
		add_option( $this->lease_name, $lease, '', false );
		add_option( $this->target_name, 'generation-1', '', false );

		try {
			$this->assertTrue( $first->query( $this->cas_query( $first, $lease, 'generation-a' ), MYSQLI_ASYNC ) );
			$this->assertTrue( $second->query( $this->cas_query( $second, $lease, 'generation-b' ), MYSQLI_ASYNC ) );
			$pending      = array( $first, $second );
			$affected_rows = array();
			$deadline     = microtime( true ) + 5;
			while ( $pending && microtime( true ) < $deadline ) {
				$read   = $pending;
				$error  = array();
				$reject = array();
				if ( 0 === \mysqli_poll( $read, $error, $reject, 0, 100000 ) ) {
					continue;
				}
				foreach ( array_merge( $read, $error, $reject ) as $ready ) {
					$this->assertTrue( $ready->reap_async_query() );
					$affected_rows[] = $ready->affected_rows;
					$pending         = array_values( array_filter( $pending, static fn( \mysqli $connection ): bool => $connection !== $ready ) );
				}
			}

			$this->assertSame( array(), $pending, 'Both concurrent CAS statements must finish.' );
			sort( $affected_rows, SORT_NUMERIC );
			$this->assertSame( array( 0, 1 ), $affected_rows );
			wp_cache_delete( $this->target_name, 'options' );
			$this->assertContains( get_option( $this->target_name ), array( 'generation-a', 'generation-b' ) );
		} finally {
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
		global $wpdb;
		$table = str_replace( '`', '``', $wpdb->options );

		return sprintf(
			"UPDATE `{$table}` AS target INNER JOIN `{$table}` AS lease ON lease.option_name = '%s' AND lease.option_value = '%s' SET target.option_value = '%s' WHERE target.option_name = '%s' AND target.option_value = '%s'",
			$connection->real_escape_string( $this->lease_name ),
			$connection->real_escape_string( maybe_serialize( $lease ) ),
			$connection->real_escape_string( maybe_serialize( $replacement ) ),
			$connection->real_escape_string( $this->target_name ),
			$connection->real_escape_string( maybe_serialize( 'generation-1' ) )
		);
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
