<?php
/**
 * Pure-PHP smoke test for AI request metadata and size guardrails.
 *
 * Run with: php tests/ai-request-metadata-guardrails-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

class WP_CLI_Command {}

class WP_CLI {
	public static array $logs = array();

	public static function log( string $message ): void {
		self::$logs[] = $message;
	}

	public static function warning( string $message ): void {
		self::$logs[] = 'WARNING: ' . $message;
	}

	public static function error( string $message ): void {
		throw new RuntimeException( $message );
	}
}

$GLOBALS['datamachine_test_filters'] = array();
$GLOBALS['datamachine_test_logs']    = array();

function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['datamachine_test_filters'][ $tag ][ $priority ][] = array( $callback, $accepted_args );
}

function apply_filters( string $tag, $value, ...$args ) {
	$callbacks = $GLOBALS['datamachine_test_filters'][ $tag ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( $priority_callbacks as $entry ) {
			$callback      = $entry[0];
			$accepted_args = $entry[1];
			$value         = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
		}
	}
	return $value;
}

function do_action( string $tag, ...$args ): void {
	if ( 'datamachine_log' === $tag ) {
		$GLOBALS['datamachine_test_logs'][] = $args;
	}
}

function did_action( string $hook = '' ): int {
	return 0;
}

function doing_action( string $hook = '' ): bool {
	return false;
}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	// no-op
}

function wp_json_encode( $data, int $flags = 0 ) {
	return json_encode( $data, $flags );
}

function size_format( $bytes ): string {
	return $bytes . ' B';
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use DataMachine\Cli\Commands\JobsCommand;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\Database\Chat\ConversationStoreInterface;
use DataMachine\Engine\AI\AIConversationLoop;
use DataMachine\Engine\AI\Directives\DirectiveInterface;
use DataMachine\Engine\AI\RequestBuilder;

class RequestMetadataSmokeDirective implements DirectiveInterface {
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		return array(
			array(
				'type'    => 'system_text',
				'content' => "## Memory File: RULES.md\n" . str_repeat( 'policy ', 25 ),
			),
		);
	}
}

class RequestMetadataSmokeStore implements ConversationStoreInterface {
	public array $sessions = array();
	public array $updated  = array();

	public function create_session( int $user_id, int $agent_id = 0, array $metadata = array(), string $context = 'chat' ): string {
		$this->sessions['smoke-session'] = compact( 'user_id', 'agent_id', 'metadata', 'context' );
		return 'smoke-session';
	}

	public function get_session( string $session_id ): ?array { return null; }
	public function update_session( string $session_id, array $messages, array $metadata = array(), string $provider = '', string $model = '' ): bool {
		$this->updated[ $session_id ] = compact( 'messages', 'metadata', 'provider', 'model' );
		return true;
	}
	public function delete_session( string $session_id ): bool { return true; }
	public function get_user_sessions( int $user_id, int $limit = 20, int $offset = 0, ?string $context = null, ?int $agent_id = null ): array { return array(); }
	public function get_user_session_count( int $user_id, ?string $context = null, ?int $agent_id = null ): int { return 0; }
	public function get_recent_pending_session( int $user_id, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array { return null; }
	public function update_title( string $session_id, string $title ): bool { return true; }
	public function count_unread( array $messages, ?string $last_read_at ): int { return 0; }
	public function mark_session_read( string $session_id, int $user_id ) { return gmdate( 'Y-m-d H:i:s' ); }
	public function cleanup_expired_sessions(): int { return 0; }
	public function cleanup_old_sessions( int $retention_days ): int { return 0; }
	public function cleanup_orphaned_sessions( int $hours = 1 ): int { return 0; }
	public function list_sessions_for_day( string $date ): array { return array(); }
	public function get_storage_metrics(): ?array { return array( 'rows' => 0, 'size_mb' => '0.0' ); }
}

$failures = array();

function assert_true( bool $condition, string $label ): void {
	global $failures;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}
	echo "FAIL: {$label}\n";
	$failures[] = $label;
}

function reset_smoke_state(): void {
	$GLOBALS['datamachine_test_filters'] = array();
	$GLOBALS['datamachine_test_logs']    = array();
	WP_CLI::$logs                        = array();
}

function build_smoke_request(): array {
	add_filter(
		'datamachine_directives',
		function ( array $directives ): array {
			$directives[] = array(
				'class'    => RequestMetadataSmokeDirective::class,
				'priority' => 20,
				'modes'    => array( 'all' ),
			);
			return $directives;
		}
	);

	add_filter(
		'chubes_ai_request',
		function ( array $request ) {
			$GLOBALS['datamachine_test_dispatched_request'] = $request;
			return array(
				'success' => true,
				'data'    => array(
					'content'    => 'ok',
					'tool_calls' => array(),
				),
			);
		},
		10,
		6
	);

	return RequestBuilder::build(
		array( array( 'role' => 'user', 'content' => 'hello' ) ),
		'openai',
		'gpt-smoke',
		array(
			'wiki_upsert' => array(
				'description' => str_repeat( 'tool ', 20 ),
				'parameters'  => array( 'type' => 'object', 'properties' => array( 'title' => array( 'type' => 'string' ) ) ),
			),
		),
		'pipeline',
		array( 'job_id' => 279, 'flow_step_id' => 12, 'persist_transcript' => true )
	);
}

// 1. Tiny thresholds warn before dispatch, and compact metadata is attached to the response.
reset_smoke_state();
foreach ( array( 'datamachine_ai_request_warning_bytes', 'datamachine_ai_messages_warning_bytes', 'datamachine_ai_tools_warning_bytes', 'datamachine_ai_directive_warning_bytes', 'datamachine_ai_tool_warning_bytes' ) as $filter ) {
	add_filter( $filter, fn() => 1 );
}
$response = build_smoke_request();
assert_true( ! empty( $GLOBALS['datamachine_test_logs'] ), 'oversized request emits a pre-dispatch warning' );
assert_true( isset( $response['request_metadata']['request_json_bytes'] ), 'response carries request metadata' );
assert_true( 'RULES.md' === ( $response['request_metadata']['memory_files'][0]['filename'] ?? '' ), 'memory file metadata is compactly captured' );

// 2. Large thresholds keep normal requests quiet.
reset_smoke_state();
foreach ( array( 'datamachine_ai_request_warning_bytes', 'datamachine_ai_messages_warning_bytes', 'datamachine_ai_tools_warning_bytes', 'datamachine_ai_directive_warning_bytes', 'datamachine_ai_tool_warning_bytes' ) as $filter ) {
	add_filter( $filter, fn() => 999999999 );
}
build_smoke_request();
$guardrail_warnings = array_filter(
	$GLOBALS['datamachine_test_logs'],
	fn( $entry ) => 'warning' === ( $entry[0] ?? '' ) && 'AI request size guardrail warning' === ( $entry[1] ?? '' )
);
assert_true( empty( $guardrail_warnings ), 'small request does not emit guardrail warning' );

// 3. Simulated persisted pipeline transcript stores request_metadata in session metadata.
$store    = new RequestMetadataSmokeStore();
$property = new ReflectionProperty( ConversationStoreFactory::class, 'instance' );
$property->setValue( null, $store );

$method = new ReflectionMethod( AIConversationLoop::class, 'maybePersistTranscript' );
$session_id = $method->invoke(
	null,
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	'openai',
	'gpt-smoke',
	array( 'persist_transcript' => true, 'job_id' => 279, 'flow_step_id' => 12, 'user_id' => 1, 'agent_id' => 2 ),
	array( 'turn_count' => 1, 'completed' => false, 'request_metadata' => $response['request_metadata'] )
);
assert_true( 'smoke-session' === $session_id, 'simulated pipeline transcript is persisted' );
assert_true( isset( $store->updated['smoke-session']['metadata']['request_metadata'] ), 'transcript metadata includes request_metadata' );

// 4. Legacy transcript metadata with no request_metadata still renders cleanly.
$command = new JobsCommand();
$render  = new ReflectionMethod( JobsCommand::class, 'renderTranscriptText' );
$render->invoke(
	$command,
	279,
	'legacy-session',
	array( 'provider' => 'openai', 'model' => 'gpt-smoke' ),
	array( array( 'role' => 'user', 'content' => 'legacy transcript' ) ),
	array( 'turn_count' => 1, 'completed' => true )
);
assert_true( in_array( 'Transcript for job 279', WP_CLI::$logs, true ), 'legacy transcript renders without request metadata' );

echo "\n";
if ( empty( $failures ) ) {
	echo "All AI request metadata guardrail smoke tests passed.\n";
	exit( 0 );
}

echo sprintf( "%d failure(s):\n", count( $failures ) );
foreach ( $failures as $failure ) {
	echo "  - {$failure}\n";
}
exit( 1 );
