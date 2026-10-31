<?php
/**
 * Tests for media hygiene variant indexing.
 *
 * @package DataMachineBusiness\Tests
 */

use DataMachineBusiness\Abilities\MediaHygiene\MediaHygieneScanner;

class MediaHygieneScannerTest extends \PHPUnit\Framework\TestCase {

	public function test_appended_output_variants_are_indexed_for_originals_and_resized_images(): void {
		$known_files = array(
			'2023/08/image.jpg'         => true,
			'2023/08/image-768x512.jpg' => true,
		);

		$index = MediaHygieneScanner::appended_variant_index( $known_files, array( 'webp' ) );

		$this->assertArrayHasKey( '2023/08/image.jpg.webp', $index );
		$this->assertArrayHasKey( '2023/08/image-768x512.jpg.webp', $index );
	}

	public function test_unrelated_files_are_not_added_to_the_variant_index(): void {
		$index = MediaHygieneScanner::appended_variant_index(
			array( '2023/08/image.jpg' => true ),
			array( 'webp' )
		);

		$this->assertArrayNotHasKey( '2023/08/unrelated.jpg.webp', $index );
		$this->assertArrayNotHasKey( '2023/08/image-768x512.jpg.webp', $index );
	}
}
