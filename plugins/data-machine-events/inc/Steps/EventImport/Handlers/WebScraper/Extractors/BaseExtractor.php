<?php
/**
 * Base extractor abstract class.
 *
 * Provides centralized datetime parsing utilities using DateTimeParser
 * for consistent timezone handling across all extractors.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 * @since   0.8.27
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachineEvents\Core\DateTimeParser;
use DataMachineEvents\Core\PriceFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BaseExtractor implements ExtractorInterface {

	/**
	 * Parse UTC timestamp and convert to local timezone.
	 *
	 * Use when data source provides Unix timestamps in UTC.
	 * Handles both seconds and milliseconds.
	 *
	 * @param int|string $timestamp Unix timestamp (seconds or milliseconds)
	 * @param string $timezone IANA timezone identifier (e.g., "America/Chicago")
	 * @return array{date: string, time: string, timezone: string}
	 */
	protected function parseUtcTimestamp( $timestamp, string $timezone ): array {
		if ( empty( $timestamp ) || ! is_numeric( $timestamp ) ) {
			return array(
				'date'     => '',
				'time'     => '',
				'timezone' => '',
			);
		}

		$ts = (int) $timestamp;

		if ( $ts > 1000000000000 ) {
			$ts = (int) ( $ts / 1000 );
		}

		$utc_datetime = gmdate( 'Y-m-d\TH:i:s\Z', $ts );
		return DateTimeParser::parseUtc( $utc_datetime, $timezone );
	}

	/**
	 * Parse ISO 8601 datetime string with embedded timezone.
	 *
	 * Use when data source provides datetime strings like "2026-01-15T19:30:00-06:00".
	 *
	 * @param string $datetime ISO 8601 datetime string
	 * @return array{date: string, time: string, timezone: string}
	 */
	protected function parseIsoDatetime( string $datetime ): array {
		return DateTimeParser::parseIso( $datetime );
	}

	/**
	 * Parse UTC datetime string and convert to local timezone.
	 *
	 * Use when data source provides UTC strings with a separate timezone field.
	 * Example: Dice.fm returns "2026-01-04T02:30:00Z" with timezone "America/Chicago"
	 *
	 * @param string $datetime UTC datetime string
	 * @param string $timezone IANA timezone identifier
	 * @return array{date: string, time: string, timezone: string}
	 */
	protected function parseUtcDatetime( string $datetime, string $timezone ): array {
		return DateTimeParser::parseUtc( $datetime, $timezone );
	}

	/**
	 * Parse local datetime (already in venue timezone).
	 *
	 * Use when data source provides date/time that's already local.
	 * Example: Ticketmaster returns localDate "2026-01-15" and localTime "19:30"
	 *
	 * @param string $date Date string (Y-m-d)
	 * @param string $time Time string (H:i or H:i:s)
	 * @param string $timezone IANA timezone identifier
	 * @return array{date: string, time: string, timezone: string}
	 */
	protected function parseLocalDatetime( string $date, string $time, string $timezone ): array {
		return DateTimeParser::parseLocal( $date, $time, $timezone );
	}

	/**
	 * Auto-detect datetime format and parse accordingly.
	 *
	 * Use when datetime format is unknown or varies. Attempts to parse any
	 * datetime string and extract timezone if present. Falls back to provided
	 * timezone if datetime has no embedded timezone.
	 *
	 * @param string $datetime Datetime string in any format
	 * @param string $fallback_timezone Timezone to use if not embedded
	 * @return array{date: string, time: string, timezone: string}
	 */
	protected function parseDatetime( string $datetime, string $fallback_timezone = '' ): array {
		return DateTimeParser::parse( $datetime, $fallback_timezone );
	}

	/**
	 * Validate IANA timezone identifier.
	 *
	 * @param string $timezone Timezone to validate
	 * @return bool True if valid IANA timezone
	 */
	protected function isValidTimezone( string $timezone ): bool {
		return DateTimeParser::isValidTimezone( $timezone );
	}

	/**
	 * Sanitize text field.
	 *
	 * @param string $text Text to sanitize
	 * @return string Sanitized text
	 */
	protected function sanitizeText( string $text ): string {
		return sanitize_text_field( trim( $text ) );
	}

	/**
	 * Clean HTML content for descriptions.
	 *
	 * @param string $html HTML content
	 * @return string Cleaned HTML with allowed tags
	 */
	protected function cleanHtml( string $html ): string {
		return wp_kses_post( trim( $html ) );
	}

	/**
	 * Parse a clean time string to 24-hour format.
	 *
	 * Use for well-formatted time strings like "7:00 pm", "8pm", "19:30".
	 * For extracting times from longer text, use extractTimeFromText() instead.
	 *
	 * @since 0.9.17
	 * @param string $time_str Time string (e.g., "7:00 pm", "8pm", "19:30")
	 * @return string Time in H:i format or empty string if parsing fails
	 */
	protected function parseTimeString( string $time_str ): string {
		$time_str = strtolower( trim( $time_str ) );

		if ( empty( $time_str ) ) {
			return '';
		}

		$time_str = preg_replace( '/^(show|doors)\s*:\s*/i', '', $time_str );

		$timestamp = strtotime( $time_str );
		if ( false !== $timestamp ) {
			return gmdate( 'H:i', $timestamp );
		}

		if ( preg_match( '/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $time_str, $matches ) ) {
			$hour   = (int) $matches[1];
			$minute = ! empty( $matches[2] ) ? $matches[2] : '00';
			$ampm   = ! empty( $matches[3] ) ? strtolower( $matches[3] ) : 'pm';

			if ( 'pm' === $ampm && $hour < 12 ) {
				$hour += 12;
			} elseif ( 'am' === $ampm && 12 === $hour ) {
				$hour = 0;
			}

			return sprintf( '%02d:%s', $hour, $minute );
		}

		return '';
	}

	/**
	 * Extract time from descriptive text.
	 *
	 * Parses common time patterns found in event descriptions like "DOORS AT 8PM",
	 * "11AM DOORS", "SHOWTIME 9:30PM", "Show: 8:30 PM", etc.
	 *
	 * @since 0.9.17
	 * @param string $text Text to search for time patterns
	 * @return string|null Time in H:i format or null if not found
	 */
	protected function extractTimeFromText( string $text ): ?string {
		$patterns = array(
			'/DOORS\s*(?:AT\s*)?(\d{1,2})(?::(\d{2}))?\s*(AM|PM)/i',
			'/(\d{1,2})(?::(\d{2}))?\s*(AM|PM)\s*DOORS/i',
			'/SHOW(?:TIME)?\s*(?:AT\s*)?(\d{1,2})(?::(\d{2}))?\s*(AM|PM)/i',
			'/(\d{1,2})(?::(\d{2}))?\s*(AM|PM)\s*SHOW(?:TIME)?/i',
			'/START(?:S)?\s*(?:AT\s*)?(\d{1,2})(?::(\d{2}))?\s*(AM|PM)/i',
			'/(?:^|\s)(\d{1,2})(?::(\d{2}))?\s*(AM|PM)(?:\s|$|,)/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $text, $matches ) ) {
				$hour    = (int) $matches[1];
				$minutes = isset( $matches[2] ) && '' !== $matches[2] ? $matches[2] : '00';
				$period  = strtoupper( $matches[3] );

				if ( 'PM' === $period && $hour < 12 ) {
					$hour += 12;
				} elseif ( 'AM' === $period && 12 === $hour ) {
					$hour = 0;
				}

				return sprintf( '%02d:%s', $hour, $minutes );
			}
		}

		return null;
	}

	/**
	 * Format a price range as a display string.
	 *
	 * @param float|null $min Minimum price
	 * @param float|null $max Maximum price (optional)
	 * @return string Formatted price or empty if invalid
	 */
	protected function formatPriceRange( ?float $min, ?float $max = null ): string {
		return PriceFormatter::formatRange( $min, $max );
	}

	/**
	 * Format structured price data into a display string.
	 *
	 * @param float|null $min Minimum price.
	 * @param float|null $max Maximum price.
	 * @param string     $currency ISO currency code.
	 * @param bool|null  $is_free Explicit free flag.
	 * @return string
	 */
	protected function formatStructuredPrice( ?float $min = null, ?float $max = null, string $currency = 'USD', ?bool $is_free = null ): string {
		return PriceFormatter::formatStructured( $min, $max, $currency, $is_free );
	}

	/**
	 * Infer a full Y-m-d date from month name and day number.
	 *
	 * Assumes the current year. If that date has already passed,
	 * bumps to the next year. Useful for venue calendars that
	 * display "January 15" without a year.
	 *
	 * @since 0.15.1
	 * @param string $month Month name (e.g., "January", "Jan")
	 * @param string $day   Day number (e.g., "15")
	 * @return string Date in Y-m-d format, or empty string on failure.
	 */
	protected function inferDateFromMonthDay( string $month, string $day ): string {
		$year     = (int) gmdate( 'Y' );
		$date_str = "{$month} {$day} {$year}";

		try {
			$dt    = new \DateTime( $date_str );
			$today = new \DateTime( 'today' );

			if ( $dt < $today ) {
				$dt->modify( '+1 year' );
			}

			return $dt->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Forward-occurrence horizon (days) for scraped ICS calendars.
	 *
	 * Open-ended RRULE recurrences (no UNTIL/COUNT) project years into the
	 * future. This bounds the user-visible forward window so an open-ended
	 * weekly residency yields ~13 occurrences (90 days) instead of ~104 (2 years).
	 *
	 * Filterable so a legitimately long-horizon source (e.g. a festival
	 * announcing a year out) can opt into a wider window.
	 *
	 * @since 0.46.1
	 *
	 * @param array $context Optional context (source_url, method) for filter callbacks.
	 * @return int Horizon in days. Default 90.
	 */
	protected function getRecurrenceHorizonDays( array $context = array() ): int {
		/**
		 * Filter the forward-occurrence horizon (days) for scraped ICS calendars.
		 *
		 * Open-ended RRULE recurrences are dropped once their start exceeds
		 * now + horizon days. Raise this for a source that legitimately
		 * publishes far-future events.
		 *
		 * @param int   $days    Default horizon in days.
		 * @param array $context Optional context (source_url, method).
		 */
		$days = (int) apply_filters( 'data_machine_events_scraper_recurrence_horizon_days', 90, $context );

		return $days > 0 ? $days : 90;
	}

	/**
	 * Maximum events a single ICS scrape may emit.
	 *
	 * Backstop so no single run can dump thousands of items even if the
	 * recurrence-horizon logic regresses. The nearest events are kept.
	 *
	 * Filterable so a legitimately high-volume source can raise the ceiling.
	 *
	 * @since 0.46.1
	 *
	 * @param array $context Optional context.
	 * @return int Max events. Default 200.
	 */
	protected function getMaxScrapeEvents( array $context = array() ): int {
		/**
		 * Filter the per-scrape event cap for ICS extractors.
		 *
		 * @param int   $max     Default max events per scrape.
		 * @param array $context Optional context (source_url, method).
		 */
		$max = (int) apply_filters( 'data_machine_events_scraper_max_events', 200, $context );

		return $max > 0 ? $max : 200;
	}

	/**
	 * Constrain expanded ICal recurrence events to a forward horizon and total cap.
	 *
	 * Drops any occurrence whose start is more than the horizon (days) in the
	 * future, then caps the remaining set to the max-events backstop, keeping
	 * the nearest events. Operates on raw ICal\Event objects before
	 * normalization so far-future occurrences never pay normalization cost.
	 *
	 * This is the single source of truth for the forward window; the vendored
	 * parser's defaultSpan is intentionally left unchanged so this filter is
	 * not silently re-capped by the parser.
	 *
	 * @since 0.46.1
	 *
	 * @param array $events  Expanded ICal events (any shape with a ->dtstart).
	 * @param array $context Optional context for filters.
	 * @return array Filtered + capped events, nearest first.
	 */
	protected function constrainRecurrenceHorizon( array $events, array $context = array() ): array {
		if ( empty( $events ) ) {
			return $events;
		}

		$horizon_days = $this->getRecurrenceHorizonDays( $context );
		$max_events   = $this->getMaxScrapeEvents( $context );

		try {
			$cutoff_ts = ( new \DateTime( '+' . $horizon_days . ' days' ) )->getTimestamp();
		} catch ( \Exception $e ) {
			$cutoff_ts = ( new \DateTime( '+90 days' ) )->getTimestamp();
		}

		$kept = array();
		foreach ( $events as $event ) {
			$ts = $this->getEventStartTimestamp( $event );
			// Drop occurrences beyond the forward horizon. Events with an
			// unreadable start are kept defensively rather than silently dropped.
			if ( null !== $ts && $ts > $cutoff_ts ) {
				continue;
			}

			$kept[] = $event;
		}

		if ( empty( $kept ) ) {
			return $kept;
		}

		// ICal::events() returns feed order, not date order. Sort ascending by
		// start before slicing so the cap keeps the nearest events.
		usort(
			$kept,
			function ( $a, $b ) {
				$ta = $this->getEventStartTimestamp( $a ) ?? PHP_INT_MAX;
				$tb = $this->getEventStartTimestamp( $b ) ?? PHP_INT_MAX;
				return $ta <=> $tb;
			}
		);

		if ( count( $kept ) > $max_events ) {
			$kept = array_slice( $kept, 0, $max_events );
		}

		return $kept;
	}

	/**
	 * Best-effort Unix timestamp for an ICal\Event start.
	 *
	 * @since 0.46.1
	 *
	 * @param object $event ICal event object with a ->dtstart property.
	 * @return int|null Timestamp, or null if the start is unreadable.
	 */
	protected function getEventStartTimestamp( object $event ): ?int {
		$start = $event->dtstart ?? null;

		if ( $start instanceof \DateTime ) {
			return $start->getTimestamp();
		}

		if ( is_string( $start ) && '' !== $start ) {
			$ts = strtotime( $start );
			return false !== $ts ? $ts : null;
		}

		return null;
	}

	/**
	 * Load an HTML string into a DOMDocument + DOMXPath pair.
	 *
	 * Eliminates the repeated 5-line DOM bootstrap boilerplate
	 * across extractors.
	 *
	 * @since 0.15.1
	 * @param string $html Raw HTML content.
	 * @return array{dom: \DOMDocument, xpath: \DOMXPath}
	 */
	protected function loadDom( string $html ): array {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		return array(
			'dom'   => $dom,
			'xpath' => new \DOMXPath( $dom ),
		);
	}

	/**
	 * Merge page-level venue data into an event array.
	 *
	 * Fills in venue name and address fields that are empty in the
	 * event but present in the page-level venue data.
	 *
	 * @since 0.15.1
	 * @param array $event      Event data array.
	 * @param array $page_venue Page-level venue data from PageVenueExtractor.
	 * @return array Event with merged venue data.
	 */
	protected function mergePageVenueData( array $event, array $page_venue ): array {
		$fields = array( 'venue', 'venueAddress', 'venueCity', 'venueState', 'venueZip', 'venueCountry', 'venueTimezone' );

		foreach ( $fields as $field ) {
			if ( empty( $event[ $field ] ) && ! empty( $page_venue[ $field ] ) ) {
				$event[ $field ] = $page_venue[ $field ];
			}
		}

		return $event;
	}

	/**
	 * Fetch a URL via HttpClient with standard error handling.
	 *
	 * Centralizes the repeated pattern of HttpClient::get() + success check
	 * that appears in many extractors.
	 *
	 * @since 0.15.1
	 * @param string $url     URL to fetch.
	 * @param array  $args    Optional wp_remote_get args.
	 * @param string $context Short description for logging (e.g., "Firebase events").
	 * @return string|null Response body, or null on failure.
	 */
	protected function fetchUrl( string $url, array $args = array(), string $context = '' ): ?string {
		if ( ! class_exists( '\\DataMachine\\Core\\HttpClient' ) ) {
			return null;
		}

		$defaults = array( 'timeout' => 15 );
		$args     = array_merge( $defaults, $args );
		$result   = \DataMachine\Core\HttpClient::get( $url, $args );

		if ( empty( $result['success'] ) ) {
			if ( '' !== $context ) {
				do_action(
					'datamachine_log',
					'debug',
					"BaseExtractor::fetchUrl failed for {$context}",
					array(
						'url'         => $url,
						'status_code' => $result['status_code'] ?? 0,
					)
				);
			}
			return null;
		}

		return $result['data'] ?? null;
	}

	/**
	 * Resolve a relative URL against a base URL.
	 *
	 * @since 0.15.1
	 * @param string $url      Possibly relative URL.
	 * @param string $base_url Base URL for resolution.
	 * @return string Absolute URL.
	 */
	protected function resolveUrl( string $url, string $base_url ): string {
		if ( empty( $url ) ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		if ( str_starts_with( $url, '//' ) ) {
			$scheme = wp_parse_url( $base_url, PHP_URL_SCHEME ) ?: 'https';
			return $scheme . ':' . $url;
		}

		$parts = wp_parse_url( $base_url );
		$base  = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );

		if ( str_starts_with( $url, '/' ) ) {
			return $base . $url;
		}

		$path = $parts['path'] ?? '/';
		$dir  = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );

		return $base . $dir . $url;
	}
}
