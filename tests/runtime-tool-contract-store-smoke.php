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

		public function create_job( array $job ): int {
			$job_id              = self::$next_id++;
			self::$jobs[ $job_id ] = array_merge( $job, array( 'status' => 'created' ) );

			return $job_id;
		}

		public function start_job( int $job_id, string $status ): void {
			self::$jobs[ $job_id ]['status'] = $status;
		}

		public function store_engine_data( int $job_id, array $data ): void {
			self::$engine_data[ $job_id ] = $data;
		}

		public function retrieve_engine_data( int $job_id ): array {
			return self::$engine_data[ $job_id ] ?? array();
		}

		public function complete_job( int $job_id, string $status ): void {
			self::$jobs[ $job_id ]['status'] = $status;
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
	use DataMachine\Core\Database\Chat\ConversationStoreFactory;
	use DataMachine\Core\Database\Jobs\Jobs;
	use function DataMachine\Engine\AI\datamachine_defer_runtime_tool_call;
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

	$GLOBALS['datamachine_runtime_tool_scheduled'] = array();
	$GLOBALS['datamachine_runtime_tool_enqueued']  = array();

	function as_schedule_single_action( int $timestamp, string $hook, array $args, string $group ): void {
		$GLOBALS['datamachine_runtime_tool_scheduled'][] = compact( 'timestamp', 'hook', 'args', 'group' );
	}

	function as_enqueue_async_action( string $hook, array $args, string $group ): void {
		$GLOBALS['datamachine_runtime_tool_enqueued'][] = compact( 'hook', 'args', 'group' );
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void {
			unset( $hook, $args );
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $hook, callable|string $callback ): void {
			unset( $hook, $callback );
		}
	}

	require __DIR__ . '/../vendor/wordpress/agents-api/src/Runtime/class-wp-agent-citation-metadata.php';
	require __DIR__ . '/../vendor/wordpress/agents-api/src/Runtime/class-wp-agent-runtime-tool-request.php';
	require __DIR__ . '/../vendor/wordpress/agents-api/src/Runtime/class-wp-agent-runtime-tool-result.php';
	require __DIR__ . '/../vendor/wordpress/agents-api/src/Runtime/class-wp-agent-runtime-tool-request-store.php';
	require __DIR__ . '/../vendor/wordpress/agents-api/src/Runtime/class-wp-agent-runtime-tool-continuation.php';
	require __DIR__ . '/../vendor/wordpress/agents-api/src/Runtime/class-wp-agent-runtime-tool-lifecycle.php';
	require __DIR__ . '/../inc/Engine/AI/RuntimeToolRunStateStore.php';
	require __DIR__ . '/../inc/Engine/AI/conversation-loop.php';

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

	$pending = datamachine_defer_runtime_tool_call(
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
	$assert( true === ( $submission['success'] ?? false ), 'result submission succeeds' );
	$assert( WP_Agent_Runtime_Tool_Result::STATUS_SUBMITTED === ( $stored['metadata']['datamachine']['result']['status'] ?? '' ), 'submitted result is stored in canonical result shape' );
	$assert( 'fulfilled' === ( $stored['metadata']['datamachine']['persistence_status'] ?? '' ), 'submitted result completes namespaced Data Machine status' );
	$assert( 'completed' === ( Jobs::$jobs[1]['status'] ?? '' ), 'successful result completes the Data Machine job' );
	$assert( 1 === count( $chat_db->sessions['session-1']['messages'] ), 'submitted result appends a transcript tool message' );
	$assert( false === ( $chat_db->sessions['session-1']['metadata']['has_pending_tools'] ?? true ), 'submitted result clears pending session state' );
	$assert( 'datamachine_runtime_tool_resume' === ( $GLOBALS['datamachine_runtime_tool_enqueued'][0]['hook'] ?? '' ), 'submitted result enqueues resume action' );
	datamachine_resume_runtime_tool_request( $request_id );
	$delegated_resume = \DataMachine\Api\Chat\ChatOrchestrator::$calls[0] ?? array();
	$assert( 7 === ( $delegated_resume['user_id'] ?? null ), 'async delegated resume retains runtime/session ownership' );
	$assert( 52 === ( $delegated_resume['calling_user_id'] ?? null ), 'async delegated resume restores the original acting caller' );
	$assert( array( 'owner_tool' ) === ( $delegated_resume['tools'] ?? null ), 'delegated caller-scoped tools remain visible after async resume' );
	$assert( 52 === ( Jobs::$engine_data[1]['runtime_tool_run_state']['resume_payload']['calling_user_id'] ?? null ), 'runtime run-state records the delegated caller in its resume payload' );

	$timeout_pending = datamachine_defer_runtime_tool_call(
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
	$system_pending = datamachine_defer_runtime_tool_call(
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

	$assert( array() === datamachine_runtime_tool_request_store()->recent_pending(), 'store adapter implements the Agents API recent-pending contract' );

	if ( $failures ) {
		echo "\nFAILED: " . count( $failures ) . " runtime tool contract store assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$passes} runtime tool contract store assertions passed.\n";
}
