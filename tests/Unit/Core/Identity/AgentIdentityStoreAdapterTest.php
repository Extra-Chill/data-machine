<?php
/**
 * Materialized agent identity scope regression tests.
 *
 * @package DataMachine\Tests\Unit\Core\Identity
 */

namespace DataMachine\Tests\Unit\Core\Identity;

use AgentsAPI\Core\Identity\WP_Agent_Identity_Scope;
use AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity;
use DataMachine\Core\Database\Agents\AgentAccess;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\Identity\AgentIdentityStoreAdapter;
use DataMachine\Engine\AI\MemoryFileRegistry;
use DataMachine\Tests\Fixtures\CountingProvisionAdapter;
use DataMachine\Tests\Fixtures\DuplicateLoserAgents;
use DataMachine\Tests\Fixtures\FailingProvisionAdapter;
use WP_UnitTestCase;

class AgentIdentityStoreAdapterTest extends WP_UnitTestCase {

	private Agents $repository;
	private AgentIdentityStoreAdapter $store;
	private int $owner_a;
	private int $owner_b;
	private array $registered = array();

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agent_access" );
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agents" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$this->repository = new Agents();
		$this->store      = new AgentIdentityStoreAdapter( $this->repository );
		$this->owner_a    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->owner_b    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->owner_a );
	}

	public function tear_down(): void {
		global $wpdb;
		foreach ( $this->registered as $slug ) {
			\WP_Agents_Registry::get_instance()->unregister( $slug );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agent_access" );
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agents" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		parent::tear_down();
	}

	public function test_same_slug_with_different_owners_materializes_distinct_identities(): void {
		$this->register_agent( 'owner-scoped-agent' );
		$first  = $this->store->materialize( new WP_Agent_Identity_Scope( 'owner-scoped-agent', $this->owner_a ) );
		$second = $this->store->materialize( new WP_Agent_Identity_Scope( 'owner-scoped-agent', $this->owner_b ) );

		$this->assertNotSame( $first->id, $second->id );
		$this->assertSame( $this->owner_a, $this->store->get( $first->id )->scope->owner_user_id );
		$this->assertSame( $this->owner_b, $this->store->get( $second->id )->scope->owner_user_id );
	}

	public function test_same_slug_and_owner_with_different_instance_keys_do_not_collide(): void {
		$this->register_agent( 'instance-scoped-agent' );
		$first  = $this->store->materialize( new WP_Agent_Identity_Scope( 'instance-scoped-agent', $this->owner_a, 'workspace:first' ) );
		$second = $this->store->materialize( new WP_Agent_Identity_Scope( 'instance-scoped-agent', $this->owner_a, 'workspace:second' ) );

		$this->assertNotSame( $first->id, $second->id );
		$this->assertSame( 'workspace:first', $this->store->get( $first->id )->scope->instance_key );
		$this->assertSame( 'workspace:second', $this->store->get( $second->id )->scope->instance_key );
		$directories = new DirectoryManager();
		$this->assertNotSame(
			$directories->resolve_agent_directory( array( 'agent_id' => $first->id ) ),
			$directories->resolve_agent_directory( array( 'agent_id' => $second->id ) )
		);
	}

	public function test_long_instance_keys_with_shared_prefix_round_trip_without_collision(): void {
		$this->register_agent( 'long-instance-agent' );
		$prefix = str_repeat( 'shared-prefix-', 30 );
		$key_a  = $prefix . 'first';
		$key_b  = $prefix . 'second';

		$first  = $this->store->materialize( new WP_Agent_Identity_Scope( 'long-instance-agent', $this->owner_a, $key_a ) );
		$second = $this->store->materialize( new WP_Agent_Identity_Scope( 'long-instance-agent', $this->owner_a, $key_b ) );

		$this->assertGreaterThan( 200, strlen( $key_a ) );
		$this->assertNotSame( $first->id, $second->id );
		$this->assertSame( $key_a, $this->store->get( $first->id )->scope->instance_key );
		$this->assertSame( $key_b, $this->store->get( $second->id )->scope->instance_key );
	}

	public function test_legacy_default_row_resolves_with_preserved_access_and_directory(): void {
		$agent_id = $this->repository->create_if_missing( 'legacy-default-agent', 'Legacy Default Agent', $this->owner_a );
		( new AgentAccess() )->bootstrap_owner_access( $agent_id, $this->owner_a );

		$identity = $this->store->resolve( new WP_Agent_Identity_Scope( 'legacy-default-agent', $this->owner_a ) );

		$this->assertNotNull( $identity );
		$this->assertSame( 'default', $identity->scope->instance_key );
		$this->assertTrue( ( new AgentAccess() )->user_can_access( $agent_id, $this->owner_a, 'admin' ) );
		$this->assertStringEndsWith( '/agents/legacy-default-agent', ( new DirectoryManager() )->resolve_agent_directory( array( 'agent_id' => $agent_id ) ) );
	}

	public function test_mismatched_stale_scope_fails_closed_on_resolve_and_update(): void {
		$agent_id = $this->repository->create_identity_if_missing( 'stale-scope-agent', 'Stale Scope Agent', $this->owner_a, 'stored', array( 'value' => 'stored' ) )['agent_id'];
		$this->assertNull( $this->store->resolve( new WP_Agent_Identity_Scope( 'stale-scope-agent', $this->owner_a, 'requested' ) ) );

		$stale = new WP_Agent_Materialized_Identity(
			$agent_id,
			new WP_Agent_Identity_Scope( 'stale-scope-agent', $this->owner_a, 'requested' ),
			array( 'value' => 'changed' )
		);
		try {
			$this->store->update( $stale );
			$this->fail( 'A contradictory scope must not update persisted identity state.' );
		} catch ( \UnexpectedValueException $exception ) {
			$this->assertStringContainsString( 'does not match requested scope', $exception->getMessage() );
		}
		$this->assertSame( 'stored', $this->repository->get_agent( $agent_id )['agent_config']['value'] );
	}

	public function test_failed_provisioning_remains_incomplete_and_retryable(): void {
		$this->register_agent( 'retry-provision-agent' );
		$scope   = new WP_Agent_Identity_Scope( 'retry-provision-agent', $this->owner_a, 'recoverable' );
		$failing = new FailingProvisionAdapter( $this->repository );

		try {
			$failing->materialize( $scope );
			$this->fail( 'Injected provisioning failure must propagate.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'Injected provisioning failure.', $exception->getMessage() );
		}
		$this->assertTrue( $failing->failed );
		$this->assertNull( $this->store->resolve( $scope ) );
		$row = $this->repository->get_by_identity_scope( 'retry-provision-agent', $this->owner_a, 'recoverable' );
		$this->assertSame( '', (string) $row['provisioning_token'] );
		$this->assertEmpty( $row['provisioned_at'] );

		$identity = $this->store->materialize( $scope );
		$this->assertSame( $identity->id, $this->store->resolve( $scope )->id );
		$this->assertNotEmpty( $this->repository->get_agent( $identity->id )['provisioned_at'] );
	}

	public function test_native_scaffold_error_does_not_complete_provisioning(): void {
		$this->register_agent( 'scaffold-error-agent' );
		$scope    = new WP_Agent_Identity_Scope( 'scaffold-error-agent', $this->owner_a, 'scaffold-error' );
		$filename = 'SCAFFOLD-FAILURE.md';
		MemoryFileRegistry::register( $filename, 999, array( 'layer' => MemoryFileRegistry::LAYER_AGENT ) );

		try {
			$this->store->materialize( $scope );
			$this->fail( 'A native scaffold WP_Error must abort provisioning.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'Failed to scaffold', $exception->getMessage() );
		} finally {
			MemoryFileRegistry::deregister( $filename );
		}

		$row = $this->repository->get_by_identity_scope( 'scaffold-error-agent', $this->owner_a, 'scaffold-error' );
		$this->assertSame( '', (string) $row['provisioning_token'] );
		$this->assertEmpty( $row['provisioned_at'] );
		$this->assertNull( $this->store->resolve( $scope ) );
	}

	public function test_duplicate_key_loser_reconciles_pending_winner(): void {
		$this->register_agent( 'concurrent-agent', array( 'meta' => array( 'datamachine_default_materialization' => false ) ) );
		$scope      = new WP_Agent_Identity_Scope( 'concurrent-agent', $this->owner_a, 'shared-instance' );
		$repository = new DuplicateLoserAgents();
		$adapter    = new CountingProvisionAdapter( $repository );

		$identity = $adapter->materialize( $scope );

		$this->assertTrue( $repository->duplicate_loser_exercised );
		$this->assertSame( 1, $adapter->provision_count );
		$this->assertSame( $identity->id, $this->store->resolve( $scope )->id );
		$this->assertCount( 1, array_filter( $this->repository->get_all(), static fn( array $row ): bool => 'concurrent-agent' === $row['agent_slug'] ) );
	}

	public function test_schema_migration_preserves_legacy_rows_and_replaces_slug_unique_key(): void {
		global $wpdb;
		$table = $wpdb->base_prefix . Agents::TABLE_NAME;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, 'agent_identity_scope_hash' ) );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN instance_key', $table ) );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN instance_key_hash', $table ) );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN provisioning_token', $table ) );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN provisioning_started_at', $table ) );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN provisioned_at', $table ) );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY %i (agent_slug)', $table, 'agent_slug' ) );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (owner_id)', $table, 'agent_identity_scope_hash' ) );
		$wpdb->insert(
			$table,
			array( 'agent_slug' => 'pre-migration-agent', 'agent_name' => 'Pre Migration Agent', 'owner_id' => $this->owner_a, 'agent_config' => '{}' ),
			array( '%s', '%s', '%d', '%s' )
		);
		$agent_id = (int) $wpdb->insert_id;
		( new AgentAccess() )->bootstrap_owner_access( $agent_id, $this->owner_a );

		try {
			Agents::ensure_identity_scope_schema();
			$this->fail( 'A conflicting replacement index must fail the migration.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'create agent identity scope index', $exception->getMessage() );
		}
		$failed_indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		$this->assertNotEmpty( array_filter( $failed_indexes, static fn( array $index ): bool => 'agent_slug' === $index['Key_name'] && 0 === (int) $index['Non_unique'] ) );

		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, 'agent_identity_scope_hash' ) );
		Agents::ensure_identity_scope_schema();
		Agents::ensure_identity_scope_schema();
		$row     = ( new Agents() )->get_agent( $agent_id );
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertSame( 'default', $row['instance_key'] );
		$this->assertSame( hash( 'sha256', 'default' ), $row['instance_key_hash'] );
		$this->assertNotEmpty( $row['provisioned_at'] );
		$this->assertTrue( ( new AgentAccess() )->user_can_access( $agent_id, $this->owner_a, 'admin' ) );
		$this->assertNotEmpty( array_filter( $indexes, static fn( array $index ): bool => 'agent_identity_scope_hash' === $index['Key_name'] ) );
		$this->assertEmpty( array_filter( $indexes, static fn( array $index ): bool => 'agent_slug' === $index['Key_name'] && 0 === (int) $index['Non_unique'] ) );
	}

	public function test_schema_reconciliation_is_idempotent_with_existing_identity_index(): void {
		global $wpdb;
		$table = $wpdb->base_prefix . Agents::TABLE_NAME;

		Agents::ensure_identity_scope_schema();
		Agents::ensure_identity_scope_schema();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		$identity_index = array_filter(
			$indexes,
			static fn( array $index ): bool => 'agent_identity_scope_hash' === $index['Key_name']
		);

		$this->assertCount( 3, $identity_index );
		$this->assertSame( array( 'agent_slug', 'owner_id', 'instance_key_hash' ), array_column( $identity_index, 'Column_name' ) );
	}

	private function register_agent( string $slug, array $args = array() ): void {
		\WP_Agents_Registry::get_instance()->register( $slug, array_merge( array( 'label' => $slug ), $args ) );
		$this->registered[] = $slug;
	}
}
