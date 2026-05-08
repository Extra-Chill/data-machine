<?php
/**
 * Pure-PHP smoke tests for runtime tool declaration validation.
 *
 * Run with: php tests/runtime-tool-declaration-smoke.php
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/bootstrap-unit.php';

use AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration;

function datamachine_runtime_tool_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$assertions = 0;

$runtime_tool_file = AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-declaration.php';
$legacy_tool_file  = dirname( __DIR__ ) . '/inc/Engine/AI/Tools/WP_Agent_Tool_Declaration.php';

datamachine_runtime_tool_assert(
	is_file( $runtime_tool_file ),
	'WP_Agent_Tool_Declaration should live in the standalone Agents API dependency.'
);
++$assertions;
datamachine_runtime_tool_assert(
	! file_exists( $legacy_tool_file ),
	'WP_Agent_Tool_Declaration should not exist in the Data Machine product tool tree.'
);
++$assertions;

$runtime_tool_source = (string) file_get_contents( $runtime_tool_file );
$forbidden_tokens    = array(
	'datamachine_tools',
	'FlowStepConfig',
	'AdjacentHandlerToolSource',
	'DataMachineToolRegistrySource',
	'ToolPolicyResolver',
	'PendingAction',
	'post_origin',
	'handler_slug',
);
foreach ( $forbidden_tokens as $token ) {
	datamachine_runtime_tool_assert(
		! str_contains( $runtime_tool_source, $token ),
		'WP_Agent_Tool_Declaration should not mention Data Machine product token: ' . $token
	);
	++$assertions;
}

$valid = array(
	'name'        => 'client/select_block',
	'description' => 'Select a block in the active editor.',
	'parameters'  => array(
		'type'       => 'object',
		'properties' => array(
			'client_id' => array( 'type' => 'string' ),
		),
	),
	'executor'    => 'client',
	'scope'       => 'run',
);

$normalized = WP_Agent_Tool_Declaration::normalize( $valid );
datamachine_runtime_tool_assert(
	'client' === $normalized['source'],
	'Valid client source should be derived from namespaced name.'
);
++$assertions;
datamachine_runtime_tool_assert(
	'run' === $normalized['scope'],
	'Valid runtime tool should preserve run scope.'
);
++$assertions;
datamachine_runtime_tool_assert(
	'client' === $normalized['executor'],
	'Valid runtime tool should preserve client executor.'
);
++$assertions;
datamachine_runtime_tool_assert(
	$valid['parameters'] === $normalized['parameters'],
	'Valid runtime tool should preserve parameter schema.'
);
++$assertions;

$with_source = $valid;
$with_source['source'] = 'client';
datamachine_runtime_tool_assert(
	array() === WP_Agent_Tool_Declaration::validate( $with_source ),
	'Explicit matching source should pass validation.'
);
++$assertions;

$forbidden_server_source = $valid;
$forbidden_server_source['name'] = 'server/select_block';
datamachine_runtime_tool_assert(
	array( 'source' ) === WP_Agent_Tool_Declaration::validate( $forbidden_server_source ),
	'Unknown runtime tool source should be forbidden by default.'
);
++$assertions;

$forbidden_transport_source = $valid;
$forbidden_transport_source['name'] = 'mcp/select_block';
datamachine_runtime_tool_assert(
	array( 'source' ) === WP_Agent_Tool_Declaration::validate( $forbidden_transport_source ),
	'Transport-specific runtime tool source should be forbidden by default.'
);
++$assertions;

$mismatched_source = $valid;
$mismatched_source['source'] = 'browser';
datamachine_runtime_tool_assert(
	array( 'source' ) === WP_Agent_Tool_Declaration::validate( $mismatched_source ),
	'Explicit source must match the name prefix.'
);
++$assertions;

$missing_executor = $valid;
unset( $missing_executor['executor'] );
datamachine_runtime_tool_assert(
	array( 'executor' ) === WP_Agent_Tool_Declaration::validate( $missing_executor ),
	'Runtime tool executor is required and must be client.'
);
++$assertions;

$server_executor = $valid;
$server_executor['executor'] = 'server';
datamachine_runtime_tool_assert(
	array( 'executor' ) === WP_Agent_Tool_Declaration::validate( $server_executor ),
	'Server executor is forbidden until Ability-native execution lands.'
);
++$assertions;

$job_scope = $valid;
$job_scope['scope'] = 'job';
datamachine_runtime_tool_assert(
	array( 'scope' ) === WP_Agent_Tool_Declaration::validate( $job_scope ),
	'Only run-scoped declarations are accepted.'
);
++$assertions;

$bad_parameters = $valid;
$bad_parameters['parameters'] = 'not a schema';
datamachine_runtime_tool_assert(
	array( 'parameters' ) === WP_Agent_Tool_Declaration::validate( $bad_parameters ),
	'Parameters must be an array schema.'
);
++$assertions;

$bad_name = $valid;
$bad_name['name'] = 'select_block';
datamachine_runtime_tool_assert(
	array( 'name', 'source' ) === WP_Agent_Tool_Declaration::validate( $bad_name ),
	'Runtime tool names must be source/tool namespaced.'
);
++$assertions;

try {
	WP_Agent_Tool_Declaration::normalize( $server_executor );
	throw new RuntimeException( 'normalize() should reject forbidden executors.' );
} catch ( InvalidArgumentException $e ) {
	datamachine_runtime_tool_assert(
		str_contains( $e->getMessage(), 'invalid_runtime_tool_declaration: executor' ),
		'normalize() should throw a machine-readable executor error.'
	);
	++$assertions;
}

datamachine_runtime_tool_assert(
	'client/select_block' === WP_Agent_Tool_Declaration::namespacedName( 'client', 'select_block' ),
	'namespacedName() should build source/tool names.'
);
++$assertions;

echo 'Runtime tool declaration smoke passed (' . $assertions . " assertions).\n";
