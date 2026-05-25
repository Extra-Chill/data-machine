<?php
/**
 * Pure-PHP smoke test for end-to-end agent package portability.
 *
 * Run with: php tests/agent-bundle-package-portability-smoke.php
 *
 * @package DataMachine\Tests
 */

$failures = array();
$passes   = 0;

echo "agent-bundle-package-portability-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
agents_api_smoke_require_module();
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/register-agent-package-artifacts.php';

use DataMachine\Engine\Bundle\AgentBundleAdoptionStateStore;
use DataMachine\Engine\Bundle\AgentBundleArtifactHasher;
use DataMachine\Engine\Bundle\AgentBundleDirectory;
use DataMachine\Engine\Bundle\AgentBundleFlowFile;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use DataMachine\Engine\Bundle\AgentBundlePipelineFile;
use DataMachine\Engine\Bundle\AgentBundleUpgradePlanner;
use DataMachine\Engine\Bundle\AgentPackageProjection;
use DataMachine\Engine\Bundle\BundleSchema;

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return false;
	}
}

final class DataMachine_Portability_Test_State_Store implements WP_Agent_Package_Artifact_State_Store {
	/** @var array<int,array<string,mixed>|WP_Agent_Package_Installed_Artifact> */
	private array $installed;
	/** @var array<int,array<string,mixed>> */
	private array $current;
	/** @var array<int,array<string,mixed>> */
	private array $target;
	/** @var array<int,WP_Agent_Package_Installed_Artifact> */
	public array $recorded = array();

	/**
	 * @param array<int,array<string,mixed>|WP_Agent_Package_Installed_Artifact> $installed Installed snapshots.
	 * @param array<int,array<string,mixed>>                                     $current Current package rows.
	 * @param array<int,array<string,mixed>>                                     $target Target package rows.
	 */
	public function __construct( array $installed, array $current, array $target ) {
		$this->installed = $installed;
		$this->current   = $current;
		$this->target    = $target;
	}

	public function get_installed_artifacts( WP_Agent_Package $package, array $context = array() ): array {
		unset( $package, $context );
		return $this->installed;
	}

	public function get_current_artifacts( WP_Agent_Package $package, array $context = array() ): array {
		unset( $package, $context );
		return $this->current;
	}

	public function get_target_artifacts( WP_Agent_Package $package, array $context = array() ): array {
		unset( $package, $context );
		return $this->target;
	}

	public function record_installed_artifacts( WP_Agent_Package $package, array $artifacts, array $context = array() ): bool {
		unset( $package, $context );
		$this->recorded = $artifacts;
		return true;
	}
}

function datamachine_portability_assert( string $label, bool $condition ): void {
	global $failures, $passes;
	if ( $condition ) {
		++$passes;
		echo "  PASS {$label}\n";
		return;
	}

	$failures[] = $label;
	echo "  FAIL {$label}\n";
}

function datamachine_portability_assert_equals( string $label, $expected, $actual ): void {
	datamachine_portability_assert( $label, $expected === $actual );
	if ( $expected !== $actual ) {
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	}
}

function datamachine_portability_rm_tree( string $path ): void {
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

function datamachine_portability_directory( string $version, string $prompt, string $flow_schedule, int $queue_limit, string $extension_root ): AgentBundleDirectory {
	return new AgentBundleDirectory(
		new AgentBundleManifest(
			'2026-05-25T12:00:00Z',
			'data-machine/test',
			'Portable Agent Package',
			$version,
			'refs/heads/main',
			'abc' . str_replace( '.', '', $version ),
			array(
				'slug'         => 'Portable Agent',
				'label'        => 'Portable Agent',
				'description'  => 'Exercises portable package adoption.',
				'agent_config' => array( 'model' => 'gpt-5.5' ),
			),
			array(
				'memory'        => array(),
				'pipelines'     => array( 'daily-ingest' ),
				'flows'         => array( 'daily-ingest-flow' ),
				'prompts'       => array( 'system-prompt' ),
				'rubrics'       => array( 'quality-rubric' ),
				'tool_policies' => array( 'safe-tools' ),
				'auth_refs'     => array( 'github-default' ),
				'seed_queues'   => array( 'topic-loop' ),
				'extensions'    => array( 'extensions/intelligence/wiki-brain/portable.json' ),
				'handler_auth'  => 'refs',
			)
		),
		array(),
		array(
			new AgentBundlePipelineFile(
				'daily-ingest',
				'Daily ingest',
				array(
					array(
						'step_position' => 0,
						'step_type'     => 'fetch',
						'step_config'   => array( 'label' => 'Fetch source' ),
					),
				)
			),
		),
		array(
			new AgentBundleFlowFile(
				'daily-ingest-flow',
				'Daily ingest flow',
				'daily-ingest',
				$flow_schedule,
				array( 'mcp' => $queue_limit ),
				array(
					array(
						'step_position'   => 0,
						'step_type'       => 'fetch',
						'handler_configs' => array( 'mcp' => array( 'provider' => 'github' ) ),
					),
				)
			),
		),
		array(
			BundleSchema::PROMPTS_DIR       => array( 'system-prompt.md' => $prompt ),
			BundleSchema::RUBRICS_DIR       => array( 'quality-rubric.json' => array( 'min_score' => 4, 'version' => $version ) ),
			BundleSchema::TOOL_POLICIES_DIR => array( 'safe-tools.json' => array( 'allow' => array( 'datamachine/search' ), 'version' => $version ) ),
			BundleSchema::AUTH_REFS_DIR     => array( 'github-default.json' => array( 'ref' => 'github:default' ) ),
			BundleSchema::SEED_QUEUES_DIR   => array( 'topic-loop.json' => array( 'mode' => 'loop', 'items' => array( 'HPOS', 'Blocks' ), 'limit' => $queue_limit ) ),
		),
		array(
			array(
				'artifact_type' => 'intelligence/wiki-brain',
				'artifact_id'   => 'portable',
				'source_path'   => 'extensions/intelligence/wiki-brain/portable.json',
				'payload'       => array( 'root' => $extension_root ),
			),
		)
	);
}

function datamachine_portability_package_rows( AgentBundleDirectory $directory ): array {
	return AgentBundleAdoptionStateStore::package_artifact_rows( AgentBundleUpgradePlanner::artifacts_from_bundle( $directory ) );
}

function datamachine_portability_rows_by_key( array $rows ): array {
	$indexed = array();
	foreach ( $rows as $row ) {
		$key             = (string) $row['artifact_type'] . ':' . (string) $row['artifact_id'];
		$indexed[ $key ] = $row;
	}
	ksort( $indexed, SORT_STRING );
	return $indexed;
}

function datamachine_portability_installed_snapshots( WP_Agent_Package $package, array $rows ): array {
	$snapshots = array();
	foreach ( $rows as $row ) {
		$artifact = WP_Agent_Package_Artifact::from_array(
			array(
				'type'   => (string) $row['artifact_type'],
				'slug'   => (string) $row['artifact_id'],
				'source' => (string) $row['source'],
			)
		);
		$snapshots[] = WP_Agent_Package_Installed_Artifact::from_installed_payload( $package, $artifact, $row['payload'] ?? null, '2026-05-25T12:00:00Z' );
	}
	return $snapshots;
}

add_filter(
	'datamachine_agent_bundle_artifact_types',
	static function ( array $types ): array {
		$types[] = 'intelligence/wiki-brain';
		return $types;
	},
	10,
	1
);

add_filter(
	'datamachine_agent_bundle_apply_artifact',
	static function ( $result, array $artifact ): array {
		$GLOBALS['__datamachine_portability_applied'][] = $artifact;
		return array(
			'applied'       => true,
			'artifact_type' => $artifact['artifact_type'],
			'artifact_id'   => $artifact['artifact_id'],
		);
	},
	10,
	2
);

do_action( 'init' );
WP_Agent_Package_Artifacts_Registry::reset_for_tests();
wp_get_agent_package_artifact_types();

echo "\n[1] Bundle export/import preserves package identity and artifact coverage:\n";
$v1_directory = datamachine_portability_directory( '1.0.0', "Original prompt.\n", 'daily', 5, 'portable-v1' );
$export_path   = sys_get_temp_dir() . '/datamachine-portable-package-' . getmypid();
datamachine_portability_rm_tree( $export_path );
$v1_directory->write( $export_path );
$imported_directory = AgentBundleDirectory::read( $export_path );
$imported_package   = AgentPackageProjection::from_directory( $imported_directory );
$imported_rows      = datamachine_portability_package_rows( $imported_directory );
$imported_by_key    = datamachine_portability_rows_by_key( $imported_rows );

datamachine_portability_assert_equals( 'package slug survives export/import', 'portable-agent-package', $imported_package->get_slug() );
datamachine_portability_assert_equals( 'package version survives export/import', '1.0.0', $imported_package->get_version() );
datamachine_portability_assert_equals( 'agent config projects through WP_Agent', 'gpt-5.5', $imported_package->get_agent()->get_default_config()['model'] ?? null );
datamachine_portability_assert( 'pipeline artifact round-trips', isset( $imported_by_key['datamachine/pipeline:daily-ingest'] ) );
datamachine_portability_assert( 'flow artifact round-trips', isset( $imported_by_key['datamachine/flow:daily-ingest-flow'] ) );
datamachine_portability_assert( 'prompt artifact round-trips', isset( $imported_by_key['datamachine/prompt:system-prompt'] ) );
datamachine_portability_assert( 'rubric artifact round-trips', isset( $imported_by_key['datamachine/rubric:quality-rubric'] ) );
datamachine_portability_assert( 'tool policy artifact round-trips', isset( $imported_by_key['datamachine/tool-policy:safe-tools'] ) );
datamachine_portability_assert( 'auth ref artifact round-trips', isset( $imported_by_key['datamachine/auth-ref:github-default'] ) );
datamachine_portability_assert( 'queue seed artifact round-trips', isset( $imported_by_key['datamachine/seed-queue:topic-loop'] ) );
datamachine_portability_assert( 'extension artifact round-trips with namespaced type', isset( $imported_by_key['intelligence/wiki-brain:portable'] ) );

echo "\n[2] Agents API adoption plan preserves local edits while auto-applying clean artifacts:\n";
$v2_directory = datamachine_portability_directory( '1.1.0', "Updated remote prompt.\n", 'hourly', 10, 'portable-v2' );
$target_rows  = datamachine_portability_package_rows( $v2_directory );
$current_rows = $imported_rows;
foreach ( $current_rows as &$row ) {
	$key = (string) $row['artifact_type'] . ':' . (string) $row['artifact_id'];
	if ( 'datamachine/prompt:system-prompt' === $key ) {
		$row['payload'] = "Locally customized prompt.\n";
	}
	if ( 'datamachine/flow:daily-ingest-flow' === $key && is_array( $row['payload'] ?? null ) ) {
		$row['payload']['schedule'] = 'manual-local';
	}
	unset( $row['hash'] );
}
unset( $row );

$store        = new DataMachine_Portability_Test_State_Store( datamachine_portability_installed_snapshots( $imported_package, $imported_rows ), $current_rows, $target_rows );
$orchestrator = new WP_Agent_Package_Adoption_Orchestrator( $store );
$plan         = $orchestrator->plan( AgentPackageProjection::from_directory( $v2_directory ), array( 'timestamp' => '2026-05-25T13:00:00Z' ) );

datamachine_portability_assert_equals( 'local prompt and flow edits require approval', 2, count( $plan->get_bucket( 'needs_approval' ) ) );
datamachine_portability_assert( 'clean package artifacts remain auto-applyable', count( $plan->get_bucket( 'auto_apply' ) ) >= 4 );

$result = $orchestrator->adopt(
	new WP_Agent_Package_Adoption_Request(
		AgentPackageProjection::from_directory( $v2_directory ),
		array(
			'operation'              => 'upgrade',
			'approved_artifact_keys' => array( 'datamachine/prompt:system-prompt' ),
			'context'                => array(
				'timestamp' => '2026-05-25T13:00:00Z',
				'agent'     => array( 'agent_slug' => 'portable-agent' ),
			),
		)
	)
);

$applied_keys = array();
foreach ( $result->get_applied_artifacts() as $entry ) {
	$applied_keys[] = (string) $entry['artifact_key'];
}
sort( $applied_keys, SORT_STRING );

$skipped_keys = array();
foreach ( $result->get_skipped_artifacts() as $entry ) {
	$skipped_keys[] = (string) $entry['artifact_key'];
}
sort( $skipped_keys, SORT_STRING );

$recorded_payloads = array();
foreach ( $store->recorded as $snapshot ) {
	$recorded_payloads[ $snapshot->get_artifact_type() . ':' . $snapshot->get_artifact_id() ] = $snapshot->get_installed_payload();
}

datamachine_portability_assert_equals( 'approval-gated adoption is partial while flow edit remains skipped', 'partial', $result->get_status() );
datamachine_portability_assert( 'approved prompt applies through Data Machine package callback', in_array( 'datamachine/prompt:system-prompt', $applied_keys, true ) );
datamachine_portability_assert( 'locally edited flow is skipped without approval', in_array( 'datamachine/flow:daily-ingest-flow', $skipped_keys, true ) );
datamachine_portability_assert_equals( 'approved prompt snapshot records target payload', "Updated remote prompt.\n", $recorded_payloads['datamachine/prompt:system-prompt'] ?? null );
datamachine_portability_assert_equals( 'skipped flow keeps local schedule out of recorded snapshots', false, isset( $recorded_payloads['datamachine/flow:daily-ingest-flow'] ) );
datamachine_portability_assert( 'extension artifact applied through namespaced package type', in_array( 'intelligence/wiki-brain:portable', $applied_keys, true ) );
datamachine_portability_assert( 'Data Machine materializer saw applied artifacts', count( $GLOBALS['__datamachine_portability_applied'] ?? array() ) >= count( $applied_keys ) );

datamachine_portability_rm_tree( $export_path );
agents_api_smoke_finish( 'Data Machine agent package portability', $failures, $passes );
