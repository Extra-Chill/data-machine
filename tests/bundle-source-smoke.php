<?php
/**
 * Behavior smoke test for BundleSource resolver (#1826).
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

	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}

	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		return $value;
	}

	function wp_delete_file( string $path ): void {
		if ( file_exists( $path ) ) {
			unlink( $path ); // phpcs:ignore
		}
	}

	// Minimal download_url stub. Tests that exercise the download path
	// override this via $GLOBALS['datamachine_test_download_url'].
	function download_url( string $url, int $timeout = 30 ): mixed {
		$override = $GLOBALS['datamachine_test_download_url'] ?? null;
		if ( is_callable( $override ) ) {
			return $override( $url, $timeout );
		}

		return new WP_Error( 'no_stub', 'download_url is not stubbed in this test.' );
	}

}

namespace DataMachine\Engine\Bundle {
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSource.php';
}

namespace {
	use DataMachine\Engine\Bundle\BundleSource;

	$assertions = 0;
	$assert     = function ( string $label, bool $condition ) use ( &$assertions ): void {
		++$assertions;
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$label}\n" );
			exit( 1 );
		}
		echo "ok - {$label}\n";
	};

	echo "=== BundleSource Smoke (#1826) ===\n";

	echo "\n[1] is_remote()\n";
	$assert( 'http URL is remote', BundleSource::is_remote( 'http://example.com/bundle.zip' ) );
	$assert( 'https URL is remote', BundleSource::is_remote( 'https://example.com/bundle.zip' ) );
	$assert( 'HTTPS uppercase is remote', BundleSource::is_remote( 'HTTPS://example.com/bundle.zip' ) );
	$assert( 'local absolute path is not remote', ! BundleSource::is_remote( '/tmp/bundle.zip' ) );
	$assert( 'local relative path is not remote', ! BundleSource::is_remote( './bundle.zip' ) );
	$assert( 'file:// is not remote (treated as local for safety)', ! BundleSource::is_remote( 'file:///tmp/bundle.zip' ) );
	$assert( 'empty string is not remote', ! BundleSource::is_remote( '' ) );

	echo "\n[2] normalize_github_url() — all documented cases\n";

	// archive/refs/heads/<branch>.zip — unchanged
	$archive_branch = 'https://github.com/foo/bar/archive/refs/heads/main.zip';
	$assert(
		'archive/refs/heads/<branch>.zip is unchanged',
		$archive_branch === BundleSource::normalize_github_url( $archive_branch )
	);

	// archive/<sha>.zip — unchanged (matches generic archive .zip)
	$archive_sha = 'https://github.com/foo/bar/archive/abc123.zip';
	$assert(
		'archive/<sha>.zip is unchanged',
		$archive_sha === BundleSource::normalize_github_url( $archive_sha )
	);

	// blob/<ref>/<path>.json → raw URL
	$blob       = 'https://github.com/foo/bar/blob/main/bundles/agent.json';
	$normalized = BundleSource::normalize_github_url( $blob );
	$assert(
		'blob/<ref>/<path>.json normalizes to raw.githubusercontent.com',
		'https://raw.githubusercontent.com/foo/bar/main/bundles/agent.json' === $normalized
	);

	// blob/<ref>/<path>.zip → raw URL
	$blob_zip = 'https://github.com/foo/bar/blob/main/bundles/agent.zip';
	$assert(
		'blob/<ref>/<path>.zip normalizes to raw',
		'https://raw.githubusercontent.com/foo/bar/main/bundles/agent.zip' === BundleSource::normalize_github_url( $blob_zip )
	);

	// raw/<ref>/<path> → raw.githubusercontent.com
	$raw_legacy = 'https://github.com/foo/bar/raw/main/bundles/agent.json';
	$assert(
		'raw/<ref>/<path> normalizes to raw.githubusercontent.com',
		'https://raw.githubusercontent.com/foo/bar/main/bundles/agent.json' === BundleSource::normalize_github_url( $raw_legacy )
	);

	// already raw.githubusercontent.com → unchanged
	$raw_direct = 'https://raw.githubusercontent.com/foo/bar/main/bundle.json';
	$assert(
		'raw.githubusercontent.com URL is unchanged',
		$raw_direct === BundleSource::normalize_github_url( $raw_direct )
	);

	// tree/<branch> → archive zip
	$tree = 'https://github.com/foo/bar/tree/main';
	$assert(
		'tree/<branch> normalizes to archive zip',
		'https://github.com/foo/bar/archive/refs/heads/main.zip' === BundleSource::normalize_github_url( $tree )
	);

	// tree/<branch>/ trailing slash also handled
	$tree_slash = 'https://github.com/foo/bar/tree/develop/';
	$assert(
		'tree/<branch>/ trailing slash normalizes',
		'https://github.com/foo/bar/archive/refs/heads/develop.zip' === BundleSource::normalize_github_url( $tree_slash )
	);

	// non-github URL → unchanged
	$other = 'https://example.com/bundle.zip';
	$assert(
		'non-github URL is unchanged',
		$other === BundleSource::normalize_github_url( $other )
	);

	// bare repo URL → unchanged (caller will reject as missing extension)
	$bare = 'https://github.com/foo/bar';
	$assert(
		'bare repo URL is unchanged (caller rejects)',
		$bare === BundleSource::normalize_github_url( $bare )
	);

	echo "\n[3] resolve() — local paths\n";

	// Existing local file is returned as-is.
	$tmp_local = tempnam( sys_get_temp_dir(), 'datamachine-bundle-test-' );
	rename( $tmp_local, $tmp_local . '.json' );
	$tmp_local = $tmp_local . '.json';
	file_put_contents( $tmp_local, '{}' );
	$resolved = BundleSource::resolve( $tmp_local );
	$assert( 'existing local .json path resolves as-is', $resolved === $tmp_local );
	wp_delete_file( $tmp_local );

	// Missing local path returns WP_Error.
	$missing = BundleSource::resolve( '/definitely/not/a/real/path/here.json' );
	$assert( 'missing local path returns WP_Error', is_wp_error( $missing ) );
	$assert(
		'missing local path uses datamachine_bundle_source_invalid code',
		$missing instanceof WP_Error && 'datamachine_bundle_source_invalid' === $missing->get_error_code()
	);

	// Empty source returns WP_Error.
	$empty = BundleSource::resolve( '' );
	$assert( 'empty source returns WP_Error', is_wp_error( $empty ) );

	echo "\n[4] resolve() — remote URLs (download_url stubbed)\n";

	// Successful download path returns a temp file.
	$fake_payload = '{"bundle_version":"1","agent":{"agent_slug":"x","agent_name":"X"}}';
	$GLOBALS['datamachine_test_download_url'] = function ( string $url, int $timeout ) use ( $fake_payload ): string {
		$tmp = tempnam( sys_get_temp_dir(), 'datamachine-stub-download-' );
		file_put_contents( $tmp, $fake_payload );
		return $tmp;
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

	// cleanup() removes the temp file.
	BundleSource::cleanup( $resolved, $source );
	$assert( 'cleanup removes temp file for remote source', ! file_exists( $resolved ) );

	// cleanup() is a no-op for local sources (does NOT delete user files).
	$user_file = tempnam( sys_get_temp_dir(), 'datamachine-user-file-' );
	file_put_contents( $user_file, 'do not delete me' );
	BundleSource::cleanup( $user_file, $user_file );
	$assert( 'cleanup is no-op when source is local', file_exists( $user_file ) );
	wp_delete_file( $user_file );

	echo "\n[5] resolve() — error paths\n";

	// download_url returns WP_Error → resolver wraps as datamachine_bundle_source_download_failed.
	$GLOBALS['datamachine_test_download_url'] = function () {
		return new WP_Error( 'http_404', 'Not Found' );
	};
	$err = BundleSource::resolve( 'https://example.com/missing.zip' );
	$assert( 'download_url failure returns WP_Error', is_wp_error( $err ) );
	$assert(
		'download failure uses datamachine_bundle_source_download_failed code',
		$err instanceof WP_Error && 'datamachine_bundle_source_download_failed' === $err->get_error_code()
	);

	// Remote URL without .zip/.json extension is rejected before download.
	$GLOBALS['datamachine_test_download_url'] = function () {
		fwrite( STDERR, "FAIL: download_url should not have been called for unsupported extension\n" );
		exit( 1 );
	};
	$bad_ext = BundleSource::resolve( 'https://example.com/something' );
	$assert( 'remote URL without .zip/.json is rejected', is_wp_error( $bad_ext ) );

	// Bare GitHub repo URL → no normalization, no extension, rejected.
	$bare_repo = BundleSource::resolve( 'https://github.com/foo/bar' );
	$assert( 'bare GitHub repo URL is rejected (no extension)', is_wp_error( $bare_repo ) );

	echo "\n[6] resolve() — GitHub blob/tree URLs round-trip through normalization\n";

	// blob URL pointing at .json should be normalized and downloaded.
	$GLOBALS['datamachine_test_seen_url']    = '';
	$GLOBALS['datamachine_test_download_url'] = function ( string $url, int $timeout ): string {
		$GLOBALS['datamachine_test_seen_url'] = $url;
		$tmp = tempnam( sys_get_temp_dir(), 'datamachine-stub-blob-' );
		file_put_contents( $tmp, '{}' );
		return $tmp;
	};
	$blob_src = 'https://github.com/org/repo/blob/main/agent.json';
	$resolved = BundleSource::resolve( $blob_src );
	$assert( 'GitHub blob URL resolves to a temp file', is_string( $resolved ) );
	$assert(
		'GitHub blob URL is normalized to raw.githubusercontent.com before download',
		'https://raw.githubusercontent.com/org/repo/main/agent.json' === $GLOBALS['datamachine_test_seen_url']
	);
	if ( is_string( $resolved ) ) {
		BundleSource::cleanup( $resolved, $blob_src );
	}

	// tree URL → archive .zip download
	$GLOBALS['datamachine_test_download_url'] = function ( string $url, int $timeout ): string {
		$GLOBALS['datamachine_test_seen_url'] = $url;
		$tmp = tempnam( sys_get_temp_dir(), 'datamachine-stub-tree-' );
		file_put_contents( $tmp, 'PK' );
		return $tmp;
	};
	$tree_src = 'https://github.com/org/repo/tree/main';
	$resolved = BundleSource::resolve( $tree_src );
	$assert( 'GitHub tree URL resolves to a temp file', is_string( $resolved ) );
	$assert(
		'GitHub tree URL is normalized to archive zip before download',
		'https://github.com/org/repo/archive/refs/heads/main.zip' === $GLOBALS['datamachine_test_seen_url']
	);
	if ( is_string( $resolved ) ) {
		BundleSource::cleanup( $resolved, $tree_src );
	}

	echo "\nAssertions: {$assertions}\n";
	echo "PASS\n";
}
