<?php
/**
 * Real MySQL advisory-lock concurrency coverage.
 *
 * @package DataMachineEvents\Tests\Integration
 */

namespace DataMachineEvents\Tests\Integration;

use DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations;
use DataMachine\Core\EngineData;
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
		$this->assertTrue( class_exists( PostIdentityReservations::class ), 'Data Machine #3408 reservation infrastructure is required.' );
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
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

	public function test_concurrent_source_create_and_candidate_adoption_converge_or_conflict(): void {
		$source       = 'mysql-candidate-' . uniqid();
		$source_id    = 'event-' . uniqid();
		$identity     = hash( 'sha256', $source . "\0" . $source_id );
		$candidate_id = wp_insert_post(
			array(
				'post_title'  => 'Existing MySQL Candidate ' . uniqid(),
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $candidate_id );

		$event = array(
			'title'           => 'Concurrent Candidate Event ' . uniqid(),
			'venue'           => 'Concurrent Candidate Venue ' . uniqid(),
			'startDate'       => '2027-03-12',
			'startTime'       => '20:00',
			'source'          => $source,
			'source_id'       => $source_id,
			'source_identity' => $identity,
		);
		$gate  = tempnam( sys_get_temp_dir(), 'dme-candidate-gate-' );
		unlink( $gate );
		$files = array(
			'create' => tempnam( sys_get_temp_dir(), 'dme-candidate-create-' ),
			'adopt'  => tempnam( sys_get_temp_dir(), 'dme-candidate-adopt-' ),
		);
		$pids  = array();

		foreach ( array( 'create', 'adopt' ) as $operation ) {
			$pid = pcntl_fork();
			$this->assertNotSame( -1, $pid );
			if ( 0 === $pid ) {
				global $wpdb, $table_prefix;
				$wpdb = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
				$wpdb->set_prefix( $table_prefix );
				while ( ! file_exists( $gate ) ) {
					clearstatcache( true, $gate );
					usleep( 10000 );
				}
				$child_event = $event;
				if ( 'adopt' === $operation ) {
					$child_event['event_id'] = $candidate_id;
				}
				$result = ( new EventUpsert() )->upsertCanonicalEvent(
					$child_event,
					array(
						'post_status' => 'publish',
						'post_author' => 1,
					)
				);
				file_put_contents( $files[ $operation ], wp_json_encode( $result ) );
				exit( 0 );
			}
			$pids[] = $pid;
		}

		file_put_contents( $gate, 'go' );
		foreach ( $pids as $pid ) {
			pcntl_waitpid( $pid, $status );
			$this->assertTrue( pcntl_wifexited( $status ) );
		}
		unlink( $gate );
		$create = json_decode( (string) file_get_contents( $files['create'] ), true );
		$adopt  = json_decode( (string) file_get_contents( $files['adopt'] ), true );
		unlink( $files['create'] );
		unlink( $files['adopt'] );

		$this->assertTrue( $create['success'] ?? false, wp_json_encode( $create ) );
		if ( ! empty( $adopt['success'] ) ) {
			$this->assertSame( $candidate_id, (int) $adopt['data']['post_id'] );
			$this->assertSame( $candidate_id, (int) $create['data']['post_id'] );
		} else {
			$this->assertSame( 'event_source_claimant_conflict', $adopt['error_code'] ?? '' );
			$this->assertSame( 409, (int) ( $adopt['status'] ?? 0 ) );
		}

		$claimants = get_posts(
			array(
				'post_type'      => Event_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => EventUpsert::SOURCE_IDENTITY_META_KEY,
				'meta_value'     => $identity,
			)
		);
		$this->assertCount( 1, $claimants, 'Concurrent create/adopt must leave exactly one source claimant.' );
		$reservation = $this->reservation( $identity );
		$this->assertNotNull( $reservation );
		$this->assertSame( 'complete', $reservation['state'] );
		$this->assertSame( (int) reset( $claimants ), (int) $reservation['post_id'] );
	}

	public function test_different_source_identities_cannot_both_adopt_one_candidate(): void {
		$candidate_id = wp_insert_post(
			array(
				'post_title'  => 'Shared Existing Candidate ' . uniqid(),
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $candidate_id );
		$original_title = get_post_field( 'post_title', $candidate_id );
		$events         = array();
		foreach ( array( 'alpha', 'beta' ) as $name ) {
			$source = 'mysql-' . $name . '-' . uniqid();
			$id     = 'event-' . uniqid();
			$events[ $name ] = array(
				'title'           => 'Candidate Winner ' . $name . ' ' . uniqid(),
				'venue'           => 'Candidate Lock Venue ' . $name . ' ' . uniqid(),
				'startDate'       => '2027-03-13',
				'startTime'       => '20:00',
				'source'          => $source,
				'source_id'       => $id,
				'source_identity' => hash( 'sha256', $source . "\0" . $id ),
				'event_id'        => $candidate_id,
			);
		}

		$gate  = tempnam( sys_get_temp_dir(), 'dme-candidate-two-gate-' );
		unlink( $gate );
		$files = array(
			'alpha' => tempnam( sys_get_temp_dir(), 'dme-candidate-alpha-' ),
			'beta'  => tempnam( sys_get_temp_dir(), 'dme-candidate-beta-' ),
		);
		$pids = array();
		foreach ( $events as $name => $event ) {
			$pid = pcntl_fork();
			$this->assertNotSame( -1, $pid );
			if ( 0 === $pid ) {
				global $wpdb, $table_prefix;
				$wpdb = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
				$wpdb->set_prefix( $table_prefix );
				while ( ! file_exists( $gate ) ) {
					clearstatcache( true, $gate );
					usleep( 10000 );
				}
				$result = ( new EventUpsert() )->upsertCanonicalEvent(
					$event,
					array(
						'post_status' => 'publish',
						'post_author' => 1,
					)
				);
				file_put_contents( $files[ $name ], wp_json_encode( $result ) );
				exit( 0 );
			}
			$pids[] = $pid;
		}

		file_put_contents( $gate, 'go' );
		foreach ( $pids as $pid ) {
			pcntl_waitpid( $pid, $status );
			$this->assertTrue( pcntl_wifexited( $status ) );
		}
		unlink( $gate );
		$results = array();
		foreach ( $files as $name => $file ) {
			$results[ $name ] = json_decode( (string) file_get_contents( $file ), true );
			unlink( $file );
		}

		$successful = array_filter( $results, static fn( array $result ): bool => ! empty( $result['success'] ) );
		$failed     = array_filter( $results, static fn( array $result ): bool => empty( $result['success'] ) );
		$this->assertCount( 1, $successful, wp_json_encode( $results ) );
		$this->assertCount( 1, $failed, wp_json_encode( $results ) );
		$winner_name = (string) array_key_first( $successful );
		$loser_name  = (string) array_key_first( $failed );
		$this->assertSame( $candidate_id, (int) $successful[ $winner_name ]['data']['post_id'] );
		$this->assertSame( 'event_candidate_source_metadata_conflict', $failed[ $loser_name ]['error_code'] ?? '' );
		$this->assertSame( 409, (int) ( $failed[ $loser_name ]['status'] ?? 0 ) );

		clean_post_cache( $candidate_id );
		$winner = $events[ $winner_name ];
		$loser  = $events[ $loser_name ];
		$this->assertNotSame( $original_title, get_post_field( 'post_title', $candidate_id ) );
		$this->assertSame( $winner['title'], get_post_field( 'post_title', $candidate_id ) );
		$this->assertSame( array( $winner['source_identity'] ), get_post_meta( $candidate_id, EventUpsert::SOURCE_IDENTITY_META_KEY, false ) );
		$this->assertSame( array( $winner['source'] ), get_post_meta( $candidate_id, EventUpsert::SOURCE_NAME_META_KEY, false ) );
		$this->assertSame( array( $winner['source_id'] ), get_post_meta( $candidate_id, EventUpsert::SOURCE_ID_META_KEY, false ) );
		$this->assertNotContains( $loser['source_identity'], get_post_meta( $candidate_id, EventUpsert::SOURCE_IDENTITY_META_KEY, false ) );

		$winner_reservation = $this->reservation( $winner['source_identity'] );
		$this->assertNotNull( $winner_reservation );
		$this->assertSame( 'complete', $winner_reservation['state'] );
		$this->assertSame( $candidate_id, (int) $winner_reservation['post_id'] );
		$this->assertNull( $this->reservation( $loser['source_identity'] ), 'The rejected identity must not create a reservation.' );
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

	private function reservation( string $source_identity ): ?array {
		$identity = PostIdentityReservations::normalize_identity(
			Event_Post_Type::POST_TYPE,
			array(
				'key'   => EventUpsert::SOURCE_IDENTITY_META_KEY,
				'value' => $source_identity,
			)
		);
		$this->assertNotWPError( $identity );

		return ( new PostIdentityReservations() )->get_reservation( $identity['identity_hash'] );
	}
}
