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
if ( ! defined( 'DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK' ) ) {
	define( 'DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK', true );
}

$GLOBALS['__bundle_upgrade_filters']    = array();
$GLOBALS['__bundle_upgrade_transients'] = array();
$GLOBALS['__bundle_upgrade_actions']    = array();

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
if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function_name, $message, $version ) {
		unset( $function_name, $message, $version );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$slug = strtolower( (string) $title );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
		return trim( (string) $slug, '-' );
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
		return 'init' === $hook ? 1 : 0;
	}
}
if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook = '' ) {
		return in_array( $hook, $GLOBALS['__bundle_upgrade_actions'], true );
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
		$GLOBALS['__bundle_upgrade_actions'][] = $hook;
		apply_filters( $hook, null, ...$args );
		array_pop( $GLOBALS['__bundle_upgrade_actions'] );
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
if ( ! class_exists( 'AgentsAPI\\Core\\Workspace\\WP_Agent_Workspace_Scope' ) ) {
	eval( '
	namespace AgentsAPI\\Core\\Workspace;
	class WP_Agent_Workspace_Scope {
		private string $type;
		private string $id;
		private function __construct( string $type, string $id ) { $this->type = $type; $this->id = $id; }
		public static function from_parts( string $type, string $id ): self { return new self( $type, $id ); }
		public static function from_array( array $data ): self { return new self( (string) ( $data["type"] ?? "site" ), (string) ( $data["id"] ?? "test" ) ); }
		public function to_array(): array { return array( "type" => $this->type, "id" => $this->id ); }
	}
	' );
}
if ( ! class_exists( 'AgentsAPI\\AI\\Approvals\\WP_Agent_Pending_Action_Status' ) ) {
	eval( '
	namespace AgentsAPI\\AI\\Approvals;
	final class WP_Agent_Pending_Action_Status {
		public const PENDING = "pending";
		public const ACCEPTED = "accepted";
		public const REJECTED = "rejected";
		public const EXPIRED = "expired";
		public const DELETED = "deleted";
		public static function normalize( string $status ): string { return $status; }
	}
	' );
}
if ( ! class_exists( 'AgentsAPI\\AI\\WP_Agent_Message' ) ) {
	eval( '
	namespace AgentsAPI\\AI;
	final class WP_Agent_Message {
		public const SCHEMA = "wp-agent-message";
		public const VERSION = "1.0.0";
		public const TYPE_APPROVAL_REQUIRED = "approval_required";
		public static function approvalRequired( string $summary, array $payload, array $metadata ): array {
			$pending = $payload["pending_action"] ?? array();
			return array(
				"schema" => self::SCHEMA,
				"version" => self::VERSION,
				"type" => self::TYPE_APPROVAL_REQUIRED,
				"role" => "tool",
				"content" => $summary,
				"payload" => $payload,
				"metadata" => $metadata,
				"kind" => $pending["kind"] ?? "",
				"preview" => $pending["preview"] ?? array(),
			);
		}
	}
	' );
}
if ( ! class_exists( 'AgentsAPI\\AI\\Approvals\\WP_Agent_Approval_Decision' ) ) {
	eval( '
	namespace AgentsAPI\\AI\\Approvals;
	final class WP_Agent_Approval_Decision {
		private string $value;
		private function __construct( string $value ) { $this->value = $value; }
		public static function from_string( string $value ): self {
			if ( ! in_array( $value, array( "accepted", "rejected" ), true ) ) { throw new \\InvalidArgumentException( "invalid decision" ); }
			return new self( $value );
		}
		public function value(): string { return $this->value; }
		public function is_rejected(): bool { return "rejected" === $this->value; }
	}
	' );
}
if ( ! class_exists( 'AgentsAPI\\AI\\Approvals\\WP_Agent_Pending_Action' ) ) {
	eval( '
	namespace AgentsAPI\\AI\\Approvals;
	final class WP_Agent_Pending_Action {
		private array $data;
		private function __construct( array $data ) { $this->data = $data; }
		public static function from_array( array $data ): self { return new self( $data ); }
		public function to_array(): array { return $this->data; }
	}
	' );
}
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/register-agent-package-artifacts.php';
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
	$ok = $expected === $actual;
	assert_upgrade_plan( $label, $ok );
	if ( ! $ok ) {
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	}
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

echo "\n[2b] Agent config drift is surfaced like artifact conflicts\n";
$old_agent_config = upgrade_artifact(
	'agent_config',
	'config',
	array(
		'intelligence' => array(
			'context_servers' => array(
				'wporg' => array( 'transport' => 'stdio' ),
			),
		),
	),
	'manifest.json#/agent/agent_config'
);
$local_agent_config = upgrade_artifact(
	'agent_config',
	'config',
	array(
		'intelligence' => array(
			'context_servers' => array(
				'wporg' => array(
					'transport' => 'streamable-http',
					'headers'   => array( 'Authorization' => 'Bearer local-token' ),
				),
			),
		),
	),
	'manifest.json#/agent/agent_config'
);
$target_agent_config = upgrade_artifact(
	'agent_config',
	'config',
	array(
		'intelligence' => array(
			'context_servers' => array(
				'wporg' => array( 'transport' => 'stdio', 'command' => 'node' ),
			),
		),
	),
	'manifest.json#/agent/agent_config'
);
$config_plan       = AgentBundleUpgradePlanner::plan(
	array( installed_row( $old_agent_config ) ),
	array( $local_agent_config ),
	array( $target_agent_config ),
	array( 'bundle_slug' => 'wordpress-core-wiki' )
)->to_array();
$config_conflict   = $config_plan['needs_approval'][0] ?? array();
assert_upgrade_plan_equals( 'locally changed context server needs approval', 'agent_config:config', $config_conflict['artifact_key'] ?? null );
assert_upgrade_plan_equals( 'agent config reason is local modified', 'local_modified', $config_conflict['reason'] ?? null );
assert_upgrade_plan_equals( 'authorization header is redacted', '[redacted]', $config_conflict['diff']['before']['intelligence']['context_servers']['wporg']['headers']['Authorization'] ?? null );
assert_upgrade_plan( 'raw local bearer is absent from config preview', false === strpos( (string) json_encode( $config_plan ), 'local-token' ) );

echo "\n[2c] Prompt/rubric artifact edits get readable approval diffs\n";
$old_prompt      = upgrade_artifact( 'prompt', 'extract-facts', "Extract facts.\n", 'prompts/extract-facts.md' );
$local_prompt    = upgrade_artifact( 'prompt', 'extract-facts', "Extract facts and cite local sources.\n", 'prompts/extract-facts.md' );
$target_prompt   = upgrade_artifact( 'prompt', 'extract-facts', "Extract facts and cite bundle sources.\n", 'prompts/extract-facts.md' );
$prompt_plan     = AgentBundleUpgradePlanner::plan(
	array( installed_row( $old_prompt ) ),
	array( $local_prompt ),
	array( $target_prompt ),
	array( 'bundle_slug' => 'prompt-bundle' )
)->to_array();
$prompt_conflict = $prompt_plan['needs_approval'][0] ?? array();
assert_upgrade_plan_equals( 'locally changed prompt needs approval', 'prompt:extract-facts', $prompt_conflict['artifact_key'] ?? null );
assert_upgrade_plan_equals( 'prompt approval includes readable local text', "Extract facts and cite local sources.\n", $prompt_conflict['diff']['before'] ?? null );
assert_upgrade_plan_equals( 'prompt approval includes readable target text', "Extract facts and cite bundle sources.\n", $prompt_conflict['diff']['after'] ?? null );

echo "\n[2d] Flow runtime overlays are planned separately from source shape\n";
$installed_runtime_flow = upgrade_artifact(
	'flow',
	'queued-fetch',
	array(
		'portable_slug'     => 'queued-fetch',
		'flow_name'         => 'Queued Fetch',
		'flow_config'       => array(
			'1_fetch_1' => array(
				'handler_configs' => array( 'mcp' => array( 'provider' => 'mgs' ) ),
			),
		),
		'scheduling_policy' => 'create_paused_upgrade_preserve_existing',
		'queue_policy'      => 'create_seed_upgrade_preserve_existing',
		'runtime_overlays'  => array(
			'scheduling_config' => array(
				'enabled'   => false,
				'interval'  => 'manual',
				'max_items' => array( 'mcp' => 5 ),
			),
			'steps'             => array(
				'1_fetch_1' => array(
					'config_patch_queue'     => array( array( 'patch' => array( 'query' => 'seed' ) ) ),
					'queue_mode'             => 'loop',
					'_queue_consume_revision' => 'seed-rev',
					'handler_configs'        => array( 'mcp' => array( 'max_items' => 5 ) ),
				),
			),
		),
	),
	'flows/queued-fetch.json'
);
$current_runtime_flow   = $installed_runtime_flow;
$target_runtime_flow    = $installed_runtime_flow;
$target_runtime_flow['payload']['runtime_overlays']['scheduling_config'] = array(
	'enabled'   => true,
	'interval'  => 'hourly',
	'max_items' => array( 'mcp' => 50 ),
);
$target_runtime_flow['payload']['runtime_overlays']['steps']['1_fetch_1']['config_patch_queue'] = array(
	array( 'patch' => array( 'query' => 'target-a' ) ),
	array( 'patch' => array( 'query' => 'target-b' ) ),
);
$target_runtime_flow['payload']['runtime_overlays']['steps']['1_fetch_1']['queue_mode'] = 'drain';
$target_runtime_flow['payload']['runtime_overlays']['steps']['1_fetch_1']['handler_configs']['mcp']['max_items'] = 50;
$runtime_plan = AgentBundleUpgradePlanner::plan(
	array( installed_row( $installed_runtime_flow ) ),
	array( $current_runtime_flow ),
	array( $target_runtime_flow ),
	array( 'bundle_slug' => 'runtime-overlay-brain' )
)->to_array();
$runtime_update = $runtime_plan['auto_apply'][0] ?? array();
assert_upgrade_plan_equals( 'bundle seed overlay change auto-applies when source shape is clean', 'flow:queued-fetch', $runtime_update['artifact_key'] ?? null );
assert_upgrade_plan_equals( 'runtime overlay seed change is not hidden', 2, count( $runtime_update['diff']['after']['runtime_overlays']['steps']['1_fetch_1']['config_patch_queue'] ?? array() ) );
assert_upgrade_plan_equals( 'manual paused seed is visible in before diff', false, $runtime_update['diff']['before']['runtime_overlays']['scheduling_config']['enabled'] ?? null );
assert_upgrade_plan_equals( 'scheduled seed is visible in after diff', 'hourly', $runtime_update['diff']['after']['runtime_overlays']['scheduling_config']['interval'] ?? null );
assert_upgrade_plan_equals( 'burn-in max_items seed drift remains visible', 50, $runtime_update['diff']['after']['runtime_overlays']['steps']['1_fetch_1']['handler_configs']['mcp']['max_items'] ?? null );

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
$staged_pending = $staged['payload'] ?? $staged;
assert_upgrade_plan_equals( 'pending action kind is bundle_upgrade', 'bundle_upgrade', $staged_pending['kind'] ?? null );
assert_upgrade_plan_equals( 'preview carries approval count', 1, $staged_pending['preview']['counts']['needs_approval'] ?? null );

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
