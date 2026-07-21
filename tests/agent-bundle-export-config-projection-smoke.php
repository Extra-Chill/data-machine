<?php
/**
 * Round-trip coverage for portable agent config export projection (#2913).
 *
 * Run with: php tests/agent-bundle-export-config-projection-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
		return trim( (string) $title, '-' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
	}
}

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

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	$GLOBALS['export_config_filters'] = array();
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['export_config_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['export_config_filters'][ $hook ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['export_config_filters'][ $hook ], SORT_NUMERIC );
		foreach ( $GLOBALS['export_config_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = $callback[0]( ...array_slice( array_merge( array( $value ), $args ), 0, $callback[1] ) );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ) {
		unset( $args );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {
		unset( $args );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Engine\Bundle\AgentBundleArrayAdapter;

final class ExportConfigAgentsRepository extends Agents {
	public function __construct( private array $agent ) {
	}

	public function get_by_slug( string $agent_slug ): ?array {
		return $agent_slug === $this->agent['agent_slug'] ? $this->agent : null;
	}
}

final class ExportConfigPipelinesRepository extends Pipelines {
	public function __construct() {
	}

	public function get_all_pipelines( ?int $user_id = null, ?int $agent_id = null, ?string $search = null, ?int $per_page = null, int $offset = 0 ): array {
		unset( $user_id, $agent_id, $search, $per_page, $offset );
		return array();
	}
}

final class ExportConfigFlowsRepository extends Flows {
	public function __construct() {
	}

	public function get_all_flows( ?int $user_id = null, ?int $agent_id = null ): array {
		unset( $user_id, $agent_id );
		return array();
	}
}

final class ExportConfigDirectoryManager extends DirectoryManager {
	public function get_agent_identity_directory( string $agent_slug ): string {
		return sys_get_temp_dir() . '/missing-agent-' . $agent_slug;
	}

	public function get_user_directory( int $user_id ): string {
		return sys_get_temp_dir() . '/missing-user-' . $user_id;
	}
}

$failures = array();
$passes   = 0;

function export_config_assert( string $label, bool $condition ): void {
	global $failures, $passes;
	if ( $condition ) {
		++$passes;
		echo "  PASS: {$label}\n";
		return;
	}

	$failures[] = $label;
	echo "  FAIL: {$label}\n";
}

function export_config_assert_equals( string $label, $expected, $actual ): void {
	export_config_assert( $label, $expected === $actual );
}

function export_config_bundler( array $agent ): AgentBundler {
	$reflection = new ReflectionClass( AgentBundler::class );
	$bundler    = $reflection->newInstanceWithoutConstructor();
	$properties = array(
		'agents_repo'       => new ExportConfigAgentsRepository( $agent ),
		'pipelines_repo'    => new ExportConfigPipelinesRepository(),
		'flows_repo'        => new ExportConfigFlowsRepository(),
		'directory_manager' => new ExportConfigDirectoryManager(),
	);

	foreach ( $properties as $name => $value ) {
		$property = $reflection->getProperty( $name );
		$property->setValue( $bundler, $value );
	}

	return $bundler;
}

$agent = array(
	'agent_id'     => 2913,
	'agent_slug'   => 'events-bot',
	'agent_name'   => 'Events Bot',
	'owner_id'     => 1,
	'site_scope'   => 7,
	'agent_config' => array(
		'provider'                => 'openai',
		'model'                   => 'gpt-5.5',
		'tool_policy'             => array( 'allow' => array( 'datamachine/search' ) ),
		'intelligence_wiki_brain' => array( 'graph' => array( 'enabled' => true ) ),
		'intelligence'            => array(
			'context_servers' => array( 'events' => array( 'url' => 'https://runtime.example.test' ) ),
			'auth_refs'       => array( 'events' => 'runtime:events' ),
		),
		'plugin_runtime'          => array( 'endpoint' => 'https://plugin-runtime.example.test' ),
		'backup_private'          => array( 'token' => 'do-not-export' ),
		'datamachine_bundle'      => array(
			'adopted'          => true,
			'bundle_slug'      => 'events-bot',
			'bundle_version'   => '1.2.3',
			'template_slug'    => 'events-template',
			'template_version' => '4.5.6',
			'source_ref'       => 'refs/heads/main',
			'source_revision'  => 'abc123',
		),
	),
);

add_filter(
	'datamachine_agent_config_artifact_projection_policies',
	static function ( array $policies ): array {
		$policies['plugin_runtime'] = array(
			'tracking' => 'exclude',
			'merge'    => 'preserve_local',
			'reason'   => 'preserve_plugin_runtime_config',
		);
		$policies['backup_private'] = array(
			'tracking'      => 'exclude',
			'backup_egress' => 'exclude',
			'merge'         => 'preserve_local',
			'reason'        => 'exclude_private_backup_config',
		);
		return $policies;
	}
);

echo "=== Agent Bundle Export Config Projection Smoke (#2913) ===\n";

foreach ( array( 'share', 'backup', 'fork' ) as $profile ) {
	echo "\n[{$profile}] Adopted agent export remains portable\n";
	$result    = export_config_bundler( $agent )->export_directory_object( 'events-bot', array( 'profile' => $profile ) );
	$directory = $result['directory'] ?? null;
	export_config_assert( "{$profile} export succeeds", ! empty( $result['success'] ) && null !== $directory );
	if ( null === $directory ) {
		continue;
	}

	$round_trip = AgentBundleArrayAdapter::to_array_bundle( $directory );
	$config     = $round_trip['agent']['agent_config'] ?? array();
	export_config_assert( "{$profile} omits runtime bundle metadata", ! array_key_exists( 'datamachine_bundle', $config ) );
	if ( 'backup' === $profile ) {
		export_config_assert_equals( 'backup keeps restorable plugin runtime context', 'https://runtime.example.test', $config['intelligence']['context_servers']['events']['url'] ?? null );
		export_config_assert_equals( 'backup keeps restorable plugin auth refs', 'runtime:events', $config['intelligence']['auth_refs']['events'] ?? null );
		export_config_assert_equals( 'backup keeps tracking-excluded plugin config', 'https://plugin-runtime.example.test', $config['plugin_runtime']['endpoint'] ?? null );
	} else {
		export_config_assert( "{$profile} omits plugin runtime context", ! isset( $config['intelligence']['context_servers'] ) );
		export_config_assert( "{$profile} omits plugin runtime auth refs", ! isset( $config['intelligence']['auth_refs'] ) );
		export_config_assert( "{$profile} omits tracking-excluded plugin config", ! isset( $config['plugin_runtime'] ) );
	}
	export_config_assert( "{$profile} honors explicit backup-private exclusion", ! isset( $config['backup_private'] ) );
	export_config_assert_equals( "{$profile} keeps normalized provider", 'openai', $config['default_provider'] ?? null );
	export_config_assert_equals( "{$profile} keeps normalized model", 'gpt-5.5', $config['default_model'] ?? null );
	export_config_assert_equals( "{$profile} keeps tool policy", array( 'datamachine/search' ), $config['tool_policy']['allow'] ?? null );
	export_config_assert_equals( "{$profile} keeps plugin bundle-owned policy", true, $config['intelligence_wiki_brain']['graph']['enabled'] ?? null );
	export_config_assert_equals( "{$profile} keeps site scope", 7, $round_trip['agent']['site_scope'] ?? null );
}

if ( isset( $GLOBALS['wpdb'] ) && function_exists( 'get_current_user_id' ) ) {
	echo "\n[restore] Real WordPress export imports into a fresh agent\n";
	$source_slug   = 'events-bot-source-' . getmypid();
	$restored_slug = 'events-bot-restored-' . getmypid();
	$owner_id      = max( 1, get_current_user_id() );
	$repository    = new Agents();
	$source_id     = $repository->create_if_missing( $source_slug, 'Events Bot Source', $owner_id, $agent['agent_config'], 7 );
	export_config_assert( 'real source agent is created', false !== $source_id && $source_id > 0 );

	if ( false !== $source_id && $source_id > 0 ) {
		$export          = ( new AgentBundler() )->export_directory_object( $source_slug, array( 'profile' => 'backup' ) );
		$backup          = isset( $export['directory'] ) ? AgentBundleArrayAdapter::to_array_bundle( $export['directory'] ) : array();
		$backup_config   = $backup['agent']['agent_config'] ?? array();
		$restore         = ( new AgentBundler() )->import( $backup, $restored_slug, $owner_id );
		$restored_agent  = $repository->get_by_slug( $restored_slug );
		$restored_config = $restored_agent['agent_config'] ?? array();

		export_config_assert( 'real backup omits source package metadata', ! isset( $backup_config['datamachine_bundle'] ) );
		export_config_assert( 'fresh restore succeeds', ! empty( $restore['success'] ) );
		export_config_assert_equals( 'fresh restore keeps plugin runtime context', 'https://runtime.example.test', $restored_config['intelligence']['context_servers']['events']['url'] ?? null );
		export_config_assert_equals( 'fresh restore keeps plugin auth refs', 'runtime:events', $restored_config['intelligence']['auth_refs']['events'] ?? null );
		export_config_assert_equals( 'fresh restore keeps tracking-excluded plugin config', 'https://plugin-runtime.example.test', $restored_config['plugin_runtime']['endpoint'] ?? null );
		export_config_assert( 'fresh restore honors explicit backup-private exclusion', ! isset( $restored_config['backup_private'] ) );
		export_config_assert_equals( 'fresh restore keeps normalized provider', 'openai', $restored_config['default_provider'] ?? null );
		export_config_assert_equals( 'fresh restore keeps normalized model', 'gpt-5.5', $restored_config['default_model'] ?? null );
		export_config_assert_equals( 'fresh restore keeps site scope', 7, $restored_agent['site_scope'] ?? null );

		$table = $GLOBALS['wpdb']->base_prefix . Agents::TABLE_NAME;
		$GLOBALS['wpdb']->delete( $table, array( 'agent_id' => (int) $source_id ), array( '%d' ) );
		if ( is_array( $restored_agent ) ) {
			$GLOBALS['wpdb']->delete( $table, array( 'agent_id' => (int) $restored_agent['agent_id'] ), array( '%d' ) );
		}
	}
}

if ( ! empty( $failures ) ) {
	echo "\nFAILED: " . count( $failures ) . " export config projection assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} export config projection assertions passed.\n";
