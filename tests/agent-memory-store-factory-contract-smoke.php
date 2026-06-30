<?php
/**
 * Pure-PHP smoke tests for the agent memory store resolver contract.
 *
 * Verifies that Data Machine uses the canonical Agents API store seam, invalid filter
 * returns do not replace the default, and callers can operate against the
 * interface without knowing which backing store is active.
 */

define( 'ABSPATH', __DIR__ . '/../' );

$GLOBALS['datamachine_agent_memory_store_contract_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['datamachine_agent_memory_store_contract_filters'][ $hook ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		);
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$filters = $GLOBALS['datamachine_agent_memory_store_contract_filters'][ $hook ] ?? array();
		ksort( $filters );

		foreach ( $filters as $callbacks ) {
			foreach ( $callbacks as $filter ) {
				$value = $filter['callback']( ...array_slice( array_merge( array( $value ), $args ), 0, $filter['accepted_args'] ) );
			}
		}

		return $value;
	}
}

require_once __DIR__ . '/../inc/Engine/AI/MemoryFileRegistry.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/DirectoryManager.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/FilesystemHelper.php';
require_once __DIR__ . '/agents-api-loader.php';
datamachine_tests_require_agents_api();
require_once __DIR__ . '/../inc/Core/FilesRepository/DiskAgentMemoryStore.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemoryStoreFactory.php';

use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_List_Entry;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Metadata;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Query;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Read_Result;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store_Capabilities;
use DataMachine\Core\FilesRepository\AgentMemoryStoreFactory;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Write_Result;
use DataMachine\Core\FilesRepository\DiskAgentMemoryStore;

class AgentMemoryStoreContractFakeStore implements WP_Agent_Memory_Store {
	/** @var array<string, string> */
	public array $files = array();

	/** @var WP_Agent_Memory_Scope[] */
	public array $scopes = array();

	public function capabilities(): WP_Agent_Memory_Store_Capabilities {
		return WP_Agent_Memory_Store_Capabilities::none();
	}

	public function read( WP_Agent_Memory_Scope $scope, array $metadata_fields = WP_Agent_Memory_Metadata::FIELDS ): WP_Agent_Memory_Read_Result {
		$this->scopes[] = $scope;
		if ( ! array_key_exists( $scope->key(), $this->files ) ) {
			return WP_Agent_Memory_Read_Result::not_found();
		}

		$content = $this->files[ $scope->key() ];
		return new WP_Agent_Memory_Read_Result( true, $content, sha1( $content ), strlen( $content ), 123, null, $metadata_fields );
	}

	public function write( WP_Agent_Memory_Scope $scope, string $content, ?string $_if_match = null, ?WP_Agent_Memory_Metadata $metadata = null ): WP_Agent_Memory_Write_Result {
		unset( $_if_match, $metadata );
		$this->scopes[] = $scope;
		$this->files[ $scope->key() ] = $content;

		return WP_Agent_Memory_Write_Result::ok( sha1( $content ), strlen( $content ) );
	}

	public function exists( WP_Agent_Memory_Scope $scope ): bool {
		$this->scopes[] = $scope;
		return array_key_exists( $scope->key(), $this->files );
	}

	public function delete( WP_Agent_Memory_Scope $scope ): WP_Agent_Memory_Write_Result {
		$this->scopes[] = $scope;
		unset( $this->files[ $scope->key() ] );

		return WP_Agent_Memory_Write_Result::ok( '', 0 );
	}

	public function list_layer( WP_Agent_Memory_Scope $scope_query, ?WP_Agent_Memory_Query $query = null ): array {
		unset( $query );
		$this->scopes[] = $scope_query;
		$entries        = array();

		foreach ( $this->files as $key => $content ) {
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

			if ( false !== strpos( $filename, '/' ) ) {
				continue;
			}

			$entries[] = new WP_Agent_Memory_List_Entry( $filename, $layer, strlen( $content ), 123 );
		}

		return $entries;
	}

	public function list_subtree( WP_Agent_Memory_Scope $scope_query, string $prefix, ?WP_Agent_Memory_Query $query = null ): array {
		unset( $query );
		$this->scopes[] = $scope_query;
		unset( $prefix, $query );
		return array();
	}
}

function datamachine_agent_memory_store_contract_assert( bool $condition, string $message ): void {
	static $assertions = 0;
	++$assertions;

	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "Assertion failed: {$message}\n" );
		exit( 1 );
	}

	echo "ok {$assertions} - {$message}\n";
}

function datamachine_agent_memory_store_contract_reset_filters(): void {
	$GLOBALS['datamachine_agent_memory_store_contract_filters'] = array();
}

function datamachine_agent_memory_store_contract_round_trip( WP_Agent_Memory_Store $store, WP_Agent_Memory_Scope $scope ): WP_Agent_Memory_Read_Result {
	$write = $store->write( $scope, "# Memory\n" );
	datamachine_agent_memory_store_contract_assert( true === $write->success, 'interface write succeeds without concrete-store branching' );

	return $store->read( $scope );
}

$scope = new WP_Agent_Memory_Scope( 'agent', 'site', 'https://example.test', 7, 42, 'MEMORY.md' );

datamachine_agent_memory_store_contract_reset_filters();
$default_store = AgentMemoryStoreFactory::for_scope( $scope );
datamachine_agent_memory_store_contract_assert( $default_store instanceof DiskAgentMemoryStore, 'factory falls back to the disk store with no filter' );

datamachine_agent_memory_store_contract_reset_filters();
$fake_store       = new AgentMemoryStoreContractFakeStore();
$filter_arguments = array();
add_filter(
	'wp_agent_memory_store',
	static function ( $store, array $context ) use ( $fake_store, &$filter_arguments ) {
		$filter_arguments[] = array( $store, $context );
		return $fake_store;
	},
	10,
	2
);

$selected_store = AgentMemoryStoreFactory::for_scope( $scope );
datamachine_agent_memory_store_contract_assert( $fake_store === $selected_store, 'factory selects a valid store from wp_agent_memory_store' );
datamachine_agent_memory_store_contract_assert( 1 === count( $filter_arguments ), 'store filter is invoked once for one resolution' );
datamachine_agent_memory_store_contract_assert( null === $filter_arguments[0][0], 'store filter receives null as the default candidate' );
datamachine_agent_memory_store_contract_assert( $scope === ( $filter_arguments[0][1]['scope'] ?? null ), 'store filter context receives the scope being resolved' );

$read = datamachine_agent_memory_store_contract_round_trip( $selected_store, $scope );
datamachine_agent_memory_store_contract_assert( true === $read->exists, 'selected store returns content through the interface contract' );
datamachine_agent_memory_store_contract_assert( "# Memory\n" === $read->content, 'selected store preserves content through read/write round trip' );

datamachine_agent_memory_store_contract_reset_filters();
add_filter(
	'wp_agent_memory_store',
	static fn( $_store, $_scope ) => new stdClass(),
	10,
	2
);
$invalid_store = AgentMemoryStoreFactory::for_scope( $scope );
datamachine_agent_memory_store_contract_assert( $invalid_store instanceof DiskAgentMemoryStore, 'factory ignores non-WP_Agent_Memory_Store filter returns' );

datamachine_agent_memory_store_contract_reset_filters();
add_filter(
	'agents_api_memory_store',
	static function ( $_store, $_scope ) use ( $fake_store ) {
		return $fake_store;
	},
	10,
	2
);
$previous_agents_api_filter_store = AgentMemoryStoreFactory::for_scope( $scope );
datamachine_agent_memory_store_contract_assert( $previous_agents_api_filter_store instanceof DiskAgentMemoryStore, 'previous agents_api_memory_store filter is not mirrored as a runtime alias' );

datamachine_agent_memory_store_contract_reset_filters();
add_filter(
	'datamachine_memory_store',
	static function ( $_store, $_scope ) use ( $fake_store ) {
		return $fake_store;
	},
	10,
	2
);
$old_filter_store = AgentMemoryStoreFactory::for_scope( $scope );
datamachine_agent_memory_store_contract_assert( $old_filter_store instanceof DiskAgentMemoryStore, 'old datamachine_memory_store filter is not mirrored as a runtime alias' );

echo "Agent memory store factory contract smoke passed.\n";
