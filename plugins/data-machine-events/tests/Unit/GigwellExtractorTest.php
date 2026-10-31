<?php
/**
 * Gigwell extractor timezone tests (#907).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\GigwellExtractor;

class GigwellExtractorTest extends WP_UnitTestCase {

	public function test_source_zone_is_used_and_offset_reported(): void {
		$event = $this->normalize(
			array(
				'title'         => 'Zoned Show',
				'startDateTime' => '2026-10-12T03:00:00Z',
				'eventTimeZone' => 'America/Los_Angeles',
			)
		);

		$this->assertSame( '2026-10-11', $event['startDate'] );
		$this->assertSame( '20:00', $event['startTime'] );
		$this->assertSame( '-07:00', $event['startOffset'] );
		$this->assertSame( 'America/Los_Angeles', $event['venueTimezone'] );
	}

	public function test_missing_source_zone_is_not_guessed(): void {
		$event = $this->normalize(
			array(
				'title'         => 'Unzoned Show',
				'startDateTime' => '2026-10-12T03:00:00Z',
			)
		);

		$this->assertSame( '2026-10-12', $event['startDate'] );
		$this->assertSame( '03:00', $event['startTime'] );
		$this->assertSame( '+00:00', $event['startOffset'] );
		$this->assertSame( '', $event['venueTimezone'] );
	}

	private function normalize( array $raw ): array {
		$method = new \ReflectionMethod( GigwellExtractor::class, 'normalizeEvent' );
		$method->setAccessible( true );
		return $method->invoke( new GigwellExtractor(), $raw );
	}
}
