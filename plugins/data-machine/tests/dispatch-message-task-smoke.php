<?php
/**
 * Pure-PHP smoke test for DispatchMessageTask (#2049).
 *
 * Run with: php tests/dispatch-message-task-smoke.php
 *
 * Pins four behaviours of the new system_task wrapping
 * `agents/dispatch-message`:
 *
 *   1. The task is registered for task id `dispatch_message` and the
 *      DispatchMessageTask class file/import shape lives in the provider.
 *   2. When the ability is not registered, the task fails the job with a
 *      clear error message.
 *   3. When a handler returns canonical output, the task completes the
 *      job and surfaces `sent / channel / recipient / message_id /
 *      metadata` in the result envelope.
 *   4. When a handler returns a WP_Error, the task fails the job and
 *      propagates the error code + message.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Engine\AI\System\Tasks {

	// Lightweight SystemTask shim so we can exercise DispatchMessageTask
	// without booting the real Jobs DB layer that the production
	// SystemTask base depends on. Records completeJob/failJob calls so
	// the test can assert on them.
	abstract class SystemTask {
		/** @var array<int, array{job_id:int, data:array}> */
		public static array $completed = array();
		/** @var array<int, array{job_id:int, message:string}> */
		public static array $failed = array();

		abstract public function executeTask( int $jobId, array $params ): void;
		abstract public function getTaskType(): string;

		public static function getTaskMeta(): array {
			return array();
		}

		public function needsPipelineContext(): bool {
			return false;
		}

		public function getFlowStepConfigPassthrough(): array {
			return array();
		}

		protected function completeJob( int $jobId, array $data ): void {
			self::$completed[] = array(
				'job_id' => $jobId,
				'data'   => $data,
			);
		}

		protected function failJob( int $jobId, string $message ): void {
			self::$failed[] = array(
				'job_id'  => $jobId,
				'message' => $message,
			);
		}

		public static function resetCalls(): void {
			self::$completed = array();
			self::$failed    = array();
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	// ─── Minimal WP function stubs ────────────────────────────────────

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value ) {
			return $value;
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void {
			// no-op
		}
	}

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( string $type ): string {
			return 'mysql' === $type ? '2000-01-01 00:00:00' : (string) time();
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private string $code;
			private string $message;

			public function __construct( string $code = '', string $message = '' ) {
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
	}

	$GLOBALS['dispatch_message_ability_resolver'] = static function ( string $slug ) {
		return null;
	};

	if ( ! function_exists( 'wp_get_ability' ) ) {
		function wp_get_ability( string $slug ) {
			$resolver = $GLOBALS['dispatch_message_ability_resolver'] ?? null;
			if ( ! is_callable( $resolver ) ) {
				return null;
			}
			return $resolver( $slug );
		}
	}

	// Load the production task class. It uses the namespaced SystemTask
	// shim defined above instead of the real Jobs-coupled base.
	require_once dirname( __DIR__ ) . '/inc/Engine/AI/System/Tasks/DispatchMessageTask.php';

	/**
	 * Tiny ability double matching the surface DispatchMessageTask uses
	 * (`execute( array $input )`). The executor returns whatever the
	 * registered callable returns — mimics the agents/dispatch-message
	 * dispatcher contract.
	 */
	final class DispatchMessageTask_FakeAbility {
		/** @var callable */
		private $callback;
		/** @var array<int, array> */
		public array $invocations = array();

		public function __construct( callable $callback ) {
			$this->callback = $callback;
		}

		public function execute( array $input ) {
			$this->invocations[] = $input;
			return call_user_func( $this->callback, $input );
		}
	}

	// ─── Test harness ─────────────────────────────────────────────────

	$GLOBALS['__dispatch_failures'] = array();
	$GLOBALS['__dispatch_passes']   = 0;

	function dispatch_assert_equals( $expected, $actual, string $name ): void {
		if ( $expected === $actual ) {
			++$GLOBALS['__dispatch_passes'];
			echo "  ✓ {$name}\n";
			return;
		}

		$GLOBALS['__dispatch_failures'][] = $name;
		echo "  ✗ {$name}\n";
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	}

	function dispatch_assert_true( bool $condition, string $name, string $detail = '' ): void {
		if ( $condition ) {
			++$GLOBALS['__dispatch_passes'];
			echo "  ✓ {$name}\n";
			return;
		}

		$GLOBALS['__dispatch_failures'][] = $name;
		echo "  ✗ {$name}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
	}

	function dispatch_assert_contains( string $needle, string $haystack, string $name ): void {
		dispatch_assert_true(
			'' !== $needle && false !== strpos( $haystack, $needle ),
			$name,
			"expected to find '{$needle}' in '{$haystack}'"
		);
	}

	echo "dispatch-message task smoke (#2049)\n";
	echo "------------------------------------\n";

	$root = dirname( __DIR__ );

	$task_path     = $root . '/inc/Engine/AI/System/Tasks/DispatchMessageTask.php';
	$provider_path = $root . '/inc/Engine/AI/System/SystemAgentServiceProvider.php';

	$task_source     = file_exists( $task_path ) ? (string) file_get_contents( $task_path ) : '';
	$provider_source = file_exists( $provider_path ) ? (string) file_get_contents( $provider_path ) : '';

	echo "\n[1] task file + provider registration:\n";
	dispatch_assert_true( '' !== $task_source, 'DispatchMessageTask.php exists' );
	dispatch_assert_contains( 'class DispatchMessageTask extends SystemTask', $task_source, 'class extends SystemTask' );
	dispatch_assert_contains( "return 'dispatch_message';", $task_source, 'getTaskType returns dispatch_message' );
	dispatch_assert_contains( 'agents/dispatch-message', $task_source, 'task references the canonical ability slug' );
	dispatch_assert_contains( 'use DataMachine\\Engine\\AI\\System\\Tasks\\DispatchMessageTask;', $provider_source, 'provider imports DispatchMessageTask' );
	dispatch_assert_contains( "\$tasks['dispatch_message']", $provider_source, 'provider maps dispatch_message task id' );
	dispatch_assert_contains( 'DispatchMessageTask::class', $provider_source, 'provider maps to DispatchMessageTask::class' );

	echo "\n[2] no transport-specific names in the new code:\n";
	$banned = array( 'kimaki', 'discord', 'slack', 'telegram', 'whatsapp', 'cc-connect' );
	foreach ( $banned as $name ) {
		$task_clean = false === stripos( $task_source, $name );
		dispatch_assert_true( $task_clean, "task source contains no '{$name}'" );
	}

	$task = new \DataMachine\Engine\AI\System\Tasks\DispatchMessageTask();
	$base = \DataMachine\Engine\AI\System\Tasks\SystemTask::class;

	echo "\n[3] ability-missing path fails the job cleanly:\n";

	$base::resetCalls();
	$GLOBALS['dispatch_message_ability_resolver'] = static function ( string $slug ) {
		return null; // simulate wp_get_ability returning null
	};

	$task->executeTask(
		101,
		array(
			'task'   => 'dispatch_message',
			'params' => array(
				'channel'   => 'example-channel',
				'recipient' => 'example-recipient',
				'message'   => 'hi',
			),
		)
	);

	dispatch_assert_equals( 0, count( $base::$completed ), 'ability missing: no job completed' );
	dispatch_assert_equals( 1, count( $base::$failed ), 'ability missing: job failed once' );
	dispatch_assert_equals( 101, $base::$failed[0]['job_id'], 'ability missing: failed job id propagated' );
	dispatch_assert_contains( 'agents/dispatch-message', $base::$failed[0]['message'], 'ability missing: error mentions ability slug' );

	echo "\n[4] handler success path completes the job with canonical output:\n";

	$base::resetCalls();

	$success_payload = array(
		'sent'       => true,
		'channel'    => 'example-channel',
		'recipient'  => 'example-recipient',
		'message_id' => 'msg_42',
		'metadata'   => array( 'delivered_at' => 'now' ),
	);

	$success_ability = new DispatchMessageTask_FakeAbility(
		static function ( array $input ) use ( $success_payload ) {
			return $success_payload;
		}
	);

	$GLOBALS['dispatch_message_ability_resolver'] = static function ( string $slug ) use ( $success_ability ) {
		return 'agents/dispatch-message' === $slug ? $success_ability : null;
	};

	$task->executeTask(
		202,
		array(
			'task'   => 'dispatch_message',
			'params' => array(
				'channel'         => 'example-channel',
				'recipient'       => 'example-recipient',
				'message'         => 'hello world',
				'conversation_id' => null,
				'attachments'     => array(),
				'client_context'  => array( 'agent_id' => 7 ),
				'metadata'        => array( 'job_id' => 202 ),
			),
		)
	);

	dispatch_assert_equals( 1, count( $base::$completed ), 'success: job completed once' );
	dispatch_assert_equals( 0, count( $base::$failed ), 'success: no job failures' );
	dispatch_assert_equals( 202, $base::$completed[0]['job_id'], 'success: completed job id propagated' );

	$completed_data = $base::$completed[0]['data'];
	dispatch_assert_equals( true, $completed_data['sent'] ?? null, 'success: sent flag in envelope' );
	dispatch_assert_equals( 'example-channel', $completed_data['channel'] ?? null, 'success: channel in envelope' );
	dispatch_assert_equals( 'example-recipient', $completed_data['recipient'] ?? null, 'success: recipient in envelope' );
	dispatch_assert_equals( 'msg_42', $completed_data['message_id'] ?? null, 'success: message_id in envelope' );
	dispatch_assert_equals( array( 'delivered_at' => 'now' ), $completed_data['metadata'] ?? null, 'success: metadata in envelope' );
	dispatch_assert_true( isset( $completed_data['completed_at'] ), 'success: completed_at timestamp present' );

	$forwarded = $success_ability->invocations[0] ?? array();
	dispatch_assert_equals( 'example-channel', $forwarded['channel'] ?? null, 'success: channel forwarded' );
	dispatch_assert_equals( 'example-recipient', $forwarded['recipient'] ?? null, 'success: recipient forwarded' );
	dispatch_assert_equals( 'hello world', $forwarded['message'] ?? null, 'success: message forwarded' );
	dispatch_assert_true( array_key_exists( 'conversation_id', $forwarded ) && null === $forwarded['conversation_id'], 'success: conversation_id forwarded as null' );
	dispatch_assert_equals( array(), $forwarded['attachments'] ?? null, 'success: attachments forwarded' );
	dispatch_assert_equals( array( 'agent_id' => 7 ), $forwarded['client_context'] ?? null, 'success: client_context forwarded' );
	dispatch_assert_equals( array( 'job_id' => 202 ), $forwarded['metadata'] ?? null, 'success: metadata forwarded' );
	dispatch_assert_true( ! array_key_exists( 'task', $forwarded ), 'success: SystemTask wrapper key task is stripped' );

	echo "\n[5] handler WP_Error path fails the job with the error message:\n";

	$base::resetCalls();

	$error_ability = new DispatchMessageTask_FakeAbility(
		static function ( array $input ) {
			return new \WP_Error( 'transport_offline', 'Channel transport is offline.' );
		}
	);

	$GLOBALS['dispatch_message_ability_resolver'] = static function ( string $slug ) use ( $error_ability ) {
		return 'agents/dispatch-message' === $slug ? $error_ability : null;
	};

	$task->executeTask(
		303,
		array(
			'task'   => 'dispatch_message',
			'params' => array(
				'channel'   => 'example-channel',
				'recipient' => 'example-recipient',
				'message'   => 'will fail',
			),
		)
	);

	dispatch_assert_equals( 0, count( $base::$completed ), 'wp_error: no job completed' );
	dispatch_assert_equals( 1, count( $base::$failed ), 'wp_error: job failed once' );
	dispatch_assert_equals( 303, $base::$failed[0]['job_id'], 'wp_error: failed job id propagated' );
	dispatch_assert_contains( 'transport_offline', $base::$failed[0]['message'], 'wp_error: error code surfaced' );
	dispatch_assert_contains( 'Channel transport is offline.', $base::$failed[0]['message'], 'wp_error: error message surfaced' );

	echo "\n[6] flat-shape params (no nested params key) also work:\n";

	$base::resetCalls();

	$flat_ability = new DispatchMessageTask_FakeAbility(
		static function ( array $input ) {
			return array(
				'sent'       => true,
				'channel'    => $input['channel'] ?? '',
				'recipient'  => $input['recipient'] ?? '',
				'message_id' => 'flat_1',
				'metadata'   => array(),
			);
		}
	);

	$GLOBALS['dispatch_message_ability_resolver'] = static function ( string $slug ) use ( $flat_ability ) {
		return 'agents/dispatch-message' === $slug ? $flat_ability : null;
	};

	$task->executeTask(
		404,
		array(
			'task'      => 'dispatch_message',
			'task_type' => 'dispatch_message',
			'channel'   => 'flat-channel',
			'recipient' => 'flat-recipient',
			'message'   => 'flat body',
		)
	);

	dispatch_assert_equals( 1, count( $base::$completed ), 'flat shape: job completed' );
	$flat_forwarded = $flat_ability->invocations[0] ?? array();
	dispatch_assert_equals( 'flat-channel', $flat_forwarded['channel'] ?? null, 'flat shape: channel forwarded' );
	dispatch_assert_equals( 'flat-recipient', $flat_forwarded['recipient'] ?? null, 'flat shape: recipient forwarded' );
	dispatch_assert_equals( 'flat body', $flat_forwarded['message'] ?? null, 'flat shape: message forwarded' );
	dispatch_assert_true( ! array_key_exists( 'task', $flat_forwarded ), 'flat shape: task scaffolding stripped' );
	dispatch_assert_true( ! array_key_exists( 'task_type', $flat_forwarded ), 'flat shape: task_type scaffolding stripped' );

	echo "\n------------------------------------\n";
	$total = $GLOBALS['__dispatch_passes'] + count( $GLOBALS['__dispatch_failures'] );
	echo "{$GLOBALS['__dispatch_passes']} / {$total} passed\n";

	if ( ! empty( $GLOBALS['__dispatch_failures'] ) ) {
		echo "\nFailures:\n";
		foreach ( $GLOBALS['__dispatch_failures'] as $failure ) {
			echo " - {$failure}\n";
		}
		exit( 1 );
	}

	echo "\nAll assertions passed.\n";
}
