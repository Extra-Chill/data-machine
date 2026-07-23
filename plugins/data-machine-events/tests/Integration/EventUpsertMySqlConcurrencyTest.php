<?php
/**
 * Real MySQL advisory-lock concurrency coverage.
 *
 * @package DataMachineEvents\Tests\Integration
 */

namespace DataMachineEvents\Tests\Integration;

use DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex;
use DataMachine\Core\EngineData;
use DataMachineEvents\Core\DuplicateDetection\EventIdentityWriter;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;
use WP_UnitTestCase;

class EventUpsertMySqlConcurrencyTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		if ( ! extension_loaded( 'mysqli' ) ) {
			$this->markTestSkipped( 'MySQL lock integration skipped: the mysqli extension is unavailable.' );
		}
		if ( ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'MySQL lock integration skipped: pcntl_fork() is unavailable.' );
		}
		if ( ! $wpdb->dbh instanceof \mysqli ) {
			$this->markTestSkipped( 'MySQL lock integration skipped: the configured WordPress test database is not MySQL (' . get_debug_type( $wpdb->dbh ) . ').' );
		}
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
		( new PostIdentityIndex() )->create_table();
	}

	public function test_held_lock_excludes_times_out_releases_and_loser_reuses_winner(): void {
		$title      = 'MySQL Concurrent Winner ' . uniqid();
		$venue_name = 'MySQL Lock Venue ' . uniqid();
		$venue      = wp_insert_term( $venue_name, 'venue' );
		$this->assertNotWPError( $venue );

		$handler = new EventUpsert();
		$keys    = $this->lockKeys( $handler, $title, $venue_name, '2026-12-01 20:00' );
		$held    = $keys[0];
		$owner   = $this->connection();
		$waiter  = $this->connection();

		$this->assertSame( 1, $this->lock( $owner, $held, 0 ) );
		$started = microtime( true );
		$this->assertSame( 0, $this->lock( $waiter, $held, 1 ), 'A second real connection must time out while the lock is held.' );
		$this->assertGreaterThanOrEqual( 0.8, microtime( true ) - $started );

		$result_file = tempnam( sys_get_temp_dir(), 'dme-lock-' );
		$pid         = pcntl_fork();
		$this->assertNotSame( -1, $pid );

		if ( 0 === $pid ) {
			global $wpdb, $table_prefix;
			$wpdb = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$wpdb->set_prefix( $table_prefix );
			$child_handler = new EventUpsert();
			$parameters    = array(
				'title'     => $title,
				'venue'     => $venue_name,
				'startDate' => '2026-12-01',
				'startTime' => '20:00',
				'engine'    => new EngineData(
					array(
						'title'     => $title,
						'venue'     => $venue_name,
						'startDate' => '2026-12-01',
						'startTime' => '20:00',
					),
					0
				),
				'job_id'    => 0,
			);
			$method        = new \ReflectionMethod( $child_handler, 'executeUpsert' );
			$method->setAccessible( true );
			$result = $method->invoke( $child_handler, $parameters, array( 'post_status' => 'publish', 'post_author' => 1 ) );
			file_put_contents( $result_file, wp_json_encode( $result ) );
			exit( 0 );
		}

		usleep( 300000 );
		$winner_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2026-12-01","startTime":"20:00"} --><div></div><!-- /wp:data-machine-events/event-details -->',
			)
		);
		wp_set_object_terms( $winner_id, array( $venue['term_id'] ), 'venue' );
		EventIdentityWriter::syncIdentityRow( $winner_id, $title );

		$this->assertSame( 1, $this->release( $owner, $held ) );
		pcntl_waitpid( $pid, $status );
		$this->assertTrue( pcntl_wifexited( $status ) );
		$result = json_decode( (string) file_get_contents( $result_file ), true );
		unlink( $result_file );

		$this->assertTrue( $result['success'] ?? false, wp_json_encode( $result ) );
		$this->assertSame( $winner_id, (int) ( $result['data']['post_id'] ?? 0 ) );
		$this->assertContains( $result['data']['action'] ?? '', array( 'updated', 'no_change' ) );
		clean_post_cache( $winner_id );
		$matching_posts = get_posts(
			array(
				'post_type'      => Event_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'title'          => $title,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertSame( array( $winner_id ), array_map( 'intval', $matching_posts ), 'Exactly one canonical event must remain after the blocked loser rechecks.' );
		$this->assertSame( 1, $this->lock( $waiter, $held, 0 ), 'The same lock must be acquirable after release.' );
		$this->assertSame( 1, $this->release( $waiter, $held ) );

		$owner->close();
		$waiter->close();
	}

	private function lockKeys( EventUpsert $handler, string $title, string $venue, string $start ): array {
		$method = new \ReflectionMethod( $handler, 'buildUpsertLockKeys' );
		$method->setAccessible( true );

		return $method->invoke( $handler, $title, $venue, $start );
	}

	private function connection(): \mysqli {
		$connection = mysqli_init();
		$connection->real_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );

		return $connection;
	}

	private function lock( \mysqli $connection, string $key, int $timeout ): int {
		$statement = $connection->prepare( 'SELECT GET_LOCK(?, ?)' );
		$statement->bind_param( 'si', $key, $timeout );
		$statement->execute();

		return (int) $statement->get_result()->fetch_row()[0];
	}

	private function release( \mysqli $connection, string $key ): int {
		$statement = $connection->prepare( 'SELECT RELEASE_LOCK(?)' );
		$statement->bind_param( 's', $key );
		$statement->execute();

		return (int) $statement->get_result()->fetch_row()[0];
	}
}
