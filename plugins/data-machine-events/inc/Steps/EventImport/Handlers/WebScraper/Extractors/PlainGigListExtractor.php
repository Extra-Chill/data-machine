<?php
/**
 * Conservative server-rendered tour row extraction.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PlainGigListExtractor extends BaseExtractor {

	public function getMethod(): string {
		return 'plain_gig_list';
	}

	public function canExtract( string $html ): bool {
		return ! empty( $this->extract( $html, 'https://example.org/' ) );
	}

	public function extract( string $html, string $source_url ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}
		$loaded = $this->loadDom( $html );
		$xpath  = $loaded['xpath'];
		$groups = array();
		foreach ( $this->elements( $xpath, '//tr | //div | //li | //article' ) as $row ) {
			if ( array() !== $this->elements( $xpath, 'ancestor::nav | ancestor::footer', $row ) ) {
				continue;
			}
			$candidate = $this->parseRow( $row, $xpath, $source_url );
			if ( null !== $candidate ) {
				$groups[ $row->parentNode->getNodePath() ][] = $candidate;
			}
		}
		$events = array();
		foreach ( $groups as $rows ) {
			if ( count( $rows ) < 3 ) {
				continue;
			}
			$day_first = $this->isDayFirst( $rows );
			$parsed    = array();
			foreach ( $rows as $row ) {
				$date = $this->normalizeDate( $row['raw_date'], $day_first );
				if ( '' === $date ) {
					continue;
				}
				unset( $row['raw_date'] );
				$row['startDate'] = $date;
				$parsed[]         = $row;
			}
			if ( count( $parsed ) >= 3 ) {
				foreach ( $parsed as $event ) {
					$key            = $event['startDate'] . '|' . $event['venue'] . '|' . $event['venueCity'];
					$events[ $key ] = $event;
				}
			}
		}
		return array_values( $events );
	}

	/** Read one full row; responsive fragments never provide enough fields. */
	private function parseRow( \DOMElement $row, \DOMXPath $xpath, string $source_url ): ?array {
		$venue    = $this->fieldText( $row, $xpath, 'venue' );
		$location = $this->fieldText( $row, $xpath, 'citystate' );
		$artist   = $this->fieldText( $row, $xpath, 'artist-name' );
		$cells    = $this->elements( $xpath, './td', $row );
		if ( '' === $venue && count( $cells ) >= 3 ) {
			foreach ( $cells as $index => $cell ) {
				$text = $this->nodeText( $cell );
				$next = $cells[ $index + 1 ] ?? null;
				if ( preg_match( '/^[A-Z]{2}\s*-\s*\p{L}/u', $text ) && null !== $next ) {
					$location = $text;
					$venue    = $this->nodeText( $next );
					break;
				}
			}
		}
		if ( '' === $venue || ! preg_match( '/\p{L}/u', $venue ) || preg_match( '/^(?:tickets?|buy|details|home)$/i', $venue ) ) {
			return null;
		}
		$text = $this->nodeText( $row );
		preg_match_all( '/\b(?:\d{1,2}[-\/]\d{1,2}[-\/]\d{4}|(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2},?\s+\d{4})\b/i', $text, $matches );
		$dates = array_unique( array_map( static fn( $date ) => str_replace( ',', '', $date ), $matches[0] ) );
		if ( 1 !== count( $dates ) ) {
			return null;
		}
		$country = '';
		$state   = '';
		if ( preg_match( '/^([A-Z]{2})\s*-\s*(.+)$/u', $location, $parts ) ) {
			$country  = $parts[1];
			$location = $parts[2];
		}
		if ( preg_match( '/^(.+),\s*([A-Z]{2})$/u', $location, $parts ) ) {
			$location = trim( $parts[1] );
			$state    = $parts[2];
		}
		$ticket = '';
		foreach ( $this->elements( $xpath, './/a[@href]', $row ) as $link ) {
			if ( preg_match( '/\b(?:tickets?|buy|book)\b/i', $this->nodeText( $link ) ) ) {
				$ticket = esc_url_raw( $this->resolveUrl( $link->getAttribute( 'href' ), $source_url ) );
				break;
			}
		}
		return array(
			'title'        => '' !== $artist ? $artist : $venue,
			'raw_date'     => reset( $dates ),
			'startTime'    => '',
			'venue'        => $venue,
			'venueCity'    => $location,
			'venueState'   => $state,
			'venueCountry' => $country,
			'ticketUrl'    => $ticket,
			'source_url'   => $source_url,
		);
	}

	/** Ignore ticket controls nested inside location fields. */
	private function nodeText( \DOMNode $node ): string {
		if ( $node instanceof \DOMElement && in_array( $node->tagName, array( 'a', 'button' ), true ) ) {
			return trim( $node->textContent );
		}
		$text = '';
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && in_array( $child->tagName, array( 'a', 'button' ), true ) ) {
				continue;
			}
			$text .= ' ' . ( $child->hasChildNodes() ? $this->nodeText( $child ) : $child->textContent );
		}
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Element results of an XPath query; an invalid expression yields none.
	 *
	 * @return \DOMElement[]
	 */
	private function elements( \DOMXPath $xpath, string $expression, ?\DOMNode $context = null ): array {
		$nodes = $xpath->query( $expression, $context );
		if ( false === $nodes ) {
			return array();
		}
		$elements = array();
		foreach ( $nodes as $node ) {
			if ( $node instanceof \DOMElement ) {
				$elements[] = $node;
			}
		}
		return $elements;
	}

	private function fieldText( \DOMElement $row, \DOMXPath $xpath, string $field_class ): string {
		$node = $this->elements( $xpath, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' {$field_class} ')]", $row )[0] ?? null;
		if ( null === $node ) {
			return '';
		}
		// Venue names are often links to the venue homepage, not event URLs.
		if ( 'venue' === $field_class ) {
			return trim( preg_replace( '/\s+/u', ' ', $node->textContent ) );
		}
		return $this->nodeText( $node );
	}

	/**
	 * Day-first only on evidence: some day > 12, a run of consecutive days when
	 * read day-first, or a series that ascends day-first but not month-first.
	 * Ambiguous pages stay month-first.
	 */
	private function isDayFirst( array $rows ): bool {
		$day_first   = array();
		$month_first = array();
		foreach ( $rows as $row ) {
			if ( ! preg_match( '/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $row['raw_date'], $parts ) ) {
				continue;
			}
			if ( (int) $parts[1] > 12 && checkdate( (int) $parts[2], (int) $parts[1], (int) $parts[3] ) ) {
				return true;
			}
			$day_first[]   = sprintf( '%04d-%02d-%02d', $parts[3], $parts[2], $parts[1] );
			$month_first[] = sprintf( '%04d-%02d-%02d', $parts[3], $parts[1], $parts[2] );
		}
		if ( ! $this->isAscending( $day_first ) ) {
			return false;
		}
		return ! $this->isAscending( $month_first ) || $this->isConsecutive( $day_first );
	}

	/** Every distinct date is exactly one day after the previous one. */
	private function isConsecutive( array $dates ): bool {
		$dates = array_values( array_unique( $dates ) );
		$count = count( $dates );
		for ( $index = 1; $index < $count; ++$index ) {
			if ( strtotime( $dates[ $index ] . ' UTC' ) - strtotime( $dates[ $index - 1 ] . ' UTC' ) !== DAY_IN_SECONDS ) {
				return false;
			}
		}
		return true;
	}

	/** At least three distinct dates in strictly ascending order. */
	private function isAscending( array $dates ): bool {
		$dates = array_values( array_unique( $dates ) );
		$count = count( $dates );
		if ( $count < 3 ) {
			return false;
		}
		for ( $index = 1; $index < $count; ++$index ) {
			if ( $dates[ $index ] <= $dates[ $index - 1 ] ) {
				return false;
			}
		}
		return true;
	}

	private function normalizeDate( string $value, bool $day_first ): string {
		if ( preg_match( '/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $value, $parts ) ) {
			if ( ! $day_first && (int) $parts[2] <= 12 ) {
				return '';
			}
			$day   = (int) $parts[ $day_first ? 1 : 2 ];
			$month = (int) $parts[ $day_first ? 2 : 1 ];
			$year  = (int) $parts[3];
			return checkdate( $month, $day, $year ) ? sprintf( '%04d-%02d-%02d', $year, $month, $day ) : '';
		}
		$time = strtotime( $value );
		return false === $time ? '' : gmdate( 'Y-m-d', $time );
	}
}
