<?php
/**
 * DetailPageEnricher tests.
 *
 * Covers gap-only filling, the follow cap, candidate URL ordering,
 * provenance recording, and no-fetch skipping. All tests inject a fake
 * fetcher — no live HTTP.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\DetailPageEnricher;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\Sources\TimeTextSource;

class DetailPageEnricherTest extends WP_UnitTestCase {

	private ExecutionContext $context;

	public function setUp(): void {
		parent::setUp();
		$this->context = ExecutionContext::direct( 'universal_web_scraper' );
	}

	/**
	 * Empty fields are filled from the followed page; non-empty fields are
	 * never overwritten.
	 */
	public function test_fills_only_empty_fields(): void {
		$pages = array(
			'https://venue.example/show/1' => '<html><body><p>Startar 20:00</p></body></html>',
		);

		$enricher = $this->makeEnricher( $pages );
		$events   = array(
			array(
				'title'      => 'Empty time',
				'startTime'  => '',
				'source_url' => 'https://venue.example/show/1',
			),
			array(
				'title'      => 'Has time',
				'startTime'  => '19:00',
				'source_url' => 'https://venue.example/show/1',
			),
		);

		$enriched = $enricher->run( $events, array( 'startTime' ), 'https://venue.example/calendar', $this->context );

		$this->assertSame( '20:00', $enriched[0]['startTime'] );
		$this->assertSame( '19:00', $enriched[1]['startTime'], 'A non-empty listing time must never be overwritten.' );
	}

	/**
	 * The follow cap bounds fetches per run; events beyond the cap pass
	 * through untouched.
	 */
	public function test_respects_follow_cap(): void {
		$calls   = array();
		$pages   = array(
			'https://venue.example/show/1' => '<p>Startar 20:00</p>',
			'https://venue.example/show/2' => '<p>Startar 21:00</p>',
		);
		$fetcher = $this->recordingFetcher( $pages, $calls );

		$enricher = new DetailPageEnricher( $fetcher, null, 1 );
		$events   = array(
			array(
				'title'      => 'One',
				'startTime'  => '',
				'source_url' => 'https://venue.example/show/1',
			),
			array(
				'title'      => 'Two',
				'startTime'  => '',
				'source_url' => 'https://venue.example/show/2',
			),
		);

		$enriched = $enricher->run( $events, array( 'startTime' ), 'https://venue.example/calendar', $this->context );

		$this->assertCount( 1, $calls, 'Only one follow may happen at cap 1.' );
		$this->assertSame( '20:00', $enriched[0]['startTime'] );
		$this->assertSame( '', $enriched[1]['startTime'], 'Events beyond the cap stay untouched.' );
	}

	/**
	 * Events with no candidate URL are skipped without any fetch.
	 */
	public function test_skips_events_without_candidate_urls(): void {
		$calls   = array();
		$fetcher = $this->recordingFetcher( array(), $calls );

		$enricher = new DetailPageEnricher( $fetcher );
		$events   = array(
			array(
				'title'      => 'No URLs',
				'startTime'  => '',
				'source_url' => '',
				'ticketUrl'  => '',
			),
		);

		$enriched = $enricher->run( $events, array( 'startTime' ), 'https://venue.example/calendar', $this->context );

		$this->assertSame( array(), $calls, 'No fetch may happen without a candidate URL.' );
		$this->assertSame( '', $enriched[0]['startTime'] );
	}

	/**
	 * Candidate order: same-host source_url first, then ticketUrl. A
	 * cross-host source_url is not followed.
	 */
	public function test_candidate_url_order_and_same_host_filter(): void {
		$calls   = array();
		$pages   = array(
			'https://venue.example/show/3'  => '<p>no time here</p>',
			'https://tickets.example/buy/3' => '<p>Startar 20:00</p>',
		);
		$fetcher = $this->recordingFetcher( $pages, $calls );

		$enricher = new DetailPageEnricher( $fetcher );
		$events   = array(
			array(
				'title'      => 'Ordered',
				'startTime'  => '',
				'source_url' => 'https://venue.example/show/3',
				'ticketUrl'  => 'https://tickets.example/buy/3',
			),
			array(
				'title'      => 'Cross-host detail skipped',
				'startTime'  => '',
				'source_url' => 'https://other.example/show/9',
				'ticketUrl'  => 'https://tickets.example/buy/3',
			),
		);

		$enriched = $enricher->run( $events, array( 'startTime' ), 'https://venue.example/calendar', $this->context );

		$this->assertSame(
			array( 'https://venue.example/show/3', 'https://tickets.example/buy/3', 'https://tickets.example/buy/3' ),
			$calls,
			'Same-host detail page must be tried before the ticket URL; cross-host detail pages must not be followed.'
		);
		$this->assertSame( '20:00', $enriched[0]['startTime'] );
		$this->assertSame( '20:00', $enriched[1]['startTime'] );
	}

	/**
	 * Every filled field records provenance: value, source method, URL.
	 */
	public function test_records_provenance(): void {
		$pages = array(
			'https://venue.example/show/5' => '<html><body><p>På scen kl. 20:00</p></body></html>',
		);

		$enricher = $this->makeEnricher( $pages );
		$events   = array(
			array(
				'title'      => 'Provenance',
				'startTime'  => '',
				'source_url' => 'https://venue.example/show/5',
			),
		);

		$enriched = $enricher->run( $events, array( 'startTime' ), 'https://venue.example/calendar', $this->context );

		$this->assertSame(
			array(
				'value'  => '20:00',
				'source' => 'time_text',
				'url'    => 'https://venue.example/show/5',
			),
			$enriched[0]['enrichment']['startTime']
		);
		$this->assertSame( array( 'time_text' ), $enricher->getLastRunSources() );
	}

	/**
	 * shouldEnrich() reflects missing fields plus candidate URLs.
	 */
	public function test_should_enrich_contract(): void {
		$enricher = new DetailPageEnricher( null );

		$needs_enrichment = array(
			'startTime' => '',
			'ticketUrl' => 'https://t.example/x',
		);
		$already_filled = array(
			'startTime' => '20:00',
			'ticketUrl' => 'https://t.example/x',
		);

		$this->assertTrue( $enricher->shouldEnrich( $needs_enrichment ) );
		$this->assertFalse( $enricher->shouldEnrich( $already_filled ) );
		$this->assertFalse( $enricher->shouldEnrich( array( 'startTime' => '' ) ) );
	}

	/**
	 * provides() is the union of the registered sources.
	 */
	public function test_provides_union_of_sources(): void {
		$enricher = new DetailPageEnricher( null );

		$this->assertSame( array( 'startTime' ), $enricher->provides() );
		$this->assertSame( 'detail_page', $enricher->getMethod() );
	}

	/**
	 * Enricher with a fake fetcher and only the time-text source.
	 *
	 * @param array $pages URL => HTML map.
	 */
	private function makeEnricher( array $pages ): DetailPageEnricher {
		return new DetailPageEnricher(
			static fn( string $url ): ?string => $pages[ $url ] ?? null,
			array( new TimeTextSource() )
		);
	}

	/**
	 * Fake fetcher that records every call order into &$calls.
	 *
	 * @param array $pages URL => HTML map.
	 * @param array $calls Output accumulator.
	 * @return callable
	 */
	private function recordingFetcher( array $pages, array &$calls ): callable {
		return static function ( string $url ) use ( $pages, &$calls ): ?string {
			$calls[] = $url;
			return $pages[ $url ] ?? null;
		};
	}
}
