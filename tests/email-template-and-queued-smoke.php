<?php
/**
 * Pure-PHP smoke for SendEmailAbility template registry and
 * SendEmailQueuedAbility worker/scheduling behavior.
 *
 * Run with: php tests/email-template-and-queued-smoke.php
 *
 * Covers:
 *   (a) template render + placeholder replacement (template emits {site_name}
 *       and {date}; placeholder pass resolves them after render)
 *   (b) unknown template id → structured error, no silent fallback
 *   (c) "send-now" queued path → as_enqueue_async_action invoked with
 *       worker hook + group + _attempt=1 payload
 *   (d) scheduled queued path → as_schedule_single_action invoked at the
 *       resolved timestamp
 *   (e) worker invokes the synchronous ability and re-enqueues with
 *       exponential backoff up to MAX_ATTEMPTS on failure
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// ---------------------------------------------------------------------------
// Minimal WordPress shim — just what the abilities under test exercise.
// ---------------------------------------------------------------------------

$GLOBALS['dm_email_smoke_filters']         = array();
$GLOBALS['dm_email_smoke_actions']         = array();
$GLOBALS['dm_email_smoke_async']           = array();
$GLOBALS['dm_email_smoke_scheduled']       = array();
$GLOBALS['dm_email_smoke_logs']            = array();
$GLOBALS['dm_email_smoke_wp_mail_calls']   = array();
$GLOBALS['dm_email_smoke_wp_mail_return']  = true;
$GLOBALS['dm_email_smoke_ability_calls']   = array();
$GLOBALS['dm_email_smoke_ability_returns'] = array();
$GLOBALS['dm_email_smoke_action_id_seq']   = 1000;

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['dm_email_smoke_filters'][ $hook ][] = $callback;
		unset( $priority, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		$args = func_get_args();
		$args = array_slice( $args, 1 );
		if ( empty( $GLOBALS['dm_email_smoke_filters'][ $hook ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['dm_email_smoke_filters'][ $hook ] as $callback ) {
			$args[0] = call_user_func_array( $callback, $args );
		}
		return $args[0];
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['dm_email_smoke_actions'][ $hook ][] = $callback;
		unset( $priority, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook ): void {
		$args = func_get_args();
		$args = array_slice( $args, 1 );
		if ( 'datamachine_log' === $hook ) {
			$GLOBALS['dm_email_smoke_logs'][] = $args;
			return;
		}
		if ( empty( $GLOBALS['dm_email_smoke_actions'][ $hook ] ) ) {
			return;
		}
		foreach ( $GLOBALS['dm_email_smoke_actions'][ $hook ] as $callback ) {
			call_user_func_array( $callback, $args );
		}
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook ): bool {
		unset( $hook );
		return false;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): bool {
		unset( $hook );
		return false;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ): void {
		// Smoke does not exercise the ability registry — execute() is called
		// directly on instances. Capture for inspection only.
		$GLOBALS['dm_email_smoke_registered'][ $name ] = $args;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return is_string( $email ) && false !== strpos( $email, '@' ) ? $email : false;
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $body, $headers = array(), $attachments = array() ) {
		$GLOBALS['dm_email_smoke_wp_mail_calls'][] = array(
			'to'          => $to,
			'subject'     => $subject,
			'body'        => $body,
			'headers'     => $headers,
			'attachments' => $attachments,
		);
		return $GLOBALS['dm_email_smoke_wp_mail_return'];
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $key ) {
		return 'Smoke Site';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) {
		if ( 'date_format' === $key ) {
			return 'Y-m-d';
		}
		if ( 'admin_email' === $key ) {
			return 'admin@example.com';
		}
		return $default;
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $format, ?int $timestamp = null ): string {
		return gmdate( $format, $timestamp ?? time() );
	}
}

// Action Scheduler stubs (queue capture).
if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ): int {
		$id                                    = ++$GLOBALS['dm_email_smoke_action_id_seq'];
		$GLOBALS['dm_email_smoke_async'][]     = array(
			'id'    => $id,
			'hook'  => $hook,
			'args'  => $args,
			'group' => $group,
		);
		return $id;
	}
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
		$id                                       = ++$GLOBALS['dm_email_smoke_action_id_seq'];
		$GLOBALS['dm_email_smoke_scheduled'][]    = array(
			'id'        => $id,
			'timestamp' => $timestamp,
			'hook'      => $hook,
			'args'      => $args,
			'group'     => $group,
		);
		return $id;
	}
}

// wp_get_ability stub: returns an object whose execute() proxies to a
// configurable closure stack. Used to verify the worker calls the sync ability.
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ): ?object {
		if ( 'datamachine/send-email' !== $name ) {
			return null;
		}

		return new class() {
			public function execute( array $input ): array {
				$GLOBALS['dm_email_smoke_ability_calls'][] = $input;
				if ( ! empty( $GLOBALS['dm_email_smoke_ability_returns'] ) ) {
					return array_shift( $GLOBALS['dm_email_smoke_ability_returns'] );
				}
				return array( 'success' => true, 'logs' => array() );
			}
		};
	}
}

// ---------------------------------------------------------------------------
// Permission stub (the abilities call PermissionHelper::can_manage()) and
// the abilities under test.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/fixtures/permission-helper-stub.php';
require_once __DIR__ . '/../inc/Abilities/Publish/SendEmailAbility.php';
require_once __DIR__ . '/../inc/Abilities/Publish/SendEmailQueuedAbility.php';

use DataMachine\Abilities\Publish\SendEmailAbility;
use DataMachine\Abilities\Publish\SendEmailQueuedAbility;

function dm_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		echo "  [PASS] {$message}\n";
		return;
	}
	echo "  [FAIL] {$message}\n";
	exit( 1 );
}

function dm_email_smoke_reset(): void {
	$GLOBALS['dm_email_smoke_filters']         = array();
	$GLOBALS['dm_email_smoke_actions']         = array();
	$GLOBALS['dm_email_smoke_async']           = array();
	$GLOBALS['dm_email_smoke_scheduled']       = array();
	$GLOBALS['dm_email_smoke_logs']            = array();
	$GLOBALS['dm_email_smoke_wp_mail_calls']   = array();
	$GLOBALS['dm_email_smoke_wp_mail_return']  = true;
	$GLOBALS['dm_email_smoke_ability_calls']   = array();
	$GLOBALS['dm_email_smoke_ability_returns'] = array();
}

echo "=== email-template-and-queued-smoke ===\n";

// ---------------------------------------------------------------------------
// [1] Template render runs BEFORE placeholder replacement.
// ---------------------------------------------------------------------------
echo "\n[1] template render + placeholder replacement\n";
dm_email_smoke_reset();

add_filter( 'datamachine_email_templates', static function ( array $templates ): array {
	$templates['smoke-template'] = static function ( array $context ): string {
		$name = isset( $context['name'] ) ? (string) $context['name'] : 'friend';
		// Template emits {site_name} and {date} — placeholder pass must resolve them.
		return "<p>Hello {$name} from {site_name} on {date}</p>";
	};
	return $templates;
} );

$ability = new SendEmailAbility();
$result  = $ability->execute( array(
	'to'       => 'user@example.com',
	'subject'  => 'Smoke {site_name}',
	'template' => 'smoke-template',
	'context'  => array( 'name' => 'Chris' ),
) );

dm_assert( true === ( $result['success'] ?? false ), 'template render path returns success' );
dm_assert( 1 === count( $GLOBALS['dm_email_smoke_wp_mail_calls'] ), 'wp_mail() invoked exactly once' );
$call = $GLOBALS['dm_email_smoke_wp_mail_calls'][0];
dm_assert( false !== strpos( $call['body'], 'Hello Chris' ), 'template context interpolated into body' );
dm_assert( false !== strpos( $call['body'], 'Smoke Site' ), 'template-emitted {site_name} resolved after render' );
dm_assert( false === strpos( $call['body'], '{site_name}' ), 'no unresolved {site_name} placeholder remains' );
dm_assert( false === strpos( $call['body'], '{date}' ), 'no unresolved {date} placeholder remains' );
dm_assert( false !== strpos( $call['subject'], 'Smoke Site' ), 'subject placeholder still works' );

// ---------------------------------------------------------------------------
// [2] Unknown template id → structured error, no wp_mail invocation.
// ---------------------------------------------------------------------------
echo "\n[2] unknown template id returns structured error\n";
dm_email_smoke_reset();

$ability = new SendEmailAbility();
$result  = $ability->execute( array(
	'to'       => 'user@example.com',
	'subject'  => 'Hi',
	'template' => 'does-not-exist',
) );

dm_assert( false === ( $result['success'] ?? null ), 'unknown template id returns success=false' );
dm_assert( ! empty( $result['error'] ), 'unknown template id returns an error message' );
dm_assert( false !== strpos( (string) $result['error'], 'does-not-exist' ), 'error names the missing template id' );
dm_assert( 0 === count( $GLOBALS['dm_email_smoke_wp_mail_calls'] ), 'wp_mail() NOT invoked when template missing' );

// ---------------------------------------------------------------------------
// [3] Missing body AND missing template → structured error.
// ---------------------------------------------------------------------------
echo "\n[3] missing body+template returns structured error\n";
dm_email_smoke_reset();

$ability = new SendEmailAbility();
$result  = $ability->execute( array(
	'to'      => 'user@example.com',
	'subject' => 'Hi',
) );

dm_assert( false === ( $result['success'] ?? null ), 'missing body+template returns success=false' );
dm_assert( false !== strpos( (string) ( $result['error'] ?? '' ), 'body' ), 'error mentions body' );
dm_assert( 0 === count( $GLOBALS['dm_email_smoke_wp_mail_calls'] ), 'wp_mail() NOT invoked when neither provided' );

// ---------------------------------------------------------------------------
// [4] Backwards-compat: omitting template uses body verbatim.
// ---------------------------------------------------------------------------
echo "\n[4] backwards-compat: raw body still works\n";
dm_email_smoke_reset();

$ability = new SendEmailAbility();
$result  = $ability->execute( array(
	'to'      => 'user@example.com',
	'subject' => 'Hi',
	'body'    => '<p>Plain body for {site_name}</p>',
) );

dm_assert( true === ( $result['success'] ?? false ), 'raw body path returns success' );
$call = $GLOBALS['dm_email_smoke_wp_mail_calls'][0];
dm_assert( false !== strpos( $call['body'], 'Plain body for Smoke Site' ), 'raw body placeholders resolved' );

// ---------------------------------------------------------------------------
// [5] Queued "send-now" uses as_enqueue_async_action with _attempt=1.
// ---------------------------------------------------------------------------
echo "\n[5] queued send-now enqueues async\n";
dm_email_smoke_reset();

$queued = new SendEmailQueuedAbility();
$result = $queued->execute( array(
	'to'      => 'user@example.com',
	'subject' => 'Async hi',
	'body'    => '<p>Hi</p>',
) );

dm_assert( true === ( $result['success'] ?? false ), 'queued send-now returns success' );
dm_assert( 1 === count( $GLOBALS['dm_email_smoke_async'] ), 'exactly one async action enqueued' );
$async = $GLOBALS['dm_email_smoke_async'][0];
dm_assert( SendEmailQueuedAbility::WORKER_HOOK === $async['hook'], 'enqueued under worker hook' );
dm_assert( SendEmailQueuedAbility::GROUP === $async['group'], 'enqueued under datamachine-email group' );
dm_assert( isset( $async['args'][0]['_attempt'] ) && 1 === $async['args'][0]['_attempt'], 'payload carries _attempt=1' );
dm_assert( ! isset( $async['args'][0]['send_at'] ), 'send_at stripped from worker payload' );
dm_assert( ! isset( $async['args'][0]['priority'] ), 'priority stripped from worker payload' );
dm_assert( 0 === count( $GLOBALS['dm_email_smoke_scheduled'] ), 'no scheduled action created for send-now' );

// ---------------------------------------------------------------------------
// [6] Queued with send_at uses as_schedule_single_action.
// ---------------------------------------------------------------------------
echo "\n[6] queued with send_at schedules at timestamp\n";
dm_email_smoke_reset();

$future = time() + 3600;
$queued = new SendEmailQueuedAbility();
$result = $queued->execute( array(
	'to'      => 'user@example.com',
	'subject' => 'Later',
	'body'    => '<p>Later</p>',
	'send_at' => gmdate( 'c', $future ),
) );

dm_assert( true === ( $result['success'] ?? false ), 'scheduled send returns success' );
dm_assert( 1 === count( $GLOBALS['dm_email_smoke_scheduled'] ), 'exactly one scheduled action' );
dm_assert( 0 === count( $GLOBALS['dm_email_smoke_async'] ), 'no async action for scheduled send' );
$scheduled = $GLOBALS['dm_email_smoke_scheduled'][0];
dm_assert( $future === $scheduled['timestamp'], 'scheduled at exact requested timestamp' );
dm_assert( SendEmailQueuedAbility::WORKER_HOOK === $scheduled['hook'], 'scheduled under worker hook' );
dm_assert( SendEmailQueuedAbility::GROUP === $scheduled['group'], 'scheduled under datamachine-email group' );
dm_assert( $future === ( $result['scheduled_for'] ?? 0 ), 'result.scheduled_for matches' );

// ---------------------------------------------------------------------------
// [7] Worker invokes synchronous ability and does NOT retry on success.
// ---------------------------------------------------------------------------
echo "\n[7] worker invokes sync ability and stops on success\n";
dm_email_smoke_reset();

$GLOBALS['dm_email_smoke_ability_returns'] = array(
	array( 'success' => true, 'logs' => array() ),
);

SendEmailQueuedAbility::runWorker( array(
	'to'       => 'user@example.com',
	'subject'  => 'Hi',
	'body'     => '<p>Hi</p>',
	'_attempt' => 1,
) );

dm_assert( 1 === count( $GLOBALS['dm_email_smoke_ability_calls'] ), 'sync ability called exactly once' );
$ability_input = $GLOBALS['dm_email_smoke_ability_calls'][0];
dm_assert( ! isset( $ability_input['_attempt'] ), '_attempt stripped before invoking sync ability' );
dm_assert( 'user@example.com' === ( $ability_input['to'] ?? '' ), 'payload forwarded to sync ability' );
dm_assert( 0 === count( $GLOBALS['dm_email_smoke_scheduled'] ), 'no retry scheduled on success' );

// ---------------------------------------------------------------------------
// [8] Worker re-enqueues with exponential backoff on failure, up to cap.
// ---------------------------------------------------------------------------
echo "\n[8] worker retries with backoff, caps at MAX_ATTEMPTS\n";
dm_email_smoke_reset();

// First attempt fails — worker should reschedule with _attempt=2.
$GLOBALS['dm_email_smoke_ability_returns'] = array(
	array( 'success' => false, 'error' => 'smtp boom', 'logs' => array() ),
);

$before_retry = time();
SendEmailQueuedAbility::runWorker( array(
	'to'       => 'user@example.com',
	'subject'  => 'Hi',
	'body'     => '<p>Hi</p>',
	'_attempt' => 1,
) );

dm_assert( 1 === count( $GLOBALS['dm_email_smoke_scheduled'] ), 'failure reschedules worker' );
$retry = $GLOBALS['dm_email_smoke_scheduled'][0];
dm_assert( SendEmailQueuedAbility::WORKER_HOOK === $retry['hook'], 'retry hook matches worker' );
dm_assert( 2 === $retry['args'][0]['_attempt'], 'retry payload has _attempt=2' );
dm_assert( $retry['timestamp'] >= $before_retry + 60, 'first retry delayed at least 60s' );
dm_assert( $retry['timestamp'] <= $before_retry + 70, 'first retry delay matches 60s baseline' );

// Final attempt fails — worker must NOT reschedule beyond MAX_ATTEMPTS.
dm_email_smoke_reset();
$GLOBALS['dm_email_smoke_ability_returns'] = array(
	array( 'success' => false, 'error' => 'final boom', 'logs' => array() ),
);

SendEmailQueuedAbility::runWorker( array(
	'to'       => 'user@example.com',
	'subject'  => 'Hi',
	'body'     => '<p>Hi</p>',
	'_attempt' => SendEmailQueuedAbility::MAX_ATTEMPTS,
) );

dm_assert( 1 === count( $GLOBALS['dm_email_smoke_ability_calls'] ), 'sync ability invoked on final attempt' );
dm_assert( 0 === count( $GLOBALS['dm_email_smoke_scheduled'] ), 'no further retry past MAX_ATTEMPTS' );

echo "\nAll email template and queued smoke assertions passed.\n";
