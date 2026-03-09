<?php
/**
 * AgentAbilities Tests
 *
 * Tests for agent CRUD operations via the Abilities API.
 *
 * @package DataMachine\Tests\Unit\Abilities
 * @since 0.40.0
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\AgentAbilities;
use WP_UnitTestCase;

class AgentAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Admin user ID for tests.
	 */
	private int $admin_id;

	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function test_createAgent_success(): void {
		$result = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'test-bot',
				'agent_name' => 'Test Bot',
				'owner_id'   => $this->admin_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'test-bot', $result['agent_slug'] );
		$this->assertSame( 'Test Bot', $result['agent_name'] );
		$this->assertGreaterThan( 0, $result['agent_id'] );
	}

	public function test_createAgent_requires_slug(): void {
		$result = AgentAbilities::createAgent(
			array(
				'agent_slug' => '',
				'owner_id'   => $this->admin_id,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'slug', $result['error'] );
	}

	public function test_createAgent_requires_owner(): void {
		$result = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'orphan-bot',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Owner', $result['error'] );
	}

	public function test_createAgent_rejects_duplicate_slug(): void {
		AgentAbilities::createAgent(
			array(
				'agent_slug' => 'dupe-bot',
				'agent_name' => 'First',
				'owner_id'   => $this->admin_id,
			)
		);

		$result = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'dupe-bot',
				'agent_name' => 'Second',
				'owner_id'   => $this->admin_id,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'already exists', $result['error'] );
	}

	public function test_createAgent_rejects_invalid_owner(): void {
		$result = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'bad-owner-bot',
				'owner_id'   => 999999,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_getAgent_by_slug(): void {
		$created = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'lookup-bot',
				'agent_name' => 'Lookup Bot',
				'owner_id'   => $this->admin_id,
			)
		);

		$result = AgentAbilities::getAgent( array( 'agent_slug' => 'lookup-bot' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'lookup-bot', $result['agent']['agent_slug'] );
		$this->assertSame( 'Lookup Bot', $result['agent']['agent_name'] );
		$this->assertSame( $created['agent_id'], $result['agent']['agent_id'] );
		$this->assertArrayHasKey( 'access', $result['agent'] );
	}

	public function test_getAgent_by_id(): void {
		$created = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'id-lookup-bot',
				'owner_id'   => $this->admin_id,
			)
		);

		$result = AgentAbilities::getAgent( array( 'agent_id' => $created['agent_id'] ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'id-lookup-bot', $result['agent']['agent_slug'] );
	}

	public function test_getAgent_not_found(): void {
		$result = AgentAbilities::getAgent( array( 'agent_slug' => 'nonexistent-bot' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_deleteAgent_success(): void {
		$created = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'delete-me-bot',
				'owner_id'   => $this->admin_id,
			)
		);

		$result = AgentAbilities::deleteAgent( array( 'agent_slug' => 'delete-me-bot' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $created['agent_id'], $result['agent_id'] );

		// Verify it's gone.
		$lookup = AgentAbilities::getAgent( array( 'agent_slug' => 'delete-me-bot' ) );
		$this->assertFalse( $lookup['success'] );
	}

	public function test_deleteAgent_not_found(): void {
		$result = AgentAbilities::deleteAgent( array( 'agent_slug' => 'ghost-bot' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_createAgent_bootstraps_owner_access(): void {
		$created = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'access-bot',
				'owner_id'   => $this->admin_id,
			)
		);

		$access_repo = new \DataMachine\Core\Database\Agents\AgentAccess();
		$has_access  = $access_repo->user_can_access( $created['agent_id'], $this->admin_id, 'admin' );

		$this->assertTrue( $has_access );
	}

	public function test_listAgents_returns_all(): void {
		AgentAbilities::createAgent(
			array(
				'agent_slug' => 'list-test-a',
				'owner_id'   => $this->admin_id,
			)
		);
		AgentAbilities::createAgent(
			array(
				'agent_slug' => 'list-test-b',
				'owner_id'   => $this->admin_id,
			)
		);

		$result = AgentAbilities::listAgents( array() );

		$this->assertTrue( $result['success'] );
		$slugs = array_column( $result['agents'], 'agent_slug' );
		$this->assertContains( 'list-test-a', $slugs );
		$this->assertContains( 'list-test-b', $slugs );
	}

	public function test_createAgent_name_defaults_to_slug(): void {
		$result = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'no-name-bot',
				'owner_id'   => $this->admin_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'no-name-bot', $result['agent_name'] );
	}

	// --- updateAgent tests ---

	public function test_updateAgent_name(): void {
		$created = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'rename-me',
				'agent_name' => 'Old Name',
				'owner_id'   => $this->admin_id,
			)
		);

		$result = AgentAbilities::updateAgent(
			array(
				'agent_id'   => $created['agent_id'],
				'agent_name' => 'New Name',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'New Name', $result['agent']['agent_name'] );
	}

	public function test_updateAgent_status(): void {
		$created = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'status-bot',
				'owner_id'   => $this->admin_id,
			)
		);

		$result = AgentAbilities::updateAgent(
			array(
				'agent_id' => $created['agent_id'],
				'status'   => 'inactive',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'inactive', $result['agent']['status'] );
	}

	public function test_updateAgent_config(): void {
		$created = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'config-bot',
				'owner_id'   => $this->admin_id,
				'config'     => array( 'provider' => 'anthropic' ),
			)
		);

		$result = AgentAbilities::updateAgent(
			array(
				'agent_id'     => $created['agent_id'],
				'agent_config' => array( 'provider' => 'openai', 'model' => 'gpt-4o' ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'openai', $result['agent']['agent_config']['provider'] );
		$this->assertSame( 'gpt-4o', $result['agent']['agent_config']['model'] );
	}

	public function test_updateAgent_multiple_fields(): void {
		$created = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'multi-update-bot',
				'agent_name' => 'Before',
				'owner_id'   => $this->admin_id,
			)
		);

		$result = AgentAbilities::updateAgent(
			array(
				'agent_id'   => $created['agent_id'],
				'agent_name' => 'After',
				'status'     => 'archived',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'After', $result['agent']['agent_name'] );
		$this->assertSame( 'archived', $result['agent']['status'] );
	}

	public function test_updateAgent_requires_agent_id(): void {
		$result = AgentAbilities::updateAgent( array( 'agent_name' => 'Orphan' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'agent_id', $result['error'] );
	}

	public function test_updateAgent_rejects_not_found(): void {
		$result = AgentAbilities::updateAgent(
			array(
				'agent_id'   => 999999,
				'agent_name' => 'Ghost',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_updateAgent_rejects_empty_name(): void {
		$created = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'empty-name-bot',
				'owner_id'   => $this->admin_id,
			)
		);

		$result = AgentAbilities::updateAgent(
			array(
				'agent_id'   => $created['agent_id'],
				'agent_name' => '',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'empty', $result['error'] );
	}

	public function test_updateAgent_rejects_invalid_status(): void {
		$created = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'bad-status-bot',
				'owner_id'   => $this->admin_id,
			)
		);

		$result = AgentAbilities::updateAgent(
			array(
				'agent_id' => $created['agent_id'],
				'status'   => 'deleted',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid status', $result['error'] );
	}

	public function test_updateAgent_rejects_no_fields(): void {
		$created = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'no-fields-bot',
				'owner_id'   => $this->admin_id,
			)
		);

		$result = AgentAbilities::updateAgent(
			array( 'agent_id' => $created['agent_id'] )
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'No fields', $result['error'] );
	}
}
