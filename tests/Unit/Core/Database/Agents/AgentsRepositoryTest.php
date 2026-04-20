<?php
/**
 * Agents Repository Tests
 *
 * Coverage for the multi-agent database primitives added in #993:
 * - get_all_by_owner_id()
 * - get_agents_by_ids()
 * - get_all() with owner_id filter
 *
 * @package DataMachine\Tests\Unit\Core\Database\Agents
 */

namespace DataMachine\Tests\Unit\Core\Database\Agents;

use DataMachine\Core\Database\Agents\Agents as AgentsRepository;
use WP_UnitTestCase;

class AgentsRepositoryTest extends WP_UnitTestCase {

	private AgentsRepository $repo;
	private int $owner_a;
	private int $owner_b;

	public function set_up(): void {
		parent::set_up();

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->repo    = new AgentsRepository();
		$this->owner_a = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->owner_b = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function tear_down(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agents" );

		parent::tear_down();
	}

	private function create_agent( string $slug, int $owner_id, array $config = array() ): int {
		return $this->repo->create_if_missing( $slug, $slug, $owner_id, $config );
	}

	// ---------------------------------------------------------------
	// get_all_by_owner_id()
	// ---------------------------------------------------------------

	public function test_get_all_by_owner_id_returns_only_owned_agents(): void {
		$id_a1 = $this->create_agent( 'a-one', $this->owner_a );
		$id_a2 = $this->create_agent( 'a-two', $this->owner_a );
		$this->create_agent( 'b-one', $this->owner_b );

		$result = $this->repo->get_all_by_owner_id( $this->owner_a );

		$this->assertCount( 2, $result );

		$ids = array_map( static fn( $a ) => (int) $a['agent_id'], $result );
		$this->assertContains( $id_a1, $ids );
		$this->assertContains( $id_a2, $ids );
	}

	public function test_get_all_by_owner_id_returns_empty_for_unknown_user(): void {
		$this->create_agent( 'a-one', $this->owner_a );

		$this->assertSame( array(), $this->repo->get_all_by_owner_id( 99999 ) );
	}

	public function test_get_all_by_owner_id_returns_empty_for_invalid_id(): void {
		$this->create_agent( 'a-one', $this->owner_a );

		$this->assertSame( array(), $this->repo->get_all_by_owner_id( 0 ) );
		$this->assertSame( array(), $this->repo->get_all_by_owner_id( -1 ) );
	}

	public function test_get_all_by_owner_id_decodes_agent_config(): void {
		$this->create_agent( 'a-one', $this->owner_a, array( 'foo' => 'bar' ) );

		$result = $this->repo->get_all_by_owner_id( $this->owner_a );

		$this->assertIsArray( $result[0]['agent_config'] );
		$this->assertSame( 'bar', $result[0]['agent_config']['foo'] );
	}

	public function test_get_all_by_owner_id_orders_by_agent_id_ascending(): void {
		$id_first  = $this->create_agent( 'a-one', $this->owner_a );
		$id_second = $this->create_agent( 'a-two', $this->owner_a );

		$result = $this->repo->get_all_by_owner_id( $this->owner_a );

		$this->assertSame( $id_first, (int) $result[0]['agent_id'] );
		$this->assertSame( $id_second, (int) $result[1]['agent_id'] );
	}

	// ---------------------------------------------------------------
	// get_agents_by_ids()
	// ---------------------------------------------------------------

	public function test_get_agents_by_ids_batches_a_single_query(): void {
		$id_a = $this->create_agent( 'a-one', $this->owner_a );
		$id_b = $this->create_agent( 'b-one', $this->owner_b );

		$result = $this->repo->get_agents_by_ids( array( $id_a, $id_b ) );

		$this->assertCount( 2, $result );

		$ids = array_map( static fn( $a ) => (int) $a['agent_id'], $result );
		$this->assertContains( $id_a, $ids );
		$this->assertContains( $id_b, $ids );
	}

	public function test_get_agents_by_ids_returns_empty_for_empty_input(): void {
		$this->create_agent( 'a-one', $this->owner_a );

		$this->assertSame( array(), $this->repo->get_agents_by_ids( array() ) );
	}

	public function test_get_agents_by_ids_silently_drops_missing_ids(): void {
		$id_a = $this->create_agent( 'a-one', $this->owner_a );

		$result = $this->repo->get_agents_by_ids( array( $id_a, 99999 ) );

		$this->assertCount( 1, $result );
		$this->assertSame( $id_a, (int) $result[0]['agent_id'] );
	}

	public function test_get_agents_by_ids_dedupes_input(): void {
		$id_a = $this->create_agent( 'a-one', $this->owner_a );

		$result = $this->repo->get_agents_by_ids( array( $id_a, $id_a, $id_a ) );

		$this->assertCount( 1, $result );
	}

	public function test_get_agents_by_ids_drops_non_positive_ids(): void {
		$id_a = $this->create_agent( 'a-one', $this->owner_a );

		// Should not crash on 0, negatives, or strings — all sanitized away.
		$result = $this->repo->get_agents_by_ids( array( $id_a, 0, -5, 'not-an-id' ) );

		$this->assertCount( 1, $result );
		$this->assertSame( $id_a, (int) $result[0]['agent_id'] );
	}

	public function test_get_agents_by_ids_decodes_agent_config(): void {
		$id_a = $this->create_agent( 'a-one', $this->owner_a, array( 'tools' => array( 'x', 'y' ) ) );

		$result = $this->repo->get_agents_by_ids( array( $id_a ) );

		$this->assertIsArray( $result[0]['agent_config'] );
		$this->assertSame( array( 'x', 'y' ), $result[0]['agent_config']['tools'] );
	}

	// ---------------------------------------------------------------
	// get_all() with owner_id filter
	// ---------------------------------------------------------------

	public function test_get_all_filters_by_owner_id(): void {
		$id_a1 = $this->create_agent( 'a-one', $this->owner_a );
		$this->create_agent( 'a-two', $this->owner_a );
		$this->create_agent( 'b-one', $this->owner_b );

		$result = $this->repo->get_all( array( 'owner_id' => $this->owner_a ) );

		$this->assertCount( 2, $result );

		foreach ( $result as $row ) {
			$this->assertSame( $this->owner_a, (int) $row['owner_id'] );
		}

		$ids = array_map( static fn( $a ) => (int) $a['agent_id'], $result );
		$this->assertContains( $id_a1, $ids );
	}

	public function test_get_all_with_no_args_returns_everything(): void {
		$this->create_agent( 'a-one', $this->owner_a );
		$this->create_agent( 'b-one', $this->owner_b );

		$result = $this->repo->get_all();

		$this->assertGreaterThanOrEqual( 2, count( $result ) );
	}
}
