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
use WP_UnitTestCase;

class AgentIdentityStoreAdapterTest extends WP_UnitTestCase {

	private Agents $repository;
	private AgentIdentityStoreAdapter $store;
	private int $owner_a;
	private int $owner_b;
	private array $registered = array();

	public function set_up(): void {
		parent::set_up();
		Agents::ensure_identity_scope_schema();
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

	public function test_concurrent_materialization_retries_are_idempotent(): void {
		$this->register_agent( 'concurrent-agent' );
		$scope = new WP_Agent_Identity_Scope( 'concurrent-agent', $this->owner_a, 'shared-instance' );

		$first  = $this->store->materialize( $scope );
		$second = ( new AgentIdentityStoreAdapter( new Agents() ) )->materialize( $scope );

		$this->assertSame( $first->id, $second->id );
		$this->assertCount( 1, array_filter( $this->repository->get_all(), static fn( array $row ): bool => 'concurrent-agent' === $row['agent_slug'] ) );
	}

	public function test_schema_migration_preserves_legacy_rows_and_replaces_slug_unique_key(): void {
		global $wpdb;
		$table = $wpdb->base_prefix . Agents::TABLE_NAME;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, 'agent_identity_scope' ) );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN instance_key', $table ) );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY %i (agent_slug)', $table, 'agent_slug' ) );
		$wpdb->insert(
			$table,
			array( 'agent_slug' => 'pre-migration-agent', 'agent_name' => 'Pre Migration Agent', 'owner_id' => $this->owner_a, 'agent_config' => '{}' ),
			array( '%s', '%s', '%d', '%s' )
		);
		$agent_id = (int) $wpdb->insert_id;
		( new AgentAccess() )->bootstrap_owner_access( $agent_id, $this->owner_a );

		Agents::ensure_identity_scope_schema();
		$row     = ( new Agents() )->get_agent( $agent_id );
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertSame( 'default', $row['instance_key'] );
		$this->assertTrue( ( new AgentAccess() )->user_can_access( $agent_id, $this->owner_a, 'admin' ) );
		$this->assertNotEmpty( array_filter( $indexes, static fn( array $index ): bool => 'agent_identity_scope' === $index['Key_name'] ) );
		$this->assertEmpty( array_filter( $indexes, static fn( array $index ): bool => 'agent_slug' === $index['Key_name'] && 0 === (int) $index['Non_unique'] ) );
	}

	private function register_agent( string $slug ): void {
		\WP_Agents_Registry::get_instance()->register( $slug, array( 'label' => $slug ) );
		$this->registered[] = $slug;
	}
}
