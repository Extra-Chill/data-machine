<?php
/**
 * Tests for VideoAbilities ability registration and execution.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Media\VideoAbilities;
use WP_UnitTestCase;

class VideoAbilitiesTest extends WP_UnitTestCase {

	private VideoAbilities $abilities;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->abilities = new VideoAbilities();
	}

	/**
	 * Test all three abilities are registered.
	 */
	public function test_abilities_registered(): void {
		$upload   = wp_get_ability( 'datamachine/upload-video' );
		$validate = wp_get_ability( 'datamachine/validate-video' );
		$metadata = wp_get_ability( 'datamachine/video-metadata' );

		$this->assertNotNull( $upload, 'upload-video ability should be registered' );
		$this->assertNotNull( $validate, 'validate-video ability should be registered' );
		$this->assertNotNull( $metadata, 'video-metadata ability should be registered' );
	}

	/**
	 * Test upload-video requires url or file_path.
	 */
	public function test_upload_video_missing_input(): void {
		$result = $this->abilities->executeUploadVideo( array() );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'url or file_path is required', $result['error'] );
	}

	/**
	 * Test upload-video with nonexistent file_path.
	 */
	public function test_upload_video_nonexistent_path(): void {
		$result = $this->abilities->executeUploadVideo( array(
			'file_path' => '/nonexistent/video.mp4',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not a valid video', $result['error'] );
	}

	/**
	 * Test validate-video requires path.
	 */
	public function test_validate_video_missing_path(): void {
		$result = $this->abilities->executeValidateVideo( array() );

		$this->assertFalse( $result['success'] );
		$this->assertContains( 'path is required', $result['errors'] );
	}

	/**
	 * Test validate-video with nonexistent file.
	 */
	public function test_validate_video_nonexistent_file(): void {
		$result = $this->abilities->executeValidateVideo( array(
			'path' => '/nonexistent/video.mp4',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

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
		$temp_file = tempnam( sys_get_temp_dir(), 'dm-video-test-' );
		file_put_contents( $temp_file, str_repeat( 'x', 100 ) );

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
