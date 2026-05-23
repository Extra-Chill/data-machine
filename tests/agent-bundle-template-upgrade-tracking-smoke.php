<?php
/**
 * Pure-PHP smoke test for template source/version reporting and local artifact classification (#1537).
 *
 * Run with: php tests/agent-bundle-template-upgrade-tracking-smoke.php
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
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( trim( (string) $title ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
		return trim( (string) $title, '-' );
	}
}
if ( ! class_exists( 'WP_CLI_Command' ) ) {
	class WP_CLI_Command {}
}
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static function line( $message = '' ): void {
			echo $message . "\n";
		}
		public static function log( $message = '' ): void {
			echo $message . "\n";
		}
		public static function error( $message = '' ): void {
			throw new RuntimeException( (string) $message );
		}
	}
}

require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleValidationException.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSchema.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/PortableSlug.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleArtifactExtensions.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleAgentConfig.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleArtifactHasher.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleArtifactStatus.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentTemplateMetadata.php';
require_once dirname( __DIR__ ) . '/inc/Cli/BaseCommand.php';
require_once dirname( __DIR__ ) . '/inc/Cli/Commands/AgentBundleCommand.php';

use DataMachine\Cli\Commands\AgentBundleCommand;
use DataMachine\Engine\Bundle\AgentBundleAgentConfig;
use DataMachine\Engine\Bundle\AgentBundleArtifactHasher;
use DataMachine\Engine\Bundle\AgentBundleArtifactStatus;
use DataMachine\Engine\Bundle\AgentTemplateMetadata;

$failures = 0;
$total    = 0;

function template_tracking_assert( string $label, bool $condition ): void {
	global $failures, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	echo "  FAIL: {$label}\n";
	++$failures;
}

function template_tracking_assert_equals( string $label, $expected, $actual ): void {
	template_tracking_assert( $label, $expected === $actual );
}

final class TemplateTrackingCommand extends AgentBundleCommand {
	private array $current;

	public function __construct( array $current ) {
		$this->current = $current;
	}

	public function expose_status( array $agent ): array {
		return $this->installed_status( $agent );
	}

	protected function current_artifacts( array $agent, array $installed ): array {
		unset( $agent, $installed );
		return $this->current;
	}
}

echo "=== Agent Bundle Template Upgrade Tracking Smoke (#1537) ===\n";

$bundle = array(
	'bundle_slug'     => 'wordpress-com-brain',
	'bundle_version'  => '1.2.3',
	'template_slug'   => 'domain-wiki-template',
	'template_version' => '4.5.6',
	'source_ref'      => 'https://github.com/Automattic/intelligence/releases/download/v1.2.3/wpcom.zip',
	'source_revision' => 'etag-abc123',
	'agent'           => array( 'agent_slug' => 'wordpress-com-brain' ),
);

echo "\n[1] Bundle arrays produce template source/version metadata\n";
$metadata = AgentTemplateMetadata::from_bundle_array( $bundle )->to_array();
template_tracking_assert_equals( 'template slug comes from bundle metadata', 'domain-wiki-template', $metadata['template_slug'] );
template_tracking_assert_equals( 'template version comes from bundle metadata', '4.5.6', $metadata['template_version'] );
template_tracking_assert_equals( 'bundle version remains distinct', '1.2.3', $metadata['bundle_version'] );
template_tracking_assert_equals( 'source revision is preserved', 'etag-abc123', $metadata['source_revision'] );

echo "\n[2] Installed status reports source/version and live local modification state\n";
$installed_agent_config = array( 'model' => 'openai/gpt-5.5' );
$installed_pipeline     = array(
	'portable_slug'   => 'daily-sync',
	'pipeline_name'   => 'Daily Sync',
	'pipeline_config' => array( 'step' => array( 'max_items' => 25 ) ),
);
$current_pipeline       = array(
	'portable_slug'   => 'daily-sync',
	'pipeline_name'   => 'Daily Sync',
	'pipeline_config' => array( 'step' => array( 'max_items' => 1 ) ),
);

$agent = array(
	'agent_id'     => 123,
	'agent_slug'   => 'wordpress-com-brain',
	'agent_config' => array(
		'model'              => 'openai/gpt-5.5',
		'datamachine_bundle' => array(
			'template_slug'    => 'domain-wiki-template',
			'template_version' => '4.5.6',
			'bundle_slug'      => 'wordpress-com-brain',
			'bundle_version'   => '1.2.3',
			'source_ref'       => 'https://github.com/Automattic/intelligence/releases/download/v1.2.3/wpcom.zip',
			'source_revision'  => 'etag-abc123',
			'artifacts'        => array(
				'agent_config:config' => array(
					'artifact_type'  => 'agent_config',
					'artifact_id'    => 'config',
					'source_path'    => 'manifest.json#/agent/agent_config',
					'installed_hash' => AgentBundleArtifactHasher::hash( $installed_agent_config ),
				),
				'pipeline:daily-sync' => array(
					'artifact_type'  => 'pipeline',
					'artifact_id'    => 'daily-sync',
					'source_path'    => 'pipelines/daily-sync.json',
					'installed_hash' => AgentBundleArtifactHasher::hash( $installed_pipeline ),
				),
			),
		),
	),
);

$command = new TemplateTrackingCommand(
	array(
		array(
			'artifact_type' => 'agent_config',
			'artifact_id'   => 'config',
			'payload'       => AgentBundleAgentConfig::tracked_payload( $agent['agent_config'] ),
		),
		array(
			'artifact_type' => 'pipeline',
			'artifact_id'   => 'daily-sync',
			'payload'       => $current_pipeline,
		),
	)
);
$status  = $command->expose_status( $agent );
$records = array_column( $status['artifacts'], null, 'artifact_id' );

template_tracking_assert_equals( 'status reports template slug', 'domain-wiki-template', $status['template_slug'] );
template_tracking_assert_equals( 'status reports template version', '4.5.6', $status['template_version'] );
template_tracking_assert_equals( 'status reports bundle source', $bundle['source_ref'], $status['source_ref'] );
template_tracking_assert_equals( 'unchanged agent config is clean', AgentBundleArtifactStatus::CLEAN, $records['config']['status'] ?? null );
template_tracking_assert_equals( 'changed pipeline is modified', AgentBundleArtifactStatus::MODIFIED, $records['daily-sync']['status'] ?? null );

echo "\nTotal assertions: {$total}\n";
if ( 0 !== $failures ) {
	echo "Failures: {$failures}\n";
	exit( 1 );
}

echo "All assertions passed.\n";
