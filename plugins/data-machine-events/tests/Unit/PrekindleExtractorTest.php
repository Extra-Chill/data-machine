<?php
/**
 * Prekindle organizer ID regression tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

// phpcs:disable WordPress.Files.FileName -- PSR-4 test class follows repository naming.
// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Script markup is extractor test input, not rendered output.

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\PrekindleExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Covers API-callback embeds and existing organizer ID sources.
 */
class PrekindleExtractorTest extends TestCase {

	/**
	 * Mohawk's callback script must supply the full organizer ID as a string.
	 */
	public function test_extract_org_id_from_api_callback_fixture(): void {
		$html      = file_get_contents( __DIR__ . '/../Fixtures/prekindle/mohawk-home.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local HTML fixture.
		$extractor = new PrekindleExtractor();

		$this->assertTrue( $extractor->canExtract( $html ) );
		$this->assertSame( '531433527670566235', $this->extract_org_id( $html, 'https://mohawkaustin.com/' ) );
	}

	/**
	 * Existing ID sources retain their behavior and precedence.
	 */
	public function test_extract_org_id_preserves_existing_sources(): void {
		$this->assertSame( '123', $this->extract_org_id( '<div data-org-id="456"></div>', 'https://www.prekindle.com/organizer/123' ) );
		$this->assertSame( '456', $this->extract_org_id( '<div data-org-id="456"></div>', 'https://venue.example/' ) );
		$this->assertSame( '789', $this->extract_org_id( '<script src="https://www.prekindle.com/widget/id/789"></script>', 'https://venue.example/' ) );
		$this->assertNull( $this->extract_org_id( '<script src="https://www.prekindle.com/widget/mohawk-widget_v1.3.js"></script>', 'https://venue.example/' ) );
	}

	/**
	 * Invoke ID detection without making network requests.
	 *
	 * @param string $html Page HTML.
	 * @param string $source_url Page URL.
	 * @return string|null Organizer ID.
	 */
	private function extract_org_id( string $html, string $source_url ): ?string {
		$method = new \ReflectionMethod( PrekindleExtractor::class, 'extractOrgId' );
		$method->setAccessible( true );

		return $method->invoke( new PrekindleExtractor(), $html, $source_url );
	}
}
