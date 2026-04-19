<?php
/**
 * PipelineAbilities Tests
 *
 * Tests for pipeline CRUD abilities.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\PipelineAbilities;
use WP_UnitTestCase;

class PipelineAbilitiesTest extends WP_UnitTestCase {

	private PipelineAbilities $pipeline_abilities;
	private int $test_pipeline_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->pipeline_abilities = new PipelineAbilities();

		$result = $this->pipeline_abilities->executeCreatePipeline(
			array( 'pipeline_name' => 'Test Pipeline for Abilities' )
		);
		$this->test_pipeline_id = $result['pipeline_id'];
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		parent::tear_down();
	}

	public function test_get_pipelines_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/get-pipelines' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/get-pipelines', $ability->get_name() );
	}

	public function test_get_pipelines_supports_single_pipeline_lookup(): void {
		$result = $this->pipeline_abilities->executeGetPipelines(
			array( 'pipeline_id' => $this->test_pipeline_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'pipelines', $result );
		$this->assertCount( 1, $result['pipelines'] );
	}

	public function test_create_pipeline_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/create-pipeline' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/create-pipeline', $ability->get_name() );
	}

	public function test_update_pipeline_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/update-pipeline' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/update-pipeline', $ability->get_name() );
	}

	public function test_delete_pipeline_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/delete-pipeline' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/delete-pipeline', $ability->get_name() );
	}

	public function test_duplicate_pipeline_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/duplicate-pipeline' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/duplicate-pipeline', $ability->get_name() );
	}

	public function test_import_pipelines_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/import-pipelines' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/import-pipelines', $ability->get_name() );
	}

	public function test_export_pipelines_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/export-pipelines' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/export-pipelines', $ability->get_name() );
	}

	public function test_get_all_pipelines(): void {
		$result = $this->pipeline_abilities->executeGetPipelines(
			array(
				'per_page' => 20,
				'offset'   => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'pipelines', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'per_page', $result );
		$this->assertArrayHasKey( 'offset', $result );
		$this->assertArrayHasKey( 'output_mode', $result );
		$this->assertEquals( 'full', $result['output_mode'] );

		$pipelines = $result['pipelines'];
		$this->assertIsArray( $pipelines );
		$this->assertGreaterThan( 0, count( $pipelines ) );
	}

	public function test_get_pipelines_with_pagination(): void {
		$result1 = $this->pipeline_abilities->executeGetPipelines(
			array(
				'per_page' => 1,
				'offset'   => 0,
			)
		);

		$result2 = $this->pipeline_abilities->executeGetPipelines(
			array(
				'per_page' => 1,
				'offset'   => 1,
			)
		);

		$this->assertTrue( $result1['success'] );
		$this->assertTrue( $result2['success'] );

		$this->assertEquals( 0, $result1['offset'] );
		$this->assertEquals( 1, $result2['offset'] );
	}

	public function test_output_mode_full(): void {
		$result = $this->pipeline_abilities->executeGetPipelines(
			array(
				'output_mode' => 'full',
				'per_page'    => 20,
				'offset'      => 0,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'full', $result['output_mode'] );
		$this->assertGreaterThan( 0, count( $result['pipelines'] ) );

		$first_pipeline = $result['pipelines'][0];
		$this->assertArrayHasKey( 'pipeline_id', $first_pipeline );
		$this->assertArrayHasKey( 'pipeline_name', $first_pipeline );
		$this->assertArrayHasKey( 'pipeline_config', $first_pipeline );
		$this->assertArrayHasKey( 'flows', $first_pipeline );
		$this->assertArrayHasKey( 'flow_count', $first_pipeline );
	}

	public function test_output_mode_full_without_flows_embed(): void {
		$result = $this->pipeline_abilities->executeGetPipelines(
			array(
				'output_mode'   => 'full',
				'per_page'      => 20,
				'offset'        => 0,
				'include_flows' => false,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'full', $result['output_mode'] );
		$this->assertGreaterThan( 0, count( $result['pipelines'] ) );

		$first_pipeline = $result['pipelines'][0];
		$this->assertArrayHasKey( 'pipeline_id', $first_pipeline );
		$this->assertArrayHasKey( 'pipeline_name', $first_pipeline );
		$this->assertArrayHasKey( 'pipeline_config', $first_pipeline );
		$this->assertArrayHasKey( 'flow_count', $first_pipeline );
		$this->assertArrayNotHasKey( 'flows', $first_pipeline );
		$this->assertIsInt( $first_pipeline['flow_count'] );
	}

	public function test_output_mode_summary(): void {
		$result = $this->pipeline_abilities->executeGetPipelines(
			array(
				'output_mode' => 'summary',
				'per_page'    => 20,
				'offset'      => 0,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'summary', $result['output_mode'] );
		$this->assertGreaterThan( 0, count( $result['pipelines'] ) );

		$first_pipeline = $result['pipelines'][0];
		$this->assertArrayHasKey( 'pipeline_id', $first_pipeline );
		$this->assertArrayHasKey( 'pipeline_name', $first_pipeline );
		$this->assertArrayHasKey( 'flow_count', $first_pipeline );
		$this->assertArrayNotHasKey( 'pipeline_config', $first_pipeline );
		$this->assertArrayNotHasKey( 'flows', $first_pipeline );
	}

	public function test_output_mode_ids(): void {
		$result = $this->pipeline_abilities->executeGetPipelines(
			array(
				'output_mode' => 'ids',
				'per_page'    => 20,
				'offset'      => 0,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'ids', $result['output_mode'] );
		$this->assertGreaterThan( 0, count( $result['pipelines'] ) );

		$first_pipeline = $result['pipelines'][0];
		$this->assertIsInt( $first_pipeline );
	}

	public function test_get_pipelines_with_pipeline_id_returns_single_pipeline(): void {
		$result = $this->pipeline_abilities->executeGetPipelines(
			array( 'pipeline_id' => $this->test_pipeline_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'pipelines', $result );
		$this->assertCount( 1, $result['pipelines'] );
		$this->assertArrayHasKey( 'pipeline_id', $result['pipelines'][0] );
		$this->assertEquals( $this->test_pipeline_id, $result['pipelines'][0]['pipeline_id'] );
	}

	public function test_get_pipelines_with_invalid_pipeline_id_returns_empty_array(): void {
		$result = $this->pipeline_abilities->executeGetPipelines(
			array( 'pipeline_id' => 999999 )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'pipelines', $result );
		$this->assertEmpty( $result['pipelines'] );
		$this->assertEquals( 0, $result['total'] );
	}

	public function test_get_pipelines_with_zero_pipeline_id_returns_error(): void {
		$result = $this->pipeline_abilities->executeGetPipelines(
			array( 'pipeline_id' => 0 )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'positive integer', $result['error'] );
	}

	public function test_create_pipeline_simple(): void {
		$result = $this->pipeline_abilities->executeCreatePipeline(
			array( 'pipeline_name' => 'Test Simple Pipeline' )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'pipeline_id', $result );
		$this->assertArrayHasKey( 'pipeline_name', $result );
		$this->assertArrayHasKey( 'flow_id', $result );
		$this->assertEquals( 'Test Simple Pipeline', $result['pipeline_name'] );
		$this->assertEquals( 'simple', $result['creation_mode'] );
		$this->assertEquals( 0, $result['steps_created'] );
	}

	public function test_create_pipeline_with_empty_name(): void {
		$result = $this->pipeline_abilities->executeCreatePipeline(
			array( 'pipeline_name' => '' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'empty', $result['error'] );
	}

	public function test_create_pipeline_without_name(): void {
		$result = $this->pipeline_abilities->executeCreatePipeline( array() );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'required', $result['error'] );
	}

	public function test_update_pipeline_name(): void {
		$result = $this->pipeline_abilities->executeUpdatePipeline(
			array(
				'pipeline_id'   => $this->test_pipeline_id,
				'pipeline_name' => 'Updated Pipeline Name',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'Updated Pipeline Name', $result['pipeline_name'] );
		$this->assertEquals( $this->test_pipeline_id, $result['pipeline_id'] );
	}

	public function test_update_pipeline_not_found(): void {
		$result = $this->pipeline_abilities->executeUpdatePipeline(
			array(
				'pipeline_id'   => 999999,
				'pipeline_name' => 'Should Not Update',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_update_pipeline_without_name(): void {
		$result = $this->pipeline_abilities->executeUpdatePipeline(
			array( 'pipeline_id' => $this->test_pipeline_id )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'Must provide', $result['error'] );
	}

	public function test_update_pipeline_with_empty_name(): void {
		$result = $this->pipeline_abilities->executeUpdatePipeline(
			array(
				'pipeline_id'   => $this->test_pipeline_id,
				'pipeline_name' => '',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'empty', $result['error'] );
	}

	public function test_delete_pipeline(): void {
		$create_result = $this->pipeline_abilities->executeCreatePipeline(
			array( 'pipeline_name' => 'Pipeline To Delete' )
		);
		$pipeline_id   = $create_result['pipeline_id'];

		$result = $this->pipeline_abilities->executeDeletePipeline(
			array( 'pipeline_id' => $pipeline_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( $pipeline_id, $result['pipeline_id'] );
		$this->assertEquals( 'Pipeline To Delete', $result['pipeline_name'] );
		$this->assertArrayHasKey( 'deleted_flows', $result );
		$this->assertArrayHasKey( 'message', $result );

		$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$pipeline     = $db_pipelines->get_pipeline( $pipeline_id );
		$this->assertNull( $pipeline );
	}

	public function test_delete_pipeline_not_found(): void {
		$result = $this->pipeline_abilities->executeDeletePipeline(
			array( 'pipeline_id' => 999999 )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_delete_pipeline_with_invalid_id(): void {
		$result = $this->pipeline_abilities->executeDeletePipeline(
			array( 'pipeline_id' => 0 )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'positive integer', $result['error'] );
	}

	public function test_duplicate_pipeline(): void {
		$result = $this->pipeline_abilities->executeDuplicatePipeline(
			array( 'pipeline_id' => $this->test_pipeline_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'pipeline_id', $result );
		$this->assertArrayHasKey( 'pipeline_name', $result );
		$this->assertArrayHasKey( 'source_pipeline_id', $result );
		$this->assertArrayHasKey( 'source_pipeline_name', $result );
		$this->assertArrayHasKey( 'flows_created', $result );
		$this->assertNotEquals( $this->test_pipeline_id, $result['pipeline_id'] );
		$this->assertEquals( $this->test_pipeline_id, $result['source_pipeline_id'] );
		$this->assertStringContainsString( 'Copy of', $result['pipeline_name'] );
	}

	public function test_duplicate_pipeline_with_custom_name(): void {
		$result = $this->pipeline_abilities->executeDuplicatePipeline(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'new_name'    => 'My Custom Copy',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'My Custom Copy', $result['pipeline_name'] );
	}

	public function test_duplicate_pipeline_not_found(): void {
		$result = $this->pipeline_abilities->executeDuplicatePipeline(
			array( 'pipeline_id' => 999999 )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_export_pipelines(): void {
		$result = $this->pipeline_abilities->executeExportPipelines(
			array( 'pipeline_ids' => array( $this->test_pipeline_id ) )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'count', $result );
		$this->assertEquals( 1, $result['count'] );
		$this->assertNotEmpty( $result['data'] );
	}

	public function test_export_all_pipelines(): void {
		$result = $this->pipeline_abilities->executeExportPipelines( array() );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'count', $result );
		$this->assertGreaterThan( 0, $result['count'] );
	}

	public function test_import_pipelines_empty_data(): void {
		$result = $this->pipeline_abilities->executeImportPipelines(
			array( 'data' => '' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'required', $result['error'] );
	}

	public function test_permission_callback(): void {
		wp_set_current_user( 0 );
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$ability = wp_get_ability( 'datamachine/get-pipelines' );
		$this->assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'per_page' => 20,
				'offset'   => 0,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	public function test_ability_not_found(): void {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		$ability = wp_get_ability( 'datamachine/non-existent-ability' );
		$this->assertNull( $ability );
	}
}
