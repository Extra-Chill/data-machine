<?php
/**
 * Smoke assertions for the runtime-tool Agents API contract store adapter.
 *
 * Run with: php tests/runtime-tool-contract-store-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

namespace DataMachine\Core {
	class JobStatus { public const WAITING = 'waiting'; }
	class PluginSettings {
		public const DEFAULT_MAX_TURNS = 8;
	}

	class EngineData {
		public static function mutate( int $job_id, callable $callback, string $event_type ): array {
			unset( $event_type );
			$current = \DataMachine\Core\Database\Jobs\Jobs::$engine_data[ $job_id ] ?? array();
			\DataMachine\Core\Database\Jobs\Jobs::$engine_data[ $job_id ] = $callback( $current );

			return array( 'success' => true );
		}
	}
}

namespace DataMachine\Api\Chat {
	class ChatOrchestrator {
		public static array $calls = array();

		public static function processContinue( string $session_id, int $user_id, ?int $calling_user_id = null ): array {
			self::$calls[] = array(
				'session_id'      => $session_id,
				'user_id'         => $user_id,
				'calling_user_id' => $calling_user_id,
				'tools'           => null !== $calling_user_id && $calling_user_id > 0 ? array( 'owner_tool' ) : array(),
			);

			return array();
		}
	}
}

namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public static array $jobs        = array();
		public static array $engine_data = array();
		public static int $next_id       = 1;
		public static bool $fail_next_complete = false;

		public function create_job( array $job ): int {
			$job_id              = self::$next_id++;
			self::$jobs[ $job_id ] = array_merge( $job, array( 'status' => 'created' ) );

			return $job_id;
		}

		public function start_job( int $job_id, string $status ): bool {
			self::$jobs[ $job_id ]['status'] = $status;
			return true;
		}

		public function store_engine_data( int $job_id, array $data ): void {
			self::$engine_data[ $job_id ] = $data;
		}

		public function retrieve_engine_data( int $job_id ): array {
			return self::$engine_data[ $job_id ] ?? array();
		}

		public function complete_job( int $job_id, string $status ): bool {
			if ( self::$fail_next_complete ) {
				self::$fail_next_complete = false;
				return false;
			}
			self::$jobs[ $job_id ]['status'] = $status;
			unset( self::$engine_data[ $job_id ]['packet_tool_executions'], self::$engine_data[ $job_id ]['_datamachine_item_claim'] );
			return true;
		}

		public function get_job( int $job_id ): ?array {
			return self::$jobs[ $job_id ] ?? null;
		}
	}
}

namespace DataMachine\Core\Database\Chat {
	class RuntimeToolContractStoreSmokeSessionStore {
		public array $sessions = array();

		public function get_session( string $session_id ): ?array {
			return $this->sessions[ $session_id ] ?? null;
		}

		public function update_session( string $session_id, array $messages, array $metadata, string $provider, string $model ): bool {
			$this->sessions[ $session_id ] = array_merge(
				$this->sessions[ $session_id ] ?? array(),
				array(
					'messages' => $messages,
					'metadata' => $metadata,
					'provider' => $provider,
					'model'    => $model,
				)
			);

			return true;
		}

		public function acquire_session_lock( string $session_id, int $ttl_seconds = 300 ): ?string {
			unset( $session_id, $ttl_seconds );
			return 'runtime-tool-contract-lock';
		}

		public function release_session_lock( string $session_id, string $lock_token ): bool {
			unset( $session_id, $lock_token );
			return true;
		}
	}

	class ConversationStoreFactory {
		public static ?RuntimeToolContractStoreSmokeSessionStore $store = null;

		public static function get(): RuntimeToolContractStoreSmokeSessionStore {
			if ( null === self::$store ) {
				self::$store = new RuntimeToolContractStoreSmokeSessionStore();
			}

			return self::$store;
		}
	}
}

namespace DataMachine\Engine\AI {
	class ConversationManager {
		public static function validateToolCall( string $tool_name, array $tool_parameters, array $messages, ?array $tool_definition ): array {
			unset( $tool_name, $tool_parameters, $messages, $tool_definition );
			return array( 'is_duplicate' => false );
		}

		public static function duplicateToolCallError( string $tool_name, string $mode ): string {
			unset( $tool_name, $mode );
			return 'Duplicate tool call.';
		}

		public static function formatToolResultMessage( string $tool_name, array $tool_result, array $tool_parameters, bool $is_handler_tool = false, int $turn_count = 0 ): array {
			unset( $tool_parameters, $is_handler_tool );

			return array(
				'role'       => 'tool',
				'tool_name'  => $tool_name,
				'turn_count' => $turn_count,
				'payload'    => $tool_result,
			);
		}
	}
}

namespace {
	use AgentsAPI\AI\WP_Agent_Runtime_Tool_Request;
	use AgentsAPI\AI\WP_Agent_Runtime_Tool_Result;
	use AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle;
	use AgentsAPI\AI\WP_Agent_Conversation_Loop;
	use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor;
	use DataMachine\Core\Database\Chat\ConversationStoreFactory;
	use DataMachine\Core\Database\Jobs\Jobs;
	use DataMachine\Engine\AI\DataMachineToolRuntimeRules;
	use DataMachine\Engine\AI\LoopEventSinkInterface;
	use function DataMachine\Engine\AI\datamachine_build_pre_tool_mediator;
	use function DataMachine\Engine\AI\datamachine_prepare_runtime_tool_pending_result;
	use function DataMachine\Engine\AI\datamachine_prepare_runtime_tool_request;
	use function DataMachine\Engine\AI\datamachine_runtime_tool_request_store;
	use function DataMachine\Engine\AI\datamachine_resume_runtime_tool_request;
	use function DataMachine\Engine\AI\datamachine_session_has_pending_runtime_tools;
	use function DataMachine\Engine\AI\datamachine_submit_runtime_tool_result;
	use function DataMachine\Engine\AI\datamachine_timeout_runtime_tool_request;

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/../' );
	}

	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code, string $message ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( string $type, bool $gmt = false ): string {
			unset( $type, $gmt );

			return gmdate( 'Y-m-d H:i:s' );
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, int $flags = 0, int $depth = 512 ) {
			return json_encode( $data, $flags, $depth );
		}
	}

	$GLOBALS['datamachine_runtime_tool_scheduled'] = array();
	$GLOBALS['datamachine_runtime_tool_enqueued']  = array();

	function as_schedule_single_action( int $timestamp, string $hook, array $args, string $group ): int {
		$GLOBALS['datamachine_runtime_tool_scheduled'][] = compact( 'timestamp', 'hook', 'args', 'group' );
		return count( $GLOBALS['datamachine_runtime_tool_scheduled'] );
	}

	function as_enqueue_async_action( string $hook, array $args, string $group ): int {
		$GLOBALS['datamachine_runtime_tool_enqueued'][] = compact( 'hook', 'args', 'group' );
		return count( $GLOBALS['datamachine_runtime_tool_enqueued'] );
	}

	function as_has_scheduled_action( string $hook, array $args, string $group ): int {
		foreach ( $GLOBALS['datamachine_runtime_tool_enqueued'] as $index => $action ) {
			if ( $hook === $action['hook'] && $args === $action['args'] && $group === $action['group'] ) {
				return $index + 1;
			}
		}
		return 0;
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void {
			unset( $hook, $args );
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$args ) {
			unset( $hook, $args );
			return $value;
		}
	}

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
			unset( $hook, $callback, $priority, $accepted_args );
		}
	}

	if ( ! function_exists( 'did_action' ) ) {
		function did_action( string $hook = '' ): int {
			unset( $hook );
			return 0;
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $hook, callable|string $callback ): void {
			unset( $hook, $callback );
		}
	}

	require __DIR__ . '/../vendor/autoload.php';
	require_once __DIR__ . '/../inc/Engine/AI/RuntimeToolRunStateStore.php';
	require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolExecutor.php';
	require_once __DIR__ . '/../inc/Engine/AI/conversation-loop.php';

	class RuntimeToolContractStoreSmokeExecutor implements WP_Agent_Tool_Executor {
		public int $calls = 0;

		public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
			unset( $tool_call, $tool_definition, $context );
			++$this->calls;
			return array( 'success' => false, 'error' => 'Deferred tools must not reach the PHP executor.' );
		}
	}

	function prepare_and_persist_runtime_tool_request( array $request, array $payload ): array|WP_Error {
		$pending = datamachine_prepare_runtime_tool_pending_result( $request, $payload );
		if ( $pending instanceof WP_Error ) {
			return $pending;
		}

		$pending['runtime_tool_request'] = WP_Agent_Runtime_Tool_Lifecycle::create_pending_request(
			datamachine_runtime_tool_request_store(),
			$pending['runtime_tool_request'],
			array( 'payload' => $payload )
		);
		return $pending;
	}

	$failures = array();
	$passes   = 0;

	$assert = static function ( bool $condition, string $message ) use ( &$failures, &$passes ): void {
		if ( $condition ) {
			++$passes;
			echo "  ✓ {$message}\n";
			return;
		}

		$failures[] = $message;
		echo "  ✗ {$message}\n";
	};

	echo "runtime-tool-contract-store-smoke\n\n";

	$chat_db                              = ConversationStoreFactory::get();
	$chat_db->sessions['session-1']       = array( 'messages' => array(), 'metadata' => array(), 'provider' => 'openai', 'model' => 'gpt' );
	$chat_db->sessions['session-timeout'] = array( 'messages' => array(), 'metadata' => array(), 'provider' => 'openai', 'model' => 'gpt' );

	$pending = prepare_and_persist_runtime_tool_request(
		array(
			'tool_name'  => 'client/select_block',
			'call_id'    => 'call-1',
			'parameters' => array( 'label' => 'Hero' ),
			'turn_count' => 3,
			'session_id' => 'session-1',
			'mode'       => 'chat',
			'modes'      => array( 'chat' ),
		),
		array(
			'user_id'         => 7,
			'calling_user_id' => 52,
			'agent_id'        => 11,
			'client_context'  => array( 'runtime_tool_timeout' => 30 ),
		)
	);

	$request    = $pending['runtime_tool_request'] ?? array();
	$request_id = (string) ( $request['request_id'] ?? '' );
	$assert( WP_Agent_Runtime_Tool_Request::STATUS_PENDING === ( $request['status'] ?? '' ), 'deferred request uses canonical pending status' );
	$assert( 'call-1' === ( $request['tool_call_id'] ?? '' ), 'deferred request carries canonical tool_call_id' );
	$assert( 'pending' === ( $request['metadata']['datamachine']['persistence_status'] ?? '' ), 'deferred request keeps Data Machine persistence status namespaced' );
	$assert( 52 === ( $request['metadata']['datamachine']['calling_user_id'] ?? null ), 'deferred request persists the delegated acting caller separately from runtime ownership' );
	$assert( isset( Jobs::$engine_data[1]['runtime_tool_request'] ), 'store adapter persists request in job engine data' );
	$assert( isset( $chat_db->sessions['session-1']['metadata']['runtime_tool_requests'][ $request_id ] ), 'store adapter mirrors request into session metadata' );
	$assert( 'datamachine_runtime_tool_timeout' === ( $GLOBALS['datamachine_runtime_tool_scheduled'][0]['hook'] ?? '' ), 'deferred request schedules timeout action' );
	$assert( null !== datamachine_runtime_tool_request_store()->get( $request_id ), 'store adapter reads the pending request back' );

	$submission = datamachine_submit_runtime_tool_result( $request_id, array( 'selected_id' => 'block-1' ) );
	$stored     = Jobs::$engine_data[1]['runtime_tool_request'];
	$assert( is_array( $submission ) && true === ( $submission['success'] ?? false ), 'result submission succeeds' . ( $submission instanceof WP_Error ? ': ' . $submission->get_error_message() : '' ) );
	$assert( WP_Agent_Runtime_Tool_Result::STATUS_SUBMITTED === ( $stored['metadata']['datamachine']['result']['status'] ?? '' ), 'submitted result is stored in canonical result shape' );
	$assert( 'fulfilled' === ( $stored['metadata']['datamachine']['persistence_status'] ?? '' ), 'submitted result completes namespaced Data Machine status' );
	$assert( 'completed' === ( Jobs::$jobs[1]['status'] ?? '' ), 'successful result completes the Data Machine job' );
	$assert( 1 === count( $chat_db->sessions['session-1']['messages'] ), 'submitted result appends a transcript tool message' );
	$assert( false === ( $chat_db->sessions['session-1']['metadata']['has_pending_tools'] ?? true ), 'submitted result clears pending session state' );
	$assert( 'datamachine_runtime_tool_resume' === ( $GLOBALS['datamachine_runtime_tool_enqueued'][0]['hook'] ?? '' ), 'submitted result enqueues resume action' );
	$before_recovery_messages = count( $chat_db->sessions['session-1']['messages'] );
	$before_recovery_actions  = count( $GLOBALS['datamachine_runtime_tool_enqueued'] );
	$duplicate_submission = datamachine_submit_runtime_tool_result( $request_id, array( 'selected_id' => 'block-1' ) );
	$assert( is_array( $duplicate_submission ) && true === ( $duplicate_submission['success'] ?? false ), 'duplicate result submission enters idempotent continuation recovery' . ( $duplicate_submission instanceof WP_Error ? ': ' . $duplicate_submission->get_error_message() : '' ) );
	$assert( $before_recovery_messages === count( $chat_db->sessions['session-1']['messages'] ), 'duplicate result submission does not duplicate transcript projection' );
	$assert( $before_recovery_actions === count( $GLOBALS['datamachine_runtime_tool_enqueued'] ), 'duplicate result submission adopts existing resume action' );
	datamachine_timeout_runtime_tool_request( $request_id );
	$assert( $before_recovery_messages === count( $chat_db->sessions['session-1']['messages'] ), 'fulfilled timeout recovery does not duplicate transcript projection' );
	$assert( $before_recovery_actions === count( $GLOBALS['datamachine_runtime_tool_enqueued'] ), 'fulfilled timeout recovery adopts existing resume action idempotently' );
	datamachine_resume_runtime_tool_request( $request_id );
	$delegated_resume = \DataMachine\Api\Chat\ChatOrchestrator::$calls[0] ?? array();
	$assert( 7 === ( $delegated_resume['user_id'] ?? null ), 'async delegated resume retains runtime/session ownership' );
	$assert( 52 === ( $delegated_resume['calling_user_id'] ?? null ), 'async delegated resume restores the original acting caller' );
	$assert( array( 'owner_tool' ) === ( $delegated_resume['tools'] ?? null ), 'delegated caller-scoped tools remain visible after async resume' );
	$assert( 52 === ( Jobs::$engine_data[1]['runtime_tool_run_state']['resume_payload']['calling_user_id'] ?? null ), 'runtime run-state records the delegated caller in its resume payload' );

	$timeout_pending = prepare_and_persist_runtime_tool_request(
		array(
			'tool_name'  => 'client/confirm',
			'call_id'    => 'call-timeout',
			'parameters' => array(),
			'turn_count' => 1,
			'session_id' => 'session-timeout',
		),
		array( 'user_id' => 7, 'agent_id' => 11 )
	);
	datamachine_timeout_runtime_tool_request( (string) ( $timeout_pending['runtime_tool_request']['request_id'] ?? '' ) );
	$timeout_stored = Jobs::$engine_data[2]['runtime_tool_request'];
	$assert( 'failed' === ( $timeout_stored['metadata']['datamachine']['persistence_status'] ?? '' ), 'timeout marks namespaced Data Machine status failed' );
	$assert( 'runtime_tool_timeout' === ( $timeout_stored['metadata']['datamachine']['result']['metadata']['datamachine']['code'] ?? '' ), 'timeout stores canonical error result metadata' );
	$assert( 'failed' === ( Jobs::$jobs[2]['status'] ?? '' ), 'timeout fails the Data Machine job' );

	$chat_db->sessions['session-system'] = array( 'messages' => array(), 'metadata' => array(), 'provider' => 'openai', 'model' => 'gpt' );
	$system_pending = prepare_and_persist_runtime_tool_request(
		array(
			'tool_name'  => 'client/select_block',
			'call_id'    => 'call-system',
			'parameters' => array(),
			'turn_count' => 2,
			'session_id' => 'session-system',
			'mode'       => 'chat',
			'modes'      => array( 'chat' ),
		),
		array(
			'user_id'         => 7,
			'calling_user_id' => 0,
			'agent_id'        => 11,
		)
	);
	$system_request    = $system_pending['runtime_tool_request'] ?? array();
	$system_request_id = (string) ( $system_request['request_id'] ?? '' );
	$assert( 0 === ( $system_request['metadata']['datamachine']['calling_user_id'] ?? null ), 'deferred request preserves an explicit no-human caller' );
	datamachine_submit_runtime_tool_result( $system_request_id, array( 'selected_id' => 'block-2' ) );
	datamachine_resume_runtime_tool_request( $system_request_id );
	$system_resume = \DataMachine\Api\Chat\ChatOrchestrator::$calls[1] ?? array();
	$assert( 7 === ( $system_resume['user_id'] ?? null ), 'async no-human resume retains runtime/session ownership' );
	$assert( 0 === ( $system_resume['calling_user_id'] ?? null ), 'async no-human resume restores explicit zero without owner fallback' );
	$assert( array() === ( $system_resume['tools'] ?? null ), 'owner-scoped tools do not appear after async no-human resume' );
	$assert( 0 === ( Jobs::$engine_data[3]['runtime_tool_run_state']['resume_payload']['calling_user_id'] ?? null ), 'runtime run-state records explicit zero in its resume payload' );

	$chat_db->sessions['session-packet'] = array( 'messages' => array(), 'metadata' => array(), 'provider' => 'openai', 'model' => 'gpt' );
	$packet_token = 'packet-reservation-token';
	$packet_id    = hash( 'sha256', 'packet-runtime-identity' );
	$packet_request = datamachine_prepare_runtime_tool_request(
		array(
			'tool_name'  => 'client/packet_handler',
			'call_id'    => 'call-packet',
			'parameters' => array( 'disposition_id' => $packet_id ),
			'turn_count' => 4,
			'session_id' => 'session-packet',
			'mode'       => 'pipeline',
			'modes'      => array( 'pipeline' ),
		),
		array(
			'user_id' => 7,
			'agent_id' => 11,
			'job_id' => 404,
			'packet_execution_identity' => array(
				'job_id'            => 0,
				'tool_name'         => 'client/packet_handler',
				'disposition_id'    => $packet_id,
				'reservation_token' => $packet_token,
			),
		)
	);
	$packet_job_id = (int) substr( (string) $packet_request['request_id'], strlen( 'runtime_tool_' ) );
	$packet_request['metadata']['datamachine']['packet_execution']['job_id'] = $packet_job_id;
	Jobs::$engine_data[ $packet_job_id ] = array(
		'preserved_key' => 'preserved-value',
		'_datamachine_item_claim' => array( 'ownership_token' => 'claim-token' ),
		'packet_tool_executions' => array(
			'client/packet_handler' => array(
				$packet_id => array(
					'state'      => 'pending',
					'token'      => $packet_token,
					'request_id' => $packet_request['request_id'],
					'started_at' => gmdate( 'c' ),
				),
			),
		),
	);
	\AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::create_pending_request( datamachine_runtime_tool_request_store(), $packet_request );
	$assert( 'preserved-value' === ( Jobs::$engine_data[ $packet_job_id ]['preserved_key'] ?? null ), 'packet-bound request creation preserves the existing engine snapshot' );
	$assert( 'pending' === ( Jobs::$engine_data[ $packet_job_id ]['packet_tool_executions']['client/packet_handler'][ $packet_id ]['state'] ?? null ), 'packet-bound request creation preserves claim reservation state' );
	$assert( $packet_token === ( Jobs::$engine_data[ $packet_job_id ]['packet_tool_executions']['client/packet_handler'][ $packet_id ]['token'] ?? null ) && ! str_contains( (string) json_encode( $packet_request ), $packet_token ), 'packet reservation token remains only in server-side engine state' );
	$assert( isset( Jobs::$engine_data[ $packet_job_id ]['_datamachine_item_claim'] ), 'packet-bound request creation preserves packet claim metadata' );

	Jobs::$fail_next_complete = true;
	$first_packet_submission = datamachine_submit_runtime_tool_result( (string) $packet_request['request_id'], array( 'published' => true ) );
	$packet_phases = Jobs::$engine_data[ $packet_job_id ]['runtime_tool_request']['metadata']['datamachine']['completion_phases'] ?? array();
	$assert( true === ( $first_packet_submission['success'] ?? false ) && false === ( $first_packet_submission['scheduled'] ?? true ), 'request terminal transition survives a crash before job terminalization' );
	$assert( ! empty( $packet_phases['request_terminal_at'] ) && ! empty( $packet_phases['reservation_finalized_at'] ) && empty( $packet_phases['job_terminalized_at'] ), 'crash snapshot records exactly the completed runtime phases' );
	$assert( 'succeeded' === ( Jobs::$engine_data[ $packet_job_id ]['packet_tool_executions']['client/packet_handler'][ $packet_id ]['state'] ?? null ), 'reservation finalizes before terminal cleanup' );

	$before_packet_messages = count( $chat_db->sessions['session-packet']['messages'] );
	$duplicate_packet_submission = datamachine_submit_runtime_tool_result( (string) $packet_request['request_id'], array( 'published' => true ) );
	$packet_phases = Jobs::$engine_data[ $packet_job_id ]['runtime_tool_request']['metadata']['datamachine']['completion_phases'] ?? array();
	$assert( true === ( $duplicate_packet_submission['success'] ?? false ) && true === ( $duplicate_packet_submission['scheduled'] ?? false ), 'duplicate callback completes every missing phase after terminalization failure' );
	$assert( 'completed' === ( Jobs::$jobs[ $packet_job_id ]['status'] ?? '' ), 'duplicate callback retries and completes job terminalization' );
	$assert( ! isset( Jobs::$engine_data[ $packet_job_id ]['packet_tool_executions'] ), 'terminal cleanup runs only after reservation finalization is durable' );
	$assert( ! empty( $packet_phases['job_terminalized_at'] ) && ! empty( $packet_phases['session_projected_at'] ) && ! empty( $packet_phases['resume_scheduled_at'] ), 'duplicate callback persists terminal, projection, and resume phases' );
	$assert( $before_packet_messages + 1 === count( $chat_db->sessions['session-packet']['messages'] ), 'recovery projects the packet result exactly once' );
	$before_packet_resumes = count( \DataMachine\Api\Chat\ChatOrchestrator::$calls );
	datamachine_resume_runtime_tool_request( (string) $packet_request['request_id'] );
	datamachine_resume_runtime_tool_request( (string) $packet_request['request_id'] );
	$assert( $before_packet_resumes + 1 === count( \DataMachine\Api\Chat\ChatOrchestrator::$calls ), 'duplicate resume action delivery continues the conversation exactly once' );
	$GLOBALS['datamachine_runtime_tool_enqueued'] = array_values( array_filter( $GLOBALS['datamachine_runtime_tool_enqueued'], static fn( array $action ): bool => 'datamachine_runtime_tool_resume' !== $action['hook'] || array( $packet_request['request_id'] ) !== $action['args'] ) );
	datamachine_submit_runtime_tool_result( (string) $packet_request['request_id'], array( 'published' => true ) );
	$assert( 0 === count( array_filter( $GLOBALS['datamachine_runtime_tool_enqueued'], static fn( array $action ): bool => 'datamachine_runtime_tool_resume' === $action['hook'] && array( $packet_request['request_id'] ) === $action['args'] ) ), 'completed duplicate callback does not enqueue another resume after the first action ran' );
	$assert( array() === datamachine_runtime_tool_request_store()->recent_pending(), 'store adapter implements the Agents API recent-pending contract' );

	$chat_db->sessions['session-client-output'] = array( 'messages' => array(), 'metadata' => array(), 'provider' => 'openai', 'model' => 'gpt' );
	$client_pending = prepare_and_persist_runtime_tool_request(
		array(
			'tool_name'  => 'client/packet_handler',
			'call_id'    => 'call-client-output',
			'parameters' => array( 'disposition_id' => $packet_id ),
			'session_id' => 'session-client-output',
		),
		array(
			'user_id' => 7,
			'agent_id' => 11,
			'job_id' => 808,
			'packet_execution_identity' => array(
				'job_id'            => 808,
				'tool_name'         => 'client/packet_handler',
				'disposition_id'    => $packet_id,
				'reservation_token' => 'must-never-leave-engine-state',
			),
		)
	);
	$client_request   = is_array( $client_pending['runtime_tool_request'] ?? null ) ? $client_pending['runtime_tool_request'] : array();
	$client_execution = $client_request['metadata']['datamachine']['packet_execution'] ?? array();
	$assert( ! str_contains( (string) json_encode( $client_pending ), 'reservation_token' ) && ! str_contains( (string) json_encode( $client_pending ), 'must-never-leave-engine-state' ), 'actual client-visible deferred request output contains no reservation token' );
	$assert( 808 === ( $client_execution['job_id'] ?? null ) && 'client/packet_handler' === ( $client_execution['tool_name'] ?? null ) && $packet_id === ( $client_execution['disposition_id'] ?? null ), 'client-visible deferred request retains only safe packet execution identity' );

	$chat_db->sessions['session-integrated'] = array( 'messages' => array(), 'metadata' => array(), 'provider' => 'openai', 'model' => 'gpt' );
	$integrated_tool = array(
		'name'              => 'client/deferred_action',
		'description'       => 'A deferred client action.',
		'parameters'        => array( 'type' => 'object', 'properties' => array() ),
		'executor'          => 'client',
		'external_executor' => true,
		'runtime_tool'      => true,
	);
	$integrated_tools = array( 'client/deferred_action' => $integrated_tool );
	$integrated_payload = array(
		'user_id'        => 7,
		'agent_id'       => 11,
		'session_id'     => 'session-integrated',
		'client_context' => array(
			'session_id'         => 'session-integrated',
			'runtime_tool_async' => true,
		),
	);
	$integrated_executor = new RuntimeToolContractStoreSmokeExecutor();
	$integrated_result   = WP_Agent_Conversation_Loop::run(
		array( array( 'role' => 'user', 'content' => 'Run the deferred action.' ) ),
		static fn( array $messages ): array => array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'id'         => 'call-integrated',
					'name'       => 'client/deferred_action',
					'parameters' => array(),
				),
			),
		),
		array(
			'max_turns'         => 1,
			'tool_executor'     => $integrated_executor,
			'tool_declarations' => $integrated_tools,
			'pre_tool_mediator' => datamachine_build_pre_tool_mediator(
				$integrated_tools,
				$integrated_payload,
				'chat',
				array( 'chat' ),
				new DataMachineToolRuntimeRules(),
				new class() implements LoopEventSinkInterface {
					public function emit( string $event, array $payload = array() ): void {
						unset( $event, $payload );
					}
				},
				array()
			),
			'runtime_tool_store' => datamachine_runtime_tool_request_store(),
		)
	);
	$integrated_request    = is_array( $integrated_result['runtime_tool_pending'] ?? null ) ? $integrated_result['runtime_tool_pending'] : array();
	$integrated_request_id = (string) ( $integrated_request['request_id'] ?? '' );
	$integrated_job_id     = (int) substr( $integrated_request_id, strlen( 'runtime_tool_' ) );
	$assert( str_starts_with( $integrated_request_id, 'runtime_tool_' ) && $integrated_job_id > 0, 'integrated async mediation preserves the preallocated Data Machine request id' );
	$assert( 'call-integrated' === ( $integrated_request['tool_call_id'] ?? '' ), 'integrated async mediation preserves the provider tool call id' );
	$assert( $integrated_request_id === ( Jobs::$engine_data[ $integrated_job_id ]['runtime_tool_request']['request_id'] ?? '' ), 'Agents API persists the canonical mediated request in the Data Machine store' );
	$assert( isset( $chat_db->sessions['session-integrated']['metadata']['runtime_tool_requests'][ $integrated_request_id ] ), 'integrated async mediation mirrors the canonical request into session state' );
	$assert( 0 === $integrated_executor->calls, 'integrated async mediation bypasses the PHP executor' );
	if ( $failures ) {
		echo "\nFAILED: " . count( $failures ) . " runtime tool contract store assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$passes} runtime tool contract store assertions passed.\n";
}
