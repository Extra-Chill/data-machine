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

			$dtstart_array = $ical_event->dtstart_array ?? array();
			$is_date_only  = isset( $dtstart_array[0]['VALUE'] ) && 'DATE' === $dtstart_array[0]['VALUE'];

			if ( $start_datetime instanceof \DateTime ) {
				$tz      = $start_datetime->getTimezone();
				$tz_name = $tz ? $tz->getName() : '';

				$is_explicit_utc = $this->hasUtcMarker( $dtstart_array );
				$is_floating     = ! $is_explicit_utc && ! $this->hasExplicitTimezone( $dtstart_array );

				$explicit_tzid = $this->getExplicitTimezone( $dtstart_array );
				if ( ! empty( $explicit_tzid ) ) {
					$local_dt = $this->parseFloatingTime( $dtstart_array, $explicit_tzid );
					if ( $local_dt ) {
						$event['startDate']     = $local_dt->format( 'Y-m-d' );
						$event['startTime']     = $local_dt->format( 'H:i' );
						$event['venueTimezone'] = $explicit_tzid;
					} else {
						$event['startDate']     = $start_datetime->format( 'Y-m-d' );
						$event['startTime']     = $start_datetime->format( 'H:i' );
						$event['venueTimezone'] = $explicit_tzid;
					}
				} elseif ( $is_floating && ! empty( $event_timezone ) ) {
					$local_dt = $this->parseFloatingTime( $dtstart_array, $event_timezone );
					if ( $local_dt ) {
						$event['startDate']     = $local_dt->format( 'Y-m-d' );
						$event['startTime']     = $local_dt->format( 'H:i' );
						$event['venueTimezone'] = $event_timezone;
					} else {
						$event['startDate'] = $start_datetime->format( 'Y-m-d' );
						$event['startTime'] = $start_datetime->format( 'H:i' );
						if ( ! empty( $event_timezone ) ) {
							$event['venueTimezone'] = $event_timezone;
						}
					}
				} elseif ( 'UTC' !== $tz_name && 'Z' !== $tz_name ) {
					$event['startDate']     = $start_datetime->format( 'Y-m-d' );
					$event['venueTimezone'] = $tz_name;
					$event['startTime']     = $start_datetime->format( 'H:i' );
				} elseif ( $is_explicit_utc && ! empty( $event_timezone ) ) {
					$event['venueTimezone'] = $event_timezone;
					$start_datetime->setTimezone( new \DateTimeZone( $event_timezone ) );
					$event['startDate'] = $start_datetime->format( 'Y-m-d' );
					$event['startTime'] = $start_datetime->format( 'H:i' );
				} else {
					$event['startDate'] = $start_datetime->format( 'Y-m-d' );
					$event['startTime'] = $start_datetime->format( 'H:i' );
					if ( ! empty( $event_timezone ) ) {
						$event['venueTimezone'] = $event_timezone;
					}
				}
			} elseif ( is_string( $start_datetime ) ) {
				$parsed                 = DateTimeParser::parseIcs( $start_datetime, $calendar_timezone );
				$event['startDate']     = $parsed['date'];
				$event['startTime']     = $parsed['time'];
				$event['venueTimezone'] = $parsed['timezone'];
			}

			// For date-only events, leave time empty so agent can parse from title
			if ( $is_date_only && '00:00' === $event['startTime'] ) {
				$event['startTime'] = '';
			}
		}
	}

	private function parseEndDateTime( array &$event, $ical_event, string $calendar_timezone, string $event_timezone ): void {
		if ( ! empty( $ical_event->dtend ) ) {
			$end_datetime = $ical_event->dtend;

			$dtend_array  = $ical_event->dtend_array ?? array();
			$is_date_only = isset( $dtend_array[0]['VALUE'] ) && 'DATE' === $dtend_array[0]['VALUE'];

			if ( $end_datetime instanceof \DateTime ) {
				$tz      = $end_datetime->getTimezone();
				$tz_name = $tz ? $tz->getName() : '';

				$is_explicit_utc = $this->hasUtcMarker( $dtend_array );
				$is_floating     = ! $is_explicit_utc && ! $this->hasExplicitTimezone( $dtend_array );

				$explicit_tzid = $this->getExplicitTimezone( $dtend_array );
				if ( ! empty( $explicit_tzid ) ) {
					$local_dt = $this->parseFloatingTime( $dtend_array, $explicit_tzid );
					if ( $local_dt ) {
						$event['endDate']       = $local_dt->format( 'Y-m-d' );
						$event['endTime']       = $local_dt->format( 'H:i' );
						$event['venueTimezone'] = $explicit_tzid;
					} else {
						$event['endDate']       = $end_datetime->format( 'Y-m-d' );
						$event['endTime']       = $end_datetime->format( 'H:i' );
						$event['venueTimezone'] = $explicit_tzid;
					}
				} elseif ( $is_floating && ! empty( $event_timezone ) ) {
					$local_dt = $this->parseFloatingTime( $dtend_array, $event_timezone );
					if ( $local_dt ) {
						$event['endDate']       = $local_dt->format( 'Y-m-d' );
						$event['endTime']       = $local_dt->format( 'H:i' );
						$event['venueTimezone'] = $event_timezone;
					} else {
						$event['endDate'] = $end_datetime->format( 'Y-m-d' );
						$event['endTime'] = $end_datetime->format( 'H:i' );
					}
				} elseif ( 'UTC' !== $tz_name && 'Z' !== $tz_name ) {
					$event['endDate']       = $end_datetime->format( 'Y-m-d' );
					$event['venueTimezone'] = $tz_name;
					$event['endTime']       = $end_datetime->format( 'H:i' );
				} elseif ( $is_explicit_utc && ! empty( $event_timezone ) ) {
					$event['venueTimezone'] = $event_timezone;
					$end_datetime->setTimezone( new \DateTimeZone( $event_timezone ) );
					$event['endDate'] = $end_datetime->format( 'Y-m-d' );
					$event['endTime'] = $end_datetime->format( 'H:i' );
				} else {
					$event['endDate'] = $end_datetime->format( 'Y-m-d' );
					$event['endTime'] = $end_datetime->format( 'H:i' );
				}
			} elseif ( is_string( $end_datetime ) ) {
				$parsed           = DateTimeParser::parseIcs( $end_datetime, $calendar_timezone );
				$event['endDate'] = $parsed['date'];
				$event['endTime'] = $parsed['time'];
			}

			// For date-only events, leave time empty so agent can parse from title
			if ( $is_date_only && '00:00' === $event['endTime'] ) {
				$event['endTime'] = '';
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

	private function hasUtcMarker( array $dtarray ): bool {
		$raw_value = $dtarray[1] ?? '';
		return str_ends_with( $raw_value, 'Z' );
	}

	private function hasExplicitTimezone( array $dtarray ): bool {
		$params = $dtarray[0] ?? array();
		return ! empty( $params['TZID'] );
	}

	private function getExplicitTimezone( array $dtarray ): string {
		$params = $dtarray[0] ?? array();
		return $params['TZID'] ?? '';
	}

	private function parseFloatingTime( array $dtarray, string $timezone ): ?\DateTime {
		$raw_value = $dtarray[1] ?? '';

		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})?)?$/', $raw_value, $m ) ) {
			return null;
		}

		$date_str = sprintf( '%s-%s-%s', $m[1], $m[2], $m[3] );
		$time_str = isset( $m[4] ) ? sprintf( '%s:%s:%s', $m[4], $m[5], $m[6] ?? '00' ) : '00:00:00';

		try {
			return new \DateTime( $date_str . ' ' . $time_str, new \DateTimeZone( $timezone ) );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
