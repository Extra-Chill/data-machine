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
$GLOBALS['ec_users']           = array( 1 => true, 42 => true );
$GLOBALS['ec_auth_salt']       = 'send-email-smoke-auth';
$GLOBALS['ec_options']         = array();
$GLOBALS['ec_option_autoload'] = array();
$GLOBALS['ec_schedule_result'] = true;
$GLOBALS['ec_schedule_throw']  = false;
$GLOBALS['ec_options_at_schedule'] = array();
$GLOBALS['ec_schedule_fail_hook'] = '';
$GLOBALS['ec_wp_mail_callback'] = null;

class EC_Email_Lock_Wpdb {
	public array $locks = array();
	public array $timeouts = array();

	public function prepare( string $query, ...$args ): array {
		return array( $query, $args );
	}

	public function get_var( array $prepared ): string {
		list( $query, $args ) = $prepared;
		$lock_name = (string) ( $args[0] ?? '' );
		if ( str_contains( $query, 'GET_LOCK' ) ) {
			$this->timeouts[] = (int) ( $args[1] ?? 0 );
			if ( isset( $this->locks[ $lock_name ] ) ) {
				return '0';
			}
			$this->locks[ $lock_name ] = true;
			return '1';
		}
		unset( $this->locks[ $lock_name ] );
		return '1';
	}
}

$GLOBALS['wpdb'] = new EC_Email_Lock_Wpdb();

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

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, int $user_id ) {
		return ! empty( $GLOBALS['ec_users'][ $user_id ] ) ? (object) array( 'ID' => $user_id ) : false;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $key ) {
    	return 'Test Site';
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $key, $default_value = null ) {
		if ( array_key_exists( $key, $GLOBALS['ec_options'] ) ) {
			return $GLOBALS['ec_options'][ $key ];
		}
		if ( 'admin_email' === $key ) {
    		return 'admin@example.com';
    	}
    	if ( 'date_format' === $key ) {
    		return 'Y-m-d';
    	}
    	return $default_value;
    }
}

function add_option( string $key, $value, string $deprecated = '', bool $autoload = true ): bool {
	if ( array_key_exists( $key, $GLOBALS['ec_options'] ) ) {
		return false;
	}
	$GLOBALS['ec_options'][ $key ]         = $value;
	$GLOBALS['ec_option_autoload'][ $key ] = $autoload;
	return true;
}

function update_option( string $key, $value, bool $autoload = true ): bool {
	if ( ! array_key_exists( $key, $GLOBALS['ec_options'] ) || $GLOBALS['ec_options'][ $key ] === $value ) {
		return false;
	}
	$GLOBALS['ec_options'][ $key ]         = $value;
	$GLOBALS['ec_option_autoload'][ $key ] = $autoload;
	return true;
}

function delete_option( string $key ): bool {
	if ( ! array_key_exists( $key, $GLOBALS['ec_options'] ) ) {
		return false;
	}
	unset( $GLOBALS['ec_options'][ $key ], $GLOBALS['ec_option_autoload'][ $key ] );
	return true;
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
		if ( is_callable( $GLOBALS['ec_wp_mail_callback'] ) ) {
			$callback = $GLOBALS['ec_wp_mail_callback'];
			$GLOBALS['ec_wp_mail_callback'] = null;
			$callback();
		}
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

function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int|false {
	if ( $GLOBALS['ec_schedule_throw'] ) {
		throw new RuntimeException( 'scheduler unavailable' );
	}
	if ( ! $GLOBALS['ec_schedule_result'] ) {
		return false;
	}
	if ( $hook === $GLOBALS['ec_schedule_fail_hook'] ) {
		return false;
	}
	$GLOBALS['ec_options_at_schedule'][] = count( $GLOBALS['ec_options'] );
	$id                            = ++$GLOBALS['ec_action_id_seq'];
	$GLOBALS['ec_scheduled'][ $id ] = array( 'kind' => 'single', 'timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'group' => $group );
	return $id;
}

function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ): int|false {
	if ( $GLOBALS['ec_schedule_throw'] ) {
		throw new RuntimeException( 'scheduler unavailable' );
	}
	if ( ! $GLOBALS['ec_schedule_result'] ) {
		return false;
	}
	if ( $hook === $GLOBALS['ec_schedule_fail_hook'] ) {
		return false;
	}
	$GLOBALS['ec_options_at_schedule'][] = count( $GLOBALS['ec_options'] );
	$id                            = ++$GLOBALS['ec_action_id_seq'];
	$GLOBALS['ec_scheduled'][ $id ] = array( 'kind' => 'async', 'timestamp' => time(), 'hook' => $hook, 'args' => $args, 'group' => $group );
	return $id;
}

function ec_scheduled_for_hook( string $hook ): array {
	return array_values( array_filter( $GLOBALS['ec_scheduled'], static fn( array $action ): bool => $hook === $action['hook'] ) );
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
	eval( 'namespace DataMachine\\Abilities; class PermissionHelper { public static bool $manage = true; public static int $user_id = 1; public static int $agent_id = 0; public static int $token_id = 0; public static function can_manage(): bool { return self::$manage; } public static function can( string $action ): bool { return self::$manage; } public static function acting_user_id(): int { return self::$user_id; } public static function get_acting_agent_id(): ?int { return self::$agent_id ?: null; } public static function get_acting_token_id(): ?int { return self::$token_id ?: null; } public static function is_authenticated_context(): bool { return false; } }' );
}

if ( ! class_exists( 'WP_Agent_Token' ) ) {
	class WP_Agent_Token {
		public function __construct( public int $token_id, public string $agent_id, public int $owner_user_id, public ?array $allowed_capabilities = null, public array $metadata = array(), public bool $expired = false ) {}
		public function is_expired(): bool { return $this->expired; }
	}
}

if ( ! class_exists( '\\DataMachine\\Core\\Database\\Agents\\Agents' ) ) {
	eval( 'namespace DataMachine\\Core\\Database\\Agents; class Agents { public static array $rows = array(); public function get_agent(int $agent_id): ?array { return self::$rows[$agent_id] ?? null; } } class AgentTokens { public static array $tokens = array(); public function get_token(int $token_id): ?\\WP_Agent_Token { return self::$tokens[$token_id] ?? null; } public static function normalize_capability_payload(?array $payload): array { if (null === $payload) { return array("allowed_capabilities" => null, "stored_payload" => null); } $is_list = array_is_list($payload); return array("allowed_capabilities" => $is_list ? $payload : (array) ($payload["capabilities"] ?? array()), "stored_payload" => $payload); } }' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private array $data;
		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_data(): array {
			return $this->data;
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
		return 'auth' === $scheme ? $GLOBALS['ec_auth_salt'] : 'send-email-smoke-' . $scheme;
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
$queued_definition = $GLOBALS['ec_abilities']['datamachine/send-email-queued'];
$expected_queue_inputs = array( 'auth_ref', 'to', 'cc', 'bcc', 'subject', 'body', 'template', 'context', 'mail_site_id', 'content_type', 'from_name', 'from_email', 'reply_to', 'attachments', 'send_at', 'priority' );
ec_assert( 'queued input contract remains unchanged', array( 'to', 'subject' ) === $queued_definition['input_schema']['required'] && $expected_queue_inputs === array_keys( $queued_definition['input_schema']['properties'] ) );
ec_assert( 'queued output contract remains unchanged', array( 'success', 'action_id', 'scheduled_for', 'error', 'logs' ) === array_keys( $queued_definition['output_schema']['properties'] ) );

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

\DataMachine\Abilities\PermissionHelper::$user_id = 0;
$GLOBALS['ec_scheduled'] = array();
$res = $queued->execute( array( 'to' => 'user@example.com', 'subject' => 'No issuer', 'body' => 'body' ) );
ec_assert( 'queued email rejects principal-less ambient execution', is_wp_error( $res ) && 0 === count( $GLOBALS['ec_scheduled'] ) );

$system_input = array( 'to' => 'user@example.com', 'subject' => 'System issuer', 'body' => 'body' );
$grant_method = new ReflectionMethod( \DataMachine\Abilities\Publish\SendEmailQueuedAbility::class, 'createMailboxGrant' );
$grant_method->setAccessible( true );
$system_input['_mailbox_grant'] = $grant_method->invoke(
	new \DataMachine\Abilities\Publish\SendEmailQueuedAbility(),
	$system_input,
	array( 'user_id' => 0, 'agent_id' => 0, 'token_id' => 0, 'system' => true )
);
$system_input['_attempt'] = 1;
ec_assert( 'system grant records no user identity', 'system' === $system_input['_mailbox_grant']['issuer_type'] && 0 === $system_input['_mailbox_grant']['user_id'] );
$GLOBALS['ec_wp_mail_calls'] = array();
$system_worker = new \DataMachine\Abilities\Publish\SendEmailQueuedAbility();
$system_worker->runWorker( $system_input );
ec_assert( 'worker accepts signed system issuer grant', 1 === count( $GLOBALS['ec_wp_mail_calls'] ) );
$GLOBALS['ec_scheduled'] = array();

\DataMachine\Abilities\PermissionHelper::$user_id = 1;

$large_body = 'body secret recovery-code-123 ' . str_repeat( 'large-private-email-content-', 240 );
$GLOBALS['ec_options']         = array();
$GLOBALS['ec_option_autoload'] = array();
$res = $queued->execute( array(
	'to'          => 'user@example.com',
	'cc'          => 'private-cc@example.com',
	'bcc'         => 'private-bcc@example.com',
	'subject'     => 'Subject',
	'body'        => $large_body,
	'context'     => array( 'authorization' => 'Bearer secret-auth-material' ),
	'from_name'   => 'Private Sender',
	'from_email'  => 'private-sender@example.com',
	'reply_to'    => 'private-reply@example.com',
	'attachments' => array( '/private/mailbox-grant-file.pdf' ),
) );

ec_assert( 'queued async success', true === ( $res['success'] ?? false ), $res['error'] ?? '' );
ec_assert( 'queued async returned action_id > 0', ( $res['action_id'] ?? 0 ) > 0 );
$first = reset( $GLOBALS['ec_scheduled'] );
ec_assert( 'queued async used async action', ( $first['kind'] ?? '' ) === 'async' );
ec_assert( 'queued async hook is worker', ( $first['hook'] ?? '' ) === 'datamachine_send_email_worker' );
$cleanup_actions = ec_scheduled_for_hook( \DataMachine\Abilities\Publish\SendEmailQueuedAbility::CLEANUP_HOOK );
ec_assert( 'queue schedules one generic orphan cleanup action', 1 === count( $cleanup_actions ) );
$cleanup_action = $cleanup_actions[0] ?? array();
ec_assert( 'cleanup action uses compact reference without sensitive args', ( $cleanup_action['args'][0] ?? null ) === ( $first['args'][0] ?? null ) && strlen( serialize( $cleanup_action['args'] ?? array() ) ) < 8000 && ! str_contains( serialize( $cleanup_action['args'] ?? array() ), 'user@example.com' ) && ! str_contains( serialize( $cleanup_action['args'] ?? array() ), 'recovery-code-123' ) );
ec_assert( 'cleanup deadline exceeds delayed send and retry horizon', ( $cleanup_action['timestamp'] ?? 0 ) > ( $first['timestamp'] ?? 0 ) + 600 );
$queued_reference = $first['args'][0] ?? array();
$raw_args          = serialize( $first['args'] );
$json_args         = json_encode( $first['args'] );
foreach ( array( 'user@example.com', 'private-cc@example.com', 'private-bcc@example.com', 'Subject', 'recovery-code-123', 'Bearer secret-auth-material', 'Private Sender', 'private-sender@example.com', 'private-reply@example.com', '/private/mailbox-grant-file.pdf', '_mailbox_grant', 'auth_ref' ) as $sensitive_value ) {
	ec_assert( 'raw scheduler args omit plaintext ' . $sensitive_value, ! str_contains( $raw_args, $sensitive_value ) );
}
ec_assert( 'raw scheduler args contain compact payload reference only', array( '_datamachine_email_payload' ) === array_keys( $queued_reference ) && ! str_contains( $raw_args, 'ciphertext' ) );
ec_assert( 'serialized and JSON scheduler args remain below Action Scheduler limit', strlen( $raw_args ) < 8000 && is_string( $json_args ) && strlen( $json_args ) < 8000, sprintf( 'serialize=%d json=%d', strlen( $raw_args ), is_string( $json_args ) ? strlen( $json_args ) : -1 ) );
$reference_id = $queued_reference['_datamachine_email_payload']['id'] ?? '';
$option_name  = 'datamachine_email_payload_' . $reference_id;
$stored_envelope = $GLOBALS['ec_options'][ $option_name ] ?? null;
ec_assert( 'encrypted payload stored in uniquely keyed option', is_array( $stored_envelope ) && 1 === count( $GLOBALS['ec_options'] ) );
ec_assert( 'encrypted payload option is non-autoloaded', false === ( $GLOBALS['ec_option_autoload'][ $option_name ] ?? null ) );
ec_assert( 'large encrypted option may exceed scheduler args ceiling', strlen( serialize( $stored_envelope ) ) > 8000, (string) strlen( serialize( $stored_envelope ) ) );
ec_assert( 'stored option contains no plaintext', ! str_contains( serialize( $stored_envelope ), 'user@example.com' ) && ! str_contains( serialize( $stored_envelope ), 'recovery-code-123' ) && ! str_contains( serialize( $stored_envelope ), 'Bearer secret-auth-material' ) );
$decrypt_method = new ReflectionMethod( \DataMachine\Abilities\Publish\SendEmailQueuedAbility::class, 'decryptQueuedPayload' );
$decrypt_method->setAccessible( true );
$decrypted_payload = $decrypt_method->invoke( new \DataMachine\Abilities\Publish\SendEmailQueuedAbility(), $stored_envelope, $reference_id );
ec_assert( 'encrypted queue payload decrypts exactly for delivery', 'user@example.com' === ( $decrypted_payload['to'] ?? '' ) && 'Subject' === ( $decrypted_payload['subject'] ?? '' ) && $large_body === ( $decrypted_payload['body'] ?? '' ) && 'private-reply@example.com' === ( $decrypted_payload['reply_to'] ?? '' ) && 'Bearer secret-auth-material' === ( $decrypted_payload['context']['authorization'] ?? '' ) );
$legacy_payload = $decrypted_payload;

$GLOBALS['ec_wp_mail_calls'] = array();
$GLOBALS['ec_logs']          = array();
$worker_for_delivery = new \DataMachine\Abilities\Publish\SendEmailQueuedAbility();
$GLOBALS['ec_wp_mail_callback'] = static function () use ( $worker_for_delivery, $queued_reference ): void {
	$worker_for_delivery->runWorker( $queued_reference );
};
$worker_for_delivery->runWorker( $queued_reference );
ec_assert( 'stored payload delivers exact large body', 1 === count( $GLOBALS['ec_wp_mail_calls'] ) && $large_body === $GLOBALS['ec_wp_mail_calls'][0]['body'] );
ec_assert( 'concurrent duplicate is serialized with bounded lock wait', 1 === count( $GLOBALS['ec_wp_mail_calls'] ) && 5 === max( $GLOBALS['wpdb']->timeouts ) );
ec_assert( 'serialized duplicate diagnostics omit plaintext', ! str_contains( serialize( $GLOBALS['ec_logs'] ), 'user@example.com' ) && ! str_contains( serialize( $GLOBALS['ec_logs'] ), 'recovery-code-123' ) );
ec_assert( 'successful worker deletes stored envelope', ! isset( $GLOBALS['ec_options'][ $option_name ] ) );
$worker_for_delivery->runCleanup( $cleanup_action['args'][0] ?? array() );
ec_assert( 'scheduled cleanup after immediate success is an idempotent no-op', 1 === count( $GLOBALS['ec_wp_mail_calls'] ) && ! isset( $GLOBALS['ec_options'][ $option_name ] ) );
$worker_for_delivery->runWorker( $queued_reference );
ec_assert( 'duplicate worker after cleanup does not resend', 1 === count( $GLOBALS['ec_wp_mail_calls'] ) );

$GLOBALS['ec_scheduled'] = array();
$queued->execute( array( 'to' => 'user@example.com', 'subject' => 'Demotion', 'body' => 'private demotion body' ) );
$demotion_action    = reset( $GLOBALS['ec_scheduled'] );
$demotion_reference = $demotion_action['args'][0] ?? array();
$demotion_option    = 'datamachine_email_payload_' . ( $demotion_reference['_datamachine_email_payload']['id'] ?? '' );
$GLOBALS['ec_manage_users'][1] = false;
$GLOBALS['ec_wp_mail_calls']    = array();
$worker_for_demotion = new \DataMachine\Abilities\Publish\SendEmailQueuedAbility();
$worker_for_demotion->runWorker( $demotion_reference );
ec_assert( 'stored queued send denies issuer demoted after enqueue', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );
ec_assert( 'authorization denial deletes stored envelope', ! isset( $GLOBALS['ec_options'][ $demotion_option ] ) );
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
ec_assert( 'tool-only queued spoof requires auth_ref', is_wp_error( $res ) );
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
$worker_for_delivery->runWorker( $first['args'][0] ?? array() );

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
ec_assert( 'invalid send_at fails', is_wp_error( $res ) );

/* ---------------------------------------------------------------------------
 * Case 9 — worker invokes underlying ability and re-enqueues on failure
 *           up to MAX_ATTEMPTS, then gives up.
 * -------------------------------------------------------------------------*/

echo "\nCase 9: worker retry + give up\n";

// First call: wp_mail() will fail, worker should re-enqueue.
$GLOBALS['ec_wp_mail_result'] = false;
$GLOBALS['ec_scheduled'] = array();
$GLOBALS['ec_logs']      = array();
$GLOBALS['ec_options']   = array();
$GLOBALS['ec_option_autoload'] = array();

$worker = new \DataMachine\Abilities\Publish\SendEmailQueuedAbility();
$legacy_payload['_attempt'] = 1;
$worker->runWorker( $legacy_payload );

ec_assert( 'worker scheduled retry and bounded cleanup on first legacy failure', 1 === count( ec_scheduled_for_hook( 'datamachine_send_email_worker' ) ) && 1 === count( ec_scheduled_for_hook( \DataMachine\Abilities\Publish\SendEmailQueuedAbility::CLEANUP_HOOK ) ) );
$retry_actions = ec_scheduled_for_hook( 'datamachine_send_email_worker' );
$retry = $retry_actions[0] ?? array();
ec_assert( 'retry uses worker hook', ( $retry['hook'] ?? '' ) === 'datamachine_send_email_worker' );
$retry_reference = $retry['args'][0] ?? array();
$retry_raw       = serialize( $retry['args'] );
ec_assert( 'legacy retry scheduler args contain compact reference only', array( '_datamachine_email_payload' ) === array_keys( $retry_reference ) && strlen( $retry_raw ) < 8000 );
ec_assert( 'retry scheduler args omit recipient, subject, body, and auth material', ! str_contains( $retry_raw, 'user@example.com' ) && ! str_contains( $retry_raw, 'Subject' ) && ! str_contains( $retry_raw, 'recovery-code-123' ) && ! str_contains( $retry_raw, 'Bearer secret-auth-material' ) );
ec_assert( 'retry failure log omits queued plaintext', ! str_contains( serialize( $GLOBALS['ec_logs'] ), 'user@example.com' ) && ! str_contains( serialize( $GLOBALS['ec_logs'] ), 'Subject' ) && ! str_contains( serialize( $GLOBALS['ec_logs'] ), 'recovery-code-123' ) && ! str_contains( serialize( $GLOBALS['ec_logs'] ), 'Bearer secret-auth-material' ) );
$retry_reference_id = $retry_reference['_datamachine_email_payload']['id'] ?? '';
$retry_option_name  = 'datamachine_email_payload_' . $retry_reference_id;
$retry_payload = $decrypt_method->invoke( $worker, $GLOBALS['ec_options'][ $retry_option_name ] ?? array(), $retry_reference_id );
ec_assert( 'encrypted retry payload increments _attempt', ( $retry_payload['_attempt'] ?? 0 ) === 2 );
ec_assert( 'retry keeps encrypted option durable before scheduling', isset( $GLOBALS['ec_options'][ $retry_option_name ] ) && 0 < end( $GLOBALS['ec_options_at_schedule'] ) );
ec_assert( 'retry scheduled ~5 min out', abs( ( $retry['timestamp'] ?? 0 ) - ( time() + 300 ) ) < 5 );

// Second failure updates the same encrypted option and schedules attempt 3.
$GLOBALS['ec_scheduled'] = array();
$worker->runWorker( $retry_reference );
$second_retry = reset( $GLOBALS['ec_scheduled'] );
$second_retry_reference = $second_retry['args'][0] ?? array();
ec_assert( 'stored retry reuses compact authenticated reference', $retry_reference === $second_retry_reference );
$third_payload = $decrypt_method->invoke( $worker, $GLOBALS['ec_options'][ $retry_option_name ] ?? array(), $retry_reference_id );
ec_assert( 'stored retry persists incremented third attempt encrypted', 3 === ( $third_payload['_attempt'] ?? 0 ) );

// Third attempt (max): should not re-enqueue and must clean up storage.
$GLOBALS['ec_scheduled'] = array();
$worker->runWorker( $second_retry_reference );
ec_assert( 'worker gives up at MAX_ATTEMPTS', count( $GLOBALS['ec_scheduled'] ) === 0 );
ec_assert( 'terminal send failure deletes stored envelope', ! isset( $GLOBALS['ec_options'][ $retry_option_name ] ) );

// Restore wp_mail success and verify worker succeeds and does NOT re-enqueue.
$GLOBALS['ec_wp_mail_result'] = true;
$GLOBALS['ec_scheduled']      = array();
$GLOBALS['ec_wp_mail_calls']  = array();
$legacy_payload['_attempt'] = 1;
$worker->runWorker( $legacy_payload );
ec_assert( 'worker success: no retry', count( $GLOBALS['ec_scheduled'] ) === 0 );
ec_assert( 'worker success: wp_mail invoked', count( $GLOBALS['ec_wp_mail_calls'] ) === 1 );
ec_assert( 'worker strips _attempt before forwarding', ! isset( $GLOBALS['ec_wp_mail_calls'][0]['_attempt'] ) );
ec_assert( 'already-persisted legacy plaintext payload remains consumable', array( 'user@example.com' ) === $GLOBALS['ec_wp_mail_calls'][0]['to'] && 'Subject' === $GLOBALS['ec_wp_mail_calls'][0]['subject'] && $large_body === $GLOBALS['ec_wp_mail_calls'][0]['body'] );

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

echo "\nCase 9b: durable storage failures are closed and cleaned up\n";
$queue_fixture = static function () use ( $queued, $large_body ): array {
	$GLOBALS['ec_scheduled'] = array();
	$result = $queued->execute( array( 'to' => 'user@example.com', 'subject' => 'Subject', 'body' => $large_body ) );
	$action = reset( $GLOBALS['ec_scheduled'] );
	$reference = $action['args'][0] ?? array();
	$option_name = 'datamachine_email_payload_' . ( $reference['_datamachine_email_payload']['id'] ?? '' );
	return array( $result, $reference, $option_name );
};

$GLOBALS['ec_options']         = array();
$GLOBALS['ec_option_autoload'] = array();
$GLOBALS['ec_schedule_fail_hook'] = \DataMachine\Abilities\Publish\SendEmailQueuedAbility::CLEANUP_HOOK;
list( $cleanup_failure_result, $cleanup_failure_reference, $cleanup_failure_option ) = $queue_fixture();
$cleanup_failure_logs = serialize( is_array( $cleanup_failure_result ) ? ( $cleanup_failure_result['logs'] ?? array() ) : array() );
ec_assert( 'cleanup scheduling failure preserves runnable queued payload', is_array( $cleanup_failure_result ) && isset( $GLOBALS['ec_options'][ $cleanup_failure_option ] ) && 1 === count( ec_scheduled_for_hook( 'datamachine_send_email_worker' ) ) );
ec_assert( 'cleanup scheduling failure logs no plaintext', ! str_contains( $cleanup_failure_logs, 'user@example.com' ) && ! str_contains( $cleanup_failure_logs, 'recovery-code-123' ) );
$GLOBALS['ec_schedule_fail_hook'] = '';
$GLOBALS['ec_wp_mail_calls'] = array();
$worker->runWorker( $cleanup_failure_reference );
ec_assert( 'payload still delivers after cleanup scheduling failure', 1 === count( $GLOBALS['ec_wp_mail_calls'] ) && ! isset( $GLOBALS['ec_options'][ $cleanup_failure_option ] ) );

$GLOBALS['ec_schedule_result'] = false;
$failed_initial = $queued->execute( array( 'to' => 'user@example.com', 'subject' => 'Initial failure', 'body' => $large_body ) );
ec_assert( 'initial scheduler failure returns error and cleans storage', is_wp_error( $failed_initial ) && array() === $GLOBALS['ec_options'] );
$GLOBALS['ec_schedule_result'] = true;
$GLOBALS['ec_schedule_throw']  = true;
$thrown_initial = $queued->execute( array( 'to' => 'user@example.com', 'subject' => 'Initial exception', 'body' => $large_body ) );
ec_assert( 'initial scheduler exception returns error and cleans storage', is_wp_error( $thrown_initial ) && array() === $GLOBALS['ec_options'] );
$GLOBALS['ec_schedule_throw'] = false;

$GLOBALS['ec_wp_mail_result']  = false;
$GLOBALS['ec_schedule_result'] = false;
$worker->runWorker( $legacy_payload );
ec_assert( 'retry scheduler failure cleans newly stored envelope', array() === $GLOBALS['ec_options'] );
$GLOBALS['ec_schedule_result'] = true;
$GLOBALS['ec_wp_mail_result']  = true;

list( , $missing_reference, $missing_option ) = $queue_fixture();
delete_option( $missing_option );
$GLOBALS['ec_wp_mail_calls'] = array();
$worker->runWorker( $missing_reference );
ec_assert( 'missing stored envelope fails closed', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );

list( , $orphan_reference, $orphan_option ) = $queue_fixture();
$orphan_cleanup_actions = ec_scheduled_for_hook( \DataMachine\Abilities\Publish\SendEmailQueuedAbility::CLEANUP_HOOK );
$orphan_cleanup = $orphan_cleanup_actions[0] ?? array();
$worker->runCleanup( $orphan_cleanup['args'][0] ?? array() );
$GLOBALS['ec_wp_mail_calls'] = array();
$worker->runWorker( $orphan_reference );
ec_assert( 'retention cleanup deletes orphan and later worker does not send', ! isset( $GLOBALS['ec_options'][ $orphan_option ] ) && 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );

list( , $crash_reference, $crash_option ) = $queue_fixture();
$GLOBALS['ec_wp_mail_calls'] = array();
$GLOBALS['ec_wp_mail_callback'] = static function (): void {
	throw new RuntimeException( 'simulated crash after provider acceptance' );
};
try {
	$worker->runWorker( $crash_reference );
} catch ( RuntimeException $exception ) {
	// The provider accepted the first attempt, but local cleanup did not run.
}
ec_assert( 'post-acceptance crash retains payload and releases dispatch lock', 1 === count( $GLOBALS['ec_wp_mail_calls'] ) && isset( $GLOBALS['ec_options'][ $crash_option ] ) && array() === $GLOBALS['wpdb']->locks );
$worker->runWorker( $crash_reference );
ec_assert( 'at-least-once replay may send twice after post-acceptance crash', 2 === count( $GLOBALS['ec_wp_mail_calls'] ) && ! isset( $GLOBALS['ec_options'][ $crash_option ] ) );

list( , $tampered_reference, $tampered_option ) = $queue_fixture();
$GLOBALS['ec_options'][ $tampered_option ]['_datamachine_encrypted_email']['ciphertext'][0] = 'A' === $GLOBALS['ec_options'][ $tampered_option ]['_datamachine_encrypted_email']['ciphertext'][0] ? 'B' : 'A';
$GLOBALS['ec_logs']          = array();
$GLOBALS['ec_wp_mail_calls'] = array();
$worker->runWorker( $tampered_reference );
$tampered_logs = serialize( $GLOBALS['ec_logs'] );
ec_assert( 'tampered stored envelope fails closed and is deleted', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) && ! isset( $GLOBALS['ec_options'][ $tampered_option ] ) );
ec_assert( 'tampered storage logs omit plaintext', ! str_contains( $tampered_logs, 'user@example.com' ) && ! str_contains( $tampered_logs, 'recovery-code-123' ) );

list( , $malformed_reference, $malformed_option ) = $queue_fixture();
$GLOBALS['ec_options'][ $malformed_option ]['_datamachine_encrypted_email']['version'] = 2;
$GLOBALS['ec_wp_mail_calls'] = array();
$worker->runWorker( $malformed_reference );
ec_assert( 'unsupported stored envelope fails closed and is deleted', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) && ! isset( $GLOBALS['ec_options'][ $malformed_option ] ) );

list( , $invalid_reference, $invalid_option ) = $queue_fixture();
$invalid_reference['_datamachine_email_payload']['mac'][0] = 'a' === $invalid_reference['_datamachine_email_payload']['mac'][0] ? 'b' : 'a';
$GLOBALS['ec_wp_mail_calls'] = array();
$worker->runWorker( $invalid_reference );
ec_assert( 'tampered reference fails closed and cleans selected storage', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) && ! isset( $GLOBALS['ec_options'][ $invalid_option ] ) );

list( , $rotation_reference, $rotation_option ) = $queue_fixture();
$GLOBALS['ec_logs']          = array();
$GLOBALS['ec_wp_mail_calls'] = array();
$GLOBALS['ec_auth_salt']     = 'rotated-send-email-smoke-auth';
$worker->runWorker( $rotation_reference );
$rotation_logs = serialize( $GLOBALS['ec_logs'] );
ec_assert( 'salt rotation fails closed and cleans stored envelope', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) && ! isset( $GLOBALS['ec_options'][ $rotation_option ] ) );
ec_assert( 'salt rotation diagnostic omits queued plaintext', ! str_contains( $rotation_logs, 'user@example.com' ) && ! str_contains( $rotation_logs, 'Subject' ) && ! str_contains( $rotation_logs, 'recovery-code-123' ) );
$GLOBALS['ec_auth_salt'] = 'send-email-smoke-auth';

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
$GLOBALS['ec_manage_users'][42] = true;
\DataMachine\Core\Database\Agents\Agents::$rows[303] = array( 'owner_id' => 42 );
$GLOBALS['ec_scheduled'] = array();
$queued->execute( array(
	'auth_ref' => 'email_imap:delegated',
	'to'       => 'user@example.com',
	'subject'  => 'Revocation',
	'body'     => 'body',
) );
$scheduled = reset( $GLOBALS['ec_scheduled'] );
$revoked_payload = $scheduled['args'][0] ?? array();
$named_raw = serialize( $scheduled['args'] );
ec_assert( 'named mailbox scheduler args omit auth ref and grant', ! str_contains( $named_raw, 'email_imap:delegated' ) && ! str_contains( $named_raw, '_mailbox_grant' ) && ! str_contains( $named_raw, 'user@example.com' ) );
$GLOBALS['ec_mailbox_revoked'] = true;
$GLOBALS['ec_wp_mail_calls']    = array();
$GLOBALS['ec_scheduled']        = array();
$worker->runWorker( $revoked_payload );
ec_assert( 'worker rechecks and denies revoked mailbox grant', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );
$revoked_retry = reset( $GLOBALS['ec_scheduled'] );
$GLOBALS['ec_scheduled'] = array();
$worker->runWorker( $revoked_retry['args'][0] ?? array() );
$revoked_final = reset( $GLOBALS['ec_scheduled'] );
$GLOBALS['ec_scheduled'] = array();
$worker->runWorker( $revoked_final['args'][0] ?? array() );
ec_assert( 'revoked mailbox terminal retry cleans stored envelope', 0 === count( $GLOBALS['ec_scheduled'] ) );
\DataMachine\Abilities\PermissionHelper::$manage   = true;
\DataMachine\Abilities\PermissionHelper::$user_id  = 1;
\DataMachine\Abilities\PermissionHelper::$agent_id = 0;

echo "\nCase 10: queued named-mailbox issuer authority revalidation\n";
$enqueue_named = static function () use ( $queued ): array {
	$GLOBALS['ec_mailbox_revoked'] = false;
	$GLOBALS['ec_scheduled']       = array();
	$queued->execute( array( 'auth_ref' => 'email_imap:delegated', 'to' => 'user@example.com', 'subject' => 'Authority', 'body' => 'body' ) );
	$scheduled = reset( $GLOBALS['ec_scheduled'] );
	return $scheduled['args'][0] ?? array();
};

\DataMachine\Abilities\PermissionHelper::$user_id  = 1;
\DataMachine\Abilities\PermissionHelper::$agent_id = 0;
\DataMachine\Abilities\PermissionHelper::$token_id = 0;
$user_payload = $enqueue_named();
$GLOBALS['ec_manage_users'][1] = false;
$GLOBALS['ec_wp_mail_calls']   = array();
$worker->runWorker( $user_payload );
ec_assert( 'named queue denies user capability demotion after enqueue', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );
$GLOBALS['ec_manage_users'][1] = true;
$user_payload = $enqueue_named();
$GLOBALS['ec_users'][1]        = false;
$GLOBALS['ec_wp_mail_calls']   = array();
$worker->runWorker( $user_payload );
ec_assert( 'named queue denies deleted user after enqueue', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );
$GLOBALS['ec_users'][1] = true;

\DataMachine\Abilities\PermissionHelper::$user_id  = 42;
\DataMachine\Abilities\PermissionHelper::$agent_id = 303;
$agent_payload = $enqueue_named();
unset( \DataMachine\Core\Database\Agents\Agents::$rows[303] );
$GLOBALS['ec_wp_mail_calls'] = array();
$worker->runWorker( $agent_payload );
ec_assert( 'named queue denies deleted agent after enqueue', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );
\DataMachine\Core\Database\Agents\Agents::$rows[303] = array( 'owner_id' => 42 );
$agent_payload = $enqueue_named();
$GLOBALS['ec_manage_users'][42] = false;
$GLOBALS['ec_wp_mail_calls']    = array();
$worker->runWorker( $agent_payload );
ec_assert( 'named queue denies revoked agent owner capability ceiling', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );
$GLOBALS['ec_manage_users'][42] = true;

\DataMachine\Abilities\PermissionHelper::$token_id = 900;
$valid_token = static fn ( ?array $caps = array( 'datamachine_use_tools' ), array $metadata = array() ): WP_Agent_Token => new WP_Agent_Token( 900, '303', 42, $caps, $metadata );
\DataMachine\Core\Database\Agents\AgentTokens::$tokens[900] = $valid_token();
$valid_token_payload = $enqueue_named();
$GLOBALS['ec_wp_mail_calls'] = array();
$worker->runWorker( $valid_token_payload );
ec_assert( 'named queue allows unchanged live token authority', 1 === count( $GLOBALS['ec_wp_mail_calls'] ) );

$GLOBALS['ec_scheduled'] = array();
$token_payload = $enqueue_named();
unset( \DataMachine\Core\Database\Agents\AgentTokens::$tokens[900] );
$GLOBALS['ec_wp_mail_calls'] = array();
$worker->runWorker( $token_payload );
ec_assert( 'named queue denies revoked token after enqueue', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );

\DataMachine\Core\Database\Agents\AgentTokens::$tokens[900] = $valid_token();
$token_payload = $enqueue_named();
\DataMachine\Core\Database\Agents\AgentTokens::$tokens[900] = $valid_token( array() );
$GLOBALS['ec_wp_mail_calls'] = array();
$worker->runWorker( $token_payload );
ec_assert( 'named queue denies narrowed token capability ceiling', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );

\DataMachine\Core\Database\Agents\AgentTokens::$tokens[900] = $valid_token();
$token_payload = $enqueue_named();
$denied_scope = array( 'capabilities' => array( 'datamachine_use_tools' ), 'ability_deny' => array( 'datamachine/send-email-queued' ) );
\DataMachine\Core\Database\Agents\AgentTokens::$tokens[900] = $valid_token( array( 'datamachine_use_tools' ), array( 'datamachine_scope' => $denied_scope ) );
$GLOBALS['ec_wp_mail_calls'] = array();
$worker->runWorker( $token_payload );
ec_assert( 'named queue denies revoked token ability scope', 0 === count( $GLOBALS['ec_wp_mail_calls'] ) );
\DataMachine\Abilities\PermissionHelper::$token_id = 0;

ec_assert( 'durable email payload options are cleaned after terminal outcomes', array() === $GLOBALS['ec_options'], implode( ', ', array_keys( $GLOBALS['ec_options'] ) ) );

/* ---------------------------------------------------------------------------
 * Summary
 * -------------------------------------------------------------------------*/

echo "\n";
if ( $failed > 0 ) {
	echo "FAILED: $failed / $total\n";
	exit( 1 );
}
echo "OK: $total assertions passed\n";
