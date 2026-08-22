<?php
/**
 * Pure-PHP smoke test for portable flow-step settings in CSV import/export.
 *
 * Run with: php tests/import-export-portable-flow-settings-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Abilities\Flow {
	if ( ! class_exists( QueueAbility::class, false ) ) {
		class QueueAbility {
			const SLOT_PROMPT_QUEUE       = 'prompt_queue';
			const SLOT_CONFIG_PATCH_QUEUE = 'config_patch_queue';
			const FIELD_PROMPT            = 'prompt';
			const FIELD_PATCH             = 'patch';
		}
	}
}

namespace {

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', __DIR__ );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		if ( 'datamachine_step_types' === $hook ) {
			return array(
				'ai'      => array( 'uses_handler' => false, 'multi_handler' => false ),
				'fetch'   => array( 'uses_handler' => true, 'multi_handler' => false ),
				'publish' => array( 'uses_handler' => true, 'multi_handler' => true ),
				'upsert'  => array( 'uses_handler' => true, 'multi_handler' => true ),
			);
		}

		return $value;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $title ) ), '-' );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'datamachine_validate_interval' ) ) {
	function datamachine_validate_interval( string $interval, array $config = array() ): array {
		unset( $config );
		$valid = in_array( $interval, array( 'manual', 'hourly', 'daily' ), true );
		return array(
			'valid'    => $valid,
			'resolved' => $interval,
			'error'    => $valid ? '' : 'invalid interval',
		);
	}
}

require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfigFactory.php';
require_once __DIR__ . '/../inc/Engine/PortableFlowStepFields.php';
require_once __DIR__ . '/../inc/Engine/Bundle/AuthRefHandlerConfig.php';
require_once __DIR__ . '/../inc/Api/Flows/FlowScheduling.php';
require_once __DIR__ . '/../inc/Engine/Actions/ImportExport.php';

use DataMachine\Engine\Actions\ImportExport;
use DataMachine\Engine\Bundle\AuthRefHandlerConfig;
use DataMachine\Engine\PortableFlowStepFields;

$failures = array();
$passes   = 0;

function assert_csv_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

function call_import_export_private( ImportExport $import_export, string $method, array $arg ): array {
	$reflection = new ReflectionMethod( ImportExport::class, $method );
	$result = $reflection->invoke( $import_export, $arg );
	return is_array( $result ) ? $result : array();
}

echo "import-export-portable-flow-settings-smoke\n";

$import_export = new ImportExport();

$parse_csv = new ReflectionMethod( ImportExport::class, 'parse_csv_rows' );
$canonical_rows = $parse_csv->invoke(
	$import_export,
	"format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings\n1.0,pipeline_step,1,Example,0,fetch,{},,,"
);
assert_csv_equals( 1, count( $canonical_rows ), 'canonical 1.0 CSV header is accepted', $failures, $passes );

$old_header_rejected = false;
try {
	$parse_csv->invoke(
		$import_export,
		"pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings\n1,Example,0,fetch,{},,,{}"
	);
} catch ( InvalidArgumentException $e ) {
	$old_header_rejected = true;
}
assert_csv_equals( true, $old_header_rejected, 'pre-1.0 CSV header is rejected', $failures, $passes );

$ai_settings = call_import_export_private(
	$import_export,
	'export_flow_step_settings',
	array(
		'step_type'     => 'ai',
		'enabled_tools' => array( 'datamachine/get-github-pull-review-context', 'datamachine/upsert-github-pull-review-comment' ),
		'prompt_queue'  => array(
			array(
				'prompt'   => 'Review this PR.',
				'added_at' => '2026-04-27T00:00:00Z',
			),
		),
		'queue_mode'    => 'loop',
	)
);

assert_csv_equals(
	array( 'datamachine/get-github-pull-review-context', 'datamachine/upsert-github-pull-review-comment' ),
	$ai_settings['enabled_tools'] ?? null,
	'AI enabled_tools export as portable settings',
	$failures,
	$passes
);
assert_csv_equals( 'Review this PR.', $ai_settings['prompt_queue'][0]['prompt'] ?? null, 'AI prompt_queue exports as portable settings', $failures, $passes );
assert_csv_equals( 'loop', $ai_settings['queue_mode'] ?? null, 'AI queue_mode exports as portable settings', $failures, $passes );
assert_csv_equals( false, array_key_exists( 'handler_slug', $ai_settings ), 'AI settings stay handler-free', $failures, $passes );

$fetch_settings = call_import_export_private(
	$import_export,
	'export_flow_step_settings',
	array(
		'step_type'          => 'fetch',
		'handler_slugs'      => array( 'webhook_payload' ),
		'handler_configs'    => array( 'webhook_payload' => array( 'payload_path' => 'pull_request' ) ),
		'config_patch_queue' => array(
			array(
				'patch'    => array( 'after' => '2026-04-01' ),
				'added_at' => '2026-04-27T00:00:00Z',
			),
		),
		'queue_mode'         => 'drain',
	)
);

assert_csv_equals( array( 'webhook_payload' ), $fetch_settings['handler_slugs'] ?? null, 'fetch handler_slugs export', $failures, $passes );
assert_csv_equals( array( 'payload_path' => 'pull_request' ), $fetch_settings['handler_configs']['webhook_payload'] ?? null, 'fetch handler_configs export', $failures, $passes );
assert_csv_equals( array( 'after' => '2026-04-01' ), $fetch_settings['config_patch_queue'][0]['patch'] ?? null, 'fetch config_patch_queue exports canonical patch entry', $failures, $passes );
assert_csv_equals( 'drain', $fetch_settings['queue_mode'] ?? null, 'fetch queue_mode exports as portable settings', $failures, $passes );

$secure_settings = call_import_export_private(
	$import_export,
	'export_flow_step_settings',
	array(
		'step_type'       => 'fetch',
		'handler_slugs'   => array( 'custom_api' ),
		'handler_configs' => array(
			'custom_api' => array(
				'auth_ref'  => 'custom:destination',
				'api_key'   => 'csv-direct-key',
				'endpoint'  => 'https://api.example.test',
				'auth'      => array(
					'refresh_token' => 'csv-refresh-token',
					'password'      => 'csv-password',
				),
				'headers'   => array(
					'bearer_token' => 'csv-bearer-token',
					'authorization_header' => 'csv-authorization',
					'cookie'       => 'csv-cookie',
					'X-API-Key'    => 'csv-prefixed-api-key',
					'accept'       => 'application/json',
				),
				'private_key' => 'csv-private-key',
				'privateKey'  => 'csv-camel-private-key',
				'private-key' => 'csv-hyphen-private-key',
				'max_tokens'  => 2048,
				'token_budget' => 4096,
				'token_type'  => 'wordpiece',
				'credential'  => 'csv-credential',
				'credentials' => 'csv-credentials',
				'secrets'     => 'csv-secrets',
			),
		),
	)
);
$secure_json = json_encode( $secure_settings );
assert_csv_equals( 'custom:destination', $secure_settings['handler_configs']['custom_api']['auth_ref'] ?? null, 'CSV settings preserve auth_ref', $failures, $passes );
assert_csv_equals( 'https://api.example.test', $secure_settings['handler_configs']['custom_api']['endpoint'] ?? null, 'CSV settings preserve ordinary handler config', $failures, $passes );
assert_csv_equals( 'application/json', $secure_settings['handler_configs']['custom_api']['headers']['accept'] ?? null, 'CSV settings preserve nested ordinary config', $failures, $passes );
assert_csv_equals( 2048, $secure_settings['handler_configs']['custom_api']['max_tokens'] ?? null, 'CSV settings preserve non-secret token limits', $failures, $passes );
assert_csv_equals( 4096, $secure_settings['handler_configs']['custom_api']['token_budget'] ?? null, 'CSV settings preserve non-secret token budgets', $failures, $passes );
assert_csv_equals( 'wordpiece', $secure_settings['handler_configs']['custom_api']['token_type'] ?? null, 'CSV settings preserve non-secret token types', $failures, $passes );
assert_csv_equals( false, str_contains( $secure_json, 'csv-direct-key' ) || str_contains( $secure_json, 'csv-refresh-token' ) || str_contains( $secure_json, 'csv-password' ) || str_contains( $secure_json, 'csv-bearer-token' ) || str_contains( $secure_json, 'csv-authorization' ) || str_contains( $secure_json, 'csv-cookie' ) || str_contains( $secure_json, 'csv-prefixed-api-key' ) || str_contains( $secure_json, 'csv-private-key' ) || str_contains( $secure_json, 'csv-camel-private-key' ) || str_contains( $secure_json, 'csv-hyphen-private-key' ) || str_contains( $secure_json, 'csv-credential' ) || str_contains( $secure_json, 'csv-credentials' ) || str_contains( $secure_json, 'csv-secrets' ), 'CSV settings exclude direct and nested credentials', $failures, $passes );

$ordered_list = array();
for ( $index = 0; $index < 12; ++$index ) {
	$ordered_list[] = array( 'position' => $index );
}
assert_csv_equals( $ordered_list, AuthRefHandlerConfig::strip_secrets_for_export( $ordered_list ), 'credential projection preserves ordered lists', $failures, $passes );

$secure_csv = 'format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings' . "\n";
$secure_csv .= '1.0,pipeline_step,1,Secure,0,fetch,{},,,' . "\n";
$secure_csv .= '1.0,flow,1,Secure,,,,9,Destination,"{ ""scheduling_config"": { ""interval"": ""manual"" }, ""portable_slug"": ""destination"" }"' . "\n";
$secure_csv .= '1.0,flow_step,1,Secure,0,fetch,{},9,Destination,' . '"' . str_replace( '"', '""', (string) $secure_json ) . '"';
$secure_rows = $parse_csv->invoke( $import_export, $secure_csv );
assert_csv_equals( $secure_settings, $secure_rows[2]['settings'] ?? null, 'import preserves the credential-free exported settings', $failures, $passes );

$schedule_export = call_import_export_private(
	$import_export,
	'export_scheduling_config',
	array(
		'interval'        => 'manual',
		'webhook_token'   => 'csv-webhook-token',
		'webhook_secrets' => array( array( 'secret' => 'csv-hmac-secret', 'label' => 'primary' ) ),
	)
);
assert_csv_equals( false, str_contains( (string) json_encode( $schedule_export ), 'csv-webhook-token' ) || str_contains( (string) json_encode( $schedule_export ), 'csv-hmac-secret' ), 'flow scheduling export excludes webhook credentials', $failures, $passes );
assert_csv_equals( false, array_key_exists( 'webhook_secrets', $schedule_export ), 'flow scheduling export removes credential containers', $failures, $passes );

$merged_schedule = AuthRefHandlerConfig::preserve_local_secrets(
	array( 'interval' => 'hourly', 'enabled' => true ),
	array( 'interval' => 'manual', 'webhook_token' => 'destination-token', 'webhook_secrets' => array( array( 'secret' => 'destination-secret' ) ) )
);
assert_csv_equals( 'hourly', $merged_schedule['interval'] ?? null, 'reimport applies portable schedule desired state', $failures, $passes );
assert_csv_equals( 'destination-token', $merged_schedule['webhook_token'] ?? null, 'reimport preserves destination webhook token', $failures, $passes );
assert_csv_equals( 'destination-secret', $merged_schedule['webhook_secrets'][0]['secret'] ?? null, 'reimport preserves destination verifier secrets', $failures, $passes );

$merged_handler = AuthRefHandlerConfig::preserve_local_secrets(
	array( 'endpoint' => 'https://portable.example.test' ),
	array( 'endpoint' => 'https://local.example.test', 'api_key' => 'destination-api-key', 'privateKey' => 'destination-private-key', 'headers' => array( 'Authorization' => 'destination-authorization' ) )
);
assert_csv_equals( 'https://portable.example.test', $merged_handler['endpoint'] ?? null, 'reimport applies portable handler settings', $failures, $passes );
assert_csv_equals( 'destination-api-key', $merged_handler['api_key'] ?? null, 'reimport preserves destination handler API keys', $failures, $passes );
assert_csv_equals( 'destination-private-key', $merged_handler['privateKey'] ?? null, 'reimport preserves destination handler private keys', $failures, $passes );
assert_csv_equals( 'destination-authorization', $merged_handler['headers']['Authorization'] ?? null, 'reimport preserves nested destination credentials when portable parent is absent', $failures, $passes );

$normalized = call_import_export_private(
	$import_export,
	'normalize_portable_flow_step_settings',
	array(
		'enabled_tools'        => array( 'datamachine/read-github-file' ),
		'queue_mode'           => 'static',
		'prompt_queue'         => array( array( 'prompt' => 'Pinned prompt.' ) ),
		'completion_assertions' => array( 'required_tool_names' => array( 'publish_result' ) ),
		'tool_runtime_rules'    => array( array( 'id' => 'publish-result', 'max_calls' => 1 ) ),
		'enabled'               => false,
	)
);

assert_csv_equals( array( 'datamachine/read-github-file' ), $normalized['enabled_tools'] ?? null, 'import normalization keeps enabled_tools', $failures, $passes );
assert_csv_equals( array( array( 'prompt' => 'Pinned prompt.' ) ), $normalized['prompt_queue'] ?? null, 'import normalization keeps prompt_queue', $failures, $passes );
assert_csv_equals( 'static', $normalized['queue_mode'] ?? null, 'import normalization keeps queue_mode', $failures, $passes );
assert_csv_equals( array( 'required_tool_names' => array( 'publish_result' ) ), $normalized['completion_assertions'] ?? null, 'import normalization keeps completion_assertions', $failures, $passes );
assert_csv_equals( array( array( 'id' => 'publish-result', 'max_calls' => 1 ) ), $normalized['tool_runtime_rules'] ?? null, 'import normalization keeps tool_runtime_rules', $failures, $passes );
assert_csv_equals( false, $normalized['enabled'] ?? null, 'import normalization keeps disabled step state', $failures, $passes );
assert_csv_equals( false, array_key_exists( 'handler_slug', $normalized ), 'portable normalization does not duplicate handler fields', $failures, $passes );

$cleared = PortableFlowStepFields::clear_settings(
	array_merge(
		$normalized,
		array(
			'flow_step_id'  => 'step-1',
			'handler_slugs' => array( 'rss' ),
		)
	)
);
assert_csv_equals( 'step-1', $cleared['flow_step_id'] ?? null, 'authoritative replacement preserves structural flow-step identity', $failures, $passes );
assert_csv_equals( false, array_key_exists( 'enabled', $cleared ) || array_key_exists( 'handler_slugs', $cleared ) || array_key_exists( 'queue_mode', $cleared ), 'authoritative replacement removes absent destination behavior', $failures, $passes );

$normalized_patch = call_import_export_private(
	$import_export,
	'normalize_portable_flow_step_settings',
	array(
		'config_patch_queue' => array(
			array(
				'patch'    => array( 'before' => '2026-05-01' ),
				'added_at' => '2026-04-27T00:00:00Z',
			),
		),
	)
);
assert_csv_equals( array( 'before' => '2026-05-01' ), $normalized_patch['config_patch_queue'][0]['patch'] ?? null, 'import normalization keeps canonical config_patch_queue entries', $failures, $passes );

$threw = false;
try {
	call_import_export_private(
		$import_export,
		'normalize_portable_flow_step_settings',
		array( 'enabled_tools' => array( 'valid-tool', array( 'not' => 'a string' ) ) )
	);
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'enabled_tools must be a list of strings' );
}
assert_csv_equals( true, $threw, 'malformed enabled_tools fails clearly', $failures, $passes );

$threw = false;
try {
	call_import_export_private(
		$import_export,
		'normalize_portable_flow_step_settings',
		array( 'config_patch_queue' => array( array( 'after' => '2026-04-01' ) ) )
	);
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'config_patch_queue entries must include an object patch' );
}
assert_csv_equals( true, $threw, 'malformed config_patch_queue fails clearly', $failures, $passes );

$threw = false;
try {
	call_import_export_private( $import_export, 'normalize_portable_flow_step_settings', array( 'queue_mode' => 'bogus' ) );
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'queue_mode must be one of' );
}
assert_csv_equals( true, $threw, 'invalid queue_mode fails clearly', $failures, $passes );

$threw = false;
try {
	call_import_export_private( $import_export, 'normalize_portable_flow_step_settings', array( 'enabled' => 'false' ) );
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'enabled must be a boolean' );
}
assert_csv_equals( true, $threw, 'string enabled state fails before writes', $failures, $passes );

$threw = false;
try {
	$invalid_handler_csv = 'format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings' . "\n";
	$invalid_handler_csv .= '1.0,pipeline_step,1,Example,0,fetch,{},,,' . "\n";
	$invalid_handler_csv .= '1.0,flow,1,Example,,,,42,Named Flow,"{ ""scheduling_config"": { ""interval"": ""manual"" }, ""portable_slug"": ""named-flow"" }"' . "\n";
	$invalid_handler_csv .= '1.0,flow_step,1,Example,0,fetch,{},42,Named Flow,"{ ""handler_slugs"": [""rss""], ""handler_configs"": ""bad"" }"';
	$parse_csv->invoke( $import_export, $invalid_handler_csv );
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'handler_configs must be an object' );
}
assert_csv_equals( true, $threw, 'malformed handler_configs fails before writes', $failures, $passes );

$threw = false;
try {
	$invalid_nested_handler_csv = str_replace(
		'""handler_configs"": ""bad""',
		'""handler_configs"": { ""rss"": ""bad"" }',
		$invalid_handler_csv
	);
	$parse_csv->invoke( $import_export, $invalid_nested_handler_csv );
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'handler_configs entries must be objects' );
}
assert_csv_equals( true, $threw, 'malformed nested handler config fails before writes', $failures, $passes );

$threw = false;
try {
	$parse_csv->invoke(
		$import_export,
		"format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings\n1.0,flow,1,Example,,,,42,Named Flow,\"{ \"\"scheduling_config\"\": { \"\"interval\"\": \"\"bogus\"\" }, \"\"portable_slug\"\": \"\"named-flow\"\" }\""
	);
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'invalid scheduling_config' );
}
assert_csv_equals( true, $threw, 'invalid scheduling interval fails before writes', $failures, $passes );

$threw = false;
try {
	$parse_csv->invoke(
		$import_export,
		"format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings\n1.0,pipeline_step,1,Example,2,fetch,{},,,"
	);
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'must be contiguous from zero' );
}
assert_csv_equals( true, $threw, 'sparse pipeline step positions fail before writes', $failures, $passes );

foreach ( array( 'abc', '0.5' ) as $invalid_position ) {
	$threw = false;
	try {
		$parse_csv->invoke(
			$import_export,
			"format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings\n1.0,pipeline_step,1,Example,{$invalid_position},fetch,{},,,"
		);
	} catch ( InvalidArgumentException $e ) {
		$threw = str_contains( $e->getMessage(), 'invalid step_position' );
	}
	assert_csv_equals( true, $threw, "step position {$invalid_position} fails before writes", $failures, $passes );
}

$threw = false;
try {
	$mismatched_step_csv = 'format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings' . "\n";
	$mismatched_step_csv .= '1.0,pipeline_step,1,Example,0,fetch,{},,,' . "\n";
	$mismatched_step_csv .= '1.0,flow,1,Example,,,,42,Named Flow,"{ ""scheduling_config"": { ""interval"": ""manual"" }, ""portable_slug"": ""named-flow"" }"' . "\n";
	$mismatched_step_csv .= '1.0,flow_step,1,Example,0,publish,{},42,Named Flow,"{ ""enabled"": true }"';
	$parse_csv->invoke( $import_export, $mismatched_step_csv );
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'step_type does not match' );
}
assert_csv_equals( true, $threw, 'mismatched flow-step type fails before writes', $failures, $passes );

$threw = false;
try {
	$duplicate_slug_csv = 'format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings' . "\n";
	$duplicate_slug_csv .= '1.0,flow,1,Example,,,,41,First,"{ ""scheduling_config"": { ""interval"": ""manual"" }, ""portable_slug"": ""Foo Bar"" }"' . "\n";
	$duplicate_slug_csv .= '1.0,flow,1,Example,,,,42,Second,"{ ""scheduling_config"": { ""interval"": ""manual"" }, ""portable_slug"": ""foo-bar"" }"';
	$parse_csv->invoke( $import_export, $duplicate_slug_csv );
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'duplicate portable_slug' );
}
assert_csv_equals( true, $threw, 'duplicate portable flow identities fail before writes', $failures, $passes );

$threw = false;
try {
	$pipeline_mismatch_csv = 'format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings' . "\n";
	$pipeline_mismatch_csv .= '1.0,pipeline_step,1,Example,0,fetch,{},,,' . "\n";
	$pipeline_mismatch_csv .= '1.0,flow,2,Example,,,,42,Named Flow,"{ ""scheduling_config"": { ""interval"": ""manual"" }, ""portable_slug"": ""named-flow"" }"';
	$parse_csv->invoke( $import_export, $pipeline_mismatch_csv );
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'multiple source pipeline IDs' );
}
assert_csv_equals( true, $threw, 'inconsistent source pipeline identity fails before writes', $failures, $passes );

$threw = false;
try {
	$source_name_mismatch_csv = 'format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings' . "\n";
	$source_name_mismatch_csv .= '1.0,pipeline_step,1,First Pipeline,0,fetch,{},,,' . "\n";
	$source_name_mismatch_csv .= '1.0,flow,1,Second Pipeline,,,,42,Named Flow,"{ ""scheduling_config"": { ""interval"": ""manual"" }, ""portable_slug"": ""named-flow"" }"';
	$parse_csv->invoke( $import_export, $source_name_mismatch_csv );
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'multiple pipeline names' );
}
assert_csv_equals( true, $threw, 'one source pipeline ID cannot map to multiple names', $failures, $passes );

$threw = false;
try {
	$flow_name_mismatch_csv = str_replace( '42,Named Flow,"{ ""enabled"": true }"', '42,Wrong Flow,"{ ""enabled"": true }"', $mismatched_step_csv );
	$flow_name_mismatch_csv = str_replace( '0,publish', '0,fetch', $flow_name_mismatch_csv );
	$parse_csv->invoke( $import_export, $flow_name_mismatch_csv );
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'flow_name does not match' );
}
assert_csv_equals( true, $threw, 'mismatched flow name fails before writes', $failures, $passes );

$threw = false;
try {
	$duplicate_flow_step_csv = $secure_csv . "\n" . substr( $secure_csv, strrpos( $secure_csv, "\n" ) + 1 );
	$parse_csv->invoke( $import_export, $duplicate_flow_step_csv );
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'duplicate flow_step metadata' );
}
assert_csv_equals( true, $threw, 'duplicate flow-step identities fail before writes', $failures, $passes );

$threw = false;
try {
	$parse_csv->invoke(
		$import_export,
		"format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings\n1.0,flow,1,Example,,,,42,Named Flow,{}"
	);
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'must contain an object scheduling_config' );
}
assert_csv_equals( true, $threw, 'flow metadata without scheduling_config fails clearly', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " portable flow settings assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} portable flow settings assertions passed.\n";
}
