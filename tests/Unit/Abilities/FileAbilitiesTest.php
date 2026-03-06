<?php
/**
 * FlowFileAbilities Tests
 *
 * Tests for flow-scoped file listing, retrieval, deletion, and cleanup abilities.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\File\FlowFileAbilities;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\FilesRepository\FileStorage;
use WP_UnitTestCase;

class FileAbilitiesTest extends WP_UnitTestCase {

	private FlowFileAbilities $flow_file_abilities;
	private int $test_pipeline_id;
	private int $test_flow_id;
	private string $test_flow_step_id;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->flow_file_abilities = new FlowFileAbilities();

		$pipeline_ability       = wp_get_ability( 'datamachine/create-pipeline' );
		$flow_ability           = wp_get_ability( 'datamachine/create-flow' );

		$pipeline               = $pipeline_ability->execute( array( 'pipeline_name' => 'Test Pipeline for Files' ) );
		$this->test_pipeline_id = $pipeline['pipeline_id'];

		$flow                   = $flow_ability->execute( array( 'pipeline_id' => $this->test_pipeline_id, 'flow_name' => 'Test Flow for Files' ) );
		$this->test_flow_id     = $flow['flow_id'];

		$this->test_flow_step_id = $this->test_pipeline_id . '-' . $this->test_flow_id;
	}

	public function tear_down(): void {
		parent::tear_down();
	}

	// =========================================================================
	// Ability registration
	// =========================================================================

	public function test_list_flow_files_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/list-flow-files' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/list-flow-files', $ability->get_name() );
	}

	public function test_get_flow_file_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/get-flow-file' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/get-flow-file', $ability->get_name() );
	}

	public function test_delete_flow_file_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/delete-flow-file' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/delete-flow-file', $ability->get_name() );
	}

	public function test_cleanup_flow_files_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/cleanup-flow-files' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/cleanup-flow-files', $ability->get_name() );
	}

	public function test_upload_flow_file_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/upload-flow-file' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/upload-flow-file', $ability->get_name() );
	}

	// =========================================================================
	// List flow files
	// =========================================================================

	public function test_list_flow_files_requires_flow_step_id(): void {
		$result = $this->flow_file_abilities->executeListFlowFiles( array() );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'flow_step_id is required', $result['error'] );
	}

	// =========================================================================
	// Get flow file
	// =========================================================================

	public function test_get_flow_file_requires_both_params(): void {
		// Without flow_step_id — should fail.
		$result = $this->flow_file_abilities->executeGetFlowFile(
			array(
				'filename' => 'test.txt',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_get_flow_file_requires_flow_step_id(): void {
		$result = $this->flow_file_abilities->executeGetFlowFile(
			array(
				'filename' => 'test.txt',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'flow_step_id is required', $result['error'] );
	}

	// =========================================================================
	// Delete flow file
	// =========================================================================

	public function test_delete_flow_file_requires_filename(): void {
		$result = $this->flow_file_abilities->executeDeleteFlowFile(
			array(
				'flow_step_id' => $this->test_flow_step_id,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_delete_flow_file_requires_flow_step_id(): void {
		$result = $this->flow_file_abilities->executeDeleteFlowFile(
			array(
				'filename' => 'test.txt',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'flow_step_id is required', $result['error'] );
	}

	// =========================================================================
	// Cleanup flow files
	// =========================================================================

	public function test_cleanup_flow_files_requires_scope(): void {
		$result = $this->flow_file_abilities->executeCleanupFlowFiles( array() );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'Must provide either', $result['error'] );
	}

	public function test_cleanup_flow_files_job_requires_flow(): void {
		$result = $this->flow_file_abilities->executeCleanupFlowFiles(
			array(
				'job_id' => 1,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'flow_id is required', $result['error'] );
	}

	public function test_cleanup_flow_files_with_invalid_flow_id(): void {
		$result = $this->flow_file_abilities->executeCleanupFlowFiles(
			array(
				'flow_id' => 999999,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_cleanup_flow_files_with_valid_flow(): void {
		$result = $this->flow_file_abilities->executeCleanupFlowFiles(
			array(
				'flow_id' => $this->test_flow_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'deleted_count', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertIsInt( $result['deleted_count'] );
	}

	public function test_cleanup_flow_files_with_job_and_flow(): void {
		$db_jobs = new Jobs();
		$job_id  = $db_jobs->create_job(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'flow_id'     => $this->test_flow_id,
			)
		);

		$result = $this->flow_file_abilities->executeCleanupFlowFiles(
			array(
				'job_id'  => $job_id,
				'flow_id' => $this->test_flow_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'deleted_count', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertStringContainsString( 'Cleanup completed', $result['message'] );
	}

	// =========================================================================
	// Permission
	// =========================================================================

	public function test_permission_callback_denies_unauthenticated(): void {
		wp_set_current_user( 0 );
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$ability = wp_get_ability( 'datamachine/list-flow-files' );
		$this->assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'flow_step_id' => $this->test_flow_step_id,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	public function test_permission_callback_allows_admin(): void {
		$result = $this->flow_file_abilities->checkPermission();
		$this->assertTrue( $result );
	}
}
