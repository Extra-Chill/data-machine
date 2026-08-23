<?php
/** Executable coverage for owned and caller-owned transaction scopes. */

define( 'ABSPATH', __DIR__ . '/' );

class TransactionScopeFakeWpdb {
	public array $queries = array();
	public array $prepare_calls = array();
	private array $failures = array();
	public function __construct( private bool $in_transaction = false ) {}
	public function failNext( string $prefix ): void {
		$this->failures[ $prefix ] = ( $this->failures[ $prefix ] ?? 0 ) + 1;
	}
	public function get_var( string $query ): string|false {
		return match ( $query ) {
			'SELECT @@autocommit' => $this->in_transaction ? '0' : '1',
			"SHOW VARIABLES LIKE 'in_transaction'" => 'in_transaction',
			'SELECT @@in_transaction' => $this->in_transaction ? '1' : '0',
			default => false,
		};
	}
	public function prepare( string $query, mixed ...$args ): string {
		$this->prepare_calls[] = array( $query, $args );
		return str_replace( '%i', '`' . (string) $args[0] . '`', $query );
	}
	public function query( string $query ): int|false {
		$this->queries[] = $query;
		foreach ( $this->failures as $prefix => $remaining ) {
			if ( $remaining > 0 && str_starts_with( $query, $prefix ) ) {
				--$this->failures[ $prefix ];
				return false;
			}
		}
		return 1;
	}
}

require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
require_once __DIR__ . '/../inc/Core/Database/TransactionScope.php';

$failures = 0;
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		++$failures;
		echo "[FAIL] {$label}\n";
	}
};

$owned = new TransactionScopeFakeWpdb();
$scope = \DataMachine\Core\Database\TransactionScope::begin( $owned );
$assert( null !== $scope && $scope->commit() && array( 'START TRANSACTION', 'COMMIT' ) === $owned->queries, 'owned scope starts and commits its transaction' );

$nested = new TransactionScopeFakeWpdb( true );
$scope  = \DataMachine\Core\Database\TransactionScope::begin( $nested );
$assert( null !== $scope, 'nested scope opens a savepoint' );
$scope?->rollback();
$assert( 3 === count( $nested->queries ) && str_starts_with( $nested->queries[0], 'SAVEPOINT datamachine_transaction_' ) && str_starts_with( $nested->queries[1], 'ROLLBACK TO SAVEPOINT datamachine_transaction_' ) && str_starts_with( $nested->queries[2], 'RELEASE SAVEPOINT datamachine_transaction_' ), 'nested rollback is limited to its savepoint' );
$assert( array() === $nested->prepare_calls && ! str_contains( implode( ' ', $nested->queries ), '`' ), 'internally generated savepoints use portable bare identifiers' );

$nested = new TransactionScopeFakeWpdb( true );
$scope  = \DataMachine\Core\Database\TransactionScope::begin( $nested );
$assert( null !== $scope && $scope->commit(), 'nested scope releases its savepoint' );
$assert( 2 === count( $nested->queries ) && str_starts_with( $nested->queries[0], 'SAVEPOINT datamachine_transaction_' ) && str_starts_with( $nested->queries[1], 'RELEASE SAVEPOINT datamachine_transaction_' ), 'release uses the same MySQL-compatible bare identifier spelling as savepoint creation' );
$assert( array() === $nested->prepare_calls, 'savepoint release does not pass the internal identifier through wpdb prepare' );

$stale = new TransactionScopeFakeWpdb( true );
$scope = \DataMachine\Core\Database\TransactionScope::begin( $stale );
$scope?->rollback();
$assert( null !== $scope && ! $scope->commit() && 3 === count( $stale->queries ), 'rolled-back scope cannot issue a stale commit' );

$rollback_retry = new TransactionScopeFakeWpdb( true );
$scope          = \DataMachine\Core\Database\TransactionScope::begin( $rollback_retry );
$rollback_retry->failNext( 'ROLLBACK TO SAVEPOINT' );
$scope?->rollback();
$assert( 2 === count( $rollback_retry->queries ) && ! str_starts_with( end( $rollback_retry->queries ), 'RELEASE SAVEPOINT' ), 'failed savepoint rollback does not release the boundary' );
$scope?->rollback();
$assert( 4 === count( $rollback_retry->queries ) && str_starts_with( $rollback_retry->queries[2], 'ROLLBACK TO SAVEPOINT' ) && str_starts_with( $rollback_retry->queries[3], 'RELEASE SAVEPOINT' ) && ! $scope->commit(), 'failed savepoint rollback remains retryable until rollback and release succeed' );

$release_retry = new TransactionScopeFakeWpdb( true );
$scope         = \DataMachine\Core\Database\TransactionScope::begin( $release_retry );
$release_retry->failNext( 'RELEASE SAVEPOINT' );
$scope?->rollback();
$scope?->rollback();
$assert( 5 === count( $release_retry->queries ) && str_starts_with( $release_retry->queries[3], 'ROLLBACK TO SAVEPOINT' ) && str_starts_with( $release_retry->queries[4], 'RELEASE SAVEPOINT' ) && ! $scope->commit(), 'failed savepoint release preserves a retry path' );

$owned_retry = new TransactionScopeFakeWpdb();
$scope       = \DataMachine\Core\Database\TransactionScope::begin( $owned_retry );
$owned_retry->failNext( 'ROLLBACK' );
$scope?->rollback();
$scope?->rollback();
$assert( array( 'START TRANSACTION', 'ROLLBACK', 'ROLLBACK' ) === $owned_retry->queries && ! $scope->commit(), 'failed top-level rollback remains retryable' );

echo sprintf( "Transaction scope smoke complete: %d failures.\n", $failures );
exit( $failures > 0 ? 1 : 0 );
