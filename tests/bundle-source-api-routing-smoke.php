<?php
/**
 * Behavior smoke test for BundleSource → api.github.com/zipball routing (#1840).
 *
 * Verifies:
 *
 *   - Web-host archive URLs stay as web-host URLs when no token is configured.
 *   - Web-host archive URLs route to api.github.com/zipball when a token is
 *     available (CLI, env, constant, option, filter — all five chain slots).
 *   - tree/<branch> URLs route to api.github.com/zipball when authenticated.
 *   - archive/<sha>.zip URLs route to api.github.com/zipball/<sha> when authenticated.
 *   - Cross-host redirects (api.github.com → codeload/S3) DO NOT forward the
 *     Authorization header.
 *   - Same-host redirects KEEP the Authorization header.
 *   - ETag parsing handles the api.github.com response shape.
 *   - api.github.com/zipball URLs without a .zip suffix pass extension validation.
 *
 * Run with: php tests/bundle-source-api-routing-smoke.php
 */

namespace {
	$datamachine_test_abspath = sys_get_temp_dir() . '/datamachine-bundle-routing-test-' . uniqid() . '/';
	@mkdir( $datamachine_test_abspath . 'wp-admin/includes', 0777, true );
	@file_put_contents( $datamachine_test_abspath . 'wp-admin/includes/file.php', "<?php\n// test stub\n" );
	define( 'ABSPATH', $datamachine_test_abspath );

	register_shutdown_function( function () use ( $datamachine_test_abspath ) {
		if ( ! is_dir( $datamachine_test_abspath ) ) {
			return;
		}
		foreach ( new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $datamachine_test_abspath, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		) as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}
		@rmdir( $datamachine_test_abspath );
	} );

	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code, string $message ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( mixed $thing ): bool {
			return $thing instanceof WP_Error;
		}
	}

	$GLOBALS['datamachine_test_filters']     = array();
	$GLOBALS['datamachine_test_added_hooks'] = array();
	$GLOBALS['datamachine_test_options']     = array();
	$GLOBALS['datamachine_test_responses']   = array();
	$GLOBALS['datamachine_test_call_log']    = array();

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
			// Run real registered filters (e.g. BundleSourceAuth::inject_github_auth).
			if ( ! empty( $GLOBALS['datamachine_test_added_hooks'][ $hook ] ) ) {
				foreach ( $GLOBALS['datamachine_test_added_hooks'][ $hook ] as $cb ) {
					$value = $cb( $value, ...$args );
				}
			}
			// Then apply per-test override on top so individual tests can mutate
			// the result without unhooking the auth filter.
			$override = $GLOBALS['datamachine_test_filters'][ $hook ] ?? null;
			if ( is_callable( $override ) ) {
				return $override( $value, ...$args );
			}
			return $value;
		}
	}

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): bool {
			$GLOBALS['datamachine_test_added_hooks'][ $hook ][] = $cb;
			return true;
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $key, mixed $default = false ): mixed {
			return $GLOBALS['datamachine_test_options'][ $key ] ?? $default;
		}
	}

	function wp_parse_url( string $url, int $component = -1 ): mixed {
		$parts = parse_url( $url );
		if ( -1 === $component ) {
			return $parts;
		}
		$map = array(
			PHP_URL_HOST   => 'host',
			PHP_URL_SCHEME => 'scheme',
		);
		$key = $map[ $component ] ?? null;
		return $key && isset( $parts[ $key ] ) ? $parts[ $key ] : null;
	}

	function wp_delete_file( string $path ): void {
		if ( file_exists( $path ) ) {
			unlink( $path ); // phpcs:ignore
		}
	}

	function wp_tempnam( string $hint = '' ): string {
		$tmp = tempnam( sys_get_temp_dir(), 'datamachine-routing-stub-' );
		return $tmp ?: '';
	}

	/**
	 * Stub wp_safe_remote_get() that walks a queue of canned responses.
	 *
	 * Each response is keyed by URL prefix; the first matching prefix wins.
	 * If a response has 'redirect_to', return a 302 with Location header.
	 * Otherwise return the configured ('code', 'headers', 'body').
	 */
	if ( ! function_exists( 'wp_safe_remote_get' ) ) {
		function wp_safe_remote_get( string $url, array $args = array() ): mixed {
			$GLOBALS['datamachine_test_call_log'][] = array(
				'url'  => $url,
				'args' => $args,
			);

			foreach ( $GLOBALS['datamachine_test_responses'] as $prefix => $resp ) {
				if ( 0 !== strpos( $url, $prefix ) ) {
					continue;
				}

				if ( ! empty( $resp['redirect_to'] ) ) {
					return array(
						'response' => array( 'code' => $resp['code'] ?? 302 ),
						'headers'  => array( 'location' => $resp['redirect_to'] ),
					);
				}

				$code = (int) ( $resp['code'] ?? 200 );
				if ( $code >= 200 && $code < 300 && ! empty( $args['filename'] ) ) {
					file_put_contents( $args['filename'], $resp['body'] ?? 'PK' );
				}

				return array(
					'response' => array( 'code' => $code ),
					'headers'  => $resp['headers'] ?? array(),
				);
			}

			return new WP_Error( 'no_stub', 'wp_safe_remote_get not stubbed for ' . $url );
		}
	}

	if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
		function wp_remote_retrieve_response_code( $response ): int {
			if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
				return (int) $response['response']['code'];
			}
			return 0;
		}
	}

	if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
		function wp_remote_retrieve_header( $response, string $name ): string {
			if ( is_array( $response ) && isset( $response['headers'] ) && is_array( $response['headers'] ) ) {
				foreach ( $response['headers'] as $k => $v ) {
					if ( 0 === strcasecmp( (string) $k, $name ) ) {
						return (string) $v;
					}
				}
			}
			return '';
		}
	}

	if ( ! defined( 'DATAMACHINE_VERSION' ) ) {
		define( 'DATAMACHINE_VERSION', '0.0.0-test' );
	}
}

namespace DataMachine\Engine\Bundle {
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSourceAuthResolverInterface.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSourceResolverInterface.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/GitHubBundleSourceAuthResolver.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/GitHubBundleSourceResolver.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSourceResolverRegistry.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSourceAuth.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSource.php';
}

namespace {
	use DataMachine\Engine\Bundle\BundleSource;
	use DataMachine\Engine\Bundle\BundleSourceAuth;

	$assertions = 0;
	$assert     = function ( string $label, bool $condition ) use ( &$assertions ): void {
		++$assertions;
		if ( ! $condition ) {
			fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$label}\n" );
			exit( 1 );
		}
		echo "ok - {$label}\n";
	};

	$reset = function () {
		$GLOBALS['datamachine_test_filters']   = array();
		$GLOBALS['datamachine_test_options']   = array();
		$GLOBALS['datamachine_test_responses'] = array();
		$GLOBALS['datamachine_test_call_log']  = array();
		putenv( 'DATAMACHINE_GITHUB_TOKEN' );
	};

	// Register the auth filter once at boot — production behavior is the
	// same (data-machine.php calls register() at plugin bootstrap).
	BundleSourceAuth::register();

	echo "=== BundleSource API Routing Smoke (#1840) ===\n";

	echo "\n[1] normalize_github_url() — no token = web-host URLs unchanged\n";

	$reset();

	$cases = array(
		'archive/refs/heads/<branch>.zip stays web-host (no token)' => array(
			'in'  => 'https://github.com/foo/bar/archive/refs/heads/main.zip',
			'out' => 'https://github.com/foo/bar/archive/refs/heads/main.zip',
		),
		'archive/<sha>.zip stays web-host (no token)' => array(
			'in'  => 'https://github.com/foo/bar/archive/abc1234.zip',
			'out' => 'https://github.com/foo/bar/archive/abc1234.zip',
		),
		'tree/<branch> normalizes to web-host archive (no token)' => array(
			'in'  => 'https://github.com/foo/bar/tree/main',
			'out' => 'https://github.com/foo/bar/archive/refs/heads/main.zip',
		),
		'tree/<branch>/ trailing slash works (no token)' => array(
			'in'  => 'https://github.com/foo/bar/tree/develop/',
			'out' => 'https://github.com/foo/bar/archive/refs/heads/develop.zip',
		),
		'blob URL still routes to raw.githubusercontent.com' => array(
			'in'  => 'https://github.com/foo/bar/blob/main/agent.json',
			'out' => 'https://raw.githubusercontent.com/foo/bar/main/agent.json',
		),
	);

	foreach ( $cases as $label => $case ) {
		$assert( $label, $case['out'] === BundleSource::normalize_github_url( $case['in'] ) );
	}

	echo "\n[2] normalize_github_url() — token = api.github.com/zipball routing\n";

	// 2a. CLI-supplied token in context.
	$reset();
	$ctx = array( 'cli_token' => 'cli-secret' );
	$assert(
		'CLI token routes archive/refs/heads/<branch>.zip → api.github.com/zipball/<branch>',
		'https://api.github.com/repos/foo/bar/zipball/main'
			=== BundleSource::normalize_github_url( 'https://github.com/foo/bar/archive/refs/heads/main.zip', $ctx )
	);
	$assert(
		'CLI token routes archive/<sha>.zip → api.github.com/zipball/<sha>',
		'https://api.github.com/repos/foo/bar/zipball/abc1234'
			=== BundleSource::normalize_github_url( 'https://github.com/foo/bar/archive/abc1234.zip', $ctx )
	);
	$assert(
		'CLI token routes tree/<branch> → api.github.com/zipball/<branch>',
		'https://api.github.com/repos/foo/bar/zipball/main'
			=== BundleSource::normalize_github_url( 'https://github.com/foo/bar/tree/main', $ctx )
	);

	// 2b. Env-var token.
	$reset();
	putenv( 'DATAMACHINE_GITHUB_TOKEN=env-secret' );
	$assert(
		'Env-var token triggers API routing',
		'https://api.github.com/repos/o/r/zipball/main'
			=== BundleSource::normalize_github_url( 'https://github.com/o/r/archive/refs/heads/main.zip' )
	);
	putenv( 'DATAMACHINE_GITHUB_TOKEN' );

	// 2c. Constant token (one-shot define — surrogate via filter fallback below
	// because DATAMACHINE_GITHUB_TOKEN may already be defined globally).
	$reset();
	$GLOBALS['datamachine_test_filters']['datamachine_bundle_source_token_for_url'] = function ( $value, $url, $host, $context ) {
		return null !== $value ? $value : 'filter-secret';
	};
	$assert(
		'Filter-fallback token triggers API routing',
		'https://api.github.com/repos/o/r/zipball/main'
			=== BundleSource::normalize_github_url( 'https://github.com/o/r/archive/refs/heads/main.zip' )
	);

	// 2d. WP option token. The github.com option slot is shared with
	// api.github.com (same DATAMACHINE_GITHUB_TOKEN scope), so an option
	// token also triggers API routing.
	$reset();
	$GLOBALS['datamachine_test_options']['datamachine_bundle_source_github_token'] = 'option-secret';
	$out = BundleSource::normalize_github_url( 'https://github.com/o/r/archive/refs/heads/main.zip' );
	$assert(
		'WP option token triggers API routing (shared github.com / api.github.com slot)',
		'https://api.github.com/repos/o/r/zipball/main' === $out
	);

	echo "\n[3] resolve() — public install (no token) keeps web-host URL\n";

	$reset();
	$GLOBALS['datamachine_test_responses']['https://github.com/foo/bar/archive/refs/heads/main.zip'] = array(
		'code'    => 200,
		'headers' => array( 'ETag' => 'W/"deadbeefdeadbeefdeadbeefdeadbeefdeadbeef:zipball"' ),
		'body'    => 'PK',
	);

	$src      = 'https://github.com/foo/bar/archive/refs/heads/main.zip';
	$resolved = BundleSource::resolve( $src );
	$assert( 'public install resolves successfully', is_string( $resolved ) );
	$assert(
		'public install hit web-host URL exactly once',
		1 === count( $GLOBALS['datamachine_test_call_log'] )
		&& 'https://github.com/foo/bar/archive/refs/heads/main.zip' === $GLOBALS['datamachine_test_call_log'][0]['url']
	);
	$assert(
		'public install carries no Authorization header',
		empty( $GLOBALS['datamachine_test_call_log'][0]['args']['headers']['Authorization'] )
	);
	if ( is_string( $resolved ) ) {
		BundleSource::cleanup( $resolved, $src );
	}

	echo "\n[4] resolve() — authenticated install routes to api.github.com\n";

	$reset();
	$GLOBALS['datamachine_test_responses']['https://api.github.com/repos/foo/bar/zipball/main'] = array(
		'code'    => 200,
		'headers' => array( 'ETag' => 'W/"deadbeefdeadbeefdeadbeefdeadbeefdeadbeef:zipball"' ),
		'body'    => 'PK',
	);
	$resolved = BundleSource::resolve( $src, array( 'cli_token' => 'gh_pat_1234' ) );
	$assert( 'authenticated install resolves successfully', is_string( $resolved ) );
	$assert(
		'authenticated install hit api.github.com/zipball URL',
		1 === count( $GLOBALS['datamachine_test_call_log'] )
		&& 'https://api.github.com/repos/foo/bar/zipball/main' === $GLOBALS['datamachine_test_call_log'][0]['url']
	);
	$assert(
		'authenticated install resolved a tempfile with .zip extension',
		is_string( $resolved ) && preg_match( '/\.zip$/', $resolved )
	);
	$assert(
		'authenticated install captured ETag → SHA',
		'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef' === BundleSource::last_resolved_revision()
	);
	if ( is_string( $resolved ) ) {
		BundleSource::cleanup( $resolved, $src );
	}

	echo "\n[5] resolve() — cross-host redirect strips Authorization header\n";

	$reset();
	$GLOBALS['datamachine_test_responses']['https://api.github.com/repos/foo/bar/zipball/main'] = array(
		'code'        => 302,
		'redirect_to' => 'https://codeload.github.com/foo/bar/legacy.zip/refs/heads/main?token=signed',
	);
	$GLOBALS['datamachine_test_responses']['https://codeload.github.com/'] = array(
		'code'    => 200,
		'headers' => array( 'ETag' => 'W/"deadbeefdeadbeefdeadbeefdeadbeefdeadbeef:zipball"' ),
		'body'    => 'PK',
	);

	$resolved = BundleSource::resolve( $src, array( 'cli_token' => 'gh_pat_redirect' ) );
	$assert( 'cross-host redirect resolves successfully', is_string( $resolved ) );
	$assert(
		'two HTTP calls were made (api.github.com + codeload.github.com)',
		2 === count( $GLOBALS['datamachine_test_call_log'] )
	);
	$assert(
		'first hop carried Authorization: Bearer header',
		( $GLOBALS['datamachine_test_call_log'][0]['args']['headers']['Authorization'] ?? '' ) === 'Bearer gh_pat_redirect'
	);
	$assert(
		'second hop (cross-host) DROPPED Authorization header',
		empty( $GLOBALS['datamachine_test_call_log'][1]['args']['headers']['Authorization'] )
	);
	$assert(
		'second hop hit codeload host',
		0 === strpos( $GLOBALS['datamachine_test_call_log'][1]['url'], 'https://codeload.github.com/' )
	);
	if ( is_string( $resolved ) ) {
		BundleSource::cleanup( $resolved, $src );
	}

	echo "\n[6] resolve() — same-host redirect KEEPS Authorization header\n";

	$reset();
	// api.github.com → api.github.com (e.g. canonicalization). Token must persist.
	$GLOBALS['datamachine_test_responses']['https://api.github.com/repos/foo/bar/zipball/main'] = array(
		'code'        => 302,
		'redirect_to' => 'https://api.github.com/repos/foo/bar/zipball/abcdef0',
	);
	$GLOBALS['datamachine_test_responses']['https://api.github.com/repos/foo/bar/zipball/abcdef0'] = array(
		'code'    => 200,
		'headers' => array(),
		'body'    => 'PK',
	);
	$resolved = BundleSource::resolve( $src, array( 'cli_token' => 'gh_pat_same_host' ) );
	$assert( 'same-host redirect resolves successfully', is_string( $resolved ) );
	$assert(
		'first hop carried Authorization header',
		( $GLOBALS['datamachine_test_call_log'][0]['args']['headers']['Authorization'] ?? '' ) === 'Bearer gh_pat_same_host'
	);
	$assert(
		'second hop (same-host) KEPT Authorization header',
		( $GLOBALS['datamachine_test_call_log'][1]['args']['headers']['Authorization'] ?? '' ) === 'Bearer gh_pat_same_host'
	);
	if ( is_string( $resolved ) ) {
		BundleSource::cleanup( $resolved, $src );
	}

	echo "\n[7] resolve() — too many redirects surfaces a clear error\n";

	$reset();
	$GLOBALS['datamachine_test_responses']['https://api.github.com/repos/foo/bar/zipball/main'] = array(
		'code'        => 302,
		'redirect_to' => 'https://api.github.com/repos/foo/bar/zipball/main',
	);
	// Force a low cap by passing redirection through default args filter.
	$GLOBALS['datamachine_test_filters']['datamachine_bundle_source_download_args'] = function ( array $args, string $source, string $fetch_url ): array {
		$args['redirection'] = 2;
		return $args;
	};
	$err = BundleSource::resolve( $src, array( 'cli_token' => 'gh_pat_loop' ) );
	$assert( 'redirect loop returns WP_Error', is_wp_error( $err ) );
	$assert(
		'redirect loop uses too_many_redirects code',
		$err instanceof WP_Error && 'datamachine_bundle_source_too_many_redirects' === $err->get_error_code()
	);

	echo "\n[8] is_github_api_zipball_url() validation\n";

	$reset();
	$GLOBALS['datamachine_test_responses']['https://api.github.com/repos/foo/bar/zipball/main'] = array(
		'code'    => 200,
		'headers' => array(),
		'body'    => 'PK',
	);
	// Direct API URL with no .zip suffix should pass extension validation.
	$resolved = BundleSource::resolve( 'https://api.github.com/repos/foo/bar/zipball/main' );
	$assert( 'direct api.github.com/zipball URL passes extension validation', is_string( $resolved ) );
	$assert(
		'direct api.github.com URL resolves to a .zip tempfile',
		is_string( $resolved ) && preg_match( '/\.zip$/', $resolved )
	);
	if ( is_string( $resolved ) ) {
		BundleSource::cleanup( $resolved, 'https://api.github.com/repos/foo/bar/zipball/main' );
	}

	echo "\n[9] parse_sha_from_etag() — api.github.com response shape\n";

	// api.github.com/zipball returns: ETag: W/"<sha>" (no :zipball suffix on
	// some shapes) or W/"<sha>:gzip" depending on transfer-encoding. The
	// existing parser already handles bare and :suffix forms.
	$assert(
		'api.github.com bare W/"<sha>" parses',
		'abcdef0123456789abcdef0123456789abcdef01'
			=== BundleSource::parse_sha_from_etag( 'W/"abcdef0123456789abcdef0123456789abcdef01"' )
	);
	$assert(
		'api.github.com W/"<sha>:gzip" parses',
		'abcdef0123456789abcdef0123456789abcdef01'
			=== BundleSource::parse_sha_from_etag( 'W/"abcdef0123456789abcdef0123456789abcdef01:gzip"' )
	);

	echo "\nAssertions: {$assertions}\n";
	echo "PASS\n";
}
