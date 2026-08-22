<?php
/**
 * Atomic RichText attribute repair integration tests.
 *
 * @package DataMachine\Tests\Unit\Core\Content
 */

namespace DataMachine\Tests\Unit\Core\Content;

use DataMachine\Core\Content\SourceDerivedBlockAttributeRepair;
use WP_UnitTestCase;

final class SourceDerivedBlockAttributeRepairTest extends WP_UnitTestCase {

	public function test_atomic_update_holds_post_row_lock_through_wp_update_post(): void {
		if ( ! class_exists( '\mysqli' ) || ! defined( 'MYSQLI_ASYNC' ) ) {
			$this->markTestSkipped( 'MySQLi async support is unavailable.' );
		}

		$second = $this->openMysqlConnection();
		if ( ! $second instanceof \mysqli ) {
			$this->markTestSkipped( 'A second direct test database connection is unavailable.' );
		}

		global $wpdb;
		$original   = '<!-- wp:paragraph --><p>Original</p><!-- /wp:paragraph -->';
		$repaired   = '<!-- wp:paragraph --><p>Repaired</p><!-- /wp:paragraph -->';
		$competing  = '<!-- wp:paragraph --><p>Concurrent editor</p><!-- /wp:paragraph -->';
		$post_id    = self::factory()->post->create( array( 'post_content' => $original ) );
		$table      = str_replace( '`', '``', $wpdb->posts );
		$escaped    = $second->real_escape_string( $competing );
		$blocked    = false;
		$async_sent = false;

		try {
			$this->assertTrue( $second->query( 'SET SESSION innodb_lock_wait_timeout = 2' ) );
			$repair = new SourceDerivedBlockAttributeRepair();
			$result = $repair->updatePostAtomically(
				$post_id,
				$repaired,
				$original,
				null,
				function ( array $post_data, bool $wp_error ) use ( $second, $table, $escaped, $post_id, &$blocked, &$async_sent ) {
					$async_sent = $second->query( "UPDATE `{$table}` SET post_content = '{$escaped}' WHERE ID = {$post_id}", MYSQLI_ASYNC );
					$read       = array( $second );
					$error      = array();
					$reject     = array();
					$blocked    = 0 === \mysqli_poll( $read, $error, $reject, 0, 100000 );

					return wp_update_post( $post_data, $wp_error );
				}
			);

			$this->assertSame( $post_id, $result );
			$this->assertTrue( $async_sent );
			$this->assertTrue( $blocked, 'The competing editor must wait for the post row lock.' );

			$ready = 0;
			for ( $attempt = 0; $attempt < 20 && 0 === $ready; ++$attempt ) {
				$read   = array( $second );
				$error  = array();
				$reject = array();
				$ready  = \mysqli_poll( $read, $error, $reject, 0, 100000 );
			}
			$this->assertSame( 1, $ready, 'The competing editor should resume after commit.' );
			$this->assertTrue( $second->reap_async_query() );

			clean_post_cache( $post_id );
			$this->assertSame( $competing, get_post( $post_id )->post_content );
		} finally {
			$second->query( 'ROLLBACK' );
			$second->close();
			wp_delete_post( $post_id, true );
		}
	}

	private function openMysqlConnection(): ?\mysqli {
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
