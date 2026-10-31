<?php
/**
 * Operator-only migration runner.
 *
 * @package DataMachine\Tests\Unit\Core\Database\Migrations
 */

namespace DataMachine\Tests\Unit\Core\Database\Migrations;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Migrations\JobsStatusReasonBackfill;
use DataMachine\Core\Database\Migrations\MigrationRunner;
use DataMachine\Core\JobStatus;
use WP_UnitTestCase;

class MigrationRunnerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();
		Jobs::create_table();
		delete_option( JobsStatusReasonBackfill::STATE_OPTION );
		delete_option( JobsStatusReasonBackfill::LEGACY_OPTION );
		delete_option( MigrationRunner::LEASE_OPTION );
	}

	public function test_web_context_refuses_apply(): void {
		$result = MigrationRunner::run( 'jobs.status-reason-backfill', 10, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'web_context', $result['code'] );
	}

	public function test_unknown_id_is_refused(): void {
		$result = MigrationRunner::run( 'does.not.exist', 10, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'unknown_migration', $result['code'] );
	}

	public function test_overlapping_run_is_locked(): void {
		update_option(
			MigrationRunner::LEASE_OPTION,
			array(
				'token'      => 'held-token',
				'owner'      => 'jobs.status-reason-backfill',
				'started_at' => time(),
				'expires_at' => time() + 120,
				'ttl'        => 120,
			),
			false
		);

		$result = MigrationRunner::run( 'jobs.status-reason-backfill', 10, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'locked', $result['code'] );
	}

	public function test_status_reason_backfill_is_batched_and_completes(): void {
		$jobs   = new Jobs();
		$job_id = $jobs->create_job(
			array(
				'pipeline_id' => 'direct',
				'flow_id'     => 'direct',
				'source'      => 'system',
				'label'       => 'Legacy compound',
			)
		);
		$this->assertIsInt( $job_id );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . Jobs::TABLE_NAME,
			array(
				'status'        => 'failed - provider timeout',
				'status_reason' => null,
			),
			array( 'job_id' => $job_id ),
			array( '%s', null ),
			array( '%d' )
		);

		$result = MigrationRunner::run( 'jobs.status-reason-backfill', 250, true );

		$this->assertTrue( $result['success'] );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, status_reason FROM %i WHERE job_id = %d',
				$wpdb->prefix . Jobs::TABLE_NAME,
				$job_id
			),
			ARRAY_A
		);
		$this->assertSame( JobStatus::FAILED, $row['status'] );
		$this->assertSame( 'provider timeout', $row['status_reason'] );
	}

	public function test_legacy_complete_flag_skips_work(): void {
		update_option( JobsStatusReasonBackfill::LEGACY_OPTION, 1, false );

		$result = MigrationRunner::run( 'jobs.status-reason-backfill', 10, true );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'already_complete', $result['code'] );
	}

	public function test_create_table_does_not_mark_status_reason_complete(): void {
		delete_option( JobsStatusReasonBackfill::LEGACY_OPTION );
		Jobs::create_table();

		$this->assertFalse( (bool) get_option( JobsStatusReasonBackfill::LEGACY_OPTION ) );
		$inspect = ( new JobsStatusReasonBackfill() )->inspect();
		$this->assertContains( $inspect['status'], array( 'complete', 'migration_required', 'in_progress' ) );
	}
}
