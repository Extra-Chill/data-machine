<?php
/**
 * Process-death integration coverage for explicit identity retries.
 *
 * @package DataMachine\Tests\Integration\Abilities\Content
 */

namespace DataMachine\Tests\Integration\Abilities\Content;

use DataMachine\Abilities\Content\UpsertPostAbility;
use DataMachine\Core\Database\BaseRepository;
use DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 * @group mysql
 */
class PostIdentityExplicitRetryProcessTest extends TestCase {

	private const CHILD_TIMEOUT_SECONDS = 10.0;

	/** @var array<int,true> */
	private array $children = array();

	public function test_process_death_retry_completes_durable_identity_checkpoint(): void {
		if ( BaseRepository::is_sqlite() ) {
			$this->markTestSkipped( 'Process-death coverage requires MySQL/InnoDB.' );
		}
		if ( ! function_exists( 'pcntl_fork' ) || ! function_exists( 'pcntl_waitpid' ) || ! function_exists( 'posix_kill' ) ) {
			$this->markTestSkipped( 'PCNTL and POSIX process control are required.' );
		}

		global $wpdb;
		$this->assertSame( '0', (string) $wpdb->get_var( 'SELECT @@session.in_transaction' ), 'Plain integration tests must not hold a parent transaction.' );
		PostIdentityReservations::create_table();
		$repository = new PostIdentityReservations();
		$schema     = $repository->validate_schema();
		if ( is_wp_error( $schema ) ) {
			$this->markTestSkipped( 'A valid InnoDB reservation schema is required: ' . $schema->get_error_message() );
		}

		$parent_wpdb = $wpdb;
		$value       = 'process-retry-' . wp_generate_uuid4();
		$identity    = PostIdentityReservations::normalize_identity(
			'post',
			array(
				'key'   => '_source',
				'value' => $value,
			)
		);
		$table       = $repository->get_table_name();
		$result_file = tempnam( sys_get_temp_dir(), 'dm-identity-death-' );
		$retry_file  = tempnam( sys_get_temp_dir(), 'dm-identity-retry-' );
		$post_id     = 0;
		$inspect     = null;

		if ( false === $result_file || false === $retry_file ) {
			$this->remove_temporary_files( array( $result_file, $retry_file ) );
			$this->markTestSkipped( 'Temporary process result files are unavailable.' );
		}

		try {
			$writer_pid = $this->fork_child();
			if ( 0 === $writer_pid ) {
				$this->run_checkpoint_writer( $parent_wpdb->prefix, $identity, $value, $result_file );
			}
			$writer_status = $this->wait_for_child( $writer_pid );
			$checkpoint    = json_decode( (string) file_get_contents( $result_file ), true );
			$post_id       = (int) ( $checkpoint['post_id'] ?? 0 );

			$this->assertArrayNotHasKey( 'error', $checkpoint );
			$this->assertTrue( pcntl_wifsignaled( $writer_status ), 'Checkpoint writer must die by signal.' );
			$this->assertSame( SIGKILL, pcntl_wtermsig( $writer_status ) );

			$inspect               = $this->new_wpdb( $parent_wpdb->prefix );
			$GLOBALS['wpdb']       = $inspect;
			$checkpoint_repository = new PostIdentityReservations();
			$checkpoint_row        = $checkpoint_repository->get_reservation( $identity['identity_hash'] );
			$this->assertSame( 'linked', $checkpoint_row['state'] );
			$this->assertSame( $post_id, (int) $checkpoint_row['post_id'] );
			$this->assertSame( 'process_interrupted', $checkpoint_row['last_error_code'] );

			$retry_pid = $this->fork_child();
			if ( 0 === $retry_pid ) {
				$this->run_retry( $parent_wpdb->prefix, $post_id, $value, $retry_file );
			}
			$retry_status = $this->wait_for_child( $retry_pid );
			$result       = json_decode( (string) file_get_contents( $retry_file ), true );

			$this->assertTrue( pcntl_wifexited( $retry_status ) );
			$this->assertSame( 0, pcntl_wexitstatus( $retry_status ) );
			$this->assertArrayNotHasKey( 'error', $result );
			$this->assertTrue( $result['success'] );
			$this->assertSame( $post_id, (int) $result['post_id'] );

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
					'_source',
					$value
				)
			);
			$this->assertSame( array( (string) $post_id ), array_map( 'strval', $claimants ) );
			$this->assertSame( 1, (int) $inspect->get_var( $inspect->prepare( 'SELECT COUNT(*) FROM %i WHERE identity_hash = %s', $table, $identity['identity_hash'] ) ) );

			$lock_repository = new PostIdentityReservations();
			$this->assertTrue( $lock_repository->acquire_lock( $identity['identity_hash'] ) );
			$this->assertTrue( $lock_repository->release_lock( $identity['identity_hash'] ) );
		} finally {
			$this->terminate_children();
			$cleanup         = $inspect instanceof \wpdb ? $inspect : $this->new_wpdb( $parent_wpdb->prefix );
			$GLOBALS['wpdb'] = $cleanup;
			if ( $post_id <= 0 ) {
				$post_id = (int) $cleanup->get_var( $cleanup->prepare( 'SELECT post_id FROM %i WHERE identity_hash = %s', $table, $identity['identity_hash'] ) );
			}
			$cleanup->delete( $table, array( 'identity_hash' => $identity['identity_hash'] ), array( '%s' ) );
			$this->delete_post_tree( $cleanup, $post_id );
			$cleanup->close();
			$GLOBALS['wpdb'] = $parent_wpdb;
			$this->remove_temporary_files( array( $result_file, $retry_file ) );
		}
	}

	private function run_checkpoint_writer( string $prefix, array $identity, string $value, string $result_file ): void {
		$post_id = 0;
		try {
			$child_wpdb      = $this->new_wpdb( $prefix );
			$GLOBALS['wpdb'] = $child_wpdb;
			$repository      = new PostIdentityReservations();
			$locked          = $repository->acquire_lock( $identity['identity_hash'] );
			if ( is_wp_error( $locked ) ) {
				throw new \RuntimeException( $locked->get_error_message() );
			}
			$reserved = $repository->reserve_and_resolve(
				'post',
				array(
					'key'   => '_source',
					'value' => $value,
				),
				0,
				0,
				$this->shell()
			);
			if ( is_wp_error( $reserved ) ) {
				throw new \RuntimeException( $reserved->get_error_message() );
			}
			$post_id = (int) $reserved['post_id'];
			update_post_meta( $post_id, '_source', $value );
			$repository->record_error( $identity['identity_hash'], 'process_interrupted', 'Writer died after identity metadata.' );
			file_put_contents( $result_file, wp_json_encode( array( 'post_id' => $post_id ) ), LOCK_EX );
			posix_kill( getmypid(), SIGKILL );
		} catch ( \Throwable $throwable ) {
			file_put_contents(
				$result_file,
				wp_json_encode(
					array(
						'error'   => $throwable->getMessage(),
						'post_id' => $post_id,
					)
				),
				LOCK_EX
			);
			exit( 1 );
		}
		exit( 2 );
	}

	private function run_retry( string $prefix, int $post_id, string $value, string $retry_file ): void {
		try {
			$child_wpdb      = $this->new_wpdb( $prefix );
			$GLOBALS['wpdb'] = $child_wpdb;
			$result          = UpsertPostAbility::execute(
				array(
					'post_type'     => 'post',
					'post_id'       => $post_id,
					'title'         => 'Recovered after process death',
					'content'       => '<!-- wp:paragraph --><p>Recovered body</p><!-- /wp:paragraph -->',
					'identity_meta' => array(
						'key'   => '_source',
						'value' => $value,
					),
				)
			);
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
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
		$deadline = microtime( true ) + self::CHILD_TIMEOUT_SECONDS;
		do {
			$waited = pcntl_waitpid( $pid, $status, WNOHANG );
			if ( $pid === $waited ) {
				unset( $this->children[ $pid ] );
				return $status;
			}
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );

		posix_kill( $pid, SIGTERM );
		$deadline = microtime( true ) + 1.0;
		do {
			$waited = pcntl_waitpid( $pid, $status, WNOHANG );
			if ( $pid === $waited ) {
				unset( $this->children[ $pid ] );
				return $status;
			}
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );

		posix_kill( $pid, SIGKILL );
		pcntl_waitpid( $pid, $status );
		unset( $this->children[ $pid ] );
		return $status;
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

	private function delete_post_tree( \wpdb $database, int $post_id ): void {
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
		foreach ( array_reverse( $ids ) as $id ) {
			$comment_ids = array_map( 'intval', $database->get_col( $database->prepare( 'SELECT comment_ID FROM %i WHERE comment_post_ID = %d', $database->comments, $id ) ) );
			foreach ( $comment_ids as $comment_id ) {
				$database->delete( $database->commentmeta, array( 'comment_id' => $comment_id ), array( '%d' ) );
			}
			$database->delete( $database->comments, array( 'comment_post_ID' => $id ), array( '%d' ) );
			$database->delete( $database->term_relationships, array( 'object_id' => $id ), array( '%d' ) );
			$database->delete( $database->postmeta, array( 'post_id' => $id ), array( '%d' ) );
			$database->delete( $database->posts, array( 'ID' => $id ), array( '%d' ) );
			clean_post_cache( $id );
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

	private function shell(): array {
		return array(
			'post_author'    => get_current_user_id(),
			'comment_status' => get_default_comment_status( 'post' ),
			'ping_status'    => get_default_comment_status( 'post', 'pingback' ),
			'post_date'      => current_time( 'mysql' ),
			'post_date_gmt'  => current_time( 'mysql', true ),
			'guid'           => 'urn:uuid:' . wp_generate_uuid4(),
		);
	}
}
