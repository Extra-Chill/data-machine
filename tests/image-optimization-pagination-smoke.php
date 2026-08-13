<?php
/**
 * Pure-PHP smoke test for image optimization candidate pagination.
 *
 * Run with: php tests/image-optimization-pagination-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$GLOBALS['__image_optimization_attachments'] = array();
$GLOBALS['__image_optimization_files']       = array();
$GLOBALS['__image_optimization_queries']     = array();
$GLOBALS['__image_optimization_failures']    = 0;

function image_optimization_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		echo "  PASS: {$message}\n";
		return;
	}

	echo "  FAIL: {$message}\n";
	++$GLOBALS['__image_optimization_failures'];
}

function image_optimization_fixture( array $sizes ): void {
	$GLOBALS['__image_optimization_attachments'] = array_keys( $sizes );
	$GLOBALS['__image_optimization_files']       = array();
	$GLOBALS['__image_optimization_queries']     = array();

	foreach ( $sizes as $attachment_id => $size ) {
		$file = tempnam( sys_get_temp_dir(), 'dm-image-scan-' );
		file_put_contents( $file, str_repeat( 'x', $size ) );
		$GLOBALS['__image_optimization_files'][ $attachment_id ] = $file;
	}
}

function image_optimization_cleanup(): void {
	foreach ( $GLOBALS['__image_optimization_files'] as $file ) {
		unlink( $file );
	}
}
}

namespace DataMachine\Abilities\Media {
function absint( $value ): int {
	return abs( (int) $value );
}

function get_posts( array $args ): array {
	$GLOBALS['__image_optimization_queries'][] = $args;
	return array_slice(
		$GLOBALS['__image_optimization_attachments'],
		(int) ( $args['offset'] ?? 0 ),
		(int) $args['posts_per_page']
	);
}

function get_attached_file( int $attachment_id ): string {
	return $GLOBALS['__image_optimization_files'][ $attachment_id ] ?? '';
}

function get_the_title( int $attachment_id ): string {
	return 'Image ' . $attachment_id;
}

function get_post_mime_type( int $attachment_id ): string {
	unset( $attachment_id );
	return 'image/jpeg';
}

function size_format( int $bytes ): string {
	return $bytes . ' B';
}
}

namespace {
require_once dirname( __DIR__ ) . '/inc/Abilities/Media/ImageOptimizationAbilities.php';

use DataMachine\Abilities\Media\ImageOptimizationAbilities;

echo "=== image-optimization-pagination-smoke ===\n";

$sizes      = array_fill( 1, 100, 5 );
$sizes[101] = 20;
image_optimization_fixture( $sizes );
$result = ImageOptimizationAbilities::optimizeImages(
	array(
		'size_threshold' => 10,
		'limit'          => 1,
		'dry_run'        => true,
	)
);
image_optimization_assert( array( 101 ) === array_column( $result['would_optimize'], 'attachment_id' ), 'finds an eligible image beyond the first page' );
image_optimization_assert( 101 === $result['scanned_count'], 'reports all inspected attachments across pages' );
image_optimization_assert( true === $result['scan_complete'], 'reports complete coverage when the final page is exhausted' );
image_optimization_cleanup();

$sizes      = array_fill( 1, 100, 5 );
$sizes[101] = 20;
$sizes[102] = 20;
$sizes[103] = 20;
$sizes[104] = 20;
image_optimization_fixture( $sizes );
$result = ImageOptimizationAbilities::optimizeImages(
	array(
		'size_threshold' => 10,
		'limit'          => 3,
		'dry_run'        => true,
	)
);
image_optimization_assert( array( 101, 102, 103 ) === array_column( $result['would_optimize'], 'attachment_id' ), 'stops at the eligible limit across pages' );
image_optimization_assert( 3 === $result['eligible_count'], 'reports the bounded eligible result count' );
image_optimization_assert( 103 === $result['scanned_count'], 'counts only attachments evaluated for eligibility' );
image_optimization_assert( false === $result['scan_complete'], 'reports partial coverage when the eligible limit stops the scan' );
image_optimization_cleanup();

$sizes = array_fill( 1, 205, 5 );
image_optimization_fixture( $sizes );
$result = ImageOptimizationAbilities::optimizeImages(
	array(
		'size_threshold' => 10,
		'limit'          => 2,
		'dry_run'        => true,
	)
);
image_optimization_assert( 205 === $result['scanned_count'], 'continues until all attachments are exhausted' );
image_optimization_assert( 0 === $result['eligible_count'], 'reports no eligible images after a complete scan' );
image_optimization_assert( true === $result['scan_complete'], 'marks exhausted attachment coverage complete' );
image_optimization_assert( array( 0, 100, 200 ) === array_column( $GLOBALS['__image_optimization_queries'], 'offset' ), 'uses deterministic bounded offsets' );
image_optimization_cleanup();

if ( $GLOBALS['__image_optimization_failures'] > 0 ) {
	exit( 1 );
}

echo "All image optimization pagination checks passed.\n";
}
