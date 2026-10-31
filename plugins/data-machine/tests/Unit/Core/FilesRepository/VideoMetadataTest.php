<?php
/**
 * Tests for VideoMetadata.
 *
 * @package DataMachine\Tests\Unit\Core\FilesRepository
 */

namespace DataMachine\Tests\Unit\Core\FilesRepository;

use DataMachine\Core\FilesRepository\VideoMetadata;
use WP_UnitTestCase;

class VideoMetadataTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		VideoMetadata::reset_cache();
	}

	public function test_extract_nonexistent_file(): void {
		$result = VideoMetadata::extract( '/nonexistent/video.mp4' );

		$this->assertSame( 'File not found', $result['error'] );
		$this->assertNull( $result['duration'] );
		$this->assertNull( $result['width'] );
		$this->assertNull( $result['height'] );
		$this->assertNull( $result['codec'] );
		$this->assertSame( 0, $result['file_size'] );
		$this->assertFalse( $result['ffprobe'] );
	}

	public function test_extract_returns_file_size_and_mime(): void {
		global $wp_filesystem;
		$temp_file = tempnam( sys_get_temp_dir(), 'dm-video-test-' );
		$wp_filesystem->put_contents( $temp_file, str_repeat( 'x', 500 ) );

		$result = VideoMetadata::extract( $temp_file );

		$this->assertSame( 500, $result['file_size'] );
		// MIME type detected (may be application/octet-stream for fake file).
		$this->assertNotNull( $result['mime_type'] );

		wp_delete_file( $temp_file );
	}

	public function test_extract_result_structure(): void {
		global $wp_filesystem;
		$temp_file = tempnam( sys_get_temp_dir(), 'dm-video-test-' );
		$wp_filesystem->put_contents( $temp_file, 'fake' );

		$result = VideoMetadata::extract( $temp_file );

		$this->assertArrayHasKey( 'duration', $result );
		$this->assertArrayHasKey( 'width', $result );
		$this->assertArrayHasKey( 'height', $result );
		$this->assertArrayHasKey( 'codec', $result );
		$this->assertArrayHasKey( 'bitrate', $result );
		$this->assertArrayHasKey( 'framerate', $result );
		$this->assertArrayHasKey( 'mime_type', $result );
		$this->assertArrayHasKey( 'file_size', $result );
		$this->assertArrayHasKey( 'format', $result );
		$this->assertArrayHasKey( 'ffprobe', $result );
		$this->assertArrayHasKey( 'error', $result );

		wp_delete_file( $temp_file );
	}

	public function test_is_ffprobe_available_returns_bool(): void {
		$result = VideoMetadata::is_ffprobe_available();
		$this->assertIsBool( $result );
	}

	public function test_reset_cache(): void {
		// Call once to cache.
		VideoMetadata::is_ffprobe_available();

		// Reset and call again — should work without error.
		VideoMetadata::reset_cache();
		$result = VideoMetadata::is_ffprobe_available();
		$this->assertIsBool( $result );
	}
}
