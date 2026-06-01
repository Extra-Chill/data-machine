<?php
/**
 * Smoke test for Codex-specific wp-ai-client transport defaults.
 *
 * Run with: php tests/wp-ai-client-codex-timeout-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$GLOBALS['datamachine_codex_timeout_test_settings'] = array(
	'wp_ai_client_connect_timeout' => 25.0,
	'wp_ai_client_request_timeout' => 180.0,
);

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		if ( 'datamachine_settings' === $option ) {
			return $GLOBALS['datamachine_codex_timeout_test_settings'];
		}
		return $default_value;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		unset( $hook, $args );
		return $value;
	}
}

require_once __DIR__ . '/bootstrap-unit.php';

use DataMachine\Engine\AI\RequestBuilder;

$failures = 0;

function assert_codex_timeout_smoke( bool $condition, string $label ): void {
	global $failures;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	echo "FAIL: {$label}\n";
	++$failures;
}

$openai_transport = RequestBuilder::wpAiClientTransportProfile( 'sandbox', 'openai', 'gpt-smoke', array() );
$codex_transport  = RequestBuilder::wpAiClientTransportProfile( 'sandbox', 'codex', 'gpt-5.5', array() );

assert_codex_timeout_smoke( 25.0 === ( $openai_transport['connect_timeout'] ?? null ), 'regular providers keep configured connect timeout default' );
assert_codex_timeout_smoke( 120.0 === ( $codex_transport['connect_timeout'] ?? null ), 'Codex provider receives longer connect timeout default' );
assert_codex_timeout_smoke( 180.0 === ( $codex_transport['request_timeout'] ?? null ), 'Codex provider keeps configured request timeout default' );

$GLOBALS['datamachine_codex_timeout_test_settings']['wp_ai_client_request_timeout'] = 60.0;
$settings_reflection = new ReflectionClass( \DataMachine\Core\PluginSettings::class );
$settings_cache      = $settings_reflection->getProperty( 'cache' );
$settings_cache->setValue( null, null );
$short_codex = RequestBuilder::wpAiClientTransportProfile( 'sandbox', 'codex', 'gpt-5.5', array() );
assert_codex_timeout_smoke( 60.0 === ( $short_codex['connect_timeout'] ?? null ), 'Codex connect timeout floor does not exceed request timeout' );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "\nwp-ai-client Codex timeout smoke passed.\n";
