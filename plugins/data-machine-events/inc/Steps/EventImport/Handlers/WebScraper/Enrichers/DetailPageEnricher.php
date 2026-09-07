<?php
/**
 * Detail page enricher (second-hop enrichment stage).
 *
 * Follows per-event candidate URLs — the same-host event detail page
 * first, then the external ticket URL — and fills missing event fields
 * from the fetched page via registered sources (JSON-LD, visible time
 * text). Extractors opt in per field via needsDetailEnrichment(); the
 * stage never overwrites a value the listing page already provided.
 *
 * Bounded by policy owned here rather than re-decided per extractor:
 * max follows per fetch run, single attempt per URL, no retries, all
 * requests through BaseExtractor::fetchUrl() so existing HTTP guards
 * apply. Provenance for every filled field is recorded on the event
 * under an `enrichment` key (source method + URL), which surfaces in the
 * data packet's raw_source and the test-event-scraper output.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers;

use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\Sources\JsonLdEventSource;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\Sources\SourceInterface;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\Sources\TimeTextSource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DetailPageEnricher implements EnricherInterface {

	/**
	 * Maximum detail-page follows per fetch run (one executeFetch cycle).
	 */
	public const MAX_FOLLOWS_PER_RUN = 20;

	/**
	 * Enrichment sources, applied in order to each fetched page.
	 *
	 * @var SourceInterface[]
	 */
	private array $sources;

	/**
	 * Fetch transport: fn( string $url ): ?string.
	 *
	 * @var callable
	 */
	private $fetcher;

	/**
	 * Follows remaining in the current fetch run.
	 *
	 * @var int
	 */
	private int $remaining_follows;

	/**
	 * Source methods that filled at least one field during the last run().
	 *
	 * @var string[]
	 */
	private array $last_run_sources = array();

	/**
	 * @param callable|null         $fetcher     Optional fetch override; defaults to DetailPageFetcher (HttpClient + guards).
	 * @param SourceInterface[]|null $sources    Optional source override; defaults to JSON-LD + time text.
	 * @param int|null              $max_follows Optional cap override for tests.
	 */
	public function __construct( ?callable $fetcher = null, ?array $sources = null, ?int $max_follows = null ) {
		$this->fetcher           = $fetcher ?? static fn( string $url ): ?string => ( new DetailPageFetcher() )->fetch( $url );
		$this->sources           = $sources ?? array( new JsonLdEventSource(), new TimeTextSource() );
		$this->remaining_follows = $max_follows ?? self::MAX_FOLLOWS_PER_RUN;
	}

	/** {@inheritdoc} */
	public function provides(): array {
		$fields = array();
		foreach ( $this->sources as $source ) {
			foreach ( $source->provides() as $field ) {
				$fields[ $field ] = true;
			}
		}
		return array_keys( $fields );
	}

	/** {@inheritdoc} */
	public function shouldEnrich( array $event ): bool {
		foreach ( $this->provides() as $field ) {
			if ( empty( $event[ $field ] ) && $this->hasCandidateUrl( $event ) ) {
				return true;
			}
		}
		return false;
	}

	/** {@inheritdoc} */
	public function enrich( array $event, string $source_url, ExecutionContext $context ): array {
		$result = $this->enrichEvent( $event, $this->provides(), $source_url, $context );
		return $result['event'];
	}

	/** {@inheritdoc} */
	public function getMethod(): string {
		return 'detail_page';
	}

	/**
	 * Reset the per-run follow budget.
	 *
	 * Called by UniversalWebScraper at the start of each fetch cycle so
	 * the cap spans all pages and extractors within one run.
	 */
	public function resetBudget(): void {
		$this->remaining_follows = self::MAX_FOLLOWS_PER_RUN;
	}

	/**
	 * Source methods that filled at least one field during the last run().
	 *
	 * Used to extend extraction_method provenance
	 * (e.g. "bandzoogle+detail:time_text").
	 *
	 * @return string[]
	 */
	public function getLastRunSources(): array {
		return $this->last_run_sources;
	}

	/**
	 * Enrich a batch of extracted events.
	 *
	 * Only events missing one of the requested fields with a candidate URL
	 * are followed; the rest pass through untouched. A single summary log
	 * line is emitted per call so test-event-scraper can surface the
	 * second hop.
	 *
	 * @param array<int, array<string, mixed>> $events      Extracted events.
	 * @param string[]                         $fields      Fields the calling extractor wants enriched.
	 * @param string                           $listing_url Listing page URL the events were extracted from.
	 * @param ExecutionContext                 $context     Execution context for logging.
	 * @return array<int, array<string, mixed>> Events with enrichment applied.
	 */
	public function run( array $events, array $fields, string $listing_url, ExecutionContext $context ): array {
		$this->last_run_sources = array();

		$active_fields = array_intersect( $fields, $this->provides() );
		if ( empty( $active_fields ) ) {
			return $events;
		}

		$followed = 0;
		$filled   = array();
		$skipped  = 0;

		foreach ( $events as $index => $event ) {
			if ( $this->remaining_follows <= 0 || ! $this->needsFields( $event, $active_fields ) ) {
				++$skipped;
				continue;
			}

			$result           = $this->enrichEvent( $event, $active_fields, $listing_url, $context );
			$events[ $index ] = $result['event'];
			$followed        += $result['followed'];

			foreach ( $result['filled'] as $field ) {
				$filled[ $field ] = ( $filled[ $field ] ?? 0 ) + 1;
			}
		}

		$context->log(
			'info',
			'Detail Page Enricher: Run summary',
			array(
				'followed' => $followed,
				'filled'   => $filled,
				'skipped'  => $skipped,
				'fields'   => array_values( $active_fields ),
			)
		);

		return $events;
	}

	/**
	 * Follow candidate URLs for one event and fill empty fields.
	 *
	 * @param array<string, mixed> $event       Event data (filled fields merged into the returned copy).
	 * @param string[]             $fields      Fields eligible for filling.
	 * @param string               $listing_url Listing page URL for same-host candidate resolution.
	 * @param ExecutionContext     $context     Execution context for logging.
	 * @return array{event: array<string, mixed>, followed: int, filled: string[]}
	 */
	private function enrichEvent( array $event, array $fields, string $listing_url, ExecutionContext $context ): array {
		$followed     = 0;
		$filled_total = array();

		foreach ( $this->candidateUrls( $event, $listing_url ) as $url ) {
			if ( $this->remaining_follows <= 0 || $this->hasAllFields( $event, $fields ) ) {
				break;
			}

			--$this->remaining_follows;
			++$followed;

			$html = call_user_func( $this->fetcher, $url );
			if ( empty( $html ) ) {
				$context->log(
					'debug',
					'Detail Page Enricher: Follow failed or empty',
					array(
						'url'    => $url,
						'status' => 'fetch_failed',
						'filled' => array(),
					)
				);
				continue;
			}

			$filled       = $this->applySources( $event, $fields, $html, $url );
			$filled_total = array_merge( $filled_total, $filled );

			$context->log(
				'debug',
				'Detail Page Enricher: Followed detail page',
				array(
					'url'    => $url,
					'status' => 'ok',
					'filled' => $filled,
				)
			);
		}

		return array(
			'event'    => $event,
			'followed' => $followed,
			'filled'   => array_values( array_unique( $filled_total ) ),
		);
	}

	/**
	 * Candidate follow URLs in priority order.
	 *
	 * Same-host event detail page first, then the external ticket URL.
	 * Duplicates are dropped.
	 *
	 * @param array<string, mixed> $event       Event data.
	 * @param string               $listing_url Listing page URL.
	 * @return string[]
	 */
	private function candidateUrls( array $event, string $listing_url ): array {
		$candidates  = array();
		$source_host = wp_parse_url( $listing_url, PHP_URL_HOST );

		$event_source_url = (string) ( $event['source_url'] ?? '' );
		if (
			'' !== $event_source_url
			&& null !== $source_host
			&& wp_parse_url( $event_source_url, PHP_URL_HOST ) === $source_host
		) {
			$candidates[] = $event_source_url;
		}

		$ticket_url = (string) ( $event['ticketUrl'] ?? '' );
		if ( '' !== $ticket_url && ! in_array( $ticket_url, $candidates, true ) ) {
			$candidates[] = $ticket_url;
		}

		return $candidates;
	}

	/**
	 * Run all sources over a fetched page and merge fills into the event.
	 *
	 * Only empty fields are filled; every fill records provenance under
	 * $event['enrichment'][field] = array( value, source, url ).
	 *
	 * @param array<string, mixed> $event  Event data (modified by reference).
	 * @param string[]             $fields Fields eligible for filling.
	 * @param string $html   Fetched page HTML.
	 * @param string $url    URL the HTML was fetched from.
	 * @return string[] Field names filled by this page.
	 */
	private function applySources( array &$event, array $fields, string $html, string $url ): array {
		$filled = array();

		foreach ( $this->sources as $source ) {
			$found = $source->extract( $html, $url );
			if ( empty( $found ) ) {
				continue;
			}

			foreach ( $found as $field => $value ) {
				if ( ! in_array( $field, $fields, true ) || empty( $value ) || ! empty( $event[ $field ] ) ) {
					continue;
				}

				$event[ $field ]               = (string) $value;
				$event['enrichment'][ $field ] = array(
					'value'  => (string) $value,
					'source' => $source->getMethod(),
					'url'    => $url,
				);
				$filled[]                      = $field;

				if ( ! in_array( $source->getMethod(), $this->last_run_sources, true ) ) {
					$this->last_run_sources[] = $source->getMethod();
				}
			}
		}

		return $filled;
	}

	/**
	 * Whether the event is missing any of the given fields and has a URL to try.
	 *
	 * @param array<string, mixed> $event  Event data.
	 * @param string[]             $fields Fields to check.
	 * @return bool
	 */
	private function needsFields( array $event, array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( empty( $event[ $field ] ) && $this->hasCandidateUrl( $event ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether every given field is non-empty.
	 *
	 * @param array<string, mixed> $event  Event data.
	 * @param string[]             $fields Fields to check.
	 * @return bool
	 */
	private function hasAllFields( array $event, array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( empty( $event[ $field ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the event carries any candidate URL at all.
	 *
	 * @param array<string, mixed> $event Event data.
	 * @return bool
	 */
	private function hasCandidateUrl( array $event ): bool {
		return '' !== (string) ( $event['source_url'] ?? '' )
			|| '' !== (string) ( $event['ticketUrl'] ?? '' );
	}
}
