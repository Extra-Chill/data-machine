<?php
/** Conservative detail-link and date detection regression tests. */
namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\EventSectionDetails;
use WP_UnitTestCase;

class EventSectionDetailsTest extends WP_UnitTestCase {
	public function test_only_distinct_same_site_detail_links_are_candidates(): void {
		$dom = new \DOMDocument();
		$dom->loadHTML( '<div><a href="/events/">Index</a><a href="#filter">Filter</a>'
			. '<a href="https://tickets.example.net/event/show/">Tickets</a>'
			. '<a href="https://www.example.com/event/show/">Show</a>'
			. '<a href="https://www.example.com/event/show/#tickets">More</a></div>' );
		$links = EventSectionDetails::links( $dom->documentElement, 'http://example.com' );
		$this->assertSame( array( 'https://www.example.com/event/show/' ), array_keys( $links ) );
	}

	public function test_teasers_missing_either_date_or_time_need_enrichment(): void {
		$this->assertFalse( EventSectionDetails::hasDateAndTime( '<p>December 12, 2099</p>' ) );
		$this->assertFalse( EventSectionDetails::hasDateAndTime( '<p>Show at 7:30pm</p>' ) );
		$this->assertTrue( EventSectionDetails::hasDateAndTime( '<p>December 12, 2099 at 7:30pm</p>' ) );
		$this->assertTrue( EventSectionDetails::hasDateAndTime( '<p>2099-12-12 at 19:30</p>' ) );
	}
}
