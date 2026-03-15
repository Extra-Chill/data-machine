<?php
/**
 * Tests for MediaValidator abstraction.
 *
 * Verifies the shared validation logic works correctly through both
 * ImageValidator and VideoValidator subclasses.
 *
 * @package DataMachine\Tests\Unit\FilesRepository
 */

namespace DataMachine\Tests\Unit\FilesRepository;

use DataMachine\Core\FilesRepository\ImageValidator;
use DataMachine\Core\FilesRepository\MediaValidator;
use DataMachine\Core\FilesRepository\VideoValidator;
use WP_UnitTestCase;

class MediaValidatorTest extends WP_UnitTestCase {

	/**
	 * Test that ImageValidator extends MediaValidator.
	 */
	public function test_image_validator_extends_media_validator(): void {
		$validator = new ImageValidator();
		$this->assertInstanceOf( MediaValidator::class, $validator );
	}

	/**
	 * Test that VideoValidator extends MediaValidator.
	 */
	public function test_video_validator_extends_media_validator(): void {
		$validator = new VideoValidator();
		$this->assertInstanceOf( MediaValidator::class, $validator );
	}

	/**
	 * Test image MIME types are distinct from video MIME types.
	 */
	public function test_image_and_video_mime_types_are_distinct(): void {
		$image = new ImageValidator();
		$video = new VideoValidator();

		// Image types should not be supported by video validator.
		$this->assertTrue( $image->is_supported_mime_type( 'image/jpeg' ) );
		$this->assertFalse( $video->is_supported_mime_type( 'image/jpeg' ) );

		// Video types should not be supported by image validator.
		$this->assertTrue( $video->is_supported_mime_type( 'video/mp4' ) );
		$this->assertFalse( $image->is_supported_mime_type( 'video/mp4' ) );
	}

	/**
	 * Test that nonexistent file returns correct label in error message for images.
	 */
	public function test_image_validator_error_label(): void {
		$validator = new ImageValidator();
		$result    = $validator->validate_repository_file( '/nonexistent/photo.jpg' );

		$this->assertFalse( $result['valid'] );
		$this->assertContains( 'Image file not found in repository', $result['errors'] );
	}

	/**
	 * Test that nonexistent file returns correct label in error message for videos.
	 */
	public function test_video_validator_error_label(): void {
		$validator = new VideoValidator();
		$result    = $validator->validate_repository_file( '/nonexistent/clip.mp4' );

		$this->assertFalse( $result['valid'] );
		$this->assertContains( 'Video file not found in repository', $result['errors'] );
	}

	/**
	 * Test validate_repository_file returns consistent structure across both validators.
	 */
	public function test_result_structure_consistent(): void {
		$image_result = ( new ImageValidator() )->validate_repository_file( '/nonexistent' );
		$video_result = ( new VideoValidator() )->validate_repository_file( '/nonexistent' );

		$expected_keys = array( 'valid', 'mime_type', 'size', 'errors' );

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $image_result, "Image result missing key: {$key}" );
			$this->assertArrayHasKey( $key, $video_result, "Video result missing key: {$key}" );
		}
	}

	/**
	 * Test unreadable file error uses correct label.
	 *
	 * Skipped when running as root since root can read any file.
	 */
	public function test_unreadable_file_error(): void {
		if ( function_exists( 'posix_getuid' ) && posix_getuid() === 0 ) {
			$this->markTestSkipped( 'Cannot test unreadable files when running as root.' );
		}

		$temp_file = tempnam( sys_get_temp_dir(), 'dm-media-test-' );
		file_put_contents( $temp_file, 'test' );
		chmod( $temp_file, 0000 );

		$image_result = ( new ImageValidator() )->validate_repository_file( $temp_file );
		$video_result = ( new VideoValidator() )->validate_repository_file( $temp_file );

		// Restore permissions before asserting (so tear_down can clean up).
		chmod( $temp_file, 0644 );
		wp_delete_file( $temp_file );

		$this->assertFalse( $image_result['valid'] );
		$this->assertContains( 'Image file not readable', $image_result['errors'] );

		$this->assertFalse( $video_result['valid'] );
		$this->assertContains( 'Video file not readable', $video_result['errors'] );
	}

	/**
	 * Test constraint validation with max_file_size works via base class.
	 */
	public function test_constraint_validation_available_on_both(): void {
		$image = new ImageValidator();
		$video = new VideoValidator();

		// Both should have validate_against_constraints from the base class.
		$this->assertTrue( method_exists( $image, 'validate_against_constraints' ) );
		$this->assertTrue( method_exists( $video, 'validate_against_constraints' ) );
	}

	/**
	 * Test constraint validation result structure.
	 */
	public function test_constraint_result_structure(): void {
		$validator = new VideoValidator();
		$result    = $validator->validate_against_constraints( '/nonexistent', array( 'max_file_size' => 1000 ) );

		$this->assertArrayHasKey( 'valid', $result );
		$this->assertArrayHasKey( 'results', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertFalse( $result['valid'] );
	}
}
