<?php
/**
 * Smoke test for the core FlowDiagramTemplate.
 *
 * Asserts:
 *  - implements TemplateInterface and reports the expected contract
 *  - renders a nodes/edges spec to a valid PNG file
 *  - handles box/diamond/oval shapes and edge labels
 *  - GDRenderer::draw_rounded_rect() works without imagefilledroundedrectangle()
 *    (the PHP 8.4+ builtin) so nodes render on PHP 8.0–8.3
 *  - registered on the core datamachine/image_generation/templates filter
 *
 * Run with: php tests/flow-diagram-template-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types = 1 );

$failed = 0;
$total  = 0;

$assert = static function ( string $name, bool $condition ) use ( &$failed, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}
	echo "  FAIL: {$name}\n";
	++$failed;
};

$plugin_root = dirname( __DIR__ );

// -------------------------------------------------------------------------
// Source-string assertions (hold in every backend, incl. real WordPress).
// -------------------------------------------------------------------------

$bootstrap     = (string) file_get_contents( $plugin_root . '/inc/bootstrap.php' );
$gd_source     = (string) file_get_contents( $plugin_root . '/inc/Abilities/Media/GDRenderer.php' );
$tmpl_source   = (string) file_get_contents( $plugin_root . '/inc/Abilities/Media/Templates/FlowDiagramTemplate.php' );

$assert(
	'bootstrap registers flow_diagram on the core template filter',
	str_contains( $bootstrap, "'datamachine/image_generation/templates'" )
		&& str_contains( $bootstrap, 'FlowDiagramTemplate::class' )
);

$assert(
	'draw_rounded_rect guards imagefilledroundedrectangle for PHP < 8.4',
	str_contains( $gd_source, "function_exists( 'imagefilledroundedrectangle' )" )
);

$assert(
	'FlowDiagramTemplate implements TemplateInterface',
	str_contains( $tmpl_source, 'implements TemplateInterface' )
);

// -------------------------------------------------------------------------
// Behavioral: render a real diagram (skipped if GD/freetype unavailable).
// -------------------------------------------------------------------------

if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagettftext' ) ) {
	echo "\nGD/freetype unavailable — behavioral render assertions skipped.\n";
	if ( $failed > 0 ) {
		fwrite( fopen( 'php://stderr', 'w' ), "\nflow-diagram-template-smoke: {$failed}/{$total} assertions failed\n" );
		exit( 1 );
	}
	echo "All {$total} flow-diagram-template source assertions passed.\n";
	return;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ) {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$rest ) {
		return $value;
	}
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		if ( is_string( $file ) && file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore
		}
	}
}
if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory() {
		return '/tmp/datamachine-no-theme';
	}
}

require_once $plugin_root . '/inc/Abilities/Media/PlatformPresets.php';
require_once $plugin_root . '/inc/Abilities/Media/TemplateInterface.php';
require_once $plugin_root . '/inc/Abilities/Media/GDRenderer.php';
require_once $plugin_root . '/inc/Abilities/Media/Templates/FlowDiagramTemplate.php';

use DataMachine\Abilities\Media\GDRenderer;
use DataMachine\Abilities\Media\TemplateInterface;
use DataMachine\Abilities\Media\Templates\FlowDiagramTemplate;

$template = new FlowDiagramTemplate();

$assert( 'instance is a TemplateInterface', $template instanceof TemplateInterface );
$assert( 'get_id() returns flow_diagram', $template->get_id() === 'flow_diagram' );
$assert( 'nodes field is required', ! empty( $template->get_fields()['nodes']['required'] ) );
$assert( 'default preset declared', $template->get_default_preset() !== '' );

$spec = array(
	'title'     => 'Smoke Test Flow',
	'direction' => 'horizontal',
	'nodes'     => array(
		array( 'id' => 'a', 'label' => 'Start',           'shape' => 'oval' ),
		array( 'id' => 'b', 'label' => 'Decision?',        'shape' => 'diamond' ),
		array( 'id' => 'c', 'label' => 'a-very-long-single-token-node-label', 'shape' => 'box', 'color' => '#16a34a' ),
	),
	'edges'     => array(
		array( 'from' => 'a', 'to' => 'b', 'label' => 'go' ),
		array( 'from' => 'b', 'to' => 'c' ),
	),
);

$paths = $template->render( $spec, new GDRenderer(), array( 'format' => 'png' ) );

$assert( 'render returns a non-empty array of paths', is_array( $paths ) && count( $paths ) === 1 );

$path = $paths[0] ?? '';
$assert( 'rendered file exists', $path && file_exists( $path ) );

if ( $path && file_exists( $path ) ) {
	$info = getimagesize( $path );
	$assert( 'output is a valid PNG', is_array( $info ) && $info['mime'] === 'image/png' );
	$assert( 'output has sane dimensions', is_array( $info ) && $info[0] > 100 && $info[1] > 100 );
	wp_delete_file( $path );
}

// Empty nodes must fail gracefully (no fatal, empty result).
$empty = $template->render( array( 'nodes' => array() ), new GDRenderer() );
$assert( 'empty nodes returns empty array without fatal', is_array( $empty ) && count( $empty ) === 0 );

// Rounded-rect polyfill path: draw a box node and confirm a canvas was produced
// even on runtimes lacking imagefilledroundedrectangle (8.0–8.3).
$renderer = new GDRenderer();
$renderer->create_canvas( 200, 100 );
$fill = $renderer->color_hex( 'x', '#2d6cdf' );
$renderer->draw_rounded_rect( 10, 10, 180, 80, $fill, 16 );
$assert( 'draw_rounded_rect produced an image on this PHP', $renderer->get_image() instanceof \GdImage );

if ( $failed > 0 ) {
	fwrite( fopen( 'php://stderr', 'w' ), "\nflow-diagram-template-smoke: {$failed}/{$total} assertions failed\n" );
	exit( 1 );
}

echo "\nAll {$total} flow-diagram-template assertions passed.\n";
