<?php
/**
 * WordPress extractor source identity regression tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\WordPressExtractor;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use WP_UnitTestCase;

class WordPressExtractorIdentityTest extends WP_UnitTestCase {

	public function test_charleston_pour_house_tribe_times_reach_source_identity(): void {
		$extractor = new WordPressExtractor();
		$payload   = wp_json_encode(
			array(
				'rest_url' => 'https://charlestonpourhouse.com/wp-json/tribe/events/v1/',
				'events'   => array(
					array(
						'title'      => 'Motown Throwdown',
						'start_date' => '2026-04-26 13:30:00',
						'venue'      => array( 'venue' => 'Charleston Pour House' ),
					),
					array(
						'title'      => 'Motown Throwdown',
						'start_date' => '2026-04-26 21:30:00',
						'venue'      => array( 'venue' => 'Charleston Pour House' ),
					),
				),
			)
		);

		$events = $extractor->extract( $payload, 'https://charlestonpourhouse.com/events/' );

		$this->assertCount( 2, $events );
		$this->assertSame( '13:30', $events[0]['startTime'] );
		$this->assertSame( '21:30', $events[1]['startTime'] );
		$this->assertNotSame(
			EventIdentifierGenerator::generate( $events[0]['title'], $events[0]['startDate'], $events[0]['venue'], $events[0]['startTime'] ),
			EventIdentifierGenerator::generate( $events[1]['title'], $events[1]['startDate'], $events[1]['venue'], $events[1]['startTime'] )
		);
	}
}
