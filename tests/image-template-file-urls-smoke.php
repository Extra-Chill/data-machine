<?php
/**
 * Standalone smoke test for public image-template file URLs (#3658).
 *
 * Run with: php tests/image-template-file-urls-smoke.php
 *
 * Uses the real ability, registry, and repository classes with minimal WP
 * stubs and a fixture template, so GD is not required.
 *
 * @package DataMachine\Tests
 */

declare( strict_types = 1 );

define( 'ABSPATH', sys_get_temp_dir() . '/' );

$root = sys_get_temp_dir() . '/datamachine-file-urls-' . bin2hex( random_bytes( 8 ) );
mkdir( $root );
register_shutdown_function( static function () use ( $root ): void {
	$entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $entries as $entry ) {
		if ( $entry->isDir() ) {
			rmdir( $entry->getPathname() );
		} else {
			unlink( $entry->getPathname() );
		}
	}
	rmdir( $root );
} );

function wp_upload_dir(): array {
	return array( 'basedir' => $GLOBALS['root'] . '/uploads', 'baseurl' => 'https://example.test/uploads', 'error' => false );
}
function trailingslashit( $path ): string {
	return rtrim( $path, '/\\' ) . '/';
}
function wp_normalize_path( $path ): string {
	return str_replace( '\\', '/', $path );
}
function wp_generate_uuid4(): string {
	return bin2hex( random_bytes( 16 ) );
}
function sanitize_file_name( $name ): string {
	return preg_replace( '/[^a-zA-Z0-9._-]/', '', $name );
}
function wp_mkdir_p( $path ): bool {
	return is_dir( $path ) || mkdir( $path, 0777, true );
}
function do_action( ...$args ): void {}
function apply_filters( $tag, $value ) {
	return 'datamachine/image_generation/templates' === $tag
		? array( 'file_urls_fixture' => FileUrlsFixtureTemplate::class ) : $value;
}
function WP_Filesystem(): bool {
	return true;
}
function wp_delete_file( $path ): void {
	unlink( $path );
}
class WP_Error {
	public function __construct( public string $code, public string $message, public array $data = array() ) {}
}
class WP_Filesystem_Base {
	public int $copies = 0;
	public bool $fail_copy = false;
	public function copy( $source, $destination, $overwrite = false ): bool {
		++$this->copies;
		return ! $this->fail_copy && copy( $source, $destination );
	}
}
$wp_filesystem = new WP_Filesystem_Base();

$plugin_root = dirname( __DIR__ );
foreach ( array(
	'inc/Core/FilesRepository/DirectoryManager.php',
	'inc/Core/FilesRepository/FilesystemHelper.php',
	'inc/Core/FilesRepository/FileStorage.php',
	'inc/Abilities/Media/TemplateInterface.php',
	'inc/Abilities/Media/TemplateRegistry.php',
	'inc/Abilities/Media/GDRenderer.php',
	'inc/Abilities/Media/ImageTemplateAbilities.php',
) as $file ) {
	require_once $plugin_root . '/' . $file;
}

use DataMachine\Abilities\Media\GDRenderer;
use DataMachine\Abilities\Media\ImageTemplateAbilities;
use DataMachine\Abilities\Media\TemplateInterface;

class FileUrlsFixtureTemplate implements TemplateInterface {
	public function get_id(): string { return 'file_urls_fixture'; }
	public function get_name(): string { return 'File URLs fixture'; }
	public function get_description(): string { return 'Returns fixture image paths.'; }
	public function get_fields(): array { return array(); }
	public function get_default_preset(): string { return ''; }
	public function render( array $data, GDRenderer $renderer, array $options = array() ): array {
		return $data['paths'];
	}
}

$failed = 0;
$total  = 0;
$assert = static function ( string $name, bool $condition ) use ( &$failed, &$total ): void {
	++$total;
	echo ( $condition ? '  PASS: ' : '  FAIL: ' ) . $name . "\n";
	if ( ! $condition ) {
		++$failed;
	}
};
$render = static function ( array $paths, array $extra = array() ): array|WP_Error {
	return ImageTemplateAbilities::renderTemplate( array_merge(
		array( 'template_id' => 'file_urls_fixture', 'data' => array( 'paths' => $paths ) ),
		$extra
	) );
};

// Distinct valid PNG fixtures make index alignment verifiable by content.
$paths = array( $root . '/first.png', $root . '/second.png' );
$png   = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=' );
foreach ( $paths as $index => $path ) {
	file_put_contents( $path, $png . str_repeat( "\0", $index ) );
}
$result = $render( $paths );
$assert( 'default files render succeeds', is_array( $result ) && true === $result['success'] );
$assert( 'local file_paths are unchanged', is_array( $result ) && $paths === $result['file_paths'] );
$urls = is_array( $result ) ? ( $result['file_urls'] ?? array() ) : array();
$assert( 'one URL per path with matching indices', array_keys( $paths ) === array_keys( $urls ) );
foreach ( $urls as $index => $url ) {
	$prefix = wp_upload_dir()['baseurl'] . '/datamachine-files/';
	$stored = str_replace( wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $url );
	$assert( "URL {$index} points to an existing repository file", str_starts_with( $url, $prefix ) && is_file( $stored ) );
	$assert( "URL {$index} preserves corresponding render bytes", is_file( $stored ) && file_get_contents( $paths[ $index ] ) === file_get_contents( $stored ) );
	$assert( "source {$index} remains available", is_file( $paths[ $index ] ) );
}

$existing = wp_upload_dir()['basedir'] . '/datamachine-files/pipeline-7/flow-8/flow-8-files/existing.png';
wp_mkdir_p( dirname( $existing ) );
file_put_contents( $existing, $png );
$copies = $wp_filesystem->copies;
$result = $render( array( $existing ), array( 'output' => 'files' ) );
$assert( 'repository path is not re-copied', $copies === $wp_filesystem->copies );
$assert( 'existing repository path and URL are retained', is_array( $result ) && array( $existing ) === $result['file_paths'] && array( 'https://example.test/uploads/datamachine-files/pipeline-7/flow-8/flow-8-files/existing.png' ) === $result['file_urls'] );

$result = $render( array( $paths[0] ), array( 'context' => array( 'pipeline_id' => 7, 'flow_id' => 8 ) ) );
$assert( 'publishing respects storage context', is_array( $result ) && str_contains( $result['file_urls'][0], '/pipeline-7/flow-8/flow-8-files/' ) );

$wp_filesystem->fail_copy = true;
$result = $render( array( $paths[0] ) );
$assert( 'publishing failure returns an error, not partial URLs', $result instanceof WP_Error && 'template_storage_failed' === $result->code );
$wp_filesystem->fail_copy = false;

$copies = $wp_filesystem->copies;
$result = $render( array( $paths[0] ), array( 'output' => 'cached_file', 'cache' => array( 'bucket' => 'og', 'key' => 'fixture' ) ) );
$assert( 'cached mode still uses cached_urls without file_urls', is_array( $result ) && array( 'https://example.test/uploads/og/fixture.png' ) === $result['cached_urls'] && ! isset( $result['file_urls'] ) );
$assert( 'cached mode bypasses repository publishing', $copies === $wp_filesystem->copies && is_file( $root . '/uploads/og/fixture.png' ) );

$definition = new ReflectionMethod( ImageTemplateAbilities::class, 'render_image_template_definition' );
$schema     = $definition->invoke( null )['output_schema']['properties'];
$assert( 'output schema declares an array of URL strings', 'array' === ( $schema['file_urls']['type'] ?? '' ) && 'string' === ( $schema['file_urls']['items']['type'] ?? '' ) );

echo "\nimage-template-file-urls-smoke: {$failed}/{$total} assertions failed\n";
exit( $failed > 0 ? 1 : 0 );
