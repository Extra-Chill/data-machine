<?php
/**
 * Calendar URL Builder
 *
 * Pure functions that build add-to-calendar deep-link URLs for the
 * single-event "Add to Calendar" dropdown.
 *
 * Three destinations:
 *   - Google Calendar (https://calendar.google.com/calendar/render)
 *   - Outlook.com (https://outlook.live.com/calendar/0/deeplink/compose)
 *   - .ics file download (REST route /datamachine/v1/events/{id}/ics)
 *
 * Input is the structured `$event_data` array produced by
 * `data_machine_events_parse_event_data( $post )` (see public-api.php).
 *
 * Timezone handling notes:
 *   - Google Calendar expects `dates=YYYYMMDDTHHmmSSZ/YYYYMMDDTHHmmSSZ`
 *     in UTC. We convert the event's local start/end (interpreted in
 *     `venueTimezone`, falling back to site timezone) into UTC.
 *   - Outlook.com accepts ISO 8601 with offset. We emit the local time
 *     with the event's timezone offset, e.g. `2026-09-23T20:00:00-04:00`.
 *
 * Default end time: when `endTime` is empty we assume a 3-hour event.
 *
 * @package DataMachineEvents\EventActions
 * @since   0.40.0
 */

namespace DataMachineEvents\EventActions;

defined( 'ABSPATH' ) || exit;

class CalendarUrlBuilder {

	/**
	 * Default event duration when no endTime is provided, in seconds.
	 */
	const DEFAULT_DURATION_SECONDS = 3 * HOUR_IN_SECONDS;

	/**
	 * Google Calendar deep-link base.
	 */
	const GOOGLE_BASE = 'https://calendar.google.com/calendar/render';

	/**
	 * Outlook.com deep-link base.
	 */
	const OUTLOOK_BASE = 'https://outlook.live.com/calendar/0/deeplink/compose';

	/**
	 * Build a Google Calendar "create event" URL.
	 *
	 * @param array $event Event data from `data_machine_events_parse_event_data()`.
	 *                     Must include `post_id` (added by callers) plus the standard
	 *                     EventDetails attributes (startDate, startTime, endDate,
	 *                     endTime, venueTimezone, venue, address, etc.).
	 * @return string Absolute Google Calendar URL, or empty string when event lacks
	 *                a start date.
	 */
	public static function google( array $event ): string {
		$range = self::resolve_datetime_range( $event );
		if ( null === $range ) {
			return '';
		}

		$post_id = isset( $event['post_id'] ) ? (int) $event['post_id'] : 0;
		$title   = self::event_title( $event, $post_id );

		// Google expects UTC dates with a literal Z suffix.
		$start_utc = $range['start']->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
		$end_utc   = $range['end']->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );

		$params = array(
			'action'   => 'TEMPLATE',
			'text'     => $title,
			'dates'    => $start_utc . '/' . $end_utc,
			'details'  => self::build_description( $event, $post_id ),
			'location' => self::build_location( $event ),
		);

		// Google accepts a `ctz` (calendar timezone) hint — useful so the
		// imported event remembers its origin timezone even though `dates`
		// is in UTC.
		if ( ! empty( $event['venueTimezone'] ) ) {
			$params['ctz'] = (string) $event['venueTimezone'];
		}

		return self::GOOGLE_BASE . '?' . self::build_query( $params );
	}

	/**
	 * Build an Outlook.com "create event" URL.
	 *
	 * @param array $event Event data (see google()).
	 * @return string Absolute Outlook.com URL, or empty string when event lacks
	 *                a start date.
	 */
	public static function outlook( array $event ): string {
		$range = self::resolve_datetime_range( $event );
		if ( null === $range ) {
			return '';
		}

		$post_id = isset( $event['post_id'] ) ? (int) $event['post_id'] : 0;
		$title   = self::event_title( $event, $post_id );

		// Outlook accepts ISO 8601 with offset, e.g. 2026-09-23T20:00:00-04:00.
		$start_iso = $range['start']->format( \DateTimeInterface::ATOM );
		$end_iso   = $range['end']->format( \DateTimeInterface::ATOM );

		$params = array(
			'path'     => '/calendar/action/compose',
			'rru'      => 'addevent',
			'subject'  => $title,
			'startdt'  => $start_iso,
			'enddt'    => $end_iso,
			'body'     => self::build_description( $event, $post_id ),
			'location' => self::build_location( $event ),
		);

		return self::OUTLOOK_BASE . '?' . self::build_query( $params );
	}

	/**
	 * Build the public URL of the .ics endpoint for a given event.
	 *
	 * @param int $post_id Event post ID.
	 * @return string Absolute URL of the .ics endpoint.
	 */
	public static function ics_url( int $post_id ): string {
		return rest_url( 'datamachine/v1/events/' . $post_id . '/ics' );
	}

	/**
	 * Resolve the start + end DateTimeImmutable for an event.
	 *
	 * Honors `venueTimezone` when present; falls back to the site timezone.
	 * When `endTime` is empty the end defaults to start + 3 hours.
	 *
	 * @param array $event Event data.
	 * @return array|null { start: \DateTimeImmutable, end: \DateTimeImmutable } or null.
	 */
	private static function resolve_datetime_range( array $event ): ?array {
		if ( empty( $event['startDate'] ) ) {
			return null;
		}

		$tz_name = ! empty( $event['venueTimezone'] ) ? (string) $event['venueTimezone'] : wp_timezone_string();
		try {
			$tz = new \DateTimeZone( $tz_name );
		} catch ( \Exception $e ) {
			$tz = new \DateTimeZone( 'UTC' );
		}

		$start_time = ! empty( $event['startTime'] ) ? (string) $event['startTime'] : '00:00:00';
		try {
			$start = new \DateTimeImmutable( $event['startDate'] . ' ' . $start_time, $tz );
		} catch ( \Exception $e ) {
			return null;
		}

		$end_date = ! empty( $event['endDate'] ) ? (string) $event['endDate'] : (string) $event['startDate'];
		if ( ! empty( $event['endTime'] ) ) {
			try {
				$end = new \DateTimeImmutable( $end_date . ' ' . $event['endTime'], $tz );
			} catch ( \Exception $e ) {
				$end = $start->add( new \DateInterval( 'PT3H' ) );
			}
		} else {
			// Default duration: 3 hours.
			$end = $start->add( new \DateInterval( 'PT3H' ) );
		}

		// Defensive: end must be >= start.
		if ( $end < $start ) {
			$end = $start->add( new \DateInterval( 'PT3H' ) );
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Get the event title, preferring `get_the_title()` when a post ID is available.
	 *
	 * @param array $event   Event data.
	 * @param int   $post_id Event post ID (0 if unknown).
	 * @return string
	 */
	private static function event_title( array $event, int $post_id ): string {
		if ( $post_id > 0 ) {
			$title = get_the_title( $post_id );
			if ( $title ) {
				return wp_strip_all_tags( $title );
			}
		}
		return isset( $event['title'] ) ? wp_strip_all_tags( (string) $event['title'] ) : '';
	}

	/**
	 * Build the description body shared between Google and Outlook.
	 *
	 * Includes performer (when present), the permalink, and the ticket URL
	 * (when present). Plain text only — no HTML.
	 *
	 * @param array $event   Event data.
	 * @param int   $post_id Event post ID.
	 * @return string
	 */
	private static function build_description( array $event, int $post_id ): string {
		$parts = array();

		$performer = '';
		if ( ! empty( $event['performer'] ) ) {
			$performer = (string) $event['performer'];
		} elseif ( ! empty( $event['performerName'] ) ) {
			$performer = (string) $event['performerName'];
		}
		if ( $performer ) {
			$parts[] = sprintf( __( 'Performer: %s', 'data-machine-events' ), wp_strip_all_tags( $performer ) );
		}

		if ( $post_id > 0 ) {
			$permalink = get_permalink( $post_id );
			if ( $permalink ) {
				$parts[] = __( 'More info:', 'data-machine-events' ) . ' ' . $permalink;
			}
		}

		if ( ! empty( $event['ticketUrl'] ) ) {
			$parts[] = __( 'Tickets:', 'data-machine-events' ) . ' ' . (string) $event['ticketUrl'];
		}

		/**
		 * Filter the Add-to-Calendar description body.
		 *
		 * @param string $description Joined description lines (newline-separated).
		 * @param array  $event       Event data.
		 * @param int    $post_id     Event post ID.
		 */
		return (string) apply_filters(
			'data_machine_events_add_to_calendar_description',
			implode( "\n", $parts ),
			$event,
			$post_id
		);
	}

	/**
	 * Build the location string shared between Google and Outlook.
	 *
	 * @param array $event Event data.
	 * @return string
	 */
	private static function build_location( array $event ): string {
		$venue   = isset( $event['venue'] ) ? wp_strip_all_tags( (string) $event['venue'] ) : '';
		$address = isset( $event['address'] ) ? wp_strip_all_tags( (string) $event['address'] ) : '';

		if ( $venue && $address ) {
			return $venue . ', ' . $address;
		}
		return $venue ?: $address;
	}

	/**
	 * URL-encode an associative array using rawurlencode, joined with `&`.
	 *
	 * `http_build_query()` uses urlencode (spaces -> `+`); we prefer
	 * rawurlencode (spaces -> `%20`) for consistency with calendar URL
	 * conventions in the wild.
	 *
	 * @param array $params
	 * @return string
	 */
	private static function build_query( array $params ): string {
		$pairs = array();
		foreach ( $params as $key => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
		return implode( '&', $pairs );
	}
}
