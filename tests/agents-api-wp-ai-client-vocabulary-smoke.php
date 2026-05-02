<?php
/**
 * Static smoke test for Agents API wp-ai-client vocabulary (#1652).
 *
 * Run with: php tests/agents-api-wp-ai-client-vocabulary-smoke.php
 *
 * @package DataMachine\Tests
 */

$failures = array();
$passes   = 0;

echo "agents-api-wp-ai-client-vocabulary-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$root = realpath( __DIR__ . '/..' );
agents_api_smoke_assert_equals( true, is_string( $root ), 'repository root resolves', $failures, $passes );

$scanned_files = array(
	'docs/development/agents-api-extraction-map.md',
	'docs/development/agents-api-pre-extraction-audit.md',
	'docs/core-system/request-builder.md',
	'inc/Engine/AI/RequestBuilder.php',
	'inc/Engine/AI/WpAiClientProviderAdmin.php',
);

$provider_slug        = 'wp-' . 'ai-client';
$provider_func_prefix = 'wp_' . 'ai_client';
$connector_word       = 'bri' . 'dge';

$forbidden_architecture_phrases = array(
	$connector_word . ' to ' . $provider_slug,
	$provider_slug . ' ' . $connector_word,
	$connector_word . ' to ' . $provider_func_prefix,
	$provider_func_prefix . ' ' . $connector_word,
);

$matches = array();
foreach ( $scanned_files as $relative_path ) {
	$path = (string) $root . '/' . $relative_path;
	agents_api_smoke_assert_equals( true, is_file( $path ), $relative_path . ' exists', $failures, $passes );
	$source = strtolower( (string) file_get_contents( $path ) );

	foreach ( $forbidden_architecture_phrases as $phrase ) {
		if ( str_contains( $source, $phrase ) ) {
			$matches[] = $relative_path . ' contains "' . $phrase . '"';
		}
	}
}

agents_api_smoke_assert_equals( array(), $matches, 'docs/comments avoid forbidden final-architecture provider wording', $failures, $passes );

$extraction_map = strtolower( (string) file_get_contents( (string) $root . '/docs/development/agents-api-extraction-map.md' ) );
$audit_doc      = strtolower( (string) file_get_contents( (string) $root . '/docs/development/agents-api-pre-extraction-audit.md' ) );
$engine_readme  = strtolower( (string) file_get_contents( (string) $root . '/inc/Engine/AI/README.md' ) );
$request_docs   = strtolower( (string) file_get_contents( (string) $root . '/docs/core-system/request-builder.md' ) );

agents_api_smoke_assert_equals( true, str_contains( $extraction_map, 'abilities api' ), 'extraction map names Abilities API layer', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $extraction_map, 'one-shot ai operations call `wp-ai-client` directly' ), 'extraction map preserves direct one-shot wp-ai-client usage', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $extraction_map, 'pipeline ai steps should not move to agents api solely for provider dispatch' ), 'extraction map blocks pipeline AI migration for provider dispatch only', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $audit_doc, 'wp-ai-client` remains the direct wordpress provider primitive for one-shot ai operations' ), 'pre-extraction audit preserves direct one-shot provider primitive', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $audit_doc, 'durable agent runtime behavior' ), 'pre-extraction audit names durable runtime boundary', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $engine_readme, 'wp-ai-client | direct provider/model prompt execution for one-shot ai operations' ), 'Engine AI README names direct wp-ai-client layer', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $request_docs, 'plugins that only need one-shot ai operations may call `wp-ai-client` directly' ), 'RequestBuilder docs do not force all plugins through Agents API', $failures, $passes );

$deleted_adapter_path    = '/inc/Engine/AI/WpAiClient' . 'Adapter.php';
$deleted_capability_path = '/inc/Engine/AI/WpAiClient' . 'Capability.php';
agents_api_smoke_assert_equals( false, is_file( (string) $root . $deleted_adapter_path ), 'Data Machine provider adapter file is deleted', $failures, $passes );
agents_api_smoke_assert_equals( false, is_file( (string) $root . $deleted_capability_path ), 'Data Machine capability alias wrapper is deleted', $failures, $passes );

agents_api_smoke_assert_equals( false, is_file( (string) $root . '/vendor/automattic/agents-api/inc/AI/WpAiClient.php' ), 'Agents API carries no low-level wp-ai-client execution wrapper', $failures, $passes );

$agents_api_dir = (string) $root . '/vendor/automattic/agents-api';
$host_terms     = array( 'wpcom', 'wpcOM', 'dolly', 'odie', 'wordpress.com', 'automattic ai framework' );
$host_matches   = array();
$iterator       = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $agents_api_dir ) );

foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}

	$source = strtolower( (string) file_get_contents( $file->getPathname() ) );
	foreach ( $host_terms as $term ) {
		if ( str_contains( $source, strtolower( $term ) ) ) {
			$host_matches[] = str_replace( $agents_api_dir . '/', '', $file->getPathname() ) . ' contains ' . $term;
		}
	}
}

agents_api_smoke_assert_equals( array(), $host_matches, 'agents-api has no host-specific implementation vocabulary', $failures, $passes );

agents_api_smoke_finish( 'Agents API wp-ai-client vocabulary', $failures, $passes );
