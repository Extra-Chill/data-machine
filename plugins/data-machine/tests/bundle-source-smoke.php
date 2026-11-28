<?php
/**
 * Behavior smoke test for BundleSource resolver (#1826, #1830).
 *
 * Run with: php tests/bundle-source-smoke.php
 */

namespace {
	// Use a throwaway ABSPATH so the require_once in BundleSource resolves
	// against a stub file in /tmp, not inside the plugin tree.
	$datamachine_test_abspath = sys_get_temp_dir() . '/datamachine-bundle-source-test-' . uniqid() . '/';
	@mkdir( $datamachine_test_abspath . 'wp-admin/includes', 0777, true );
	@file_put_contents( $datamachine_test_abspath . 'wp-admin/includes/file.php', "<?php\n// test stub\n" );
	define( 'ABSPATH', $datamachine_test_abspath );

	register_shutdown_function( function () use ( $datamachine_test_abspath ) {
		// Best-effort cleanup of the stub tree.
		$files = @glob( $datamachine_test_abspath . '/*' );
		if ( is_array( $files ) ) {
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
		}
	} );

	// Minimal WP_Error stub.
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

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
			$override = $GLOBALS['datamachine_test_filters'][ $hook ] ?? null;
			if ( is_callable( $override ) ) {
				return $override( $value, ...$args );
			}
			return $value;
		}
	}

	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( string $url, int $component = -1 ): mixed {
			return parse_url( $url, $component );
		}
	}

	function wp_delete_file( string $path ): void {
		if ( file_exists( $path ) ) {
			unlink( $path ); // phpcs:ignore
		}
	}

	function wp_tempnam( string $hint = '' ): string {
		$tmp = tempnam( sys_get_temp_dir(), 'datamachine-stub-' );
		return $tmp ?: '';
	}

	if ( ! function_exists( 'wp_safe_remote_get' ) ) {
		function wp_safe_remote_get( string $url, array $args = array() ): mixed {
			$override = $GLOBALS['datamachine_test_safe_remote_get'] ?? null;
			if ( is_callable( $override ) ) {
				return $override( $url, $args );
			}

			return new WP_Error( 'no_stub', 'wp_safe_remote_get is not stubbed in this test.' );
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

	$assertions = 0;
	$assert     = function ( string $label, bool $condition ) use ( &$assertions ): void {
		++$assertions;
		if ( ! $condition ) {
			fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$label}\n" );
			exit( 1 );
		}
		echo "ok - {$label}\n";
	};

	$reset_stubs = function () {
		$GLOBALS['datamachine_test_filters']           = array();
		$GLOBALS['datamachine_test_safe_remote_get']   = null;
		$GLOBALS['datamachine_test_seen_url']          = '';
		$GLOBALS['datamachine_test_seen_args']         = array();
	};

	echo "=== BundleSource Smoke (#1826 + #1830) ===\n";

	echo "\n[1] is_remote()\n";
	$assert( 'http URL is remote', BundleSource::is_remote( 'http://example.com/bundle.zip' ) );
	$assert( 'https URL is remote', BundleSource::is_remote( 'https://example.com/bundle.zip' ) );
	$assert( 'HTTPS uppercase is remote', BundleSource::is_remote( 'HTTPS://example.com/bundle.zip' ) );
	$assert( 'local absolute path is not remote', ! BundleSource::is_remote( '/tmp/bundle.zip' ) );
	$assert( 'local relative path is not remote', ! BundleSource::is_remote( './bundle.zip' ) );
	$assert( 'file:// is not remote (treated as local for safety)', ! BundleSource::is_remote( 'file:///tmp/bundle.zip' ) );
	$assert( 'empty string is not remote', ! BundleSource::is_remote( '' ) );

	echo "\n[2] normalize_github_url() — all documented cases\n";

	$archive_branch = 'https://github.com/foo/bar/archive/refs/heads/main.zip';
	$assert(
		'archive/refs/heads/<branch>.zip is unchanged',
		$archive_branch === BundleSource::normalize_github_url( $archive_branch )
	);

	$archive_sha = 'https://github.com/foo/bar/archive/abc123.zip';
	$assert(
		'archive/<sha>.zip is unchanged',
		$archive_sha === BundleSource::normalize_github_url( $archive_sha )
	);

	$blob       = 'https://github.com/foo/bar/blob/main/bundles/agent.json';
	$normalized = BundleSource::normalize_github_url( $blob );
	$assert(
		'blob/<ref>/<path>.json normalizes to raw.githubusercontent.com',
		'https://raw.githubusercontent.com/foo/bar/main/bundles/agent.json' === $normalized
	);

	$blob_zip = 'https://github.com/foo/bar/blob/main/bundles/agent.zip';
	$assert(
		'blob/<ref>/<path>.zip normalizes to raw',
		'https://raw.githubusercontent.com/foo/bar/main/bundles/agent.zip' === BundleSource::normalize_github_url( $blob_zip )
	);

	$raw_legacy = 'https://github.com/foo/bar/raw/main/bundles/agent.json';
	$assert(
		'raw/<ref>/<path> normalizes to raw.githubusercontent.com',
		'https://raw.githubusercontent.com/foo/bar/main/bundles/agent.json' === BundleSource::normalize_github_url( $raw_legacy )
	);

	$raw_direct = 'https://raw.githubusercontent.com/foo/bar/main/bundle.json';
	$assert(
		'raw.githubusercontent.com URL is unchanged',
		$raw_direct === BundleSource::normalize_github_url( $raw_direct )
	);

	$tree = 'https://github.com/foo/bar/tree/main';
	$assert(
		'tree/<branch> normalizes to archive zip',
		'https://github.com/foo/bar/archive/refs/heads/main.zip' === BundleSource::normalize_github_url( $tree )
	);

	$tree_slash = 'https://github.com/foo/bar/tree/develop/';
	$assert(
		'tree/<branch>/ trailing slash normalizes',
		'https://github.com/foo/bar/archive/refs/heads/develop.zip' === BundleSource::normalize_github_url( $tree_slash )
	);

	$other = 'https://example.com/bundle.zip';
	$assert(
		'non-github URL is unchanged',
		$other === BundleSource::normalize_github_url( $other )
	);

	$bare = 'https://github.com/foo/bar';
	$assert(
		'bare repo URL is unchanged (caller rejects)',
		$bare === BundleSource::normalize_github_url( $bare )
	);

	$reset_stubs();
	$GLOBALS['datamachine_test_filters']['datamachine_bundle_source_resolvers'] = function ( array $resolvers ) {
		$resolvers[] = new class implements \DataMachine\Engine\Bundle\BundleSourceResolverInterface {
			public function normalize( string $source, array $context = array() ): ?string {
				unset( $context );
				return 'https://packages.example/agent' === $source ? 'https://packages.example/download/agent' : null;
			}

			public function accepts_fetch_url( string $fetch_url ): bool {
				return 'https://packages.example/download/agent' === $fetch_url;
			}

			public function expected_extension( string $fetch_url ): string {
				return 'https://packages.example/download/agent' === $fetch_url ? 'zip' : '';
			}

			public function revision_from_etag( string $fetch_url, string $etag ): ?string {
				unset( $fetch_url );
				return '"custom-revision"' === $etag ? 'custom-revision' : null;
			}
		};
		return $resolvers;
	};
	$assert(
		'custom source resolver can normalize provider URL',
		'https://packages.example/download/agent' === BundleSource::normalize_remote_url( 'https://packages.example/agent' )
	);

	echo "\n[3] resolve() — local paths\n";

	$reset_stubs();

	$tmp_local = tempnam( sys_get_temp_dir(), 'datamachine-bundle-test-' );
	rename( $tmp_local, $tmp_local . '.json' );
	$tmp_local = $tmp_local . '.json';
	file_put_contents( $tmp_local, '{}' );
	$resolved = BundleSource::resolve( $tmp_local );
	$assert( 'existing local .json path resolves as-is', $resolved === $tmp_local );
	$assert( 'last_resolved_revision is null after local resolve', null === BundleSource::last_resolved_revision() );
	wp_delete_file( $tmp_local );

	$missing = BundleSource::resolve( '/definitely/not/a/real/path/here.json' );
	$assert( 'missing local path returns WP_Error', is_wp_error( $missing ) );
	$assert(
		'missing local path uses datamachine_bundle_source_invalid code',
		$missing instanceof WP_Error && 'datamachine_bundle_source_invalid' === $missing->get_error_code()
	);

	$empty = BundleSource::resolve( '' );
	$assert( 'empty source returns WP_Error', is_wp_error( $empty ) );

	echo "\n[4] resolve() — remote URLs (wp_safe_remote_get stubbed)\n";

	$reset_stubs();
	$fake_payload = '{"bundle_version":"1","agent":{"agent_slug":"x","agent_name":"X"}}';
	$GLOBALS['datamachine_test_safe_remote_get'] = function ( string $url, array $args ) use ( $fake_payload ): array {
		$GLOBALS['datamachine_test_seen_url']  = $url;
		$GLOBALS['datamachine_test_seen_args'] = $args;
		if ( ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $fake_payload );
		}
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
		);
	};

	$source   = 'https://example.com/some-bundle.json';
	$resolved = BundleSource::resolve( $source );
	$assert( 'remote .json resolves to a string path', is_string( $resolved ) );
	$assert(
		'remote .json resolved path lives in sys_get_temp_dir',
		is_string( $resolved ) && 0 === strpos( realpath( $resolved ), realpath( sys_get_temp_dir() ) )
	);
	$assert(
		'remote .json resolved path preserves .json extension',
		is_string( $resolved ) && preg_match( '/\.json$/', $resolved )
	);
	$assert(
		'remote .json resolved file contains downloaded payload',
		is_string( $resolved ) && $fake_payload === file_get_contents( $resolved )
	);
	$assert(
		'request was streamed to disk (stream + filename set)',
		( $GLOBALS['datamachine_test_seen_args']['stream'] ?? false ) === true
		&& ! empty( $GLOBALS['datamachine_test_seen_args']['filename'] )
	);
	$assert(
		'no Authorization header is set when no token is available',
		empty( $GLOBALS['datamachine_test_seen_args']['headers']['Authorization'] )
	);

	BundleSource::cleanup( $resolved, $source );
	$assert( 'cleanup removes temp file for remote source', ! file_exists( $resolved ) );

	$user_file = tempnam( sys_get_temp_dir(), 'datamachine-user-file-' );
	file_put_contents( $user_file, 'do not delete me' );
	BundleSource::cleanup( $user_file, $user_file );
	$assert( 'cleanup is no-op when source is local', file_exists( $user_file ) );
	wp_delete_file( $user_file );

	echo "\n[5] resolve() — error paths\n";

	$reset_stubs();
	$GLOBALS['datamachine_test_safe_remote_get'] = function (): WP_Error {
		return new WP_Error( 'http_request_failed', 'connection refused' );
	};
	$err = BundleSource::resolve( 'https://example.com/missing.zip' );
	$assert( 'wp_safe_remote_get failure returns WP_Error', is_wp_error( $err ) );
	$assert(
		'transport failure uses datamachine_bundle_source_download_failed code',
		$err instanceof WP_Error && 'datamachine_bundle_source_download_failed' === $err->get_error_code()
	);

	// 401 → datamachine_bundle_source_auth_required, no token leakage.
	$reset_stubs();
	$GLOBALS['datamachine_test_safe_remote_get'] = function (): array {
		return array(
			'response' => array( 'code' => 401 ),
			'headers'  => array(),
		);
	};
	$auth_err = BundleSource::resolve( 'https://github.com/private-org/private-repo/archive/refs/heads/main.zip' );
	$assert( '401 returns WP_Error', is_wp_error( $auth_err ) );
	$assert(
		'401 uses datamachine_bundle_source_auth_required code',
		$auth_err instanceof WP_Error && 'datamachine_bundle_source_auth_required' === $auth_err->get_error_code()
	);
	$assert(
		'401 message does not include the word "token" verbatim leaked from a header',
		$auth_err instanceof WP_Error && false === strpos( $auth_err->get_error_message(), 'Bearer ' )
	);

	// 403 → same auth-required code.
	$reset_stubs();
	$GLOBALS['datamachine_test_safe_remote_get'] = function (): array {
		return array(
			'response' => array( 'code' => 403 ),
			'headers'  => array(),
		);
	};
	$forbidden = BundleSource::resolve( 'https://github.com/p/r/archive/refs/heads/main.zip' );
	$assert( '403 returns WP_Error', is_wp_error( $forbidden ) );
	$assert(
		'403 uses auth_required code',
		$forbidden instanceof WP_Error && 'datamachine_bundle_source_auth_required' === $forbidden->get_error_code()
	);

	// 500 → datamachine_bundle_source_http_error.
	$reset_stubs();
	$GLOBALS['datamachine_test_safe_remote_get'] = function (): array {
		return array(
			'response' => array( 'code' => 500 ),
			'headers'  => array(),
		);
	};
	$server_err = BundleSource::resolve( 'https://example.com/bundle.zip' );
	$assert( '500 returns WP_Error', is_wp_error( $server_err ) );
	$assert(
		'500 uses datamachine_bundle_source_http_error code',
		$server_err instanceof WP_Error && 'datamachine_bundle_source_http_error' === $server_err->get_error_code()
	);

	// Remote URL without .zip/.json extension is rejected before download.
	$reset_stubs();
	$GLOBALS['datamachine_test_safe_remote_get'] = function () {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: wp_safe_remote_get should not have been called for unsupported extension\n" );
		exit( 1 );
	};
	$bad_ext = BundleSource::resolve( 'https://example.com/something' );
	$assert( 'remote URL without .zip/.json is rejected', is_wp_error( $bad_ext ) );

	$bare_repo = BundleSource::resolve( 'https://github.com/foo/bar' );
	$assert( 'bare GitHub repo URL is rejected (no extension)', is_wp_error( $bare_repo ) );

	echo "\n[6] resolve() — GitHub blob/tree URLs round-trip through normalization\n";

	$reset_stubs();
	$GLOBALS['datamachine_test_safe_remote_get'] = function ( string $url, array $args ): array {
		$GLOBALS['datamachine_test_seen_url'] = $url;
		if ( ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], '{}' );
		}
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
		);
	};
	$blob_src = 'https://github.com/org/repo/blob/main/agent.json';
	$resolved = BundleSource::resolve( $blob_src );
	$assert( 'GitHub blob URL resolves to a temp file', is_string( $resolved ) );
	$assert(
		'GitHub blob URL is normalized to raw.githubusercontent.com before fetch',
		'https://raw.githubusercontent.com/org/repo/main/agent.json' === $GLOBALS['datamachine_test_seen_url']
	);
	if ( is_string( $resolved ) ) {
		BundleSource::cleanup( $resolved, $blob_src );
	}

	$reset_stubs();
	$GLOBALS['datamachine_test_safe_remote_get'] = function ( string $url, array $args ): array {
		$GLOBALS['datamachine_test_seen_url'] = $url;
		if ( ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], 'PK' );
		}
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
		);
	};
	$tree_src = 'https://github.com/org/repo/tree/main';
	$resolved = BundleSource::resolve( $tree_src );
	$assert( 'GitHub tree URL resolves to a temp file', is_string( $resolved ) );
	$assert(
		'GitHub tree URL is normalized to archive zip before fetch',
		'https://github.com/org/repo/archive/refs/heads/main.zip' === $GLOBALS['datamachine_test_seen_url']
	);
	if ( is_string( $resolved ) ) {
		BundleSource::cleanup( $resolved, $tree_src );
	}

	echo "\n[7] resolve() — datamachine_bundle_source_download_args filter\n";

	$reset_stubs();
	$GLOBALS['datamachine_test_filters']['datamachine_bundle_source_download_args'] = function ( array $args, string $source, string $fetch_url ): array {
		$GLOBALS['datamachine_test_filter_source']    = $source;
		$GLOBALS['datamachine_test_filter_fetch_url'] = $fetch_url;
		$args['headers']['X-Custom-Header']           = 'beep';
		return $args;
	};
	$GLOBALS['datamachine_test_safe_remote_get'] = function ( string $url, array $args ): array {
		$GLOBALS['datamachine_test_seen_args'] = $args;
		if ( ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], '{}' );
		}
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
		);
	};
	$src      = 'https://example.com/bundle.json';
	$resolved = BundleSource::resolve( $src );
	$assert( 'filter receives source URL', ( $GLOBALS['datamachine_test_filter_source'] ?? '' ) === $src );
	$assert( 'filter receives fetch URL', ( $GLOBALS['datamachine_test_filter_fetch_url'] ?? '' ) === $src );
	$assert(
		'filter-injected header reaches wp_safe_remote_get',
		( $GLOBALS['datamachine_test_seen_args']['headers']['X-Custom-Header'] ?? '' ) === 'beep'
	);
	$assert(
		'internal datamachine_bundle_source key is stripped before request',
		empty( $GLOBALS['datamachine_test_seen_args']['datamachine_bundle_source'] )
	);
	if ( is_string( $resolved ) ) {
		BundleSource::cleanup( $resolved, $src );
	}

	echo "\n[8] parse_sha_from_etag()\n";

	$assert(
		'W/"<sha>:zipball" parses',
		'abcdef0123456789abcdef0123456789abcdef01' === BundleSource::parse_sha_from_etag( 'W/"abcdef0123456789abcdef0123456789abcdef01:zipball"' )
	);
	$assert(
		'"<sha>" parses',
		'abcdef0123456789abcdef0123456789abcdef01' === BundleSource::parse_sha_from_etag( '"abcdef0123456789abcdef0123456789abcdef01"' )
	);
	$assert(
		'bare hex parses',
		'0123456789abcdef0123456789abcdef01234567' === BundleSource::parse_sha_from_etag( '0123456789abcdef0123456789abcdef01234567' )
	);
	$assert(
		'mixed-case hex normalizes to lowercase',
		'abcdef0123456789abcdef0123456789abcdef01' === BundleSource::parse_sha_from_etag( 'W/"ABCDEF0123456789abcdef0123456789ABCDEF01:tarball"' )
	);
	$assert(
		'short hash returns null',
		null === BundleSource::parse_sha_from_etag( 'W/"abcd:zipball"' )
	);
	$assert(
		'opaque etag returns null',
		null === BundleSource::parse_sha_from_etag( '"some-other-format"' )
	);
	$assert(
		'empty etag returns null',
		null === BundleSource::parse_sha_from_etag( '' )
	);

	echo "\n[9] resolve() — ETag → last_resolved_revision\n";

	$reset_stubs();
	$GLOBALS['datamachine_test_safe_remote_get'] = function ( string $url, array $args ): array {
		if ( ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], 'PK' );
		}
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'ETag' => 'W/"deadbeefdeadbeefdeadbeefdeadbeefdeadbeef:zipball"' ),
		);
	};
	$resolved = BundleSource::resolve( 'https://github.com/foo/bar/archive/refs/heads/main.zip' );
	$assert( 'resolve with ETag returns a path', is_string( $resolved ) );
	$assert(
		'last_resolved_revision returns parsed SHA',
		'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef' === BundleSource::last_resolved_revision()
	);
	if ( is_string( $resolved ) ) {
		BundleSource::cleanup( $resolved, 'https://github.com/foo/bar/archive/refs/heads/main.zip' );
	}

	// Unparseable ETag → null revision.
	$reset_stubs();
	$GLOBALS['datamachine_test_safe_remote_get'] = function ( string $url, array $args ): array {
		if ( ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], 'PK' );
		}
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'ETag' => '"opaque-token"' ),
		);
	};
	$resolved = BundleSource::resolve( 'https://github.com/foo/bar/archive/refs/heads/main.zip' );
	$assert(
		'unparseable ETag yields null revision',
		null === BundleSource::last_resolved_revision()
	);
	if ( is_string( $resolved ) ) {
		BundleSource::cleanup( $resolved, 'https://github.com/foo/bar/archive/refs/heads/main.zip' );
	}

	// last_resolved_revision is reset when next resolve fails before fetch.
	BundleSource::resolve( '' );
	$assert(
		'last_resolved_revision resets on subsequent resolve()',
		null === BundleSource::last_resolved_revision()
	);

	echo "\nAssertions: {$assertions}\n";
	echo "PASS\n";
}
