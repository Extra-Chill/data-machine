<?php
/**
 * Smoke test: youtube-search credential resolution and request building.
 *
 * Run with: php tests/youtube-search-credentials-smoke.php
 *
 * @package DataMachineSocials\Tests
 */

namespace DataMachine\Abilities {
	class AuthAbilities {
		public function getProvider( string $key ) {
			return $GLOBALS['yts_provider'];
		}
	}
}

namespace DataMachine\Core {
	class HttpClient {
		public static function get( string $url, array $options = array() ): array {
			$GLOBALS['yts_requests'][] = array( $url, $options );
			return array(
				'success' => true,
				'data'    => '{"items":[{"id":{"videoId":"abc"},"snippet":{"title":"Pils live"}}]}',
			);
		}
	}
}

namespace DataMachineSocials\Abilities {
	abstract class AbstractSocialAbility {
		protected function registerAbility( $callback, bool $flag = false ): void {}
	}
}

namespace DataMachineSocials {
	class PublishAuthorization {
		public static function can_edit(): bool {
			return true;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
	function is_wp_error( $v ): bool {
		return $v instanceof WP_Error;
	}
	function apply_filters( $hook, $value ) {
		return $GLOBALS['yts_filter'][ $hook ] ?? $value;
	}
	function __( $t, $d = '' ) {
		return $t;
	}

	class YTS_Provider {
		public function __construct( public bool $authed, public string $token, public array $config ) {}
		public function is_authenticated(): bool {
			return $this->authed;
		}
		public function get_valid_access_token() {
			return $this->token;
		}
		public function get_config(): array {
			return $this->config;
		}
	}

	require dirname( __DIR__ ) . '/inc/Abilities/YouTube/YouTubeSearchAbility.php';

	use DataMachineSocials\Abilities\YouTube\YouTubeSearchAbility as Y;

	$failures = 0;
	$check    = static function ( bool $cond, string $msg ) use ( &$failures ): void {
		if ( ! $cond ) {
			++$failures;
			fwrite( STDERR, "FAIL: {$msg}\n" );
		}
	};

	// Neither OAuth nor API key: clear not-configured error.
	$GLOBALS['yts_provider'] = new YTS_Provider( false, '', array() );
	$creds                   = Y::resolve_credentials();
	$check( is_wp_error( $creds ) && 'youtube_not_configured' === $creds->get_error_code(), 'no credentials returns youtube_not_configured' );
	$check( is_wp_error( $creds ) && false !== strpos( $creds->get_error_message(), 'API key' ), 'not-configured message tells the operator what to add' );

	// No provider at all behaves the same.
	$GLOBALS['yts_provider'] = null;
	$creds                   = Y::resolve_credentials();
	$check( is_wp_error( $creds ) && 'youtube_not_configured' === $creds->get_error_code(), 'missing provider returns youtube_not_configured' );

	// API key from provider config.
	$GLOBALS['yts_provider'] = new YTS_Provider( false, '', array( 'api_key' => 'KEY123' ) );
	$creds                   = Y::resolve_credentials();
	$check( ! is_wp_error( $creds ) && 'api_key' === $creds['type'] && 'KEY123' === $creds['value'], 'api_key config resolves' );
	$req = Y::build_request( array( 'q' => 'Pils' ), $creds );
	$check( false !== strpos( $req['url'], 'key=KEY123' ) && array() === $req['headers'], 'api key path sends key= and no auth header' );

	// API key via filter.
	$GLOBALS['yts_provider']                              = new YTS_Provider( false, '', array() );
	$GLOBALS['yts_filter']['datamachine_youtube_api_key'] = 'FILTERKEY';
	$creds = Y::resolve_credentials();
	$check( ! is_wp_error( $creds ) && 'FILTERKEY' === $creds['value'], 'api key filter is honored' );
	unset( $GLOBALS['yts_filter'] );

	// OAuth wins when connected.
	$GLOBALS['yts_provider'] = new YTS_Provider( true, 'TOKEN', array( 'api_key' => 'KEY123' ) );
	$creds                   = Y::resolve_credentials();
	$check( ! is_wp_error( $creds ) && 'oauth' === $creds['type'], 'oauth preferred when connected' );
	$req = Y::build_request( array( 'q' => 'Pils' ), $creds );
	$check( 'Bearer TOKEN' === ( $req['headers']['Authorization'] ?? '' ) && false === strpos( $req['url'], 'key=' ), 'oauth path unchanged' );

	// OAuth connected but token refresh failed: fall back to API key.
	$GLOBALS['yts_provider'] = new YTS_Provider( true, '', array( 'api_key' => 'KEY123' ) );
	$creds                   = Y::resolve_credentials();
	$check( ! is_wp_error( $creds ) && 'api_key' === $creds['type'], 'falls back to api key when token is unavailable' );

	// End to end through execute_search with an API key.
	$GLOBALS['yts_provider'] = new YTS_Provider( false, '', array( 'api_key' => 'KEY123' ) );
	$ability                 = new Y();
	$result                  = $ability->execute_search( array( 'query' => 'Pils' ) );
	$check( is_array( $result ) && ! empty( $result['success'] ) && 'abc' === $result['results'][0]['id'], 'execute_search returns results with api key' );
	$check( false !== strpos( $GLOBALS['yts_requests'][0][0] ?? '', 'key=KEY123' ), 'execute_search used the key' );

	if ( $failures ) {
		exit( 1 );
	}
	echo "YouTube search credentials smoke passed.\n";
}
