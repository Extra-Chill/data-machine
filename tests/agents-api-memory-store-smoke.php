<?php
/**
 * Pure-PHP smoke test for the Agents API memory store contract (#1637).
 *
 * Run with: php tests/agents-api-memory-store-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $value ): string {
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
		return trim( (string) $value, '-' );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $value ): string {
		return basename( $value );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $_hook, callable $_callback, int $_priority = 10, int $_accepted_args = 1 ): void {}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $_hook, ...$_args ): void {}
}

require_once __DIR__ . '/../agents-api/agents-api.php';

use DataMachine\Core\FilesRepository\AgentMemoryListEntry;
use DataMachine\Core\FilesRepository\AgentMemoryReadResult;
use DataMachine\Core\FilesRepository\AgentMemoryScope;
use DataMachine\Core\FilesRepository\AgentMemoryStoreInterface;
use DataMachine\Core\FilesRepository\AgentMemoryWriteResult;

$failures = array();
$passes   = 0;

function agents_api_memory_assert( bool $condition, string $name ): void {
	global $failures, $passes;

	if ( $condition ) {
		++$passes;
		echo "  PASS {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  FAIL {$name}\n";
}

function agents_api_memory_assert_same( $expected, $actual, string $name ): void {
	global $failures, $passes;

	if ( $expected === $actual ) {
		++$passes;
		echo "  PASS {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  FAIL {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

class AgentsApiMemoryFakeStore implements AgentMemoryStoreInterface {

	/** @var array<string, string> */
	private array $records = array();

	public function read( AgentMemoryScope $scope ): AgentMemoryReadResult {
		$key = $scope->key();

		if ( ! array_key_exists( $key, $this->records ) ) {
			return AgentMemoryReadResult::not_found();
		}

		$content = $this->records[ $key ];
		return new AgentMemoryReadResult( true, $content, sha1( $content ), strlen( $content ), 123 );
	}

	public function write( AgentMemoryScope $scope, string $content, ?string $if_match = null ): AgentMemoryWriteResult {
		$current = $this->read( $scope );
		if ( null !== $if_match && $current->hash !== $if_match ) {
			return AgentMemoryWriteResult::failure( 'conflict' );
		}

		$this->records[ $scope->key() ] = $content;
		return AgentMemoryWriteResult::ok( sha1( $content ), strlen( $content ) );
	}

	public function exists( AgentMemoryScope $scope ): bool {
		return array_key_exists( $scope->key(), $this->records );
	}

	public function delete( AgentMemoryScope $scope ): AgentMemoryWriteResult {
		unset( $this->records[ $scope->key() ] );
		return AgentMemoryWriteResult::ok( '', 0 );
	}

	public function list_layer( AgentMemoryScope $scope_query ): array {
		return $this->list_subtree( $scope_query, '' );
	}

	public function list_subtree( AgentMemoryScope $scope_query, string $prefix ): array {
		$entries = array();
		$prefix  = trim( $prefix, '/' );

		foreach ( $this->records as $key => $content ) {
			list( $layer, $user_id, $agent_id, $filename ) = explode( ':', $key, 4 );
			if ( $layer !== $scope_query->layer || (int) $user_id !== $scope_query->user_id || (int) $agent_id !== $scope_query->agent_id ) {
				continue;
			}

			if ( '' !== $prefix && ! str_starts_with( $filename, $prefix . '/' ) ) {
				continue;
			}

			$entries[] = new AgentMemoryListEntry( $filename, $layer, strlen( $content ), 123 );
		}

		return $entries;
	}
}

echo "agents-api-memory-store-smoke\n";

echo "\n[1] Module bootstrap exposes memory contracts without Data Machine product runtime:\n";
agents_api_memory_assert( class_exists( AgentMemoryScope::class ), 'AgentMemoryScope is available' );
agents_api_memory_assert( class_exists( AgentMemoryReadResult::class ), 'AgentMemoryReadResult is available' );
agents_api_memory_assert( class_exists( AgentMemoryWriteResult::class ), 'AgentMemoryWriteResult is available' );
agents_api_memory_assert( class_exists( AgentMemoryListEntry::class ), 'AgentMemoryListEntry is available' );
agents_api_memory_assert( interface_exists( AgentMemoryStoreInterface::class ), 'AgentMemoryStoreInterface is available' );
agents_api_memory_assert( ! class_exists( 'DataMachine\Core\FilesRepository\DiskAgentMemoryStore', false ), 'DiskAgentMemoryStore is not loaded by agents-api bootstrap' );
agents_api_memory_assert( ! class_exists( 'DataMachine\Core\FilesRepository\AgentMemoryStoreFactory', false ), 'Data Machine memory factory is not loaded by agents-api bootstrap' );

echo "\n[2] Fake store satisfies the contract shape in isolation:\n";
$store = new AgentsApiMemoryFakeStore();
$scope = new AgentMemoryScope( 'agent', 7, 42, 'MEMORY.md' );

$missing = $store->read( $scope );
agents_api_memory_assert_same( false, $missing->exists, 'missing read returns not-found sentinel' );
agents_api_memory_assert_same( 'agent:7:42:MEMORY.md', $scope->key(), 'scope exposes stable key' );

$write = $store->write( $scope, "First memory\n" );
agents_api_memory_assert_same( true, $write->success, 'write succeeds' );
agents_api_memory_assert_same( sha1( "First memory\n" ), $write->hash, 'write returns content hash' );
agents_api_memory_assert_same( true, $store->exists( $scope ), 'exists reflects write' );

$read = $store->read( $scope );
agents_api_memory_assert_same( true, $read->exists, 'read sees stored content' );
agents_api_memory_assert_same( "First memory\n", $read->content, 'read content round-trips' );
agents_api_memory_assert_same( strlen( "First memory\n" ), $read->bytes, 'read byte count is stable' );

$conflict = $store->write( $scope, "Second memory\n", 'stale-hash' );
agents_api_memory_assert_same( false, $conflict->success, 'compare-and-swap conflict fails' );
agents_api_memory_assert_same( 'conflict', $conflict->error, 'compare-and-swap conflict code is stable' );

$cas_write = $store->write( $scope, "Second memory\n", $read->hash );
agents_api_memory_assert_same( true, $cas_write->success, 'compare-and-swap write succeeds with matching hash' );

$daily_scope = new AgentMemoryScope( 'agent', 7, 42, 'daily/2026/04/17.md' );
$store->write( $daily_scope, "Daily memory\n" );

$layer_entries = $store->list_layer( new AgentMemoryScope( 'agent', 7, 42, '' ) );
agents_api_memory_assert_same( array( 'MEMORY.md', 'daily/2026/04/17.md' ), array_map( static fn( AgentMemoryListEntry $entry ): string => $entry->filename, $layer_entries ), 'layer list returns scoped entries' );

$subtree_entries = $store->list_subtree( new AgentMemoryScope( 'agent', 7, 42, '' ), 'daily' );
agents_api_memory_assert_same( array( 'daily/2026/04/17.md' ), array_map( static fn( AgentMemoryListEntry $entry ): string => $entry->filename, $subtree_entries ), 'subtree list filters by prefix' );

$delete = $store->delete( $scope );
agents_api_memory_assert_same( true, $delete->success, 'delete succeeds' );
agents_api_memory_assert_same( false, $store->exists( $scope ), 'exists reflects delete' );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " Agents API memory assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} Agents API memory assertions passed.\n";
