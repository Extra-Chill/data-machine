<?php
/**
 * ICS Feed Extractor
 *
 * Parses direct ICS/iCal feed content (not HTML pages with embedded calendars).
 * Supports Tockify, Google Calendar, Apple Calendar, Outlook, and any standard ICS feed.
 * Venue overrides and timezone handling applied by StructuredDataProcessor.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachineEvents\Core\DateTimeParser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IcsExtractor extends BaseExtractor {

	public function canExtract( string $content ): bool {
		if ( ! class_exists( 'ICal\ICal' ) ) {
			return false;
		}

		$content = trim( $content );

		if ( empty( $content ) ) {
			return false;
		}

		return preg_match( '/^BEGIN:VCALENDAR/im', $content ) !== false;
	}

	public function extract( string $content, string $source_url ): array {
		if ( ! class_exists( 'ICal\ICal' ) ) {
			return array();
		}

		try {
			$ical = new \ICal\ICal(
				false,
				array(
					'defaultSpan'           => 2,
					'defaultTimeZone'       => 'UTC',
					'defaultWeekStart'      => 'MO',
					'skipRecurrence'        => false,
					'useTimeZoneWithRRules' => false,
					'filterDaysBefore'      => 1,
				)
			);

			$ical->initString( $content );

			$events = $ical->events() ?? array();

			if ( empty( $events ) ) {
				return array();
			}

			$calendar_timezone = $ical->calendarTimeZone() ?? '';

			$normalized = array();
			foreach ( $events as $ical_event ) {
				$event = $this->normalizeEvent( $ical_event, $calendar_timezone );

				if ( ! empty( $event['title'] ) ) {
					$normalized[] = $event;
				}
			}

			return $normalized;

		} catch ( \Exception $e ) {
			return array();
		}
	}

	public function getMethod(): string {
		return 'ics_feed';
	}

	private function normalizeEvent( $ical_event, string $calendar_timezone ): array {
		$event_timezone = $calendar_timezone ? $calendar_timezone : $this->extractEventTimezone( $ical_event );

		$event = array(
			'title'         => sanitize_text_field( $ical_event->summary ?? '' ),
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
			'venueCountry'  => '',
			'venueTimezone' => $event_timezone,
			'ticketUrl'     => esc_url_raw( $ical_event->url ?? '' ),
			'image'         => '',
			'price'         => '',
			'performer'     => '',
			'organizer'     => sanitize_text_field( $ical_event->organizer ?? '' ),
			'source_url'    => esc_url_raw( $ical_event->url ?? '' ),
		);

		$this->parseStartDateTime( $event, $ical_event, $calendar_timezone, $event_timezone );
		$this->parseEndDateTime( $event, $ical_event, $calendar_timezone, $event_timezone );
		$this->parseLocation( $event, $ical_event );

		return $event;
	}

	private function extractEventTimezone( $ical_event ): string {
		if ( ! empty( $ical_event->dtstart_tz ) ) {
			return $ical_event->dtstart_tz;
		}

		if ( ! empty( $ical_event->dtstart ) && $ical_event->dtstart instanceof \DateTime ) {
			$tz = $ical_event->dtstart->getTimezone();
			if ( $tz ) {
				$tz_name = $tz->getName();
				if ( 'UTC' !== $tz_name && 'Z' !== $tz_name ) {
					return $tz_name;
				}
			}
		}

		return '';
	}

	private function parseStartDateTime( array &$event, $ical_event, string $calendar_timezone, string $event_timezone ): void {
		if ( ! empty( $ical_event->dtstart ) ) {
			$start_datetime = $ical_event->dtstart;

			if ( $start_datetime instanceof \DateTime ) {
				$tz      = $start_datetime->getTimezone();
				$tz_name = $tz ? $tz->getName() : '';

				$event['startDate'] = $start_datetime->format( 'Y-m-d' );

				if ( 'UTC' !== $tz_name && 'Z' !== $tz_name ) {
					$event['venueTimezone'] = $tz_name;
					$event['startTime']     = $start_datetime->format( 'H:i' );
				} elseif ( ! empty( $event_timezone ) ) {
					$event['venueTimezone'] = $event_timezone;
					$start_datetime->setTimezone( new \DateTimeZone( $event_timezone ) );
					$event['startTime'] = $start_datetime->format( 'H:i' );
				} else {
					$event['startTime'] = $start_datetime->format( 'H:i' );
				}
			} elseif ( is_string( $start_datetime ) ) {
				$parsed                 = DateTimeParser::parseIcs( $start_datetime, $calendar_timezone );
				$event['startDate']     = $parsed['date'];
				$event['startTime']     = $parsed['time'];
				$event['venueTimezone'] = $parsed['timezone'];
			}
		}
	}

	private function parseEndDateTime( array &$event, $ical_event, string $calendar_timezone, string $event_timezone ): void {
		if ( ! empty( $ical_event->dtend ) ) {
			$end_datetime = $ical_event->dtend;

			if ( $end_datetime instanceof \DateTime ) {
				$tz      = $end_datetime->getTimezone();
				$tz_name = $tz ? $tz->getName() : '';

				$event['endDate'] = $end_datetime->format( 'Y-m-d' );

				if ( 'UTC' !== $tz_name && 'Z' !== $tz_name ) {
					$event['venueTimezone'] = $tz_name;
					$event['endTime']       = $end_datetime->format( 'H:i' );
				} elseif ( ! empty( $event_timezone ) ) {
					$event['venueTimezone'] = $event_timezone;
					$end_datetime->setTimezone( new \DateTimeZone( $event_timezone ) );
					$event['endTime'] = $end_datetime->format( 'H:i' );
				} else {
					$event['endTime'] = $end_datetime->format( 'H:i' );
				}
			} elseif ( is_string( $end_datetime ) ) {
				$parsed           = DateTimeParser::parseIcs( $end_datetime, $calendar_timezone );
				$event['endDate'] = $parsed['date'];
				$event['endTime'] = $parsed['time'];
			}
		}
	}

	private function parseLocation( array &$event, $ical_event ): void {
		$location = $ical_event->location ?? '';

		if ( ! empty( $location ) ) {
			$location_parts = explode( ',', $location, 2 );
			$event['venue'] = sanitize_text_field( trim( $location_parts[0] ) );

			if ( isset( $location_parts[1] ) ) {
				$event['venueAddress'] = sanitize_text_field( trim( $location_parts[1] ) );
			} else {
				$event['venueAddress'] = sanitize_text_field( $location );
			}
		}
	}
}
