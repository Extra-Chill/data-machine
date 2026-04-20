<?php
/**
 * datamachine/list-agents Scope-Aware Tests
 *
 * Coverage for the refactored listAgents() ability (#996):
 * - Default scope ('mine') returns owned + access-granted agents
 * - scope=all requires admin, returns everything on site
 * - user_id escalation is admin-only
 * - Role enrichment (include_role=true) resolves correctly
 * - Description extracted from agent_config
 * - is_owner flag reflects target user, not caller
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\AgentAbilities;
use DataMachine\Core\Database\Agents\Agents as AgentsRepository;
use DataMachine\Core\Database\Agents\AgentAccess;
use WP_UnitTestCase;

class ListAgentsAbilityTest extends WP_UnitTestCase {

	private AgentsRepository $repo;
	private AgentAccess $access_repo;
	private int $admin_user;
	private int $owner_user;
	private int $granted_user;
	private int $bystander_user;

	public function set_up(): void {
		parent::set_up();

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

	// ---------------------------------------------------------------
	// scope=mine (default)
	// ---------------------------------------------------------------

	public function test_default_scope_returns_owned_agents_for_non_admin(): void {
		$agent_id = $this->repo->create_if_missing( 'mine', 'Mine', $this->owner_user );

		wp_set_current_user( $this->owner_user );
		$result = AgentAbilities::listAgents( array() );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $result['agents'] );
		$this->assertSame( $agent_id, $result['agents'][0]['agent_id'] );
		$this->assertTrue( $result['agents'][0]['is_owner'] );
	}

	public function test_default_scope_returns_granted_agents_for_non_admin(): void {
		$agent_id = $this->repo->create_if_missing( 'shared', 'Shared', $this->owner_user );
		$this->access_repo->grant_access( $agent_id, $this->granted_user, 'operator' );

		wp_set_current_user( $this->granted_user );
		$result = AgentAbilities::listAgents( array() );

		$this->assertCount( 1, $result['agents'] );
		$this->assertSame( $agent_id, $result['agents'][0]['agent_id'] );
		$this->assertFalse( $result['agents'][0]['is_owner'] );
	}

	public function test_default_scope_unions_owned_and_granted_without_dupes(): void {
		$owned_id   = $this->repo->create_if_missing( 'owned', 'Owned', $this->granted_user );
		$granted_id = $this->repo->create_if_missing( 'granted', 'Granted', $this->owner_user );
		$this->access_repo->grant_access( $granted_id, $this->granted_user, 'viewer' );

		// Also grant access on an agent the user owns — must not duplicate the row.
		$this->access_repo->grant_access( $owned_id, $this->granted_user, 'viewer' );

		wp_set_current_user( $this->granted_user );
		$result = AgentAbilities::listAgents( array() );

		$this->assertCount( 2, $result['agents'] );

		$ids = array_map( static fn( $a ) => $a['agent_id'], $result['agents'] );
		$this->assertContains( $owned_id, $ids );
		$this->assertContains( $granted_id, $ids );
	}

	public function test_bystander_gets_empty_list(): void {
		$this->repo->create_if_missing( 'shared', 'Shared', $this->owner_user );

		wp_set_current_user( $this->bystander_user );
		$result = AgentAbilities::listAgents( array() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $result['agents'] );
	}

	public function test_default_scope_filters_by_status(): void {
		$active_id = $this->repo->create_if_missing( 'active-agent', 'Active', $this->owner_user );
		$this->repo->update_agent(
			$this->repo->create_if_missing( 'inactive-agent', 'Inactive', $this->owner_user ),
			array( 'status' => 'inactive' )
		);

		wp_set_current_user( $this->owner_user );
		$result = AgentAbilities::listAgents( array( 'status' => 'active' ) );

		$this->assertCount( 1, $result['agents'] );
		$this->assertSame( $active_id, $result['agents'][0]['agent_id'] );
	}

	public function test_status_any_skips_status_filter(): void {
		$this->repo->create_if_missing( 'active-agent', 'Active', $this->owner_user );
		$this->repo->update_agent(
			$this->repo->create_if_missing( 'inactive-agent', 'Inactive', $this->owner_user ),
			array( 'status' => 'inactive' )
		);

		wp_set_current_user( $this->owner_user );
		$result = AgentAbilities::listAgents( array( 'status' => 'any' ) );

		$this->assertCount( 2, $result['agents'] );
	}

	// ---------------------------------------------------------------
	// scope=all
	// ---------------------------------------------------------------

	public function test_scope_all_denied_for_non_admin(): void {
		$this->repo->create_if_missing( 'shared', 'Shared', $this->owner_user );

		wp_set_current_user( $this->granted_user );
		$result = AgentAbilities::listAgents( array( 'scope' => 'all' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'admin', strtolower( $result['error'] ) );
	}

	public function test_scope_all_returns_all_agents_for_admin(): void {
		$this->repo->create_if_missing( 'one', 'One', $this->owner_user );
		$this->repo->create_if_missing( 'two', 'Two', $this->granted_user );
		$this->repo->create_if_missing( 'three', 'Three', $this->bystander_user );

		wp_set_current_user( $this->admin_user );
		$result = AgentAbilities::listAgents( array( 'scope' => 'all' ) );

		$this->assertTrue( $result['success'] );
		$this->assertGreaterThanOrEqual( 3, count( $result['agents'] ) );
	}

	public function test_admin_default_scope_is_mine_not_all(): void {
		$this->repo->create_if_missing( 'not-mine', 'Not Mine', $this->owner_user );

		// Admin with no owned agents calling with defaults gets THEIR accessible
		// agents (i.e. none), not the firehose. Backward-compat note in #996 body.
		wp_set_current_user( $this->admin_user );
		$result = AgentAbilities::listAgents( array() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $result['agents'] );
	}

	// ---------------------------------------------------------------
	// user_id escalation
	// ---------------------------------------------------------------

	public function test_admin_can_query_other_user_agents(): void {
		$agent_id = $this->repo->create_if_missing( 'target', 'Target', $this->owner_user );

		wp_set_current_user( $this->admin_user );
		$result = AgentAbilities::listAgents( array( 'user_id' => $this->owner_user ) );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $result['agents'] );
		$this->assertSame( $agent_id, $result['agents'][0]['agent_id'] );
		$this->assertTrue( $result['agents'][0]['is_owner'], 'is_owner must reflect target user, not caller' );
	}

	public function test_non_admin_cannot_query_other_user(): void {
		$this->repo->create_if_missing( 'target', 'Target', $this->owner_user );

		wp_set_current_user( $this->granted_user );
		$result = AgentAbilities::listAgents( array( 'user_id' => $this->owner_user ) );

		$this->assertFalse( $result['success'] );
	}

	public function test_non_admin_can_explicitly_query_self(): void {
		$agent_id = $this->repo->create_if_missing( 'mine', 'Mine', $this->owner_user );

		wp_set_current_user( $this->owner_user );
		$result = AgentAbilities::listAgents( array( 'user_id' => $this->owner_user ) );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $result['agents'] );
		$this->assertSame( $agent_id, $result['agents'][0]['agent_id'] );
	}

	// ---------------------------------------------------------------
	// Role enrichment
	// ---------------------------------------------------------------

	public function test_include_role_returns_admin_for_owner(): void {
		$this->repo->create_if_missing( 'mine', 'Mine', $this->owner_user );

		wp_set_current_user( $this->owner_user );
		$result = AgentAbilities::listAgents( array( 'include_role' => true ) );

		$this->assertSame( 'admin', $result['agents'][0]['user_role'] );
	}

	public function test_include_role_returns_granted_role(): void {
		$agent_id = $this->repo->create_if_missing( 'shared', 'Shared', $this->owner_user );
		$this->access_repo->grant_access( $agent_id, $this->granted_user, 'operator' );

		wp_set_current_user( $this->granted_user );
		$result = AgentAbilities::listAgents( array( 'include_role' => true ) );

		$this->assertSame( 'operator', $result['agents'][0]['user_role'] );
	}

	public function test_include_role_reflects_target_user_not_caller(): void {
		$agent_id = $this->repo->create_if_missing( 'shared', 'Shared', $this->owner_user );
		$this->access_repo->grant_access( $agent_id, $this->granted_user, 'viewer' );

		// Admin queries on behalf of granted_user — role must be 'viewer' (granted_user's role),
		// not 'admin' (caller's role).
		wp_set_current_user( $this->admin_user );
		$result = AgentAbilities::listAgents(
			array(
				'user_id'      => $this->granted_user,
				'include_role' => true,
			)
		);

		$this->assertSame( 'viewer', $result['agents'][0]['user_role'] );
	}

	public function test_omitting_include_role_leaves_user_role_absent(): void {
		$this->repo->create_if_missing( 'mine', 'Mine', $this->owner_user );

		wp_set_current_user( $this->owner_user );
		$result = AgentAbilities::listAgents( array() );

		$this->assertArrayNotHasKey( 'user_role', $result['agents'][0] );
	}

	// ---------------------------------------------------------------
	// Description extraction
	// ---------------------------------------------------------------

	public function test_description_extracted_from_agent_config(): void {
		$this->repo->create_if_missing(
			'described',
			'Described',
			$this->owner_user,
			array( 'description' => 'Your AI assistant.' )
		);

		wp_set_current_user( $this->owner_user );
		$result = AgentAbilities::listAgents( array() );

		$this->assertSame( 'Your AI assistant.', $result['agents'][0]['description'] );
	}

	public function test_description_empty_when_config_missing_description(): void {
		$this->repo->create_if_missing( 'plain', 'Plain', $this->owner_user, array() );

		wp_set_current_user( $this->owner_user );
		$result = AgentAbilities::listAgents( array() );

		$this->assertSame( '', $result['agents'][0]['description'] );
	}

	// ---------------------------------------------------------------
	// Input validation
	// ---------------------------------------------------------------

	public function test_invalid_scope_rejected(): void {
		wp_set_current_user( $this->owner_user );
		$result = AgentAbilities::listAgents( array( 'scope' => 'garbage' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'scope', strtolower( $result['error'] ) );
	}
}
