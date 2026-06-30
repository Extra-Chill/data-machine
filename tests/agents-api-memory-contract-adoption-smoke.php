<?php
/**
 * Smoke coverage for Data Machine's Agents API memory/context contract adoption.
 *
 * Run with: php tests/agents-api-memory-contract-adoption-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) {
		return preg_replace( '/[^a-zA-Z0-9._\-]/', '', basename( (string) $filename ) );
	}
}

$GLOBALS['datamachine_contract_adoption_actions'] = array();
$GLOBALS['datamachine_contract_adoption_filters'] = array();

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['datamachine_contract_adoption_actions'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$callbacks = $GLOBALS['datamachine_contract_adoption_actions'][ $hook ] ?? array();
		ksort( $callbacks );
		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				call_user_func_array( $callback[0], array_slice( $args, 0, $callback[1] ) );
			}
		}
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$callbacks = $GLOBALS['datamachine_contract_adoption_filters'][ $hook ] ?? array();
		ksort( $callbacks );
		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				$value = call_user_func_array( $callback[0], array_merge( array( $value ), array_slice( $args, 0, $callback[1] - 1 ) ) );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {
		// no-op stub for standalone smoke runs.
	}
}


require_once __DIR__ . '/../vendor/wordpress/agents-api/agents-api.php';
require_once __DIR__ . '/../inc/Engine/AI/MemoryFileRegistry.php';
require_once __DIR__ . '/../inc/Engine/AI/SectionRegistry.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/DiskAgentMemoryStore.php';
require_once __DIR__ . '/../inc/Core/FilesRepository/GuidelineAgentMemoryStore.php';

use AgentsAPI\AI\Context\WP_Agent_Context_Authority_Tier;
use AgentsAPI\AI\Context\WP_Agent_Context_Conflict_Kind;
use AgentsAPI\AI\Context\WP_Agent_Default_Context_Conflict_Resolver;
use AgentsAPI\AI\Context\WP_Agent_Context_Item;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Metadata;
use DataMachine\Core\FilesRepository\DiskAgentMemoryStore;
use DataMachine\Core\FilesRepository\GuidelineAgentMemoryStore;
use DataMachine\Engine\AI\MemoryFileRegistry;
use DataMachine\Engine\AI\SectionRegistry;

function datamachine_contract_adoption_assert( bool $condition, string $message ): void {
	static $assertions = 0;
	++$assertions;
	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "Assertion failed: {$message}\n" );
		exit( 1 );
	}
	echo "ok {$assertions} - {$message}\n";
}

echo "agents-api-memory-contract-adoption-smoke\n";

MemoryFileRegistry::reset();
MemoryFileRegistry::register(
	'SITE.md',
	10,
	array(
		'layer'      => MemoryFileRegistry::LAYER_SHARED,
		'protected'  => true,
		'composable' => true,
		'label'      => 'Site Context',
	)
);

$datamachine_file = MemoryFileRegistry::get( 'SITE.md' );
$agents_source    = WP_Agent_Memory_Registry::get( 'datamachine/site.md' );
datamachine_contract_adoption_assert( null !== $agents_source, 'Data Machine registration creates an Agents API memory source' );
datamachine_contract_adoption_assert( 'SITE.md' === ( $agents_source['meta']['filename'] ?? null ), 'Agents API source retains Data Machine filename adapter metadata' );
datamachine_contract_adoption_assert( $datamachine_file['retrieval_policy'] === ( $agents_source['retrieval_policy'] ?? null ), 'Data Machine and Agents API memory registries share normalized retrieval policy' );
datamachine_contract_adoption_assert( empty( $datamachine_file['injection_contexts'] ) && empty( $agents_source['injection_contexts'] ?? array() ), 'Data Machine and Agents API memory registries share normalized injection contexts' );
datamachine_contract_adoption_assert( WP_Agent_Context_Authority_Tier::WORKSPACE_SHARED === $datamachine_file['authority_tier'], 'shared memory file receives workspace authority tier' );
datamachine_contract_adoption_assert( false === $datamachine_file['editable'], 'composable files remain non-editable through the adapter' );

SectionRegistry::reset();
SectionRegistry::register(
	'SITE.md',
	'one',
	20,
	static fn(): string => "## One\nFirst",
	array(
		'owner'      => 'test-owner',
		'freshness'  => 'snapshot',
		'conditions' => 'test condition',
	)
);
SectionRegistry::register( 'SITE.md', 'zero', 10, static fn(): string => "## Zero\nBefore" );
$content = SectionRegistry::generate( 'SITE.md' );
datamachine_contract_adoption_assert( "## Zero\nBefore\n\n## One\nFirst" === $content, 'Data Machine section composition stays priority-stable through Agents API' );
$sections = SectionRegistry::get_sections( 'SITE.md' );
datamachine_contract_adoption_assert( 'tests' === $sections['one']['source_plugin'], 'section registry records source plugin provenance' );
datamachine_contract_adoption_assert( 'tests/agents-api-memory-contract-adoption-smoke.php' === $sections['one']['source_file'], 'section registry records source file provenance' );
datamachine_contract_adoption_assert( 'Closure' === $sections['one']['source_callback'], 'section registry records callback provenance' );
datamachine_contract_adoption_assert( '-' === $sections['one']['registered_at'], 'section registry records registration hook provenance fallback' );
datamachine_contract_adoption_assert( 'test-owner' === $sections['one']['owner'], 'section registry records logical owner metadata' );
datamachine_contract_adoption_assert( 'snapshot' === $sections['one']['freshness'], 'section registry records freshness metadata' );
datamachine_contract_adoption_assert( 'test condition' === $sections['one']['conditions'], 'section registry records condition metadata' );
datamachine_contract_adoption_assert( $sections['one'] === SectionRegistry::get_section( 'SITE.md', 'one' ), 'section registry exposes single-section lookup' );
datamachine_contract_adoption_assert( null === SectionRegistry::get_section( 'SITE.md', 'missing' ), 'section registry single-section lookup returns null for missing sections' );

$disk_store = ( new ReflectionClass( DiskAgentMemoryStore::class ) )->newInstanceWithoutConstructor();
$disk_caps  = $disk_store->capabilities();
$unsupported = $disk_caps->unsupported_metadata_fields( array( 'source_type', 'authority_tier' ), 'persist' );
datamachine_contract_adoption_assert( array( 'source_type', 'authority_tier' ) === $unsupported, 'disk store declares unsupported metadata instead of dropping silently' );

$guideline_caps = ( new GuidelineAgentMemoryStore() )->capabilities();
datamachine_contract_adoption_assert( array() === $guideline_caps->unsupported_metadata_fields( WP_Agent_Memory_Metadata::FIELDS, 'persist' ), 'guideline store declares full metadata persistence support' );

$resolver = new WP_Agent_Default_Context_Conflict_Resolver();
$items    = array(
	new WP_Agent_Context_Item( 'agent memory says no', array( 'filename' => 'MEMORY.md' ), WP_Agent_Context_Authority_Tier::AGENT_MEMORY, array(), WP_Agent_Context_Conflict_Kind::AUTHORITATIVE_FACT, 'policy:x' ),
	new WP_Agent_Context_Item( 'workspace says yes', array( 'filename' => 'SITE.md' ), WP_Agent_Context_Authority_Tier::WORKSPACE_SHARED, array(), WP_Agent_Context_Conflict_Kind::AUTHORITATIVE_FACT, 'policy:x' ),
);
$resolution = $resolver->resolve( $items )['policy:x'] ?? null;
datamachine_contract_adoption_assert( null !== $resolution && 'workspace says yes' === $resolution->winner->content, 'authority cascade rejects lower-scope memory for authoritative fact conflicts' );

echo "Agents API memory contract adoption smoke passed.\n";
