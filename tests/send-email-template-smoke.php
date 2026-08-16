<?php
/**
 * Pure-PHP smoke test for SendEmailAbility template + mail_site_id behavior
 * and SendEmailQueuedAbility enqueue + worker retry behavior (#2064).
 *
 * Run with: php tests/send-email-template-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failed = 0;
$total  = 0;

function ec_assert( string $name, bool $cond, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $cond ) {
		echo "  [PASS] $name\n";
		return;
	}
	echo "  [FAIL] $name" . ( $detail ? " - $detail" : '' ) . "\n";
	++$failed;
}

/* ---------------------------------------------------------------------------
 * Minimal WP stubs.
 * -------------------------------------------------------------------------*/

$GLOBALS['ec_filters']         = array();
$GLOBALS['ec_actions']         = array();
$GLOBALS['ec_logs']            = array();
$GLOBALS['ec_wp_mail_calls']   = array();
$GLOBALS['ec_wp_mail_result']  = true;
$GLOBALS['ec_switch_history']  = array();
$GLOBALS['ec_current_blog']    = 1;
$GLOBALS['ec_known_blogs']     = array( 1, 2, 7 );
$GLOBALS['ec_is_multisite']    = true;
$GLOBALS['ec_scheduled']       = array();
$GLOBALS['ec_abilities']       = array();
$GLOBALS['ec_action_id_seq']   = 1000;
$GLOBALS['ec_manage_users']    = array( 1 => true );

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): bool {
    	$GLOBALS['ec_filters'][ $hook ][ $priority ][] = $cb;
    	return true;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( string $hook, $value, ...$args ) {
    	if ( empty( $GLOBALS['ec_filters'][ $hook ] ) ) {
    		return $value;
    	}
    	ksort( $GLOBALS['ec_filters'][ $hook ] );
    	foreach ( $GLOBALS['ec_filters'][ $hook ] as $callbacks ) {
    		foreach ( $callbacks as $cb ) {
    			$value = $cb( $value, ...$args );
    		}
    	}
    	return $value;
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): bool {
    	$GLOBALS['ec_actions'][ $hook ][] = $cb;
    	return true;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( string $hook, ...$args ): void {
    	if ( 'datamachine_log' === $hook ) {
    		$GLOBALS['ec_logs'][] = $args;
    		return;
    	}
    	foreach ( $GLOBALS['ec_actions'][ $hook ] ?? array() as $cb ) {
    		$cb( ...$args );
    	}
    }
}

function doing_action( string $hook ): bool {
	// Pretend we are inside wp_abilities_api_init so registration fires inline.
	return 'wp_abilities_api_init' === $hook;
}

function did_action( string $hook ): bool {
	return false;
}

function wp_register_ability( string $id, array $args ): bool {
	$GLOBALS['ec_abilities'][ $id ] = $args;
	return true;
}

function wp_get_ability( string $id ) {
	if ( ! isset( $GLOBALS['ec_abilities'][ $id ] ) ) {
		return null;
	}
	$args = $GLOBALS['ec_abilities'][ $id ];
	return new class( $args ) {
		private array $args;
		public function __construct( array $args ) {
			$this->args = $args;
		}
		public function execute( array $input ) {
			return call_user_func( $this->args['execute_callback'], $input );
		}
	};
}

if ( ! function_exists( 'is_email' ) ) {
    function is_email( $email ) {
    	if ( ! is_string( $email ) ) {
    		return false;
    	}
    	return preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email ) ? $email : false;
    }
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( int $user_id, string $capability ): bool {
		return ! empty( $GLOBALS['ec_manage_users'][ $user_id ] );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $key ) {
    	return 'Test Site';
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $key, $default_value = null ) {
    	if ( 'admin_email' === $key ) {
    		return 'admin@example.com';
    	}
    	if ( 'date_format' === $key ) {
    		return 'Y-m-d';
    	}
    	return $default_value;
    }
}

function wp_date( string $format, ?int $timestamp = null ): string {
	return gmdate( $format, $timestamp ?? time() );
}

if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $body, $headers = array(), $attachments = array() ): bool {
    	$GLOBALS['ec_wp_mail_calls'][] = array(
    		'to'          => $to,
    		'subject'     => $subject,
    		'body'        => $body,
    		'headers'     => $headers,
    		'attachments' => $attachments,
    		'blog'        => $GLOBALS['ec_current_blog'],
    	);
    	return (bool) $GLOBALS['ec_wp_mail_result'];
    }
}

if ( ! function_exists( 'is_multisite' ) ) {
    function is_multisite(): bool {
    	return (bool) $GLOBALS['ec_is_multisite'];
    }
}

if ( ! function_exists( 'get_blog_details' ) ) {
    function get_blog_details( $id ) {
    	return in_array( (int) $id, $GLOBALS['ec_known_blogs'], true ) ? (object) array( 'blog_id' => (int) $id ) : false;
    }
}

if ( ! function_exists( 'switch_to_blog' ) ) {
    function switch_to_blog( int $id ): bool {
    	$GLOBALS['ec_switch_history'][] = $id;
    	$GLOBALS['ec_current_blog']     = $id;
    	return true;
    }
}

if ( ! function_exists( 'restore_current_blog' ) ) {
    function restore_current_blog(): bool {
    	$GLOBALS['ec_current_blog'] = 1;
    	return true;
    }
}

function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
	$id                            = ++$GLOBALS['ec_action_id_seq'];
	$GLOBALS['ec_scheduled'][ $id ] = array( 'kind' => 'single', 'timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'group' => $group );
	return $id;
}

function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ): int {
	$id                            = ++$GLOBALS['ec_action_id_seq'];
	$GLOBALS['ec_scheduled'][ $id ] = array( 'kind' => 'async', 'timestamp' => time(), 'hook' => $hook, 'args' => $args, 'group' => $group );
	return $id;
}

if ( ! function_exists( '__' ) ) {
    function __( string $s, string $domain = '' ): string {
    	return $s;
    }
}

/* ---------------------------------------------------------------------------
 * Stub PermissionHelper + WP_Error.
 * -------------------------------------------------------------------------*/

if ( ! class_exists( '\\DataMachine\\Abilities\\PermissionHelper' ) ) {
	eval( 'namespace DataMachine\\Abilities; class PermissionHelper { public static bool $manage = true; public static int $user_id = 1; public static int $agent_id = 0; public static function can_manage(): bool { return self::$manage; } public static function can( string $action ): bool { return self::$manage; } public static function acting_user_id(): int { return self::$user_id; } public static function get_acting_agent_id(): ?int { return self::$agent_id ?: null; } }' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_code(): string {
			return $this->code;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ): bool {
    	return $thing instanceof WP_Error;
    }
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'send-email-smoke-' . $scheme;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return sprintf( '00000000-0000-4000-8000-%012d', ++$GLOBALS['ec_action_id_seq'] );
	}
}

/* ---------------------------------------------------------------------------
 * Load the abilities under test.
 * -------------------------------------------------------------------------*/

require_once __DIR__ . '/../inc/Abilities/AbilityRegistration.php';
require_once __DIR__ . '/../inc/Abilities/Publish/SendEmailAbility.php';
require_once __DIR__ . '/../inc/Abilities/Publish/SendEmailQueuedAbility.php';

new \DataMachine\Abilities\Publish\SendEmailAbility();
new \DataMachine\Abilities\Publish\SendEmailQueuedAbility();

ec_assert( 'send-email ability registered', isset( $GLOBALS['ec_abilities']['datamachine/send-email'] ) );
ec_assert( 'send-email-queued ability registered', isset( $GLOBALS['ec_abilities']['datamachine/send-email-queued'] ) );

$send = wp_get_ability( 'datamachine/send-email' );
ec_assert( 'send-email resolvable via wp_get_ability', null !== $send );

/* ---------------------------------------------------------------------------
 * Case 1 — backward compatible: raw `body`, no template, no mail_site_id.
 * -------------------------------------------------------------------------*/

echo "\nCase 1: backward-compat raw body\n";
$GLOBALS['ec_wp_mail_calls'] = array();
$GLOBALS['ec_switch_history'] = array();

$res = $send->execute( array(
	'to'      => 'user@example.com',
	'subject' => 'Hello {site_name}',
	'body'    => '<p>Body content for {year}</p>',
) );

ec_assert( 'raw body success', true === ( $res['success'] ?? false ), $res['error'] ?? '' );
ec_assert( 'raw body wp_mail called once', count( $GLOBALS['ec_wp_mail_calls'] ) === 1 );
ec_assert( 'raw body subject placeholders resolved', false !== strpos( $GLOBALS['ec_wp_mail_calls'][0]['subject'], 'Test Site' ) );
ec_assert( 'raw body content placeholders resolved', false !== strpos( $GLOBALS['ec_wp_mail_calls'][0]['body'], gmdate( 'Y' ) ) );
ec_assert( 'raw body no switch_to_blog', count( $GLOBALS['ec_switch_history'] ) === 0 );

echo "\nCase 1b: tool-only sender spoofing denied\n";
\DataMachine\Abilities\PermissionHelper::$manage  = false;
\DataMachine\Abilities\PermissionHelper::$user_id = 2;
$GLOBALS['ec_wp_mail_calls'] = array();
$res = $send->execute( array(
	'to'         => 'user@example.com',
	'subject'    => 'Spoof',
	'body'       => 'body',
	'from_email' => 'spoof@example.com',
	'reply_to'   => 'spoof@example.com',
) );
ec_assert( 'tool-only immediate send requires auth_ref', is_wp_error( $res ) && 'email_auth_ref_required' === $res->get_error_code() );
ec_assert( 'tool-only immediate spoof never calls wp_mail', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );
\DataMachine\Abilities\PermissionHelper::$manage  = true;
\DataMachine\Abilities\PermissionHelper::$user_id = 1;

/* ---------------------------------------------------------------------------
 * Case 2 — template render + placeholder replacement after template.
 * -------------------------------------------------------------------------*/

echo "\nCase 2: template render then placeholder replacement\n";
add_filter( 'datamachine_email_templates', function ( array $templates ): array {
	$templates['fake-digest'] = function ( array $context ): string {
		return '<h1>' . ( $context['title'] ?? 'untitled' ) . '</h1><p>Year: {year}</p>';
	};
	return $templates;
} );

$GLOBALS['ec_wp_mail_calls'] = array();
$res = $send->execute( array(
	'to'       => 'user@example.com',
	'subject'  => 'Subject',
	'template' => 'fake-digest',
	'context'  => array( 'title' => 'My Title' ),
) );

ec_assert( 'template render success', true === ( $res['success'] ?? false ), $res['error'] ?? '' );
ec_assert( 'template body contains rendered context', false !== strpos( $GLOBALS['ec_wp_mail_calls'][0]['body'] ?? '', 'My Title' ) );
ec_assert( 'placeholders applied after template render', false !== strpos( $GLOBALS['ec_wp_mail_calls'][0]['body'] ?? '', gmdate( 'Y' ) ) );

/* ---------------------------------------------------------------------------
 * Case 3 — unknown template returns structured error.
 * -------------------------------------------------------------------------*/

echo "\nCase 3: unknown template\n";
$res = $send->execute( array(
	'to'       => 'user@example.com',
	'subject'  => 'Subject',
	'template' => 'does-not-exist',
) );

ec_assert( 'unknown template fails', is_wp_error( $res ) );
ec_assert( 'unknown template error mentions id', is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'does-not-exist' ) );

/* ---------------------------------------------------------------------------
 * Case 4 — mail_site_id wraps wp_mail in switch_to_blog/restore.
 * -------------------------------------------------------------------------*/

echo "\nCase 4: mail_site_id switches blog around wp_mail only\n";
$GLOBALS['ec_wp_mail_calls'] = array();
$GLOBALS['ec_switch_history'] = array();

$res = $send->execute( array(
	'to'           => 'user@example.com',
	'subject'      => 'Subject',
	'body'         => 'body',
	'mail_site_id' => 7,
) );

ec_assert( 'mail_site_id success', true === ( $res['success'] ?? false ), $res['error'] ?? '' );
ec_assert( 'switch_to_blog called once with site 7', $GLOBALS['ec_switch_history'] === array( 7 ) );
ec_assert( 'wp_mail observed switched blog', ( $GLOBALS['ec_wp_mail_calls'][0]['blog'] ?? 0 ) === 7 );
ec_assert( 'restore_current_blog returned current to 1', $GLOBALS['ec_current_blog'] === 1 );

/* ---------------------------------------------------------------------------
 * Case 5 — invalid mail_site_id rejects with structured error and no switch.
 * -------------------------------------------------------------------------*/

echo "\nCase 5: invalid mail_site_id rejected\n";
$GLOBALS['ec_switch_history'] = array();
$res = $send->execute( array(
	'to'           => 'user@example.com',
	'subject'      => 'Subject',
	'body'         => 'body',
	'mail_site_id' => 999,
) );

ec_assert( 'invalid mail_site_id fails', is_wp_error( $res ) );
ec_assert( 'no switch_to_blog on invalid id', count( $GLOBALS['ec_switch_history'] ) === 0 );

/* ---------------------------------------------------------------------------
 * Case 6 — queued ability enqueues async when send_at omitted.
 * -------------------------------------------------------------------------*/

echo "\nCase 6: queued enqueue async\n";
$GLOBALS['ec_scheduled'] = array();
$queued = wp_get_ability( 'datamachine/send-email-queued' );

$res = $queued->execute( array(
	'to'      => 'user@example.com',
	'subject' => 'Subject',
	'body'    => 'body',
) );

ec_assert( 'queued async success', true === ( $res['success'] ?? false ), $res['error'] ?? '' );
ec_assert( 'queued async returned action_id > 0', ( $res['action_id'] ?? 0 ) > 0 );
$first = reset( $GLOBALS['ec_scheduled'] );
ec_assert( 'queued async used async action', ( $first['kind'] ?? '' ) === 'async' );
ec_assert( 'queued async hook is worker', ( $first['hook'] ?? '' ) === 'datamachine_send_email_worker' );
$legacy_payload = $first['args'][0] ?? array();

$GLOBALS['ec_manage_users'][1] = false;
$GLOBALS['ec_wp_mail_calls']    = array();
$worker_for_demotion = new \DataMachine\Abilities\Publish\SendEmailQueuedAbility();
$worker_for_demotion->runWorker( $legacy_payload );
ec_assert( 'legacy queued send denies issuer demoted after enqueue', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );
$GLOBALS['ec_manage_users'][1] = true;

\DataMachine\Abilities\PermissionHelper::$manage  = false;
\DataMachine\Abilities\PermissionHelper::$user_id = 2;
$GLOBALS['ec_scheduled'] = array();
$res = $queued->execute( array(
	'to'         => 'user@example.com',
	'subject'    => 'Spoof',
	'body'       => 'body',
	'from_email' => 'spoof@example.com',
	'reply_to'   => 'spoof@example.com',
) );
ec_assert( 'tool-only queued spoof requires auth_ref', false === ( $res['success'] ?? true ) );
ec_assert( 'tool-only queued spoof is not scheduled', 0 === count( $GLOBALS['ec_scheduled'] ) );
\DataMachine\Abilities\PermissionHelper::$manage  = true;
\DataMachine\Abilities\PermissionHelper::$user_id = 1;

/* ---------------------------------------------------------------------------
 * Case 7 — queued ability schedules single action when send_at is ISO 8601.
 * -------------------------------------------------------------------------*/

echo "\nCase 7: queued send_at parses ISO 8601\n";
$GLOBALS['ec_scheduled'] = array();
$future_iso = gmdate( 'c', time() + 3600 );
$res = $queued->execute( array(
	'to'      => 'user@example.com',
	'subject' => 'Subject',
	'body'    => 'body',
	'send_at' => $future_iso,
) );
ec_assert( 'queued ISO success', true === ( $res['success'] ?? false ), $res['error'] ?? '' );
$first = reset( $GLOBALS['ec_scheduled'] );
ec_assert( 'queued ISO used single action', ( $first['kind'] ?? '' ) === 'single' );
ec_assert( 'queued ISO timestamp roughly matches', abs( ( $first['timestamp'] ?? 0 ) - ( time() + 3600 ) ) < 5 );

/* ---------------------------------------------------------------------------
 * Case 8 — invalid send_at rejected.
 * -------------------------------------------------------------------------*/

echo "\nCase 8: queued invalid send_at rejected\n";
$res = $queued->execute( array(
	'to'      => 'user@example.com',
	'subject' => 'Subject',
	'body'    => 'body',
	'send_at' => 'not-a-date',
) );
ec_assert( 'invalid send_at fails', false === ( $res['success'] ?? true ) );

/* ---------------------------------------------------------------------------
 * Case 9 — worker invokes underlying ability and re-enqueues on failure
 *           up to MAX_ATTEMPTS, then gives up.
 * -------------------------------------------------------------------------*/

echo "\nCase 9: worker retry + give up\n";

// First call: wp_mail() will fail, worker should re-enqueue.
$GLOBALS['ec_wp_mail_result'] = false;
$GLOBALS['ec_scheduled'] = array();

$worker = new \DataMachine\Abilities\Publish\SendEmailQueuedAbility();
$legacy_payload['_attempt'] = 1;
$worker->runWorker( $legacy_payload );

ec_assert( 'worker scheduled a retry on first failure', count( $GLOBALS['ec_scheduled'] ) === 1 );
$retry = reset( $GLOBALS['ec_scheduled'] );
ec_assert( 'retry uses worker hook', ( $retry['hook'] ?? '' ) === 'datamachine_send_email_worker' );
$retry_payload = $retry['args'][0] ?? array();
ec_assert( 'retry payload increments _attempt', ( $retry_payload['_attempt'] ?? 0 ) === 2 );
ec_assert( 'retry scheduled ~5 min out', abs( ( $retry['timestamp'] ?? 0 ) - ( time() + 300 ) ) < 5 );

// Third attempt (max): should NOT re-enqueue.
$GLOBALS['ec_scheduled'] = array();
$legacy_payload['_attempt'] = 3;
$worker->runWorker( $legacy_payload );
ec_assert( 'worker gives up at MAX_ATTEMPTS', count( $GLOBALS['ec_scheduled'] ) === 0 );

// Restore wp_mail success and verify worker succeeds and does NOT re-enqueue.
$GLOBALS['ec_wp_mail_result'] = true;
$GLOBALS['ec_scheduled']      = array();
$GLOBALS['ec_wp_mail_calls']  = array();
$legacy_payload['_attempt'] = 1;
$worker->runWorker( $legacy_payload );
ec_assert( 'worker success: no retry', count( $GLOBALS['ec_scheduled'] ) === 0 );
ec_assert( 'worker success: wp_mail invoked', count( $GLOBALS['ec_wp_mail_calls'] ) === 1 );
ec_assert( 'worker strips _attempt before forwarding', ! isset( $GLOBALS['ec_wp_mail_calls'][0]['_attempt'] ) );

$GLOBALS['ec_wp_mail_calls'] = array();
$worker->runWorker( array( 'to' => 'user@example.com', 'subject' => 'Ambient bypass', 'body' => 'body' ) );
ec_assert( 'worker denies missing signed grant despite ambient scheduler access', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );

$tampered = $legacy_payload;
$tampered['from_email'] = 'tampered@example.com';
$GLOBALS['ec_wp_mail_calls'] = array();
$worker->runWorker( $tampered );
ec_assert( 'worker denies tampered sender payload', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );

$tampered = $legacy_payload;
$tampered['to']   = 'redirected@example.com';
$tampered['body'] = 'replacement body';
$GLOBALS['ec_wp_mail_calls'] = array();
$worker->runWorker( $tampered );
ec_assert( 'worker denies tampered recipient and body payload', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );

$GLOBALS['ec_mailbox_revoked'] = false;
$fake_mailbox_auth = new class() {
	public function resolve_mailbox( string $ref, string $operation ): array|WP_Error {
		return $this->resolve( $ref );
	}
	public function resolve_mailbox_for_principal( string $ref, string $operation, array $context ): array|WP_Error {
		return $this->resolve( $ref );
	}
	private function resolve( string $ref ): array|WP_Error {
		if ( $GLOBALS['ec_mailbox_revoked'] ) {
			return new WP_Error( 'email_mailbox_forbidden', 'Mailbox grant revoked.' );
		}
		return array(
			'ref'         => $ref,
			'credentials' => array( 'imap_user' => 'authorized@example.com' ),
		);
	}
};
add_filter( 'datamachine_auth_providers', static function ( array $providers ) use ( $fake_mailbox_auth ): array {
	$providers['email_imap'] = $fake_mailbox_auth;
	return $providers;
} );
\DataMachine\Abilities\PermissionHelper::$manage   = false;
\DataMachine\Abilities\PermissionHelper::$user_id  = 42;
\DataMachine\Abilities\PermissionHelper::$agent_id = 303;
$GLOBALS['ec_scheduled'] = array();
$queued->execute( array(
	'auth_ref' => 'email_imap:delegated',
	'to'       => 'user@example.com',
	'subject'  => 'Revocation',
	'body'     => 'body',
) );
$scheduled = reset( $GLOBALS['ec_scheduled'] );
$revoked_payload = $scheduled['args'][0] ?? array();
$GLOBALS['ec_mailbox_revoked'] = true;
$GLOBALS['ec_wp_mail_calls']    = array();
$worker->runWorker( $revoked_payload );
ec_assert( 'worker rechecks and denies revoked mailbox grant', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );
\DataMachine\Abilities\PermissionHelper::$manage   = true;
\DataMachine\Abilities\PermissionHelper::$user_id  = 1;
\DataMachine\Abilities\PermissionHelper::$agent_id = 0;

/* ---------------------------------------------------------------------------
 * Summary
 * -------------------------------------------------------------------------*/

echo "\n";
if ( $failed > 0 ) {
	echo "FAILED: $failed / $total\n";
	exit( 1 );
}
echo "OK: $total assertions passed\n";
