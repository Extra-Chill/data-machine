<?php
/**
 * Agent prune resurrection regression tests.
 *
 * Covers the fixes for Extra-Chill/data-machine#2866:
 *   1. Stale active-agent user meta is cleared when the agent no longer exists.
 *   2. pruneAgents clears each pruned owner's active-agent meta.
 *   3. AgentIdentityStoreAdapter::materialize refuses unregistered scopes.
 *   4. AgentAccess rejects grants for agent_id <= 0.
 *
 * @package DataMachine\Tests\Unit\Abilities
 * @since   0.161.1
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\AgentAbilities;
use DataMachine\Core\Database\Agents\AgentAccess;
use DataMachine\Core\Identity\AgentIdentityStoreAdapter;
use WP_UnitTestCase;

class AgentPruneResurrectionTest extends WP_UnitTestCase {

	private const ACTIVE_AGENT_META_KEY = 'datamachine_active_agent_slug';

	/**
	 * Admin user ID for tests.
	 */
	private int $admin_id;

	/**
	 * Second user ID used as a stray owner.
	 */
	private int $owner_id;

	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down(): void {
		// Clean up any user meta we touched so later tests do not see stale state.
		delete_user_meta( $this->owner_id, self::ACTIVE_AGENT_META_KEY );
		delete_user_meta( $this->admin_id, self::ACTIVE_AGENT_META_KEY );

		// The Agents API registry is in-memory; reset it between tests to avoid
		// state bleed from registrations made inside test cases.
		if ( class_exists( 'WP_Agents_Registry' ) && method_exists( 'WP_Agents_Registry', 'reset_for_tests' ) ) {
			\WP_Agents_Registry::reset_for_tests();
		}

		parent::tear_down();
	}

	public function test_getActiveAgent_clears_stale_meta_and_does_not_resurrect(): void {
		$created = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'stale-meta-bot',
				'owner_id'   => $this->owner_id,
			)
		);
		$this->assertTrue( $created['success'] );

		// Owner selects the agent as their active preference.
		AgentAbilities::setActiveAgent(
			array(
				'user_id' => $this->owner_id,
				'agent'   => $created['agent_slug'],
			)
		);
		$this->assertSame( 'stale-meta-bot', get_user_meta( $this->owner_id, self::ACTIVE_AGENT_META_KEY, true ) );

		// Simulate prune by deleting the agent row directly.
		$deleted = AgentAbilities::deleteAgent( array( 'agent_id' => $created['agent_id'] ) );
		$this->assertTrue( $deleted['success'] );

		// Reading the active agent should now clear the stale meta and report none.
		$result = AgentAbilities::getActiveAgent( array( 'user_id' => $this->owner_id ) );
		$this->assertTrue( $result['success'] );
		$this->assertNull( $result['agent'] );
		$this->assertSame( 'invalid_preference', $result['source'] );
		$this->assertSame( '', get_user_meta( $this->owner_id, self::ACTIVE_AGENT_META_KEY, true ) );

		// The agent row must stay deleted.
		$lookup = AgentAbilities::getAgent( array( 'agent_slug' => 'stale-meta-bot' ) );
		$this->assertFalse( $lookup['success'] );
	}

	public function test_pruneAgents_clears_owner_active_agent_meta(): void {
		$created = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'prune-me-bot',
				'owner_id'   => $this->owner_id,
			)
		);
		$this->assertTrue( $created['success'] );

		AgentAbilities::setActiveAgent(
			array(
				'user_id' => $this->owner_id,
				'agent'   => $created['agent_slug'],
			)
		);

		// No references => prune candidate.
		$pruned = AgentAbilities::pruneAgents( array( 'dry_run' => false ) );
		$this->assertTrue( $pruned['success'] );

		$deleted_ids = array_column( $pruned['deleted'], 'agent_id' );
		$this->assertContains( $created['agent_id'], $deleted_ids );

		// The owner's active-agent pointer must have been deleted network-wide.
		$this->assertSame( '', get_user_meta( $this->owner_id, self::ACTIVE_AGENT_META_KEY, true ) );
	}

	public function test_materialize_throws_for_unregistered_stale_scope(): void {
		$store = new AgentIdentityStoreAdapter();
		$scope = new \AgentsAPI\Core\Identity\WP_Agent_Identity_Scope( 'pruned-stale-bot', $this->owner_id );

		$this->expectException( \InvalidArgumentException::class );
		$store->materialize( $scope, array( 'model' => array( 'default' => 'openai/gpt-4o' ) ) );
	}

	public function test_materialize_creates_registered_definition(): void {
		$scope = new \AgentsAPI\Core\Identity\WP_Agent_Identity_Scope( 'registered-bot', $this->owner_id );

		$registry = \WP_Agents_Registry::get_instance();
		$registry->register(
			'registered-bot',
			array(
				'label'          => 'Registered Bot',
				'owner_resolver' => function () {
					return $this->owner_id;
				},
			)
		);

		try {
			$store    = new AgentIdentityStoreAdapter();
			$identity = $store->materialize( $scope );

			$this->assertGreaterThan( 0, $identity->id );
			$this->assertSame( 'registered-bot', $identity->scope->normalize()->agent_slug );

			$access_repo = new AgentAccess();
			$this->assertTrue( $access_repo->user_can_access( $identity->id, $this->owner_id, 'admin' ) );
		} finally {
			$registry->unregister( 'registered-bot' );
		}
	}

	public function test_grant_access_rejects_agent_id_zero(): void {
		$access_repo = new AgentAccess();
		$grant       = new \WP_Agent_Access_Grant( '0', $this->owner_id, \WP_Agent_Access_Grant::ROLE_ADMIN );

		$this->expectException( \InvalidArgumentException::class );
		$access_repo->grant_access( $grant );
	}

	public function test_bootstrap_owner_access_rejects_agent_id_zero(): void {
		$access_repo = new AgentAccess();
		$this->assertFalse( $access_repo->bootstrap_owner_access( 0, $this->owner_id ) );
		$this->assertFalse( $access_repo->bootstrap_owner_access( -1, $this->owner_id ) );
	}
}
