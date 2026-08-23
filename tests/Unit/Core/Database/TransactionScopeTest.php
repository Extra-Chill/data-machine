<?php
/**
 * Portable transaction savepoint coverage.
 *
 * @package DataMachine\Tests\Unit\Core\Database
 */

namespace DataMachine\Tests\Unit\Core\Database;

use DataMachine\Core\Database\BaseRepository;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\TransactionScope;
use DataMachine\Core\JobStatus;
use WP_UnitTestCase;

class TransactionScopeTest extends WP_UnitTestCase {

	private Jobs $jobs;

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();
		$this->jobs = new Jobs();
	}

	public function test_sqlite_status_write_survives_savepoint_release(): void {
		$this->requireSqlite();
		$job_id = $this->createPendingJob( 'SQLite savepoint release' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$GLOBALS['wpdb']->query( 'SAVEPOINT datamachine_test_caller' );
		$scope  = TransactionScope::begin( $GLOBALS['wpdb'] );

		$this->assertInstanceOf( TransactionScope::class, $scope );
		$this->assertTrue( $this->jobs->start_job( $job_id ) );
		$this->assertTrue( $scope->commit() );
		$this->assertSame( JobStatus::PROCESSING, $this->jobs->get_job( $job_id )['status'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$GLOBALS['wpdb']->query( 'ROLLBACK TO SAVEPOINT datamachine_test_caller' );
		$this->assertSame( JobStatus::PENDING, $this->jobs->get_job( $job_id )['status'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$GLOBALS['wpdb']->query( 'RELEASE SAVEPOINT datamachine_test_caller' );
	}

	public function test_sqlite_savepoint_rollback_discards_status_write(): void {
		$this->requireSqlite();
		$job_id = $this->createPendingJob( 'SQLite savepoint rollback' );
		$scope  = TransactionScope::begin( $GLOBALS['wpdb'] );

		$this->assertInstanceOf( TransactionScope::class, $scope );
		$this->assertTrue( $this->jobs->start_job( $job_id ) );
		$scope->rollback();
		$this->assertSame( JobStatus::PENDING, $this->jobs->get_job( $job_id )['status'] );
	}

	public function test_sqlite_rolled_back_scope_cannot_commit_as_stale_owner(): void {
		$this->requireSqlite();
		$job_id = $this->createPendingJob( 'SQLite stale savepoint owner' );
		$scope  = TransactionScope::begin( $GLOBALS['wpdb'] );

		$this->assertInstanceOf( TransactionScope::class, $scope );
		$this->assertTrue( $this->jobs->start_job( $job_id ) );
		$scope->rollback();
		$this->assertFalse( $scope->commit() );
		$this->assertSame( JobStatus::PENDING, $this->jobs->get_job( $job_id )['status'] );
	}

	private function requireSqlite(): void {
		if ( ! BaseRepository::is_sqlite() ) {
			$this->markTestSkipped( 'SQLite-specific savepoint integration coverage.' );
		}
	}

	private function createPendingJob( string $label ): int {
		$job_id = $this->jobs->create_job( array( 'source' => 'pipeline', 'label' => $label ) );
		$this->assertIsInt( $job_id );
		$this->assertSame( JobStatus::PENDING, $this->jobs->get_job( $job_id )['status'] );
		return $job_id;
	}
}
