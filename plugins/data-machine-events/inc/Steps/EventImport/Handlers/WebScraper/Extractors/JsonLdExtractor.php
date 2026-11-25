<?php
/**
 * JSON-LD extractor.
 *
 * Universal catch-all extractor for Schema.org JSON-LD Event data embedded
 * in `<script type="application/ld+json">` blocks.
 *
 * Handles every common Event-emitting shape:
 *
 *   1. Single Event object:
 *      { "@type": "Event", ... }
 *
 *   2. Top-level array of Events:
 *      [ { "@type": "Event", ... }, { "@type": "Event", ... } ]
 *
 *   3. `@graph` wrapper:
 *      { "@graph": [ { "@type": "Event", ... }, ... ] }
 *
 *   4. Schema.org Event subtypes (MusicEvent, TheaterEvent, Festival, etc.),
 *      whether expressed as a string or as an array of types:
 *      { "@type": "MusicEvent", ... }
 *      { "@type": ["Event", "MusicEvent"], ... }
 *
 *   5. Parent Festival (or other Event subtype) with a `subEvent` array:
 *      { "@type": "Festival", "subEvent": [ { "@type": "Event", ... } ] }
 *      The parent is extracted too if it has its own `startDate`.
 *
 *   6. Multiple `<script type="application/ld+json">` blocks on the same page,
 *      e.g. Yoast SEO splits Organization / BreadcrumbList / Event into
 *      separate blocks.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachineEvents\Core\TextNormalization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JsonLdExtractor extends BaseExtractor {

	/**
	 * Schema.org types we treat as extractable Events.
	 *
	 * Only types that exist in the schema.org vocabulary are listed; do not
	 * invent types (e.g. `ConcertEvent` does not exist).
	 *
	 * @var string[]
	 */
	private const EVENT_TYPES = array(
		'Event',
		'MusicEvent',
		'TheaterEvent',
		'DanceEvent',
		'SportsEvent',
		'Festival',
		'ScreeningEvent',
	);

	/**
	 * Maximum recursion depth when walking decoded JSON-LD trees.
	 *
	 * Prevents pathological self-referential blobs from exhausting the stack.
	 * Real-world schema.org graphs are rarely deeper than 4 levels.
	 *
	 * @var int
	 */
	private const MAX_WALK_DEPTH = 12;

	public function canExtract( string $html ): bool {
		// Liberal: any `application/ld+json` block at all. The cost of trying
		// and finding no events is low; the cost of skipping a valid Event
		// block is high.
		return stripos( $html, 'application/ld+json' ) !== false;
	}

	public function extract( string $html, string $source_url ): array {
		if ( ! preg_match_all(
			'/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
			$html,
			$matches
		) ) {
			return array();
		}

		$events = array();

		foreach ( $matches[1] as $json_content ) {
			$json_content = trim( $json_content );
			if ( '' === $json_content ) {
				continue;
			}

			$data = json_decode( $json_content, true );
			if ( JSON_ERROR_NONE !== json_last_error() || empty( $data ) || ! is_array( $data ) ) {
				do_action(
					'datamachine_log',
					'debug',
					'JsonLdExtractor: skipping malformed JSON-LD block',
					array(
						'source_url' => $source_url,
						'json_error' => json_last_error_msg(),
					)
				);
				continue;
			}

			$collected = array();
			$this->walkForEvents( $data, $collected, 0 );

			foreach ( $collected as $event_data ) {
				$parsed = $this->parseEvent( $event_data );
				if ( null !== $parsed ) {
					$events[] = $parsed;
				}
			}
		}

		return $events;
	}

	public function getMethod(): string {
		return 'jsonld';
	}

	/**
	 * Recursively walk a decoded JSON-LD tree, collecting every Event-typed
	 * object encountered.
	 *
	 * Recursion targets:
	 *  - `@graph` arrays
	 *  - `subEvent` arrays (parent Festival pattern)
	 *  - `event` arrays (less common alias)
	 *  - `itemListElement` arrays (ItemList wrapper, with optional ListItem `item`)
	 *  - Plain numeric-indexed arrays of nodes
	 *
	 * The parent of a `subEvent` array is itself collected if it is an Event
	 * type with a `startDate` — Festivals usually have their own headline
	 * date that consumers care about, separate from the child sets.
	 *
	 * @param mixed $node      Current node (array, object-as-array, scalar).
	 * @param array $collected Accumulator passed by reference.
	 * @param int   $depth     Current recursion depth.
	 */
	private function walkForEvents( $node, array &$collected, int $depth ): void {
		if ( $depth > self::MAX_WALK_DEPTH || ! is_array( $node ) ) {
			return;
		}

		// Numeric-indexed array: descend into each item.
		if ( $this->isNumericList( $node ) ) {
			foreach ( $node as $item ) {
				$this->walkForEvents( $item, $collected, $depth + 1 );
			}
			return;
		}

		$is_event = $this->isEventTyped( $node );

		if ( $is_event ) {
			$collected[] = $node;
		}

		// Even if this node is an Event, keep walking — Festivals carry
		// `subEvent` children, ItemList carries `itemListElement`, etc.
		if ( isset( $node['@graph'] ) && is_array( $node['@graph'] ) ) {
			$this->walkForEvents( $node['@graph'], $collected, $depth + 1 );
		}

		if ( isset( $node['subEvent'] ) && is_array( $node['subEvent'] ) ) {
			$this->walkForEvents( $node['subEvent'], $collected, $depth + 1 );
		}

		if ( isset( $node['subEvents'] ) && is_array( $node['subEvents'] ) ) {
			$this->walkForEvents( $node['subEvents'], $collected, $depth + 1 );
		}

		if ( isset( $node['event'] ) && is_array( $node['event'] ) ) {
			$this->walkForEvents( $node['event'], $collected, $depth + 1 );
		}

		if ( isset( $node['events'] ) && is_array( $node['events'] ) ) {
			$this->walkForEvents( $node['events'], $collected, $depth + 1 );
		}

		if ( isset( $node['itemListElement'] ) && is_array( $node['itemListElement'] ) ) {
			foreach ( $node['itemListElement'] as $list_item ) {
				if ( ! is_array( $list_item ) ) {
					continue;
				}
				// ListItem wraps the actual node under `item`.
				if ( isset( $list_item['item'] ) && is_array( $list_item['item'] ) ) {
					$this->walkForEvents( $list_item['item'], $collected, $depth + 1 );
				} else {
					$this->walkForEvents( $list_item, $collected, $depth + 1 );
				}
			}
		}
	}

	/**
	 * Determine whether a node is an Event (or recognized Event subtype).
	 *
	 * `@type` may be a string OR an array of types. Schema.org allows
	 * multiple types per node (e.g. `["Event", "MusicEvent"]`).
	 *
	 * @param array $node JSON-LD node.
	 * @return bool
	 */
	private function isEventTyped( array $node ): bool {
		if ( ! isset( $node['@type'] ) ) {
			return false;
		}

		$type = $node['@type'];

		if ( is_string( $type ) ) {
			return in_array( $type, self::EVENT_TYPES, true );
		}

		if ( is_array( $type ) ) {
			foreach ( $type as $t ) {
				if ( is_string( $t ) && in_array( $t, self::EVENT_TYPES, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check whether an array is a numeric-indexed list (vs. an associative map).
	 *
	 * @param array $data Array to check.
	 * @return bool
	 */
	private function isNumericList( array $data ): bool {
		if ( empty( $data ) ) {
			return false;
		}

		return array_keys( $data ) === range( 0, count( $data ) - 1 );
	}

	/**
	 * Parse a JSON-LD Event node into the standardized event shape consumed
	 * by the rest of the pipeline.
	 *
	 * @param array $event_data JSON-LD Event object.
	 * @return array|null Standardized event, or null when required fields missing.
	 */
	private function parseEvent( array $event_data ): ?array {
		$event = array(
			'title'       => html_entity_decode( TextNormalization::scalar_text( $event_data['name'] ?? '' ) ),
			'description' => TextNormalization::scalar_text( $event_data['description'] ?? '' ),
		);

		// Keep event-level identity separate from offers and venue URLs.
		foreach ( array( '@id', 'url' ) as $field ) {
			if ( is_string( $event_data[ $field ] ?? null ) ) {
				$event[ $field ] = trim( $event_data[ $field ] );
			}
		}

		$this->parseDates( $event, $event_data );
		$this->parsePerformerAndOrganizer( $event, $event_data );
		$this->parseLocation( $event, $event_data );
		$this->parseOffers( $event, $event_data );
		$this->parseImage( $event, $event_data );

		if ( empty( $event['title'] ) || empty( $event['startDate'] ) ) {
			return null;
		}

		return $event;
	}

	/**
	 * Parse date/time from JSON-LD event.
	 *
	 * JSON-LD dates typically include timezone offset (ISO 8601 format).
	 * Handles both `-04:00` and `-0400` offset variants — DateTime accepts both.
	 */
	private function parseDates( array &$event, array $event_data ): void {
		if ( ! empty( $event_data['startDate'] ) && is_string( $event_data['startDate'] ) ) {
			$parsed               = $this->parseIsoDatetime( $event_data['startDate'] );
			$event['startDate']   = $parsed['date'];
			$event['startTime']   = '00:00' !== $parsed['time'] ? $parsed['time'] : '';
			$event['startOffset'] = '' !== $event['startTime'] ? $parsed['offset'] : '';
		}

		if ( ! empty( $event_data['endDate'] ) && is_string( $event_data['endDate'] ) ) {
			$parsed             = $this->parseIsoDatetime( $event_data['endDate'] );
			$event['endDate']   = $parsed['date'];
			$event['endTime']   = $parsed['time'];
			$event['endOffset'] = $parsed['offset'];
		}
	}

	/**
	 * Parse performer and organizer from JSON-LD event.
	 */
	private function parsePerformerAndOrganizer( array &$event, array $event_data ): void {
		if ( ! empty( $event_data['performer'] ) ) {
			$performer = $event_data['performer'];
			if ( is_array( $performer ) ) {
				$event['performer'] = TextNormalization::scalar_text( $performer['name'] ?? $performer[0]['name'] ?? '' );
			} else {
				$event['performer'] = (string) $performer;
			}
		}

		if ( ! empty( $event_data['organizer'] ) ) {
			$organizer = $event_data['organizer'];
			if ( is_array( $organizer ) ) {
				$event['organizer'] = TextNormalization::scalar_text( $organizer['name'] ?? $organizer[0]['name'] ?? '' );
			} else {
				$event['organizer'] = (string) $organizer;
			}
		}
	}

	/**
	 * Parse location from JSON-LD event.
	 */
	private function parseLocation( array &$event, array $event_data ): void {
		if ( empty( $event_data['location'] ) || ! is_array( $event_data['location'] ) ) {
			return;
		}

		$location = $event_data['location'];
		// `location` may be a list of Places (or a VirtualLocation first).
		if ( array_is_list( $location ) ) {
			$location = $this->firstPlace( $location );
		}
		if ( empty( $location ) ) {
			return;
		}

		$event['venue'] = html_entity_decode( TextNormalization::scalar_text( $location['name'] ?? '' ) );

		$address = $location['address'] ?? null;
		if ( is_array( $address ) && array_is_list( $address ) ) {
			$address = $address[0] ?? null;
		}
		if ( is_array( $address ) ) {
			// Schema.org lets addressCountry / addressRegion hold a Thing
			// (`{"@type":"Country","name":"US"}`), which must not reach code
			// that casts to string (#4059).
			$event['venueAddress'] = TextNormalization::scalar_text( $address['streetAddress'] ?? '' );
			$event['venueCity']    = TextNormalization::scalar_text( $address['addressLocality'] ?? '' );
			$event['venueState']   = TextNormalization::scalar_text( $address['addressRegion'] ?? '' );
			$event['venueZip']     = TextNormalization::scalar_text( $address['postalCode'] ?? '' );
			$event['venueCountry'] = TextNormalization::scalar_text( $address['addressCountry'] ?? '' );
		} elseif ( is_string( $address ) ) {
			$event['venueAddress'] = $address;
		}

		foreach ( array( 'venueAddress', 'venueCity', 'venueState' ) as $field ) {
			if ( ! empty( $event[ $field ] ) ) {
				$event[ $field ] = html_entity_decode( $event[ $field ] );
			}
		}

		$event['venuePhone']   = TextNormalization::scalar_text( $location['telephone'] ?? '' );
		$event['venueWebsite'] = TextNormalization::scalar_text( $location['url'] ?? '' );

		$geo = is_array( $location['geo'] ?? null ) ? $location['geo'] : $location;
		$lat = TextNormalization::scalar_text( $geo['latitude'] ?? '' );
		$lng = TextNormalization::scalar_text( $geo['longitude'] ?? '' );
		if ( '' !== $lat && '' !== $lng ) {
			$event['venueCoordinates'] = $lat . ',' . $lng;
		}
	}

	/**
	 * First physical Place in a list of locations.
	 *
	 * @param array $locations Location nodes.
	 * @return array Place node, or [] when none.
	 */
	private function firstPlace( array $locations ): array {
		$fallback = array();
		foreach ( $locations as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			if ( 'VirtualLocation' !== ( $candidate['@type'] ?? '' ) ) {
				return $candidate;
			}
			if ( empty( $fallback ) ) {
				$fallback = $candidate;
			}
		}
		return $fallback;
	}

	/**
	 * Parse offers/pricing from JSON-LD event.
	 */
	private function parseOffers( array &$event, array $event_data ): void {
		$offers = array();

		if ( ! empty( $event_data['offers'] ) ) {
			$offers = $event_data['offers'];
			if ( is_array( $offers ) && isset( $offers[0] ) ) {
				$offers = $offers[0];
			}
		}

		if ( ! is_array( $offers ) ) {
			$offers = array();
		}

		$event['price'] = TextNormalization::scalar_text( $offers['price'] ?? '' );

		// Ticket URL: check offers.url first, then fall back to event-level url (Eventbrite pattern).
		$event['ticketUrl'] = TextNormalization::scalar_text( $offers['url'] ?? $event_data['url'] ?? '' );
	}

	/**
	 * Parse image from JSON-LD event.
	 */
	private function parseImage( array &$event, array $event_data ): void {
		if ( empty( $event_data['image'] ) ) {
			return;
		}

		$image = $event_data['image'];
		if ( is_array( $image ) ) {
			// Image can be a list of URL strings OR a list of ImageObject nodes.
			$first = $image[0] ?? '';
			if ( ! array_is_list( $image ) ) {
				$first = $image;
			}
			if ( is_array( $first ) ) {
				$event['imageUrl'] = TextNormalization::scalar_text( $first['url'] ?? $first['contentUrl'] ?? '' );
			} else {
				$event['imageUrl'] = TextNormalization::scalar_text( $first );
			}
		} else {
			$event['imageUrl'] = TextNormalization::scalar_text( $image );
		}
	}
}
