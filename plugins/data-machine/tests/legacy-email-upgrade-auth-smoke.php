<?php
/**
 * End-to-end compatibility coverage for the final pre-1.0 email auth transform.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static function acting_user_id(): int {
			return 0;
		}

		public static function get_acting_agent_id(): ?int {
			return null;
		}

		public static function can_manage(): bool {
			return false;
		}

		public static function can( string $action ): bool {
			return false;
		}
	}
}

namespace DataMachine\Core\Database\Flows {
	class Flows {
		public function get_flow( int $flow_id ): ?array {
			return $GLOBALS['legacy_email_flows'][ get_current_blog_id() ][ $flow_id ] ?? null;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['legacy_email_options'] = array();
	$GLOBALS['legacy_email_flows']   = array();
	$GLOBALS['legacy_email_blog_id'] = 1;

	class WP_Error {
		public function __construct( private string $code, private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string {
			return $this->code;
		}
	}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function __( string $text, string $domain = '' ): string {
		return $text;
	}

	function absint( $value ): int {
		return abs( (int) $value );
	}

	function sanitize_key( $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}

	function user_can( int $user_id, string $capability ): bool {
		return false;
	}

	function get_current_blog_id(): int {
		return $GLOBALS['legacy_email_blog_id'];
	}

	function get_site_option( string $name, $default = false ) {
		return $GLOBALS['legacy_email_options'][ get_current_blog_id() ][ $name ] ?? $default;
	}

	function update_site_option( string $name, $value ): bool {
		$GLOBALS['legacy_email_options'][ get_current_blog_id() ][ $name ] = $value;
		return true;
	}

	function wp_salt( string $scheme = 'auth' ): string {
		return 'legacy-upgrade-network-salt-' . $scheme;
	}

	function apply_filters( string $hook, $value ) {
		return $value;
	}

	function do_action( string $hook, ...$args ): void {}

	require_once __DIR__ . '/../inc/Core/OAuth/BaseAuthProvider.php';
	require_once __DIR__ . '/../inc/Core/Steps/Fetch/Handlers/Email/EmailAuth.php';

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

	$auth = new EmailAuth();
	$auth->save_config(
		array(
			'imap_host'     => 'imap.example.test',
			'imap_user'     => 'legacy@example.test',
			'imap_password' => 'secret',
		)
	);

	$flow_id      = 91;
	$flow_step_id = 'step-email';
	$agent_id     = 303;
	$legacy_flow  = array(
		'agent_id'   => $agent_id,
		'flow_config' => array(
			$flow_step_id => array(
				'handler_slugs'   => array( 'email' ),
				'handler_configs' => array( 'email' => array( 'folder' => 'INBOX' ) ),
			),
		),
	);

	// Represent the final pre-1.0 transform against the legacy persisted row.
	$marker = EmailAuth::legacy_default_marker( $flow_id, $flow_step_id, $agent_id );
	$assert( hash_hmac( 'sha256', 'email-default-v1|91|step-email|303', wp_salt( 'auth' ) ) === $marker, '1.0 reproduces the shipped pre-1.0 signature format' );
	$legacy_flow['flow_config'][ $flow_step_id ]['handler_configs']['email']['_legacy_default_auth'] = $marker;
	$GLOBALS['legacy_email_flows'][1][ $flow_id ] = $legacy_flow;

	$context = array(
		'agent_id'           => $agent_id,
		'flow_id'            => $flow_id,
		'flow_step_id'       => $flow_step_id,
		'legacy_default_auth' => $marker,
	);
	$assert( ! is_wp_error( $auth->resolve_mailbox_for_principal( 'default', 'read', $context ) ), 'valid migrated row retains site default mailbox access after 1.0' );
	$assert( is_wp_error( $auth->resolve_mailbox_for_principal( 'default', 'read', array_diff_key( $context, array( 'legacy_default_auth' => true ) ) ) ), 'unsigned legacy flow fails closed' );

	$forged_context                        = $context;
	$forged_context['legacy_default_auth'] = str_repeat( 'a', 64 );
	$assert( is_wp_error( $auth->resolve_mailbox_for_principal( 'default', 'read', $forged_context ) ), 'forged marker fails closed' );

	$new_flow_context            = $context;
	$new_flow_context['flow_id'] = 92;
	$assert( is_wp_error( $auth->resolve_mailbox_for_principal( 'default', 'read', $new_flow_context ) ), 'new flow cannot replay a migrated marker' );

	$wrong_agent_context             = $context;
	$wrong_agent_context['agent_id'] = 404;
	$assert( is_wp_error( $auth->resolve_mailbox_for_principal( 'default', 'read', $wrong_agent_context ) ), 'marker cannot widen agent ownership' );

	$wrong_step_context                 = $context;
	$wrong_step_context['flow_step_id'] = 'other-step';
	$assert( is_wp_error( $auth->resolve_mailbox_for_principal( 'default', 'read', $wrong_step_context ) ), 'marker cannot widen step scope' );

	$GLOBALS['legacy_email_blog_id'] = 2;
	$auth->save_config(
		array(
			'imap_host'     => 'imap.other.test',
			'imap_user'     => 'other@example.test',
			'imap_password' => 'other-secret',
		)
	);
	$assert( is_wp_error( $auth->resolve_mailbox_for_principal( 'default', 'read', $context ) ), 'marker cannot cross site scope without its persisted row' );

	if ( $failures ) {
		exit( 1 );
	}

	echo 'legacy-email-upgrade-auth-smoke: ok' . PHP_EOL;
}
