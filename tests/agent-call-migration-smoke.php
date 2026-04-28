<?php
/**
 * Pure-PHP smoke test for agent_ping → agent_call migration (#1478).
 *
 * Run with: php tests/agent-call-migration-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failed = 0;
$total  = 0;

function assert_agent_call( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}
	echo "  FAIL: {$name}\n";
	++$failed;
}

$root_dir = dirname( __DIR__ );
require_once $root_dir . '/inc/migrations/agent-ping.php';

echo "=== Agent Call Migration Smoke (#1478) ===\n";

echo "\n[migration:1] Legacy agent_ping params map to canonical agent_call config\n";
$canonical = datamachine_agent_call_config_from_legacy_ping( array(
	'webhook_url'      => "https://example.test/hook\nhttps://example.test/other",
	'prompt'           => 'Review this packet',
	'auth_header_name' => 'X-Agent-Token',
	'auth_token'       => 'secret-token',
	'reply_to'         => 'thread-123',
) );
assert_agent_call( 'task is agent_call', 'agent_call' === ( $canonical['task'] ?? null ) );
assert_agent_call( 'target.type is webhook', 'webhook' === ( $canonical['params']['target']['type'] ?? null ) );
assert_agent_call( 'target.id preserves webhook_url', "https://example.test/hook\nhttps://example.test/other" === ( $canonical['params']['target']['id'] ?? null ) );
assert_agent_call( 'auth header name is nested under target.auth', 'X-Agent-Token' === ( $canonical['params']['target']['auth']['header_name'] ?? null ) );
assert_agent_call( 'auth token is nested under target.auth', 'secret-token' === ( $canonical['params']['target']['auth']['token'] ?? null ) );
assert_agent_call( 'input.task preserves prompt', 'Review this packet' === ( $canonical['params']['input']['task'] ?? null ) );
assert_agent_call( 'input.messages is initialized', array() === ( $canonical['params']['input']['messages'] ?? null ) );
assert_agent_call( 'input.context is initialized', array() === ( $canonical['params']['input']['context'] ?? null ) );
assert_agent_call( 'delivery.mode is fire_and_forget', 'fire_and_forget' === ( $canonical['params']['delivery']['mode'] ?? null ) );
assert_agent_call( 'delivery.reply_to preserves reply_to', 'thread-123' === ( $canonical['params']['delivery']['reply_to'] ?? null ) );

echo "\n[migration:2] Runtime chain includes the post-system-task migration\n";
$runtime_src = (string) file_get_contents( $root_dir . '/inc/migrations/runtime.php' );
$pos_flow    = strpos( $runtime_src, 'datamachine_migrate_agent_ping_to_system_task();' );
$pos_pipe    = strpos( $runtime_src, 'datamachine_migrate_agent_ping_pipeline_to_system_task();' );
$pos_call    = strpos( $runtime_src, 'datamachine_migrate_agent_ping_task_to_agent_call();' );
assert_agent_call( 'agent_call migration is wired into runtime chain', false !== $pos_call );
assert_agent_call( 'agent_call migration runs after both legacy agent_ping step migrations', false !== $pos_flow && false !== $pos_pipe && false !== $pos_call && $pos_flow < $pos_call && $pos_pipe < $pos_call );

echo "\n[task:1] System task vocabulary is agent_call only\n";
$provider_src = (string) file_get_contents( $root_dir . '/inc/Engine/AI/System/SystemAgentServiceProvider.php' );
assert_agent_call( 'service provider registers agent_call task', false !== strpos( $provider_src, "\$tasks['agent_call']" ) );
assert_agent_call( 'service provider no longer registers agent_ping task', false === strpos( $provider_src, "\$tasks['agent_ping']" ) );

$task_src = (string) file_get_contents( $root_dir . '/inc/Engine/AI/System/Tasks/AgentCallTask.php' );
assert_agent_call( 'AgentCallTask returns agent_call type', false !== strpos( $task_src, "return 'agent_call';" ) );
assert_agent_call( 'AgentCallTask executes datamachine/agent-call ability', false !== strpos( $task_src, "wp_get_ability( 'datamachine/agent-call' )" ) );
assert_agent_call( 'AgentCallTask rejects missing ability by canonical name', false !== strpos( $task_src, 'Ability datamachine/agent-call not registered.' ) );

echo "\n[ability:1] Agent call ability owns the canonical runtime surface\n";
$ability_src = (string) file_get_contents( $root_dir . '/inc/Abilities/AgentCall/AgentCallAbility.php' );
assert_agent_call( 'ability registers datamachine/agent-call', false !== strpos( $ability_src, "wp_register_ability(\n\t\t\t\t'datamachine/agent-call'" ) );
assert_agent_call( 'ability supports webhook target type', false !== strpos( $ability_src, "'webhook'" ) );
assert_agent_call( 'ability supports fire_and_forget delivery mode', false !== strpos( $ability_src, "'fire_and_forget'" ) );
assert_agent_call( 'ability returns proposed status envelope', false !== strpos( $ability_src, "'remote_run_id'" ) && false !== strpos( $ability_src, "'resume_token'" ) );

echo "\n[tool:1] Existing chat tool bridges to agent_call without old ability shim\n";
$tool_src = (string) file_get_contents( $root_dir . '/inc/Api/Chat/Tools/SendPing.php' );
assert_agent_call( 'send_ping tool now points at datamachine/agent-call', false !== strpos( $tool_src, "'ability' => 'datamachine/agent-call'" ) );
assert_agent_call( 'send_ping tool transforms webhook_url into target.id', false !== strpos( $tool_src, "'id'   => \$parameters['webhook_url'] ?? ''" ) );
assert_agent_call( 'no datamachine/send-ping runtime shim remains', false === strpos( $tool_src, 'datamachine/send-ping' ) );

echo "\n";
$failure_count = (int) $GLOBALS['failed'];
if ( 0 === $failure_count ) {
	echo "=== agent-call-migration-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}

echo "=== agent-call-migration-smoke: {$failure_count} FAIL of {$total} ===\n";
exit( 1 );
