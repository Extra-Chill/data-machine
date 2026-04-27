<?php
/**
 * Agent Context Propagation Tests
 *
 * Tests that agent_id propagates correctly through create/duplicate abilities:
 * - CreatePipelineAbility: agent_id in schema, stored in DB, cascaded to flows
 * - CreateFlowAbility: agent_id in schema, stored in DB
 * - DuplicatePipelineAbility: agent_id carried from source or overridden
 * - AgentMemoryAbilities: agent_id in all four ability schemas
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\AgentAbilities;
use DataMachine\Abilities\AgentMemoryAbilities;
use DataMachine\Abilities\Pipeline\CreatePipelineAbility;
use DataMachine\Abilities\Pipeline\DuplicatePipelineAbility;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\Database\Flows\Flows;
use WP_UnitTestCase;

/**
 * Integration tests for agent_id context propagation in abilities.
 */
class AgentContextPropagationTest extends WP_UnitTestCase {

	private CreatePipelineAbility $create_pipeline_ability;
	private DuplicatePipelineAbility $duplicate_pipeline_ability;
	private int $admin_id;
	private int $agent_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->create_pipeline_ability    = new CreatePipelineAbility();
		$this->duplicate_pipeline_ability = new DuplicatePipelineAbility();

		// Create a test agent via the abilities layer.
		$agent_result = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'test-propagation-agent',
				'agent_name' => 'Test Propagation Agent',
				'owner_id'   => $this->admin_id,
			)
		);
		$this->agent_id = $agent_result['agent_id'];
	}

	// =========================================================================
	// CreatePipelineAbility — agent_id in schema and execution
	// =========================================================================

	/**
	 * Test create-pipeline input_schema includes agent_id.
	 */
	public function test_create_pipeline_schema_has_agent_id(): void {
		$ability = wp_get_ability( 'datamachine/create-pipeline' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'agent_id', $schema['properties'] );
	}

	/**
	 * Test creating a pipeline with agent_id stores it in DB.
	 */
	public function test_create_pipeline_with_agent_id(): void {
		$result = $this->create_pipeline_ability->execute(
			array(
				'pipeline_name' => 'Agent-Scoped Pipeline',
				'agent_id'      => $this->agent_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['pipeline_id'] );

		// Verify agent_id is stored in DB.
		$db       = new Pipelines();
		$pipeline = $db->get_pipeline( $result['pipeline_id'] );
		$this->assertEquals( $this->agent_id, (int) $pipeline['agent_id'] );
	}

	/**
	 * Test creating a pipeline without agent_id leaves it NULL.
	 */
	public function test_create_pipeline_without_agent_id(): void {
		$result = $this->create_pipeline_ability->execute(
			array( 'pipeline_name' => 'Unscoped Pipeline' )
		);

		$this->assertTrue( $result['success'] );

		$db       = new Pipelines();
		$pipeline = $db->get_pipeline( $result['pipeline_id'] );
		$this->assertNull( $pipeline['agent_id'] );
	}

	/**
	 * Test creating a pipeline with flow_config cascades agent_id to flow.
	 */
	public function test_create_pipeline_cascades_agent_id_to_flow(): void {
		$result = $this->create_pipeline_ability->execute(
			array(
				'pipeline_name' => 'Cascading Agent Pipeline',
				'agent_id'      => $this->agent_id,
				'flow_config'   => array(
					'flow_name' => 'Agent Flow',
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertNotNull( $result['flow_id'] );

		// Verify flow has agent_id.
		$db_flows = new Flows();
		$flow     = $db_flows->get_flow( $result['flow_id'] );
		$this->assertEquals( $this->agent_id, (int) $flow['agent_id'] );
	}

	// =========================================================================
	// CreateFlowAbility — agent_id in schema and execution
	// =========================================================================

	/**
	 * Test create-flow input_schema includes agent_id.
	 */
	public function test_create_flow_schema_has_agent_id(): void {
		$ability = wp_get_ability( 'datamachine/create-flow' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'agent_id', $schema['properties'] );
	}

	/**
	 * Test creating a flow with agent_id stores it in DB.
	 */
	public function test_create_flow_with_agent_id(): void {
		// First create a pipeline.
		$pipeline_result = $this->create_pipeline_ability->execute(
			array( 'pipeline_name' => 'Flow Test Pipeline' )
		);
		$pipeline_id     = $pipeline_result['pipeline_id'];

		$result = wp_get_ability( 'datamachine/create-flow' )->execute(
			array(
				'pipeline_id' => $pipeline_id,
				'flow_name'   => 'Agent-Scoped Flow',
				'agent_id'    => $this->agent_id,
			)
		);

		$this->assertTrue( $result['success'] );

		// Verify agent_id stored in DB.
		$db_flows = new Flows();
		$flow     = $db_flows->get_flow( $result['flow_id'] );
		$this->assertEquals( $this->agent_id, (int) $flow['agent_id'] );
	}

	/**
	 * Test creating a flow without agent_id leaves it NULL.
	 */
	public function test_create_flow_without_agent_id(): void {
		$pipeline_result = $this->create_pipeline_ability->execute(
			array( 'pipeline_name' => 'Flow Test Pipeline 2' )
		);
		$pipeline_id     = $pipeline_result['pipeline_id'];

		$result = wp_get_ability( 'datamachine/create-flow' )->execute(
			array(
				'pipeline_id' => $pipeline_id,
				'flow_name'   => 'Unscoped Flow',
			)
		);

		$this->assertTrue( $result['success'] );

		$db_flows = new Flows();
		$flow     = $db_flows->get_flow( $result['flow_id'] );
		$this->assertNull( $flow['agent_id'] );
	}

	// =========================================================================
	// DuplicatePipelineAbility — agent_id carried or overridden
	// =========================================================================

	/**
	 * Test duplicate-pipeline input_schema includes agent_id.
	 */
	public function test_duplicate_pipeline_schema_has_agent_id(): void {
		$ability = wp_get_ability( 'datamachine/duplicate-pipeline' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'agent_id', $schema['properties'] );
	}

	/**
	 * Test duplicating a pipeline carries agent_id from source.
	 */
	public function test_duplicate_pipeline_carries_agent_id(): void {
		// Create source pipeline with agent_id.
		$source = $this->create_pipeline_ability->execute(
			array(
				'pipeline_name' => 'Source Agent Pipeline',
				'agent_id'      => $this->agent_id,
			)
		);
		$this->assertTrue( $source['success'] );

		$result = $this->duplicate_pipeline_ability->execute(
			array( 'pipeline_id' => $source['pipeline_id'] )
		);

		$this->assertTrue( $result['success'] );

		// Verify duplicate has same agent_id.
		$db       = new Pipelines();
		$pipeline = $db->get_pipeline( $result['pipeline_id'] );
		$this->assertEquals( $this->agent_id, (int) $pipeline['agent_id'] );
	}

	/**
	 * Test duplicating a pipeline with explicit agent_id override.
	 */
	public function test_duplicate_pipeline_agent_id_override(): void {
		// Create a second agent.
		$agent_result_2 = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'test-override-agent',
				'agent_name' => 'Override Agent',
				'owner_id'   => $this->admin_id,
			)
		);
		$agent_id_2     = $agent_result_2['agent_id'];

		// Create source pipeline with first agent.
		$source = $this->create_pipeline_ability->execute(
			array(
				'pipeline_name' => 'Source for Override',
				'agent_id'      => $this->agent_id,
			)
		);

		// Duplicate with different agent_id.
		$result = $this->duplicate_pipeline_ability->execute(
			array(
				'pipeline_id' => $source['pipeline_id'],
				'agent_id'    => $agent_id_2,
			)
		);

		$this->assertTrue( $result['success'] );

		// Verify duplicate uses the override agent_id.
		$db       = new Pipelines();
		$pipeline = $db->get_pipeline( $result['pipeline_id'] );
		$this->assertEquals( $agent_id_2, (int) $pipeline['agent_id'] );
	}

	// =========================================================================
	// AgentMemoryAbilities — agent_id in all schemas
	// =========================================================================

	/**
	 * Test get-agent-memory schema has agent_id.
	 */
	public function test_get_memory_schema_has_agent_id(): void {
		$ability = wp_get_ability( 'datamachine/get-agent-memory' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'agent_id', $schema['properties'] );
	}

	/**
	 * Test update-agent-memory schema has agent_id.
	 */
	public function test_update_memory_schema_has_agent_id(): void {
		$ability = wp_get_ability( 'datamachine/update-agent-memory' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'agent_id', $schema['properties'] );
	}

	/**
	 * Test search-agent-memory schema has agent_id.
	 */
	public function test_search_memory_schema_has_agent_id(): void {
		$ability = wp_get_ability( 'datamachine/search-agent-memory' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'agent_id', $schema['properties'] );
	}

	/**
	 * Test list-agent-memory-sections schema has agent_id.
	 */
	public function test_list_sections_schema_has_agent_id(): void {
		$ability = wp_get_ability( 'datamachine/list-agent-memory-sections' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'agent_id', $schema['properties'] );
	}

	// =========================================================================
	// Bulk creation — agent_id propagates
	// =========================================================================

	/**
	 * Test bulk pipeline creation propagates agent_id.
	 */
	public function test_bulk_create_pipeline_propagates_agent_id(): void {
		$result = $this->create_pipeline_ability->execute(
			array(
				'agent_id'  => $this->agent_id,
				'pipelines' => array(
					array( 'name' => 'Bulk Agent Pipeline 1' ),
					array( 'name' => 'Bulk Agent Pipeline 2' ),
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 2, $result['created_count'] );

		// Verify both pipelines have agent_id.
		$db = new Pipelines();
		foreach ( $result['created'] as $created ) {
			$pipeline = $db->get_pipeline( $created['pipeline_id'] );
			$this->assertEquals( $this->agent_id, (int) $pipeline['agent_id'] );
		}
	}
}
