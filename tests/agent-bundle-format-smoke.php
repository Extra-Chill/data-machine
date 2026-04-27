<?php
/**
 * Pure-PHP smoke test for agent bundle format value objects (#1303).
 *
 * Run with: php tests/agent-bundle-format-smoke.php
 *
 * Phase 2a defines the on-disk bundle format only: manifest schema,
 * pipeline/flow JSON documents, deterministic directory read/write, and
 * stable portable slug columns. It must not depend on WordPress runtime state
 * or perform importer/exporter DB writes; those belong to later phases.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use DataMachine\Engine\Bundle\AgentBundleDirectory;
use DataMachine\Engine\Bundle\AgentBundleFlowFile;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use DataMachine\Engine\Bundle\AgentBundlePipelineFile;
use DataMachine\Engine\Bundle\BundleSchema;
use DataMachine\Engine\Bundle\BundleValidationException;
use DataMachine\Engine\Bundle\PortableSlug;

$GLOBALS['__agent_bundle_failures'] = 0;
$GLOBALS['__agent_bundle_total']    = 0;

function assert_bundle( string $label, bool $condition ): void {
	++$GLOBALS['__agent_bundle_total'];
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	echo "  FAIL: {$label}\n";
	++$GLOBALS['__agent_bundle_failures'];
}

function assert_bundle_equals( string $label, $expected, $actual ): void {
	assert_bundle( $label, $expected === $actual );
}

function rm_tree( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $path );
}

echo "=== Agent Bundle Format Smoke (#1303) ===\n";

echo "\n[1] Portable slug normalization is deterministic\n";
assert_bundle_equals( 'normalizes names to kebab-case', 'woocommerce-daily-ingest', PortableSlug::normalize( 'WooCommerce Daily Ingest!' ) );
assert_bundle_equals( 'falls back for empty candidates', 'pipeline', PortableSlug::normalize( '!!!', 'pipeline' ) );
assert_bundle_equals( 'dedupes sibling slugs with numeric suffix', 'daily-ingest-3', PortableSlug::dedupe( 'daily-ingest', array( 'daily-ingest', 'daily-ingest-2' ) ) );

echo "\n[2] Manifest schema validates and normalizes included lists\n";
$manifest = AgentBundleManifest::from_array(
	array(
		'schema_version' => 1,
		'exported_at'    => '2026-04-26T15:30:00Z',
		'exported_by'    => 'data-machine/0.84.0-test',
		'agent'          => array(
			'slug'         => 'WooCommerce Agent',
			'label'        => 'WooCommerce Knowledge Keeper',
			'description'  => 'Maintains the WooCommerce wiki.',
			'agent_config' => array( 'model' => array( 'default' => 'gpt-5.5' ) ),
		),
		'included'       => array(
			'memory'       => array( 'MEMORY.md', 'SOUL.md' ),
			'pipelines'    => array( 'wc-weekly-lint', 'wc-daily-ingest' ),
			'flows'        => array( 'wc-weekly-lint-flow', 'wc-daily-ingest-flow' ),
			'handler_auth' => 'refs',
		),
	)
);
$manifest_array = $manifest->to_array();
assert_bundle_equals( 'schema version pinned to v1', 1, $manifest_array['schema_version'] );
assert_bundle_equals( 'agent slug normalized once', 'woocommerce-agent', $manifest_array['agent']['slug'] );
assert_bundle_equals( 'included pipelines sorted for deterministic JSON', array( 'wc-daily-ingest', 'wc-weekly-lint' ), $manifest_array['included']['pipelines'] );
assert_bundle_equals( 'handler_auth refs accepted', 'refs', $manifest_array['included']['handler_auth'] );

echo "\n[3] Pipeline and flow documents sort steps by position\n";
$pipeline = AgentBundlePipelineFile::from_array(
	array(
		'schema_version' => 1,
		'slug'           => 'WC Daily Ingest',
		'name'           => 'WooCommerce daily ingest',
		'steps'          => array(
			array(
				'step_position' => 1,
				'step_type'     => 'ai',
				'step_config'   => array( 'label' => 'Extract', 'system_prompt' => 'Extract facts.' ),
			),
			array(
				'step_position' => 0,
				'step_type'     => 'fetch',
				'step_config'   => array( 'label' => 'Fetch' ),
			),
		),
	)
);
$pipeline_array = $pipeline->to_array();
assert_bundle_equals( 'pipeline slug normalized', 'wc-daily-ingest', $pipeline_array['slug'] );
assert_bundle_equals( 'pipeline step 0 first after normalization', 'fetch', $pipeline_array['steps'][0]['step_type'] );

$flow = AgentBundleFlowFile::from_array(
	array(
		'schema_version' => 1,
		'slug'           => 'WC Daily Ingest Flow',
		'name'           => 'WC Daily Ingest',
		'pipeline_slug'  => 'WC Daily Ingest',
		'schedule'       => 'daily',
		'max_items'      => array( 'mcp' => 5 ),
		'steps'          => array(
			array(
				'step_position'   => 1,
				'handler_slug'    => 'wordpress_publish',
				'handler_configs' => array( 'wordpress_publish' => array( 'post_type' => 'wiki' ) ),
			),
			array(
				'step_position'   => 0,
				'handler_slug'    => 'mcp',
				'handler_configs' => array( 'mcp' => array( 'auth_ref' => 'wpcom:default', 'provider' => 'mgs' ) ),
			),
		),
	)
);
$flow_array = $flow->to_array();
assert_bundle_equals( 'flow references pipeline by slug, not source ID', 'wc-daily-ingest', $flow_array['pipeline_slug'] );
assert_bundle_equals( 'flow step 0 first after normalization', 'mcp', $flow_array['steps'][0]['handler_slug'] );

echo "\n[4] Directory write/read round-trips without DB access\n";
$bundle = new AgentBundleDirectory(
	$manifest,
	array(
		'MEMORY.md'       => "# Memory\n",
		'SOUL.md'         => "# Soul\n",
		'daily/2026-04-26.md' => "# Daily\n",
	),
	array( $pipeline ),
	array( $flow )
);
$tmp = sys_get_temp_dir() . '/datamachine-agent-bundle-' . getmypid();
rm_tree( $tmp );
$bundle->write( $tmp );

assert_bundle( 'manifest.json written', is_file( $tmp . '/manifest.json' ) );
assert_bundle( 'memory/SOUL.md written as markdown file', is_file( $tmp . '/memory/SOUL.md' ) );
assert_bundle( 'pipeline file named by portable slug', is_file( $tmp . '/pipelines/wc-daily-ingest.json' ) );
assert_bundle( 'flow file named by portable slug', is_file( $tmp . '/flows/wc-daily-ingest-flow.json' ) );

$read = AgentBundleDirectory::read( $tmp );
assert_bundle_equals( 'read manifest preserves agent slug', 'woocommerce-agent', $read->manifest()->agent_slug() );
assert_bundle_equals( 'read memory files preserve nested daily file', "# Daily\n", $read->memory_files()['daily/2026-04-26.md'] ?? null );
assert_bundle_equals( 'one pipeline read', 1, count( $read->pipelines() ) );
assert_bundle_equals( 'one flow read', 1, count( $read->flows() ) );
assert_bundle_equals( 'round-trip manifest array stable', $manifest->to_array(), $read->manifest()->to_array() );

echo "\n[5] JSON output is stable and review-friendly\n";
$encoded_manifest = file_get_contents( $tmp . '/manifest.json' );
assert_bundle( 'pretty JSON contains newlines', false !== strpos( (string) $encoded_manifest, "\n    \"agent\"" ) );
assert_bundle( 'JSON ends with newline', str_ends_with( (string) $encoded_manifest, "\n" ) );
assert_bundle( 'JSON object keys are deterministically sorted', strpos( (string) $encoded_manifest, '"agent"' ) < strpos( (string) $encoded_manifest, '"exported_at"' ) );

echo "\n[6] Unsupported schema versions fail clearly\n";
$threw = false;
try {
	AgentBundleManifest::from_array(
		array(
			'schema_version' => 2,
			'exported_at'    => '2026-04-26T15:30:00Z',
			'exported_by'    => 'data-machine/next',
			'agent'          => array(),
			'included'       => array(),
		)
	);
} catch ( BundleValidationException $e ) {
	$threw = str_contains( $e->getMessage(), 'unsupported schema_version 2' );
}
assert_bundle( 'v2 manifest refused with clear message', $threw );

echo "\n[7] Source schema exposes stable portable slug columns\n";
$pipelines_source = file_get_contents( dirname( __DIR__ ) . '/inc/Core/Database/Pipelines/Pipelines.php' );
$flows_source     = file_get_contents( dirname( __DIR__ ) . '/inc/Core/Database/Flows/Flows.php' );
assert_bundle( 'pipelines table has portable_slug column', str_contains( (string) $pipelines_source, 'portable_slug varchar(191) DEFAULT NULL' ) );
assert_bundle( 'pipelines table has agent-scoped portable_slug uniqueness', str_contains( (string) $pipelines_source, 'UNIQUE KEY agent_portable_slug (agent_id, portable_slug)' ) );
assert_bundle( 'flows table has portable_slug column', str_contains( (string) $flows_source, 'portable_slug varchar(191) DEFAULT NULL' ) );
assert_bundle( 'flows table has pipeline-scoped portable_slug uniqueness', str_contains( (string) $flows_source, 'UNIQUE KEY pipeline_portable_slug (pipeline_id, portable_slug)' ) );

rm_tree( $tmp );

$total    = (int) $GLOBALS['__agent_bundle_total'];
$failures = (int) $GLOBALS['__agent_bundle_failures'];
echo "\nTotal assertions: {$total}\n";
// @phpstan-ignore-next-line Smoke assertions mutate this counter through a global helper.
if ( getenv( 'DATAMACHINE_AGENT_BUNDLE_SMOKE_FORCE_FAILURE' ) || 0 !== $failures ) {
	echo "Failures: {$failures}\n";
	exit( 1 );
}

echo "All assertions passed.\n";
