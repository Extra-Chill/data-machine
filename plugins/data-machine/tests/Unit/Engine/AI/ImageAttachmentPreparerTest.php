<?php
/**
 * Coverage for #3548: sniff image format from content and convert/downscale/drop
 * instead of failing the whole AI step.
 *
 * Events AI steps were failing with `ai_processing_failed` when a scraped
 * image's real format didn't match its file extension (AVIF saved as .jpg) or
 * when the image was legitimately huge (5.7MB / ~286 megapixels). The
 * provider's 400 response failed the entire job. `ImageAttachmentPreparer`
 * sniffs the real format via `wp_get_image_mime()`, converts unsupported but
 * decodable formats to JPEG via `wp_get_image_editor()`, and downscales or
 * drops images that exceed the size budget — an optional image must never
 * fail the job.
 *
 * These tests exercise real WordPress image editor primitives (Imagick/GD)
 * against synthetically generated fixtures rather than mocks, so the
 * assertions reflect actual encode/decode behavior.
 *
 * @package DataMachine\Tests\Unit\Engine\AI
 */

namespace DataMachine\Tests\Unit\Engine\AI;

use DataMachine\Engine\AI\ImageAttachmentPreparer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DataMachine\Engine\AI\ImageAttachmentPreparer
 */
class ImageAttachmentPreparerTest extends TestCase {

	/** @var string[] Files created during a test, removed in tearDown(). */
	private array $created_files = array();

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_get_image_editor' ) || ! function_exists( 'wp_get_image_mime' ) ) {
			$this->markTestSkipped( 'Requires a bootstrapped WordPress runtime (wp_get_image_editor/wp_get_image_mime).' );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->created_files as $path ) {
			if ( file_exists( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort test cleanup.
			}
		}
		$this->created_files = array();

		parent::tearDown();
	}

	/**
	 * A real JPEG within budget passes straight through — no re-encode, same path.
	 */
	public function test_prepare_passes_through_supported_format_unchanged(): void {
		$path = $this->makeSmallJpeg();

		$result = ImageAttachmentPreparer::prepare( $path, 'image/jpeg' );

		$this->assertNotNull( $result, 'A small, supported, in-budget JPEG must never be dropped' );
		$this->assertSame( $path, $result['file_path'], 'Supported formats within budget are not re-encoded' );
		$this->assertSame( 'image/jpeg', $result['mime_type'] );
	}

	/**
	 * Regression for #3548's primary repro: a scraped image is AVIF bytes
	 * saved with a lying ".jpg" extension. The real format must be sniffed
	 * from content (not the extension) and converted to a provider-supported
	 * format instead of being sent as a broken image/jpeg — or dropped, but
	 * never crash the job.
	 */
	public function test_prepare_converts_avif_saved_as_jpg_to_supported_jpeg(): void {
		$this->requireImagickAvifSupport();

		$avif_as_jpg = $this->makeAvifNamedAsJpg();

		// wp_get_image_mime() sniffs real content; this is what the fixed call
		// sites now pass in instead of trusting the .jpg extension.
		$sniffed = wp_get_image_mime( $avif_as_jpg );
		$this->assertSame( 'image/avif', $sniffed, 'Precondition: the fixture must actually be AVIF bytes under a .jpg name' );

		$result = ImageAttachmentPreparer::prepare( $avif_as_jpg, $sniffed );

		$this->assertNotNull( $result, 'A decodable-but-unsupported image must be converted, not dropped' );
		$this->track( $result['file_path'] );

		$this->assertSame( 'image/jpeg', $result['mime_type'] );
		$this->assertNotSame( $avif_as_jpg, $result['file_path'], 'Conversion writes a new file; the AVIF source is left untouched' );
		$this->assertFileExists( $result['file_path'] );
		$this->assertSame(
			'image/jpeg',
			wp_get_image_mime( $result['file_path'] ),
			'The converted file must actually decode as JPEG, not just be named .jpg'
		);
	}

	/**
	 * A file that declares an unsupported format but doesn't actually decode
	 * as one (corrupt / not really an image) must be dropped with a
	 * diagnostic instead of throwing or producing a broken attachment.
	 */
	public function test_prepare_drops_when_declared_format_does_not_decode(): void {
		$path = $this->makeGarbageFile();

		$logged = array();
		add_action(
			'datamachine_log',
			static function ( $level, $message, $context = array() ) use ( &$logged ) {
				$logged[] = array( $level, $message, $context );
			},
			10,
			3
		);

		$result = ImageAttachmentPreparer::prepare( $path, 'image/heic' );

		remove_all_actions( 'datamachine_log' );

		$this->assertNull( $result, 'Undecodable content must be dropped, not passed through as a broken attachment' );
		$this->assertNotEmpty( $logged, 'A diagnostic must be recorded when an image is dropped' );
		$this->assertSame( 'warning', $logged[0][0] );
		$this->assertStringContainsString( 'dropped image attachment', $logged[0][1] );
	}

	/**
	 * An oversized-but-supported image is downscaled to fit the byte budget
	 * instead of being sent raw (and instead of failing the job).
	 */
	public function test_prepare_downscales_oversized_image_under_budget(): void {
		$path = $this->makeNoisyJpeg( 3000 );
		$original_size = filesize( $path );
		$this->assertNotFalse( $original_size );

		// Force the "oversized" branch without needing a multi-megabyte fixture:
		// cap the budget just under this fixture's real size.
		$budget = $original_size - 1;
		add_filter(
			'datamachine_ai_image_attachment_max_bytes',
			static function () use ( $budget ) {
				return $budget;
			}
		);

		$result = ImageAttachmentPreparer::prepare( $path, 'image/jpeg' );

		remove_all_filters( 'datamachine_ai_image_attachment_max_bytes' );

		$this->assertNotNull( $result, 'An oversized image that CAN be downscaled under budget must not be dropped' );
		$this->track( $result['file_path'] );

		$this->assertSame( 'image/jpeg', $result['mime_type'] );
		$this->assertNotSame( $path, $result['file_path'], 'Downscaling writes a new, smaller file' );

		$final_size = filesize( $result['file_path'] );
		$this->assertNotFalse( $final_size );
		$this->assertLessThanOrEqual( $budget, $final_size, 'The downscaled file must actually fit the budget' );

		$dimensions = getimagesize( $result['file_path'] );
		$this->assertNotFalse( $dimensions );
		$this->assertLessThanOrEqual( 2048, $dimensions[0], 'Long edge must be constrained to the downscale cap' );
		$this->assertLessThanOrEqual( 2048, $dimensions[1], 'Long edge must be constrained to the downscale cap' );
	}

	/**
	 * When even the downscaled re-encode can't fit the budget, the image is
	 * dropped with a diagnostic — never sent oversized, never fails the job.
	 */
	public function test_prepare_drops_when_still_oversized_after_downscale(): void {
		$path = $this->makeSmallJpeg();

		// A budget no real JPEG can satisfy forces the "still oversized after
		// downscale" branch deterministically.
		add_filter(
			'datamachine_ai_image_attachment_max_bytes',
			static function () {
				return 1;
			}
		);

		$logged = array();
		add_action(
			'datamachine_log',
			static function ( $level, $message, $context = array() ) use ( &$logged ) {
				$logged[] = array( $level, $message, $context );
			},
			10,
			3
		);

		$result = ImageAttachmentPreparer::prepare( $path, 'image/jpeg' );

		remove_all_filters( 'datamachine_ai_image_attachment_max_bytes' );
		remove_all_actions( 'datamachine_log' );

		$this->assertNull( $result, 'An image that cannot be brought under budget must be dropped, not sent oversized' );
		$this->assertNotEmpty( $logged );
		$this->assertStringContainsString( 'still oversized', (string) ( $logged[0][2]['reason'] ?? '' ) );
	}

	private function track( string $path ): void {
		$this->created_files[] = $path;
	}

	private function requireImagickAvifSupport(): void {
		if ( ! class_exists( '\\Imagick' ) ) {
			$this->markTestSkipped( 'Imagick extension not available.' );
		}
		if ( ! in_array( 'AVIF', \Imagick::queryFormats( 'AVIF' ), true ) ) {
			$this->markTestSkipped( 'Imagick build lacks AVIF support.' );
		}
	}

	/**
	 * Writes a minimal valid JPEG and returns its path.
	 */
	private function makeSmallJpeg(): string {
		$jpeg = base64_decode( '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/3/AD' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a fixture literal, not user input.

		$path = $this->tempPath( '.jpg' );
		file_put_contents( $path, $jpeg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$this->track( $path );

		return $path;
	}

	/**
	 * Encodes a real AVIF payload via Imagick and writes it to a path ending
	 * in ".jpg" — reproduces the exact #3548 repro (scraper saves whatever
	 * bytes the source served under a .jpg name).
	 */
	private function makeAvifNamedAsJpg(): string {
		$imagick = new \Imagick();
		$imagick->newImage( 64, 64, new \ImagickPixel( 'red' ) );
		$imagick->setImageFormat( 'avif' );
		$blob = $imagick->getImageBlob();
		$imagick->destroy();

		$path = $this->tempPath( '.jpg' );
		file_put_contents( $path, $blob ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$this->track( $path );

		return $path;
	}

	/**
	 * A non-image file — proves the drop path doesn't crash on garbage input.
	 */
	private function makeGarbageFile(): string {
		$path = $this->tempPath( '.jpg' );
		file_put_contents( $path, str_repeat( 'not an image', 20 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$this->track( $path );

		return $path;
	}

	/**
	 * A large, high-entropy JPEG so compression doesn't crush its byte size
	 * to nothing — needed so downscaling measurably reduces file size (a
	 * solid-color image would compress to a few hundred bytes at any
	 * resolution and never exercise the oversized branch meaningfully).
	 *
	 * @param int $dimension Width/height in pixels.
	 */
	private function makeNoisyJpeg( int $dimension ): string {
		$image = imagecreatetruecolor( $dimension, $dimension );
		mt_srand( 3548 );
		for ( $i = 0; $i < 4000; $i++ ) {
			$color = imagecolorallocate( $image, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) );
			imagefilledrectangle(
				$image,
				mt_rand( 0, $dimension - 1 ),
				mt_rand( 0, $dimension - 1 ),
				mt_rand( 0, $dimension - 1 ),
				mt_rand( 0, $dimension - 1 ),
				$color
			);
		}

		$path = $this->tempPath( '.jpg' );
		imagejpeg( $image, $path, 92 );
		imagedestroy( $image );
		$this->track( $path );

		return $path;
	}

	private function tempPath( string $suffix ): string {
		return rtrim( sys_get_temp_dir(), '/\\' ) . '/dm-3548-' . bin2hex( random_bytes( 6 ) ) . $suffix;
	}
}
