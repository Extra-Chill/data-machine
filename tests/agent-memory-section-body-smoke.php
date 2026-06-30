<?php
/**
 * Pure-PHP smoke test for opaque agent memory section bodies (#1978).
 *
 * Run with: php tests/agent-memory-section-body-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

$GLOBALS['datamachine_agent_memory_section_filters'] = array();

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $filename ): string {
		return preg_replace( '/[^a-zA-Z0-9._\/\-]/', '', $filename );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $value ): string {
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
		return trim( (string) $value, '-' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/' ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $_path = '/' ): string {
		unset( $_path );
		return 'https://example.test';
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		return array( 'basedir' => sys_get_temp_dir() );
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( int $bytes ): string {
		return $bytes . ' B';
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['datamachine_agent_memory_section_filters'][ $hook ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		);
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$filters = $GLOBALS['datamachine_agent_memory_section_filters'][ $hook ] ?? array();
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
	function do_action( string $_hook, ...$_args ): void {}
}

require_once __DIR__ . '/../inc/Engine/AI/MemoryFileRegistry.php';
require_once __DIR__ . '/agents-api-loader.php';
datamachine_tests_require_agents_api();
require_once __DIR__ . '/../inc/Core/Workspace/WordPressWorkspaceScope.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/DirectoryManager.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/DiskAgentMemoryStore.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemoryStoreFactory.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemory.php';

use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_List_Entry;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Metadata;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Query;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Read_Result;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store_Capabilities;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Write_Result;
use DataMachine\Core\FilesRepository\AgentMemory;

class AgentMemorySectionBodyFakeStore implements WP_Agent_Memory_Store {

	/** @var array<string, string> */
	public array $files = array();

	public function capabilities(): WP_Agent_Memory_Store_Capabilities {
		return WP_Agent_Memory_Store_Capabilities::none();
	}

	public function read( WP_Agent_Memory_Scope $scope, array $metadata_fields = WP_Agent_Memory_Metadata::FIELDS ): WP_Agent_Memory_Read_Result {
		if ( ! array_key_exists( $scope->key(), $this->files ) ) {
			return WP_Agent_Memory_Read_Result::not_found();
		}

		$content = $this->files[ $scope->key() ];
		return new WP_Agent_Memory_Read_Result( true, $content, sha1( $content ), strlen( $content ), 123, null, $metadata_fields );
	}

	public function write( WP_Agent_Memory_Scope $scope, string $content, ?string $if_match = null, ?WP_Agent_Memory_Metadata $metadata = null ): WP_Agent_Memory_Write_Result {
		unset( $metadata );
		$current = $this->read( $scope );
		if ( null !== $if_match && $current->hash !== $if_match ) {
			return WP_Agent_Memory_Write_Result::failure( 'conflict' );
		}

		$this->files[ $scope->key() ] = $content;
		return WP_Agent_Memory_Write_Result::ok( sha1( $content ), strlen( $content ) );
	}

	public function exists( WP_Agent_Memory_Scope $scope ): bool {
		return array_key_exists( $scope->key(), $this->files );
	}

	public function delete( WP_Agent_Memory_Scope $scope ): WP_Agent_Memory_Write_Result {
		unset( $this->files[ $scope->key() ] );
		return WP_Agent_Memory_Write_Result::ok( '', 0 );
	}

	public function list_layer( WP_Agent_Memory_Scope $_scope_query, ?WP_Agent_Memory_Query $query = null ): array {
		unset( $_scope_query, $query );
		return array();
	}

	public function list_subtree( WP_Agent_Memory_Scope $_scope_query, string $_prefix, ?WP_Agent_Memory_Query $query = null ): array {
		unset( $_scope_query, $_prefix, $query );
		return array();
	}
}

function datamachine_agent_memory_section_assert( bool $condition, string $message ): void {
	static $assertions = 0;
	++$assertions;

	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "Assertion failed: {$message}\n" );
		exit( 1 );
	}

	echo "ok {$assertions} - {$message}\n";
}

$store = new AgentMemorySectionBodyFakeStore();
add_filter(
	'wp_agent_memory_store',
	function ( $_default, array $_context ) use ( $store ) {
		unset( $_default, $_context );
		return $store;
	},
	10,
	2
);

$memory = new AgentMemory( 7, 42, 'MEMORY.md', 'agent' );
$body   = "Overview line\n\n## Stack\n- WordPress\n\n## Network options\n- Multisite";

$memory->replace_all( "# MEMORY.md\n" );

$write = $memory->set_section( 'Transcribe Pipeline', $body );
datamachine_agent_memory_section_assert( true === $write['success'], 'section write with nested headings succeeds' );

$sections = $memory->get_sections();
datamachine_agent_memory_section_assert( array( 'Transcribe Pipeline' ) === $sections['sections'], 'nested body headings are not listed as sections' );

$read = $memory->get_section( 'Transcribe Pipeline' );
datamachine_agent_memory_section_assert( true === $read['success'], 'section read succeeds' );
datamachine_agent_memory_section_assert( $body === $read['content'], 'section read returns literal nested headings without markers' );

$append = $memory->append_to_section( 'Transcribe Pipeline', "\n## Runtime\n- CLI" );
datamachine_agent_memory_section_assert( true === $append['success'], 'append with nested heading succeeds' );

$appended = $memory->get_section( 'Transcribe Pipeline' );
datamachine_agent_memory_section_assert( str_contains( $appended['content'], '## Runtime' ), 'append preserves nested heading as body content' );

$search = $memory->search( 'CLI' );
datamachine_agent_memory_section_assert( true === $search['success'], 'search succeeds' );
datamachine_agent_memory_section_assert( 'Transcribe Pipeline' === $search['matches'][0]['section'], 'search keeps matches in parent section' );

$delete = $memory->delete_section( 'Transcribe Pipeline' );
datamachine_agent_memory_section_assert( true === $delete['success'], 'section delete succeeds' );

$after_delete = $memory->get_sections();
datamachine_agent_memory_section_assert( array() === $after_delete['sections'], 'section delete removes the heading and body' );

echo "agent-memory-section-body-smoke complete\n";
