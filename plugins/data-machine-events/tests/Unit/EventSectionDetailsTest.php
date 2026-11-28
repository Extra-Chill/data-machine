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

	public function test_non_english_detail_paths_are_followed(): void {
		$dom = new \DOMDocument();
		$dom->loadHTML( '<div><a href="/programma">Programma</a>'
			. '<a href="/agenda/bezerkus-bingo-halloween-01-nov-2026">Bezerkus Bingo</a></div>' );
		$links = EventSectionDetails::links( $dom->documentElement, 'https://gebrdenobel.nl/programma' );
		$this->assertSame( array( 'https://gebrdenobel.nl/agenda/bezerkus-bingo-halloween-01-nov-2026' ), array_keys( $links ) );
	}

	public function test_day_first_abbreviated_and_non_english_months_are_dates(): void {
		$this->assertTrue( EventSectionDetails::hasDate( '<p>zo. 01 nov.</p>' ) );
		$this->assertTrue( EventSectionDetails::hasDate( '<p>1 déc 2026</p>' ) );
		$this->assertTrue( EventSectionDetails::hasDate( '<p>3 mär</p>' ) );
		$this->assertFalse( EventSectionDetails::hasDate( '<p>10 decibels of feedback</p>' ) );
		$this->assertFalse( EventSectionDetails::hasDate( '<p>2 shows tonight</p>' ) );
	}

	public function test_title_text_ignores_weekdays_entities_and_bare_times(): void {
		$this->assertFalse( EventSectionDetails::hasTitleText( '<li class="day">SUN</li>' ) );
		$this->assertFalse( EventSectionDetails::hasTitleText( '<div>&nbsp;</div>' ) );
		$this->assertFalse( EventSectionDetails::hasTitleText( '<div>18+ Doors 7:30PM Show 8:00PM</div>' ) );
		$this->assertTrue( EventSectionDetails::hasTitleText( '<div>Sunset Rubdown Dec 12</div>' ) );
		$this->assertTrue( EventSectionDetails::hasTitleText( '<div><img alt="The Band"> Fri 8pm</div>' ) );
	}
}
