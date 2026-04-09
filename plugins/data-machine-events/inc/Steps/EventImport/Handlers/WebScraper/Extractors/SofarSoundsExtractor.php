<?php
/**
 * Sofar Sounds extractor.
 *
 * Extracts event data from Sofar Sounds by detecting their React SPA
 * and querying their public GraphQL API directly.
 *
 * Sofar Sounds is a global live music platform operating in 659+ cities
 * worldwide. Events are secret-location shows with curated lineups.
 * Venue details are typically hidden until after ticket purchase.
 *
 * The Sofar website is a client-rendered React SPA (<div id="__app">),
 * so static HTML fetching returns zero event data. This extractor bypasses
 * the client-side rendering by going straight to the GraphQL data source.
 *
 * Detection: looks for sofarsounds.com assets + __app root div in HTML.
 * Extraction: POST to GraphQL GetEventsForFan with city slug from URL.
 *
 * No API key or authentication is required — the GraphQL endpoint serves
 * public event data to unauthenticated requests.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 * @since   0.28.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SofarSoundsExtractor extends BaseExtractor {

	/**
	 * GraphQL endpoint with operation name for server-side routing.
	 */
	const GRAPHQL_ENDPOINT = 'https://www.sofarsounds.com/api/v2/graphql?on=GetEventsForFan';

	/**
	 * GraphQL query for fetching paginated upcoming events by city.
	 *
	 * Field selection derived from Sofar Sounds frontend Apollo queries.
	 */
	const EVENTS_QUERY = <<<'GRAPHQL'
query GetEventsForFan(
	$city: String
	$upcoming: Boolean
	$published: Boolean
	$excludeCancelled: Boolean
	$perPage: Int
	$page: Int
) {
	events(
		city: $city
		upcoming: $upcoming
		published: $published
		excludeCancelled: $excludeCancelled
		perPage: $perPage
		page: $page
	) {
		events {
			id
			cachedSlug
			headline
			localStartsAt
			startsAt
			endsAt
			cancelled
			isSoldOut
			isPurchasable
			ticketPrice
			attendeeFlow
			imageUrl
			venue {
				id
				venueName
				address
				latitude
				longitude
			}
			city {
				id
				title
				cachedSlug
				timezone
				currencyCode
			}
			artists {
				id
				title
				cachedSlug
			}
		}
		metadata {
			totalRecords
			currentPage
		}
	}
}
GRAPHQL;

	/**
	 * Maximum pages to paginate through per city.
	 */
	const MAX_PAGES = 10;

	/**
	 * Events per GraphQL page.
	 */
	const PER_PAGE = 20;

	/**
	 * Check if this page is a Sofar Sounds React SPA.
	 *
	 * Detects the Sofar SPA by looking for sofarsounds.com asset URLs
	 * alongside the React root div. This combination is unique to Sofar.
	 *
	 * @param string $html HTML content to check.
	 * @return bool True if Sofar Sounds page detected.
	 */
	public function canExtract( string $html ): bool {
		return false !== strpos( $html, 'sofarsounds.com' )
			&& false !== strpos( $html, '__app' );
	}

	/**
	 * Extract events by querying the Sofar GraphQL API for the given city.
	 *
	 * Parses the city slug from the source URL, then paginates through
	 * all upcoming published events via GraphQL.
	 *
	 * @param string $html       HTML content (React SPA shell — ignored).
	 * @param string $source_url Source URL containing the city slug.
	 * @return array Array of normalized event arrays.
	 */
	public function extract( string $html, string $source_url ): array {
		$city_slug = $this->extractCitySlug( $source_url );
		if ( empty( $city_slug ) ) {
			return array();
		}

		$raw_events = $this->fetchAllEvents( $city_slug );
		if ( empty( $raw_events ) ) {
			return array();
		}

		$events = array();
		foreach ( $raw_events as $raw_event ) {
			if ( ! empty( $raw_event['cancelled'] ) ) {
				continue;
			}

			$normalized = $this->normalizeEvent( $raw_event );
			if ( ! empty( $normalized['title'] ) && ! empty( $normalized['startDate'] ) ) {
				$events[] = $normalized;
			}
		}

		return $events;
	}

	/**
	 * Get the extraction method identifier.
	 *
	 * @return string Method identifier.
	 */
	public function getMethod(): string {
		return 'sofar_sounds';
	}

	/**
	 * Extract city slug from the source URL.
	 *
	 * Supports both URL patterns:
	 * - /cities/{slug}  (e.g., /cities/charleston-sc)
	 * - /{slug}         (e.g., /charleston-sc — direct city URL)
	 *
	 * @param string $url Source URL.
	 * @return string|null City slug or null if not found.
	 */
	private function extractCitySlug( string $url ): ?string {
		// Pattern 1: /cities/{slug}
		if ( preg_match( '#sofarsounds\.com/cities/([a-z0-9-]+)#i', $url, $matches ) ) {
			return $matches[1];
		}

		// Pattern 2: /{slug} (direct city URL — slugs are 3+ chars, start with letter)
		if ( preg_match( '#sofarsounds\.com/([a-z][a-z0-9-]{2,})#i', $url, $matches ) ) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * Fetch all upcoming events for a city via GraphQL with pagination.
	 *
	 * Uses accumulator pattern — collects events from all pages before returning.
	 *
	 * @param string $city_slug Sofar city slug (e.g., "charleston-sc").
	 * @return array Raw event objects from the API.
	 */
	private function fetchAllEvents( string $city_slug ): array {
		$all_events = array();
		$page       = 1;

		do {
			$response = $this->queryGraphQL( $city_slug, $page );

			if ( null === $response ) {
				break;
			}

			$events_data = $response['data']['events'] ?? null;
			if ( null === $events_data ) {
				break;
			}

			$page_events = $events_data['events'] ?? array();
			$metadata    = $events_data['metadata'] ?? array();

			if ( empty( $page_events ) ) {
				break;
			}

			$all_events = array_merge( $all_events, $page_events );

			$current_page  = (int) ( $metadata['currentPage'] ?? $page );
			$total_records = (int) ( $metadata['totalRecords'] ?? 0 );
			$total_pages   = (int) ceil( $total_records / self::PER_PAGE );

			if ( $current_page >= $total_pages ) {
				break;
			}

			++$page;
		} while ( $page <= self::MAX_PAGES );

		return $all_events;
	}

	/**
	 * Execute a GraphQL query against Sofar Sounds.
	 *
	 * @param string $city_slug City slug.
	 * @param int    $page      Page number.
	 * @return array|null Decoded JSON response or null on failure.
	 */
	private function queryGraphQL( string $city_slug, int $page ): ?array {
		$payload = wp_json_encode(
			array(
				'query'     => self::EVENTS_QUERY,
				'variables' => array(
					'city'             => $city_slug,
					'upcoming'         => true,
					'published'        => true,
					'excludeCancelled' => true,
					'perPage'          => self::PER_PAGE,
					'page'             => $page,
				),
			)
		);

		$result = HttpClient::post(
			self::GRAPHQL_ENDPOINT,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => $payload,
				'context' => 'Sofar Sounds GraphQL',
			)
		);

		if ( empty( $result['success'] ) || 200 !== ( $result['status_code'] ?? 0 ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'SofarSoundsExtractor: GraphQL request failed',
				array(
					'status_code' => $result['status_code'] ?? 0,
					'city_slug'   => $city_slug,
					'page'        => $page,
				)
			);
			return null;
		}

		$body = $result['data'] ?? ( $result['body'] ?? '' );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return null;
		}

		if ( ! empty( $data['errors'] ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'SofarSoundsExtractor: GraphQL returned errors',
				array(
					'errors'    => $data['errors'],
					'city_slug' => $city_slug,
				)
			);
			return null;
		}

		return $data;
	}

	/**
	 * Normalize a Sofar Sounds event to the standard event format.
	 *
	 * Handles Sofar-specific quirks:
	 * - Venue names are often null (secret locations)
	 * - Artists may be null (announced later)
	 * - ticketPrice is in cents (e.g., 2500 = $25.00)
	 * - headline may be null (use artist names or city as fallback)
	 *
	 * @param array $event Raw event from GraphQL response.
	 * @return array Normalized event array.
	 */
	private function normalizeEvent( array $event ): array {
		$event_id    = (int) ( $event['id'] ?? 0 );
		$city_title  = $this->sanitizeText( $event['city']['title'] ?? '' );
		$city_slug   = $event['city']['cachedSlug'] ?? '';
		$timezone    = $event['city']['timezone'] ?? '';

		// Title: prefer headline → artists → fallback to city name.
		$title = $this->resolveTitle( $event, $city_title );

		// Date/time from UTC timestamps with city timezone.
		$utc_starts = $event['startsAt'] ?? '';
		$utc_ends   = $event['endsAt'] ?? '';

		$start_parsed = ! empty( $utc_starts )
			? $this->parseUtcDatetime( $utc_starts, $timezone )
			: array( 'date' => '', 'time' => '' );

		$end_parsed = ! empty( $utc_ends )
			? $this->parseUtcDatetime( $utc_ends, $timezone )
			: array( 'date' => '', 'time' => '' );

		// Venue — often secret/unlisted for Sofar shows.
		$venue_name    = $this->sanitizeText( $event['venue']['venueName'] ?? '' );
		$venue_address = $this->sanitizeText( $event['venue']['address'] ?? '' );
		$venue_lat     = $event['venue']['latitude'] ?? '';
		$venue_lng     = $event['venue']['longitude'] ?? '';
		$venue_coords  = '';
		if ( '' !== $venue_lat && '' !== $venue_lng ) {
			$venue_coords = $venue_lat . ',' . $venue_lng;
		}

		// Price — ticketPrice is in cents.
		$price_cents = (int) ( $event['ticketPrice'] ?? 0 );
		$currency    = strtoupper( $event['city']['currencyCode'] ?? 'USD' );
		$price       = '';
		if ( $price_cents > 0 ) {
			$price = $this->formatStructuredPrice( $price_cents / 100, null, $currency );
		}

		// Ticket URL — link to Sofar event page.
		$ticket_url = '';
		if ( $event_id > 0 ) {
			$ticket_url = 'https://www.sofarsounds.com/events/' . $event_id;
		}

		// Image.
		$image_url = ! empty( $event['imageUrl'] ) ? esc_url_raw( $event['imageUrl'] ) : '';

		// Build description from available context.
		$description = $this->buildDescription( $event, $city_title );

		return array(
			'title'            => $title,
			'description'      => $description,
			'startDate'        => $start_parsed['date'],
			'endDate'          => $end_parsed['date'],
			'startTime'        => $start_parsed['time'],
			'endTime'          => $end_parsed['time'],
			'venue'            => $venue_name,
			'venueAddress'     => $venue_address,
			'venueCity'        => $city_title,
			'venueTimezone'    => $timezone,
			'venueCoordinates' => $venue_coords,
			'ticketUrl'        => $ticket_url,
			'imageUrl'         => $image_url,
			'price'            => $price,
			'organizer'        => 'Sofar Sounds',
			'sourceId'         => 'sofar_' . $event_id,
		);
	}

	/**
	 * Resolve the event title from available data.
	 *
	 * Priority: headline → artist names → "Sofar Sounds {City}"
	 *
	 * @param array  $event      Raw event data.
	 * @param string $city_title City display name.
	 * @return string Resolved title.
	 */
	private function resolveTitle( array $event, string $city_title ): string {
		// Best case: explicit headline.
		$headline = $this->sanitizeText( $event['headline'] ?? '' );
		if ( ! empty( $headline ) ) {
			return $headline;
		}

		// Fall back to artist names.
		$artists = $event['artists'] ?? array();
		if ( ! empty( $artists ) ) {
			$names = array();
			foreach ( $artists as $artist ) {
				$name = $this->sanitizeText( $artist['title'] ?? '' );
				if ( '' !== $name ) {
					$names[] = $name;
				}
			}
			if ( ! empty( $names ) ) {
				return implode( ', ', $names );
			}
		}

		// Last resort: generic title with city.
		return 'Sofar Sounds ' . $city_title;
	}

	/**
	 * Build a descriptive summary from available event context.
	 *
	 * @param array  $event      Raw event data.
	 * @param string $city_title City display name.
	 * @return string Description.
	 */
	private function buildDescription( array $event, string $city_title ): string {
		$parts = array();

		$parts[] = 'Sofar Sounds secret show in ' . $city_title . '.';

		// Artists.
		$artists = $event['artists'] ?? array();
		if ( ! empty( $artists ) ) {
			$names = array();
			foreach ( $artists as $artist ) {
				$name = $this->sanitizeText( $artist['title'] ?? '' );
				if ( '' !== $name ) {
					$names[] = $name;
				}
			}
			if ( ! empty( $names ) ) {
				$parts[] = 'Featuring ' . implode( ', ', $names ) . '.';
			}
		}

		// Attendee flow.
		$flow = $event['attendeeFlow'] ?? '';
		if ( 'apply' === $flow ) {
			$parts[] = 'Apply for tickets — winners selected by lottery.';
		}

		// Sold out notice.
		if ( ! empty( $event['isSoldOut'] ) ) {
			$parts[] = 'SOLD OUT.';
		}

		// Secret venue note.
		$venue_name = $event['venue']['venueName'] ?? '';
		if ( empty( $venue_name ) ) {
			$parts[] = 'Secret location revealed to ticket holders.';
		}

		return implode( ' ', $parts );
	}
}
