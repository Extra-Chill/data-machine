<?php
/**
 * Smoke test: datamachine/web-fetch validates hosts, follows redirects
 * safely and returns readable text.
 *
 * Run with: php tests/web-fetch-ability-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- stub.
	}
	function wp_strip_all_tags( $text ) {
		return strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- stub.
	}
	function __( $text ) {
		return $text;
	}
	function add_filter() {
		return true;
	}
	function remove_filter() {
		return true;
	}
}

namespace DataMachine\Core {
	class HttpClient {
		public static function get( $url, $options = array() ) {
			throw new \RuntimeException( 'real HTTP must not be used in the smoke test' );
		}
	}
}

namespace {
	require dirname( __DIR__ ) . '/inc/Abilities/WebFetchAbilities.php';

	use DataMachine\Abilities\WebFetchAbilities as W;

	$failures = 0;
	function wf_check( $cond, $msg ) {
		global $failures;
		if ( ! $cond ) {
			++$failures;
			fwrite( STDERR, "FAIL: {$msg}\n" );
		}
	}
	function wf_code( $result ) {
		return is_wp_error( $result ) ? $result->get_error_code() : 'ok';
	}

	$dns = array(
		'public.example'   => array( '93.184.216.34' ),
		'rebind.example'   => array( '93.184.216.34', '10.0.0.5' ),
		'meta.example'     => array( '169.254.169.254' ),
		'cgnat.example'    => array( '100.64.1.1' ),
		'v6local.example'  => array( 'fd00::1' ),
		'mapped.example'   => array( '::ffff:127.0.0.1' ),
		'redirect.example' => array( '93.184.216.34' ),
	);
	W::$resolver = static function ( $host ) use ( $dns ) {
		return $dns[ $host ] ?? array();
	};

	$requests  = array();
	$responses = array(
		'https://public.example/page'     => array(
			'success'     => true,
			'status_code' => 200,
			'data'        => '<html><head><title>Show &amp; Tell</title><script>evil()</script></head><body><nav>menu</nav><p>Gavin Hamilton live</p><footer>f</footer></body></html>',
		),
		'https://redirect.example/a'      => array(
			'success'     => false,
			'status_code' => 301,
			'headers'     => array( 'Location' => '/b' ),
		),
		'https://redirect.example/b'      => array(
			'success'     => false,
			'status_code' => 302,
			'headers'     => array( 'location' => 'https://public.example/page' ),
		),
		'https://redirect.example/inside' => array(
			'success'     => false,
			'status_code' => 302,
			'headers'     => array( 'location' => 'http://169.254.169.254/latest/meta-data/' ),
		),
		'https://redirect.example/loop'   => array(
			'success'     => false,
			'status_code' => 302,
			'headers'     => array( 'location' => 'https://redirect.example/loop' ),
		),
		'https://public.example/big'      => array(
			'success'     => true,
			'status_code' => 200,
			'data'        => '<p>' . str_repeat( 'a', W::MAX_CONTENT_CHARS + 10 ) . '</p>',
		),
		'https://public.example/404'      => array(
			'success'     => false,
			'status_code' => 404,
			'error'       => 'HTTP 404',
		),
	);
	W::$http_get = static function ( $url, $options ) use ( &$requests, $responses ) {
		$requests[] = array( $url, $options );
		return $responses[ $url ] ?? array(
			'success' => false,
			'error'   => 'unexpected url ' . $url,
		);
	};

	/* Happy path: readable text, title, no script/nav/footer, capped body. */
	$r = W::fetch( 'https://public.example/page' );
	wf_check( 'ok' === wf_code( $r ), 'public page fetches' );
	wf_check( 'Show & Tell' === ( $r['title'] ?? '' ), 'title decoded' );
	$text = (string) ( $r['content'] ?? '' );
	wf_check( false !== strpos( $text, 'Gavin Hamilton live' ) && false === strpos( $text, 'evil' ) && false === strpos( $text, 'menu' ), 'readable text without script or nav' );
	wf_check( W::MAX_RESPONSE_BYTES === ( $requests[0][1]['limit_response_size'] ?? 0 ), 'response size is capped' );

	/* Blocked targets never reach HTTP. */
	$requests = array();
	foreach ( array(
		''                                  => 'web_fetch_url_required',
		'file:///etc/passwd'                => 'web_fetch_invalid_url',
		'ftp://public.example/x'            => 'web_fetch_invalid_url',
		'https://u:p@public.example/'       => 'web_fetch_invalid_url',
		'https://public.example:8443/'      => 'web_fetch_blocked_host',
		'http://localhost/'                 => 'web_fetch_blocked_host',
		'http://db.internal/'               => 'web_fetch_blocked_host',
		'http://127.0.0.1/'                 => 'web_fetch_blocked_host',
		'http://10.1.2.3/'                  => 'web_fetch_blocked_host',
		'http://192.168.1.1/'               => 'web_fetch_blocked_host',
		'http://169.254.169.254/'           => 'web_fetch_blocked_host',
		'http://[::1]/'                     => 'web_fetch_blocked_host',
		'https://meta.example/'             => 'web_fetch_blocked_host',
		'https://cgnat.example/'            => 'web_fetch_blocked_host',
		'https://v6local.example/'          => 'web_fetch_blocked_host',
		'https://mapped.example/'           => 'web_fetch_blocked_host',
		'https://rebind.example/'           => 'web_fetch_blocked_host',
		'https://nowhere.example/'          => 'web_fetch_unresolvable',
	) as $url => $expected ) {
		wf_check( $expected === wf_code( W::fetch( $url ) ), "{$url} => {$expected}" );
	}
	wf_check( array() === $requests, 'blocked URLs never issue a request' );

	/* Redirects: relative and absolute hops followed, each one validated. */
	$r = W::fetch( 'https://redirect.example/a' );
	wf_check( 'ok' === wf_code( $r ) && 'https://public.example/page' === $r['final_url'], 'redirect chain followed to final_url' );
	wf_check( 'https://redirect.example/a' === ( $r['url'] ?? '' ), 'original url preserved' );

	$requests = array();
	wf_check( 'web_fetch_blocked_host' === wf_code( W::fetch( 'https://redirect.example/inside' ) ), 'redirect into metadata IP is blocked' );
	wf_check( 1 === count( $requests ), 'blocked redirect target is never requested' );

	wf_check( 'web_fetch_too_many_redirects' === wf_code( W::fetch( 'https://redirect.example/loop' ) ), 'redirect loop stops' );

	/* Truncation and upstream errors. */
	$r = W::fetch( 'https://public.example/big' );
	wf_check( ! empty( $r['content_truncated'] ) && str_ends_with( $r['content'], '[Content truncated]' ), 'content truncated at the cap' );
	wf_check( 'web_fetch_failed' === wf_code( W::fetch( 'https://public.example/404' ) ), 'HTTP errors surface as web_fetch_failed' );

	/* IP classifier. */
	wf_check( W::is_public_ip( '8.8.8.8' ) && W::is_public_ip( '2606:4700::1111' ), 'public IPs allowed' );
	wf_check( ! W::is_public_ip( 'not-an-ip' ) && ! W::is_public_ip( '0.0.0.0' ) && ! W::is_public_ip( 'fe80::1' ), 'invalid/unspecified/link-local refused' );

	/* The chat tool delegates to the ability implementation. */
	$tool = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Engine/AI/Tools/Global/WebFetch.php' );
	wf_check( false !== strpos( $tool, 'WebFetchAbilities::fetch(' ) && false === strpos( $tool, 'HttpClient::get' ), 'web_fetch tool uses the shared, validated fetch' );

	if ( $failures ) {
		exit( 1 );
	}
	echo "Web fetch ability smoke passed.\n";
}
