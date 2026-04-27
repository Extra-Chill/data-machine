<?php
/**
 * Tests for MediaAbilities ability registration and execution.
 *
 * @package DataMachine\Tests\Unit\Abilities\Media
 */

namespace DataMachine\Tests\Unit\Abilities\Media;

use DataMachine\Abilities\Media\MediaAbilities;
use WP_UnitTestCase;

class MediaAbilitiesTest extends WP_UnitTestCase {

	private MediaAbilities $abilities;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->abilities = new MediaAbilities();
	}

	/**
	 * Test all three abilities are registered.
	 */
	public function test_abilities_registered(): void {
		$upload   = wp_get_ability( 'datamachine/upload-media' );
		$validate = wp_get_ability( 'datamachine/validate-media' );
		$metadata = wp_get_ability( 'datamachine/video-metadata' );

		$this->assertNotNull( $upload, 'upload-media ability should be registered' );
		$this->assertNotNull( $validate, 'validate-media ability should be registered' );
		$this->assertNotNull( $metadata, 'video-metadata ability should be registered' );
	}

	// ── Upload Media ──────────────────────────────────────────────────

	/**
	 * Test upload-media requires url or file_path.
	 */
	public function test_upload_media_missing_input(): void {
		$result = $this->abilities->executeUploadMedia( array() );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'url or file_path is required', $result['error'] );
	}

	/**
	 * Test upload-media with nonexistent file_path.
	 */
	public function test_upload_media_nonexistent_path(): void {
		$result = $this->abilities->executeUploadMedia( array(
			'file_path' => '/nonexistent/video.mp4',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not a valid', $result['error'] );
	}

	/**
	 * Test upload-media auto-detects video from extension.
	 */
	public function test_upload_media_detects_video_extension(): void {
		$result = $this->abilities->executeUploadMedia( array(
			'file_path' => '/nonexistent/clip.mp4',
		) );

		// Will fail validation (file doesn't exist), but error message should say "video".
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'video', strtolower( $result['error'] ) );
	}

	/**
	 * Test upload-media auto-detects image from extension.
	 */
	public function test_upload_media_detects_image_extension(): void {
		$result = $this->abilities->executeUploadMedia( array(
			'file_path' => '/nonexistent/photo.jpg',
		) );

		// Will fail validation (file doesn't exist), but error message should say "image".
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'image', strtolower( $result['error'] ) );
	}

	// ── Validate Media ────────────────────────────────────────────────

	/**
	 * Test validate-media requires path.
	 */
	public function test_validate_media_missing_path(): void {
		$result = $this->abilities->executeValidateMedia( array() );

		$this->assertFalse( $result['success'] );
		$this->assertContains( 'path is required', $result['errors'] );
	}

	/**
	 * Test validate-media with nonexistent file.
	 */
	public function test_validate_media_nonexistent_file(): void {
		$result = $this->abilities->executeValidateMedia( array(
			'path' => '/nonexistent/video.mp4',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertArrayHasKey( 'media_type', $result );
	}

	/**
	 * Test validate-media respects forced media_type.
	 */
	public function test_validate_media_forced_type(): void {
		$result = $this->abilities->executeValidateMedia( array(
			'path'       => '/nonexistent/ambiguous.dat',
			'media_type' => 'video',
		) );

		$this->assertSame( 'video', $result['media_type'] );
	}

	/**
	 * Test validate-media defaults to image for unknown extensions.
	 */
	public function test_validate_media_defaults_to_image(): void {
		$result = $this->abilities->executeValidateMedia( array(
			'path' => '/nonexistent/ambiguous.dat',
		) );

		$this->assertSame( 'image', $result['media_type'] );
	}

	// ── Video Metadata ────────────────────────────────────────────────

	/**
	 * Test video-metadata requires path.
	 */
	public function test_video_metadata_missing_path(): void {
		$result = $this->abilities->executeVideoMetadata( array() );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'path is required', $result['error'] );
	}

	/**
	 * Test video-metadata with nonexistent file.
	 */
	public function test_video_metadata_nonexistent_file(): void {
		$result = $this->abilities->executeVideoMetadata( array(
			'path' => '/nonexistent/video.mp4',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'File not found', $result['error'] );
	}

	/**
	 * Test video-metadata returns structured data.
	 */
	public function test_video_metadata_returns_structure(): void {
		global $wp_filesystem;
		$temp_file = tempnam( sys_get_temp_dir(), 'dm-video-test-' );
		$wp_filesystem->put_contents( $temp_file, str_repeat( 'x', 100 ) );

		$result = $this->abilities->executeVideoMetadata( array(
			'path' => $temp_file,
		) );

		$this->assertArrayHasKey( 'duration', $result );
		$this->assertArrayHasKey( 'width', $result );
		$this->assertArrayHasKey( 'height', $result );
		$this->assertArrayHasKey( 'codec', $result );
		$this->assertArrayHasKey( 'file_size', $result );
		$this->assertArrayHasKey( 'ffprobe', $result );
		$this->assertSame( 100, $result['file_size'] );

		wp_delete_file( $temp_file );
	}
}
