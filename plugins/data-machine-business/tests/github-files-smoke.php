<?php
/**
 * Exercise the real HTTP client and fetch pipeline with in-memory WP transport and storage.
 * Run with: php tests/github-files-smoke.php
 *
 * @package DataMachineBusiness\Tests
 */

namespace DataMachine\Core\Database\ProcessedItems {
	class ProcessedItems {
		public const DEFAULT_CLAIM_TTL_SECONDS = 600;
		public const CLAIM_METADATA_KEY        = '_claim';
		public const DISPOSITION_ID_METADATA_KEY = '_disposition';
		public static array $processed = array();

		public function has_item_been_processed( $step, $type, $id ): bool {
			return isset( self::$processed[ $step ][ $id ] );
		}
		public function get_item_lifecycle_states( $step, $type, $ids ): array {
			return array_intersect_key( self::$processed[ $step ] ?? array(), array_flip( $ids ) );
		}
		public function claim_item_owned( ...$args ): string {
			return 'test-claim';
		}
		public static function disposition_identity( ...$args ): string {
			return hash( 'sha256', implode( ':', $args ) );
		}
	}
}

namespace DataMachine\Core\Database\TrackedItems {
	class TrackedItems {
		private static array $rows = array();

		public function get( $namespace, $id ): ?array {
			return self::$rows[ $namespace ][ $id ] ?? null;
		}
		public function upsert( $row ): array {
			return self::$rows[ $row['namespace'] ][ $row['item_id'] ] = $row;
		}
	}
}

namespace DataMachine\Core {
	class RunMetrics {
		public static function recordStepResult( ...$args ): bool {
			return true;
		}
	}
}

namespace {
	use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
	use DataMachineBusiness\Api\GitHubClient;

	define( 'ABSPATH', __DIR__ );
	define( 'WPINC', 'wp-includes' );

	class WP_Error {
		public function __construct( private string $code, private string $message, private array $data = array() ) {}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}

	function __( $message, $domain ) {
		return $message;
	}
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
	function wp_json_encode( ...$args ) {
		return json_encode( ...$args );
	}
	function plugin_dir_path( $file ) {
		return dirname( $file ) . '/';
	}
	function plugin_dir_url( $file ) {
		return 'https://example.test/wp-content/plugins/data-machine-business/';
	}
	function home_url() {
		return 'https://example.test';
	}
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['hooks'][ $tag ][ $priority ][] = array( $callback, $accepted_args );
	}
	function add_action( ...$args ) {
		add_filter( ...$args );
	}
	function apply_filters( $tag, $value, ...$args ) {
		$hooks = $GLOBALS['hooks'][ $tag ] ?? array();
		ksort( $hooks );
		foreach ( $hooks as $group ) {
			foreach ( $group as list( $callback, $accepted ) ) {
				$value = $callback( ...array_slice( array( $value, ...$args ), 0, $accepted ) );
			}
		}
		return $value;
	}
	function do_action( $tag, ...$args ) {
		foreach ( $GLOBALS['hooks'][ $tag ] ?? array() as $group ) {
			foreach ( $group as list( $callback, $accepted ) ) {
				$callback( ...array_slice( $args, 0, $accepted ) );
			}
		}
	}
	function did_action( $tag ) {
		return 0;
	}
	function doing_action( $tag ) {
		return false;
	}
	function get_site_option( $name, $default ) {
		return $GLOBALS['options'][ $name ] ?? $default;
	}
	function update_site_option( $name, $value ) {
		$GLOBALS['options'][ $name ] = $value;
		return true;
	}
	function wp_salt( $scheme ) {
		return 'github-files-smoke-test-salt';
	}
	function get_transient( $key ) {
		return $GLOBALS['transients'][ $key ] ?? false;
	}
	function set_transient( $key, $value, $ttl ) {
		$GLOBALS['transients'][ $key ] = $value;
	}
	function wp_remote_get( $url, $args ) {
		$prefix = 'https://api.github.com/repos/owner/repo';
		check( str_starts_with( $url, $prefix ), 'Unexpected GitHub URL: ' . $url );
		$route = substr( $url, strlen( $prefix ) );
		$GLOBALS['requests'][] = array( $route, $args );
		check( isset( $GLOBALS['responses'][ $route ] ), 'Unexpected API request: ' . $route );
		list( $status, $body ) = $GLOBALS['responses'][ $route ];
		return array( 'status' => $status, 'body' => json_encode( $body ) );
	}
	function wp_remote_retrieve_body( $response ) {
		return $response['body'];
	}
	function wp_remote_retrieve_response_code( $response ) {
		return $response['status'];
	}
	function wp_remote_retrieve_headers( $response ) {
		return array();
	}
	function check( $condition, $message ) {
		if ( ! $condition ) {
			fwrite( STDERR, $message . "\n" );
			exit( 1 );
		}
	}

	$core = getenv( 'DATA_MACHINE_PATH' ) ?: dirname( __DIR__, 2 ) . '/data-machine';
	spl_autoload_register(
		static function ( $class ) use ( $core ) {
			if ( str_starts_with( $class, 'DataMachineBusiness\\' ) ) {
				$file = dirname( __DIR__ ) . '/inc/' . str_replace( '\\', '/', substr( $class, strlen( 'DataMachineBusiness\\' ) ) ) . '.php';
				if ( is_file( $file ) ) {
					require $file;
				}
				return;
			}
			if ( str_starts_with( $class, 'DataMachine\\' ) ) {
				$file = $core . '/inc/' . str_replace( '\\', '/', substr( $class, strlen( 'DataMachine\\' ) ) ) . '.php';
				if ( is_file( $file ) ) {
					require $file;
				}
			}
		}
	);
	new DataMachineBusiness\Handlers\GitHub\GitHubFetch();
	$registration = apply_filters( 'datamachine_handlers', array(), 'fetch' )['github'];
	check( 'files' === $registration['meta']['data_source'], 'GitHub files source was not registered.' );
	$handler = new $registration['class']();
	$config  = array(
		'repo'          => 'owner/repo',
		'file_path'     => 'docs/',
		'file_pattern'  => '*.md',
		'max_file_size' => 20,
		'flow_id'       => 1,
		'pipeline_id'   => 1,
		'flow_step_id'  => 'github-step',
	);

	function fixture( $commit, $files, $truncated = false, $include_docs = true ) {
		$GLOBALS['requests']  = array();
		$GLOBALS['responses'] = array(
			''                                 => array( 200, array( 'default_branch' => 'release/docs' ) ),
			'/commits/release%2Fdocs'           => array( 200, array( 'sha' => $commit, 'commit' => array( 'tree' => array( 'sha' => 'tree-' . $commit ) ) ) ),
			'/git/trees/tree-' . $commit . '?recursive=1' => array( 200, array( 'truncated' => $truncated, 'tree' => array_merge( $include_docs ? array( array( 'path' => 'docs', 'type' => 'tree' ) ) : array(), $files ) ) ),
		);
	}
	function blob( $path, $sha, $size = 1 ) {
		return array( 'path' => $path, 'sha' => $sha, 'type' => 'blob', 'size' => $size );
	}
	function content( $sha, $body ) {
		$GLOBALS['responses'][ '/git/blobs/' . $sha ] = array( 200, array( 'encoding' => 'base64', 'content' => base64_encode( $body ) ) );
	}
	function run_fetch( $override = array() ) {
		$packets = $GLOBALS['handler']->get_fetch_data( 1, array_replace( $GLOBALS['config'], $override ), '1' );
		return is_wp_error( $packets ) ? $packets : array_map( static fn ( $packet ) => $packet->addTo( array() )[0], $packets );
	}
	function mark_processed( $packet ) {
		ProcessedItems::$processed['github-step'][ $packet['metadata']['item_identifier'] ] = array( 'processed' => true );
	}
	function routes() {
		return array_column( $GLOBALS['requests'], 0 );
	}

	$first = array( blob( 'docs/nested/hello #.md', 'a' ), blob( 'docs/old.md', 'b' ), blob( 'docs/not.txt', 'c' ), blob( 'docs-extra/outside.md', 'd' ), blob( 'docs/large.md', 'e', 21 ) );
	fixture( 'one', $first );
	content( 'a', 'hello' );
	content( 'b', '' );
	$packets = run_fetch();
	check( is_array( $packets ) && 2 === count( $packets ), 'Prefix, glob or size filter picked the wrong files.' );
	check( array( '', '/commits/release%2Fdocs', '/git/trees/tree-one?recursive=1', '/git/blobs/a', '/git/blobs/b' ) === routes(), 'First fetch did not use default branch, pinned tree and matching blobs.' );
	$meta = $packets[0]['metadata'];
	check( 'hello' === $packets[0]['data']['body'] && '' === $packets[1]['data']['body'], 'Blob contents (including empty files) were not emitted.' );
	check( 'nested/hello #.md' === $meta['github_relative_file_path'] && 'one' === $meta['github_commit_sha'] && 'release/docs' === $meta['github_branch'], 'File identity metadata is incomplete.' );
	check( 'https://github.com/owner/repo/blob/one/docs/nested/hello%20%23.md' === $meta['source_url'] && $meta['source_url'] === $meta['github_url'], 'URL is not pinned or encoded.' );
	check( 'github_owner/repo_files_docs/nested/hello #.md_a' === $meta['dedup_key'] && $meta['dedup_key'] === $meta['original_id'], 'DocSync identity is incorrect.' );
	foreach ( $packets as $packet ) {
		check( 'added' === $packet['metadata']['github_change'], 'First run must only emit additions.' );
		mark_processed( $packet );
	}
	check( ! isset( $GLOBALS['requests'][0][1]['headers']['Authorization'] ), 'Public fetch sent an auth header.' );

	fixture( 'one', $first );
	check( array() === run_fetch(), 'An unchanged run emitted processed files.' );
	check( array( '/commits/release%2Fdocs', '/git/trees/tree-one?recursive=1' ) === routes(), 'An unchanged run requested more than commit and tree.' );

	fixture( 'no-docs', array(), false, false );
	$error = run_fetch();
	check( is_wp_error( $error ) && 'github_missing_file_path' === $error->get_error_code(), 'A previously tracked prefix that disappears must fail, not emit mass removals.' );

	$next = array( blob( 'docs/nested/hello #.md', 'f' ) );
	fixture( 'two', $next );
	$GLOBALS['responses']['/git/blobs/f'] = array( 503, array( 'message' => 'temporarily unavailable' ) );
	$error = run_fetch();
	check( is_wp_error( $error ) && 'github_api_error' === $error->get_error_code() && str_contains( $error->get_error_message(), '503' ), 'Non-2xx blob response did not fail the run.' );

	fixture( 'two', $next );
	content( 'f', 'changed' );
	$packets = run_fetch();
	check( is_array( $packets ) && 2 === count( $packets ), 'Expected one removal and one modification after retry.' );
	$meta = $packets[0]['metadata'];
	check( 'removed' === $meta['github_change'] && 'docs/old.md' === $meta['github_file_path'] && 'b' === $meta['github_file_sha'] && 'two' === $meta['github_commit_sha'] && '' === $packets[0]['data']['body'], 'Removal packet is incorrect.' );
	check( 'modified' === $packets[1]['metadata']['github_change'] && 'changed' === $packets[1]['data']['body'], 'Modified file packet is incorrect.' );
	mark_processed( $packets[1] );
	fixture( 'two', $next );
	$packets = run_fetch();
	check( 1 === count( $packets ) && 'removed' === $packets[0]['metadata']['github_change'], 'Unprocessed removals should be retried.' );
	mark_processed( $packets[0] );
	fixture( 'two', $next );
	check( array() === run_fetch(), 'A processed removal was repeated.' );

	fixture( 'two', $next );
	$error = run_fetch( array( 'file_path' => 'missing' ) );
	check( is_wp_error( $error ) && 'github_missing_file_path' === $error->get_error_code(), 'A missing prefix must fail.' );
	check( array() === run_fetch( array( 'file_pattern' => '*.rst' ) ), 'An existing path with no matching glob should succeed empty.' );
	fixture( 'three', array(), true );
	$error = run_fetch();
	check( is_wp_error( $error ) && 'github_tree_truncated' === $error->get_error_code(), 'A truncated tree must fail.' );

	$provider = apply_filters( 'datamachine_auth_providers', array() )['github'];
	check( $provider->save_config( array( 'access_token' => 'test-private-token' ) ), 'Could not save GitHub token.' );
	check( ! str_contains( json_encode( $GLOBALS['options'] ), 'test-private-token' ), 'The GitHub token was stored in the clear.' );
	fixture( 'two', $next );
	content( 'f', 'changed' );
	$packets = run_fetch( array( 'flow_step_id' => 'private-step' ) );
	check( is_array( $packets ) && 1 === count( $packets ) && 'added' === $packets[0]['metadata']['github_change'], 'Private fetch or step isolation failed.' );
	foreach ( $GLOBALS['requests'] as list( $route, $args ) ) {
		check( 'Bearer test-private-token' === ( $args['headers']['Authorization'] ?? '' ), 'Missing bearer token on ' . $route );
	}

	$GLOBALS['responses']['/contents/docs/a%20b.md?ref=release%2Fdocs'] = array( 200, array( 'encoding' => 'base64', 'content' => 'b2s=' ) );
	check( 'ok' === ( new GitHubClient() )->get_file_contents( 'owner/repo', 'docs/a b.md', 'release/docs' ), 'Contents-by-path API failed.' );
	echo "GitHub files smoke passed.\n";
}
