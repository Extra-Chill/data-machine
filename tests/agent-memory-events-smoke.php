<?php
/**
 * Pure-PHP smoke tests for agent memory/guideline change events.
 *
 * Run with: php tests/agent-memory-events-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

$GLOBALS['datamachine_agent_memory_events_filters']      = array();
$GLOBALS['datamachine_agent_memory_events_actions']      = array();
$GLOBALS['datamachine_agent_memory_events_post_types']   = array();
$GLOBALS['datamachine_agent_memory_events_taxonomies']   = array();
$GLOBALS['datamachine_agent_memory_events_posts']        = array();
$GLOBALS['datamachine_agent_memory_events_post_meta']    = array();
$GLOBALS['datamachine_agent_memory_events_next_post_id'] = 100;

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $filename ): string {
		return preg_replace( '/[^a-zA-Z0-9._\/-]/', '', $filename );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['datamachine_agent_memory_events_filters'][ $hook ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		);
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$filters = $GLOBALS['datamachine_agent_memory_events_filters'][ $hook ] ?? array();
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
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['datamachine_agent_memory_events_actions'][] = array(
			'hook' => $hook,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( int $bytes ): string {
		return $bytes . ' B';
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( string $post_type ): bool {
		return in_array( $post_type, $GLOBALS['datamachine_agent_memory_events_post_types'], true );
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( string $taxonomy ): bool {
		return in_array( $taxonomy, $GLOBALS['datamachine_agent_memory_events_taxonomies'], true );
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {

		public int $ID;
		public string $post_content      = '';
		public string $post_modified_gmt = '2026-04-28 00:00:00';
		public string $post_name         = '';

		public function __construct( array $args ) {
			foreach ( $args as $key => $value ) {
				$this->{$key} = $value;
			}
		}
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args ): array {
		$name = $args['name'] ?? '';
		foreach ( $GLOBALS['datamachine_agent_memory_events_posts'] as $post ) {
			if ( $post instanceof WP_Post && $post->post_name === $name ) {
				return array( $post );
			}
		}

		return array();
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {

		public array $posts = array();

		public function __construct( array $_args ) {
			unset( $_args );
			$this->posts = array_values( $GLOBALS['datamachine_agent_memory_events_posts'] );
		}
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $_single = false ) {
		unset( $_single );
		return $GLOBALS['datamachine_agent_memory_events_post_meta'][ $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, $value ): bool {
		$GLOBALS['datamachine_agent_memory_events_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $postarr, bool $_wp_error = false ): int {
		unset( $_wp_error );
		$post_id = $GLOBALS['datamachine_agent_memory_events_next_post_id']++;
		$post    = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_content'      => (string) ( $postarr['post_content'] ?? '' ),
				'post_modified_gmt' => '2026-04-28 00:00:00',
				'post_name'         => (string) ( $postarr['post_name'] ?? '' ),
			)
		);

		$GLOBALS['datamachine_agent_memory_events_posts'][ $post_id ] = $post;

		foreach ( $postarr['meta_input'] ?? array() as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array $postarr, bool $_wp_error = false ): int {
		unset( $_wp_error );
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		if ( ! isset( $GLOBALS['datamachine_agent_memory_events_posts'][ $post_id ] ) ) {
			return 0;
		}

		$post               = $GLOBALS['datamachine_agent_memory_events_posts'][ $post_id ];
		$post->post_content = (string) ( $postarr['post_content'] ?? $post->post_content );
		return $post_id;
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( int $post_id, bool $_force_delete = false ) {
		unset( $_force_delete );
		unset( $GLOBALS['datamachine_agent_memory_events_posts'][ $post_id ] );
		return true;
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( int $_object_id, array $terms, string $_taxonomy, bool $_append = false ): array {
		unset( $_object_id, $_taxonomy, $_append );
		return $terms;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $_thing ): bool {
		unset( $_thing );
		return false;
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
require_once __DIR__ . '/../inc/Core/FilesRepository/GuidelineAgentMemoryStore.php';

use DataMachine\Core\FilesRepository\AgentMemory;
use DataMachine\Core\FilesRepository\AgentMemoryListEntry;
use DataMachine\Core\FilesRepository\AgentMemoryReadResult;
use DataMachine\Core\FilesRepository\AgentMemoryScope;
use DataMachine\Core\FilesRepository\AgentMemoryStoreInterface;
use DataMachine\Core\FilesRepository\AgentMemoryWriteResult;
use DataMachine\Core\FilesRepository\GuidelineAgentMemoryStore;

class AgentMemoryEventsFakeStore implements AgentMemoryStoreInterface {

	/**
	 * @var array<string, string>
	 */
	public array $files = array();

	public bool $fail_next_write  = false;
	public bool $fail_next_delete = false;

	public function read( AgentMemoryScope $scope ): AgentMemoryReadResult {
		if ( ! array_key_exists( $scope->key(), $this->files ) ) {
			return AgentMemoryReadResult::not_found();
		}

		$content = $this->files[ $scope->key() ];
		return new AgentMemoryReadResult( true, $content, sha1( $content ), strlen( $content ), 123 );
	}

	public function write( AgentMemoryScope $scope, string $content, ?string $_if_match = null ): AgentMemoryWriteResult {
		unset( $_if_match );
		if ( $this->fail_next_write ) {
			$this->fail_next_write = false;
			return AgentMemoryWriteResult::failure( 'io' );
		}

		$this->files[ $scope->key() ] = $content;
		return AgentMemoryWriteResult::ok( sha1( $content ), strlen( $content ) );
	}

	public function exists( AgentMemoryScope $scope ): bool {
		return array_key_exists( $scope->key(), $this->files );
	}

	public function delete( AgentMemoryScope $scope ): AgentMemoryWriteResult {
		if ( $this->fail_next_delete ) {
			$this->fail_next_delete = false;
			return AgentMemoryWriteResult::failure( 'io' );
		}

		unset( $this->files[ $scope->key() ] );
		return AgentMemoryWriteResult::ok( '', 0 );
	}

	public function list_layer( AgentMemoryScope $_scope_query ): array {
		unset( $_scope_query );
		return array();
	}

	public function list_subtree( AgentMemoryScope $_scope_query, string $_prefix ): array {
		unset( $_scope_query, $_prefix );
		return array();
	}
}

function datamachine_agent_memory_events_assert( bool $condition, string $message ): void {
	static $assertions = 0;
	++$assertions;

	if ( ! $condition ) {
		fwrite( STDERR, "Assertion failed: {$message}\n" );
		exit( 1 );
	}

	echo "ok {$assertions} - {$message}\n";
}

function datamachine_agent_memory_events_matching( string $hook ): array {
	return array_values(
		array_filter(
			$GLOBALS['datamachine_agent_memory_events_actions'],
			static fn( array $event ): bool => $event['hook'] === $hook
		)
	);
}

$store = new AgentMemoryEventsFakeStore();
add_filter(
	'agents_api_memory_store',
	function ( $_default, AgentMemoryScope $_scope ) use ( $store ) {
		unset( $_default, $_scope );
		return $store;
	},
	10,
	2
);

$memory = new AgentMemory( 7, 42, 'MEMORY.md', 'agent' );

$GLOBALS['datamachine_agent_memory_events_actions'] = array();
$write = $memory->replace_all( "# Memory\n" );
datamachine_agent_memory_events_assert( true === $write['success'], 'replace_all succeeds through the fake store' );

$updates = datamachine_agent_memory_events_matching( 'datamachine_agent_memory_updated' );
datamachine_agent_memory_events_assert( 1 === count( $updates ), 'successful write emits one memory update event' );
datamachine_agent_memory_events_assert( $updates[0]['args'][0] instanceof AgentMemoryScope, 'update event includes AgentMemoryScope argument' );
datamachine_agent_memory_events_assert( "# Memory\n" === $updates[0]['args'][1], 'update event includes persisted full content' );

$metadata = $updates[0]['args'][2];
datamachine_agent_memory_events_assert( 'agent' === $metadata['layer'], 'update metadata includes layer' );
datamachine_agent_memory_events_assert( 7 === $metadata['user_id'], 'update metadata includes user id' );
datamachine_agent_memory_events_assert( 42 === $metadata['agent_id'], 'update metadata includes agent id' );
datamachine_agent_memory_events_assert( 'MEMORY.md' === $metadata['filename'], 'update metadata includes filename' );
datamachine_agent_memory_events_assert( 'agent:7:42:MEMORY.md' === $metadata['key'], 'update metadata includes stable scope key' );
datamachine_agent_memory_events_assert( sha1( "# Memory\n" ) === $metadata['hash'], 'update metadata includes content hash' );
datamachine_agent_memory_events_assert( strlen( "# Memory\n" ) === $metadata['bytes'], 'update metadata includes byte count' );

$GLOBALS['datamachine_agent_memory_events_actions'] = array();
$store->fail_next_write                             = true;
$failed_write                                       = $memory->replace_all( 'ignored' );
datamachine_agent_memory_events_assert( false === $failed_write['success'], 'failed write reports failure' );
datamachine_agent_memory_events_assert( array() === datamachine_agent_memory_events_matching( 'datamachine_agent_memory_updated' ), 'failed write does not emit update event' );

$GLOBALS['datamachine_agent_memory_events_actions'] = array();
$delete = $memory->delete();
datamachine_agent_memory_events_assert( true === $delete['success'], 'delete succeeds through the fake store' );
$deletes = datamachine_agent_memory_events_matching( 'datamachine_agent_memory_deleted' );
datamachine_agent_memory_events_assert( 1 === count( $deletes ), 'successful delete emits one memory delete event' );
datamachine_agent_memory_events_assert( $deletes[0]['args'][0] instanceof AgentMemoryScope, 'delete event includes AgentMemoryScope argument' );
datamachine_agent_memory_events_assert( 'agent:7:42:MEMORY.md' === $deletes[0]['args'][0]->key(), 'delete event scope identifies deleted memory' );

$GLOBALS['datamachine_agent_memory_events_actions'] = array();
$store->fail_next_delete                            = true;
$failed_delete                                      = $memory->delete();
datamachine_agent_memory_events_assert( false === $failed_delete['success'], 'failed delete reports failure' );
datamachine_agent_memory_events_assert( array() === datamachine_agent_memory_events_matching( 'datamachine_agent_memory_deleted' ), 'failed delete does not emit delete event' );

$GLOBALS['datamachine_agent_memory_events_post_types'] = array( GuidelineAgentMemoryStore::POST_TYPE );
$GLOBALS['datamachine_agent_memory_events_taxonomies'] = array( GuidelineAgentMemoryStore::TAXONOMY );
$GLOBALS['datamachine_agent_memory_events_actions']    = array();

$guideline_store = new GuidelineAgentMemoryStore();
$guideline_scope = new AgentMemoryScope( 'agent', 7, 42, 'GUIDELINE.md' );
$guideline_write = $guideline_store->write( $guideline_scope, 'Guideline content' );
datamachine_agent_memory_events_assert( true === $guideline_write->success, 'guideline-backed write succeeds when substrate exists' );

$guideline_updates = datamachine_agent_memory_events_matching( 'datamachine_guideline_updated' );
datamachine_agent_memory_events_assert( 1 === count( $guideline_updates ), 'successful guideline-backed write emits guideline update event' );
datamachine_agent_memory_events_assert( 100 === $guideline_updates[0]['args'][0], 'guideline event includes post id' );
datamachine_agent_memory_events_assert( GuidelineAgentMemoryStore::TERM_MEMORY === $guideline_updates[0]['args'][1], 'guideline event includes memory type' );

$GLOBALS['datamachine_agent_memory_events_post_types'] = array();
$GLOBALS['datamachine_agent_memory_events_taxonomies'] = array();
$GLOBALS['datamachine_agent_memory_events_actions']    = array();
$capability_write                                      = $guideline_store->write( new AgentMemoryScope( 'agent', 7, 42, 'UNAVAILABLE.md' ), 'No substrate' );
datamachine_agent_memory_events_assert( false === $capability_write->success, 'guideline-backed write fails cleanly without substrate' );
datamachine_agent_memory_events_assert( array() === datamachine_agent_memory_events_matching( 'datamachine_guideline_updated' ), 'unavailable guideline substrate does not emit guideline event' );

echo "Agent memory event smoke passed.\n";
