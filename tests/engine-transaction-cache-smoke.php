<?php
/** Verify production Jobs transaction writes never publish pre-commit cache state. */

define( 'ABSPATH', __DIR__ . '/' );

final class EngineTransactionCacheWpdb {
	public string $prefix = 'wp_';
	public string $base_prefix = 'wp_';
	public string $last_error = '';
	public array $events = array();

	public function get_var( string $query ): int { unset( $query ); return 16 * 1024 * 1024; }
	public function update( string $table, array $data, array $where, array $format, array $where_format ): int {
		unset( $table, $data, $where, $format, $where_format );
		$this->events[] = 'sql-update';
		return 1;
	}
	public function delete( string $table, array $where, array $format ): int {
		unset( $table, $where, $format );
		$this->events[] = 'metadata-delete';
		return 1;
	}
	public function query( string $query ): int { $this->events[] = $query; return 1; }
}

$GLOBALS['wpdb'] = new EngineTransactionCacheWpdb();
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	unset( $args );
	return 'datamachine_run_metadata_index_paths' === $hook ? array() : $value;
}
function do_action( string $hook, mixed ...$args ): void { unset( $hook, $args ); }
function wp_cache_set( mixed $key, mixed $value, string $group = '' ): bool {
	unset( $key, $value, $group );
	$GLOBALS['wpdb']->events[] = 'cache-set';
	return true;
}
function wp_cache_delete( mixed $key, string $group = '' ): bool {
	unset( $key, $group );
	$GLOBALS['wpdb']->events[] = 'cache-delete';
	return true;
}

require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
require_once __DIR__ . '/../inc/Core/Database/RunMetadata/RunMetadata.php';
require_once __DIR__ . '/../inc/Core/Database/Jobs/Jobs.php';

$failures = 0;
$passes   = 0;
$assert = static function ( bool $condition, string $label ) use ( &$failures, &$passes ): void {
	if ( $condition ) { ++$passes; echo "  [PASS] {$label}\n"; return; }
	++$failures; echo "  [FAIL] {$label}\n";
};

$jobs = new DataMachine\Core\Database\Jobs\Jobs();
$GLOBALS['wpdb']->query( 'START TRANSACTION' );
$stored = $jobs->store_engine_data_in_transaction( 42, array( 'claims' => array( 'one' ) ) );
$assert( $stored && ! in_array( 'cache-set', $GLOBALS['wpdb']->events, true ), 'transaction-only engine SQL does not publish cache state' );
$GLOBALS['wpdb']->query( 'COMMIT' );
$jobs->publish_committed_engine_data( 42, array( 'claims' => array( 'one' ) ) );
$commit = array_search( 'COMMIT', $GLOBALS['wpdb']->events, true );
$cache  = array_search( 'cache-delete', $GLOBALS['wpdb']->events, true );
$assert( false !== $commit && false !== $cache && $cache > $commit, 'production cache invalidation follows commit' );
$assert( 0 === count( array_filter( $GLOBALS['wpdb']->events, static fn( string $event ): bool => 'cache-set' === $event ) ), 'transaction path never publishes an unlocked snapshot' );

echo "engine-transaction-cache-smoke: {$passes} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
