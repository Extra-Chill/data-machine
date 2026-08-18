<?php
/** Executable coverage for owned and caller-owned transaction scopes. */

define( 'ABSPATH', __DIR__ . '/' );

class TransactionScopeFakeWpdb {
	public array $queries = array();
	public function __construct( private bool $in_transaction = false ) {}
	public function get_var( string $query ): string|false {
		return match ( $query ) {
			'SELECT @@autocommit' => $this->in_transaction ? '0' : '1',
			"SHOW VARIABLES LIKE 'in_transaction'" => 'in_transaction',
			'SELECT @@in_transaction' => $this->in_transaction ? '1' : '0',
			default => false,
		};
	}
	public function prepare( string $query, mixed ...$args ): string {
		return str_replace( '%i', (string) $args[0], $query );
	}
	public function query( string $query ): int {
		$this->queries[] = $query;
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

echo sprintf( "Transaction scope smoke complete: %d failures.\n", $failures );
exit( $failures > 0 ? 1 : 0 );
