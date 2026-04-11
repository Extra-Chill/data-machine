<?php
/**
 * Tests for standalone job creation and execution (null pipeline_id/flow_id).
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\ExecutionContext;
use WP_UnitTestCase;

class StandaloneJobTest extends WP_UnitTestCase {

	private Jobs $db_jobs;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		if ( function_exists( 'datamachine_activate_for_site' ) ) {
			datamachine_activate_for_site();
		}
	}

	public function set_up(): void {
		parent::set_up();
		$this->db_jobs = new Jobs();
	}

	// -- create_job --

	public function test_create_standalone_job_with_null_ids(): void {
		$job_id = $this->db_jobs->create_job( array(
			'source' => 'system_task',
			'label'  => 'Standalone test job',
		) );

		$this->assertIsInt( $job_id );
		$this->assertGreaterThan( 0, $job_id );
	}

	public function test_standalone_job_has_null_pipeline_and_flow(): void {
		$job_id = $this->db_jobs->create_job( array(
			'label' => 'Null IDs check',
		) );

		$job = $this->db_jobs->get_job( $job_id );

		$this->assertNull( $job['pipeline_id'] );
		$this->assertNull( $job['flow_id'] );
	}

	public function test_standalone_job_defaults_source_to_standalone(): void {
		$job_id = $this->db_jobs->create_job( array(
			'label' => 'Default source check',
		) );

		$job = $this->db_jobs->get_job( $job_id );

		$this->assertSame( 'direct', $job['source'] );
	}

	public function test_standalone_job_accepts_custom_source(): void {
		$job_id = $this->db_jobs->create_job( array(
			'source' => 'system_task',
			'label'  => 'Custom source',
		) );

		$job = $this->db_jobs->get_job( $job_id );

		$this->assertSame( 'system_task', $job['source'] );
	}

	public function test_mixed_null_and_numeric_ids_rejected(): void {
		// pipeline_id set but flow_id omitted = invalid (neither all-null nor all-numeric).
		$result = $this->db_jobs->create_job( array(
			'pipeline_id' => 1,
			'label'       => 'Invalid mix',
		) );

		$this->assertFalse( $result );
	}

	public function test_pipeline_jobs_still_work(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$pipeline = wp_get_ability( 'datamachine/create-pipeline' )->execute(
			array( 'pipeline_name' => 'Standalone test pipeline' )
		);
		$flow = wp_get_ability( 'datamachine/create-flow' )->execute(
			array( 'pipeline_id' => $pipeline['pipeline_id'], 'flow_name' => 'Standalone test flow' )
		);

		$job_id = $this->db_jobs->create_job( array(
			'pipeline_id' => $pipeline['pipeline_id'],
			'flow_id'     => $flow['flow_id'],
			'source'      => 'pipeline',
		) );

		$this->assertIsInt( $job_id );

		$job = $this->db_jobs->get_job( $job_id );
		$this->assertSame( (string) $pipeline['pipeline_id'], $job['pipeline_id'] );
		$this->assertSame( (string) $flow['flow_id'], $job['flow_id'] );
	}

	public function test_direct_jobs_still_work(): void {
		$job_id = $this->db_jobs->create_job( array(
			'pipeline_id' => 'direct',
			'flow_id'     => 'direct',
			'source'      => 'direct',
		) );

		$this->assertIsInt( $job_id );

		$job = $this->db_jobs->get_job( $job_id );
		$this->assertSame( 'direct', $job['pipeline_id'] );
		$this->assertSame( 'direct', $job['flow_id'] );
	}

	// -- ExecutionContext --

	public function test_standalone_execution_context(): void {
		$ctx = ExecutionContext::standalone( '99', 'test_handler' );

		$this->assertTrue( $ctx->isStandalone() );
		$this->assertFalse( $ctx->isDirect() );
		$this->assertFalse( $ctx->isFlow() );
		$this->assertSame( 'standalone', $ctx->getMode() );
		$this->assertNull( $ctx->getPipelineId() );
		$this->assertNull( $ctx->getFlowId() );
		$this->assertSame( '99', $ctx->getJobId() );
	}

	public function test_standalone_storage_path_includes_job_id(): void {
		$ctx = ExecutionContext::standalone( '42' );

		$this->assertSame( 'standalone/job-42', $ctx->getStoragePath() );
	}

	public function test_standalone_storage_path_without_job_id(): void {
		$ctx = ExecutionContext::standalone();

		$this->assertSame( 'standalone', $ctx->getStoragePath() );
	}

	public function test_standalone_file_context_has_null_ids(): void {
		$ctx = ExecutionContext::standalone( '42' );

		$file_context = $ctx->getFileContext();
		$this->assertNull( $file_context['pipeline_id'] );
		$this->assertNull( $file_context['flow_id'] );
		$this->assertSame( 'standalone', $file_context['pipeline_name'] );
		$this->assertSame( 'standalone', $file_context['flow_name'] );
	}

	public function test_from_config_detects_standalone(): void {
		$ctx = ExecutionContext::fromConfig( array(
			'pipeline_id' => null,
			'flow_id'     => null,
		), '99' );

		$this->assertTrue( $ctx->isStandalone() );
	}

	public function test_standalone_skips_deduplication(): void {
		$ctx = ExecutionContext::standalone( '42', 'test_handler' );

		// Should always return false (no deduplication for standalone).
		$this->assertFalse( $ctx->isItemProcessed( 'test-item-123' ) );
	}

	// -- FlowFiles --

	public function test_flow_files_null_flow_id_returns_null_context(): void {
		$context = \DataMachine\Api\FlowFiles::get_file_context( null );

		$this->assertNull( $context['pipeline_id'] );
		$this->assertNull( $context['flow_id'] );
	}

	public function test_flow_files_direct_still_works(): void {
		$context = \DataMachine\Api\FlowFiles::get_file_context( 'direct' );

		$this->assertSame( 'direct', $context['pipeline_id'] );
		$this->assertSame( 'direct', $context['flow_id'] );
	}
}
