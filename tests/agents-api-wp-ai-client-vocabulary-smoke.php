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
	'inc/Engine/AI/WpAiClientAdapter.php',
	'inc/Engine/AI/WpAiClientCapability.php',
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

$agents_api_dir = (string) $root . '/agents-api';
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
