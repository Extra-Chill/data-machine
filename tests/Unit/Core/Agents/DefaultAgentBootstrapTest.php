<?php
/**
 * Fresh-install agent bootstrap tests.
 *
 * @package DataMachine\Tests\Unit\Core\Agents
 */

namespace DataMachine\Tests\Unit\Core\Agents;

use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Core\Database\Agents\Agents as AgentsRepository;
use DataMachine\Core\FilesRepository\DirectoryManager;
use WP_UnitTestCase;

class DefaultAgentBootstrapTest extends WP_UnitTestCase {

	private AgentsRepository $agents_repo;
	private int $default_user_id;

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();
		datamachine_test_prepare_uploads();

		AgentsRepository::create_table();
		self::factory()->user->create( array( 'role' => 'administrator' ) );
		DirectoryManager::reset_default_agent_user_id_cache();
		$this->agents_repo    = new AgentsRepository();
		$this->default_user_id = DirectoryManager::get_default_agent_user_id();
		$this->clear_agents();
		delete_user_meta( $this->default_user_id, AgentBundler::ACTIVE_AGENT_META_KEY );
		$directory_manager = new DirectoryManager();
		wp_delete_file( trailingslashit( $directory_manager->get_user_directory( $this->default_user_id ) ) . 'USER.md' );
		wp_delete_file( trailingslashit( $directory_manager->get_shared_directory() ) . 'RULES.md' );
	}

	public function tear_down(): void {
		$this->clear_agents();
		delete_user_meta( $this->default_user_id, AgentBundler::ACTIVE_AGENT_META_KEY );
		$directory_manager = new DirectoryManager();
		wp_delete_file( trailingslashit( $directory_manager->get_user_directory( $this->default_user_id ) ) . 'USER.md' );
		wp_delete_file( trailingslashit( $directory_manager->get_shared_directory() ) . 'RULES.md' );
		DirectoryManager::reset_ensure_flag();
		parent::tear_down();
	}

	public function test_default_scaffolding_keeps_a_fresh_install_agentless(): void {
		$directory_manager = new DirectoryManager();
		$user_file         = trailingslashit( $directory_manager->get_user_directory( $this->default_user_id ) ) . 'USER.md';
		$rules_file        = trailingslashit( $directory_manager->get_shared_directory() ) . 'RULES.md';
		wp_delete_file( $user_file );
		wp_delete_file( $rules_file );
		DirectoryManager::reset_ensure_flag();

		$this->assertTrue( datamachine_ensure_default_memory_files() );

		$this->assertSame( array(), $this->agents_repo->get_all() );
		$this->assertFileExists( $user_file );
		$this->assertFileExists( $rules_file );
	}

	public function test_system_context_does_not_provision_an_agent(): void {
		$context = datamachine_resolve_system_agent_context();

		$this->assertSame( 0, $context['agent_id'] );
		$this->assertSame( array(), $this->agents_repo->get_all() );
	}

	public function test_existing_single_agent_remains_the_compatibility_default(): void {
		$agent_id = $this->agents_repo->create_if_missing( 'legacy-agent', 'Legacy Agent', $this->default_user_id, array() );

		$this->assertSame( $agent_id, datamachine_resolve_existing_agent_id( $this->default_user_id ) );
	}

	public function test_explicit_active_agent_resolves_when_owner_has_multiple_agents(): void {
		$this->agents_repo->create_if_missing( 'first-agent', 'First Agent', $this->default_user_id, array() );
		$active_id = $this->agents_repo->create_if_missing( 'active-agent', 'Active Agent', $this->default_user_id, array() );
		update_user_meta( $this->default_user_id, AgentBundler::ACTIVE_AGENT_META_KEY, 'active-agent' );

		$this->assertSame( $active_id, datamachine_resolve_existing_agent_id( $this->default_user_id ) );
	}

	public function test_active_resolution_is_scoped_to_the_owner(): void {
		$other_owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->agents_repo->create_if_missing( 'other-agent', 'Other Agent', $other_owner_id, array() );
		$expected_id = $this->agents_repo->create_if_missing( 'shared-slug', 'Default Agent', $this->default_user_id, array() );
		update_user_meta( $this->default_user_id, AgentBundler::ACTIVE_AGENT_META_KEY, 'shared-slug' );

		$this->assertSame( $expected_id, datamachine_resolve_existing_agent_id( $this->default_user_id ) );
	}

	private function clear_agents(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agents" );
	}
}
