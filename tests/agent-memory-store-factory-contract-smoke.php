<?php
/**
 * Pure-PHP smoke tests for the agent memory store resolver contract.
 *
 * Verifies that Data Machine still has one active store seam, invalid filter
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
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemoryScope.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemoryReadResult.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemoryWriteResult.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemoryListEntry.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemoryStoreInterface.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/DiskAgentMemoryStore.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemoryStoreFactory.php';

use DataMachine\Core\FilesRepository\AgentMemoryListEntry;
use DataMachine\Core\FilesRepository\AgentMemoryReadResult;
use DataMachine\Core\FilesRepository\AgentMemoryScope;
use DataMachine\Core\FilesRepository\AgentMemoryStoreFactory;
use DataMachine\Core\FilesRepository\AgentMemoryStoreInterface;
use DataMachine\Core\FilesRepository\AgentMemoryWriteResult;
use DataMachine\Core\FilesRepository\DiskAgentMemoryStore;

class AgentMemoryStoreContractFakeStore implements AgentMemoryStoreInterface {
	/** @var array<string, string> */
	public array $files = array();

	/** @var AgentMemoryScope[] */
	public array $scopes = array();

	public function read( AgentMemoryScope $scope ): AgentMemoryReadResult {
		$this->scopes[] = $scope;
		if ( ! array_key_exists( $scope->key(), $this->files ) ) {
			return AgentMemoryReadResult::not_found();
		}

		$content = $this->files[ $scope->key() ];
		return new AgentMemoryReadResult( true, $content, sha1( $content ), strlen( $content ), 123 );
	}

	public function write( AgentMemoryScope $scope, string $content, ?string $_if_match = null ): AgentMemoryWriteResult {
		unset( $_if_match );
		$this->scopes[] = $scope;
		$this->files[ $scope->key() ] = $content;

		return AgentMemoryWriteResult::ok( sha1( $content ), strlen( $content ) );
	}

	public function exists( AgentMemoryScope $scope ): bool {
		$this->scopes[] = $scope;
		return array_key_exists( $scope->key(), $this->files );
	}

	public function delete( AgentMemoryScope $scope ): AgentMemoryWriteResult {
		$this->scopes[] = $scope;
		unset( $this->files[ $scope->key() ] );

		return AgentMemoryWriteResult::ok( '', 0 );
	}

	public function list_layer( AgentMemoryScope $scope_query ): array {
		$this->scopes[] = $scope_query;
		$entries        = array();

		foreach ( $this->files as $key => $content ) {
			[ $layer, $user_id, $agent_id, $filename ] = explode( ':', $key, 4 );
			if ( $layer !== $scope_query->layer || (int) $user_id !== $scope_query->user_id || (int) $agent_id !== $scope_query->agent_id ) {
				continue;
			}

			if ( false !== strpos( $filename, '/' ) ) {
				continue;
			}

			$entries[] = new AgentMemoryListEntry( $filename, $layer, strlen( $content ), 123 );
		}

		return $entries;
	}

	public function list_subtree( AgentMemoryScope $scope_query, string $prefix ): array {
		$this->scopes[] = $scope_query;
		unset( $prefix );
		return array();
	}
}

function datamachine_agent_memory_store_contract_assert( bool $condition, string $message ): void {
	static $assertions = 0;
	++$assertions;

	if ( ! $condition ) {
		fwrite( STDERR, "Assertion failed: {$message}\n" );
		exit( 1 );
	}

	echo "ok {$assertions} - {$message}\n";
}

function datamachine_agent_memory_store_contract_reset_filters(): void {
	$GLOBALS['datamachine_agent_memory_store_contract_filters'] = array();
}

function datamachine_agent_memory_store_contract_round_trip( AgentMemoryStoreInterface $store, AgentMemoryScope $scope ): AgentMemoryReadResult {
	$write = $store->write( $scope, "# Memory\n" );
	datamachine_agent_memory_store_contract_assert( true === $write->success, 'interface write succeeds without concrete-store branching' );

	return $store->read( $scope );
}

$scope = new AgentMemoryScope( 'agent', 7, 42, 'MEMORY.md' );

datamachine_agent_memory_store_contract_reset_filters();
$default_store = AgentMemoryStoreFactory::for_scope( $scope );
datamachine_agent_memory_store_contract_assert( $default_store instanceof DiskAgentMemoryStore, 'factory falls back to the disk store with no filter' );

datamachine_agent_memory_store_contract_reset_filters();
$fake_store       = new AgentMemoryStoreContractFakeStore();
$filter_arguments = array();
add_filter(
	'datamachine_memory_store',
	static function ( $store, AgentMemoryScope $filter_scope ) use ( $fake_store, &$filter_arguments ) {
		$filter_arguments[] = array( $store, $filter_scope );
		return $fake_store;
	},
	10,
	2
);

$selected_store = AgentMemoryStoreFactory::for_scope( $scope );
datamachine_agent_memory_store_contract_assert( $fake_store === $selected_store, 'factory selects a valid store from datamachine_memory_store' );
datamachine_agent_memory_store_contract_assert( 1 === count( $filter_arguments ), 'store filter is invoked once for one resolution' );
datamachine_agent_memory_store_contract_assert( null === $filter_arguments[0][0], 'store filter receives null as the default candidate' );
datamachine_agent_memory_store_contract_assert( $scope === $filter_arguments[0][1], 'store filter receives the scope being resolved' );

$read = datamachine_agent_memory_store_contract_round_trip( $selected_store, $scope );
datamachine_agent_memory_store_contract_assert( true === $read->exists, 'selected store returns content through the interface contract' );
datamachine_agent_memory_store_contract_assert( "# Memory\n" === $read->content, 'selected store preserves content through read/write round trip' );

datamachine_agent_memory_store_contract_reset_filters();
add_filter(
	'datamachine_memory_store',
	static fn( $_store, $_scope ) => new stdClass(),
	10,
	2
);
$invalid_store = AgentMemoryStoreFactory::for_scope( $scope );
datamachine_agent_memory_store_contract_assert( $invalid_store instanceof DiskAgentMemoryStore, 'factory ignores non-AgentMemoryStoreInterface filter returns' );

datamachine_agent_memory_store_contract_reset_filters();
add_filter(
	'agents_api_memory_store',
	static function ( $_store, $_scope ) use ( $fake_store ) {
		return $fake_store;
	},
	10,
	2
);
$future_filter_store = AgentMemoryStoreFactory::for_scope( $scope );
datamachine_agent_memory_store_contract_assert( $future_filter_store instanceof DiskAgentMemoryStore, 'undocumented future filter names are not active Data Machine behavior' );

echo "Agent memory store factory contract smoke passed.\n";
