<?php
/**
 * Pure-PHP smoke test for DailyMemory's unified memory-store seam.
 *
 * Verifies that the default DailyMemory implementation addresses daily
 * entries as agent-layer files under daily/YYYY/MM/DD.md through the active
 * AgentMemoryStoreInterface. The older datamachine_daily_memory_storage seam
 * is ability-level only and is intentionally not needed for this default path.
 */

define( 'ABSPATH', __DIR__ . '/../' );

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['datamachine_daily_memory_store_seam_filters'][ $hook ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		);
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$filters = $GLOBALS['datamachine_daily_memory_store_seam_filters'][ $hook ] ?? array();
		ksort( $filters );

		foreach ( $filters as $callbacks ) {
			foreach ( $callbacks as $filter ) {
				$value = $filter['callback']( ...array_slice( array_merge( array( $value ), $args ), 0, $filter['accepted_args'] ) );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( int $bytes ): string {
		return $bytes . ' B';
	}
}

require_once __DIR__ . '/../inc/Engine/AI/MemoryFileRegistry.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/DirectoryManager.php';
require_once __DIR__ . '/../agents-api/inc/Core/FilesRepository/AgentMemoryScope.php';
require_once __DIR__ . '/../agents-api/inc/Core/FilesRepository/AgentMemoryReadResult.php';
require_once __DIR__ . '/../agents-api/inc/Core/FilesRepository/AgentMemoryWriteResult.php';
require_once __DIR__ . '/../agents-api/inc/Core/FilesRepository/AgentMemoryListEntry.php';
require_once __DIR__ . '/../agents-api/inc/Core/FilesRepository/AgentMemoryStoreInterface.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/DiskAgentMemoryStore.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemoryStoreFactory.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemory.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/DailyMemoryStorage.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/DailyMemory.php';

use AgentsAPI\Core\FilesRepository\AgentMemoryListEntry;
use AgentsAPI\Core\FilesRepository\AgentMemoryReadResult;
use AgentsAPI\Core\FilesRepository\AgentMemoryScope;
use AgentsAPI\Core\FilesRepository\AgentMemoryStoreInterface;
use AgentsAPI\Core\FilesRepository\AgentMemoryWriteResult;
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Engine\AI\MemoryFileRegistry;

class DailyMemorySeamFakeStore implements AgentMemoryStoreInterface {
	/** @var array<string, string> */
	public array $files = array();

	/** @var string[] */
	public array $operations = array();

	/** @var AgentMemoryScope[] */
	public array $scopes = array();

	/** @var string[] */
	public array $list_prefixes = array();

	public function read( AgentMemoryScope $scope ): AgentMemoryReadResult {
		$this->record( 'read', $scope );

		if ( ! array_key_exists( $scope->key(), $this->files ) ) {
			return AgentMemoryReadResult::not_found();
		}

		$content = $this->files[ $scope->key() ];
		return new AgentMemoryReadResult( true, $content, sha1( $content ), strlen( $content ), 123 );
	}

	public function write( AgentMemoryScope $scope, string $content, ?string $if_match = null ): AgentMemoryWriteResult {
		$this->record( 'write', $scope );
		$this->files[ $scope->key() ] = $content;

		return AgentMemoryWriteResult::ok( sha1( $content ), strlen( $content ) );
	}

	public function exists( AgentMemoryScope $scope ): bool {
		$this->record( 'exists', $scope );
		return array_key_exists( $scope->key(), $this->files );
	}

	public function delete( AgentMemoryScope $scope ): AgentMemoryWriteResult {
		$this->record( 'delete', $scope );
		unset( $this->files[ $scope->key() ] );

		return AgentMemoryWriteResult::ok( '', 0 );
	}

	public function list_layer( AgentMemoryScope $scope_query ): array {
		$this->record( 'list_layer', $scope_query );
		return array();
	}

	public function list_subtree( AgentMemoryScope $scope_query, string $prefix ): array {
		$this->record( 'list_subtree', $scope_query );
		$this->list_prefixes[] = $prefix;

		$entries = array();
		foreach ( $this->files as $key => $content ) {
			[ $layer, $user_id, $agent_id, $filename ] = explode( ':', $key, 4 );
			if ( $layer !== $scope_query->layer || (int) $user_id !== $scope_query->user_id || (int) $agent_id !== $scope_query->agent_id ) {
				continue;
			}
			if ( 0 !== strpos( $filename, $prefix . '/' ) ) {
				continue;
			}

			$entries[] = new AgentMemoryListEntry( $filename, $layer, strlen( $content ), 123 );
		}

		return $entries;
	}

	private function record( string $operation, AgentMemoryScope $scope ): void {
		$this->operations[] = $operation;
		$this->scopes[]     = $scope;
	}
}

function datamachine_daily_memory_store_seam_assert( bool $condition, string $message ): void {
	static $assertions = 0;
	++$assertions;

	if ( ! $condition ) {
		fwrite( STDERR, "Assertion failed: {$message}\n" );
		exit( 1 );
	}

	echo "ok {$assertions} - {$message}\n";
}

$store = new DailyMemorySeamFakeStore();
add_filter(
	'agents_api_memory_store',
	function ( $default, AgentMemoryScope $scope ) use ( $store ) {
		return $store;
	},
	10,
	2
);

$daily = new DailyMemory( 123, 456 );

$append = $daily->append( '2026', '04', '28', 'First note.' );
datamachine_daily_memory_store_seam_assert( true === $append['success'], 'append succeeds through the fake memory store' );

$read = $daily->read( '2026', '04', '28' );
datamachine_daily_memory_store_seam_assert( true === $read['success'], 'read succeeds through the fake memory store' );
datamachine_daily_memory_store_seam_assert( isset( $read['content'] ) && false !== strpos( $read['content'], 'First note.' ), 'read returns content written by append' );

$daily->write( '2026', '04', '29', 'Second note.' );

$list = $daily->list_all();
datamachine_daily_memory_store_seam_assert( true === $list['success'], 'list_all succeeds through the fake memory store' );
datamachine_daily_memory_store_seam_assert( array( '28', '29' ) === $list['months']['2026/04'], 'list_all groups daily store filenames by month' );

$filenames = array_map(
	static fn( AgentMemoryScope $scope ): string => $scope->filename,
	$store->scopes
);

datamachine_daily_memory_store_seam_assert( in_array( 'daily/2026/04/28.md', $filenames, true ), 'append/read use daily/YYYY/MM/DD.md filename for first date' );
datamachine_daily_memory_store_seam_assert( in_array( 'daily/2026/04/29.md', $filenames, true ), 'write uses daily/YYYY/MM/DD.md filename for second date' );
datamachine_daily_memory_store_seam_assert( in_array( 'list_subtree', $store->operations, true ), 'list_all calls list_subtree on the active memory store' );
datamachine_daily_memory_store_seam_assert( array( 'daily' ) === $store->list_prefixes, 'list_all enumerates the daily subtree prefix' );

foreach ( $store->scopes as $scope ) {
	datamachine_daily_memory_store_seam_assert( MemoryFileRegistry::LAYER_AGENT === $scope->layer, 'daily memory scope uses the agent layer' );
	datamachine_daily_memory_store_seam_assert( 123 === $scope->user_id, 'daily memory scope preserves effective user id' );
	datamachine_daily_memory_store_seam_assert( 456 === $scope->agent_id, 'daily memory scope preserves agent id' );
}

echo "Daily memory store seam smoke passed.\n";
