<?php
/**
 * EventSectionFinder tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\FreshCandidateCollector;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\EventSectionFinder;
use WP_UnitTestCase;

class EventSectionFinderTest extends WP_UnitTestCase {

	public function test_processed_first_section_does_not_stop_scan_for_later_fresh_section(): void {
		$context = $this->createMock( ExecutionContext::class );
		$context->method( 'isDirect' )->willReturn( true );
		$context->expects( $this->exactly( 2 ) )
			->method( 'isItemProcessed' )
			->willReturnOnConsecutiveCalls( true, false );
		$context->expects( $this->once() )
			->method( 'isItemClaimed' )
			->willReturn( false );

		$collector = new FreshCandidateCollector( $context );
		$finder    = new EventSectionFinder(
			$collector,
			fn ( string $html ): string => trim( $html ),
			fn ( string $ymd ): bool => false
		);

		$html = '<html><body>'
			. '<div class="event"><h2>Processed Show</h2>'
			. '<p>This processed event section has enough content to pass the length checks.</p></div>'
			. '<div class="event"><h2>Fresh Show</h2>'
			. '<p>This fresh event section has enough content to pass the length checks.</p></div>'
			. '</body></html>';

		$section = $finder->find_first_eligible_section( $html, 'https://example.com/events', $context );

		$this->assertNotNull( $section );
		$this->assertStringContainsString( 'Fresh Show', $section['raw_html'] );
		$this->assertSame(
			array(
				'raw_seen'           => 2,
				'accepted'           => 1,
				'processed_skipped'  => 1,
				'claimed_skipped'    => 0,
				'duplicate_skipped'  => 0,
				'reprocess_accepted' => 0,
				'max_items'          => 0,
				'source_exhausted'   => false,
			),
			$collector->getDiagnostics()
		);
	}
}
