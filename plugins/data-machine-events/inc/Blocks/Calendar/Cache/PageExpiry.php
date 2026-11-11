<?php
/**
 * Calendar page expiry.
 *
 * A server-rendered calendar stays correct until the next upcoming
 * eligibility transition: the moment an event leaves "upcoming" because its
 * end (or, without an end, its start) datetime passes. The calendar's own
 * response and bucket caches already expire at that boundary. This class
 * expresses the same boundary to the rest of the HTTP stack as a generic
 * `Cache-Control: max-age` expiry so pages that embed the calendar can be
 * cached for a bounded lifetime instead of opting out of page caching.
 *
 * @package DataMachineEvents\Blocks\Calendar\Cache
 */

namespace DataMachineEvents\Blocks\Calendar\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PageExpiry {

	/** Shortest lifetime advertised, so imminent boundaries cannot defeat caching. */
	const FLOOR = MINUTE_IN_SECONDS;

	/** Longest lifetime advertised; a safety net for missed invalidation paths. */
	const CEILING = HOUR_IN_SECONDS;

	/**
	 * Smallest max-age computed during this request, or null when no calendar rendered.
	 *
	 * @var int|null
	 */
	private static $max_age = null;

	/**
	 * Clamp the seconds remaining until a transition into the page lifetime.
	 *
	 * @param string $transition MySQL datetime in the site timezone, or '' when none exists.
	 * @param int    $now        Current Unix timestamp.
	 * @return int Lifetime in seconds, between FLOOR and CEILING.
	 */
	public static function max_age_until( string $transition, int $now ): int {
		if ( '' === $transition ) {
			return self::CEILING;
		}

		try {
			$transition_time = new \DateTimeImmutable( $transition, wp_timezone() );
		} catch ( \Exception $e ) {
			return self::FLOOR;
		}

		$seconds = $transition_time->getTimestamp() - $now;

		return min( self::CEILING, max( self::FLOOR, $seconds ) );
	}

	/**
	 * Lifetime of a calendar rendered now.
	 *
	 * @return int Lifetime in seconds.
	 */
	public static function max_age(): int {
		return self::max_age_until(
			CalendarCache::next_upcoming_transition(),
			current_datetime()->getTimestamp()
		);
	}

	/**
	 * Record a calendar render and advertise the page expiry.
	 *
	 * Multiple calendars on one page share the shortest lifetime. The header
	 * is only sent for anonymous visitors, whose responses are shareable.
	 *
	 * @return int Lifetime recorded for this request.
	 */
	public static function emit(): int {
		$max_age       = self::max_age();
		self::$max_age = null === self::$max_age ? $max_age : min( self::$max_age, $max_age );

		if ( ! is_user_logged_in() && ! headers_sent() ) {
			header( self::header_value( self::$max_age ) );
		}

		return self::$max_age;
	}

	/**
	 * Build the Cache-Control header line.
	 *
	 * @param int $max_age Lifetime in seconds.
	 * @return string Header line.
	 */
	public static function header_value( int $max_age ): string {
		return sprintf( 'Cache-Control: public, max-age=%d', $max_age );
	}

	/**
	 * Lifetime recorded for this request, or null when no calendar rendered.
	 *
	 * @return int|null
	 */
	public static function current(): ?int {
		return self::$max_age;
	}

	/**
	 * Forget the recorded lifetime (tests and long-running processes).
	 */
	public static function reset(): void {
		self::$max_age = null;
	}
}
