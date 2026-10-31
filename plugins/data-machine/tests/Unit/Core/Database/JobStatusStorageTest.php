<?php
/**
 * Bounded job status storage and status_reason backfill.
 *
 * @package DataMachine\Tests\Unit\Core\Database
 */

namespace DataMachine\Tests\Unit\Core\Database;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Jobs\JobStatusMigration;
use DataMachine\Core\Database\Migrations\JobsStatusReasonBackfill;
use DataMachine\Core\Database\Migrations\MigrationRunner;
use DataMachine\Core\JobStatus;
use WP_UnitTestCase;

class JobStatusStorageTest extends WP_UnitTestCase {

	private Jobs $jobs;

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();
		Jobs::create_table();
		$this->jobs = new Jobs();
	}

	public function test_long_failure_message_stores_bounded_status_and_separate_detail(): void {
		$job_id  = $this->create_pending_job( 'Long failure' );
		// rtrim: stored detail is trimmed, so building the expectation with a trailing
		// space would assert whitespace the storage layer deliberately discards.
		$detail  = rtrim( 'wp-ai-client request failed: ' . str_repeat( 'cURL error 28: Operation timed out after 513455 milliseconds. ', 8 ) );
		$success = $this->jobs->complete_job( $job_id, JobStatus::failed( $detail )->toString() );

		$this->assertTrue( $success );
		$row = $this->raw_job_row( $job_id );
		$this->assertSame( JobStatus::FAILED, $row['status'] );
		$this->assertSame( $detail, $row['status_reason'] );
		$this->assertStringNotContainsString( 'cURL', $row['status'] );
		$this->assertSame( $detail, $this->jobs->get_job( $job_id )['engine_data']['job_status_reason'] ?? null );
	}

	public function test_group_by_status_returns_one_row_per_state(): void {
		$first  = $this->create_pending_job( 'Group A' );
		$second = $this->create_pending_job( 'Group B' );
		$third  = $this->create_pending_job( 'Group C' );

		$this->assertTrue( $this->jobs->complete_job( $first, JobStatus::failed( 'Exception: provider timeout - retry exhausted' )->toString() ) );
		$this->assertTrue( $this->jobs->complete_job( $second, JobStatus::failed( 'packet_failure' )->toString() ) );
		$this->assertTrue( $this->jobs->complete_job( $third, JobStatus::COMPLETED ) );

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS total FROM %i GROUP BY status',
				$wpdb->prefix . Jobs::TABLE_NAME
			),
			ARRAY_A
		);

		$by_status = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$by_status[ (string) ( $row['status'] ?? '' ) ] = (int) ( $row['total'] ?? 0 );
		}
		ksort( $by_status );

		$expected = array(
			JobStatus::COMPLETED => 1,
			JobStatus::FAILED    => 2,
		);
		ksort( $expected );

		$this->assertSame( $expected, $by_status );
	}

	public function test_schema_backfill_splits_compound_status_and_preserves_detail(): void {
		$job_id = $this->create_pending_job( 'Schema backfill' );
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . Jobs::TABLE_NAME,
			array(
				'status'        => 'failed - Exception: timeout - retry exhausted',
				'status_reason' => null,
			),
			array( 'job_id' => $job_id ),
			array( '%s', null ),
			array( '%d' )
		);

		delete_option( JobsStatusReasonBackfill::LEGACY_OPTION );
		delete_option( JobsStatusReasonBackfill::STATE_OPTION );
		$result = MigrationRunner::run( 'jobs.status-reason-backfill', 250, true );
		$this->assertTrue( $result['success'] );

		$row = $this->raw_job_row( $job_id );
		$this->assertSame( JobStatus::FAILED, $row['status'] );
		$this->assertSame( 'Exception: timeout - retry exhausted', $row['status_reason'] );
	}

	public function test_migration_splits_compound_values_including_separator_in_detail(): void {
		$job_id = $this->create_pending_job( 'Legacy compound' );
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . Jobs::TABLE_NAME,
			array(
				'status'        => 'failed - provider timeout - retry exhausted',
				'status_reason' => null,
			),
			array( 'job_id' => $job_id ),
			array( '%s', null ),
			array( '%d' )
		);

		delete_option( JobStatusMigration::STATE_OPTION );
		$migration = new JobStatusMigration();
		$result    = $migration->apply( 10 );

		$this->assertGreaterThanOrEqual( 1, (int) $result['migrated'] );
		$row = $this->raw_job_row( $job_id );
		$this->assertSame( JobStatus::FAILED, $row['status'] );
		$this->assertSame( 'provider timeout - retry exhausted', $row['status_reason'] );
	}

	public function test_typeerror_exception_is_not_stored_in_status(): void {
		$job_id    = $this->create_pending_job( 'TypeError path' );
		$exception = 'DataMachineCode\\Workspace\\WorktreeContextInjector::has_owner_terminal_disposable_cleanup_signal(): Argument #1 ($metadata) must be of type array, null given, called in /Users/example/wp-content/plugins/data-machine-code/inc/Workspace/WorkspaceWorktreeCleanupEngine.php:376';
		$success   = $this->jobs->complete_job( $job_id, JobStatus::failed( 'Exception: ' . $exception )->toString() );

		$this->assertTrue( $success );
		$row = $this->raw_job_row( $job_id );
		$this->assertSame( JobStatus::FAILED, $row['status'] );
		$this->assertSame( 'Exception: ' . $exception, $row['status_reason'] );
		$this->assertStringNotContainsString( 'WorktreeContextInjector', $row['status'] );
		$this->assertStringNotContainsString( '/Users/', $row['status'] );
	}

	private function create_pending_job( string $label ): int {
		$job_id = $this->jobs->create_job(
			array(
				'pipeline_id' => 'direct',
				'flow_id'     => 'direct',
				'source'      => 'system',
				'label'       => $label,
			)
		);
		$this->assertIsInt( $job_id );
		return $job_id;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function raw_job_row( int $job_id ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, status_reason FROM %i WHERE job_id = %d',
				$wpdb->prefix . Jobs::TABLE_NAME,
				$job_id
			),
			ARRAY_A
		);
		$this->assertIsArray( $row );
		return $row;
	}
}
