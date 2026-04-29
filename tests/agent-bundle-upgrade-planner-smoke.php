<?php
/**
 * Pure-PHP smoke test for bundle upgrade planning + PendingAction apply (#1532/#1533).
 *
 * Run with: php tests/agent-bundle-upgrade-planner-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['__bundle_upgrade_filters']    = array();
$GLOBALS['__bundle_upgrade_transients'] = array();

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
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( wp_strip_all_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 1;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return 'manage_options' === $capability;
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook = '' ) {
		return 0;
	}
}
if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook = '' ) {
		return false;
	}
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		return '11111111-2222-4333-8444-555555555555';
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['__bundle_upgrade_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['__bundle_upgrade_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['__bundle_upgrade_transients'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__bundle_upgrade_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
		ksort( $GLOBALS['__bundle_upgrade_filters'][ $hook ], SORT_NUMERIC );
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['__bundle_upgrade_filters'][ $hook ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['__bundle_upgrade_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $registration ) {
				$value = call_user_func_array( $registration[0], array_slice( array_merge( array( $value ), $args ), 0, $registration[1] ) );
			}
		}
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		apply_filters( $hook, null, ...$args );
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

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleUpgradeActionHandlers.php';

use DataMachine\Engine\AI\Actions\ResolvePendingActionAbility;
use DataMachine\Engine\Bundle\AgentBundleArtifactHasher;
use DataMachine\Engine\Bundle\AgentBundleDirectory;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use DataMachine\Engine\Bundle\AgentBundleUpgradePendingAction;
use DataMachine\Engine\Bundle\AgentBundleUpgradePlanner;
use DataMachine\Engine\Bundle\BundleSchema;

$failures = 0;
$total    = 0;

function assert_upgrade_plan( string $label, bool $condition ): void {
	global $failures, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	echo "  FAIL: {$label}\n";
	++$failures;
}

function assert_upgrade_plan_equals( string $label, $expected, $actual ): void {
	assert_upgrade_plan( $label, $expected === $actual );
}

function upgrade_artifact( string $type, string $id, $payload, string $source_path = '' ): array {
	return array(
		'artifact_type' => $type,
		'artifact_id'   => $id,
		'source_path'   => $source_path ?: $type . 's/' . $id . '.json',
		'payload'       => $payload,
	);
}

function installed_row( array $artifact ): array {
	return array(
		'bundle_slug'    => 'woocommerce-brain',
		'bundle_version' => '1.0.0',
		'artifact_type'  => $artifact['artifact_type'],
		'artifact_id'    => $artifact['artifact_id'],
		'source_path'    => $artifact['source_path'],
		'installed_hash' => AgentBundleArtifactHasher::hash( $artifact['payload'] ),
		'current_hash'   => AgentBundleArtifactHasher::hash( $artifact['payload'] ),
		'installed_at'   => '2026-04-28T00:00:00Z',
		'updated_at'     => '2026-04-28T00:00:00Z',
	);
}

echo "=== Agent Bundle Upgrade Planner Smoke (#1532/#1533) ===\n";

$old_flow       = upgrade_artifact( 'flow', 'daily-loop', array( 'prompt' => 'old flow' ), 'flows/daily-loop.json' );
$target_flow    = upgrade_artifact( 'flow', 'daily-loop', array( 'prompt' => 'new flow' ), 'flows/daily-loop.json' );
$old_pipeline   = upgrade_artifact( 'pipeline', 'collector', array( 'steps' => array( 'fetch', 'ai' ) ), 'pipelines/collector.json' );
$local_pipeline = upgrade_artifact( 'pipeline', 'collector', array( 'steps' => array( 'fetch', 'ai' ), 'note' => 'local edit' ), 'pipelines/collector.json' );
$target_pipeline = upgrade_artifact(
	'pipeline',
	'collector',
	array(
		'steps'     => array( 'fetch', 'ai', 'publish' ),
		'api_token' => 'secret-token-value',
	),
	'pipelines/collector.json'
);
$old_memory     = upgrade_artifact( 'memory', 'SOUL.md', "old\n", 'memory/SOUL.md' );
$target_memory  = upgrade_artifact( 'memory', 'SOUL.md', "new\n", 'memory/SOUL.md' );
$target_prompt  = upgrade_artifact( 'prompt', 'extract-facts', array( 'prompt' => 'same' ), 'prompts/extract-facts.json' );
$orphaned       = upgrade_artifact( 'rubric', 'legacy', array( 'score' => 1 ), 'rubrics/legacy.json' );

$target_artifacts = array( $target_flow, $target_pipeline, $target_memory, $target_prompt );
$plan             = AgentBundleUpgradePlanner::plan(
	array( installed_row( $old_flow ), installed_row( $old_pipeline ), installed_row( $old_memory ), installed_row( $orphaned ) ),
	array( $old_flow, $local_pipeline, $target_prompt ),
	$target_artifacts,
	array( 'bundle_slug' => 'woocommerce-brain', 'target_version' => '1.1.0' )
);
$plan_array       = $plan->to_array();

echo "\n[1] Planner buckets artifact changes deterministically\n";
assert_upgrade_plan_equals( 'clean local artifact auto-applies', 'flow:daily-loop', $plan_array['auto_apply'][0]['artifact_key'] ?? null );
assert_upgrade_plan_equals( 'modified local artifact needs approval', 'pipeline:collector', $plan_array['needs_approval'][0]['artifact_key'] ?? null );
assert_upgrade_plan_equals( 'missing installed artifact warns', 'memory:SOUL.md', $plan_array['warnings'][0]['artifact_key'] ?? null );
assert_upgrade_plan_equals( 'orphaned installed artifact warns', 'rubric:legacy', $plan_array['warnings'][1]['artifact_key'] ?? null );
assert_upgrade_plan_equals( 'already-target artifact is no-op', 'prompt:extract-facts', $plan_array['no_op'][0]['artifact_key'] ?? null );
assert_upgrade_plan_equals( 'counts expose machine-readable summary', array( 'auto_apply' => 1, 'needs_approval' => 1, 'warnings' => 2, 'no_op' => 1 ), $plan_array['counts'] );

echo "\n[2] Preview diff is artifact-level and secret-safe\n";
$approval = $plan_array['needs_approval'][0] ?? array();
assert_upgrade_plan( 'approval entry includes before payload', isset( $approval['diff']['before']['note'] ) );
assert_upgrade_plan_equals( 'secret-like target keys are redacted', '[redacted]', $approval['diff']['after']['api_token'] ?? null );
assert_upgrade_plan( 'raw secret value is absent from preview', false === strpos( (string) json_encode( $plan_array ), 'secret-token-value' ) );

echo "\n[3] PendingAction stages bundle-upgrade previews\n";
$staged = AgentBundleUpgradePendingAction::stage(
	$plan,
	array(
		'summary'            => 'Upgrade WooCommerce brain bundle.',
		'bundle'             => array( 'bundle_slug' => 'woocommerce-brain', 'target_version' => '1.1.0' ),
		'target_artifacts'   => $target_artifacts,
		'approved_artifacts' => array( 'pipeline:collector' ),
	)
);
assert_upgrade_plan( 'pending action staged', true === ( $staged['staged'] ?? false ) );
assert_upgrade_plan_equals( 'pending action kind is bundle_upgrade', 'bundle_upgrade', $staged['kind'] ?? null );
assert_upgrade_plan_equals( 'preview carries approval count', 1, $staged['preview']['counts']['needs_approval'] ?? null );

echo "\n[4] Resolve applies approved artifacts only\n";
$applied_keys = array();
add_filter(
	'datamachine_bundle_upgrade_apply_artifact',
	static function ( $result, array $artifact ) use ( &$applied_keys ) {
		$key            = sanitize_key( (string) $artifact['artifact_type'] ) . ':' . (string) $artifact['artifact_id'];
		$applied_keys[] = $key;
		return array( 'applied' => $key );
	},
	10,
	2
);

$resolved = ResolvePendingActionAbility::execute(
	array(
		'action_id' => $staged['action_id'],
		'decision'  => 'accepted',
	)
);
assert_upgrade_plan( 'resolve accepted succeeds', true === ( $resolved['success'] ?? false ) );
assert_upgrade_plan_equals( 'only approved artifact writer ran', array( 'pipeline:collector' ), $applied_keys );
assert_upgrade_plan_equals( 'apply result records one applied artifact', 1, count( $resolved['result']['applied'] ?? array() ) );
assert_upgrade_plan_equals( 'unapproved target artifacts are skipped', 3, count( $resolved['result']['skipped'] ?? array() ) );

echo "\n[5] Directory artifacts materialize every advertised artifact type\n";
$directory = new AgentBundleDirectory(
	new AgentBundleManifest(
		'2026-04-28T12:00:00Z',
		'data-machine/test',
		'Bundle Artifact Dirs',
		'1.0.0',
		'refs/heads/main',
		'abc123',
		array(
			'slug'         => 'bundle-agent',
			'label'        => 'Bundle Agent',
			'description'  => 'Tests materialized bundle artifacts.',
			'agent_config' => array(),
		),
		array(
			'memory'        => array( 'MEMORY.md' ),
			'pipelines'     => array(),
			'flows'         => array(),
			'prompts'       => array( 'extract-facts' ),
			'rubrics'       => array( 'wiki-quality' ),
			'tool_policies' => array( 'read-only-context' ),
			'auth_refs'     => array( 'github-default' ),
			'seed_queues'   => array( 'mgs-topic-loop' ),
			'handler_auth'  => 'refs',
		)
	),
	array( 'MEMORY.md' => "memory\n" ),
	array(),
	array(),
	array(
		BundleSchema::PROMPTS_DIR       => array( 'extract-facts.md' => "Extract facts.\n" ),
		BundleSchema::RUBRICS_DIR       => array( 'wiki-quality.json' => array( 'threshold' => 4 ) ),
		BundleSchema::TOOL_POLICIES_DIR => array( 'read-only-context.json' => array( 'allow' => array( 'datamachine/search' ) ) ),
		BundleSchema::AUTH_REFS_DIR     => array(
			'github-default.json' => array(
				'ref'        => 'github:default',
				'account_id' => 42,
				'api_key'    => 'do-not-show',
				'nested'     => array( 'refresh_token' => 'also-secret' ),
			),
		),
		BundleSchema::SEED_QUEUES_DIR   => array( 'mgs-topic-loop.json' => array( 'items' => array( 'launch history' ) ) ),
	)
);
$artifacts          = AgentBundleUpgradePlanner::artifacts_from_bundle( $directory );
$artifacts_by_key   = array();
foreach ( $artifacts as $artifact ) {
	$artifacts_by_key[ $artifact['artifact_type'] . ':' . $artifact['artifact_id'] ] = $artifact;
}
assert_upgrade_plan( 'prompt artifact emitted from prompts directory', isset( $artifacts_by_key['prompt:extract-facts'] ) );
assert_upgrade_plan( 'rubric artifact emitted from rubrics directory', isset( $artifacts_by_key['rubric:wiki-quality'] ) );
assert_upgrade_plan( 'tool policy artifact emitted from tool-policies directory', isset( $artifacts_by_key['tool_policy:read-only-context'] ) );
assert_upgrade_plan( 'auth ref artifact emitted from auth-refs directory', isset( $artifacts_by_key['auth_ref:github-default'] ) );
assert_upgrade_plan( 'seed queue artifact emitted from seed-queues directory', isset( $artifacts_by_key['seed_queue:mgs-topic-loop'] ) );
assert_upgrade_plan_equals( 'prompt source path is deterministic', 'prompts/extract-facts.md', $artifacts_by_key['prompt:extract-facts']['source_path'] ?? null );

$auth_plan = AgentBundleUpgradePlanner::plan( array(), array(), array( $artifacts_by_key['auth_ref:github-default'] ) )->to_array();
$auth_diff = $auth_plan['auto_apply'][0]['diff']['after'] ?? array();
assert_upgrade_plan_equals( 'auth ref api_key is redacted in preview', '[redacted]', $auth_diff['api_key'] ?? null );
assert_upgrade_plan_equals( 'auth ref nested refresh token is redacted in preview', '[redacted]', $auth_diff['nested']['refresh_token'] ?? null );
assert_upgrade_plan( 'auth ref preview does not leak raw secret values', false === strpos( (string) json_encode( $auth_plan ), 'do-not-show' ) && false === strpos( (string) json_encode( $auth_plan ), 'also-secret' ) );

echo "\nTotal assertions: {$total}\n";
// @phpstan-ignore-next-line Smoke assertions mutate this counter through a global helper.
if ( 0 !== $failures ) {
	echo "Failures: {$failures}\n";
	exit( 1 );
}

echo "All assertions passed.\n";
