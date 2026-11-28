<?php
/**
 * Plain gig list tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\PlainGigListExtractor;
use WP_UnitTestCase;

// Fixtures are local files, never remote requests.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
class PlainGigListExtractorTest extends WP_UnitTestCase {

	public function test_live_dead_on_live_rows(): void {
		$html      = file_get_contents( __DIR__ . '/../Fixtures/deadonlive-tour.html' );
		$extractor = new PlainGigListExtractor();
		$this->assertTrue( $extractor->canExtract( $html ) );
		$events = $extractor->extract( $html, 'https://deadonlive.com/tour' );
		$this->assertCount( 3, $events );
		$this->assertSame( array( '2027-02-06', '2027-02-10', '2027-02-20' ), array_column( $events, 'startDate' ) );
		$this->assertSame( 'Dead On Live', $events[0]['title'] );
		$this->assertSame( 'The Levoy Theater', $events[0]['venue'] );
		$this->assertSame( 'Millville', $events[0]['venueCity'] );
		$this->assertSame( 'NJ', $events[0]['venueState'] );
		$this->assertSame( '', $events[0]['ticketUrl'] );
		$this->assertSame( 'Woodbridge', $events[1]['venueCity'] );
		$this->assertSame( 'https://ci.ovationtix.com/37027/production/1283356?performanceId=11841160', $events[1]['ticketUrl'] );
		$this->assertSame( 'https://deadonlive.com/tour', $events[0]['source_url'] );
	}

	public function test_live_cluzo_full_rows_not_mobile_fragments(): void {
		$html      = file_get_contents( __DIR__ . '/../Fixtures/cluzo-tour.html' );
		$extractor = new PlainGigListExtractor();
		$this->assertTrue( $extractor->canExtract( $html ) );
		$events = $extractor->extract( $html, 'https://theinspectorcluzo.com/tour2026/' );
		$this->assertCount( 6, $events );
		$this->assertSame( array( '2026-01-28', '2026-01-29', '2026-10-29', '2026-10-30', '2026-12-04', '2026-12-05' ), array_column( $events, 'startDate' ) );
		$this->assertSame( 'Planet Sarbez', $events[2]['venue'] );
		$this->assertSame( 'ST AUGUSTINE', $events[2]['venueCity'] );
		$this->assertSame( 'FL', $events[2]['venueState'] );
		$this->assertSame( 'US', $events[2]['venueCountry'] );
		$this->assertSame( 'Blue Jay Listening Room', $events[3]['title'] );
		$this->assertSame( 'JACKSONVILLE', $events[3]['venueCity'] );
		$this->assertSame( 'FL', $events[3]['venueState'] );
		$this->assertSame( 'https://app.opendate.io/e/the-inspector-cluzo-at-blue-jay-listening-room-october-30-2026-688449', $events[3]['ticketUrl'] );
		$this->assertSame( 'ANGOULEME', $events[5]['venueCity'] );
		$this->assertSame( 'FR', $events[5]['venueCountry'] );
		$this->assertSame( '', $events[5]['startTime'] );
	}

	public function test_layout_classes_are_not_required_and_full_duplicates_are_deduped(): void {
		$html = file_get_contents( __DIR__ . '/../Fixtures/cluzo-tour.html' );
		$html = str_replace( array( 'screen-row', 'mobile-row' ), array( 'wide', 'narrow' ), $html );
		preg_match( '/<tr class="wide">.*?<\/tr>/s', $html, $match );
		$html   = str_replace( '</table>', $match[0] . '</table>', $html );
		$events = ( new PlainGigListExtractor() )->extract( $html, 'https://example.org/tour' );
		$this->assertCount( 6, $events );
	}

	public function test_negative_navigation_and_footer(): void {
		$html      = file_get_contents( __DIR__ . '/../Fixtures/plain-gig-negative.html' );
		$extractor = new PlainGigListExtractor();
		$this->assertFalse( $extractor->canExtract( $html ) );
		$this->assertSame( array(), $extractor->extract( $html, 'https://example.org' ) );
	}

	public function test_numeric_date_evidence_and_minimum_siblings(): void {
		$extractor = new PlainGigListExtractor();
		$make_rows = static function ( array $dates ): string {
			$html = '<table>';
			foreach ( $dates as $date ) {
				$html .= '<tr><td>' . $date . '</td><td>FR - PARIS</td><td>Music Hall</td></tr>';
			}
			return $html . '</table>';
		};
		$this->assertFalse( $extractor->canExtract( $make_rows( array( '05-12-2026' ) ) ) );
		$this->assertFalse( $extractor->canExtract( $make_rows( array( '05-12-2026', '03-04-2026', '02-01-2026' ) ) ) );
		$events = $extractor->extract( $make_rows( array( '01-02-2026', '02-02-2026', '03-02-2026' ) ), 'https://example.org' );
		$this->assertSame( array( '2026-02-01', '2026-02-02', '2026-02-03' ), array_column( $events, 'startDate' ) );
		// Ascends both ways and is not a consecutive run: ambiguous, so no guess.
		$this->assertFalse( $extractor->canExtract( $make_rows( array( '03-04-2026', '03-05-2026', '03-09-2026' ) ) ) );
		$events = $extractor->extract( $make_rows( array( '28-01-2026', '05-12-2026', '31-02-2026', '06-12-2026' ) ), 'https://example.org' );
		$this->assertCount( 3, $events );
		$this->assertSame( '2026-12-05', $events[1]['startDate'] );
	}
}
