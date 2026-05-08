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

require_once __DIR__ . '/agents-api-loader.php';
datamachine_tests_require_agents_api();

use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_List_Entry;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Metadata;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Query;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Read_Result;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store_Capabilities;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Write_Result;

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

/**
 * Keep the final failure gate opaque to static analysis; the point of this
 * smoke is runtime assertion output, not compile-time proof of every branch.
 *
 * @param string[] $failures Assertion failure names.
 * @return bool
 */
function agents_api_memory_has_failures( array $failures ): bool {
	return count( $failures ) > 0;
}

class AgentsApiMemoryFakeStore implements WP_Agent_Memory_Store {

	/** @var array<string, string> */
	private array $records = array();

	public function capabilities(): WP_Agent_Memory_Store_Capabilities {
		return WP_Agent_Memory_Store_Capabilities::none();
	}

	public function read( WP_Agent_Memory_Scope $scope, array $metadata_fields = WP_Agent_Memory_Metadata::FIELDS ): WP_Agent_Memory_Read_Result {
		$key = $scope->key();

		if ( ! array_key_exists( $key, $this->records ) ) {
			return WP_Agent_Memory_Read_Result::not_found();
		}

		$content = $this->records[ $key ];
		return new WP_Agent_Memory_Read_Result( true, $content, sha1( $content ), strlen( $content ), 123, null, $metadata_fields );
	}

	public function write( WP_Agent_Memory_Scope $scope, string $content, ?string $if_match = null, ?WP_Agent_Memory_Metadata $metadata = null ): WP_Agent_Memory_Write_Result {
		unset( $metadata );
		$current = $this->read( $scope );
		if ( null !== $if_match && $current->hash !== $if_match ) {
			return WP_Agent_Memory_Write_Result::failure( 'conflict' );
		}

		$this->records[ $scope->key() ] = $content;
		return WP_Agent_Memory_Write_Result::ok( sha1( $content ), strlen( $content ) );
	}

	public function exists( WP_Agent_Memory_Scope $scope ): bool {
		return array_key_exists( $scope->key(), $this->records );
	}

	public function delete( WP_Agent_Memory_Scope $scope ): WP_Agent_Memory_Write_Result {
		unset( $this->records[ $scope->key() ] );
		return WP_Agent_Memory_Write_Result::ok( '', 0 );
	}

	public function list_layer( WP_Agent_Memory_Scope $scope_query, ?WP_Agent_Memory_Query $query = null ): array {
		unset( $query );
		return $this->list_subtree( $scope_query, '' );
	}

	public function list_subtree( WP_Agent_Memory_Scope $scope_query, string $prefix, ?WP_Agent_Memory_Query $query = null ): array {
		unset( $query );
		$entries = array();
		$prefix  = trim( $prefix, '/' );

		foreach ( $this->records as $key => $content ) {
			$parts = explode( ':', $key );
			if ( count( $parts ) < 6 ) {
				continue;
			}

			$layer          = array_shift( $parts );
			$workspace_type = array_shift( $parts );
			$filename       = (string) array_pop( $parts );
			$agent_id       = (int) array_pop( $parts );
			$user_id        = (int) array_pop( $parts );
			$workspace_id   = implode( ':', $parts );
			if ( $layer !== $scope_query->layer || $workspace_type !== $scope_query->workspace_type || $workspace_id !== $scope_query->workspace_id || (int) $user_id !== $scope_query->user_id || (int) $agent_id !== $scope_query->agent_id ) {
				continue;
			}

			if ( '' !== $prefix && ! str_starts_with( $filename, $prefix . '/' ) ) {
				continue;
			}

			$entries[] = new WP_Agent_Memory_List_Entry( $filename, $layer, strlen( $content ), 123 );
		}

		return $entries;
	}
}

echo "agents-api-memory-store-smoke\n";

echo "\n[1] Module bootstrap exposes memory contracts without Data Machine product runtime:\n";
agents_api_memory_assert( class_exists( WP_Agent_Memory_Scope::class ), 'WP_Agent_Memory_Scope is available' );
agents_api_memory_assert( class_exists( WP_Agent_Memory_Read_Result::class ), 'WP_Agent_Memory_Read_Result is available' );
agents_api_memory_assert( class_exists( WP_Agent_Memory_Write_Result::class ), 'WP_Agent_Memory_Write_Result is available' );
agents_api_memory_assert( class_exists( WP_Agent_Memory_List_Entry::class ), 'WP_Agent_Memory_List_Entry is available' );
agents_api_memory_assert( interface_exists( WP_Agent_Memory_Store::class ), 'WP_Agent_Memory_Store is available' );
agents_api_memory_assert( ! class_exists( 'DataMachine\Core\FilesRepository\DiskAgentMemoryStore', false ), 'DiskAgentMemoryStore is not loaded by agents-api bootstrap' );
agents_api_memory_assert( ! class_exists( 'DataMachine\Core\FilesRepository\AgentMemoryStoreFactory', false ), 'Data Machine memory factory is not loaded by agents-api bootstrap' );

echo "\n[2] Fake store satisfies the contract shape in isolation:\n";
$store = new AgentsApiMemoryFakeStore();
$scope = new WP_Agent_Memory_Scope( 'agent', 'site', 'https://example.test', 7, 42, 'MEMORY.md' );

$missing = $store->read( $scope );
agents_api_memory_assert_same( false, $missing->exists, 'missing read returns not-found sentinel' );
agents_api_memory_assert_same( 'agent:site:https://example.test:7:42:MEMORY.md', $scope->key(), 'scope exposes stable key' );

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

$daily_scope = new WP_Agent_Memory_Scope( 'agent', 'site', 'https://example.test', 7, 42, 'daily/2026/04/17.md' );
$store->write( $daily_scope, "Daily memory\n" );

$layer_entries = $store->list_layer( new WP_Agent_Memory_Scope( 'agent', 'site', 'https://example.test', 7, 42, '' ) );
agents_api_memory_assert_same( array( 'MEMORY.md', 'daily/2026/04/17.md' ), array_map( static fn( WP_Agent_Memory_List_Entry $entry ): string => $entry->filename, $layer_entries ), 'layer list returns scoped entries' );

$subtree_entries = $store->list_subtree( new WP_Agent_Memory_Scope( 'agent', 'site', 'https://example.test', 7, 42, '' ), 'daily' );
agents_api_memory_assert_same( array( 'daily/2026/04/17.md' ), array_map( static fn( WP_Agent_Memory_List_Entry $entry ): string => $entry->filename, $subtree_entries ), 'subtree list filters by prefix' );

$delete = $store->delete( $scope );
agents_api_memory_assert_same( true, $delete->success, 'delete succeeds' );
agents_api_memory_assert_same( false, $store->exists( $scope ), 'exists reflects delete' );

if ( agents_api_memory_has_failures( $failures ) ) {
	echo "\nFAILED: " . count( $failures ) . " Agents API memory assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} Agents API memory assertions passed.\n";
