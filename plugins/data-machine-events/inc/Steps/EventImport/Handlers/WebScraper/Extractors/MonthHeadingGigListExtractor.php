<?php
/**
 * Conservative small-venue gig lists written as plain lines under month headings.
 *
 * Handles the long-tail bar/restaurant format:
 *
 *     November 2026
 *     Fri. 11/6 Small Town Stranded 8-11
 *     Sat. 11/28 Lack of Principals 1-4 & Sum x4 8-11
 *
 * The year comes from the nearest preceding "Month YYYY" heading. A stated
 * weekday must agree with the computed date, and bare time ranges without
 * am/pm are read as afternoon/evening sets.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MonthHeadingGigListExtractor extends BaseExtractor {

	/** Minimum accepted lines before the page is treated as a gig list. */
	private const MIN_EVENTS = 3;

	/** Line separator substituted for <br> so one block can hold many lines. */
	private const LINE_BREAK = "\u{2029}";

	private const MONTHS = array(
		'jan' => 1,
		'feb' => 2,
		'mar' => 3,
		'apr' => 4,
		'may' => 5,
		'jun' => 6,
		'jul' => 7,
		'aug' => 8,
		'sep' => 9,
		'oct' => 10,
		'nov' => 11,
		'dec' => 12,
	);

	/** Weekday prefixes mapped to ISO-8601 day numbers (1 = Monday). */
	private const WEEKDAYS = array(
		'mon' => 1,
		'tue' => 2,
		'wed' => 3,
		'thu' => 4,
		'fri' => 5,
		'sat' => 6,
		'sun' => 7,
	);

	private const MONTH_PATTERN = '(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|Sept?(?:ember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';

	private const WEEKDAY_PATTERN = '(Mon(?:day)?|Tue(?:s(?:day)?)?|Wed(?:s|nesday)?|Thu(?:r(?:s(?:day)?)?)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)';

	/** Decorative words allowed around a month heading ("Live Music - November 2026"). */
	private const HEADING_DECOR = '(?:live\s+music|live\s+entertainment|music|entertainment|schedule|calendar|line-?up|shows|events|bands|gigs)';

	private const TIME_PART = '\d{1,2}(?::\d{2})?\s*(?:[ap]\.?m\.?)?';

	/** Lines that are promotions, closures or menu items rather than performers. */
	private const NON_EVENT_PATTERN = '/\b(?:specials?|happy\s+hour|menu|closed|close\s+early|brunch|buffet|discount|reservations?|half[\s-]+price|kitchen|takeout|delivery|tacos?|wings?|burgers?|pizzas?|nachos|drinks?|beers?|cocktails?|margaritas?|mimosas?|oysters?|prime\s+rib|bingo|trivia|karaoke)\b|\$\s*\d|\d\s*%/i';

	public function getMethod(): string {
		return 'month_heading_gig_list';
	}

	public function canExtract( string $html ): bool {
		if ( '' === trim( $html ) || ! preg_match( '/\b\d{1,2}\/\d{1,2}\b/', $html ) ) {
			return false;
		}
		return ! empty( $this->extract( $html, 'https://example.org/' ) );
	}

	public function extract( string $html, string $source_url ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}
		$html   = (string) preg_replace( '/<br\s*\/?>/i', self::LINE_BREAK, $html );
		$loaded = $this->loadDom( $html );
		$xpath  = $loaded['xpath'];

		$block_tags = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'td', 'dt', 'dd', 'div' );
		$self_test  = implode( ' or ', array_map( static fn( $tag ) => 'self::' . $tag, $block_tags ) );
		$nested     = implode( ' | ', array_map( static fn( $tag ) => './/' . $tag, array_merge( $block_tags, array( 'ul', 'ol', 'table' ) ) ) );
		$nodes      = $xpath->query( "//*[{$self_test}][not({$nested})]" );
		if ( false === $nodes ) {
			return array();
		}

		// Each section holds the rows found under one month heading.
		$sections = array();
		$current  = -1;
		$month    = 0;
		$year     = 0;
		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			$skip = $xpath->query( 'ancestor::nav | ancestor::footer | ancestor::header | ancestor::form', $node );
			if ( false !== $skip && $skip->length > 0 ) {
				continue;
			}
			foreach ( explode( self::LINE_BREAK, $node->textContent ) as $line ) {
				$line = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $line ) );
				if ( '' === $line ) {
					continue;
				}
				$heading = $this->parseHeading( $line );
				if ( null !== $heading ) {
					++$current;
					$month                = $heading['month'];
					$year                 = $heading['year'];
					$sections[ $current ] = array();
					continue;
				}
				if ( $current < 0 ) {
					continue;
				}
				$row = $this->parseLine( $line, $month, $year );
				if ( null !== $row ) {
					$sections[ $current ][] = $row;
				}
			}
		}

		$events = array();
		foreach ( $sections as $rows ) {
			$verified   = 0;
			$mismatched = 0;
			foreach ( $rows as $row ) {
				if ( true === $row['weekday_ok'] ) {
					++$verified;
				} elseif ( false === $row['weekday_ok'] ) {
					++$mismatched;
				}
			}
			// A section whose weekdays mostly disagree has the wrong year or is not a gig list.
			if ( $mismatched > 0 && $mismatched >= $verified ) {
				continue;
			}
			foreach ( $rows as $row ) {
				if ( false === $row['weekday_ok'] ) {
					continue;
				}
				foreach ( $row['events'] as $event ) {
					$event['source_url'] = $source_url;
					$key                 = $event['startDate'] . '|' . $event['startTime'] . '|' . strtolower( $event['title'] );
					$events[ $key ]      = $event;
				}
			}
		}

		return count( $events ) >= self::MIN_EVENTS ? array_values( $events ) : array();
	}

	/**
	 * Parse a stand-alone "Month YYYY" heading line.
	 *
	 * @return array{month: int, year: int}|null
	 */
	private function parseHeading( string $line ): ?array {
		$decor   = '(?:' . self::HEADING_DECOR . '\s*[-–—:|]?\s*)?';
		$trailer = '(?:\s*[-–—:|]?\s*' . self::HEADING_DECOR . ')?';
		$pattern = '/^' . $decor . self::MONTH_PATTERN . '\.?,?\s+(\d{4})' . $trailer . '\s*:?$/iu';
		if ( ! preg_match( $pattern, $line, $parts ) ) {
			return null;
		}
		$year = (int) $parts[2];
		if ( $year < 2000 || $year > 2100 ) {
			return null;
		}
		return array(
			'month' => self::MONTHS[ strtolower( substr( $parts[1], 0, 3 ) ) ],
			'year'  => $year,
		);
	}

	/**
	 * Parse one "[weekday] M/D title [time-range]" line.
	 *
	 * @return array{weekday_ok: bool|null, events: array[]}|null
	 */
	private function parseLine( string $line, int $heading_month, int $heading_year ): ?array {
		$pattern = '/^(?:' . self::WEEKDAY_PATTERN . '\.?,?\s+)?(\d{1,2})\/(\d{1,2})(?:\/(\d{2}|\d{4}))?\b\s*[-–—:|.,]?\s*(.+)$/iu';
		if ( ! preg_match( $pattern, $line, $parts ) ) {
			return null;
		}
		$month = (int) $parts[2];
		$day   = (int) $parts[3];
		$body  = trim( $parts[5] );
		if ( '' !== $parts[4] ) {
			$year = (int) $parts[4];
			$year = $year < 100 ? 2000 + $year : $year;
		} else {
			// A January line under a December heading belongs to the next year.
			$year = $month < $heading_month ? $heading_year + 1 : $heading_year;
		}
		if ( ! checkdate( $month, $day, $year ) || '' === $body ) {
			return null;
		}
		// Only the heading month, or the month immediately after it, is plausible.
		$expected_next = 12 === $heading_month ? 1 : $heading_month + 1;
		if ( $month !== $heading_month && $month !== $expected_next ) {
			return null;
		}

		$date       = sprintf( '%04d-%02d-%02d', $year, $month, $day );
		$weekday_ok = null;
		if ( '' !== $parts[1] ) {
			$stated     = self::WEEKDAYS[ strtolower( substr( $parts[1], 0, 3 ) ) ];
			$weekday_ok = (int) gmdate( 'N', (int) strtotime( $date . ' 00:00:00 UTC' ) ) === $stated;
		}

		$segments = $this->splitBill( $body );
		if ( empty( $segments ) ) {
			return null;
		}
		// Without a weekday or a time the line carries too little gig evidence.
		if ( null === $weekday_ok && '' === $segments[0]['range'] ) {
			return null;
		}

		$events = array();
		foreach ( $segments as $segment ) {
			$title = $this->sanitizeText( html_entity_decode( $segment['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$title = trim( $title, " \t-–—:|,&+" );
			if ( '' === $title || ! preg_match( '/\p{L}/u', $title ) || preg_match( self::NON_EVENT_PATTERN, $title ) ) {
				return null;
			}
			$times    = $this->parseRange( $segment['range'] );
			$events[] = array(
				'title'        => $title,
				'startDate'    => $date,
				'startTime'    => $times['start'],
				'endDate'      => '' !== $times['end'] ? $date : '',
				'endTime'      => $times['end'],
				'venue'        => '',
				'venueAddress' => '',
				'venueCity'    => '',
				'venueState'   => '',
				'venueCountry' => '',
				'ticketUrl'    => '',
				'imageUrl'     => '',
				'source_url'   => '',
			);
		}

		return array(
			'weekday_ok' => $weekday_ok,
			'events'     => $events,
		);
	}

	/**
	 * Split "A 1-4 & B 8-11" into one segment per act. Only splits when every
	 * act carries its own time, so "Chuck & Jon 7-10" stays a single title.
	 *
	 * @return array<int, array{title: string, range: string}>
	 */
	private function splitBill( string $body ): array {
		$range     = self::TIME_PART . '\s*[-–—]\s*' . self::TIME_PART . '|\d{1,2}(?::\d{2})?\s*[ap]\.?m\.?';
		$separator = '(?:&|\+|\/|,|and)';
		$pattern   = '/\G\s*(?:' . $separator . '\s+)?(.+?)\s+(' . $range . ')(?=\s*' . $separator . '\s|\s*$)/iu';
		if ( preg_match_all( $pattern, $body, $matches, PREG_SET_ORDER ) ) {
			$consumed = implode( '', array_column( $matches, 0 ) );
			if ( strlen( $consumed ) === strlen( $body ) ) {
				return array_map(
					static fn( $set ) => array(
						'title' => trim( $set[1] ),
						'range' => trim( $set[2] ),
					),
					$matches
				);
			}
		}
		// No parseable time: the whole body is the title.
		if ( preg_match( '/\d\s*[-–—]\s*\d|\d\s*[ap]\.?m\b/i', $body ) ) {
			return array();
		}
		return array(
			array(
				'title' => $body,
				'range' => '',
			),
		);
	}

	/**
	 * Convert a set time or range to 24h start/end times. Bare hours are
	 * afternoon/evening sets ("1-4", "8-11"); explicit am/pm wins. The end is
	 * omitted when it crosses midnight.
	 *
	 * @return array{start: string, end: string}
	 */
	private function parseRange( string $range ): array {
		$empty = array(
			'start' => '',
			'end'   => '',
		);
		if ( '' === $range ) {
			return $empty;
		}
		$part = '(\d{1,2})(?::(\d{2}))?\s*(?:([ap])\.?m\.?)?';
		if ( ! preg_match( '/^' . $part . '(?:\s*[-–—]\s*' . $part . ')?$/iu', $range, $parts ) ) {
			return $empty;
		}
		$start_hour   = (int) $parts[1];
		$start_minute = '' !== ( $parts[2] ?? '' ) ? (int) $parts[2] : 0;
		$start_period = strtolower( $parts[3] ?? '' );
		$has_end      = '' !== ( $parts[4] ?? '' );
		$end_hour     = $has_end ? (int) $parts[4] : 0;
		$end_minute   = '' !== ( $parts[5] ?? '' ) ? (int) $parts[5] : 0;
		$end_period   = strtolower( $parts[6] ?? '' );
		if ( $start_hour < 1 || $start_hour > 12 || ( $has_end && ( $end_hour < 1 || $end_hour > 12 ) ) || $start_minute > 59 || $end_minute > 59 ) {
			return $empty;
		}

		if ( '' === $start_period ) {
			if ( '' !== $end_period ) {
				// "11-2pm" starts in the morning; "8-11pm" shares the end's period.
				$wraps        = 12 !== $start_hour && ( 12 === $end_hour || $start_hour > $end_hour );
				$start_period = $wraps ? ( 'p' === $end_period ? 'a' : 'p' ) : $end_period;
			} else {
				$start_period = 'p';
			}
		}
		$start = $this->to24( $start_hour, $start_period );
		$times = array(
			'start' => sprintf( '%02d:%02d', $start, $start_minute ),
			'end'   => '',
		);
		if ( $has_end ) {
			$end = $this->to24( $end_hour, '' !== $end_period ? $end_period : 'p' );
			if ( $end * 60 + $end_minute > $start * 60 + $start_minute ) {
				$times['end'] = sprintf( '%02d:%02d', $end, $end_minute );
			}
		}
		return $times;
	}

	private function to24( int $hour, string $period ): int {
		if ( 'a' === $period ) {
			return 12 === $hour ? 0 : $hour;
		}
		return 12 === $hour ? 12 : $hour + 12;
	}
}
