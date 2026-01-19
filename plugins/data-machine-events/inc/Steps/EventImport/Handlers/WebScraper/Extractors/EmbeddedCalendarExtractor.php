<?php
/**
 * Embedded Calendar extractor.
 *
 * Extracts event data from pages with embedded Google Calendar iframes by
 * detecting the embed, extracting the calendar ID, and fetching the public ICS feed.
 * Uses PageVenueExtractor to get venue info from page context when not in calendar data.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use ICal\ICal;
use DataMachine\Core\HttpClient;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\PageVenueExtractor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EmbeddedCalendarExtractor extends BaseExtractor {

	public function canExtract( string $html ): bool {
		return strpos( $html, 'google.com/calendar/embed' ) !== false;
	}

	public function extract( string $html, string $source_url ): array {
		$calendar_data = $this->extractCalendarData( $html );

		if ( empty( $calendar_data['calendar_id'] ) ) {
			return array();
		}

		$ics_url     = $this->generateIcsUrl( $calendar_data['calendar_id'] );
		$ics_content = $this->fetchIcsContent( $ics_url );

		if ( empty( $ics_content ) ) {
			return array();
		}

		$calendar_timezone                 = $calendar_data['timezone'] ? $calendar_data['timezone'] : '';
		[$ical_events, $calendar_timezone] = $this->parseIcsContent( $ics_content, $calendar_timezone );

		if ( empty( $ical_events ) ) {
			return array();
		}

		// Extract venue info from page context (fallback for calendar events that lack venue data)
		$page_venue = PageVenueExtractor::extract( $html, $source_url );

		// Prefer timezone from embed URL, fall back to page context
		$timezone = $calendar_data['timezone'] ? $calendar_data['timezone'] : $page_venue['venueTimezone'];

		$events = array();
		if ( is_array( $ical_events ) ) {
			foreach ( $ical_events as $ical_event ) {
				$normalized = $this->normalizeEvent( $ical_event, $page_venue, $calendar_timezone );

				if ( ! empty( $normalized['title'] ) ) {
					$events[] = $normalized;
				}
			}
		}

		return $events;
	}

	/**
	 * Extract calendar ID and timezone from Google Calendar embed iframe.
	 */
	public function getMethod(): string {
		return 'embedded_calendar';
	}

	/**
	 * Extract calendar ID and timezone from Google Calendar embed iframe.
	 */
	private function extractCalendarData( string $html ): array {
		$data = array(
			'calendar_id' => '',
			'timezone'    => '',
		);

		if ( ! preg_match( '/<iframe[^>]+src=["\']([^"]*google\.com\/calendar\/embed[^"]*)["\'][^>]*>/i', $html, $matches ) ) {
			return $data;
		}

		$iframe_src = html_entity_decode( $matches[1] );
		$parsed_url = parse_url( $iframe_src );

		if ( empty( $parsed_url['query'] ) ) {
			return $data;
		}

		parse_str( $parsed_url['query'], $query_params );

		if ( ! empty( $query_params['src'] ) ) {
			$src = $query_params['src'];

			// Check if ID is Base64 encoded (common in some Squarespace embeds)
			// Normal IDs usually contain '@', Base64 often doesn't
			if ( strpos( $src, '@' ) === false && preg_match( '/^[a-zA-Z0-9\/+]+={0,2}$/', $src ) ) {
				$decoded = base64_decode( $src, true );
				if ( $decoded && ( strpos( $decoded, '@' ) !== false || strpos( $decoded, 'calendar.google.com' ) !== false ) ) {
					$src = $decoded;
				}
			}

			$data['calendar_id'] = $src;
		}

		if ( ! empty( $query_params['ctz'] ) ) {
			$data['timezone'] = $query_params['ctz'];
		}

		return $data;
	}

	/**
	 * Generate public ICS URL from calendar ID.
	 */
	private function generateIcsUrl( string $calendar_id ): string {
		$encoded_id = urlencode( $calendar_id );
		return "https://calendar.google.com/calendar/ical/{$encoded_id}/public/basic.ics";
	}

	/**
	 * Fetch ICS content from URL.
	 *
	 * Tries with browser_mode first, then falls back to standard mode
	 * since Google sometimes blocks spoofed browser headers for ICS feeds.
	 */
	private function fetchIcsContent( string $url ): string {
		$options = array(
			'timeout'      => 30,
			'browser_mode' => true,
			'headers'      => array(
				'Accept' => 'text/calendar, text/plain',
			),
			'context'      => 'Embedded Calendar Extractor',
		);

		$result = HttpClient::get( $url, $options );

		// Fallback: Try without browser_mode if failed
		if ( ! $result['success'] || 200 !== $result['status_code'] ) {
			$options['browser_mode'] = false;
			$result                  = HttpClient::get( $url, $options );
		}

		if ( ! $result['success'] || 200 !== $result['status_code'] ) {
			return '';
		}

		return $result['data'] ?? '';
	}

	/**
	 * Parse ICS content using ICal library.
	 */
	private function parseIcsContent( string $ics_content, string $calendar_timezone = 'UTC' ): array {
		try {
			$ical = new ICal(
				false,
				array(
					'defaultSpan'           => 2,
					'defaultTimeZone'       => ! empty( $calendar_timezone ) ? $calendar_timezone : 'UTC',
					'defaultWeekStart'      => 'MO',
					'skipRecurrence'        => false,
					'useTimeZoneWithRRules' => false,
					'filterDaysBefore'      => 1,
				)
			);

			$ical->initString( $ics_content );

			$extracted_timezone = $ical->calendarTimeZone() ?? '';
			if ( ! empty( $extracted_timezone ) && 'UTC' !== $extracted_timezone ) {
				$calendar_timezone = $extracted_timezone;
			}

			return array( $ical->events() ?? array(), $calendar_timezone );
		} catch ( \Exception $e ) {
			return array( array(), '' );
		}
	}

	/**
	 * Normalize iCal event to standardized format.
	 *
	 * Uses page venue as fallback when calendar event lacks venue data.
	 */
	private function normalizeEvent( $ical_event, array $page_venue, string $default_timezone ): array {
		$summary = $ical_event->summary ?? '';

		// Handle potential double encoding or strange characters in summary
		if ( is_string( $summary ) ) {
			$summary = html_entity_decode( $summary, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		$event = array(
			'title'         => sanitize_text_field( $summary ),
			'description'   => sanitize_textarea_field( $ical_event->description ?? '' ),
			'startDate'     => '',
			'endDate'       => '',
			'startTime'     => '',
			'endTime'       => '',
			'venue'         => '',
			'venueAddress'  => '',
			'venueCity'     => '',
			'venueState'    => '',
			'venueZip'      => '',
			'venueCountry'  => 'US',
			'venueTimezone' => $default_timezone,
			'ticketUrl'     => esc_url_raw( $ical_event->url ?? '' ),
			'source_url'    => esc_url_raw( $ical_event->url ?? '' ),
			'organizer'     => sanitize_text_field( $ical_event->organizer ?? '' ),
		);

		// Check if calendar event has location data
		$has_calendar_venue = ! empty( $ical_event->location );

		if ( $has_calendar_venue ) {
			$location       = $ical_event->location;
			$location_parts = explode( ',', $location, 2 );
			$event['venue'] = sanitize_text_field( trim( $location_parts[0] ) );
			if ( isset( $location_parts[1] ) ) {
				$event['venueAddress'] = sanitize_text_field( trim( $location_parts[1] ) );
			}
		} else {
			// Use page venue as fallback
			$event['venue']        = $page_venue['venue'] ?? '';
			$event['venueAddress'] = $page_venue['venueAddress'] ?? '';
			$event['venueCity']    = $page_venue['venueCity'] ?? '';
			$event['venueState']   = $page_venue['venueState'] ?? '';
			$event['venueZip']     = $page_venue['venueZip'] ?? '';
			$event['venueCountry'] = $page_venue['venueCountry'] ?? 'US';
		}

		$this->parseDateTimes( $event, $ical_event, $default_timezone );

		return $event;
	}

	/**
	 * Parse date/time values from iCal event.
	 */
	private function parseDateTimes( array &$event, $ical_event, string $calendar_timezone ): void {
		if ( ! empty( $ical_event->dtstart ) ) {
			$start = $ical_event->dtstart;

			if ( $start instanceof \DateTime ) {
				$tz      = $start->getTimezone();
				$tz_name = $tz ? $tz->getName() : '';

				$event['startDate'] = $start->format( 'Y-m-d' );

				if ( 'UTC' !== $tz_name && 'Z' !== $tz_name ) {
					$event['venueTimezone'] = $tz_name;
					$event['startTime']     = $start->format( 'H:i' );
				} elseif ( ! empty( $calendar_timezone ) && 'UTC' !== $calendar_timezone ) {
					$event['venueTimezone'] = $calendar_timezone;
					$start->setTimezone( new \DateTimeZone( $calendar_timezone ) );
					$event['startTime'] = $start->format( 'H:i' );
				} else {
					$event['startTime'] = $start->format( 'H:i' );
				}
			} elseif ( is_string( $start ) ) {
				$parsed                 = \DataMachineEvents\Core\DateTimeParser::parseIcs( $start, $calendar_timezone );
				$event['startDate']     = $parsed['date'];
				$event['startTime']     = $parsed['time'];
				$event['venueTimezone'] = $parsed['timezone'];
			}
		}

		if ( ! empty( $ical_event->dtend ) ) {
			$end = $ical_event->dtend;

			if ( $end instanceof \DateTime ) {
				$tz      = $end->getTimezone();
				$tz_name = $tz ? $tz->getName() : '';

				$event['endDate'] = $end->format( 'Y-m-d' );

				if ( 'UTC' !== $tz_name && 'Z' !== $tz_name ) {
					$event['venueTimezone'] = $tz_name;
					$event['endTime']       = $end->format( 'H:i' );
				} elseif ( ! empty( $calendar_timezone ) && 'UTC' !== $calendar_timezone ) {
					$event['venueTimezone'] = $calendar_timezone;
					$end->setTimezone( new \DateTimeZone( $calendar_timezone ) );
					$event['endTime'] = $end->format( 'H:i' );
				} else {
					$event['endTime'] = $end->format( 'H:i' );
				}
			} elseif ( is_string( $end ) ) {
				$parsed           = \DataMachineEvents\Core\DateTimeParser::parseIcs( $end, $calendar_timezone );
				$event['endDate'] = $parsed['date'];
				$event['endTime'] = $parsed['time'];
			}
		}

		if ( ! empty( $ical_event->dtstart_tz ) && empty( $event['venueTimezone'] ) ) {
			$event['venueTimezone'] = $ical_event->dtstart_tz;
		}
	}
}
