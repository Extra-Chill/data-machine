<?php
/**
 * Smoke tests for BrokenImageReferenceAbilities.
 *
 * Covers the load-bearing multisite resolution logic plus the pure parsing
 * helpers. Runs standalone (no WordPress install required) via:
 *
 *   php tests/image-broken-reference-multisite-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

// --- WordPress function stubs ------------------------------------------------

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return max( 0, (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( (string) $value );
	}
}

/**
 * Parse a URL with component support, mirroring wp_parse_url.
 *
 * @param string $url      URL.
 * @param int    $component Component.
 * @return mixed
 */
function wp_parse_url( string $url, int $component = -1 ) {
	$parsed = parse_url( $url );
	if ( ! is_array( $parsed ) ) {
		$parsed = array();
	}
	if ( -1 === $component ) {
		return $parsed;
	}
	$map = array(
		PHP_URL_SCHEME => 'scheme',
		PHP_URL_HOST   => 'host',
		PHP_URL_PORT   => 'port',
		PHP_URL_PATH   => 'path',
		PHP_URL_QUERY  => 'query',
	);
	$key = $map[ $component ] ?? null;
	if ( null === $key ) {
		return null;
	}

	return $parsed[ $key ] ?? null;
}

// --- Multisite blog stack ----------------------------------------------------

$GLOBALS['__dm_current_blog'] = 1;
$GLOBALS['__dm_blog_stack']   = array();

$GLOBALS['__dm_blog_uploads'] = array(
	1 => array(
		'baseurl' => 'https://extrachill.com/wp-content/uploads',
		'basedir' => '/var/www/uploads',
	),
	2 => array(
		'baseurl' => 'https://community.extrachill.com/wp-content/uploads/sites/2',
		'basedir' => '/var/www/uploads/sites/2',
	),
);

$GLOBALS['__dm_blog_home'] = array(
	1 => 'https://extrachill.com',
	2 => 'https://community.extrachill.com',
);

function is_multisite(): bool {
	return true;
}

function get_current_blog_id(): int {
	return $GLOBALS['__dm_current_blog'];
}

function switch_to_blog( int $blog_id ): void {
	$GLOBALS['__dm_blog_stack'][] = $GLOBALS['__dm_current_blog'];
	$GLOBALS['__dm_current_blog'] = $blog_id;
}

function restore_current_blog(): void {
	if ( empty( $GLOBALS['__dm_blog_stack'] ) ) {
		$GLOBALS['__dm_current_blog'] = 1;
		return;
	}
	$GLOBALS['__dm_current_blog'] = array_pop( $GLOBALS['__dm_blog_stack'] );
}

function get_sites( array $args = array() ) {
	if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
		return array( 1, 2 );
	}
	return array();
}

function wp_get_upload_dir(): array {
	$blog_id = $GLOBALS['__dm_current_blog'];
	return $GLOBALS['__dm_blog_uploads'][ $blog_id ] ?? array(
		'baseurl' => '',
		'basedir' => '',
	);
}

function home_url( string $path = '' ): string {
	$blog_id = $GLOBALS['__dm_current_blog'];
	$origin  = $GLOBALS['__dm_blog_home'][ $blog_id ] ?? 'https://extrachill.com';
	return $origin . $path;
}

// --- Fake wpdb with per-blog post fixtures -----------------------------------

class Datamachine_Broken_Image_Wpdb {
	public string $posts = 'wp_posts';

	public function prepare( string $query, ...$args ): array {
		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	public function get_results( array $prepared ): array {
		// Distinguish the single-post lookup (ID = %d, 2 args) from the
		// chunked scan (4 args). For the chunked scan, return the current
		// blog's rows whose ID > last_id.
		$blog_id = $GLOBALS['__dm_current_blog'];
		$rows    = $GLOBALS['__dm_broken_image_rows'][ $blog_id ] ?? array();

		if ( count( $prepared['args'] ) <= 2 ) {
			$target = (int) ( $prepared['args'][0] ?? 0 );
			foreach ( $rows as $row ) {
				if ( (int) $row->ID === $target ) {
					return array( $row );
				}
			}
			return array();
		}

		$last  = (int) ( $prepared['args'][2] ?? 0 );
		$limit = (int) ( $prepared['args'][3] ?? 100 );
		$out   = array_values(
			array_filter(
				$rows,
				static fn( $row ) => (int) $row->ID > $last
			)
		);
		usort( $out, static fn( $a, $b ) => (int) $a->ID <=> (int) $b->ID );

		return array_slice( $out, 0, $limit );
	}

	public function get_row( array $prepared ) {
		$results = $this->get_results( $prepared );
		return $results[0] ?? null;
	}
}

$GLOBALS['wpdb'] = new Datamachine_Broken_Image_Wpdb();

require_once dirname( __DIR__ ) . '/inc/Abilities/Media/BrokenImageReferenceAbilities.php';

use DataMachine\Abilities\Media\BrokenImageReferenceAbilities;

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $message ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( $condition ) {
		echo "  [PASS] {$message}\n";
		return;
	}
	$failures[] = $message;
	echo "  [FAIL] {$message}\n";
};

echo "Broken image reference multisite smoke\n\n";

// --- Pure helper: parse_srcset -----------------------------------------------

echo "parse_srcset:\n";
$urls = BrokenImageReferenceAbilities::parse_srcset( 'https://x/a-480w.jpg 480w, https://x/a-960w.jpg 960w 2x, https://x/a.webp 1x' );
$assert( 3 === count( $urls ), 'srcset splits into 3 candidates' );
$assert( 'https://x/a-480w.jpg' === $urls[0], 'first srcset url strips 480w descriptor' );
$assert( 'https://x/a-960w.jpg' === $urls[1], 'second srcset url strips 960w 2x descriptor' );
$assert( 'https://x/a.webp' === $urls[2], 'third srcset url strips 1x descriptor' );
$assert( array() === BrokenImageReferenceAbilities::parse_srcset( '' ), 'empty srcset yields no urls' );

// --- Pure helper: parse_css_url_list -----------------------------------------

echo "\nparse_css_url_list:\n";
$bg = BrokenImageReferenceAbilities::parse_css_url_list( 'url(/wp-content/uploads/a.jpg) 1x, url("https://x/b.jpg") 2x' );
$assert( 2 === count( $bg ), 'data-bgset extracts two url(...) candidates' );
$assert( '/wp-content/uploads/a.jpg' === $bg[0], 'first css url path extracted' );
$assert( 'https://x/b.jpg' === $bg[1], 'second css url with quotes extracted' );

// --- Pure helper: normalize_image_url ----------------------------------------

echo "\nnormalize_image_url:\n";
$origin = 'https://extrachill.com';
$assert( 'https://extrachill.com/wp-content/uploads/x.jpg' === BrokenImageReferenceAbilities::normalize_image_url( '/wp-content/uploads/x.jpg', $origin ), 'root-relative url resolves to origin' );
$assert( 'https://community.extrachill.com/foo.jpg' === BrokenImageReferenceAbilities::normalize_image_url( '//community.extrachill.com/foo.jpg', $origin ), 'protocol-relative url gets scheme' );
$assert( 'https://extrachill.com/a.jpg' === BrokenImageReferenceAbilities::normalize_image_url( 'a.jpg', $origin ), 'bare relative path resolves' );
$assert( '' === BrokenImageReferenceAbilities::normalize_image_url( 'data:image/png;base64,abc', $origin ), 'data uri is dropped' );
$assert( '' === BrokenImageReferenceAbilities::normalize_image_url( 'mailto:foo@bar.com', $origin ), 'mailto is dropped' );
$assert( 'https://x/y.jpg' === BrokenImageReferenceAbilities::normalize_image_url( 'https://x/y.jpg', $origin ), 'absolute url is unchanged' );

// --- Pure helper: extract_image_urls_from_html -------------------------------

echo "\nextract_image_urls_from_html:\n";
$html = '<p><img src="https://x/a.jpg" srcset="https://x/a-480w.jpg 480w, https://x/a-960w.jpg 960w" data-src="https://x/b.jpg" data-lazy-src="https://x/c.jpg" data-srcset="https://x/d.jpg 1x" alt="hi"><img data-bg="https://x/bg.jpg" data-bgset="url(https://x/bg2.jpg)"></p>';
$refs = BrokenImageReferenceAbilities::extract_image_urls_from_html( $html );
$urls_found = array();
foreach ( $refs as $ref ) {
	$urls_found[] = $ref['url'] . '@' . $ref['attribute'];
}
$assert( in_array( 'https://x/a.jpg@src', $urls_found, true ), 'src attribute captured' );
$assert( in_array( 'https://x/a-480w.jpg@srcset', $urls_found, true ), 'srcset 480w candidate captured' );
$assert( in_array( 'https://x/a-960w.jpg@srcset', $urls_found, true ), 'srcset 960w candidate captured' );
$assert( in_array( 'https://x/b.jpg@data-src', $urls_found, true ), 'data-src lazy attribute captured' );
$assert( in_array( 'https://x/c.jpg@data-lazy-src', $urls_found, true ), 'data-lazy-src attribute captured' );
$assert( in_array( 'https://x/d.jpg@data-srcset', $urls_found, true ), 'data-srcset candidate captured' );
$assert( in_array( 'https://x/bg.jpg@data-bg', $urls_found, true ), 'data-bg attribute captured' );
$assert( in_array( 'https://x/bg2.jpg@data-bgset', $urls_found, true ), 'data-bgset url() captured' );
$assert( array() === BrokenImageReferenceAbilities::extract_image_urls_from_html( 'no images here' ), 'html without imgs yields nothing' );

// data uri inside src is present but later dropped by normalize; confirm extraction still sees it.
$data_refs = BrokenImageReferenceAbilities::extract_image_urls_from_html( '<img src="data:image/png;base64,xx">' );
$assert( 1 === count( $data_refs ), 'data-uri src is extracted (normalize drops it downstream)' );

// --- Pure helper: resolve_url_to_local ---------------------------------------

echo "\nresolve_url_to_local (multisite + cross-site):\n";
$blog_uploads = array(
	1 => array(
		'baseurl' => 'https://extrachill.com/wp-content/uploads',
		'basedir' => '/var/www/uploads',
	),
	2 => array(
		'baseurl' => 'https://community.extrachill.com/wp-content/uploads/sites/2',
		'basedir' => '/var/www/uploads/sites/2',
	),
);

$r1 = BrokenImageReferenceAbilities::resolve_url_to_local( 'https://extrachill.com/wp-content/uploads/2024/01/a.jpg', $blog_uploads, 1, 'https://extrachill.com' );
$assert( 'local' === $r1['kind'], 'same-site upload resolves as local' );
$assert( 1 === $r1['blog_id'], 'same-site upload maps to blog 1' );
$assert( DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . '2024' . DIRECTORY_SEPARATOR . '01' . DIRECTORY_SEPARATOR . 'a.jpg' === $r1['path'], 'same-site path is basedir + relative' );

$r2 = BrokenImageReferenceAbilities::resolve_url_to_local( 'https://community.extrachill.com/wp-content/uploads/sites/2/2024/02/cross.jpg', $blog_uploads, 1, 'https://extrachill.com' );
$assert( 'local' === $r2['kind'], 'cross-site upload resolves as local' );
$assert( 2 === $r2['blog_id'], 'cross-site upload maps to blog 2 even when scanned from blog 1' );
$assert( DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . '2' . DIRECTORY_SEPARATOR . '2024' . DIRECTORY_SEPARATOR . '02' . DIRECTORY_SEPARATOR . 'cross.jpg' === $r2['path'], 'cross-site path uses blog 2 basedir' );

$rext = BrokenImageReferenceAbilities::resolve_url_to_local( 'https://cdn.example.com/img.jpg', $blog_uploads, 1, 'https://extrachill.com' );
$assert( 'external' === $rext['kind'], 'off-network host is external' );
$assert( null === $rext['path'], 'external has no path' );

// Subdirectory-style longest-prefix correctness: a host on network but a path
// that merely shares a prefix string should not match.
$r_prefix = BrokenImageReferenceAbilities::resolve_url_to_local( 'https://extrachill.com/wp-content/uploads-other/x.jpg', $blog_uploads, 1, 'https://extrachill.com' );
$assert( 'external' === $r_prefix['kind'] || 'unresolvable' === $r_prefix['kind'], 'shared-prefix sibling directory does not falsely match upload base' );

// --- Full orchestration: diagnoseBrokenImageReferences -----------------------

echo "\ndiagnoseBrokenImageReferences (full scan):\n";

$GLOBALS['__dm_broken_image_rows'] = array(
	1 => array(
		(object) array(
			'ID'           => 10,
			'post_title'   => 'Post on main site',
			'post_content' => '<img src="https://extrachill.com/wp-content/uploads/2024/01/missing.jpg">'
				. '<img srcset="https://extrachill.com/wp-content/uploads/2024/01/present.jpg 480w, https://extrachill.com/wp-content/uploads/2024/01/missing.jpg 960w">'
				. '<img data-src="https://community.extrachill.com/wp-content/uploads/sites/2/2024/02/cross_missing.jpg">'
				. '<img src="https://cdn.example.com/external.jpg">'
				. '<img src="data:image/png;base64,deadbeef">'
				. '<img src="/wp-content/uploads/2024/01/rootrelative_missing.jpg">',
		),
		(object) array(
			'ID'           => 11,
			'post_title'   => 'Post with present cross-site',
			'post_content' => '<img src="https://community.extrachill.com/wp-content/uploads/sites/2/2024/02/cross_present.jpg">',
		),
	),
	2 => array(),
);

// file_exists stub: only "present" paths exist.
$present = array(
	'/var/www/uploads/2024/01/present.jpg',
	'/var/www/uploads/sites/2/2024/02/cross_present.jpg',
);
$sep        = DIRECTORY_SEPARATOR;
$present_n  = array_map( static fn( $p ) => str_replace( '/', $sep, $p ), $present );
$file_check = static function ( string $path ) use ( $present_n ): bool {
	return in_array( $path, $present_n, true );
};

$result = BrokenImageReferenceAbilities::diagnoseBrokenImageReferences(
	array(
		'post_type' => 'post',
		'network'   => true,
	),
	$file_check
);

$assert( true === $result['success'], 'network scan returns success' );
$assert( 2 === $result['posts_scanned'], 'two posts scanned (blog 2 has no posts)' );

$broken = $result['broken_references'];
$urls   = array();
foreach ( $broken as $b ) {
	$urls[] = $b['url'];
}

$missing_main = 'https://extrachill.com/wp-content/uploads/2024/01/missing.jpg';
$cross_missing = 'https://community.extrachill.com/wp-content/uploads/sites/2/2024/02/cross_missing.jpg';
$root_missing  = 'https://extrachill.com/wp-content/uploads/2024/01/rootrelative_missing.jpg';

$assert( in_array( $missing_main, $urls, true ), 'missing main-site file reported' );
$assert( in_array( $cross_missing, $urls, true ), 'missing cross-site (blog 2) file reported from a blog 1 post' );
$assert( in_array( $root_missing, $urls, true ), 'root-relative missing file reported' );
$assert( ! in_array( 'https://cdn.example.com/external.jpg', $urls, true ), 'external url is not reported as missing local' );

// Dedup: missing.jpg appears in src AND srcset but must be reported once.
$duplicate_count = 0;
foreach ( $broken as $b ) {
	if ( $missing_main === $b['url'] ) {
		++$duplicate_count;
	}
}
$assert( 1 === $duplicate_count, 'duplicate url within a post is deduplicated to one report' );

// Cross-site host_blog attribution.
foreach ( $broken as $b ) {
	if ( $cross_missing === $b['url'] ) {
		$assert( 2 === $b['host_blog'], 'cross-site missing reference attributes host_blog=2 (owning site)' );
		$assert( 1 === $b['blog_id'], 'cross-site missing reference keeps blog_id=1 (post site)' );
	}
}

// Present files are NOT reported.
$assert( ! in_array( 'https://extrachill.com/wp-content/uploads/2024/01/present.jpg', $urls, true ), 'present main-site file is not reported' );
$assert( ! in_array( 'https://community.extrachill.com/wp-content/uploads/sites/2/2024/02/cross_present.jpg', $urls, true ), 'present cross-site file is not reported' );

// references_found counts deduped local+external refs that were processed
// (data: uri is dropped at normalize, so it is never counted).
$assert( $result['references_found'] >= 4, 'references_found counts deduped candidates' );

// --- Single-site (no network) scan -------------------------------------------

echo "\ndiagnoseBrokenImageReferences (single-site scan):\n";
$GLOBALS['__dm_current_blog'] = 1;
$GLOBALS['__dm_blog_stack']   = array();

$single = BrokenImageReferenceAbilities::diagnoseBrokenImageReferences(
	array(
		'post_type' => 'post',
		'network'   => false,
	),
	$file_check
);

$assert( true === $single['success'], 'single-site scan returns success' );
$assert( 2 === $single['posts_scanned'], 'single-site scan reads the current blog posts' );

// --- single post_id scope ----------------------------------------------------

echo "\ndiagnoseBrokenImageReferences (single post scope):\n";
$GLOBALS['__dm_current_blog'] = 1;
$GLOBALS['__dm_blog_stack']   = array();

$one = BrokenImageReferenceAbilities::diagnoseBrokenImageReferences(
	array(
		'post_id' => 11,
	),
	$file_check
);

$assert( true === $one['success'], 'single post scan returns success' );
$assert( 1 === $one['posts_scanned'], 'single post scan reads exactly one post' );
$assert( 0 === $one['broken_count'], 'post 11 has only a present file' );

// --- blog stack is always restored -------------------------------------------

$GLOBALS['__dm_current_blog'] = 1;
$GLOBALS['__dm_blog_stack']   = array();
BrokenImageReferenceAbilities::diagnoseBrokenImageReferences( array( 'network' => true ), $file_check );
$assert( 1 === get_current_blog_id(), 'current blog is restored after a network scan' );
$assert( empty( $GLOBALS['__dm_blog_stack'] ), 'blog stack is balanced after a network scan' );

echo "\n" . $assertions . ' assertions, ' . count( $failures ) . " failures\n";

if ( ! empty( $failures ) ) {
	exit( 1 );
}

echo "Broken image reference multisite smoke passed.\n";
