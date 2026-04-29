<?php
/**
 * Pure-PHP smoke test for plugin-defined agent bundle artifacts (#1577).
 *
 * Run with: php tests/agent-bundle-extension-artifacts-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['__bundle_extension_filters'] = array();

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $title ) );
		return trim( $title, '-' );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $message;
		public function __construct( string $code = '', string $message = '' ) {
			unset( $code );
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__bundle_extension_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
		ksort( $GLOBALS['__bundle_extension_filters'][ $hook ], SORT_NUMERIC );
		return true;
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook = '' ) {
		unset( $hook );
		return 0;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['__bundle_extension_filters'][ $hook ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['__bundle_extension_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $registration ) {
				$value = call_user_func_array( $registration[0], array_slice( array_merge( array( $value ), $args ), 0, $registration[1] ) );
			}
		}
		return $value;
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use DataMachine\Engine\Bundle\AgentBundleArtifactExtensions;
use DataMachine\Engine\Bundle\AgentBundleArtifactHasher;
use DataMachine\Engine\Bundle\AgentBundleDirectory;
use DataMachine\Engine\Bundle\AgentBundleInstalledArtifact;
use DataMachine\Engine\Bundle\AgentBundleLegacyAdapter;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use DataMachine\Engine\Bundle\AgentBundleUpgradePendingAction;
use DataMachine\Engine\Bundle\AgentBundleUpgradePlanner;

$failures = 0;
$total    = 0;

function assert_extension_bundle( string $label, bool $condition ): void {
	global $failures, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	echo "  FAIL: {$label}\n";
	++$failures;
}

function assert_extension_bundle_equals( string $label, $expected, $actual ): void {
	assert_extension_bundle( $label, $expected === $actual );
}

function extension_artifact( string $id, array $payload, string $source_path = '' ): array {
	return array(
		'artifact_type' => 'fake_plugin_artifact',
		'artifact_id'   => $id,
		'source_path'   => $source_path ?: 'extensions/fake-plugin/' . $id . '.json',
		'payload'       => $payload,
	);
}

function extension_rm_tree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $dir );
}

add_filter(
	'datamachine_agent_bundle_artifact_types',
	static function ( array $types ): array {
		$types[] = 'fake_plugin_artifact';
		return $types;
	},
	10,
	1
);

$export_context = array();
add_filter(
	'datamachine_agent_bundle_export_artifacts',
	static function ( array $artifacts, array $agent, array $context ) use ( &$export_context ): array {
		$export_context = array( 'agent_slug' => $agent['agent_slug'] ?? '', 'context' => $context );
		$artifacts[]    = extension_artifact( 'seed', array( 'label' => 'Seed', 'api_token' => 'secret-export-token' ) );
		$artifacts[]    = array(
			'artifact_type' => 'unknown_plugin_artifact',
			'artifact_id'   => 'ignored',
			'payload'       => array( 'ignored' => true ),
		);
		return $artifacts;
	},
	10,
	3
);

$current_payload = array( 'label' => 'Seed', 'api_token' => 'secret-export-token' );
add_filter(
	'datamachine_agent_bundle_current_artifacts',
	static function ( array $artifacts, array $agent, array $context ) use ( &$current_payload ): array {
		unset( $agent );
		assert_extension_bundle( 'current hook receives installed artifacts context', isset( $context['installed_artifacts'] ) );
		$artifacts[] = extension_artifact( 'seed', $current_payload );
		return $artifacts;
	},
	10,
	3
);

$applied = array();
add_filter(
	'datamachine_agent_bundle_apply_artifact',
	static function ( $result, array $artifact, array $agent, array $context ) use ( &$applied ) {
		unset( $context );
		if ( 'fake_plugin_artifact' !== ( $artifact['artifact_type'] ?? '' ) ) {
			return $result;
		}
		$applied[] = array( 'id' => $artifact['artifact_id'], 'agent' => $agent['agent_slug'] ?? '' );
		return array( 'applied' => $artifact['artifact_id'] );
	},
	10,
	4
);

echo "=== Agent Bundle Extension Artifact Smoke (#1577) ===\n";

echo "\n[1] Plugins register artifact types and export/current artifacts\n";
$types = DataMachine\Engine\Bundle\BundleSchema::artifact_types();
assert_extension_bundle( 'fake plugin type is registered', in_array( 'fake_plugin_artifact', $types, true ) );

$agent            = array( 'agent_id' => 7, 'agent_slug' => 'bundle-agent' );
$export_artifacts = AgentBundleArtifactExtensions::export_artifacts( $agent, array( 'phase' => 'export' ) );
assert_extension_bundle_equals( 'export hook saw agent slug', 'bundle-agent', $export_context['agent_slug'] ?? null );
assert_extension_bundle_equals( 'unknown artifact types are ignored', 1, count( $export_artifacts ) );
assert_extension_bundle_equals( 'export artifact payload preserved', 'Seed', $export_artifacts[0]['payload']['label'] ?? null );

$current_artifacts = AgentBundleArtifactExtensions::current_artifacts( $agent, array( 'installed-row' ), array( 'phase' => 'plan' ) );
assert_extension_bundle_equals( 'current hook returns fake artifact', 'fake_plugin_artifact', $current_artifacts[0]['artifact_type'] ?? null );

echo "\n[2] Directory bundles persist plugin artifacts under extensions/\n";
$manifest = new AgentBundleManifest(
	'2026-04-28T00:00:00Z',
	'data-machine/test',
	'Fake Bundle',
	'1.0.0',
	'',
	'',
	array(
		'slug'         => 'bundle-agent',
		'label'        => 'Bundle Agent',
		'description'  => '',
		'agent_config' => array(),
	),
	array(
		'memory'       => array(),
		'pipelines'    => array(),
		'flows'        => array(),
		'extensions'   => array( 'extensions/fake-plugin/seed.json' ),
		'handler_auth' => 'refs',
	)
);
$directory = new AgentBundleDirectory( $manifest, array(), array(), array(), $export_artifacts );
$tmp       = sys_get_temp_dir() . '/datamachine-extension-bundle-' . getmypid();
extension_rm_tree( $tmp );
$directory->write( $tmp );
assert_extension_bundle( 'extension artifact file written', is_file( $tmp . '/extensions/fake-plugin/seed.json' ) );
$read = AgentBundleDirectory::read( $tmp );
assert_extension_bundle_equals( 'extension artifact read from directory', 'seed', $read->extension_artifacts()[0]['artifact_id'] ?? null );
assert_extension_bundle_equals( 'legacy adapter preserves extension artifacts', 'seed', AgentBundleLegacyAdapter::to_legacy_bundle( $read )['extension_artifacts'][0]['artifact_id'] ?? null );

echo "\n[3] Planner treats plugin artifacts like core artifacts and redacts secrets\n";
$target_artifact = extension_artifact( 'seed', array( 'label' => 'Target', 'api_token' => 'target-secret-token' ) );
$installed       = AgentBundleInstalledArtifact::from_installed_payload( $manifest, 'fake_plugin_artifact', 'seed', 'extensions/fake-plugin/seed.json', $export_artifacts[0]['payload'], '2026-04-28T00:00:00Z' );
$current_payload = $export_artifacts[0]['payload'];
$clean_plan      = AgentBundleUpgradePlanner::plan( array( $installed ), AgentBundleArtifactExtensions::current_artifacts( $agent, array( $installed->to_array() ) ), array( $target_artifact ) )->to_array();
assert_extension_bundle_equals( 'clean installed plugin artifact auto-applies', 'fake_plugin_artifact:seed', $clean_plan['auto_apply'][0]['artifact_key'] ?? null );
assert_extension_bundle_equals( 'secret-like plugin target keys are redacted', '[redacted]', $clean_plan['auto_apply'][0]['diff']['after']['api_token'] ?? null );
assert_extension_bundle( 'raw plugin secret is absent from plan', false === strpos( json_encode( $clean_plan ), 'target-secret-token' ) );

$current_payload = array( 'label' => 'Locally edited', 'api_token' => 'local-secret-token' );
$modified_plan   = AgentBundleUpgradePlanner::plan( array( $installed ), AgentBundleArtifactExtensions::current_artifacts( $agent, array( $installed->to_array() ) ), array( $target_artifact ) )->to_array();
assert_extension_bundle_equals( 'locally modified plugin artifact needs approval', 'fake_plugin_artifact:seed', $modified_plan['needs_approval'][0]['artifact_key'] ?? null );
assert_extension_bundle( 'raw local plugin secret is absent from plan', false === strpos( json_encode( $modified_plan ), 'local-secret-token' ) );

echo "\n[4] PendingAction apply routes approved plugin artifacts to plugin callback\n";
$apply_result = AgentBundleUpgradePendingAction::apply(
	array(
		'agent'              => $agent,
		'approved_artifacts' => array( 'fake_plugin_artifact:seed' ),
		'target_artifacts'   => array( $target_artifact, extension_artifact( 'unapproved', array( 'label' => 'Skip' ) ) ),
	)
);
assert_extension_bundle( 'apply succeeds through plugin callback', true === ( $apply_result['success'] ?? false ) );
assert_extension_bundle_equals( 'plugin callback received approved artifact', array( array( 'id' => 'seed', 'agent' => 'bundle-agent' ) ), $applied );
assert_extension_bundle_equals( 'unapproved plugin artifact is staged/skipped', 1, count( $apply_result['skipped'] ?? array() ) );

extension_rm_tree( $tmp );

echo "\nTotal assertions: {$total}\n";
if ( 0 !== $failures ) {
	echo "Failures: {$failures}\n";
	exit( 1 );
}

echo "All assertions passed.\n";
