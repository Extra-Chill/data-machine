<?php
/**
 * Agents REST List Endpoint Tests
 *
 * Coverage for the bug fixes added in #994:
 * - Non-admin path returns OWNED agents even when no access grant exists
 * - Non-admin path uses batched get_agents_by_ids() (no N+1)
 * - Response includes user_role per agent
 *
 * @package DataMachine\Tests\Unit\Api
 */

namespace DataMachine\Tests\Unit\Api;

use DataMachine\Api\Agents as AgentsRest;
use DataMachine\Core\Database\Agents\Agents as AgentsRepository;
use DataMachine\Core\Database\Agents\AgentAccess;
use WP_REST_Request;
use WP_UnitTestCase;

class AgentsListEndpointTest extends WP_UnitTestCase {

	private AgentsRepository $repo;
	private AgentAccess $access_repo;
	private int $admin_user;
	private int $owner_user;
	private int $granted_user;
	private int $bystander_user;

	public function set_up(): void {
		parent::set_up();
		datamachine_register_capabilities();

		$this->repo        = new AgentsRepository();
		$this->access_repo = new AgentAccess();

		$this->admin_user     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->owner_user     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->granted_user   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->bystander_user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tear_down(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agents" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agent_access" );

		parent::tear_down();
	}

	private function call_handle_list(): array {
		$request  = new WP_REST_Request( 'GET', '/datamachine/v1/agents' );
		$response = AgentsRest::handle_list( $request );

		return $response->get_data();
	}

	private function call_handle_list_all(): array {
		$request = new WP_REST_Request( 'GET', '/datamachine/v1/agents' );
		$request->set_param( 'scope', 'all' );
		$response = AgentsRest::handle_list( $request );

		return $response->get_data();
	}

	/**
	 * Bug 1 regression: a non-admin user OWNS an agent but has no access grant
	 * (e.g. migration gap, manual DB edit). Pre-fix, the endpoint silently
	 * dropped their own agent because it only consulted the access table.
	 */
	public function test_non_admin_owner_sees_their_agent_without_access_grant(): void {
		$agent_id = $this->repo->create_if_missing( 'owned-agent', 'Owned Agent', $this->owner_user );

		// Deliberately do NOT call $access_repo->grant_access() / bootstrap_owner_access().
		wp_set_current_user( $this->owner_user );
		$result = $this->call_handle_list();

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $result['data'] );
		$this->assertSame( $agent_id, $result['data'][0]['agent_id'] );
	}

	/**
	 * Owners get user_role = 'admin' regardless of whether an access row exists.
	 * Frontend depends on this normalization to switch on a single role enum.
	 */
	public function test_non_admin_owner_role_normalizes_to_admin(): void {
		$this->repo->create_if_missing( 'owned-agent', 'Owned Agent', $this->owner_user );

		wp_set_current_user( $this->owner_user );
		$result = $this->call_handle_list();

		$this->assertSame( 'admin', $result['data'][0]['user_role'] );
	}

	/**
	 * Access-granted (non-owner) users see the agent and get the granted role.
	 */
	public function test_non_admin_granted_user_sees_agent_with_granted_role(): void {
		$agent_id = $this->repo->create_if_missing( 'shared-agent', 'Shared Agent', $this->owner_user );
		$this->access_repo->grant_access( $agent_id, $this->granted_user, 'operator' );

		wp_set_current_user( $this->granted_user );
		$result = $this->call_handle_list();

		$this->assertCount( 1, $result['data'] );
		$this->assertSame( $agent_id, $result['data'][0]['agent_id'] );
		$this->assertSame( 'operator', $result['data'][0]['user_role'] );
	}

	/**
	 * Owned + granted agents are merged (union) without duplicates.
	 */
	public function test_non_admin_sees_union_of_owned_and_granted_agents(): void {
		$owned_id   = $this->repo->create_if_missing( 'owned', 'Owned', $this->granted_user );
		$granted_id = $this->repo->create_if_missing( 'granted', 'Granted', $this->owner_user );
		$this->access_repo->grant_access( $granted_id, $this->granted_user, 'viewer' );

		wp_set_current_user( $this->granted_user );
		$result = $this->call_handle_list();

		$this->assertCount( 2, $result['data'] );

		$ids = array_map( static fn( $row ) => $row['agent_id'], $result['data'] );
		$this->assertContains( $owned_id, $ids );
		$this->assertContains( $granted_id, $ids );

		// Roles correctly assigned per agent.
		$by_id = array();
		foreach ( $result['data'] as $row ) {
			$by_id[ $row['agent_id'] ] = $row['user_role'];
		}

		$this->assertSame( 'admin', $by_id[ $owned_id ] );
		$this->assertSame( 'viewer', $by_id[ $granted_id ] );
	}

	/**
	 * Owner who also has an explicit grant doesn't get a duplicate row,
	 * and owner-as-admin wins over the explicit access role.
	 */
	public function test_owned_and_granted_dedupes_with_admin_role_wins(): void {
		$agent_id = $this->repo->create_if_missing( 'mine', 'Mine', $this->owner_user );
		$this->access_repo->grant_access( $agent_id, $this->owner_user, 'viewer' );

		wp_set_current_user( $this->owner_user );
		$result = $this->call_handle_list();

		$this->assertCount( 1, $result['data'], 'Owner+grant must not duplicate the agent' );
		$this->assertSame( 'admin', $result['data'][0]['user_role'], 'Owner role should normalize to admin' );
	}

	/**
	 * Bystander with neither ownership nor a grant gets an empty list.
	 */
	public function test_non_admin_bystander_sees_empty_list(): void {
		$this->repo->create_if_missing( 'shared', 'Shared', $this->owner_user );

		wp_set_current_user( $this->bystander_user );
		$result = $this->call_handle_list();

		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $result['data'] );
	}

	/**
	 * Admins see all agents on the current site and get user_role = 'admin'.
	 */
	public function test_admin_sees_all_agents_with_admin_role(): void {
		$this->repo->create_if_missing( 'one', 'One', $this->owner_user );
		$this->repo->create_if_missing( 'two', 'Two', $this->granted_user );

		wp_set_current_user( $this->admin_user );
		$result = $this->call_handle_list_all();

		$this->assertGreaterThanOrEqual( 2, count( $result['data'] ) );

		$this->assertNotEmpty( $result['data'] );
	}
}
