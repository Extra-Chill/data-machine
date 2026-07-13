<?php
// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,Universal.Operators.DisallowShortTernary.Found,Squiz.PHP.DisallowSizeFunctionsInLoops.Found,WordPress.WP.I18n.MissingTranslatorsComment -- Existing callback contracts, trusted identifiers, and renderer boundaries are reviewed and intentional.
/**
 * .ics (iCalendar / RFC 5545) Builder
 *
 * Hand-rolled VCALENDAR/VEVENT/VTIMEZONE generator for the single-event
 * "Add to Calendar" / "Apple Calendar / Download .ics" link.
 *
 * Why hand-rolled? The plugin already ships `johngrogg/ics-parser` for
 * INBOUND parsing, but it has no writer. A single VEVENT with an optional
 * VTIMEZONE block is ~80 lines of `sprintf()` — a dependency is overkill.
 *
 * RFC 5545 details implemented here:
 *   - CRLF line endings.
 *   - Long-line folding at 75 octets (continuation lines start with a space).
 *   - Text escaping for `\`, `;`, `,`, and newlines in TEXT-typed fields.
 *   - VTIMEZONE block with one STANDARD + one DAYLIGHT sub-component built
 *     from `DateTimeZone::getTransitions()` (skipped entirely when the
 *     event timezone is UTC or has no DST transitions in the relevant window).
 *
 * @package DataMachineEvents\EventActions
 * @since   0.40.0
 */

namespace DataMachineEvents\EventActions;

defined( 'ABSPATH' ) || exit;

class IcsBuilder {

	/**
	 * Default event duration when no end time is provided, in seconds.
	 */
	const DEFAULT_DURATION_SECONDS = 3 * HOUR_IN_SECONDS;

	/**
	 * Maximum octet width per RFC 5545 §3.1 (lines must be folded above this).
	 */
	const FOLD_LIMIT = 75;

	/**
	 * Build a VCALENDAR blob for a single event post.
	 *
	 * @param int $post_id Event post ID.
	 * @return string The .ics body (CRLF-terminated lines, UTF-8).
	 */
	public static function build( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		if ( ! function_exists( 'data_machine_events_parse_event_data' ) ) {
			return '';
		}

		$event = \data_machine_events_parse_event_data( $post );
		if ( ! $event ) {
			return '';
		}

		// Resolve timezone.
		$tz_name = ! empty( $event['venueTimezone'] ) ? (string) $event['venueTimezone'] : wp_timezone_string();
		try {
			$tz = new \DateTimeZone( $tz_name );
		} catch ( \Exception $e ) {
			$tz      = new \DateTimeZone( 'UTC' );
			$tz_name = 'UTC';
		}

		// Resolve start/end.
		$start_time = ! empty( $event['startTime'] ) ? (string) $event['startTime'] : '00:00:00';
		try {
			$start = new \DateTimeImmutable( $event['startDate'] . ' ' . $start_time, $tz );
		} catch ( \Exception $e ) {
			return '';
		}

		$end_date = ! empty( $event['endDate'] ) ? (string) $event['endDate'] : (string) $event['startDate'];
		if ( ! empty( $event['endTime'] ) ) {
			try {
				$end = new \DateTimeImmutable( $end_date . ' ' . $event['endTime'], $tz );
			} catch ( \Exception $e ) {
				$end = $start->add( new \DateInterval( 'PT3H' ) );
			}
		} else {
			$end = $start->add( new \DateInterval( 'PT3H' ) );
		}
		if ( $end < $start ) {
			$end = $start->add( new \DateInterval( 'PT3H' ) );
		}

		$title       = wp_strip_all_tags( (string) get_the_title( $post_id ) );
		$permalink   = (string) get_permalink( $post_id );
		$description = self::build_description( $event, $post_id );
		$location    = self::build_location( $event );

		$is_utc = 'UTC' === $tz_name;

		// Build VTIMEZONE only when non-UTC.
		$vtimezone_lines = $is_utc ? array() : self::build_vtimezone( $tz, $tz_name, $start, $end );

		// DTSTART/DTEND formatting differs between UTC and local-with-TZID.
		if ( $is_utc || empty( $vtimezone_lines ) ) {
			$dtstart_line = 'DTSTART:' . $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
			$dtend_line   = 'DTEND:' . $end->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
		} else {
			$dtstart_line = 'DTSTART;TZID=' . $tz_name . ':' . $start->format( 'Ymd\THis' );
			$dtend_line   = 'DTEND;TZID=' . $tz_name . ':' . $end->format( 'Ymd\THis' );
		}

		$dtstamp = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Ymd\THis\Z' );
		$uid_seed = $permalink ?: ( 'event-' . $post_id . '@' . wp_parse_url( home_url(), PHP_URL_HOST ) );

		$lines   = array();
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//Data Machine Events//EN';
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = 'METHOD:PUBLISH';

		foreach ( $vtimezone_lines as $vtz_line ) {
			$lines[] = $vtz_line;
		}

		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . self::escape_text( $uid_seed );
		$lines[] = 'DTSTAMP:' . $dtstamp;
		$lines[] = $dtstart_line;
		$lines[] = $dtend_line;
		$lines[] = 'SUMMARY:' . self::escape_text( $title );
		if ( $description ) {
			$lines[] = 'DESCRIPTION:' . self::escape_text( $description );
		}
		if ( $location ) {
			$lines[] = 'LOCATION:' . self::escape_text( $location );
		}
		if ( $permalink ) {
			// URL is a URI-type field; do NOT TEXT-escape the colons/commas.
			$lines[] = 'URL:' . $permalink;
		}
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		// Fold long lines and join with CRLF.
		$folded = array_map( array( __CLASS__, 'fold_line' ), $lines );

		return implode( "\r\n", $folded ) . "\r\n";
	}

	/**
	 * Build the VTIMEZONE block for the given timezone.
	 *
	 * Walks `DateTimeZone::getTransitions()` over the year containing the event
	 * to find the most-recent STANDARD and DAYLIGHT transitions, then emits
	 * RRULE-less sub-components describing them. This produces an iCalendar-
	 * valid block that's accepted by Google, Apple, and Outlook.
	 *
	 * Returns an empty array when the timezone has no DST in the relevant
	 * window (in which case the caller falls back to plain UTC DTSTART/DTEND).
	 *
	 * @param \DateTimeZone        $tz      The timezone object.
	 * @param string               $tz_name The timezone identifier (e.g. "America/New_York").
	 * @param \DateTimeImmutable   $start   Event start (used to scope transitions).
	 * @param \DateTimeImmutable   $end     Event end.
	 * @return string[] VTIMEZONE block lines (BEGIN...END), or [] when not needed.
	 */
	private static function build_vtimezone( \DateTimeZone $tz, string $tz_name, \DateTimeImmutable $start, \DateTimeImmutable $end ): array {
		// Look one year before/after the event for transition data.
		$window_start = $start->modify( '-1 year' )->getTimestamp();
		$window_end   = $end->modify( '+1 year' )->getTimestamp();

		$transitions = $tz->getTransitions( $window_start, $window_end );
		if ( ! is_array( $transitions ) || count( $transitions ) < 2 ) {
			// `getTransitions()` always returns at least a synthetic "window
			// start" snapshot entry as element 0. We need at least one real
			// transition AFTER it to build a meaningful VTIMEZONE.
			return array();
		}

		// Element 0 is the window-start snapshot (current state at $window_start)
		// — not a real transition. Skip it. Real transitions follow.
		$real = array_slice( $transitions, 1 );
		if ( empty( $real ) ) {
			return array();
		}

		// Find the first real daylight and the first real standard transition.
		$daylight = null;
		$standard = null;
		foreach ( $real as $t ) {
			if ( ! empty( $t['isdst'] ) && null === $daylight ) {
				$daylight = $t;
			} elseif ( empty( $t['isdst'] ) && null === $standard ) {
				$standard = $t;
			}
			if ( $daylight && $standard ) {
				break;
			}
		}

		// If neither sub-component is available (e.g. timezone with no DST
		// in the window), fall back to plain UTC formatting in the caller.
		if ( ! $daylight && ! $standard ) {
			return array();
		}

		// For each real transition, the predecessor is the entry immediately
		// before it in the full transitions array (including element 0,
		// because that snapshot accurately represents the offset at $window_start).
		$find_prev_offset = static function ( $target ) use ( $transitions ) {
			$prev = null;
			foreach ( $transitions as $t ) {
				if ( $t['ts'] === $target['ts'] ) {
					return $prev ? (int) $prev['offset'] : (int) $target['offset'];
				}
				$prev = $t;
			}
			return $prev ? (int) $prev['offset'] : (int) $target['offset'];
		};

		$lines   = array();
		$lines[] = 'BEGIN:VTIMEZONE';
		$lines[] = 'TZID:' . $tz_name;

		if ( $daylight ) {
			$prev_offset = $find_prev_offset( $daylight );
			$lines[]     = 'BEGIN:DAYLIGHT';
			$lines[]     = 'DTSTART:' . gmdate( 'Ymd\THis', (int) $daylight['ts'] );
			$lines[]     = 'TZOFFSETFROM:' . self::format_offset( $prev_offset );
			$lines[]     = 'TZOFFSETTO:' . self::format_offset( (int) $daylight['offset'] );
			if ( ! empty( $daylight['abbr'] ) ) {
				$lines[] = 'TZNAME:' . self::escape_text( (string) $daylight['abbr'] );
			}
			$lines[] = 'END:DAYLIGHT';
		}

		if ( $standard ) {
			$prev_offset = $find_prev_offset( $standard );
			$lines[]     = 'BEGIN:STANDARD';
			$lines[]     = 'DTSTART:' . gmdate( 'Ymd\THis', (int) $standard['ts'] );
			$lines[]     = 'TZOFFSETFROM:' . self::format_offset( $prev_offset );
			$lines[]     = 'TZOFFSETTO:' . self::format_offset( (int) $standard['offset'] );
			if ( ! empty( $standard['abbr'] ) ) {
				$lines[] = 'TZNAME:' . self::escape_text( (string) $standard['abbr'] );
			}
			$lines[] = 'END:STANDARD';
		}

		$lines[] = 'END:VTIMEZONE';

		return $lines;
	}

	/**
	 * Format a UTC offset in seconds as +HHMM / -HHMM (iCalendar UTC-OFFSET).
	 *
	 * @param int $offset_seconds
	 * @return string
	 */
	private static function format_offset( int $offset_seconds ): string {
		$sign    = $offset_seconds >= 0 ? '+' : '-';
		$abs     = abs( $offset_seconds );
		$hours   = (int) floor( $abs / 3600 );
		$minutes = (int) floor( ( $abs % 3600 ) / 60 );
		return sprintf( '%s%02d%02d', $sign, $hours, $minutes );
	}

	/**
	 * Escape a TEXT-typed value per RFC 5545 §3.3.11.
	 *
	 * @param string $value
	 * @return string
	 */
	private static function escape_text( string $value ): string {
		// Order matters: backslash first.
		$value = str_replace( '\\', '\\\\', $value );
		$value = str_replace( array( "\r\n", "\r", "\n" ), '\\n', $value );
		$value = str_replace( ';', '\\;', $value );
		$value = str_replace( ',', '\\,', $value );
		return $value;
	}

	/**
	 * Fold a single content line to 75 octets per RFC 5545 §3.1.
	 *
	 * Continuation lines start with a single space (CRLF + SP).
	 *
	 * @param string $line
	 * @return string Possibly multi-line string joined by CRLF.
	 */
	private static function fold_line( string $line ): string {
		if ( strlen( $line ) <= self::FOLD_LIMIT ) {
			return $line;
		}

		$out    = '';
		$first  = true;
		$remain = $line;
		while ( strlen( $remain ) > 0 ) {
			$chunk_size = $first ? self::FOLD_LIMIT : self::FOLD_LIMIT - 1;
			$chunk      = self::utf8_safe_substr( $remain, $chunk_size );
			$remain     = (string) substr( $remain, strlen( $chunk ) );

			if ( $first ) {
				$out  .= $chunk;
				$first = false;
			} else {
				$out .= "\r\n " . $chunk;
			}
		}

		return $out;
	}

	/**
	 * Take up to $bytes octets from a UTF-8 string without splitting a multibyte char.
	 *
	 * @param string $str
	 * @param int    $bytes
	 * @return string
	 */
	private static function utf8_safe_substr( string $str, int $bytes ): string {
		if ( strlen( $str ) <= $bytes ) {
			return $str;
		}
		$chunk = substr( $str, 0, $bytes );
		// Walk back to a UTF-8 boundary.
		while ( strlen( $chunk ) > 0 && ( ord( $chunk[ strlen( $chunk ) - 1 ] ) & 0xC0 ) === 0x80 ) {
			$chunk = substr( $chunk, 0, -1 );
		}
		return $chunk;
	}

	/**
	 * Build the description body shared with the Google/Outlook URL builders.
	 *
	 * @param array $event
	 * @param int   $post_id
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

		/** This filter is documented in inc/EventActions/CalendarUrlBuilder.php */
		return (string) apply_filters(
			'data_machine_events_add_to_calendar_description',
			implode( "\n", $parts ),
			$event,
			$post_id
		);
	}

	/**
	 * Build the location string.
	 *
	 * @param array $event
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
}
