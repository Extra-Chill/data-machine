<?php
/**
 * SQLite affected-row normalization coverage for job status updates.
 *
 * @package DataMachine\Tests\Unit\Core\Database\Jobs
 */

namespace DataMachine\Tests\Unit\Core\Database\Jobs;

use DataMachine\Core\Database\BaseRepository;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;
use ReflectionMethod;
use ReflectionProperty;
use WP_UnitTestCase;

class JobStatusAffectedRowsTest extends WP_UnitTestCase {

	private Jobs $jobs;
	private ReflectionMethod $result_normalizer;

	public function set_up(): void {
		parent::set_up();
		if ( ! BaseRepository::is_sqlite() ) {
			$this->markTestSkipped( 'SQLite-specific affected-row contract.' );
		}
		datamachine_test_prepare_site();
		$this->jobs              = new Jobs();
		$this->result_normalizer = new ReflectionMethod( $this->jobs, 'job_status_update_succeeded' );
	}

	public function test_negative_sentinel_requires_exact_persisted_status_and_engine_data(): void {
		global $wpdb;
		$job_id = $this->createPendingJob( 'SQLite negative sentinel' );
		$engine = wp_json_encode( array( 'claim' => 'winner' ) );
		$this->assertIsString( $engine );
		$this->assertSame(
			1,
			$wpdb->update(
				$wpdb->prefix . 'datamachine_jobs',
				array(
					'status'      => JobStatus::PROCESSING,
					'engine_data' => $engine,
				),
				array(
					'job_id' => $job_id,
					'status' => JobStatus::PENDING,
				),
				array( '%s', '%s' ),
				array( '%d', '%s' )
			)
		);

		$this->assertTrue( $this->normalize( -1, $job_id, JobStatus::PROCESSING, $engine ) );
		$this->assertFalse( $this->normalize( -1, $job_id, JobStatus::WAITING, $engine ) );
		$this->assertFalse( $this->normalize( -1, $job_id, JobStatus::PROCESSING, (string) wp_json_encode( array( 'claim' => 'loser' ) ) ) );
	}

	public function test_zero_false_and_database_errors_remain_failures(): void {
		global $wpdb;
		$job_id = $this->createPendingJob( 'SQLite ambiguous results' );
		$engine = wp_json_encode( array( 'claim' => 'ambiguous' ) );
		$this->assertIsString( $engine );

		$this->assertFalse( $this->normalize( 0, $job_id, JobStatus::PROCESSING, $engine ) );
		$this->assertFalse( $this->normalize( false, $job_id, JobStatus::PROCESSING, $engine ) );

		$table_name = new ReflectionProperty( $this->jobs, 'table_name' );
		$original   = $table_name->getValue( $this->jobs );
		$table_name->setValue( $this->jobs, $wpdb->prefix . 'datamachine_missing_jobs' );
		$wpdb->suppress_errors( true );
		try {
			$this->assertFalse( $this->normalize( -1, $job_id, JobStatus::PROCESSING, $engine ) );
			$this->assertNotSame( '', $wpdb->last_error );
		} finally {
			$wpdb->suppress_errors( false );
			$table_name->setValue( $this->jobs, $original );
		}
	}

	public function test_compare_and_swap_has_one_winner(): void {
		global $wpdb;
		$job_id = $this->createPendingJob( 'SQLite single winner' );
		$table  = $wpdb->prefix . 'datamachine_jobs';
		$first  = $wpdb->update(
			$table,
			array( 'status' => JobStatus::PROCESSING ),
			array(
				'job_id' => $job_id,
				'status' => JobStatus::PENDING,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
		$second = $wpdb->update(
			$table,
			array( 'status' => JobStatus::WAITING ),
			array(
				'job_id' => $job_id,
				'status' => JobStatus::PENDING,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		$this->assertSame( 1, $first );
		$this->assertSame( 0, $second );
		$this->assertSame( JobStatus::PROCESSING, $this->jobs->get_job( $job_id )['status'] );
	}

	private function normalize( int|false $updated, int $job_id, string $status, string $engine ): bool {
		return $this->result_normalizer->invoke( $this->jobs, $updated, $job_id, $status, $engine );
	}

	private function createPendingJob( string $label ): int {
		$job_id = $this->jobs->create_job( array( 'label' => $label ) );
		$this->assertIsInt( $job_id );
		return $job_id;
	}
}
