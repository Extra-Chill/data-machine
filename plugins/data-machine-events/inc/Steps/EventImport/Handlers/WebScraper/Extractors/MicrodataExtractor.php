<?php
// phpcs:disable Universal.Operators.DisallowShortTernary.Found -- Existing callback contracts, trusted identifiers, and renderer boundaries are reviewed and intentional.
/**
 * Schema.org microdata extractor.
 *
 * Extracts event data from HTML pages using Schema.org microdata attributes
 * (itemtype, itemprop) for Event structured data.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MicrodataExtractor extends BaseExtractor {

	public function canExtract( string $html ): bool {
		return strpos( $html, 'itemtype="https://schema.org/Event"' ) !== false
			|| strpos( $html, 'itemtype="http://schema.org/Event"' ) !== false
			|| strpos( $html, "itemtype='https://schema.org/Event'" ) !== false
			|| strpos( $html, "itemtype='http://schema.org/Event'" ) !== false;
	}

	public function extract( string $html, string $source_url ): array {
		$loaded = $this->loadDom( $html );
		$xpath  = $loaded['xpath'];

		$event_elements = $this->queryElements( $xpath, "//*[@itemtype='https://schema.org/Event' or @itemtype='http://schema.org/Event']" );

		if ( empty( $event_elements ) ) {
			return array();
		}

		$events = array();

		foreach ( $event_elements as $event_element ) {
			$event = $this->parseEventElement( $xpath, $event_element );

			if ( ! empty( $event['title'] ) && ! empty( $event['startDate'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	public function getMethod(): string {
		return 'microdata';
	}

	/**
	 * Parse event data from a Schema.org Event microdata element.
	 *
	 * @param \DOMXPath  $xpath         XPath query object
	 * @param \DOMElement $event_element Event element
	 * @return array Parsed event data
	 */
	private function parseEventElement( \DOMXPath $xpath, \DOMElement $event_element ): array {
		$event = array();

		$this->parseBasicProperties( $xpath, $event_element, $event );
		$this->parseDates( $xpath, $event_element, $event );
		$this->parsePerformerAndOrganizer( $xpath, $event_element, $event );
		$this->parseLocation( $xpath, $event_element, $event );
		$this->parseOffers( $xpath, $event_element, $event );
		$this->parseImage( $xpath, $event_element, $event );

		return $event;
	}

	/**
	 * Parse basic event properties (name, description).
	 */
	private function parseBasicProperties( \DOMXPath $xpath, \DOMElement $event_element, array &$event ): void {
		$name = $this->queryFirstElement( $xpath, ".//*[@itemprop='name']", $event_element );
		if ( null !== $name ) {
			$event['title'] = trim( $name->textContent );
		}

		$description = $this->queryFirstElement( $xpath, ".//*[@itemprop='description']", $event_element );
		if ( null !== $description ) {
			$event['description'] = trim( $description->textContent );
		}
	}

	/**
	 * Parse start and end dates.
	 *
	 * Microdata dates typically include timezone offset (ISO 8601 format).
	 */
	private function parseDates( \DOMXPath $xpath, \DOMElement $event_element, array &$event ): void {
		$start_date = $this->queryFirstElement( $xpath, ".//*[@itemprop='startDate']", $event_element );
		if ( null !== $start_date ) {
			$datetime = $this->extractDatetime( $start_date );
			if ( ! empty( $datetime ) ) {
				$parsed             = $this->parseDatetime( $datetime );
				$event['startDate'] = $parsed['date'];
				$event['startTime'] = '00:00' !== $parsed['time'] ? $parsed['time'] : '';
			}
		}

		$end_date = $this->queryFirstElement( $xpath, ".//*[@itemprop='endDate']", $event_element );
		if ( null !== $end_date ) {
			$datetime = $this->extractDatetime( $end_date );
			if ( ! empty( $datetime ) ) {
				$parsed           = $this->parseDatetime( $datetime );
				$event['endDate'] = $parsed['date'];
				$event['endTime'] = $parsed['time'];
			}
		}
	}

	/**
	 * Parse performer and organizer.
	 */
	private function parsePerformerAndOrganizer( \DOMXPath $xpath, \DOMElement $event_element, array &$event ): void {
		$performer = $this->queryFirstElement( $xpath, ".//*[@itemprop='performer']", $event_element );
		if ( null !== $performer ) {
			$performer_name = $this->queryFirstElement( $xpath, ".//*[@itemprop='name']", $performer );
			if ( null !== $performer_name ) {
				$event['performer'] = trim( $performer_name->textContent );
			} else {
				$event['performer'] = trim( $performer->textContent );
			}
		}

		$organizer = $this->queryFirstElement( $xpath, ".//*[@itemprop='organizer']", $event_element );
		if ( null !== $organizer ) {
			$organizer_name = $this->queryFirstElement( $xpath, ".//*[@itemprop='name']", $organizer );
			if ( null !== $organizer_name ) {
				$event['organizer'] = trim( $organizer_name->textContent );
			} else {
				$event['organizer'] = trim( $organizer->textContent );
			}
		}
	}

	/**
	 * Parse location/venue data.
	 */
	private function parseLocation( \DOMXPath $xpath, \DOMElement $event_element, array &$event ): void {
		$location = $this->queryFirstElement( $xpath, ".//*[@itemprop='location']", $event_element );
		if ( null === $location ) {
			return;
		}

		$venue_name = $this->queryFirstElement( $xpath, ".//*[@itemprop='name']", $location );
		if ( null !== $venue_name ) {
			$event['venue'] = trim( $venue_name->textContent );
		}

		$this->parseAddress( $xpath, $location, $event );
		$this->parseLocationDetails( $xpath, $location, $event );
		$this->parseGeo( $xpath, $location, $event );
	}

	/**
	 * Parse address components.
	 */
	private function parseAddress( \DOMXPath $xpath, \DOMElement $location_element, array &$event ): void {
		$address = $this->queryFirstElement( $xpath, ".//*[@itemprop='address']", $location_element );
		if ( null === $address ) {
			return;
		}

		$street = $this->queryFirstElement( $xpath, ".//*[@itemprop='streetAddress']", $address );
		if ( null !== $street ) {
			$event['venueAddress'] = trim( $street->textContent );
		}

		$locality = $this->queryFirstElement( $xpath, ".//*[@itemprop='addressLocality']", $address );
		if ( null !== $locality ) {
			$event['venueCity'] = trim( $locality->textContent );
		}

		$region = $this->queryFirstElement( $xpath, ".//*[@itemprop='addressRegion']", $address );
		if ( null !== $region ) {
			$event['venueState'] = trim( $region->textContent );
		}

		$postal = $this->queryFirstElement( $xpath, ".//*[@itemprop='postalCode']", $address );
		if ( null !== $postal ) {
			$event['venueZip'] = trim( $postal->textContent );
		}

		$country = $this->queryFirstElement( $xpath, ".//*[@itemprop='addressCountry']", $address );
		if ( null !== $country ) {
			$event['venueCountry'] = trim( $country->textContent );
		}
	}

	/**
	 * Parse phone and website from location.
	 */
	private function parseLocationDetails( \DOMXPath $xpath, \DOMElement $location_element, array &$event ): void {
		$telephone = $this->queryFirstElement( $xpath, ".//*[@itemprop='telephone']", $location_element );
		if ( null !== $telephone ) {
			$event['venuePhone'] = trim( $telephone->textContent );
		}

		$url = $this->queryFirstElement( $xpath, ".//*[@itemprop='url']", $location_element );
		if ( null !== $url ) {
			$website = $this->extractHrefOrContent( $url );
			if ( ! empty( $website ) ) {
				$event['venueWebsite'] = trim( $website );
			}
		}
	}

	/**
	 * Parse geo coordinates from location.
	 */
	private function parseGeo( \DOMXPath $xpath, \DOMElement $location_element, array &$event ): void {
		$geo = $this->queryFirstElement( $xpath, ".//*[@itemprop='geo']", $location_element );
		if ( null === $geo ) {
			return;
		}

		$latitude  = $this->queryFirstElement( $xpath, ".//*[@itemprop='latitude']", $geo );
		$longitude = $this->queryFirstElement( $xpath, ".//*[@itemprop='longitude']", $geo );

		if ( null !== $latitude && null !== $longitude ) {
			$lat                       = trim( $latitude->textContent );
			$lng                       = trim( $longitude->textContent );
			$event['venueCoordinates'] = $lat . ',' . $lng;
		}
	}

	/**
	 * Parse offers/pricing data.
	 */
	private function parseOffers( \DOMXPath $xpath, \DOMElement $event_element, array &$event ): void {
		$offers = $this->queryFirstElement( $xpath, ".//*[@itemprop='offers']", $event_element );
		if ( null === $offers ) {
			return;
		}

		$price = $this->queryFirstElement( $xpath, ".//*[@itemprop='price']", $offers );
		if ( null !== $price ) {
			$event['price'] = trim( $price->textContent );
		}

		$ticket_url = $this->queryFirstElement( $xpath, ".//*[@itemprop='url']", $offers );
		if ( null !== $ticket_url ) {
			$url = $this->extractHrefOrContent( $ticket_url );
			if ( ! empty( $url ) ) {
				$event['ticketUrl'] = trim( $url );
			}
		}
	}

	/**
	 * Parse image data.
	 */
	private function parseImage( \DOMXPath $xpath, \DOMElement $event_element, array &$event ): void {
		$image_node = $this->queryFirstElement( $xpath, ".//*[@itemprop='image']", $event_element );
		if ( null === $image_node ) {
			return;
		}

		$image_value = $image_node->getAttribute( 'src' )
			?: $image_node->getAttribute( 'href' )
			?: $image_node->textContent;

		if ( ! empty( $image_value ) ) {
			$event['imageUrl'] = trim( $image_value );
		}
	}

	/**
	 * Extract datetime from element (attribute or content).
	 */
	private function extractDatetime( \DOMElement $node ): string {
		return $node->getAttribute( 'datetime' ) ?: $node->textContent;
	}

	/**
	 * Extract href attribute or text content from element.
	 */
	private function extractHrefOrContent( \DOMElement $node ): string {
		return $node->getAttribute( 'href' ) ?: $node->textContent;
	}
}
