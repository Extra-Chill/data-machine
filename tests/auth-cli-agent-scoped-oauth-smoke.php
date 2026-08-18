<?php
/**
 * Behavioral coverage for agent-scoped OAuth CLI setup.
 *
 * Run with: php tests/auth-cli-agent-scoped-oauth-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Cli {
	class BaseCommand {}

	class AgentResolver {
		public static function resolveEffectiveContext( array $args ): array {
			if ( 'unknown' === $args['agent'] ) {
				\WP_CLI::error( 'Agent "unknown" was not found.' );
			}

			return array(
				'agent_id'   => 44,
				'user_id'    => 77,
				'agent_slug' => 'writer',
			);
		}
	}
}

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static ?int $agent_id = null;
		public static ?int $user_id = null;

		public static function run_as_agent_context( int $agent_id, int $user_id, callable $callback ) {
			$previous_agent = self::$agent_id;
			$previous_user  = self::$user_id;
			self::$agent_id = $agent_id;
			self::$user_id  = $user_id;

			try {
				return $callback();
			} finally {
				self::$agent_id = $previous_agent;
				self::$user_id  = $previous_user;
			}
		}
	}

	class AuthAbilities {
		private object $provider;

		public function __construct() {
			$this->provider = new class() extends \DataMachine\Core\OAuth\BaseOAuth2Provider {
				protected function get_agent_scoped_oauth_callback_config(): ?array { return array( 'account_details' => static fn(): array => array(), 'token_url' => 'https://provider.example/token', 'token_params' => array() ); }
				public function get_authorization_url(): string {
					$this->mark_agent_state_created();
					return 'https://provider.example/authorize';
				}
			};
		}
		public function providerExists( string $handler_slug ): bool { return in_array( $handler_slug, array( 'oauth', 'oauth-bypass', 'oauth-custom', 'oauth1' ), true ); }
		public function getProviderForHandler( string $handler_slug ): object {
			if ( 'oauth1' === $handler_slug ) {
				return new class() {
					public function get_authorization_url(): string { return 'https://provider.example/oauth1'; }
				};
			}
			if ( 'oauth-bypass' === $handler_slug ) {
				return new class() extends \DataMachine\Core\OAuth\BaseOAuth2Provider {
					protected function get_agent_scoped_oauth_callback_config(): ?array { return array( 'account_details' => static fn(): array => array(), 'token_url' => 'https://provider.example/token', 'token_params' => array() ); }
					public function get_authorization_url(): string { return 'https://provider.example/unsafe'; }
				};
			}
			if ( 'oauth-custom' === $handler_slug ) {
				return new class() extends \DataMachine\Core\OAuth\BaseOAuth2Provider {
					public function get_authorization_url(): string { return 'https://provider.example/custom'; }
					public function handle_oauth_callback(): void {}
				};
			}
			return $this->provider;
		}
		public function executeGetAuthStatus( array $input ): array {
			$url = $this->getProviderForHandler( $input['handler_slug'] )->get_authorization_url();
			return array(
				'success'       => true,
				'authenticated' => false,
				'requires_auth' => true,
				'oauth_url'     => $url . '?agent=' . ( PermissionHelper::$agent_id ?? 'site' ),
			);
		}
	}
}

namespace DataMachine\Core\Database\Agents {
	class AgentAccess {
		public static bool $allowed = true;
		public function user_can_access( int $agent_id, int $user_id, string $minimum_role ): bool {
			return self::$allowed;
		}
	}
}

namespace DataMachine\Core\OAuth {
	abstract class BaseOAuth2Provider {
		private bool $agent_state_created = false;
		public function begin_agent_scoped_authorization(): void { $this->agent_state_created = false; }
		public function has_agent_scoped_authorization_state(): bool { return $this->agent_state_created; }
		public function mark_agent_state_created(): void { $this->agent_state_created = true; }
		protected function get_agent_scoped_oauth_callback_config(): ?array { return null; }
		final public function supports_agent_scoped_oauth_callback(): bool { return null !== $this->get_agent_scoped_oauth_callback_config(); }
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	function sanitize_text_field( $value ): string { return trim( (string) $value ); }
	function get_current_user_id(): int { return (int) $GLOBALS['auth_cli_user_id']; }
	function current_user_can( string $capability ): bool { return (bool) $GLOBALS['auth_cli_site_admin']; }

	class WP_Agent_Access_Grant {
		const ROLE_ADMIN = 'admin';
	}

	class WP_CLI {
		public static array $logs = array();
		public static function error( string $message ): void { throw new \RuntimeException( $message ); }
		public static function log( string $message ): void { self::$logs[] = $message; }
		public static function success( string $message ): void { self::$logs[] = $message; }
	}

	require_once dirname( __DIR__ ) . '/inc/Cli/Commands/AuthCommand.php';

	function auth_cli_run( array $args ): array {
		WP_CLI::$logs = array();
		$command      = new \DataMachine\Cli\Commands\AuthCommand();
		$command->connect( array( 'oauth' ), $args );
		return WP_CLI::$logs;
	}

	$GLOBALS['auth_cli_user_id']    = 99;
	$GLOBALS['auth_cli_site_admin'] = false;

	$logs = auth_cli_run( array( 'agent' => 'writer' ) );
	if ( ! in_array( 'OAuth principal: agent writer (ID 44), owner user 77.', $logs, true ) || ! in_array( 'https://provider.example/authorize?agent=44', $logs, true ) ) {
		throw new \RuntimeException( 'Scoped OAuth connect did not generate and identify the agent-bound URL.' );
	}
	if ( null !== \DataMachine\Abilities\PermissionHelper::$agent_id || null !== \DataMachine\Abilities\PermissionHelper::$user_id ) {
		throw new \RuntimeException( 'Scoped OAuth connect leaked its temporary agent context.' );
	}

	$logs = auth_cli_run( array() );
	if ( ! in_array( 'https://provider.example/authorize?agent=site', $logs, true ) ) {
		throw new \RuntimeException( 'Unscoped OAuth connect no longer uses the legacy site context.' );
	}

	\DataMachine\Core\Database\Agents\AgentAccess::$allowed = true;
	try {
		auth_cli_run( array( 'agent' => 'unknown' ) );
		throw new \RuntimeException( 'Unknown agents must fail closed.' );
	} catch ( \RuntimeException $e ) {
		if ( 'Agent "unknown" was not found.' !== $e->getMessage() ) {
			throw $e;
		}
	}

	\DataMachine\Core\Database\Agents\AgentAccess::$allowed = false;
	try {
		auth_cli_run( array( 'agent' => 'writer' ) );
		throw new \RuntimeException( 'Unauthorized agents must fail closed.' );
	} catch ( \RuntimeException $e ) {
		if ( false === strpos( $e->getMessage(), 'not authorized' ) ) {
			throw $e;
		}
	}

	\DataMachine\Core\Database\Agents\AgentAccess::$allowed = true;
	try {
		$command = new \DataMachine\Cli\Commands\AuthCommand();
		$command->connect( array( 'oauth-bypass' ), array( 'agent' => 'writer' ) );
		throw new \RuntimeException( 'Agent-scoped providers that bypass the helper must fail closed.' );
	} catch ( \RuntimeException $e ) {
		if ( false === strpos( $e->getMessage(), 'did not create agent-bound OAuth state' ) ) {
			throw $e;
		}
	}

	try {
		$command = new \DataMachine\Cli\Commands\AuthCommand();
		$command->connect( array( 'oauth-custom' ), array( 'agent' => 'writer' ) );
		throw new \RuntimeException( 'Custom callback overrides must not opt into agent-scoped OAuth.' );
	} catch ( \RuntimeException $e ) {
		if ( false === strpos( $e->getMessage(), 'does not support agent-scoped OAuth callbacks' ) ) {
			throw $e;
		}
	}

	try {
		$command = new \DataMachine\Cli\Commands\AuthCommand();
		$command->connect( array( 'oauth1' ), array( 'agent' => 'writer' ) );
		throw new \RuntimeException( 'OAuth1 must not support agent-scoped OAuth.' );
	} catch ( \RuntimeException $e ) {
		if ( false === strpos( $e->getMessage(), 'does not support agent-scoped OAuth callbacks' ) ) {
			throw $e;
		}
	}

	echo "auth-cli-agent-scoped-oauth-smoke passed\n";
}
