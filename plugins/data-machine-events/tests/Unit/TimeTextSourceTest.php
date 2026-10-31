<?php
/**
 * TimeTextSource tests.
 *
 * Covers show-start extraction from detail-page text using the real
 * second-hop fixtures captured for #776 plus synthetic English cases.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\Sources\TimeTextSource;

class TimeTextSourceTest extends WP_UnitTestCase {

	private TimeTextSource $source;

	public function setUp(): void {
		parent::setUp();
		$this->source = new TimeTextSource();
	}

	/**
	 * Tickster Vadstena fixture: "Entrén öppnar 12 sep 18:00" (doors) then
	 * "Startar 12 sep 20:00" (start). The door time must not win.
	 */
	public function test_extracts_start_from_tickster_fixture(): void {
		$found = $this->source->extract( $this->fixture( 'tickster-vadstena-johnny-delaware.html' ) );

		$this->assertSame( array( 'startTime' => '20:00' ), $found );
	}

	/**
	 * Hotell Hulingen fixture: "På scen kl. 20:00".
	 */
	public function test_extracts_start_from_hotellhulingen_fixture(): void {
		$found = $this->source->extract( $this->fixture( 'hotellhulingen-hultsfred-johnny-delaware.html' ) );

		$this->assertSame( array( 'startTime' => '20:00' ), $found );
	}

	/**
	 * English doors/show pairing: the show time wins over the door time.
	 */
	public function test_show_time_wins_over_door_time(): void {
		$found = $this->source->extract( '<html><body><p>Doors 7pm / Show 8pm</p></body></html>' );

		$this->assertSame( array( 'startTime' => '20:00' ), $found );
	}

	/**
	 * A bare meridiem time is a start candidate.
	 */
	public function test_bare_meridiem_time_is_a_start(): void {
		$found = $this->source->extract( '<p>8:00PM</p>' );

		$this->assertSame( array( 'startTime' => '20:00' ), $found );
	}

	/**
	 * A bare dot-separated 24h time normalizes to colon form.
	 */
	public function test_dot_separator_time_normalizes(): void {
		$found = $this->source->extract( '<p>19.30</p>' );

		$this->assertSame( array( 'startTime' => '19:30' ), $found );
	}

	/**
	 * A doors-only page yields no start time rather than guessing.
	 */
	public function test_doors_only_page_yields_no_start(): void {
		$this->assertSame( array(), $this->source->extract( '<p>Doors 7pm</p>' ) );
		$this->assertSame( array(), $this->source->extract( '<p>Insläpp kl. 19:00</p>' ) );
		$this->assertSame( array(), $this->source->extract( '<p>Entrén öppnar 18:00</p>' ) );
	}

	/**
	 * Swedish show-start tokens classify as starts.
	 */
	public function test_swedish_show_start_tokens(): void {
		$this->assertSame( array( 'startTime' => '20:00' ), $this->source->extract( '<p>På scen kl. 20:00</p>' ) );
		$this->assertSame( array( 'startTime' => '20:00' ), $this->source->extract( '<p>Startar 12 sep 20:00</p>' ) );
	}

	/**
	 * A door label followed by a later strong start token: the door time is
	 * skipped and the start time wins.
	 */
	public function test_door_then_start_selects_the_start(): void {
		$found = $this->source->extract( '<p>Insläpp 19:00 · Startar 20:30</p>' );

		$this->assertSame( array( 'startTime' => '20:30' ), $found );
	}

	/**
	 * Times in dates (2026-09-12) are not mistaken for clock times.
	 */
	public function test_dates_are_not_clock_times(): void {
		$this->assertSame( array(), $this->source->extract( '<p>Konsert 2026-09-12, Baggelycke Gård</p>' ) );
	}

	/**
	 * provides() declares startTime only.
	 */
	public function test_provides_start_time(): void {
		$this->assertSame( array( 'startTime' ), $this->source->provides() );
		$this->assertSame( 'time_text', $this->source->getMethod() );
	}

	/**
	 * Load a detail-page fixture relative to tests/Fixtures/detail-pages.
	 */
	private function fixture( string $filename ): string {
		$path = __DIR__ . '/../Fixtures/detail-pages/' . $filename;
		if ( ! file_exists( $path ) ) {
			$this->markTestSkipped( 'Fixture not present: ' . $filename );
		}

		$html = file_get_contents( $path );
		$this->assertNotFalse( $html, 'Fixture must be readable: ' . $filename );

		return $html;
	}
}
