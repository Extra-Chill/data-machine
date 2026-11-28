<?php
/**
 * Process-death coverage for public event source identity retries.
 *
 * @package DataMachineEvents\Tests\Integration
 */

namespace DataMachineEvents\Tests\Integration;

use DataMachine\Core\Database\BaseRepository;
use DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations;
use DataMachineEvents\Abilities\EventUpsertAbilities;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 * @group mysql
 */
class EventUpsertSourceIdentityRecoveryTest extends TestCase {

	private const CHILD_TIMEOUT_SECONDS = 10.0;

	/** @var array<int,true> */
	private array $children = array();

	/**
	 * @dataProvider interruption_boundaries
	 */
	public function test_public_source_retry_survives_process_death( string $boundary ): void {
		if ( BaseRepository::is_sqlite() ) {
			$this->markTestSkipped( 'Process-death coverage requires MySQL/InnoDB.' );
		}
		if ( ! function_exists( 'pcntl_fork' ) || ! function_exists( 'pcntl_waitpid' ) || ! function_exists( 'posix_kill' ) ) {
			$this->markTestSkipped( 'PCNTL and POSIX process control are required.' );
		}

		global $wpdb;
		$this->assertSame( '0', (string) $wpdb->get_var( 'SELECT @@session.in_transaction' ), 'Plain integration tests must not hold a parent transaction.' );
		$this->register_event_types();
		EventDatesTable::create_table();
		PostIdentityReservations::create_table();

		$repository = new PostIdentityReservations();
		$schema     = $repository->validate_schema();
		if ( is_wp_error( $schema ) ) {
			$this->markTestSkipped( 'A valid InnoDB reservation schema is required: ' . $schema->get_error_message() );
		}

		$parent_wpdb     = $wpdb;
		$input           = $this->valid_input( $boundary );
		$source_identity = hash( 'sha256', $input['source'] . "\0" . $input['source_id'] );
		$identity        = PostIdentityReservations::normalize_identity(
			Event_Post_Type::POST_TYPE,
			array(
				'key'   => EventUpsert::SOURCE_IDENTITY_META_KEY,
				'value' => $source_identity,
			)
		);
		$this->assertIsArray( $identity );

		$checkpoint_file = tempnam( sys_get_temp_dir(), 'dme-identity-death-' );
		$retry_file      = tempnam( sys_get_temp_dir(), 'dme-identity-retry-' );
		$post_id         = 0;
		$inspect         = null;

		if ( false === $checkpoint_file || false === $retry_file ) {
			$this->remove_temporary_files( array( $checkpoint_file, $retry_file ) );
			$this->markTestSkipped( 'Temporary process result files are unavailable.' );
		}

		add_filter( 'datamachine_events_upsert_event_permission', '__return_true' );
		try {
			$writer_pid = $this->fork_child();
			if ( 0 === $writer_pid ) {
				$this->run_interrupted_upsert( $parent_wpdb->prefix, $input, $identity, $boundary, $checkpoint_file );
			}

			$writer_status = $this->wait_for_child( $writer_pid );
			$checkpoint    = json_decode( (string) file_get_contents( $checkpoint_file ), true );
			$post_id       = (int) ( $checkpoint['post_id'] ?? 0 );

			$this->assertArrayNotHasKey( 'error', $checkpoint );
			$this->assertGreaterThan( 0, $post_id );
			$this->assertTrue( pcntl_wifsignaled( $writer_status ), 'Checkpoint writer must die by signal.' );
			$this->assertSame( SIGKILL, pcntl_wtermsig( $writer_status ) );
			if ( 'before_population' === $boundary ) {
				$this->assertSame( '', $checkpoint['source_identity'] );
				$this->assertFalse( $checkpoint['date_reconciled'] );
				$this->assertSame( array(), $checkpoint['venue_terms'] );
			} elseif ( 'after_identity_meta' === $boundary ) {
				// Identity meta is written before save_post synchronizes event dates.
				$this->assertSame( $source_identity, $checkpoint['source_identity'] );
				$this->assertFalse( $checkpoint['date_reconciled'] );
				$this->assertSame( array(), $checkpoint['venue_terms'] );
			} else {
				$this->assertSame( $source_identity, $checkpoint['source_identity'] );
				$this->assertTrue( $checkpoint['date_reconciled'] );
				$this->assertCount( 1, $checkpoint['venue_terms'] );
				$this->assertGreaterThan( 0, $checkpoint['venue_terms'][0]['term_id'] );
				$this->assertSame( $input['event']['venue'], $checkpoint['venue_terms'][0]['name'] );
			}

			$inspect         = $this->new_wpdb( $parent_wpdb->prefix );
			$GLOBALS['wpdb'] = $inspect;
			$row             = ( new PostIdentityReservations() )->get_reservation( $identity['identity_hash'] );
			$this->assertSame( 'after_taxonomy' === $boundary ? 'complete' : 'linked', $row['state'] );
			$this->assertSame( $post_id, (int) $row['post_id'] );
			$this->assertSame( 'process_interrupted', $row['last_error_code'] );

			$retry_pid = $this->fork_child();
			if ( 0 === $retry_pid ) {
				$this->run_retry( $parent_wpdb->prefix, $input, $retry_file );
			}

			$retry_status = $this->wait_for_child( $retry_pid );
			$result       = json_decode( (string) file_get_contents( $retry_file ), true );

			$this->assertTrue( pcntl_wifexited( $retry_status ) );
			$this->assertSame( 0, pcntl_wexitstatus( $retry_status ) );
			$this->assertArrayNotHasKey( 'error', $result );
			$this->assertTrue( $result['success'] );
			$this->assertSame( $post_id, (int) $result['event_id'] );
			$this->assertSame( $source_identity, $result['source']['identity'] );

			$row = ( new PostIdentityReservations() )->get_reservation( $identity['identity_hash'] );
			$this->assertSame( 'complete', $row['state'] );
			$this->assertSame( $post_id, (int) $row['post_id'] );
			$this->assertNull( $row['last_error_code'] );
			$this->assertNull( $row['last_error_message'] );
			$this->assertNotEmpty( $row['completed_at'] );

			$claimants = $inspect->get_col(
				$inspect->prepare(
					'SELECT DISTINCT post_id FROM %i WHERE meta_key = %s AND meta_value = %s ORDER BY post_id',
					$inspect->postmeta,
					EventUpsert::SOURCE_IDENTITY_META_KEY,
					$source_identity
				)
			);
			$this->assertSame( array( (string) $post_id ), array_map( 'strval', $claimants ) );
			$this->assertSame( array( $post_id ), $this->discover_test_events( $inspect, $source_identity, $input['event']['title'], $post_id ) );
			$this->assertSame( $input['event']['title'], (string) $inspect->get_var( $inspect->prepare( 'SELECT post_title FROM %i WHERE ID = %d', $inspect->posts, $post_id ) ) );
			$this->assertSame( '2027-08-28 20:00:00', (string) $inspect->get_var( $inspect->prepare( 'SELECT start_datetime FROM %i WHERE post_id = %d', EventDatesTable::table_name(), $post_id ) ) );

			$venue_terms = $inspect->get_results(
				$inspect->prepare(
					"SELECT t.term_id, t.name FROM %i tr INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN %i t ON t.term_id = tt.term_id WHERE tr.object_id = %d AND tt.taxonomy = 'venue' ORDER BY t.term_id",
					$inspect->term_relationships,
					$inspect->term_taxonomy,
					$inspect->terms,
					$post_id
				),
				ARRAY_A
			);
			$venue_terms = array_map(
				static fn( array $term ): array => array( 'term_id' => (int) $term['term_id'], 'name' => (string) $term['name'] ),
				$venue_terms
			);
			$this->assertCount( 1, $venue_terms );
			$this->assertGreaterThan( 0, $venue_terms[0]['term_id'] );
			$this->assertSame( $input['event']['venue'], $venue_terms[0]['name'] );
			if ( 'after_taxonomy' === $boundary ) {
				$this->assertSame( $checkpoint['venue_terms'], $venue_terms );
			}

			$lock_repository = new PostIdentityReservations();
			$this->assertTrue( $lock_repository->acquire_lock( $identity['identity_hash'] ) );
			$this->assertTrue( $lock_repository->release_lock( $identity['identity_hash'] ) );
		} finally {
			remove_filter( 'datamachine_events_upsert_event_permission', '__return_true' );
			$this->terminate_children();
			$cleanup         = $inspect instanceof \wpdb ? $inspect : $this->new_wpdb( $parent_wpdb->prefix );
			$GLOBALS['wpdb'] = $cleanup;
			if ( $post_id <= 0 ) {
				$post_id = (int) $cleanup->get_var( $cleanup->prepare( 'SELECT post_id FROM %i WHERE identity_hash = %s', $repository->get_table_name(), $identity['identity_hash'] ) );
			}
			$event_ids = $this->discover_test_events( $cleanup, $source_identity, $input['event']['title'], $post_id );
			foreach ( $event_ids as $event_id ) {
				$this->delete_event_tree( $cleanup, $event_id );
			}
			$cleanup->delete( $repository->get_table_name(), array( 'identity_hash' => $identity['identity_hash'] ), array( '%s' ) );
			$this->delete_named_test_terms( $cleanup, $input['event']['venue'] );
			$cleanup->close();
			$GLOBALS['wpdb'] = $parent_wpdb;
			$this->remove_temporary_files( array( $checkpoint_file, $retry_file ) );
		}
	}

	public static function interruption_boundaries(): array {
		return array(
			'after reservation before population' => array( 'before_population' ),
			'after identity metadata before event date index synchronization' => array( 'after_identity_meta' ),
			'after event date and taxonomy reconciliation' => array( 'after_taxonomy' ),
		);
	}

	private function run_interrupted_upsert( string $prefix, array $input, array $identity, string $boundary, string $checkpoint_file ): void {
		try {
			$child_wpdb      = $this->new_wpdb( $prefix );
			$GLOBALS['wpdb'] = $child_wpdb;
			$this->register_event_types();

			$interrupt = function ( int $post_id ) use ( $identity, $boundary, $checkpoint_file ): void {
				$repository = new PostIdentityReservations();
				$repository->record_error( $identity['identity_hash'], 'process_interrupted', 'DME integration writer terminated at ' . $boundary . '.' );
				$venue_terms = wp_get_object_terms( $post_id, 'venue' );
				$venue_terms = is_wp_error( $venue_terms )
					? array()
					: array_map(
						static fn( \WP_Term $term ): array => array( 'term_id' => (int) $term->term_id, 'name' => (string) $term->name ),
						$venue_terms
					);
				file_put_contents(
					$checkpoint_file,
					wp_json_encode(
						array(
							'post_id'         => $post_id,
							'source_identity' => (string) get_post_meta( $post_id, EventUpsert::SOURCE_IDENTITY_META_KEY, true ),
							'date_reconciled' => null !== EventDatesTable::get( $post_id ),
							'venue_terms'     => $venue_terms,
						)
					),
					LOCK_EX
				);
				posix_kill( getmypid(), SIGKILL );
			};

			if ( 'before_population' === $boundary ) {
				add_action( 'datamachine_upsert_post_identity_before_population', $interrupt );
			} elseif ( 'after_identity_meta' === $boundary ) {
				add_action(
					'added_post_meta',
					static function ( int $meta_id, int $post_id, string $meta_key, $meta_value ) use ( $identity, $interrupt ): void {
						unset( $meta_id );
						if ( $identity['meta_key'] === $meta_key && $identity['meta_value'] === $meta_value ) {
							$interrupt( $post_id );
						}
					},
					10,
					4
				);
			} else {
				add_action( 'datamachine_event_taxonomy_processed', $interrupt );
			}

			$ability = wp_get_ability( EventUpsertAbilities::ABILITY_NAME );
			if ( ! $ability instanceof \WP_Ability ) {
				throw new \RuntimeException( 'Public event upsert ability is unavailable.' );
			}
			$result = $ability->execute( $input );
			file_put_contents( $checkpoint_file, wp_json_encode( array( 'error' => is_wp_error( $result ) ? $result->get_error_message() : 'Interruption hook did not run.' ) ), LOCK_EX );
			exit( 2 );
		} catch ( \Throwable $throwable ) {
			file_put_contents( $checkpoint_file, wp_json_encode( array( 'error' => $throwable->getMessage() ) ), LOCK_EX );
			exit( 1 );
		}
	}

	private function run_retry( string $prefix, array $input, string $retry_file ): void {
		try {
			$child_wpdb      = $this->new_wpdb( $prefix );
			$GLOBALS['wpdb'] = $child_wpdb;
			$this->register_event_types();
			$ability = wp_get_ability( EventUpsertAbilities::ABILITY_NAME );
			if ( ! $ability instanceof \WP_Ability ) {
				throw new \RuntimeException( 'Public event upsert ability is unavailable.' );
			}
			$result = $ability->execute( $input );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_code() . ': ' . $result->get_error_message() );
			}
			file_put_contents( $retry_file, wp_json_encode( $result ), LOCK_EX );
			exit( 0 );
		} catch ( \Throwable $throwable ) {
			file_put_contents( $retry_file, wp_json_encode( array( 'error' => $throwable->getMessage() ) ), LOCK_EX );
			exit( 1 );
		}
	}

	private function fork_child(): int {
		$pid = pcntl_fork();
		if ( -1 === $pid ) {
			$this->fail( 'Unable to fork integration child process.' );
		}
		if ( $pid > 0 ) {
			$this->children[ $pid ] = true;
		}
		return $pid;
	}

	private function wait_for_child( int $pid ): int {
		$status = $this->wait_until( $pid, microtime( true ) + self::CHILD_TIMEOUT_SECONDS );
		if ( null !== $status ) {
			return $status;
		}

		posix_kill( $pid, SIGTERM );
		$status = $this->wait_until( $pid, microtime( true ) + 1.0 );
		if ( null !== $status ) {
			return $status;
		}

		posix_kill( $pid, SIGKILL );
		pcntl_waitpid( $pid, $status );
		unset( $this->children[ $pid ] );
		return $status;
	}

	/** Poll one child until it exits or the deadline passes. */
	private function wait_until( int $pid, float $deadline ): ?int {
		do {
			$waited = pcntl_waitpid( $pid, $status, WNOHANG );
			if ( $pid === $waited ) {
				unset( $this->children[ $pid ] );
				return $status;
			}
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );

		return null;
	}

	private function terminate_children(): void {
		foreach ( array_keys( $this->children ) as $pid ) {
			posix_kill( $pid, SIGTERM );
		}
		$deadline = microtime( true ) + 1.0;
		do {
			foreach ( array_keys( $this->children ) as $pid ) {
				$waited = pcntl_waitpid( $pid, $status, WNOHANG );
				if ( $pid === $waited ) {
					unset( $this->children[ $pid ] );
				}
			}
			if ( empty( $this->children ) ) {
				return;
			}
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );

		foreach ( array_keys( $this->children ) as $pid ) {
			posix_kill( $pid, SIGKILL );
			pcntl_waitpid( $pid, $status );
			unset( $this->children[ $pid ] );
		}
	}

	private function discover_test_events( \wpdb $database, string $source_identity, string $title, int $reservation_post_id ): array {
		$identity_ids = $database->get_col(
			$database->prepare(
				'SELECT DISTINCT post_id FROM %i WHERE meta_key = %s AND meta_value = %s',
				$database->postmeta,
				EventUpsert::SOURCE_IDENTITY_META_KEY,
				$source_identity
			)
		);
		$title_ids = $database->get_col(
			$database->prepare(
				'SELECT ID FROM %i WHERE post_type = %s AND post_title = %s',
				$database->posts,
				Event_Post_Type::POST_TYPE,
				$title
			)
		);

		return array_values( array_unique( array_filter( array_map( 'intval', array_merge( $identity_ids, $title_ids, array( $reservation_post_id ) ) ) ) ) );
	}

	private function delete_event_tree( \wpdb $database, int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}
		$ids   = array( $post_id );
		$queue = array( $post_id );
		while ( ! empty( $queue ) ) {
			$parent   = array_shift( $queue );
			$children = array_map( 'intval', $database->get_col( $database->prepare( 'SELECT ID FROM %i WHERE post_parent = %d', $database->posts, $parent ) ) );
			foreach ( $children as $child ) {
				if ( ! in_array( $child, $ids, true ) ) {
					$ids[]   = $child;
					$queue[] = $child;
				}
			}
		}
		foreach ( array_reverse( array_unique( $ids ) ) as $id ) {
			$database->delete( EventDatesTable::table_name(), array( 'post_id' => $id ), array( '%d' ) );
			$database->delete( $database->term_relationships, array( 'object_id' => $id ), array( '%d' ) );
			$database->delete( $database->postmeta, array( 'post_id' => $id ), array( '%d' ) );
			$database->delete( $database->posts, array( 'ID' => $id ), array( '%d' ) );
			clean_post_cache( $id );
		}
	}

	private function delete_named_test_terms( \wpdb $database, string $name ): void {
		$term_ids = $database->get_col( $database->prepare( 'SELECT term_id FROM %i WHERE name = %s', $database->terms, $name ) );
		foreach ( array_map( 'intval', $term_ids ) as $term_id ) {
			$term_taxonomy_ids = $database->get_col( $database->prepare( "SELECT term_taxonomy_id FROM %i WHERE term_id = %d AND taxonomy = 'venue'", $database->term_taxonomy, $term_id ) );
			foreach ( array_map( 'intval', $term_taxonomy_ids ) as $term_taxonomy_id ) {
				$database->delete( $database->term_relationships, array( 'term_taxonomy_id' => $term_taxonomy_id ), array( '%d' ) );
				$database->delete( $database->term_taxonomy, array( 'term_taxonomy_id' => $term_taxonomy_id ), array( '%d' ) );
			}
			$database->delete( $database->termmeta, array( 'term_id' => $term_id ), array( '%d' ) );
			$database->delete( $database->terms, array( 'term_id' => $term_id ), array( '%d' ) );
			clean_term_cache( $term_id, 'venue' );
		}
	}

	private function register_event_types(): void {
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
	}

	private function new_wpdb( string $prefix ): \wpdb {
		$database = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$database->set_prefix( $prefix );
		return $database;
	}

	private function remove_temporary_files( array $files ): void {
		foreach ( $files as $file ) {
			if ( is_string( $file ) && file_exists( $file ) ) {
				unlink( $file );
			}
		}
	}

	private function valid_input( string $boundary ): array {
		$suffix = wp_generate_uuid4();
		return array(
			'source'    => 'dme-process-recovery',
			'source_id' => $boundary . '-' . $suffix,
			'event'     => array(
				'title'     => 'Process Recovery Event ' . $suffix,
				'startDate' => '2027-08-28',
				'startTime' => '20:00',
				'venue'     => 'Process Recovery Venue ' . $suffix,
			),
		);
	}
}
