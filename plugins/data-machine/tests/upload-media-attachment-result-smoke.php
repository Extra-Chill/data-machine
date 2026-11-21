<?php
/**
 * Smoke: upload-media returns attachment data in media-library mode (#4004).
 *
 * Run: php tests/upload-media-attachment-result-smoke.php
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['attachments'] = array();

function wp_get_attachment_url( $id ) { return $GLOBALS['attachments'][ $id ]['url'] ?? false; }
function get_attached_file( $id ) { return $GLOBALS['attachments'][ $id ]['path'] ?? false; }
function get_post_mime_type( $id ) { return $GLOBALS['attachments'][ $id ]['mime'] ?? false; }

require_once dirname( __DIR__ ) . '/inc/Abilities/Media/MediaAbilities.php';

use DataMachine\Abilities\Media\MediaAbilities;

$failures = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	} else {
		echo "PASS: {$message}\n";
	}
};

$abilities = ( new ReflectionClass( MediaAbilities::class ) )->newInstanceWithoutConstructor();
$method    = new ReflectionMethod( MediaAbilities::class, 'applyAttachmentResult' );
$method->setAccessible( true );
$apply = static fn( array $r ): array => $method->invoke( $abilities, $r );

$repo_result = array(
	'success'    => true,
	'path'       => '/nonexistent/datamachine-files/pipeline-direct/media-upload/file.jpg',
	'url'        => 'https://example.com/wp-content/uploads/datamachine-files/pipeline-direct/media-upload/file.jpg',
	'filename'   => 'file.jpg',
	'size'       => 1234,
	'mime_type'  => 'image/jpeg',
	'media_type' => 'image',
	'media_id'   => null,
);

// 1. Repository mode unchanged.
$assert( $repo_result === $apply( $repo_result ), 'repository mode (no media_id) result is unchanged' );

// 2. Media-library mode: attachment wins (converted to webp on upload).
$GLOBALS['attachments'][746822] = array(
	'url'  => 'https://example.com/wp-content/uploads/sites/7/2026/11/file.webp',
	'path' => '/srv/uploads/sites/7/2026/11/file.webp',
	'mime' => 'image/webp',
);
$lib = $apply( array_merge( $repo_result, array( 'media_id' => 746822 ) ) );
$assert( wp_get_attachment_url( 746822 ) === $lib['url'], 'url == wp_get_attachment_url(media_id)' );
$assert( get_attached_file( 746822 ) === $lib['path'], 'path == get_attached_file(media_id)' );
$assert( 'file.webp' === $lib['filename'], 'filename describes the attachment' );
$assert( 'image/webp' === $lib['mime_type'], 'mime_type == get_post_mime_type(media_id)' );
$assert( 746822 === $lib['media_id'] && 1234 === $lib['size'], 'media_id and other fields preserved' );
$assert( false === strpos( $lib['url'], 'datamachine-files' ), 'url no longer points at repository storage' );

// 3. Attachment lookups fail and original file was moved away: never point at deleted file.
$gone = $apply( array_merge( $repo_result, array( 'media_id' => 999 ) ) );
$assert( '' === $gone['url'] && '' === $gone['path'], 'deleted original is not returned when attachment lookups fail' );
$assert( 'image/jpeg' === $gone['mime_type'], 'mime_type falls back to existing value' );

// 4. Attachment lookups fail but original still exists: fall back to existing values.
$tmp = tempnam( sys_get_temp_dir(), 'dm4004' );
$kept = $apply( array_merge( $repo_result, array( 'path' => $tmp, 'media_id' => 999 ) ) );
$assert( $tmp === $kept['path'] && $repo_result['url'] === $kept['url'] && 'file.jpg' === $kept['filename'], 'existing values kept when original file still exists' );
unlink( $tmp );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} failure(s)\n" );
	exit( 1 );
}
echo "OK: upload-media attachment result smoke passed\n";
