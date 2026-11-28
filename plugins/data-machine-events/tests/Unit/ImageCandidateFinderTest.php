<?php
/** Image candidate hard exclusion tests. */
namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\ImageCandidateFinder;
use WP_UnitTestCase;

class ImageCandidateFinderTest extends WP_UnitTestCase {
	public function test_non_flyers_cannot_outscore_exclusions(): void {
		$html = '<div class="event"><a href="/event/show/">December 12 at 8pm'
			. '<img src="social/facebook.png" alt="Event poster" width="600" height="800">'
			. '<img src="poster.jpg" class="venue-logo" alt="Event poster" width="600" height="800">'
			. '<img src="tiny-poster.jpg" alt="Event poster" width="64" height="64">'
			. '<img src="real-poster.jpg" alt="Event poster" width="600" height="800">'
			. '</a></div>';
		$images = ( new ImageCandidateFinder() )->findCandidates( $html, 'https://example.com' );
		$this->assertCount( 1, $images );
		$this->assertSame( 'https://example.com/real-poster.jpg', $images[0]['url'] );
	}
}
