<?php
/**
 * Smoke test for Plugin Check request-input sanitization guardrails.
 *
 * Run with: php tests/request-input-sanitization-smoke.php
 *
 * @package DataMachine\Tests
 */

$failures = 0;
$total    = 0;

$assert = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
	$total++;
	if ( $condition ) {
		echo "  [PASS] {$label}\n";
		return;
	}

	$failures++;
	echo "  [FAIL] {$label}\n";
};

$root            = dirname( __DIR__ );
$plugin          = file_get_contents( $root . '/data-machine.php' );
$system_command  = file_get_contents( $root . '/inc/Cli/Commands/SystemCommand.php' );
$agent_authorize = file_get_contents( $root . '/inc/Core/Auth/AgentAuthorize.php' );
$webhook_gate    = file_get_contents( $root . '/inc/Core/Steps/WebhookGate/WebhookGateStep.php' );

echo "\n[1] Superglobal request input is unslashed and sanitized\n";
$assert( 'REQUEST_URI is unslashed and sanitized before parsing', str_contains( $plugin, "sanitize_text_field( wp_unslash( \$_SERVER['REQUEST_URI'] ) )" ) );
$assert( 'CLI argv is unslashed and sanitized before parsing', str_contains( $system_command, 'sanitize_text_field( wp_unslash( (string) $arg ) )' ) );
$assert( 'authorize cookie is unslashed and sanitized', str_contains( $agent_authorize, 'sanitize_text_field( wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ) )' ) );
$assert( 'webhook REMOTE_ADDR fallback is unslashed and sanitized', str_contains( $webhook_gate, "sanitize_text_field( wp_unslash( $" . "request->get_header( 'x-forwarded-for' ) ?? \$_SERVER['REMOTE_ADDR'] ?? '' ) )" ) );

echo "\nAssertions: {$total}, Failures: {$failures}\n";
if ( $failures > 0 ) {
	exit( 1 );
}
