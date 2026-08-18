<?php
/**
 * Behavioral coverage for verified-state OAuth callback authorization.
 *
 * Run with: php tests/oauth-agent-callback-authorization-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\OAuth {
	class BaseOAuth2Provider {
		public function get_provider_slug(): string { return 'test-provider'; }
		protected function get_agent_scoped_oauth_callback_config(): ?array { return array( 'account_details' => static fn(): array => array(), 'token_url' => 'https://provider.example/token', 'token_params' => array() ); }
		final public function supports_agent_scoped_oauth_callback(): bool { return null !== $this->get_agent_scoped_oauth_callback_config(); }
		public function get_oauth_response_type(): string { return 'code'; }
	}

	class OAuth2Handler {
		public static array|false $payload = false;
		public function peek_state( string $provider, string $state ) { return 'valid-state' === $state ? self::$payload : false; }
	}
}

namespace DataMachine\Core\Database\Agents {
	class Agents {
		public static ?array $agent = array( 'agent_id' => 303, 'owner_id' => 77 );
		public function get_agent( int $agent_id ): ?array { return 303 === $agent_id ? self::$agent : null; }
	}

	class AgentAccess {
		public static bool $admin = false;
		public function user_can_access( int $agent_id, int $user_id, string $role ): bool { return 303 === $agent_id && 88 === $user_id && self::$admin && 'admin' === $role; }
	}
}

namespace {
	define( 'WPINC', 'wp-includes' );
	$GLOBALS['oauth_callback_user_id'] = 0;
	function sanitize_text_field( $value ): string { return trim( (string) $value ); }
	function wp_unslash( $value ) { return $value; }
	function absint( $value ): int { return abs( (int) $value ); }
	function get_current_user_id(): int { return $GLOBALS['oauth_callback_user_id']; }
	function current_user_can( string $capability ): bool { return false; }
	class WP_Agent_Access_Grant { const ROLE_ADMIN = 'admin'; }

	require_once dirname( __DIR__ ) . '/inc/Engine/Filters/OAuth.php';

	$provider = new \DataMachine\Core\OAuth\BaseOAuth2Provider();
	\DataMachine\Core\OAuth\OAuth2Handler::$payload = array( 'agent_id' => 303, 'user_id' => 77 );

	$GLOBALS['oauth_callback_user_id'] = 77;
	if ( ! datamachine_oauth_can_handle_agent_scoped_callback( $provider, array( 'state' => 'valid-state' ) ) ) {
		throw new \RuntimeException( 'The verified agent owner should be authorized for the callback.' );
	}

	$GLOBALS['oauth_callback_user_id']                       = 88;
	\DataMachine\Core\Database\Agents\AgentAccess::$admin = true;
	if ( ! datamachine_oauth_can_handle_agent_scoped_callback( $provider, array( 'state' => 'valid-state' ) ) ) {
		throw new \RuntimeException( 'A verified agent admin should be authorized for the callback.' );
	}

	\DataMachine\Core\Database\Agents\AgentAccess::$admin = false;
	if ( datamachine_oauth_can_handle_agent_scoped_callback( $provider, array( 'state' => 'valid-state' ) ) ) {
		throw new \RuntimeException( 'A non-owner without agent admin access must be rejected.' );
	}

	if ( datamachine_oauth_can_handle_agent_scoped_callback( $provider, array( 'state' => 'invalid-state' ) ) ) {
		throw new \RuntimeException( 'An invalid state must be rejected before principal authorization.' );
	}

	\DataMachine\Core\OAuth\OAuth2Handler::$payload = array();
	$GLOBALS['oauth_callback_user_id']                  = 77;
	if ( datamachine_oauth_can_handle_agent_scoped_callback( $provider, array( 'state' => 'valid-state' ) ) ) {
		throw new \RuntimeException( 'Unscoped state must retain the legacy site-admin callback path.' );
	}

	echo "oauth-agent-callback-authorization-smoke passed\n";
}
