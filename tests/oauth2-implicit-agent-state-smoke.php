<?php
/**
 * Behavioral coverage for implicit OAuth state propagation and agent storage.
 *
 * Run with: php tests/oauth2-implicit-agent-state-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace AgentsAPI\AI {
	class WP_Agent_Execution_Principal {
		const REQUEST_CONTEXT_REST = 'rest';
	}
}

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static ?int $agent_id = null;
		public static int $user_id = 0;

		public static function run_as_agent_context( int $agent_id, int $user_id, callable $callback, string $request_context = 'rest' ) {
			unset( $request_context );
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
}

namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	define( 'WPINC', 'wp-includes' );
	define( 'MINUTE_IN_SECONDS', 60 );
	$GLOBALS['oauth2_implicit_transients'] = array();

	function __( string $text ): string { return $text; }
	function esc_html__( string $text ): string { return $text; }
	function esc_html( string $text ): string { return $text; }
	function sanitize_text_field( $value ): string { return trim( (string) $value ); }
	function wp_unslash( $value ) { return $value; }
	function wp_create_nonce( string $action ): string { return 'nonce-' . $action; }
	function wp_verify_nonce( string $nonce, string $action ): bool { return $nonce === 'nonce-' . $action; }
	function wp_json_encode( $value ): string { return json_encode( $value ); }
	function admin_url( string $path ): string { return 'https://site.test/' . $path; }
	function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
	function do_action( ...$args ): void { unset( $args ); }
	function absint( $value ): int { return abs( (int) $value ); }
	function maybe_serialize( $value ): string { return serialize( $value ); }
	function set_transient( string $key, $value, int $expiration ): bool { $GLOBALS['oauth2_implicit_transients'][ $key ] = $value; return true; }
	function get_transient( string $key ) { return $GLOBALS['oauth2_implicit_transients'][ $key ] ?? false; }
	function delete_transient( string $key ): bool { unset( $GLOBALS['oauth2_implicit_transients'][ $key ] ); return true; }
	function is_wp_error( $value ): bool { return false; }

	require_once dirname( __DIR__ ) . '/inc/Core/OAuth/OAuthRedirects.php';
	require_once dirname( __DIR__ ) . '/inc/Core/OAuth/OAuth2Handler.php';

	$mode = $argv[1] ?? '';
	if ( 'render' === $mode ) {
		$_GET = array( 'state' => 'state<safe>' );
		( new \DataMachine\Core\OAuth\OAuth2Handler() )->render_implicit_callback_page( 'implicit-test', 'https://site.test/callback' );
	}

	if ( 'post' === $mode || 'invalid' === $mode ) {
		$handler = new \DataMachine\Core\OAuth\OAuth2Handler();
		$state   = 'post' === $mode ? $handler->create_state( 'implicit-test', array( 'agent_id' => 303, 'user_id' => 77 ) ) : '';
		$_POST   = array(
			'_wpnonce'                  => 'nonce-datamachine_implicit_implicit-test',
			'datamachine_implicit_flow' => '1',
			'access_token'              => 'fragment-token',
			'state'                     => $state,
		);
		\DataMachine\Abilities\PermissionHelper::$agent_id = 111;
		\DataMachine\Abilities\PermissionHelper::$user_id  = 22;
		$report = $argv[2] ?? '';
		register_shutdown_function(
			static function () use ( $report ): void {
				$existing = is_file( $report ) ? json_decode( (string) file_get_contents( $report ), true ) : array();
				$existing['restored_agent_id'] = \DataMachine\Abilities\PermissionHelper::$agent_id;
				$existing['restored_user_id']  = \DataMachine\Abilities\PermissionHelper::$user_id;
				file_put_contents( $report, json_encode( $existing ) );
			}
		);
		$handler->handle_implicit_callback(
			'implicit-test',
			static fn( array $token ): array => array( 'access_token' => $token['access_token'] ),
			static function ( array $account ) use ( $report ): bool {
				file_put_contents(
					$report,
					json_encode(
						array(
							'account'  => $account,
							'agent_id' => \DataMachine\Abilities\PermissionHelper::$agent_id,
							'user_id'  => \DataMachine\Abilities\PermissionHelper::$user_id,
						)
					)
				);
				return true;
			}
		);
	}

	$render = shell_exec( escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' render' );
	if ( false === strpos( (string) $render, 'var state = params.get("state");' ) || false === strpos( (string) $render, 'data.append("state", state);' ) ) {
		throw new \RuntimeException( 'Implicit callback render did not forward state from the OAuth fragment.' );
	}

	$report = tempnam( sys_get_temp_dir(), 'oauth2-implicit-' );
	if ( false === $report ) {
		throw new \RuntimeException( 'Could not allocate implicit callback test report.' );
	}
	shell_exec( escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' post ' . escapeshellarg( $report ) );
	$result = json_decode( (string) file_get_contents( $report ), true );
	@unlink( $report );
	if ( 303 !== ( $result['agent_id'] ?? null ) || 77 !== ( $result['user_id'] ?? null ) || 111 !== ( $result['restored_agent_id'] ?? null ) || 22 !== ( $result['restored_user_id'] ?? null ) ) {
		throw new \RuntimeException( 'Implicit callback did not store under the verified agent principal and restore context.' );
	}

	$invalid_report = tempnam( sys_get_temp_dir(), 'oauth2-implicit-invalid-' );
	$invalid        = shell_exec( escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' invalid ' . escapeshellarg( (string) $invalid_report ) );
	if ( false !== $invalid_report ) {
		@unlink( $invalid_report );
	}
	if ( false === strpos( (string) $invalid, 'Invalid OAuth state.' ) ) {
		throw new \RuntimeException( 'Implicit callback accepted missing OAuth state.' );
	}

	echo "oauth2-implicit-agent-state-smoke passed\n";
}
