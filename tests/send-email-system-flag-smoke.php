<?php
/**
 * Pure-PHP smoke test for the send-email principal-less system flag (#3534).
 *
 * Covers:
 * - `system: true` with no acting principal authorizes the shared default
 *   mailbox through the queue worker's principal_less_system branch.
 * - `system: true` from a logged-in non-manager (or an acting agent) is
 *   stripped and denied with the existing error codes.
 * - `system` is stripped for REST-originated executions outside the trusted
 *   run_as_authenticated() seam.
 * - The datamachine_email_system_mailbox option selects the system account.
 * - EmailAuth::get_mailbox_index() and `wp datamachine email mailboxes`
 *   expose redacted metadata only.
 *
 * Run with: php tests/send-email-system-flag-smoke.php
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

$GLOBALS['ec_filters']      = array();
$GLOBALS['ec_wp_mail']      = array();
$GLOBALS['ec_options']      = array();
$GLOBALS['ec_site_options'] = array();
$GLOBALS['ec_manage_users'] = array( 1 => true );

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
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
	}
}

function doing_action( string $hook ): bool {
	return 'wp_abilities_api_init' === $hook;
}

function did_action( string $hook ): bool {
	return false;
}

function wp_register_ability( string $id, array $args ): bool {
	$GLOBALS['ec_abilities'][ $id ] = $args;
	return true;
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

if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( string $key, $default_value = false ) {
		return array_key_exists( $key, $GLOBALS['ec_site_options'] ) ? $GLOBALS['ec_site_options'][ $key ] : $default_value;
	}
}

if ( ! function_exists( 'update_site_option' ) ) {
	function update_site_option( string $key, $value ): bool {
		$GLOBALS['ec_site_options'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_site_option' ) ) {
	function delete_site_option( string $key ): bool {
		unset( $GLOBALS['ec_site_options'][ $key ] );
		return true;
	}
}

function wp_date( string $format, ?int $timestamp = null ): string {
	return gmdate( $format, $timestamp ?? time() );
}

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $body, $headers = array(), $attachments = array() ): bool {
		$GLOBALS['ec_wp_mail'][] = array(
			'to'      => $to,
			'subject' => $subject,
			'body'    => $body,
			'headers' => $headers,
		);
		return true;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return true;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $s, string $domain = '' ): string {
		return $s;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
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
		return 'system-flag-smoke-' . $scheme;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

/* ---------------------------------------------------------------------------
 * Stub PermissionHelper with controllable principal state.
 * -------------------------------------------------------------------------*/

if ( ! class_exists( '\\DataMachine\\Abilities\\PermissionHelper' ) ) {
	eval( 'namespace DataMachine\\Abilities; class PermissionHelper { public static bool $manage = false; public static int $user_id = 0; public static int $agent_id = 0; public static bool $authenticated = false; public static function can_manage(): bool { return self::$manage; } public static function can( string $action ): bool { return self::$manage; } public static function acting_user_id(): int { return self::$user_id; } public static function get_acting_agent_id(): ?int { return self::$agent_id ?: null; } public static function is_authenticated_context(): bool { return self::$authenticated; } }' );
}

/* ---------------------------------------------------------------------------
 * Load the ability and the real EmailAuth provider.
 * -------------------------------------------------------------------------*/

require_once __DIR__ . '/../inc/Abilities/AbilityRegistration.php';
require_once __DIR__ . '/../inc/Abilities/Publish/SendEmailAbility.php';
require_once __DIR__ . '/../inc/Core/OAuth/BaseAuthProvider.php';
require_once __DIR__ . '/../inc/Core/Steps/Fetch/Handlers/Email/EmailAuth.php';

new \DataMachine\Abilities\Publish\SendEmailAbility();

$execute = $GLOBALS['ec_abilities']['datamachine/send-email']['execute_callback'] ?? null;
ec_assert( 'send-email ability registered with execute callback', is_callable( $execute ) );

$auth = new \DataMachine\Core\Steps\Fetch\Handlers\Email\EmailAuth();
\DataMachine\Abilities\PermissionHelper::$manage  = true;
\DataMachine\Abilities\PermissionHelper::$user_id = 1;
$auth->save_config( array(
	'imap_host'       => 'imap.example.test',
	'imap_port'       => 993,
	'imap_encryption' => 'ssl',
	'imap_user'       => 'site@example.test',
	'imap_password'   => 'secret-site-password',
) );

add_filter( 'datamachine_auth_providers', static function ( array $providers ) use ( $auth ): array {
	$providers['email_imap'] = $auth;
	return $providers;
} );



function ec_reset_principal(): void {
	\DataMachine\Abilities\PermissionHelper::$manage        = false;
	\DataMachine\Abilities\PermissionHelper::$user_id       = 0;
	\DataMachine\Abilities\PermissionHelper::$agent_id      = 0;
	\DataMachine\Abilities\PermissionHelper::$authenticated = false;
}

function ec_from_header( array $call ): string {
	foreach ( $call['headers'] ?? array() as $header ) {
		if ( 0 === stripos( (string) $header, 'From:' ) ) {
			return (string) $header;
		}
	}
	return '';
}

/* ---------------------------------------------------------------------------
 * Case 1 — input schema declares the server-side-only system flag.
 * -------------------------------------------------------------------------*/

echo "\nCase 1: schema contract\n";
$properties = $GLOBALS['ec_abilities']['datamachine/send-email']['input_schema']['properties'];
ec_assert( 'system flag declared as boolean', isset( $properties['system'] ) && 'boolean' === $properties['system']['type'] );
ec_assert( 'system flag defaults to false', false === ( $properties['system']['default'] ?? null ) );
ec_assert( 'system flag is documented as server-side only', false !== strpos( (string) ( $properties['system']['description'] ?? '' ), 'Server-side only' ) );

/* ---------------------------------------------------------------------------
 * Case 2 — principal-less system send authorizes the shared default mailbox.
 * -------------------------------------------------------------------------*/

echo "\nCase 2: principal-less system send via default mailbox\n";
ec_reset_principal();
$GLOBALS['ec_wp_mail'] = array();
$res                   = $execute( array(
	'system'  => true,
	'to'      => 'user@example.com',
	'subject' => 'Welcome',
	'body'    => '<p>Hello</p>',
) );
ec_assert( 'principal-less system send succeeds', true === ( $res['success'] ?? false ), is_wp_error( $res ) ? $res->get_error_code() : '' );
ec_assert( 'system send dispatches exactly one email', 1 === count( $GLOBALS['ec_wp_mail'] ) );
ec_assert( 'system send uses the resolved default mailbox identity', false !== strpos( ec_from_header( $GLOBALS['ec_wp_mail'][0] ?? array() ), 'site@example.test' ), ec_from_header( $GLOBALS['ec_wp_mail'][0] ?? array() ) );
ec_assert( 'system send logs the authorized mailbox ref', false !== strpos( serialize( $res['logs'] ?? array() ), 'email_imap:default' ) );

$res = $execute( array(
	'to'      => 'user@example.com',
	'subject' => 'No flag',
	'body'    => '<p>Hello</p>',
) );
ec_assert( 'principal-less send without the flag stays denied', is_wp_error( $res ) && 'email_auth_ref_required' === $res->get_error_code() );
ec_assert( 'denied send never calls wp_mail', 1 === count( $GLOBALS['ec_wp_mail'] ) );

/* ---------------------------------------------------------------------------
 * Case 3 — logged-in non-manager passing system: true is denied.
 * -------------------------------------------------------------------------*/

echo "\nCase 3: logged-in non-manager denied\n";
\DataMachine\Abilities\PermissionHelper::$user_id = 2;
\DataMachine\Abilities\PermissionHelper::$manage  = false;
$GLOBALS['ec_wp_mail']     = array();
$res                       = $execute( array(
	'system'  => true,
	'to'      => 'user@example.com',
	'subject' => 'Escalation',
	'body'    => 'body',
) );
ec_assert( 'non-manager system flag is denied with existing error code', is_wp_error( $res ) && 'email_auth_ref_required' === $res->get_error_code(), is_wp_error( $res ) ? $res->get_error_code() : 'sent' );
ec_assert( 'non-manager escalation never sends', 0 === count( $GLOBALS['ec_wp_mail'] ) );

/* ---------------------------------------------------------------------------
 * Case 4 — manager path is unchanged: the flag never applies to principals.
 * -------------------------------------------------------------------------*/

echo "\nCase 4: manager legacy path unchanged\n";
\DataMachine\Abilities\PermissionHelper::$user_id = 1;
\DataMachine\Abilities\PermissionHelper::$manage  = true;
$GLOBALS['ec_wp_mail']     = array();
$res                       = $execute( array(
	'system'  => true,
	'to'      => 'user@example.com',
	'subject' => 'Admin',
	'body'    => 'body',
) );
ec_assert( 'manager send still succeeds', true === ( $res['success'] ?? false ), is_wp_error( $res ) ? $res->get_error_code() : '' );
ec_assert( 'manager send keeps the legacy admin_email sender, not the flag', false !== strpos( ec_from_header( $GLOBALS['ec_wp_mail'][0] ?? array() ), 'admin@example.com' ) );

/* ---------------------------------------------------------------------------
 * Case 5 — acting agent principal can never opt into the system path.
 * -------------------------------------------------------------------------*/

echo "\nCase 5: acting agent denied\n";
ec_reset_principal();
\DataMachine\Abilities\PermissionHelper::$agent_id = 303;
$GLOBALS['ec_wp_mail']      = array();
$res                        = $execute( array(
	'system'  => true,
	'to'      => 'user@example.com',
	'subject' => 'Agent escalation',
	'body'    => 'body',
) );
ec_assert( 'agent-context system flag is denied with existing error code', is_wp_error( $res ) && 'email_auth_ref_required' === $res->get_error_code(), is_wp_error( $res ) ? $res->get_error_code() : 'sent' );
ec_assert( 'agent escalation never sends', 0 === count( $GLOBALS['ec_wp_mail'] ) );

/* ---------------------------------------------------------------------------
 * Case 6 — REST-originated execution strips the flag outside the trusted seam.
 * -------------------------------------------------------------------------*/

echo "\nCase 6: REST origin stripped outside the trusted seam\n";
ec_reset_principal();
if ( ! defined( 'REST_REQUEST' ) ) {
	define( 'REST_REQUEST', true );
}
$GLOBALS['ec_wp_mail'] = array();
$res                   = $execute( array(
	'system'  => true,
	'to'      => 'user@example.com',
	'subject' => 'REST escalation',
	'body'    => 'body',
) );
ec_assert( 'REST-originated system flag is stripped and denied', is_wp_error( $res ) && 'email_auth_ref_required' === $res->get_error_code(), is_wp_error( $res ) ? $res->get_error_code() : 'sent' );
ec_assert( 'REST escalation never sends', 0 === count( $GLOBALS['ec_wp_mail'] ) );

/* ---------------------------------------------------------------------------
 * Case 7 — REST request inside the trusted run_as_authenticated seam is
 * honored (registration/welcome-email consumer path).
 * -------------------------------------------------------------------------*/

echo "\nCase 7: REST inside run_as_authenticated honored\n";
\DataMachine\Abilities\PermissionHelper::$authenticated = true;
$GLOBALS['ec_wp_mail']           = array();
$res                             = $execute( array(
	'system'  => true,
	'to'      => 'user@example.com',
	'subject' => 'Registration',
	'body'    => '<p>Welcome</p>',
) );
ec_assert( 'trusted seam system send succeeds during REST', true === ( $res['success'] ?? false ), is_wp_error( $res ) ? $res->get_error_code() : '' );
ec_assert( 'trusted seam send uses the default mailbox identity', false !== strpos( ec_from_header( $GLOBALS['ec_wp_mail'][0] ?? array() ), 'site@example.test' ) );

/* ---------------------------------------------------------------------------
 * Case 8 — datamachine_email_system_mailbox selects the system account.
 * -------------------------------------------------------------------------*/

echo "\nCase 8: system mailbox option consulted\n";
update_site_option( 'datamachine_email_system_mailbox', 'notifications' );
ec_reset_principal();
\DataMachine\Abilities\PermissionHelper::$authenticated = true;
$GLOBALS['ec_wp_mail'] = array();
$res                   = $execute( array(
	'system'  => true,
	'to'      => 'user@example.com',
	'subject' => 'Missing mailbox',
	'body'    => 'body',
) );
ec_assert( 'unconfigured system mailbox name fails with existing error code', is_wp_error( $res ) && 'auth_ref_unresolved' === $res->get_error_code(), is_wp_error( $res ) ? $res->get_error_code() : 'sent' );
ec_assert( 'failed system mailbox resolution never sends', 0 === count( $GLOBALS['ec_wp_mail'] ) );

\DataMachine\Abilities\PermissionHelper::$manage  = true;
\DataMachine\Abilities\PermissionHelper::$user_id = 1;
$auth->save_named_account( 'notifications', array(
	'imap_host'       => 'imap.example.test',
	'imap_port'       => 993,
	'imap_encryption' => 'ssl',
	'imap_user'       => 'notifications@example.test',
	'imap_password'   => 'secret-notifications-password',
), \DataMachine\Core\OAuth\BaseAuthProvider::AUTH_SCOPE_SITE, 0 );
ec_reset_principal();
\DataMachine\Abilities\PermissionHelper::$authenticated = true;
$res = $execute( array(
	'system'  => true,
	'to'      => 'user@example.com',
	'subject' => 'Missing mailbox',
	'body'    => 'body',
) );
ec_reset_principal();
\DataMachine\Abilities\PermissionHelper::$authenticated = true;
$GLOBALS['ec_wp_mail'] = array();
$res                   = $execute( array(
	'system'  => true,
	'to'      => 'user@example.com',
	'subject' => 'Named mailbox',
	'body'    => 'body',
) );
ec_assert( 'named site mailbox under principal-less context follows existing access policy', is_wp_error( $res ) && 'email_mailbox_forbidden' === $res->get_error_code(), is_wp_error( $res ) ? $res->get_error_code() : 'sent' );
ec_assert( 'denied named system mailbox never sends', 0 === count( $GLOBALS['ec_wp_mail'] ) );

update_site_option( 'datamachine_email_system_mailbox', 'default' );
$GLOBALS['ec_wp_mail'] = array();
$res                   = $execute( array(
	'system'  => true,
	'to'      => 'user@example.com',
	'subject' => 'Back to default',
	'body'    => 'body',
) );
ec_assert( 'resetting the option restores the default system sender', true === ( $res['success'] ?? false ), is_wp_error( $res ) ? $res->get_error_code() : '' );

\DataMachine\Abilities\PermissionHelper::$authenticated = false;

/* ---------------------------------------------------------------------------
 * Case 9 — get_mailbox_index is redacted.
 * -------------------------------------------------------------------------*/

echo "\nCase 9: redacted mailbox index\n";
\DataMachine\Abilities\PermissionHelper::$manage  = true;
\DataMachine\Abilities\PermissionHelper::$user_id = 1;
$auth->save_named_account( 'personal', array(
	'imap_host'       => 'imap.example.test',
	'imap_port'       => 993,
	'imap_encryption' => 'ssl',
	'imap_user'       => 'personal@example.test',
	'imap_password'   => 'secret-personal-password',
), \DataMachine\Core\OAuth\BaseAuthProvider::AUTH_SCOPE_USER, 42 );

$index = $auth->get_mailbox_index();
ec_assert( 'mailbox index lists default plus named accounts', 3 === count( $index ), (string) count( $index ) );
$by_account = array();
foreach ( $index as $row ) {
	$by_account[ $row['account'] . ':' . $row['owner_type'] . ':' . $row['owner_id'] ] = $row;
}
ec_assert( 'index marks the site default', isset( $by_account['default:site:0'] ) );
ec_assert( 'index includes site-scoped named account with owner metadata', isset( $by_account['notifications:site:0'] ) && 'notifications@example.test' === $by_account['notifications:site:0']['imap_user'] );
ec_assert( 'index includes user-scoped named account', isset( $by_account['personal:user:42'] ) && 'personal@example.test' === $by_account['personal:user:42']['imap_user'] );
ec_assert( 'index rows never carry credential fields', ! isset( $row['imap_password'] ) && array_keys( reset( $index ) ) === array( 'account', 'owner_type', 'owner_id', 'imap_host', 'imap_user' ) );
ec_assert( 'index output contains no secrets', ! str_contains( wp_json_encode( $index ), 'secret-' ) );

/* ---------------------------------------------------------------------------
 * Case 10 — CLI mailboxes command reads the provider index, never secrets.
 * -------------------------------------------------------------------------*/

echo "\nCase 10: CLI contract\n";
$cli_source = (string) file_get_contents( __DIR__ . '/../inc/Cli/Commands/EmailCommand.php' );
ec_assert( 'email CLI registers the mailboxes subcommand', false !== strpos( $cli_source, '@subcommand mailboxes' ) );
ec_assert( 'mailboxes command reads the provider index', false !== strpos( $cli_source, 'get_mailbox_index' ) );
ec_assert( 'email CLI never prints passwords', ! str_contains( $cli_source, 'imap_password' ) );

$email_auth_source = (string) file_get_contents( __DIR__ . '/../inc/Core/Steps/Fetch/Handlers/Email/EmailAuth.php' );
$start             = strpos( $email_auth_source, 'public function get_mailbox_index' );
$end               = strpos( $email_auth_source, 'private function can_use_default' );
ec_assert( 'get_mailbox_index source never touches password fields', false !== $start && false !== $end && ! str_contains( substr( $email_auth_source, $start, $end - $start ), 'imap_password' ) );

/* ---------------------------------------------------------------------------
 * Summary.
 * -------------------------------------------------------------------------*/

echo "\n";
if ( $failed > 0 ) {
	echo "send-email-system-flag-smoke: FAILED ({$failed}/{$total})\n";
	exit( 1 );
}
echo "send-email-system-flag-smoke: ok ({$total} assertions)\n";
