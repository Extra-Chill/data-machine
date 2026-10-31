<?php
/**
 * Behavior smoke test for BundleSourceAuth (#1830).
 *
 * Run with: php tests/bundle-source-auth-smoke.php
 */

namespace {
	$datamachine_test_abspath = sys_get_temp_dir() . '/datamachine-bundle-auth-test-' . uniqid() . '/';
	@mkdir( $datamachine_test_abspath, 0777, true );
	define( 'ABSPATH', $datamachine_test_abspath );

	register_shutdown_function( function () use ( $datamachine_test_abspath ) {
		if ( is_dir( $datamachine_test_abspath ) ) {
			@rmdir( $datamachine_test_abspath );
		}
	} );

	$GLOBALS['datamachine_test_options'] = array();
	$GLOBALS['datamachine_test_filters'] = array();

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
			$override = $GLOBALS['datamachine_test_filters'][ $hook ] ?? null;
			if ( is_callable( $override ) ) {
				return $override( $value, ...$args );
			}
			return $value;
		}
	}

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): bool {
			$GLOBALS['datamachine_test_added_filters'][ $hook ][] = $cb;
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
			PHP_URL_HOST => 'host',
		);
		$key = $map[ $component ] ?? null;
		return $key && isset( $parts[ $key ] ) ? $parts[ $key ] : null;
	}
}

namespace DataMachine\Engine\Bundle {
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSourceAuthResolverInterface.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSourceResolverInterface.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/GitHubBundleSourceAuthResolver.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/GitHubBundleSourceResolver.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSourceResolverRegistry.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSourceAuth.php';
}

namespace {
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
		$GLOBALS['datamachine_test_filters'] = array();
		$GLOBALS['datamachine_test_options'] = array();
		// Best effort: undo putenv from previous block.
		putenv( 'DATAMACHINE_GITHUB_TOKEN' );
		putenv( 'DATAMACHINE_A8C_GHE_TOKEN' );
	};

	echo "=== BundleSourceAuth Smoke (#1830) ===\n";

	echo "\n[1] token_for() — resolution chain\n";

	$reset();

	// 1. CLI token short-circuits everything.
	putenv( 'DATAMACHINE_GITHUB_TOKEN=from-env' );
	$assert(
		'cli_token wins over env',
		'cli-token' === BundleSourceAuth::token_for( 'https://github.com/o/r/archive/refs/heads/main.zip', array( 'cli_token' => 'cli-token' ) )
	);

	// 2. env wins when no CLI.
	$reset();
	putenv( 'DATAMACHINE_GITHUB_TOKEN=env-token' );
	$assert(
		'env var wins when no CLI',
		'env-token' === BundleSourceAuth::token_for( 'https://github.com/o/r/archive/refs/heads/main.zip' )
	);

	// 3. Constant wins when no env.
	$reset();
	if ( ! defined( 'DATAMACHINE_GITHUB_TOKEN' ) ) {
		define( 'DATAMACHINE_GITHUB_TOKEN', 'const-token' );
	}
	$assert(
		'constant wins when no env',
		'const-token' === BundleSourceAuth::token_for( 'https://github.com/o/r/archive/refs/heads/main.zip' )
	);

	// 4. WP option wins when no env/const.
	// (Constant is already defined above; create a fresh test process surrogate by mocking via filter.)
	// Since we can't undefine the constant, exercise the option path via the filter fallback (no const, no env).
	$reset();
	// Note: const is sticky from the previous test; the option path would only apply when the const is absent.
	// To verify option path is read, test through the filter fallback chain:
	$GLOBALS['datamachine_test_filters']['datamachine_bundle_source_token_for_url'] = function ( $value, $url, $host, $context ) {
		return null !== $value ? $value : 'filter-fallback';
	};
	// Constant DATAMACHINE_GITHUB_TOKEN is still defined → still wins.
	$assert(
		'constant still preferred over filter fallback',
		'const-token' === BundleSourceAuth::token_for( 'https://github.com/o/r/archive/refs/heads/main.zip' )
	);

	// Filter fallback fires for hosts with no env/const slot.
	$reset();
	$GLOBALS['datamachine_test_filters']['datamachine_bundle_source_token_for_url'] = function ( $value, $url, $host, $context ) {
		if ( null !== $value ) {
			return $value;
		}
		return 'sigillo://secret/' . $host;
	};
	$assert(
		'filter fallback fires for non-default host',
		'sigillo://secret/example.com' === BundleSourceAuth::token_for( 'https://example.com/path.zip' )
	);

	echo "\n[2] inject_github_auth() — header injection\n";

	$reset();
	putenv( 'DATAMACHINE_GITHUB_TOKEN=ghp_test' );

	$args = array(
		'headers'                   => array(),
		'datamachine_bundle_source' => array(
			'source'  => 'https://github.com/o/r/archive/refs/heads/main.zip',
			'context' => array(),
		),
	);
	$out = BundleSourceAuth::inject_github_auth( $args, $args['datamachine_bundle_source']['source'], $args['datamachine_bundle_source']['source'] );
	$assert(
		'github.com archive gets Bearer header from env',
		( $out['headers']['Authorization'] ?? '' ) === 'Bearer ghp_test'
	);

	// raw.githubusercontent.com same shape.
	$reset();
	putenv( 'DATAMACHINE_GITHUB_TOKEN=ghp_raw' );
	$args = array(
		'headers'                   => array(),
		'datamachine_bundle_source' => array( 'context' => array() ),
	);
	$out = BundleSourceAuth::inject_github_auth(
		$args,
		'https://raw.githubusercontent.com/o/r/main/agent.json',
		'https://raw.githubusercontent.com/o/r/main/agent.json'
	);
	$assert(
		'raw.githubusercontent.com gets Bearer header',
		( $out['headers']['Authorization'] ?? '' ) === 'Bearer ghp_raw'
	);

	// CLI token short-circuits via context.
	$reset();
	$args = array(
		'headers'                   => array(),
		'datamachine_bundle_source' => array(
			'context' => array( 'cli_token' => 'cli-secret' ),
		),
	);
	$out = BundleSourceAuth::inject_github_auth(
		$args,
		'https://github.com/o/r/archive/refs/heads/main.zip',
		'https://github.com/o/r/archive/refs/heads/main.zip'
	);
	$assert(
		'CLI token wins inside inject_github_auth',
		( $out['headers']['Authorization'] ?? '' ) === 'Bearer cli-secret'
	);

	// Existing Authorization header is respected.
	$reset();
	putenv( 'DATAMACHINE_GITHUB_TOKEN=should-not-overwrite' );
	$args = array(
		'headers'                   => array( 'Authorization' => 'Bearer manual' ),
		'datamachine_bundle_source' => array( 'context' => array() ),
	);
	$out = BundleSourceAuth::inject_github_auth(
		$args,
		'https://github.com/o/r/archive/refs/heads/main.zip',
		'https://github.com/o/r/archive/refs/heads/main.zip'
	);
	$assert(
		'pre-set Authorization header is preserved',
		'Bearer manual' === $out['headers']['Authorization']
	);

	$reset();
	$GLOBALS['datamachine_test_filters']['datamachine_bundle_source_auth_resolvers'] = function ( array $resolvers ) {
		$resolvers[] = new class implements \DataMachine\Engine\Bundle\BundleSourceAuthResolverInterface {
			public function apply( array $args, string $source, string $fetch_url, array $context = array() ): array {
				unset( $source, $context );
				if ( 'https://packages.example/private.zip' === $fetch_url ) {
					$args['headers']['X-Package-Auth'] = 'custom-token';
				}
				return $args;
			}

			public function token_for( string $fetch_url, array $context = array() ): ?string {
				unset( $context );
				return 'https://packages.example/private.zip' === $fetch_url ? 'custom-token' : null;
			}
		};
		return $resolvers;
	};
	$args = array( 'headers' => array(), 'datamachine_bundle_source' => array( 'context' => array() ) );
	$out  = BundleSourceAuth::inject_auth( $args, 'https://packages.example/private.zip', 'https://packages.example/private.zip' );
	$assert(
		'custom auth resolver can attach provider header',
		'custom-token' === ( $out['headers']['X-Package-Auth'] ?? '' )
	);

	// Non-github host with no GHE config → no injection.
	$reset();
	putenv( 'DATAMACHINE_GITHUB_TOKEN=ghp_unused' );
	$args = array(
		'headers'                   => array(),
		'datamachine_bundle_source' => array( 'context' => array() ),
	);
	$out = BundleSourceAuth::inject_github_auth(
		$args,
		'https://example.com/bundle.zip',
		'https://example.com/bundle.zip'
	);
	$assert(
		'non-github host gets no Authorization header',
		empty( $out['headers']['Authorization'] )
	);

	echo "\n[3] inject_github_auth() — GHE host via filter\n";

	$reset();
	putenv( 'DATAMACHINE_A8C_GHE_TOKEN=ghe-tok' );
	$GLOBALS['datamachine_test_filters']['datamachine_bundle_source_ghe_hosts'] = function ( array $hosts ) {
		$hosts['github.a8c.com'] = 'DATAMACHINE_A8C_GHE_TOKEN';
		return $hosts;
	};

	$args = array(
		'headers'                   => array(),
		'datamachine_bundle_source' => array( 'context' => array() ),
	);
	$out = BundleSourceAuth::inject_github_auth(
		$args,
		'https://github.a8c.com/team/brain/archive/refs/heads/main.zip',
		'https://github.a8c.com/team/brain/archive/refs/heads/main.zip'
	);
	$assert(
		'GHE host gets Bearer header from filter-configured env',
		( $out['headers']['Authorization'] ?? '' ) === 'Bearer ghe-tok'
	);

	// A different GHE host (not configured) gets nothing.
	$args = array(
		'headers'                   => array(),
		'datamachine_bundle_source' => array( 'context' => array() ),
	);
	$out = BundleSourceAuth::inject_github_auth(
		$args,
		'https://other-ghe.example.com/x/y/archive/refs/heads/main.zip',
		'https://other-ghe.example.com/x/y/archive/refs/heads/main.zip'
	);
	$assert(
		'unconfigured GHE host gets no header',
		empty( $out['headers']['Authorization'] )
	);

	echo "\n[4] redact_args_for_log()\n";

	$reset();
	$args = array(
		'headers'                   => array(
			'Authorization' => 'Bearer ghp_super_secret',
			'X-Other'       => 'visible',
		),
		'datamachine_bundle_source' => array( 'context' => array( 'cli_token' => 'no-leak' ) ),
	);
	$logged = BundleSourceAuth::redact_args_for_log( $args );
	$assert(
		'Authorization is redacted',
		'[redacted]' === ( $logged['headers']['Authorization'] ?? '' )
	);
	$assert(
		'other headers pass through',
		'visible' === ( $logged['headers']['X-Other'] ?? '' )
	);
	$assert(
		'internal handle is stripped from log payload',
		empty( $logged['datamachine_bundle_source'] )
	);

	echo "\n[5] ghe_hosts() — normalization\n";

	$reset();
	$GLOBALS['datamachine_test_filters']['datamachine_bundle_source_ghe_hosts'] = function ( array $hosts ) {
		return array(
			'  GitHub.A8C.COM '  => '  DATAMACHINE_A8C_GHE_TOKEN  ',
			'empty.host'         => '',
			''                   => 'IGNORED',
			'good.host'          => 'GOOD_TOKEN',
		);
	};

	$hosts = BundleSourceAuth::ghe_hosts();
	$assert(
		'host names lowercased and trimmed',
		isset( $hosts['github.a8c.com'] ) && 'DATAMACHINE_A8C_GHE_TOKEN' === $hosts['github.a8c.com']
	);
	$assert(
		'empty env name dropped',
		! isset( $hosts['empty.host'] )
	);
	$assert(
		'empty host dropped',
		! isset( $hosts[''] )
	);
	$assert(
		'good entries preserved',
		isset( $hosts['good.host'] ) && 'GOOD_TOKEN' === $hosts['good.host']
	);

	echo "\nAssertions: {$assertions}\n";
	echo "PASS\n";
}
