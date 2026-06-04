<?php
/**
 * Smoke assertions for async client runtime tool broker wiring.
 *
 * Run with: php tests/runtime-tool-async-broker-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$failures = array();
$passes   = 0;

function datamachine_runtime_async_assert( bool $condition, string $message, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "  ✓ {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "  ✗ {$message}\n";
}

$loop_source = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/conversation-loop.php' );
$chat_source = (string) file_get_contents( __DIR__ . '/../inc/Api/Chat/ChatOrchestrator.php' );

echo "runtime-tool-async-broker-smoke\n\n";

datamachine_runtime_async_assert(
	str_contains( $loop_source, 'function datamachine_defer_runtime_tool_call' ),
	'conversation loop exposes a durable runtime tool defer path',
	$failures,
	$passes
);
datamachine_runtime_async_assert(
	str_contains( $loop_source, 'WP_Agent_Runtime_Tool_Lifecycle::create_pending_request' ) && str_contains( $loop_source, 'WP_Agent_Runtime_Tool_Request_Store' ),
	'pending runtime tool requests persist through the Agents API lifecycle/store contracts',
	$failures,
	$passes
);
datamachine_runtime_async_assert(
	str_contains( $loop_source, "'source'      => 'runtime_tool'" ) && str_contains( $loop_source, "'pending_runtime_tool'" ),
	'deferred runtime tool requests are represented as Data Machine jobs',
	$failures,
	$passes
);
datamachine_runtime_async_assert(
	str_contains( $loop_source, "'metadata'     => array" ) && str_contains( $loop_source, "'datamachine' => array" ) && str_contains( $loop_source, "'persistence_status' => 'pending'" ),
	'Data Machine runtime tool request metadata is namespaced under metadata.datamachine',
	$failures,
	$passes
);
datamachine_runtime_async_assert(
	str_contains( $loop_source, 'as_schedule_single_action' ) && str_contains( $loop_source, 'datamachine_runtime_tool_timeout' ),
	'deferred runtime tool requests schedule Action Scheduler timeouts',
	$failures,
	$passes
);
datamachine_runtime_async_assert(
	str_contains( $loop_source, 'function datamachine_submit_runtime_tool_result' ),
	'client runtime tool results have a submission entry point',
	$failures,
	$passes
);
datamachine_runtime_async_assert(
	str_contains( $loop_source, 'WP_Agent_Runtime_Tool_Lifecycle::submit_result' ) && str_contains( $loop_source, 'datamachine_runtime_tool_submission_payload' ),
	'submitted runtime tool results complete through the Agents API lifecycle contract',
	$failures,
	$passes
);
datamachine_runtime_async_assert(
	str_contains( $loop_source, 'as_enqueue_async_action' ) && str_contains( $loop_source, 'datamachine_runtime_tool_resume' ),
	'submitted runtime tool results enqueue async conversation resume',
	$failures,
	$passes
);
datamachine_runtime_async_assert(
	str_contains( $loop_source, 'public function create( array $request ): void' ) && str_contains( $loop_source, 'public function get( string $request_id ): ?array' ) && str_contains( $loop_source, 'public function complete( string $request_id, array $result ): void' ) && str_contains( $loop_source, 'public function timeout( string $request_id ): void' ) && str_contains( $loop_source, 'public function recent_pending( array $query = array() ): array' ),
	'Data Machine provides a complete Agents API runtime tool request store adapter',
	$failures,
	$passes
);
datamachine_runtime_async_assert(
	str_contains( $loop_source, 'ChatOrchestrator::processContinue' ),
	'async resume reuses the existing chat continuation path',
	$failures,
	$passes
);
datamachine_runtime_async_assert(
	str_contains( $loop_source, "\$metadata['datamachine']" ) && str_contains( $loop_source, 'runtime_tool_pending_requests' ) && str_contains( $loop_source, "'runtime_tool_pending'" ),
	'conversation results surface pending runtime tool requests in Data Machine metadata without fabricating tool output',
	$failures,
	$passes
);
datamachine_runtime_async_assert(
	str_contains( $chat_source, 'runtime_tool_pending_requests' ) && str_contains( $chat_source, 'runtime_tool_requests' ),
	'chat sessions persist pending runtime tool request metadata',
	$failures,
	$passes
);

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " runtime tool async broker assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} runtime tool async broker assertions passed.\n";
