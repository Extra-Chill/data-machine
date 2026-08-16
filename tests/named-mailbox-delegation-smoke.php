<?php
/**
 * Pure-PHP coverage for named mailbox storage and delegation.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static int $user_id = 1;
		public static int $agent_id = 0;

		public static function acting_user_id(): int {
			return self::$user_id;
		}

		public static function get_acting_agent_id(): ?int {
			return self::$agent_id ?: null;
		}

		public static function can_manage(): bool {
			return 1 === self::$user_id && 0 === self::$agent_id;
		}

		public static function can( string $action ): bool {
			return self::can_manage();
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['named_mailbox_options'] = array();
	$GLOBALS['named_mailbox_audit']   = array();
	$GLOBALS['named_mailbox_option_updates'] = array();

	class WP_Error {
		public function __construct( private string $code, private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
	}

	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	function __( string $text, string $domain = '' ): string { return $text; }
	function absint( $value ): int { return abs( (int) $value ); }
	function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function user_can( int $user_id, string $capability ): bool { return 1 === $user_id; }
	function get_site_option( string $name, $default = false ) { return $GLOBALS['named_mailbox_options'][ $name ] ?? $default; }
	function update_site_option( string $name, $value ): bool { $GLOBALS['named_mailbox_options'][ $name ] = $value; $GLOBALS['named_mailbox_option_updates'][] = $value; return true; }
	function wp_salt( string $scheme = 'auth' ): string { return 'named-mailbox-test-salt-' . $scheme; }
	function apply_filters( string $hook, $value ) { return $value; }
	function do_action( string $hook, ...$args ): void {
		if ( 'datamachine_email_operation_audit' === $hook ) {
			$GLOBALS['named_mailbox_audit'][] = $args[0];
		}
	}

	require_once __DIR__ . '/../inc/Core/OAuth/BaseAuthProvider.php';
	require_once __DIR__ . '/../inc/Core/Steps/Fetch/Handlers/Email/EmailAuth.php';

	use DataMachine\Abilities\PermissionHelper;
	use DataMachine\Core\OAuth\BaseAuthProvider;
	use DataMachine\Core\Steps\Fetch\Handlers\Email\EmailAuth;

	$failures = array();
	$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
		if ( $condition ) {
			echo "PASS: {$message}\n";
			return;
		}
		$failures[] = $message;
		echo "FAIL: {$message}\n";
	};
	$credentials = static fn ( string $user ): array => array(
		'imap_host'       => 'imap.example.test',
		'imap_port'       => 993,
		'imap_encryption' => 'ssl',
		'imap_user'       => $user,
		'imap_password'   => 'secret-' . $user,
	);

	$auth = new EmailAuth();
	$auth->save_config( $credentials( 'legacy@example.test' ) );
	$auth->save_named_account( 'personal', $credentials( 'personal@example.test' ), BaseAuthProvider::AUTH_SCOPE_USER, 42 );
	$auth->save_named_account( 'events', $credentials( 'events@example.test' ), BaseAuthProvider::AUTH_SCOPE_AGENT, 707 );
	$auth->save_named_account( 'archive', $credentials( 'archive@example.test' ), BaseAuthProvider::AUTH_SCOPE_USER, 42 );

	$assert( 'personal@example.test' === $auth->get_named_account( 'personal', BaseAuthProvider::AUTH_SCOPE_USER, 42 )['imap_user'], 'one principal stores multiple named mailboxes' );
	$assert( 'archive@example.test' === $auth->get_named_account( 'archive', BaseAuthProvider::AUTH_SCOPE_USER, 42 )['imap_user'], 'named mailbox siblings remain isolated' );
	$assert( 'events@example.test' === $auth->resolve_mailbox_for_principal( 'events', 'read', array( 'agent_id' => 707 ) )['credentials']['imap_user'], 'agent-owned mailbox resolves exactly' );

	$assert( $auth->grant_agent( 'personal', BaseAuthProvider::AUTH_SCOPE_USER, 42, 303, array( 'read', 'search', 'draft' ) ), 'owner grants operation-scoped mailbox access' );
	$assert( ! is_wp_error( $auth->resolve_mailbox_for_principal( 'personal', array( 'read', 'search' ), array( 'agent_id' => 303 ) ) ), 'delegated read and search are allowed' );
	$assert( ! is_wp_error( $auth->resolve_mailbox_for_principal( 'personal', 'draft', array( 'agent_id' => 303 ) ) ), 'delegated draft is allowed' );
	$assert( is_wp_error( $auth->resolve_mailbox_for_principal( 'personal', 'send', array( 'agent_id' => 303 ) ) ), 'undelegated send is denied' );
	$assert( is_wp_error( $auth->resolve_mailbox_for_principal( 'personal', 'delete', array( 'agent_id' => 303 ) ) ), 'undelegated delete is denied' );
	$assert( is_wp_error( $auth->resolve_mailbox_for_principal( 'personal', 'read', array( 'agent_id' => 404 ) ) ), 'undelegated agent is denied' );
	$assert( is_wp_error( $auth->resolve_mailbox_for_principal( 'default', 'read', array( 'agent_id' => 404 ) ) ), 'agent denial never falls back to legacy default' );
	$legacy_marker = EmailAuth::legacy_default_marker( 91, 'step-email', 303 );
	$assert( ! is_wp_error( $auth->resolve_mailbox_for_principal( 'default', 'read', array( 'agent_id' => 303, 'flow_id' => 91, 'flow_step_id' => 'step-email', 'legacy_default_auth' => $legacy_marker ) ) ), 'migrated legacy agent flow retains default mailbox access' );
	$assert( is_wp_error( $auth->resolve_mailbox_for_principal( 'default', 'read', array( 'agent_id' => 303, 'flow_id' => 92, 'flow_step_id' => 'step-email', 'legacy_default_auth' => $legacy_marker ) ) ), 'legacy marker cannot be copied to a newly created flow' );
	$assert( ! is_wp_error( $auth->resolve_mailbox( 'default', 'read' ) ), 'legacy default remains compatible for administrators' );
	PermissionHelper::$user_id = 2;
	$assert( is_wp_error( $auth->resolve_mailbox( 'default', 'read' ) ), 'lower-privilege user cannot resolve legacy default' );
	PermissionHelper::$user_id = 0;
	$assert( is_wp_error( $auth->resolve_mailbox( 'default', 'read' ) ), 'principal-less pre-auth context cannot resolve legacy default' );
	$assert( is_wp_error( $auth->resolve_mailbox_for_principal( 'default', 'read', array( 'agent_id' => 303, 'principal_less_system' => true ) ) ), 'new agent flow omission cannot opt into legacy default' );
	PermissionHelper::$user_id = 1;
	$assert( $auth->grant_agent( 'archive', BaseAuthProvider::AUTH_SCOPE_USER, 42, 303, array( 'read' ) ), 'mixed-case revoke fixture is delegated' );
	$assert( $auth->revoke_agent( '  ArChIvE  ', BaseAuthProvider::AUTH_SCOPE_USER, 42, 303 ), 'revoke normalizes mailbox name identically to grant' );
	$assert( is_wp_error( $auth->resolve_mailbox_for_principal( 'archive', 'read', array( 'agent_id' => 303 ) ) ), 'normalized revoke removes the live grant used by queued reauthorization' );

	$assert( $auth->grant_agent( 'personal', BaseAuthProvider::AUTH_SCOPE_USER, 42, 303, array( 'read' ) ), 'delete/recreate fixture has a delegation' );
	$updates_before_delete = count( $GLOBALS['named_mailbox_option_updates'] );
	$assert( $auth->delete_named_account( 'personal', BaseAuthProvider::AUTH_SCOPE_USER, 42 ), 'named mailbox deletion succeeds' );
	$delete_updates = array_slice( $GLOBALS['named_mailbox_option_updates'], $updates_before_delete );
	$assert( 1 === count( $delete_updates ) && ! isset( $delete_updates[0]['email_imap']['principals']['user:42']['accounts']['personal'] ) && ! isset( $delete_updates[0]['email_imap']['delegations']['user:42']['personal'] ), 'one option mutation removes the account and exact delegation together' );
	$assert( $auth->save_named_account( 'personal', $credentials( 'recreated@example.test' ), BaseAuthProvider::AUTH_SCOPE_USER, 42 ), 'same mailbox name can be recreated' );
	$assert( is_wp_error( $auth->resolve_mailbox_for_principal( 'personal', 'read', array( 'agent_id' => 303 ) ) ), 'delete and recreate does not resurrect prior delegations' );

	$raw = get_site_option( 'datamachine_auth_data', array() );
	$assert( str_starts_with( $raw['email_imap']['principals']['user:42']['accounts']['personal']['imap_password'], 'dm:enc:v1:' ), 'IMAP passwords are encrypted at rest' );
	$assert( ! str_contains( json_encode( $GLOBALS['named_mailbox_audit'] ), 'secret-' ), 'audit metadata contains no credentials' );
	$assert( array( 'folder' => 'INBOX' ) === $auth->strip_auth_config_secrets( array_merge( $credentials( 'redact@example.test' ), array( 'folder' => 'INBOX' ) ) ), 'export redacts the complete connection identity' );

	if ( $failures ) {
		exit( 1 );
	}

	echo 'named-mailbox-delegation-smoke: ok' . PHP_EOL;
}
