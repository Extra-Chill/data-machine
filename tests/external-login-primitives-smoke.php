<?php
/**
 * Smoke tests for external login primitives.
 *
 *   php tests/external-login-primitives-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	class WP_Error {
		public function __construct( private string $code, private string $message = '' ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}

	class DataMachine_External_Login_WPDB_Smoke {
		public string $prefix = 'wp_';
		public array $rows = array();
		public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ) {
			unset( $table, $formats, $where_formats );
			$count = 0;
			foreach ( $this->rows as &$row ) {
				if ( $row['owner_type'] === $where['owner_type'] && $row['owner_key_hash'] === $where['owner_key_hash'] ) {
					$row = array_merge( $row, $data );
					++$count;
				}
			}
			return $count;
		}
	}

	$GLOBALS['wpdb'] = new DataMachine_External_Login_WPDB_Smoke();
	$GLOBALS['datamachine_external_login_providers'] = array();

	function __( $text, $domain = null ) { unset( $domain ); return $text; }
	function esc_html__( $text, $domain = null ) { unset( $domain ); return $text; }
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function sanitize_text_field( $value ): string { return trim( (string) $value ); }
	function wp_unslash( $value ) { return $value; }
	function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
	function untrailingslashit( string $value ): string { return rtrim( $value, '/' ); }
	function site_url( string $path = '' ): string { return 'https://example.test' . $path; }
	function home_url( string $path = '' ): string { return 'https://example.test' . $path; }
	function add_action() {}
	function add_filter() {}
	function add_rewrite_rule() {}
	function apply_filters( string $hook, $value ) {
		if ( 'datamachine_external_login_providers' === $hook ) {
			return $GLOBALS['datamachine_external_login_providers'];
		}
		return $value;
	}
}

namespace DataMachine\Abilities\Chat {
	class ChatTranscriptOwner {
		public static function user_owner( int $user_id ): array {
			return array( 'owner_type' => 'user', 'owner_key' => 'user:' . $user_id, 'owner_key_hash' => hash( 'sha256', 'user:' . $user_id ), 'owner_label' => 'User ' . $user_id, 'user_id' => $user_id );
		}
		public static function resolve_for_request( array $input = array(), int $fallback_user_id = 0 ) {
			unset( $fallback_user_id );
			$owner = $input['transcript_owner'] ?? array();
			$key = ( $owner['type'] ?? '' ) . ':' . ( $owner['key'] ?? '' );
			return array( 'owner_type' => $owner['type'] ?? '', 'owner_key' => $key, 'owner_key_hash' => hash( 'sha256', $key ), 'owner_label' => $owner['type'] ?? '', 'user_id' => 0 );
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Core/Auth/ExternalLoginProviderInterface.php';
	require_once dirname( __DIR__ ) . '/inc/Core/Auth/ExternalLoginRouter.php';
	require_once dirname( __DIR__ ) . '/inc/Core/Auth/PrincipalSessionPromoter.php';

	$pass = 0;
	$fail = 0;
	$assert = static function ( string $label, bool $condition ) use ( &$pass, &$fail ): void {
		if ( $condition ) { ++$pass; echo "PASS: {$label}\n"; return; }
		++$fail; echo "FAIL: {$label}\n";
	};

	$provider = new class() implements \DataMachine\Core\Auth\ExternalLoginProviderInterface {
		public function get_slug(): string { return 'demo'; }
		public function get_callback_path(): string { return '/login/demo/callback'; }
		public function handle_external_login_callback( array $request_params ) { return array( 'redirect_to' => $request_params['redirect_to'] ?? 'https://example.test/' ); }
	};
	$GLOBALS['datamachine_external_login_providers'] = array( 'demo' => $provider, 'invalid' => new \stdClass() );

	$assert( 'provider registry filters invalid entries', array( 'demo' ) === array_keys( \DataMachine\Core\Auth\ExternalLoginRouter::providers() ) );
	$assert( 'callback URL resolves from provider', 'https://example.test/login/demo/callback' === \DataMachine\Core\Auth\ExternalLoginRouter::callback_url( 'demo' ) );

	$browser_id = 'browser:abc';
	$GLOBALS['wpdb']->rows[] = array( 'session_id' => 's1', 'user_id' => 1, 'owner_type' => 'browser', 'owner_key_hash' => hash( 'sha256', 'browser:' . $browser_id ), 'owner_label' => 'Browser' );
	$count = \DataMachine\Core\Auth\PrincipalSessionPromoter::promote_browser_to_user( $browser_id, 12 );
	$assert( 'browser sessions promote to user owner', 1 === $count && 'user' === $GLOBALS['wpdb']->rows[0]['owner_type'] && 12 === $GLOBALS['wpdb']->rows[0]['user_id'] );

	echo "\n{$pass} passed, {$fail} failed\n";
	exit( $fail > 0 ? 1 : 0 );
}
