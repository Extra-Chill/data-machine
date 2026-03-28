<?php
/**
 * Nocodeflow Calendar extractor.
 *
 * Extracts event data from Webflow sites using the Nocodeflow calendar widget
 * (cdn.nocodeflow.net/tools/calendar.js). Events are rendered as Webflow CMS
 * dynamic items with `data-date` attributes on `.identifyer` elements, making
 * extraction reliable and precise.
 *
 * Detection: `nocodeflow.net` script reference in the HTML.
 *
 * Example HTML structure per event:
 *   <div class="ncf-date-template">
 *     <div class="text-block-13">Artist Name</div>
 *     <div class="text-block-15">6:00 pm</div>
 *     <div class="text-block-14"></div>
 *     <div class="w-embed">
 *       <div class="identifyer" data-date="2026-03-28T18:00:00"></div>
 *     </div>
 *   </div>
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NocodeflowExtractor extends BaseExtractor {

	public function canExtract( string $html ): bool {
		return strpos( $html, 'nocodeflow.net' ) !== false
			&& strpos( $html, 'ncf-date-template' ) !== false;
	}

	public function extract( string $html, string $source_url ): array {
		$parsed = $this->loadDom( $html );
		$xpath  = $parsed['xpath'];

		// Find all ncf-date-template blocks — each is one event.
		$templates = $xpath->query( "//*[contains(@class, 'ncf-date-template')]" );

		if ( false === $templates || 0 === $templates->length ) {
			return array();
		}

		$events = array();

		foreach ( $templates as $template ) {
			$event = $this->parseTemplate( $template, $xpath );
			if ( ! empty( $event['title'] ) && ! empty( $event['startDate'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	public function getMethod(): string {
		return 'nocodeflow';
	}

	/**
	 * Parse a single ncf-date-template node.
	 *
	 * @param \DOMElement  $template The template DOM node.
	 * @param \DOMXPath    $xpath    XPath instance.
	 * @return array Normalized event data.
	 */
	private function parseTemplate( \DOMElement $template, \DOMXPath $xpath ): array {
		$title     = '';
		$time_str  = '';
		$extra     = '';
		$date_attr = '';

		// Extract the data-date attribute from the .identifyer element.
		$identifiers = $xpath->query( ".//*[contains(@class, 'identifyer')]", $template );
		if ( $identifiers && $identifiers->length > 0 ) {
			$date_attr = $identifiers->item( 0 )->getAttribute( 'data-date' );
		}

		// Extract text from child divs in order.
		// Nocodeflow uses numbered text-block classes for different fields.
		$divs = $xpath->query( './/div[starts-with(@class, "text-block-")]', $template );

		if ( $divs && $divs->length > 0 ) {
			foreach ( $divs as $i => $div ) {
				$text = trim( $div->textContent );
				if ( empty( $text ) ) {
					continue;
				}

				// First non-empty text block = title (artist name).
				if ( empty( $title ) ) {
					$title = $text;
				} elseif ( empty( $time_str ) && preg_match( '/\d{1,2}:\d{2}\s*(am|pm)/i', $text ) ) {
					$time_str = $text;
				} else {
					$extra = $text;
				}
			}
		}

		// Parse the ISO data-date attribute for date and time.
		$start_date = '';
		$start_time = '';

		if ( ! empty( $date_attr ) ) {
			// data-date="2026-03-28T18:00:00"
			$parts = explode( 'T', $date_attr );
			if ( count( $parts ) >= 2 ) {
				$start_date = $parts[0];
				$time_parts = explode( ':', $parts[1] );
				if ( count( $time_parts ) >= 2 ) {
					$start_time = $time_parts[0] . ':' . $time_parts[1];
				}
			}
		}

		// Fallback: parse time from the display text if data-date didn't have it.
		if ( empty( $start_time ) && ! empty( $time_str ) ) {
			$start_time = $this->parseTimeString( $time_str );
		}

		return array(
			'title'     => $this->sanitizeText( $title ),
			'startDate' => $start_date,
			'startTime' => $start_time,
			'endDate'   => '',
			'endTime'   => '',
			'ticketUrl' => '',
			'imageUrl'  => '',
			'price'     => '',
		);
	}
}
