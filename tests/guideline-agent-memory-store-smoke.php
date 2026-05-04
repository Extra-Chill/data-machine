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
$GLOBALS['datamachine_guideline_posts']      = array();
$GLOBALS['datamachine_guideline_post_meta']  = array();
$GLOBALS['datamachine_guideline_user_id']    = 0;
$GLOBALS['datamachine_guideline_user_caps']  = array();

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {

		public int $ID;
		public string $post_type         = 'wp_guideline';
		public string $post_content      = '';
		public string $post_modified_gmt = '2026-05-04 00:00:00';
		public string $post_name         = '';

		public function __construct( array $args ) {
			foreach ( $args as $key => $value ) {
				$this->{$key} = $value;
			}
		}
	}
}

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

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) $GLOBALS['datamachine_guideline_user_id'];
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, ...$args ): bool {
		$user_id = get_current_user_id();
		$caps    = $GLOBALS['datamachine_guideline_user_caps'][ $user_id ] ?? array();
		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;

		if ( in_array( 'do_not_allow', $caps, true ) ) {
			return false;
		}

		if ( 'read_private_agent_memory' === $capability || 'edit_private_agent_memory' === $capability ) {
			$owner_id = (int) ( $GLOBALS['datamachine_guideline_post_meta'][ $post_id ][ \DataMachine\Core\FilesRepository\GuidelineAgentMemoryStore::GUIDELINE_META_USER_ID ] ?? 0 );
			if ( $owner_id !== $user_id ) {
				return false;
			}

			$primitive = 'read_private_agent_memory' === $capability ? 'read' : 'edit_posts';
			return in_array( $primitive, $caps, true );
		}

		if ( 'edit_agent_memory' === $capability || 'read_workspace_guidelines' === $capability ) {
			return in_array( 'edit_posts', $caps, true );
		}

		if ( 'edit_workspace_guidelines' === $capability ) {
			return in_array( 'publish_posts', $caps, true );
		}

		return in_array( $capability, $caps, true );
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args ): array {
		$name = $args['name'] ?? '';
		foreach ( $GLOBALS['datamachine_guideline_posts'] as $post ) {
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
			$this->posts = array_values( $GLOBALS['datamachine_guideline_posts'] );
		}
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $_single = false ) {
		unset( $_single );
		return $GLOBALS['datamachine_guideline_post_meta'][ $post_id ][ $key ] ?? '';
	}
}

require_once __DIR__ . '/agents-api-loader.php';
datamachine_tests_require_agents_api();
require_once __DIR__ . '/../inc/Core/FilesRepository/GuidelineAgentMemoryStore.php';

use AgentsAPI\Core\FilesRepository\AgentMemoryScope;
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

$scope = new AgentMemoryScope( 'agent', 'site', '1', 7, 42, 'MEMORY.md' );
$same  = new AgentMemoryScope( 'agent', 'site', '1', 7, 42, 'MEMORY.md' );
$daily = new AgentMemoryScope( 'agent', 'site', '1', 7, 42, 'daily/2026/04/17.md' );

$post_name = GuidelineAgentMemoryStore::post_name_for_scope( $scope );
datamachine_guideline_memory_assert(
	$post_name === GuidelineAgentMemoryStore::post_name_for_scope( $same ),
	'Same layer/user/agent/filename tuple yields the same post_name'
);

datamachine_guideline_memory_assert(
	'memory-' . sha1( 'agent:site:1:7:42:MEMORY.md' ) === $post_name,
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
	$daily->key() === 'agent:site:1:7:42:daily/2026/04/17.md',
	'Scope key preserves daily/... filenames'
);

$expected_meta = array(
	'_datamachine_memory_layer',
	'_datamachine_memory_workspace_type',
	'_datamachine_memory_workspace_id',
	'_datamachine_memory_user_id',
	'_datamachine_memory_agent_id',
	'_datamachine_memory_filename',
	'_datamachine_memory_hash',
	'_datamachine_memory_bytes',
);

$reflection  = new ReflectionClass( GuidelineAgentMemoryStore::class );
$actual_meta = array_values( array_intersect_key(
	$reflection->getConstants(),
	array_flip( array( 'META_LAYER', 'META_WORKSPACE_TYPE', 'META_WORKSPACE_ID', 'META_USER_ID', 'META_AGENT_ID', 'META_FILENAME', 'META_HASH', 'META_BYTES' ) )
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

echo "\nTest: guideline metadata maps Data Machine layers to Agents API scopes\n";

$guideline_constants = array(
	GuidelineAgentMemoryStore::GUIDELINE_META_SCOPE        => GuidelineAgentMemoryStore::GUIDELINE_SCOPE_PRIVATE_MEMORY,
	GuidelineAgentMemoryStore::GUIDELINE_META_USER_ID      => 7,
	GuidelineAgentMemoryStore::GUIDELINE_META_WORKSPACE_ID => '1',
);

datamachine_guideline_memory_assert(
	'_wp_guideline_scope' === GuidelineAgentMemoryStore::GUIDELINE_META_SCOPE,
	'Guideline store stamps Agents API scope metadata key'
);
datamachine_guideline_memory_assert(
	'private_user_workspace_memory' === GuidelineAgentMemoryStore::GUIDELINE_SCOPE_PRIVATE_MEMORY,
	'Guideline store uses Agents API private memory scope value'
);
datamachine_guideline_memory_assert(
	'workspace_shared_guidance' === GuidelineAgentMemoryStore::GUIDELINE_SCOPE_WORKSPACE_GUIDANCE,
	'Guideline store uses Agents API workspace guidance scope value'
);

echo "\nTest: private memory requires explicit owner capability metadata\n";

$store         = new GuidelineAgentMemoryStore();
$private_scope = new AgentMemoryScope( 'user', 'site', '1', 7, 42, 'USER.md' );
$post_id       = 200;

$GLOBALS['datamachine_guideline_posts'][ $post_id ] = new WP_Post(
	array(
		'ID'           => $post_id,
		'post_name'    => GuidelineAgentMemoryStore::post_name_for_scope( $private_scope ),
		'post_content' => 'Private profile',
	)
);
$GLOBALS['datamachine_guideline_post_meta'][ $post_id ] = array_merge(
	array(
		GuidelineAgentMemoryStore::META_LAYER    => 'user',
		GuidelineAgentMemoryStore::META_USER_ID  => 7,
		GuidelineAgentMemoryStore::META_AGENT_ID => 42,
		GuidelineAgentMemoryStore::META_FILENAME => 'USER.md',
		GuidelineAgentMemoryStore::META_HASH     => sha1( 'Private profile' ),
		GuidelineAgentMemoryStore::META_BYTES    => strlen( 'Private profile' ),
	),
	$guideline_constants
);

$GLOBALS['datamachine_guideline_user_id']      = 8;
$GLOBALS['datamachine_guideline_user_caps'][8] = array( 'read', 'edit_posts', 'publish_posts', 'read_private_posts' );
$GLOBALS['datamachine_guideline_user_caps'][7] = array( 'read', 'edit_posts' );
$non_owner_private_memory_read                 = $store->read( $private_scope );

datamachine_guideline_memory_assert(
	false === $non_owner_private_memory_read->exists,
	'Non-owner cannot read private memory even with read_private_posts/editor/admin-style caps'
);
datamachine_guideline_memory_assert(
	false === $store->exists( $private_scope ),
	'Private memory existence is not revealed to non-owner readers'
);

$GLOBALS['datamachine_guideline_user_id'] = 7;
$owner_private_memory_read                = $store->read( $private_scope );

datamachine_guideline_memory_assert(
	true === $owner_private_memory_read->exists && 'Private profile' === $owner_private_memory_read->content,
	'Owner can read private memory through explicit owner metadata'
);

echo "\nTest: workspace-shared guidance uses workspace guideline capabilities\n";

$shared_scope = new AgentMemoryScope( 'shared', 'site', '1', 7, 42, 'RULES.md' );
$post_id      = 201;

$GLOBALS['datamachine_guideline_posts'][ $post_id ] = new WP_Post(
	array(
		'ID'           => $post_id,
		'post_name'    => GuidelineAgentMemoryStore::post_name_for_scope( $shared_scope ),
		'post_content' => 'Shared rules',
	)
);
$GLOBALS['datamachine_guideline_post_meta'][ $post_id ] = array(
	GuidelineAgentMemoryStore::META_LAYER                 => 'shared',
	GuidelineAgentMemoryStore::META_USER_ID               => 7,
	GuidelineAgentMemoryStore::META_AGENT_ID              => 42,
	GuidelineAgentMemoryStore::META_FILENAME              => 'RULES.md',
	GuidelineAgentMemoryStore::META_HASH                  => sha1( 'Shared rules' ),
	GuidelineAgentMemoryStore::META_BYTES                 => strlen( 'Shared rules' ),
	GuidelineAgentMemoryStore::GUIDELINE_META_SCOPE        => GuidelineAgentMemoryStore::GUIDELINE_SCOPE_WORKSPACE_GUIDANCE,
	GuidelineAgentMemoryStore::GUIDELINE_META_USER_ID      => 7,
	GuidelineAgentMemoryStore::GUIDELINE_META_WORKSPACE_ID => '1',
);

$GLOBALS['datamachine_guideline_user_id'] = 8;
$shared_guidance_read                     = $store->read( $shared_scope );

datamachine_guideline_memory_assert(
	true === $shared_guidance_read->exists && 'Shared rules' === $shared_guidance_read->content,
	'Workspace-shared guidance reads use read_workspace_guidelines/editor threshold'
);

echo "\nAll smoke tests passed.\n";
