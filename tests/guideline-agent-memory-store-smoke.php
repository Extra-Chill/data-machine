<?php
/**
 * Pure-PHP smoke tests for GuidelineAgentMemoryStore.
 *
 * Run with: php tests/guideline-agent-memory-store-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['datamachine_guideline_post_types'] = array();
$GLOBALS['datamachine_guideline_taxonomies'] = array();

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( string $post_type ): bool {
		return in_array( $post_type, $GLOBALS['datamachine_guideline_post_types'], true );
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( string $taxonomy ): bool {
		return in_array( $taxonomy, $GLOBALS['datamachine_guideline_taxonomies'], true );
	}
}

require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemoryScope.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemoryListEntry.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemoryReadResult.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemoryWriteResult.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemoryStoreInterface.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/GuidelineAgentMemoryStore.php';

use DataMachine\Core\FilesRepository\AgentMemoryScope;
use DataMachine\Core\FilesRepository\GuidelineAgentMemoryStore;

function datamachine_guideline_memory_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		echo "  [PASS] {$message}\n";
		return;
	}

	echo "  [FAIL] {$message}\n";
	exit( 1 );
}

echo "Test: availability feature-detects the Guidelines substrate\n";

datamachine_guideline_memory_assert(
	false === GuidelineAgentMemoryStore::is_available(),
	'Unavailable when wp_guideline post type and taxonomy are absent'
);

$GLOBALS['datamachine_guideline_post_types'] = array( 'wp_guideline' );
datamachine_guideline_memory_assert(
	false === GuidelineAgentMemoryStore::is_available(),
	'Unavailable when only wp_guideline post type exists'
);

$GLOBALS['datamachine_guideline_post_types'] = array();
$GLOBALS['datamachine_guideline_taxonomies'] = array( 'wp_guideline_type' );
datamachine_guideline_memory_assert(
	false === GuidelineAgentMemoryStore::is_available(),
	'Unavailable when only wp_guideline_type taxonomy exists'
);

$GLOBALS['datamachine_guideline_post_types'] = array( 'wp_guideline' );
$GLOBALS['datamachine_guideline_taxonomies'] = array( 'wp_guideline_type' );
datamachine_guideline_memory_assert(
	true === GuidelineAgentMemoryStore::is_available(),
	'Available only when both wp_guideline and wp_guideline_type exist'
);

echo "\nTest: deterministic scope key encoding\n";

$scope = new AgentMemoryScope( 'agent', 7, 42, 'MEMORY.md' );
$same  = new AgentMemoryScope( 'agent', 7, 42, 'MEMORY.md' );
$daily = new AgentMemoryScope( 'agent', 7, 42, 'daily/2026/04/17.md' );

$post_name = GuidelineAgentMemoryStore::post_name_for_scope( $scope );
datamachine_guideline_memory_assert(
	$post_name === GuidelineAgentMemoryStore::post_name_for_scope( $same ),
	'Same layer/user/agent/filename tuple yields the same post_name'
);

datamachine_guideline_memory_assert(
	'memory-' . sha1( 'agent:7:42:MEMORY.md' ) === $post_name,
	'post_name is memory- prefixed sha1 of AgentMemoryScope::key()'
);

datamachine_guideline_memory_assert(
	$post_name !== GuidelineAgentMemoryStore::post_name_for_scope( $daily ),
	'Changing filename changes post_name'
);

datamachine_guideline_memory_assert(
	strlen( $post_name ) === 47,
	'post_name is prefix + sha1 hex (47 chars), within wp_posts.post_name limits'
);

echo "\nTest: subtree filenames and Data Machine meta keys\n";

datamachine_guideline_memory_assert(
	$daily->key() === 'agent:7:42:daily/2026/04/17.md',
	'Scope key preserves daily/... filenames'
);

$expected_meta = array(
	'_datamachine_memory_layer',
	'_datamachine_memory_user_id',
	'_datamachine_memory_agent_id',
	'_datamachine_memory_filename',
	'_datamachine_memory_hash',
	'_datamachine_memory_bytes',
);

$reflection  = new ReflectionClass( GuidelineAgentMemoryStore::class );
$actual_meta = array_values( array_intersect_key(
	$reflection->getConstants(),
	array_flip( array( 'META_LAYER', 'META_USER_ID', 'META_AGENT_ID', 'META_FILENAME', 'META_HASH', 'META_BYTES' ) )
) );

datamachine_guideline_memory_assert(
	$expected_meta === $actual_meta,
	'Guideline store uses Data Machine-branded meta keys'
);

foreach ( $actual_meta as $meta_key ) {
	datamachine_guideline_memory_assert(
		! str_contains( $meta_key, '_intelligence_' ),
		"Meta key {$meta_key} is not Intelligence-branded"
	);
}

echo "\nAll smoke tests passed.\n";
