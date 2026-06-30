<?php
/**
 * Pure-PHP smoke test for DailyMemory's unified memory-store seam.
 *
 * Verifies that the default DailyMemory implementation addresses daily
 * entries as agent-layer files under daily/YYYY/MM/DD.md through the active
 * WP_Agent_Memory_Store. The older datamachine_daily_memory_storage seam
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
require_once __DIR__ . '/agents-api-loader.php';
datamachine_tests_require_agents_api();
require_once __DIR__ . '/../inc/Core/Workspace/WordPressWorkspaceScope.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/DiskAgentMemoryStore.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemoryStoreFactory.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemory.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/DailyMemoryStorage.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/DailyMemory.php';

use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_List_Entry;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Metadata;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Query;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Read_Result;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store_Capabilities;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Write_Result;
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Engine\AI\MemoryFileRegistry;

class DailyMemorySeamFakeStore implements WP_Agent_Memory_Store {
	/** @var array<string, string> */
	public array $files = array();

	/** @var string[] */
	public array $operations = array();

	/** @var array<string, WP_Agent_Memory_Scope> */
	public array $file_scopes = array();

	/** @var WP_Agent_Memory_Scope[] */
	public array $scopes = array();

	/** @var string[] */
	public array $list_prefixes = array();

	public function capabilities(): WP_Agent_Memory_Store_Capabilities {
		return WP_Agent_Memory_Store_Capabilities::none();
	}

	public function read( WP_Agent_Memory_Scope $scope, array $metadata_fields = WP_Agent_Memory_Metadata::FIELDS ): WP_Agent_Memory_Read_Result {
		unset( $metadata_fields );
		$this->record( 'read', $scope );

		if ( ! array_key_exists( $scope->key(), $this->files ) ) {
			return WP_Agent_Memory_Read_Result::not_found();
		}

		$content = $this->files[ $scope->key() ];
		return new WP_Agent_Memory_Read_Result( true, $content, sha1( $content ), strlen( $content ), 123 );
	}

	public function write( WP_Agent_Memory_Scope $scope, string $content, ?string $if_match = null, ?WP_Agent_Memory_Metadata $metadata = null ): WP_Agent_Memory_Write_Result {
		unset( $metadata );
		$this->record( 'write', $scope );
		$this->files[ $scope->key() ] = $content;
		$this->file_scopes[ $scope->key() ] = $scope;

		return WP_Agent_Memory_Write_Result::ok( sha1( $content ), strlen( $content ) );
	}

	public function exists( WP_Agent_Memory_Scope $scope ): bool {
		$this->record( 'exists', $scope );
		return array_key_exists( $scope->key(), $this->files );
	}

	public function delete( WP_Agent_Memory_Scope $scope ): WP_Agent_Memory_Write_Result {
		$this->record( 'delete', $scope );
		unset( $this->files[ $scope->key() ] );
		unset( $this->file_scopes[ $scope->key() ] );

		return WP_Agent_Memory_Write_Result::ok( '', 0 );
	}

	public function list_layer( WP_Agent_Memory_Scope $scope_query, ?WP_Agent_Memory_Query $query = null ): array {
		unset( $query );
		$this->record( 'list_layer', $scope_query );
		return array();
	}

	public function list_subtree( WP_Agent_Memory_Scope $scope_query, string $prefix, ?WP_Agent_Memory_Query $query = null ): array {
		unset( $query );
		$this->record( 'list_subtree', $scope_query );
		$this->list_prefixes[] = $prefix;

		$entries = array();
		foreach ( $this->files as $key => $content ) {
			$scope = $this->file_scopes[ $key ] ?? null;
			if ( ! $scope instanceof WP_Agent_Memory_Scope ) {
				continue;
			}
			if ( $scope->layer !== $scope_query->layer || $scope->user_id !== $scope_query->user_id || $scope->agent_id !== $scope_query->agent_id ) {
				continue;
			}
			if ( 0 !== strpos( $scope->filename, $prefix . '/' ) ) {
				continue;
			}

			$entries[] = new WP_Agent_Memory_List_Entry( $scope->filename, $scope->layer, strlen( $content ), 123 );
		}

		return $entries;
	}

	private function record( string $operation, WP_Agent_Memory_Scope $scope ): void {
		$this->operations[] = $operation;
		$this->scopes[]     = $scope;
	}
}

function datamachine_daily_memory_store_seam_assert( bool $condition, string $message ): void {
	static $assertions = 0;
	++$assertions;

	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "Assertion failed: {$message}\n" );
		exit( 1 );
	}

	echo "ok {$assertions} - {$message}\n";
}

$store = new DailyMemorySeamFakeStore();
add_filter(
	'wp_agent_memory_store',
	function ( $default, array $context ) use ( $store ) {
		unset( $default, $context );
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
	static fn( WP_Agent_Memory_Scope $scope ): string => $scope->filename,
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
