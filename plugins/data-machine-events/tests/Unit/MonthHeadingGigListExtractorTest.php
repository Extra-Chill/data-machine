<?php
/**
 * Month-heading gig list tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\MonthHeadingGigListExtractor;
use WP_UnitTestCase;

// Fixtures are local files, never remote requests.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
class MonthHeadingGigListExtractorTest extends WP_UnitTestCase {

	private function fixture( string $name ): string {
		return file_get_contents( __DIR__ . '/../Fixtures/month-heading-gig-list/' . $name );
	}

	/** Index events by "date title" for readable assertions. */
	private function byKey( array $events ): array {
		$indexed = array();
		foreach ( $events as $event ) {
			$indexed[ $event['startDate'] . ' ' . $event['title'] ] = $event;
		}
		return $indexed;
	}

	public function test_stumble_inn_month_sections(): void {
		$extractor = new MonthHeadingGigListExtractor();
		$html      = $this->fixture( 'stumbleinn-entertainment.html' );
		$this->assertTrue( $extractor->canExtract( $html ) );
		$this->assertSame( 'month_heading_gig_list', $extractor->getMethod() );

		$events = $extractor->extract( $html, 'https://www.stumbleinnnh.com/entertainment/' );
		// 17 November lines (one a double bill = 18 events) + 15 December lines.
		$this->assertCount( 33, $events );
		$this->assertSame( '2026-11-01', $events[0]['startDate'] );
		$this->assertSame( '2026-12-31', $events[32]['startDate'] );

		$events = $this->byKey( $events );

		$rob = $events['2026-11-01 Rob & Jody'];
		$this->assertSame( '13:00', $rob['startTime'] );
		$this->assertSame( '16:00', $rob['endTime'] );
		$this->assertSame( '', $rob['venue'] );
		$this->assertSame( '', $rob['venueCity'] );
		$this->assertSame( 'https://www.stumbleinnnh.com/entertainment/', $rob['source_url'] );

		$this->assertSame( '19:00', $events['2026-11-05 Chuck & Jon']['startTime'] );
		$this->assertSame( '22:00', $events['2026-11-05 Chuck & Jon']['endTime'] );
		$this->assertSame( '20:00', $events['2026-11-06 Small Town Stranded']['startTime'] );
		$this->assertSame( '23:00', $events['2026-11-06 Small Town Stranded']['endTime'] );
		$this->assertSame( '16:00', $events['2026-11-15 Jonny Friday']['startTime'] );
		$this->assertSame( '20:00', $events['2026-11-25 J-Lo']['startTime'] );
		$this->assertSame( '20:00', $events['2026-12-05 12 Barz']['startTime'] );
		$this->assertSame( '20:00', $events['2026-12-26 Off the Record']['startTime'] );

		// No time listed: no start time.
		$this->assertSame( '', $events['2026-12-12 Conniption Fits']['startTime'] );
		$this->assertSame( '', $events['2026-12-12 Conniption Fits']['endTime'] );

		// 9-12 ends at midnight; the start is kept and the end omitted.
		$this->assertSame( '21:00', $events['2026-12-31 Down by Ten']['startTime'] );
		$this->assertSame( '', $events['2026-12-31 Down by Ten']['endTime'] );
	}

	public function test_double_bill_splits_only_when_each_act_has_a_time(): void {
		$events = $this->byKey( ( new MonthHeadingGigListExtractor() )->extract( $this->fixture( 'stumbleinn-entertainment.html' ), 'https://example.org/' ) );

		$this->assertArrayHasKey( '2026-11-28 Lack of Principals', $events );
		$this->assertArrayHasKey( '2026-11-28 Sum x4', $events );
		$this->assertSame( '13:00', $events['2026-11-28 Lack of Principals']['startTime'] );
		$this->assertSame( '20:00', $events['2026-11-28 Sum x4']['startTime'] );
		$this->assertSame( '23:00', $events['2026-11-28 Sum x4']['endTime'] );
		$this->assertArrayNotHasKey( '2026-11-05 Chuck', $events );
	}

	public function test_explicit_meridiem_and_br_separated_lines(): void {
		$html   = '<div><h3>Live Music - March 2027</h3><p>Fri 3/5 Early Show 11am-2pm<br>Sat 3/6 Late Set 10:30pm<br>Sun. 3/7 Acoustic Duo 11-1pm<br>Mon 3/8 Night Owls 10-1</p></div>';
		$events = $this->byKey( ( new MonthHeadingGigListExtractor() )->extract( $html, 'https://example.org/' ) );

		$this->assertSame( '11:00', $events['2027-03-05 Early Show']['startTime'] );
		$this->assertSame( '14:00', $events['2027-03-05 Early Show']['endTime'] );
		$this->assertSame( '22:30', $events['2027-03-06 Late Set']['startTime'] );
		$this->assertSame( '', $events['2027-03-06 Late Set']['endTime'] );
		$this->assertSame( '22:00', $events['2027-03-08 Night Owls']['startTime'] );
		$this->assertSame( '', $events['2027-03-08 Night Owls']['endTime'] );
		// "11-1pm" starts before noon.
		$this->assertSame( '11:00', $events['2027-03-07 Acoustic Duo']['startTime'] );
		$this->assertSame( '13:00', $events['2027-03-07 Acoustic Duo']['endTime'] );
	}

	public function test_weekday_mismatch_rejects_rows_and_sections(): void {
		$extractor = new MonthHeadingGigListExtractor();

		// One bad weekday among good ones: only that row is dropped.
		$html   = '<h2>November 2026</h2><p>Sun. 11/1 Band One 1-4</p><p>Mon. 11/5 Wrong Day 7-10</p><p>Fri. 11/6 Band Two 8-11</p><p>Sat. 11/7 Band Three 8-11</p>';
		$events = $extractor->extract( $html, 'https://example.org/' );
		$this->assertSame( array( 'Band One', 'Band Two', 'Band Three' ), array_column( $events, 'title' ) );

		// Weekdays that never match the heading year: the whole section is rejected.
		$html = '<h2>November 2025</h2><p>Sun. 11/1 Band One 1-4</p><p>Thurs. 11/5 Band Two 7-10</p><p>Fri. 11/6 Band Three 8-11</p><p>Sat. 11/7 Band Four 8-11</p>';
		$this->assertFalse( $extractor->canExtract( $html ) );
		$this->assertSame( array(), $extractor->extract( $html, 'https://example.org/' ) );
	}

	public function test_requires_heading_and_three_lines(): void {
		$extractor = new MonthHeadingGigListExtractor();
		$lines     = '<p>Fri. 11/6 Band One 8-11</p><p>Sat. 11/7 Band Two 8-11</p><p>Sun. 11/8 Band Three 1-4</p>';

		$this->assertFalse( $extractor->canExtract( $lines ) );
		$this->assertFalse( $extractor->canExtract( '<h2>November 2026</h2><p>Fri. 11/6 Band One 8-11</p><p>Sat. 11/7 Band Two 8-11</p>' ) );
		$this->assertTrue( $extractor->canExtract( '<h2>November 2026</h2>' . $lines ) );
		// Lines without a weekday or a time carry too little evidence.
		$this->assertFalse( $extractor->canExtract( '<h2>November 2026</h2><p>11/6 Band One</p><p>11/7 Band Two</p><p>11/8 Band Three</p>' ) );
	}

	public function test_december_heading_rolls_january_into_next_year(): void {
		$html   = '<h2>December 2026</h2><p>Thurs. 12/31 Down by Ten 9-12</p><p>Fri. 1/1 New Year Band 8-11</p><p>Sat. 1/2 Hangover Trio 1-4</p>';
		$events = ( new MonthHeadingGigListExtractor() )->extract( $html, 'https://example.org/' );
		$this->assertSame( array( '2026-12-31', '2027-01-01', '2027-01-02' ), array_column( $events, 'startDate' ) );
	}

	public function test_negative_specials_page(): void {
		$extractor = new MonthHeadingGigListExtractor();
		$html      = $this->fixture( 'specials-negative.html' );
		$this->assertFalse( $extractor->canExtract( $html ) );
		$this->assertSame( array(), $extractor->extract( $html, 'https://example.org/specials' ) );
	}

	public function test_tour_rows_are_left_to_plain_gig_list(): void {
		$html = file_get_contents( __DIR__ . '/../Fixtures/deadonlive-tour.html' );
		$this->assertFalse( ( new MonthHeadingGigListExtractor() )->canExtract( $html ) );
	}
}
