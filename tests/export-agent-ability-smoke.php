<?php
/**
 * Smoke test for datamachine/export-agent ability slice (#1305).
 *
 * Run with: php tests/export-agent-ability-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
		return trim( (string) $title, '-' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) {
		return (string) $value;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	$GLOBALS['export_agent_smoke_filters'] = array();
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['export_agent_smoke_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
	function apply_filters( $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['export_agent_smoke_filters'][ $hook ] ) ) {
			return $value;
		}
		ksort( $GLOBALS['export_agent_smoke_filters'][ $hook ], SORT_NUMERIC );
		foreach ( $GLOBALS['export_agent_smoke_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $callback_entry ) {
				$callback      = $callback_entry[0];
				$accepted_args = $callback_entry[1];
				$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
			}
		}
		return $value;
	}
}

class WP_Error {
}

require_once __DIR__ . '/../inc/Engine/Bundle/BundleValidationException.php';
require_once __DIR__ . '/../inc/Engine/Bundle/PortableSlug.php';
require_once __DIR__ . '/../inc/Engine/Bundle/BundleSchema.php';
require_once __DIR__ . '/../inc/Engine/Bundle/AgentBundleSlugTrait.php';
require_once __DIR__ . '/../inc/Engine/Bundle/AgentBundleManifest.php';
require_once __DIR__ . '/../inc/Engine/Bundle/AgentBundlePipelineFile.php';
require_once __DIR__ . '/../inc/Engine/Bundle/AgentBundleFlowFile.php';
require_once __DIR__ . '/../inc/Engine/Bundle/AgentBundleArtifactExtensions.php';
require_once __DIR__ . '/../inc/Engine/Bundle/AgentBundleDirectory.php';
require_once __DIR__ . '/../inc/Core/Agents/AgentBundler.php';

use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Engine\Bundle\AgentBundleDirectory;
use DataMachine\Engine\Bundle\AgentBundleFlowFile;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use DataMachine\Engine\Bundle\AgentBundlePipelineFile;

$assertions = 0;
$failures   = 0;

$assert = function ( string $label, bool $condition ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "FAIL: {$label}\n";
};

function export_agent_private( string $method, array $args = array() ) {
	$reflection = new ReflectionMethod( AgentBundler::class, $method );
	return $reflection->invokeArgs( null, $args );
}

echo "=== Export Agent Ability Smoke ===\n";

echo "\n[1] Export manifest profiles and filter contract\n";
$seen_filter = false;
add_filter(
	'datamachine_agent_export_manifest',
	function ( array $manifest, int $agent_id, array $context ) use ( &$seen_filter ) {
		$seen_filter = 42 === $agent_id && 'fork' === ( $context['profile'] ?? '' );
		$manifest['soul'] = false;
		return $manifest;
	},
	10,
	3
);
$fork_manifest = export_agent_private( 'resolve_export_manifest', array( 42, array( 'profile' => 'fork' ) ) );
$assert( 'manifest filter fires with profile context', $seen_filter );
$assert( 'fork profile omits handler auth', 'omit' === $fork_manifest['handler_auth'] );
$assert( 'filter return participates in resolved manifest', false === $fork_manifest['soul'] );
$backup_manifest = export_agent_private( 'resolve_export_manifest', array( 42, array( 'profile' => 'backup' ) ) );
$assert( 'backup profile includes memory', true === $backup_manifest['memory'] );
$assert( 'backup profile keeps full handler auth', 'full' === $backup_manifest['handler_auth'] );

echo "\n[2] Handler auth refs never emit raw secrets\n";
add_filter(
	'datamachine_handler_config_to_auth_ref',
	function ( array $config, string $handler_slug, array $context ) {
		unset( $handler_slug );
		unset( $context );
		$config['auth_ref'] = 'email:default';
		return $config;
	},
	10,
	3
);
$step = array(
	'handler_configs' => array(
		'email' => array(
			'username'     => 'person@example.com',
			'access_token' => 'secret-token',
			'nested'       => array( 'password' => 'secret-password' ),
		),
	),
);
$refs_configs = export_agent_private( 'handler_configs_from_flow_step', array( $step, 'refs', array() ) );
$assert( 'refs mode preserves auth_ref', 'email:default' === ( $refs_configs['email']['auth_ref'] ?? '' ) );
$assert( 'refs mode strips access_token', ! array_key_exists( 'access_token', $refs_configs['email'] ) );
$assert( 'refs mode strips nested password', ! array_key_exists( 'password', $refs_configs['email']['nested'] ?? array() ) );
$full_configs = export_agent_private( 'handler_configs_from_flow_step', array( $step, 'full', array() ) );
$assert( 'full mode preserves raw config for backups', 'secret-token' === ( $full_configs['email']['access_token'] ?? '' ) );
$omit_configs = export_agent_private( 'handler_configs_from_flow_step', array( $step, 'omit', array() ) );
$assert( 'omit mode strips handler configs', array() === $omit_configs );

echo "\n[3] Directory output is deterministic\n";
$directory = new AgentBundleDirectory(
	new AgentBundleManifest(
		'2026-04-28T00:00:00+00:00',
		'data-machine/test',
		'agent-test',
		'1',
		'',
		'',
		array( 'slug' => 'agent-test', 'label' => 'Agent Test', 'description' => '', 'agent_config' => array( 'b' => 2, 'a' => 1 ) ),
		array( 'memory' => array( 'agent/SOUL.md' ), 'pipelines' => array( 'pipeline-a' ), 'flows' => array( 'flow-a' ), 'handler_auth' => 'refs' )
	),
	array( 'agent/SOUL.md' => "Soul\n" ),
	array( new AgentBundlePipelineFile( 'pipeline-a', 'Pipeline A', array( array( 'step_position' => 0, 'step_type' => 'fetch', 'step_config' => array( 'z' => true, 'a' => true ) ) ) ) ),
	array( new AgentBundleFlowFile( 'flow-a', 'Flow A', 'pipeline-a', 'manual', array(), array( array( 'step_position' => 0, 'handler_configs' => array( 'email' => array( 'auth_ref' => 'email:default' ) ) ) ) ) )
);
$tmp_base = sys_get_temp_dir() . '/datamachine-export-agent-smoke-' . getmypid();
$dir_a    = $tmp_base . '/a';
$dir_b    = $tmp_base . '/b';
$directory->write( $dir_a );
$directory->write( $dir_b );
$manifest_a = file_get_contents( $dir_a . '/manifest.json' );
$manifest_b = file_get_contents( $dir_b . '/manifest.json' );
$assert( 'manifest export is byte-identical across writes', $manifest_a === $manifest_b );
$assert( 'pipeline export is byte-identical across writes', file_get_contents( $dir_a . '/pipelines/pipeline-a.json' ) === file_get_contents( $dir_b . '/pipelines/pipeline-a.json' ) );
$assert( 'manifest JSON sorts object keys', false !== strpos( (string) $manifest_a, "\"a\": 1,\n            \"b\": 2" ) );

echo "\n[4] Ability and CLI route through value-object exporter\n";
$ability_source = file_get_contents( __DIR__ . '/../inc/Abilities/AgentAbilities.php' ) ?: '';
$cli_source     = file_get_contents( __DIR__ . '/../inc/Cli/Commands/AgentsCommand.php' ) ?: '';
$bundler_source = file_get_contents( __DIR__ . '/../inc/Core/Agents/AgentBundler.php' ) ?: '';
$assert( 'ability registers datamachine/export-agent', str_contains( $ability_source, "'datamachine/export-agent'" ) );
$assert( 'ability calls AgentBundler export_directory_object', str_contains( $ability_source, 'export_directory_object' ) );
$assert( 'ability writes AgentBundleDirectory directly', str_contains( $ability_source, '$directory->write( $destination )' ) );
$assert( 'CLI export calls generic ability', str_contains( $cli_source, 'AgentAbilities::exportAgent' ) );
$assert( 'bundler applies datamachine_agent_export_manifest once', 1 === substr_count( $bundler_source, 'datamachine_agent_export_manifest' ) );

echo "\nAssertions: {$assertions}\n";
if ( $failures > 0 ) {
	echo "Failures: {$failures}\n";
	exit( 1 );
}

echo "All export-agent ability smoke assertions passed.\n";
