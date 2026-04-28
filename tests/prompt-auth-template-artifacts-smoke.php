<?php
/**
 * Smoke tests for prompt artifacts, system-task prompt resolution, template metadata, and auth refs.
 *
 * Run with: php tests/prompt-auth-template-artifacts-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once __DIR__ . '/smoke-wp-stubs.php';

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
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

$GLOBALS['__prompt_smoke_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__prompt_smoke_filters'][ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['__prompt_smoke_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		return null;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'http://example.test/plugin/';
	}
}

$GLOBALS['__prompt_smoke_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['__prompt_smoke_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) {
		$GLOBALS['__prompt_smoke_options'][ $key ] = $value;
		return true;
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use DataMachine\Engine\AI\System\SystemTaskPromptRegistry;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use DataMachine\Engine\Bundle\AgentTemplateMetadata;
use DataMachine\Engine\Bundle\AuthRef;
use DataMachine\Engine\Bundle\AuthRefResolver;
use DataMachine\Engine\Bundle\AuthRefResolverInterface;
use DataMachine\Engine\Bundle\BundleSchema;
use DataMachine\Engine\Bundle\BundleValidationException;
use DataMachine\Engine\Bundle\PromptArtifact;

$failures = 0;
$passes   = 0;

function prompt_artifact_assert( string $label, bool $condition ): void {
	global $failures, $passes;
	if ( $condition ) {
		++$passes;
		echo "  PASS: {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL: {$label}\n";
}

function prompt_artifact_assert_equals( string $label, $expected, $actual ): void {
	prompt_artifact_assert( $label, $expected === $actual );
}

final class FixturePromptTask extends SystemTask {

	public function executeTask( int $jobId, array $params ): void {
	}
	public function getTaskType(): string {
		return 'fixture_generation';
	}
	public function getPromptDefinitions(): array {
		return array(
			'generate' => array(
				'label'       => 'Fixture prompt',
				'description' => 'Prompt used by fixture task.',
				'default'     => 'Write about {{topic}}.',
				'variables'   => array( 'topic' => 'Topic name' ),
				'version'     => '2026.04.28',
				'changelog'   => 'Initial artifact contract.',
			),
		);
	}
	public function exposeResolvePrompt( string $key ): string {
		return $this->resolvePrompt( $key );
	}
}

final class FixtureAuthRefResolver implements AuthRefResolverInterface {

	public function resolve( AuthRef $ref, array $context = array() ): array {
		if ( 'github:automattic' !== $ref->ref() ) {
			return AuthRefResolver::unresolved( $ref, 'Fixture resolver skipped ref.' );
		}

		return array(
			'resolved' => true,
			'config'   => array(
				'account_id'   => 42,
				'access_token' => 'should-not-export',
				'app_secret'   => 'should-not-export',
			),
		);
	}
}

echo "=== Prompt/Auth/Template Artifact Contracts Smoke ===\n";

echo "\n[1] Prompt/rubric artifacts expose stable IDs, versions, and hashes\n";
$artifact       = new PromptArtifact(
	'system-task:daily_memory_generation:daily_memory',
	PromptArtifact::TYPE_PROMPT,
	'2026.04.28',
	'system-tasks/daily-memory.md',
	"Preserve all memory.\n",
	'Adds conservation wording.',
	array( 'task_type' => 'daily_memory_generation' )
);
$artifact_array = $artifact->to_array();
prompt_artifact_assert_equals( 'artifact ID is preserved', 'system-task:daily_memory_generation:daily_memory', $artifact_array['artifact_id'] );
prompt_artifact_assert_equals( 'artifact version is preserved', '2026.04.28', $artifact_array['version'] );
prompt_artifact_assert_equals( 'content hash is SHA-256 of raw content', hash( 'sha256', "Preserve all memory.\n" ), $artifact_array['content_hash'] );
prompt_artifact_assert_equals( 'version metadata includes content hash', $artifact_array['content_hash'], $artifact->version_metadata()['content_hash'] );

$rubric = PromptArtifact::from_array(
	array(
		'artifact_id'   => 'rubric:wiki_quality',
		'artifact_type' => 'rubric',
		'version'       => '1.0.0',
		'source_path'   => 'rubrics/wiki-quality.md',
		'content'       => 'Score factual density.',
	)
);
prompt_artifact_assert_equals( 'rubric artifact type round-trips', 'rubric', $rubric->to_array()['artifact_type'] );

$threw = false;
try {
	PromptArtifact::from_array(
		array(
			'artifact_id'   => 'Bad ID',
			'artifact_type' => 'prompt',
			'version'       => '1.0.0',
			'source_path'   => 'prompts/bad.md',
			'content'       => 'bad',
		)
	);
} catch ( BundleValidationException $e ) {
	$threw = str_contains( $e->getMessage(), 'stable lowercase identifier' );
}
prompt_artifact_assert( 'invalid artifact IDs fail clearly', $threw );

echo "\n[2] System task prompt registry preserves current behaviour and adds filter seam\n";
$task     = new FixturePromptTask();
$resolved = $task->exposeResolvePrompt( 'generate' );
prompt_artifact_assert_equals( 'default prompt resolves through artifact registry', 'Write about {{topic}}.', $resolved );

SystemTask::setPromptOverride( 'fixture_generation', 'generate', 'Override {{topic}}.' );
prompt_artifact_assert_equals( 'local override still wins', 'Override {{topic}}.', $task->exposeResolvePrompt( 'generate' ) );
SystemTask::setPromptOverride( 'fixture_generation', 'generate', '' );

add_filter(
	'datamachine_system_task_effective_prompt',
	static function ( array $resolved, string $task_type, string $prompt_key ): array {
		if ( 'fixture_generation' === $task_type && 'generate' === $prompt_key ) {
			$resolved['content'] = 'Filtered artifact prompt.';
			$resolved['source']  = 'filter';
		}
		return $resolved;
	},
	10,
	5
);
prompt_artifact_assert_equals( 'filter can replace effective prompt deterministically', 'Filtered artifact prompt.', $task->exposeResolvePrompt( 'generate' ) );

$definition_artifact = SystemTaskPromptRegistry::artifact_from_definition( 'fixture_generation', 'generate', $task->getPromptDefinitions()['generate'] );
prompt_artifact_assert( 'definition produces a prompt artifact', $definition_artifact instanceof PromptArtifact );
prompt_artifact_assert_equals( 'default artifact ID is stable', 'system-task:fixture_generation:generate', $definition_artifact->artifact_id() );
prompt_artifact_assert_equals( 'definition version propagates to artifact', '2026.04.28', $definition_artifact->version() );

echo "\n[3] Agent template source/version metadata round-trips\n";
$manifest       = AgentBundleManifest::from_array(
	array(
		'schema_version'  => 1,
		'bundle_slug'     => 'portable-knowledge-agent',
		'bundle_version'  => '2.3.4',
		'source_ref'      => 'refs/tags/v2.3.4',
		'source_revision' => 'abc123',
		'exported_at'     => '2026-04-28T00:00:00Z',
		'exported_by'     => 'data-machine/test',
		'agent'           => array(
			'slug'         => 'knowledge-agent',
			'label'        => 'Knowledge Agent',
			'description'  => 'Maintains a domain wiki.',
			'agent_config' => array(),
		),
		'included'        => array(
			'memory'       => array(),
			'pipelines'    => array(),
			'flows'        => array(),
			'handler_auth' => 'refs',
		),
	)
);
$template       = AgentTemplateMetadata::from_manifest(
	$manifest,
	'domain-wiki-template',
	'5.6.7',
	array(
		'instructions' => hash( 'sha256', 'instructions' ),
		'tool_policy'  => hash( 'sha256', 'tool policy' ),
	)
);
$template_array = $template->to_array();
prompt_artifact_assert_equals( 'template slug preserved', 'domain-wiki-template', $template_array['template_slug'] );
prompt_artifact_assert_equals( 'template version preserved', '5.6.7', $template_array['template_version'] );
prompt_artifact_assert_equals( 'bundle source revision preserved', 'abc123', $template_array['source_revision'] );
prompt_artifact_assert_equals( 'installed hashes sorted by artifact ID', array( 'instructions', 'tool_policy' ), array_keys( $template_array['installed_hashes'] ) );
prompt_artifact_assert_equals( 'template metadata round-trips', $template_array, AgentTemplateMetadata::from_array( $template_array )->to_array() );

echo "\n[4] Auth refs validate, resolve, and strip secrets\n";
$auth_ref = AuthRef::from_string(
	'github:automattic',
	array(
		'label'        => 'Automattic GitHub',
		'access_token' => 'nope',
	)
);
prompt_artifact_assert_equals( 'auth ref string preserves provider/account', 'github:automattic', $auth_ref->ref() );
prompt_artifact_assert_equals( 'bundle schema reserves auth refs directory', 'auth-refs', BundleSchema::AUTH_REFS_DIR );
prompt_artifact_assert( 'auth ref metadata strips token-like keys', ! array_key_exists( 'access_token', $auth_ref->metadata() ) );

$threw = false;
try {
	AuthRef::from_string( 'bad ref' );
} catch ( BundleValidationException $e ) {
	$threw = str_contains( $e->getMessage(), 'provider:account' );
}
prompt_artifact_assert( 'malformed auth refs fail clearly', $threw );

add_filter(
	'datamachine_auth_ref_resolvers',
	static function ( array $resolvers ): array {
		$resolvers[] = new FixtureAuthRefResolver();
		return $resolvers;
	},
	10,
	2
);
$resolution = AuthRefResolver::resolve( $auth_ref );
prompt_artifact_assert( 'registered resolver can resolve auth ref', true === $resolution['resolved'] );
prompt_artifact_assert_equals( 'resolver preserves ref identity', 'github:automattic', $resolution['ref'] );
prompt_artifact_assert_equals( 'non-secret config survives resolution', 42, $resolution['config']['account_id'] ?? null );
prompt_artifact_assert( 'secret config keys are stripped from resolution', ! array_key_exists( 'access_token', $resolution['config'] ?? array() ) && ! array_key_exists( 'app_secret', $resolution['config'] ?? array() ) );

$unresolved = AuthRefResolver::resolve( 'slack:a8c' );
prompt_artifact_assert( 'unresolved refs return warning envelope, not failure', false === $unresolved['resolved'] && ! empty( $unresolved['warning'] ) );

echo "\n=== Results ===\n";
echo "Passed: {$passes}\n";
echo "Failed: {$failures}\n";

if ( $failures > 0 ) {
	exit( 1 );
}
