<?php
/**
 * Post identity reservation integration tests.
 *
 * @package DataMachine\Tests\Unit\Core\Database\PostIdentityReservations
 */

namespace DataMachine\Tests\Unit\Core\Database\PostIdentityReservations;

use DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations;
use WP_UnitTestCase;

class PostIdentityReservationsTest extends WP_UnitTestCase {

	private PostIdentityReservations $repository;

	public function set_up(): void {
		parent::set_up();
		PostIdentityReservations::create_table();
		$this->repository = new PostIdentityReservations();

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->repository->get_table_name() ) );
	}

	public function test_schema_is_site_scoped_minimal_and_innodb(): void {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . PostIdentityReservations::TABLE_NAME, $this->repository->get_table_name() );
		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$this->repository->get_table_name()
			)
		);
		$this->assertSame( 'INNODB', strtoupper( (string) $engine ) );

		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->repository->get_table_name() ), ARRAY_A );
		$columns = array_column( $columns, null, 'Field' );
		$this->assertSame( 'char(64)', strtolower( $columns['identity_hash']['Type'] ) );
		$this->assertSame( 'NO', $columns['identity_hash']['Null'] );
		$this->assertMatchesRegularExpression( '/^bigint(?:\(20\))? unsigned$/', strtolower( $columns['post_id']['Type'] ) );
		$this->assertSame( 'YES', $columns['post_id']['Null'] );
		$this->assertSame( 'reserved', $columns['state']['Default'] );
		$this->assertSame( '1', (string) $columns['attempt_count']['Default'] );
		$this->assertArrayNotHasKey( 'meta_value', $columns, 'Raw identity values must not be persisted.' );

		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $this->repository->get_table_name() ) );
		$post_id_indexes = array_filter( $indexes, static fn( $index ) => 'post_id' === $index->Column_name );
		$this->assertNotEmpty( $post_id_indexes );
		$this->assertSame( '1', (string) reset( $post_id_indexes )->Non_unique, 'post_id must not be unique.' );
		$this->assertTrue( $this->repository->validate_schema() );
	}

	public function test_multisite_uses_the_switched_site_prefix(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite test suite is not active.' );
		}

		global $wpdb;
		$identity       = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'site-lock-scope' ) );
		$main_lock_name = $this->repository->lock_name( $identity['identity_hash'] );
		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		try {
			PostIdentityReservations::create_table();
			$repository = new PostIdentityReservations();
			$this->assertSame( $wpdb->prefix . PostIdentityReservations::TABLE_NAME, $repository->get_table_name() );
			$this->assertNotSame( $wpdb->base_prefix . PostIdentityReservations::TABLE_NAME, $repository->get_table_name() );
			$this->assertNotSame( $main_lock_name, $repository->lock_name( $identity['identity_hash'] ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_lock_scope_separates_databases_and_site_tables(): void {
		$identity_hash = str_repeat( 'a', 64 );
		$first         = PostIdentityReservations::build_lock_name( 'database_one', 'wp_datamachine_post_identity_reservations', $identity_hash );
		$other_site    = PostIdentityReservations::build_lock_name( 'database_one', 'wp_2_datamachine_post_identity_reservations', $identity_hash );
		$other_database = PostIdentityReservations::build_lock_name( 'database_two', 'wp_datamachine_post_identity_reservations', $identity_hash );

		$this->assertLessThanOrEqual( 64, strlen( $first ) );
		$this->assertNotSame( $first, $other_site );
		$this->assertNotSame( $first, $other_database );
	}

	public function test_missing_table_fails_identity_write_closed(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $this->repository->get_table_name() ) );
		$validation = $this->repository->validate_schema();
		$result = $this->repository->reserve_and_resolve( 'post', array( 'key' => '_source', 'value' => 'no-table' ) );

		$this->assertWPError( $validation );
		$this->assertSame( 'identity_schema_missing', $validation->get_error_code() );
		$this->assertWPError( $result );
		$this->assertSame( 'identity_schema_missing', $result->get_error_code() );
	}

	public function test_nontransactional_reservation_table_fails_closed(): void {
		global $wpdb;

		$changed = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $this->repository->get_table_name() ) );
		$this->assertNotFalse( $changed );
		try {
			$validation = $this->repository->validate_schema();
			$result = $this->repository->reserve_and_resolve( 'post', array( 'key' => '_source', 'value' => 'wrong-engine' ) );
			$this->assertWPError( $validation );
			$this->assertSame( 'identity_schema_engine', $validation->get_error_code() );
			$this->assertWPError( $result );
			$this->assertSame( 'identity_schema_engine', $result->get_error_code() );
		} finally {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $this->repository->get_table_name() ) );
		}
	}

	public function test_create_table_repairs_myisam_to_innodb(): void {
		global $wpdb;

		$this->assertNotFalse( $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $this->repository->get_table_name() ) ) );
		PostIdentityReservations::create_table();

		$this->assertTrue( ( new PostIdentityReservations() )->validate_schema() );
		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$this->repository->get_table_name()
			)
		);
		$this->assertSame( 'INNODB', strtoupper( (string) $engine ) );
	}

	public function test_schema_validation_rejects_partial_table(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN completed_at', $this->repository->get_table_name() ) );
		$validation = $this->repository->validate_schema();

		$this->assertWPError( $validation );
		$this->assertSame( 'identity_schema_columns', $validation->get_error_code() );
	}

	public function test_schema_validation_requires_nonunique_post_id_index(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX post_id', $this->repository->get_table_name() ) );
		$validation = $this->repository->validate_schema();

		$this->assertWPError( $validation );
		$this->assertSame( 'identity_schema_post_index', $validation->get_error_code() );
	}

	public function test_schema_validation_rejects_unique_post_id_index(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX post_id, ADD UNIQUE KEY post_id_unique (post_id)', $this->repository->get_table_name() ) );
		try {
			$validation = $this->repository->validate_schema();

			$this->assertWPError( $validation );
			$this->assertSame( 'identity_schema_post_index', $validation->get_error_code() );
		} finally {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX post_id_unique, ADD KEY post_id (post_id)', $this->repository->get_table_name() ) );
		}
	}

	public function test_schema_validation_rejects_malformed_column_contract_and_create_table_repairs_it(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"ALTER TABLE %i
				MODIFY identity_hash char(63) NOT NULL,
				MODIFY post_id bigint(20) DEFAULT NULL,
				MODIFY state varchar(40) NOT NULL DEFAULT 'broken',
				MODIFY attempt_count bigint(20) unsigned NOT NULL DEFAULT 2,
				MODIFY completed_at datetime NOT NULL",
				$this->repository->get_table_name()
			)
		);

		$validation = $this->repository->validate_schema();
		$this->assertWPError( $validation );
		$this->assertSame( 'identity_schema_columns', $validation->get_error_code() );

		PostIdentityReservations::create_table();
		$this->assertTrue( ( new PostIdentityReservations() )->validate_schema() );
	}

	public function test_create_table_repairs_exact_unique_post_id_index_only(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'ALTER TABLE %i DROP INDEX post_id, ADD UNIQUE KEY conflicting_post_id (post_id), ADD KEY retained_post_state (post_id, state)',
				$this->repository->get_table_name()
			)
		);

		PostIdentityReservations::create_table();
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $this->repository->get_table_name() ), ARRAY_A );
		$grouped = array();
		foreach ( $indexes as $index ) {
			$grouped[ $index['Key_name'] ]['non_unique'] = (int) $index['Non_unique'];
			$grouped[ $index['Key_name'] ]['columns'][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
		}
		foreach ( $grouped as &$index ) {
			ksort( $index['columns'] );
			$index['columns'] = array_values( $index['columns'] );
		}
		unset( $index );

		$this->assertArrayNotHasKey( 'conflicting_post_id', $grouped );
		$this->assertSame( array( 'post_id', 'state' ), $grouped['retained_post_state']['columns'] );
		$this->assertNotEmpty(
			array_filter(
				$grouped,
				static fn( $index ) => 1 === $index['non_unique'] && array( 'post_id' ) === $index['columns']
			)
		);
		$this->assertTrue( ( new PostIdentityReservations() )->validate_schema() );
	}

	public function test_identity_hash_is_deterministic_and_unambiguous(): void {
		$first  = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'ab:c' ) );
		$repeat = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'ab:c' ) );
		$other  = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_sourcea', 'value' => 'b:c' ) );

		$this->assertSame( $first['identity_hash'], $repeat['identity_hash'] );
		$this->assertNotSame( $first['identity_hash'], $other['identity_hash'] );
		$this->assertSame( 64, strlen( $first['identity_hash'] ) );
	}

	public function test_first_allocation_and_retry_converge_on_one_shell(): void {
		$identity = array( 'key' => '_source', 'value' => 'one' );
		$first    = $this->repository->reserve_and_resolve( 'post', $identity, 0, 0, $this->shell() );
		$retry    = $this->repository->reserve_and_resolve( 'post', $identity, 0, 0, $this->shell() );

		$this->assertFalse( is_wp_error( $first ) );
		$this->assertTrue( $first['allocated'] );
		$this->assertSame( $first['post_id'], $retry['post_id'] );
		$this->assertFalse( $retry['allocated'] );
		$this->assertSame( 'draft', get_post_status( $first['post_id'] ) );

		$row = $this->repository->get_reservation( $first['identity']['identity_hash'] );
		$this->assertSame( 'linked', $row['state'] );
		$this->assertSame( '2', (string) $row['attempt_count'] );
	}

	public function test_two_connections_serialize_on_the_reservation_row(): void {
		if ( ! class_exists( '\mysqli' ) || ! defined( 'MYSQLI_ASYNC' ) ) {
			$this->markTestSkipped( 'MySQLi async support is unavailable.' );
		}

		$first_connection  = $this->open_mysql_connection();
		$second_connection = $this->open_mysql_connection();
		if ( ! $first_connection instanceof \mysqli || ! $second_connection instanceof \mysqli ) {
			$this->markTestSkipped( 'Two direct test database connections are unavailable.' );
		}

		global $wpdb;
		$identity = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'two-connections' ) );
		$now      = current_time( 'mysql', true );
		$wpdb->insert(
			$this->repository->get_table_name(),
			array(
				'identity_hash'   => $identity['identity_hash'],
				'post_type_hash'  => $identity['post_type_hash'],
				'meta_key_hash'   => $identity['meta_key_hash'],
				'meta_value_hash' => $identity['meta_value_hash'],
				'state'           => 'reserved',
				'attempt_count'   => 1,
				'last_attempt_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$table   = $this->repository->get_table_name();
		$hash    = $first_connection->real_escape_string( $identity['identity_hash'] );

		try {
			$first_connection->query( 'START TRANSACTION' );
			$first_connection->query( "SELECT identity_hash FROM `{$table}` WHERE identity_hash = '{$hash}' FOR UPDATE" );
			$first_connection->query( "UPDATE `{$table}` SET post_id = {$post_id}, state = 'linked' WHERE identity_hash = '{$hash}'" );

			$second_connection->query( 'START TRANSACTION' );
			$second_connection->query( "SELECT post_id, state FROM `{$table}` WHERE identity_hash = '{$hash}' FOR UPDATE", MYSQLI_ASYNC );
			$read   = array( $second_connection );
			$error  = array();
			$reject = array();
			$this->assertSame( 0, \mysqli_poll( $read, $error, $reject, 0, 100000 ), 'Second allocator must wait for the row lock.' );

			$first_connection->query( 'COMMIT' );
			$ready = 0;
			for ( $attempt = 0; $attempt < 20 && 0 === $ready; ++$attempt ) {
				$read   = array( $second_connection );
				$error  = array();
				$reject = array();
				$ready  = \mysqli_poll( $read, $error, $reject, 0, 100000 );
			}
			$this->assertSame( 1, $ready, 'Second allocator should resume after commit.' );
			$result = $second_connection->reap_async_query();
			$this->assertInstanceOf( \mysqli_result::class, $result );
			$row = $result->fetch_assoc();
			$this->assertSame( (string) $post_id, (string) $row['post_id'] );
			$this->assertSame( 'linked', $row['state'] );
		} finally {
			$first_connection->query( 'ROLLBACK' );
			$second_connection->query( 'ROLLBACK' );
			$first_connection->close();
			$second_connection->close();
		}
	}

	public function test_advisory_lock_fences_an_independent_connection_and_releases(): void {
		$connection = $this->open_mysql_connection();
		if ( ! $connection instanceof \mysqli ) {
			$this->markTestSkipped( 'A direct test database connection is unavailable.' );
		}

		$identity  = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'advisory-fence' ) );
		$lock_name = $this->repository->lock_name( $identity['identity_hash'] );
		$this->assertLessThanOrEqual( 64, strlen( $lock_name ) );
		$this->assertTrue( $this->repository->acquire_lock( $identity['identity_hash'] ) );
		try {
			$escaped = $connection->real_escape_string( $lock_name );
			$result  = $connection->query( "SELECT GET_LOCK('{$escaped}', 0) AS acquired" );
			$this->assertSame( '0', (string) $result->fetch_assoc()['acquired'] );
		} finally {
			$this->assertTrue( $this->repository->release_lock( $identity['identity_hash'] ) );
		}

		$result = $connection->query( "SELECT GET_LOCK('{$escaped}', 0) AS acquired" );
		$this->assertSame( '1', (string) $result->fetch_assoc()['acquired'] );
		$connection->query( "SELECT RELEASE_LOCK('{$escaped}')" );
		$connection->close();
	}

	public function test_unavailable_advisory_lock_returns_retryable_error(): void {
		$connection = $this->open_mysql_connection();
		if ( ! $connection instanceof \mysqli ) {
			$this->markTestSkipped( 'A direct test database connection is unavailable.' );
		}

		$identity  = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'advisory-busy' ) );
		$lock_name = $connection->real_escape_string( $this->repository->lock_name( $identity['identity_hash'] ) );
		$connection->query( "SELECT GET_LOCK('{$lock_name}', 0)" );
		try {
			$result = $this->repository->acquire_lock( $identity['identity_hash'] );
			$this->assertWPError( $result );
			$this->assertSame( 'identity_lock_unavailable', $result->get_error_code() );
			$this->assertTrue( $result->get_error_data()['retryable'] );
		} finally {
			$connection->query( "SELECT RELEASE_LOCK('{$lock_name}')" );
			$connection->close();
		}
	}

	public function test_exact_legacy_post_is_adopted(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		update_post_meta( $post_id, '_source', 'legacy-one' );

		$result = $this->repository->reserve_and_resolve( 'post', array( 'key' => '_source', 'value' => 'legacy-one' ) );

		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertTrue( $result['adopted'] );
		$this->assertFalse( $result['allocated'] );
	}

	public function test_duplicate_legacy_claimants_are_visible_conflict(): void {
		$ids = array(
			self::factory()->post->create( array( 'post_type' => 'post' ) ),
			self::factory()->post->create( array( 'post_type' => 'post' ) ),
		);
		foreach ( $ids as $post_id ) {
			update_post_meta( $post_id, '_source', 'legacy-duplicate' );
		}

		$result = $this->repository->reserve_and_resolve( 'post', array( 'key' => '_source', 'value' => 'legacy-duplicate' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'identity_legacy_conflict', $result->get_error_code() );
		$this->assertSame( $ids, $result->get_error_data()['candidate_ids'] );
		$identity = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'legacy-duplicate' ) );
		$row      = $this->repository->get_reservation( $identity['identity_hash'] );
		$this->assertSame( 'conflict', $row['state'] );
		$this->assertStringNotContainsString( 'legacy-duplicate', $row['last_error_message'] );
	}

	public function test_missing_link_fails_without_allocating_a_second_post(): void {
		global $wpdb;

		$identity = array( 'key' => '_source', 'value' => 'missing-link' );
		$first    = $this->repository->reserve_and_resolve( 'post', $identity, 0, 0, $this->shell() );
		$wpdb->delete( $wpdb->posts, array( 'ID' => $first['post_id'] ), array( '%d' ) );
		$retry = $this->repository->reserve_and_resolve( 'post', $identity, 0, 0, $this->shell() );

		$this->assertWPError( $retry );
		$this->assertSame( 'identity_link_invalid', $retry->get_error_code() );
		$this->assertSame( $first['post_id'], $retry->get_error_data()['post_id'] );
		$row = $this->repository->get_reservation( $first['identity']['identity_hash'] );
		$this->assertSame( (string) $first['post_id'], (string) $row['post_id'] );
	}

	public function test_wrong_post_type_link_fails_without_reallocation(): void {
		global $wpdb;

		$first = $this->repository->reserve_and_resolve( 'post', array( 'key' => '_source', 'value' => 'wrong-type' ), 0, 0, $this->shell() );
		$wpdb->update( $wpdb->posts, array( 'post_type' => 'page' ), array( 'ID' => $first['post_id'] ) );

		$retry = $this->repository->reserve_and_resolve( 'post', array( 'key' => '_source', 'value' => 'wrong-type' ), 0, 0, $this->shell() );
		$this->assertWPError( $retry );
		$this->assertSame( 'identity_link_invalid', $retry->get_error_code() );
		$this->assertSame( $first['post_id'], $retry->get_error_data()['post_id'] );
	}

	public function test_component_mismatch_is_integrity_conflict(): void {
		global $wpdb;

		$first = $this->repository->reserve_and_resolve( 'post', array( 'key' => '_source', 'value' => 'component-proof' ), 0, 0, $this->shell() );
		$wpdb->update(
			$this->repository->get_table_name(),
			array( 'meta_key_hash' => str_repeat( '0', 64 ) ),
			array( 'identity_hash' => $first['identity']['identity_hash'] )
		);

		$retry = $this->repository->reserve_and_resolve( 'post', array( 'key' => '_source', 'value' => 'component-proof' ), 0, 0, $this->shell() );
		$this->assertWPError( $retry );
		$this->assertSame( 'identity_integrity_conflict', $retry->get_error_code() );
	}

	public function test_allocation_failure_rolls_back_shell_but_keeps_reservation(): void {
		global $wpdb;
		$logs   = array();
		$logger = static function ( $level, $message, $context ) use ( &$logs ): void {
			if ( 'Post identity database operation failed' === $message ) {
				$logs[] = array( $level, $context );
			}
		};
		add_action( 'datamachine_log', $logger, 10, 3 );

		$repository = new class() extends PostIdentityReservations {
			protected function link_locked_reservation( string $identity_hash, int $post_id ): bool {
				unset( $identity_hash, $post_id );
				return false;
			}
		};
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		$result = $repository->reserve_and_resolve( 'post', array( 'key' => '_source', 'value' => 'rollback' ), 0, 0, $this->shell() );
		remove_action( 'datamachine_log', $logger, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'identity_allocation_failed', $result->get_error_code() );
		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) );
		$identity = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'rollback' ) );
		$this->assertSame( 'reserved', $repository->get_reservation( $identity['identity_hash'] )['state'] );
		$this->assertNotEmpty( $logs );
		$this->assertSame( 'link_reservation', $logs[0][1]['operation'] );
		$this->assertSame( $repository->get_table_name(), $logs[0][1]['table'] );
		$this->assertArrayHasKey( 'error', $logs[0][1] );
		$this->assertStringNotContainsString( 'rollback', wp_json_encode( $logs ) );
		$this->assertStringNotContainsString( $identity['identity_hash'], wp_json_encode( $logs ) );
	}

	public function test_commit_uncertainty_does_not_claim_rollback(): void {
		global $wpdb;

		$repository = new class() extends PostIdentityReservations {
			public bool $rolled_back = false;

			protected function commit_transaction(): bool {
				throw new \RuntimeException( 'Connection result unavailable.' );
			}

			protected function rollback_transaction(): void {
				$this->rolled_back = true;
				parent::rollback_transaction();
			}
		};
		$result = $repository->reserve_and_resolve( 'post', array( 'key' => '_source', 'value' => 'uncertain' ), 0, 0, $this->shell() );

		$this->assertWPError( $result );
		$this->assertSame( 'identity_commit_uncertain', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertFalse( $repository->rolled_back );
		$wpdb->query( 'ROLLBACK' );
	}

	public function test_failure_after_reservation_leaves_retryable_reserved_checkpoint(): void {
		$repository = new class() extends PostIdentityReservations {
			protected function resolve_locked(
				array $identity,
				int $explicit_post_id = 0,
				int $slug_fallback_id = 0,
				array $shell = array()
			) {
				unset( $identity, $explicit_post_id, $slug_fallback_id, $shell );
				return new \WP_Error( 'injected_stop', 'Injected stop after reservation.' );
			}
		};
		$result = $repository->reserve_and_resolve( 'post', array( 'key' => '_source', 'value' => 'reserved-stop' ) );

		$this->assertWPError( $result );
		$identity = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'reserved-stop' ) );
		$row      = $repository->get_reservation( $identity['identity_hash'] );
		$this->assertSame( 'reserved', $row['state'] );
		$this->assertNull( $row['post_id'] );
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
