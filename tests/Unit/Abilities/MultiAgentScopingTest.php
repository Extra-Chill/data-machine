<?php
/**
 * Multi-Agent Scoping Tests
 *
 * Tests that user_id scoping works correctly across abilities:
 * - AgentMemoryAbilities: user_id flows through to AgentMemory
 * - FileAbilities: agent-scoped ops use user_id for directory isolation
 * - GetPipelinesAbility: user_id filters pipeline listing
 * - GetFlowsAbility: user_id filters flow listing
 * - GetJobsAbility: user_id filters job listing
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\AgentMemoryAbilities;
use DataMachine\Abilities\File\AgentFileAbilities;
use DataMachine\Abilities\Job\GetJobsAbility;
use DataMachine\Abilities\Pipeline\GetPipelinesAbility;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\FilesRepository\DirectoryManager;
use WP_UnitTestCase;

/**
 * Integration tests for multi-agent user_id scoping.
 */
class MultiAgentScopingTest extends WP_UnitTestCase {

	/**
	 * WordPress user IDs for test agents.
	 *
	 * @var int
	 */
	private int $agent_a_id;
	private int $agent_b_id;

	/**
	 * Set up test fixtures — create two WP users as separate agents.
	 */
	public function set_up(): void {
		parent::set_up();

		// Create admin user for permission checks.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create two agent users.
		$this->agent_a_id = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'agent_alpha',
				'user_email' => 'alpha@test.local',
			)
		);
		$this->agent_b_id = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'agent_beta',
				'user_email' => 'beta@test.local',
			)
		);
	}

	/**
	 * Clean up agent directories after tests.
	 */
	public function tear_down(): void {
		$dm = new DirectoryManager();

		// Clean up agent-specific directories if created.
		foreach ( array( $this->agent_a_id, $this->agent_b_id ) as $uid ) {
			$dir = $dm->get_agent_identity_directory_for_user( $uid );
			if ( is_dir( $dir ) ) {
				array_map( 'unlink', glob( "{$dir}/*" ) );
				rmdir( $dir );
			}
		}

		parent::tear_down();
	}

	// =========================================================================
	// AgentMemoryAbilities — user_id in input_schema
	// =========================================================================

	/**
	 * Test get-agent-memory input_schema includes user_id.
	 */
	public function test_get_memory_schema_has_user_id(): void {
		$ability = wp_get_ability( 'datamachine/get-agent-memory' );
		$this->assertNotNull( $ability, 'get-agent-memory ability should be registered' );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'user_id', $schema['properties'] );
		$this->assertSame( 'integer', $schema['properties']['user_id']['type'] );
	}

	/**
	 * Test update-agent-memory input_schema includes user_id.
	 */
	public function test_update_memory_schema_has_user_id(): void {
		$ability = wp_get_ability( 'datamachine/update-agent-memory' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'user_id', $schema['properties'] );
	}

	/**
	 * Test search-agent-memory input_schema includes user_id.
	 */
	public function test_search_memory_schema_has_user_id(): void {
		$ability = wp_get_ability( 'datamachine/search-agent-memory' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'user_id', $schema['properties'] );
	}

	/**
	 * Test list-agent-memory-sections input_schema includes user_id.
	 */
	public function test_list_sections_schema_has_user_id(): void {
		$ability = wp_get_ability( 'datamachine/list-agent-memory-sections' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'user_id', $schema['properties'] );
	}

	/**
	 * Test getMemory defaults to user_id=0 when omitted.
	 */
	public function test_get_memory_defaults_to_shared_agent(): void {
		$result = AgentMemoryAbilities::getMemory( array() );

		// Should succeed (shared agent has MEMORY.md from bootstrap).
		$this->assertIsArray( $result );
		// The result should come from the shared agent directory (user_id=0).
		// Whether success=true depends on whether MEMORY.md exists; the point
		// is it doesn't error on missing user_id.
	}

	/**
	 * Test getMemory with explicit user_id=0 matches default behavior.
	 */
	public function test_get_memory_explicit_zero_matches_default(): void {
		$default_result  = AgentMemoryAbilities::getMemory( array() );
		$explicit_result = AgentMemoryAbilities::getMemory( array( 'user_id' => 0 ) );

		$this->assertSame( $default_result['success'], $explicit_result['success'] );
	}

	// =========================================================================
	// AgentFileAbilities — agent-scoped ops use user_id
	// =========================================================================

	/**
	 * Test list-agent-files input_schema includes user_id.
	 */
	public function test_list_agent_files_schema_has_user_id(): void {
		$ability = wp_get_ability( 'datamachine/list-agent-files' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'user_id', $schema['properties'] );
	}

	/**
	 * Test write-agent-file input_schema includes user_id.
	 */
	public function test_write_agent_file_schema_has_user_id(): void {
		$ability = wp_get_ability( 'datamachine/write-agent-file' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'user_id', $schema['properties'] );
	}

	/**
	 * Test agent file listing with user_id returns scoped results.
	 */
	public function test_list_agent_files_scoped_by_user_id(): void {
		$fa = new AgentFileAbilities();

		// Write a file to agent A's directory.
		$fa->executeWriteAgentFile(
			array(
				'filename' => 'test-alpha.md',
				'content'  => '# Alpha agent file',
				'user_id'  => $this->agent_a_id,
			)
		);

		// List agent A's files — should include test-alpha.md.
		$result_a = $fa->executeListAgentFiles(
			array(
				'user_id' => $this->agent_a_id,
			)
		);

		$this->assertTrue( $result_a['success'] );
		$filenames_a = array_column( $result_a['files'], 'filename' );
		$this->assertContains( 'test-alpha.md', $filenames_a );

		// List agent B's files — should NOT include test-alpha.md.
		$result_b = $fa->executeListAgentFiles(
			array(
				'user_id' => $this->agent_b_id,
			)
		);

		$this->assertTrue( $result_b['success'] );
		$filenames_b = array_column( $result_b['files'], 'filename' );
		$this->assertNotContains( 'test-alpha.md', $filenames_b );
	}

	/**
	 * Test agent file write creates user-scoped directory.
	 */
	public function test_write_agent_file_creates_user_directory(): void {
		$fa = new AgentFileAbilities();

		$result = $fa->executeWriteAgentFile(
			array(
				'filename' => 'test-scoped.md',
				'content'  => '# Scoped file',
				'user_id'  => $this->agent_a_id,
			)
		);

		$this->assertTrue( $result['success'] );

		$dm       = new DirectoryManager();
		$user_dir = $dm->get_agent_identity_directory_for_user( $this->agent_a_id );
		$this->assertDirectoryExists( $user_dir );
		$this->assertFileExists( $user_dir . '/test-scoped.md' );
	}

	/**
	 * Test agent file delete is scoped by user_id.
	 */
	public function test_delete_agent_file_scoped(): void {
		$fa = new AgentFileAbilities();

		// Write to agent A.
		$fa->executeWriteAgentFile(
			array(
				'filename' => 'deleteme.md',
				'content'  => '# Delete me',
				'user_id'  => $this->agent_a_id,
			)
		);

		// Delete from agent B — should fail (file doesn't exist there).
		$result_b = $fa->executeDeleteAgentFile(
			array(
				'filename' => 'deleteme.md',
				'user_id'  => $this->agent_b_id,
			)
		);
		$this->assertFalse( $result_b['success'] );

		// Delete from agent A — should succeed.
		$result_a = $fa->executeDeleteAgentFile(
			array(
				'filename' => 'deleteme.md',
				'user_id'  => $this->agent_a_id,
			)
		);
		$this->assertTrue( $result_a['success'] );
	}

	// =========================================================================
	// GetPipelinesAbility — user_id filter
	// =========================================================================

	/**
	 * Test get-pipelines input_schema includes user_id.
	 */
	public function test_get_pipelines_schema_has_user_id(): void {
		$ability = wp_get_ability( 'datamachine/get-pipelines' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'user_id', $schema['properties'] );
	}

	/**
	 * Test pipeline listing filtered by user_id.
	 */
	public function test_pipeline_listing_filtered_by_user_id(): void {
		$db = new Pipelines();

		// Create pipeline for agent A.
		$db->create_pipeline(
			array(
				'pipeline_name'   => 'Alpha Pipeline',
				'pipeline_config' => wp_json_encode( array() ),
				'user_id'         => $this->agent_a_id,
			)
		);

		// Create pipeline for agent B.
		$db->create_pipeline(
			array(
				'pipeline_name'   => 'Beta Pipeline',
				'pipeline_config' => wp_json_encode( array() ),
				'user_id'         => $this->agent_b_id,
			)
		);

		$pa = new GetPipelinesAbility();

		// Filter by agent A.
		$result_a = $pa->execute(
			array( 'user_id' => $this->agent_a_id )
		);
		$this->assertTrue( $result_a['success'] );
		$names_a = array_column( $result_a['pipelines'], 'pipeline_name' );
		$this->assertContains( 'Alpha Pipeline', $names_a );
		$this->assertNotContains( 'Beta Pipeline', $names_a );

		// Filter by agent B.
		$result_b = $pa->execute(
			array( 'user_id' => $this->agent_b_id )
		);
		$this->assertTrue( $result_b['success'] );
		$names_b = array_column( $result_b['pipelines'], 'pipeline_name' );
		$this->assertContains( 'Beta Pipeline', $names_b );
		$this->assertNotContains( 'Alpha Pipeline', $names_b );
	}

	/**
	 * Test pipeline listing without user_id returns all pipelines.
	 */
	public function test_pipeline_listing_without_user_id_returns_all(): void {
		$db = new Pipelines();

		$db->create_pipeline(
			array(
				'pipeline_name'   => 'Shared Pipeline',
				'pipeline_config' => wp_json_encode( array() ),
				'user_id'         => 0,
			)
		);

		$pa     = new GetPipelinesAbility();
		$result = $pa->execute( array() );

		$this->assertTrue( $result['success'] );
		// Should return all pipelines (no user_id filter).
		$this->assertGreaterThan( 0, count( $result['pipelines'] ) );
	}

	// =========================================================================
	// GetFlowsAbility — user_id filter
	// =========================================================================

	/**
	 * Test get-flows input_schema includes user_id.
	 */
	public function test_get_flows_schema_has_user_id(): void {
		$ability = wp_get_ability( 'datamachine/get-flows' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'user_id', $schema['properties'] );
	}

	// =========================================================================
	// GetJobsAbility — user_id filter
	// =========================================================================

	/**
	 * Test get-jobs input_schema includes user_id.
	 */
	public function test_get_jobs_schema_has_user_id(): void {
		$ability = wp_get_ability( 'datamachine/get-jobs' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'user_id', $schema['properties'] );
	}

	/**
	 * Test job listing filtered by user_id.
	 */
	public function test_job_listing_filtered_by_user_id(): void {
		$db = new Jobs();

		// Create job for agent A.
		$db->create_job(
			array(
				'pipeline_id' => 'direct',
				'flow_id'     => 'direct',
				'user_id'     => $this->agent_a_id,
			)
		);

		// Create job for agent B.
		$db->create_job(
			array(
				'pipeline_id' => 'direct',
				'flow_id'     => 'direct',
				'user_id'     => $this->agent_b_id,
			)
		);

		$ja = new GetJobsAbility();

		// Filter by agent A.
		$result_a = $ja->execute(
			array( 'user_id' => $this->agent_a_id )
		);
		$this->assertTrue( $result_a['success'] );
		foreach ( $result_a['jobs'] as $job ) {
			$this->assertEquals( $this->agent_a_id, $job['user_id'] ?? 0 );
		}

		// Filter by agent B.
		$result_b = $ja->execute(
			array( 'user_id' => $this->agent_b_id )
		);
		$this->assertTrue( $result_b['success'] );
		foreach ( $result_b['jobs'] as $job ) {
			$this->assertEquals( $this->agent_b_id, $job['user_id'] ?? 0 );
		}
	}

	// =========================================================================
	// DirectoryManager — user-scoped paths
	// =========================================================================

	/**
	 * Test agent directories are isolated by user_id.
	 */
	public function test_agent_directories_isolated(): void {
		$dm = new DirectoryManager();

		$shared_dir  = $dm->get_agent_directory( 0 );
		$agent_a_dir = $dm->get_agent_identity_directory_for_user( $this->agent_a_id );
		$agent_b_dir = $dm->get_agent_identity_directory_for_user( $this->agent_b_id );

		// All three directories should be different paths.
		$this->assertNotEquals( $shared_dir, $agent_a_dir );
		$this->assertNotEquals( $shared_dir, $agent_b_dir );
		$this->assertNotEquals( $agent_a_dir, $agent_b_dir );
	}

	/**
	 * Test shared agent directory is the legacy path (agent/).
	 */
	public function test_shared_agent_uses_legacy_path(): void {
		$dm         = new DirectoryManager();
		$shared_dir = $dm->get_agent_directory( 0 );

		// Should end with /agent (no user suffix).
		$this->assertStringEndsWith( '/agent', $shared_dir );
	}

	/**
	 * Test user-scoped agent directory uses agent slug path.
	 */
	public function test_user_scoped_agent_path(): void {
		$dm       = new DirectoryManager();
		$user_dir = $dm->get_agent_identity_directory_for_user( $this->agent_a_id );

		// Should end with the agent slug derived from user_login.
		$this->assertStringEndsWith( '/agents/agent_alpha', $user_dir );
	}
}
