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

	/**
	 * Autocommit detection must not treat an unsupported variable as enabled.
	 *
	 * Engines that do not implement "@@autocommit" report NULL, which casts to
	 * 0 and would otherwise be read as "autocommit disabled". That would claim
	 * an enclosing transaction that does not exist and open a bare savepoint,
	 * which cannot be committed or rolled back on its own.
	 */
	public function test_absent_autocommit_variable_is_not_read_as_open_transaction(): void {
		$wpdb  = new TransactionScopeProbeWpdb( null );
		$scope = TransactionScope::begin( $wpdb );

		$this->assertInstanceOf( TransactionScope::class, $scope );
		$this->assertSame( array( 'START TRANSACTION' ), $wpdb->executed );

		$this->assertTrue( $scope->commit() );
		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $wpdb->executed );
	}

	/** A caller that disabled autocommit still nests inside its transaction. */
	public function test_disabled_autocommit_opens_savepoint_scope(): void {
		$wpdb  = new TransactionScopeProbeWpdb( '0' );
		$scope = TransactionScope::begin( $wpdb );

		$this->assertInstanceOf( TransactionScope::class, $scope );
		$this->assertCount( 1, $wpdb->executed );
		$this->assertStringStartsWith( 'SAVEPOINT datamachine_transaction_', $wpdb->executed[0] );
	}

	/** Autocommit enabled means this scope owns a real transaction. */
	public function test_enabled_autocommit_opens_transaction_scope(): void {
		$wpdb  = new TransactionScopeProbeWpdb( '1' );
		$scope = TransactionScope::begin( $wpdb );

		$this->assertInstanceOf( TransactionScope::class, $scope );
		$this->assertSame( array( 'START TRANSACTION' ), $wpdb->executed );
	}

	/**
	 * An unresolved scope must not make later scopes nest into nothing.
	 *
	 * A caller that returns or throws without committing or rolling back would
	 * otherwise leave the tracked depth raised, and the next independent scope
	 * would open a savepoint with no enclosing transaction to hold it.
	 */
	public function test_abandoned_scope_does_not_strand_later_scopes(): void {
		$abandoned = TransactionScope::begin( new TransactionScopeProbeWpdb( null ) );
		$this->assertInstanceOf( TransactionScope::class, $abandoned );
		unset( $abandoned );

		$wpdb  = new TransactionScopeProbeWpdb( null );
		$scope = TransactionScope::begin( $wpdb );

		$this->assertInstanceOf( TransactionScope::class, $scope );
		$this->assertSame( array( 'START TRANSACTION' ), $wpdb->executed );
	}

	/**
	 * A nested scope must not discard the enclosing scope's work.
	 *
	 * Engines differ in what they report through server variables, and some
	 * treat a second START TRANSACTION as an implicit commit of the first. The
	 * inner scope must therefore always nest rather than open a new boundary.
	 */
	public function test_inner_scope_rollback_preserves_outer_scope_write(): void {
		$outer_job = $this->createPendingJob( 'Outer scope write' );
		$inner_job = $this->createPendingJob( 'Inner scope write' );

		$outer = TransactionScope::begin( $GLOBALS['wpdb'] );
		$this->assertInstanceOf( TransactionScope::class, $outer );
		$this->assertTrue( $this->jobs->start_job( $outer_job ) );

		$inner = TransactionScope::begin( $GLOBALS['wpdb'] );
		$this->assertInstanceOf( TransactionScope::class, $inner );
		$this->assertTrue( $this->jobs->start_job( $inner_job ) );
		$inner->rollback();

		$this->assertSame( JobStatus::PENDING, $this->jobs->get_job( $inner_job )['status'] );
		$this->assertSame( JobStatus::PROCESSING, $this->jobs->get_job( $outer_job )['status'] );

		$this->assertTrue( $outer->commit() );
		$this->assertSame( JobStatus::PROCESSING, $this->jobs->get_job( $outer_job )['status'] );
		$this->assertSame( JobStatus::PENDING, $this->jobs->get_job( $inner_job )['status'] );
	}

	/** An outer rollback must discard work committed by an inner scope. */
	public function test_outer_scope_rollback_discards_committed_inner_scope(): void {
		$job_id = $this->createPendingJob( 'Outer rollback discards inner' );

		$outer = TransactionScope::begin( $GLOBALS['wpdb'] );
		$this->assertInstanceOf( TransactionScope::class, $outer );

		$inner = TransactionScope::begin( $GLOBALS['wpdb'] );
		$this->assertInstanceOf( TransactionScope::class, $inner );
		$this->assertTrue( $this->jobs->start_job( $job_id ) );
		$this->assertTrue( $inner->commit() );

		$outer->rollback();
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

/**
 * Records the statements a scope issues for a given "@@autocommit" value.
 *
 * The live test connection always runs inside the WordPress test transaction,
 * so the autocommit-enabled branch is unreachable through $GLOBALS['wpdb'].
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
class TransactionScopeProbeWpdb {

	/** @var array<int, string> */
	public array $executed = array();

	/** @param string|null $autocommit Value reported for "@@autocommit". */
	public function __construct( private $autocommit ) {}

	/**
	 * @param  string $query Query to inspect.
	 * @return string|null
	 */
	public function get_var( $query ) {
		if ( str_contains( $query, '@@autocommit' ) ) {
			return $this->autocommit;
		}
		// Report no support for the "in_transaction" variable.
		return null;
	}

	/**
	 * @param  string $query Query to record.
	 * @return int
	 */
	public function query( $query ) {
		$this->executed[] = $query;
		return 1;
	}
}
