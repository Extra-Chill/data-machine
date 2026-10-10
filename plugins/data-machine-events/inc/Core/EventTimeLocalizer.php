<?php
/**
 * Event time localization.
 *
 * Extractors report times exactly as the source gives them. When the source
 * pins an instant (an ISO offset or "Z"), the extractor also passes that
 * offset as `startOffset` / `endOffset`. This class converts those times to
 * the venue's local wall clock in one place, so a source that publishes UTC
 * does not put UTC times on the calendar. See #905.
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

use DateTime;
use DateTimeZone;
use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventTimeLocalizer {

	/**
	 * Convert offset-anchored start/end times to venue-local time.
	 *
	 * Offsets are always removed from the returned event. Times without an
	 * offset, or events whose venue timezone is unknown, keep the source's
	 * wall clock.
	 *
	 * @param array  $event          Extracted event.
	 * @param string $venue_timezone IANA timezone of the venue, or ''.
	 * @return array Event with localized times and no offset keys.
	 */
	public static function localize( array $event, string $venue_timezone ): array {
		$zone = DateTimeParser::isValidTimezone( $venue_timezone ) ? new DateTimeZone( $venue_timezone ) : null;

		foreach ( array( 'start', 'end' ) as $prefix ) {
			$offset = trim( (string) ( $event[ $prefix . 'Offset' ] ?? '' ) );
			unset( $event[ $prefix . 'Offset' ] );

			$date = (string) ( $event[ $prefix . 'Date' ] ?? '' );
			$time = (string) ( $event[ $prefix . 'Time' ] ?? '' );

			if ( null === $zone || '' === $offset || '' === $time || ! DateTimeParser::isValidYmd( $date ) ) {
				continue;
			}

			try {
				$instant = new DateTime( "{$date} {$time}", new DateTimeZone( $offset ) );
			} catch ( Exception $e ) {
				continue;
			}

			$instant->setTimezone( $zone );
			$event[ $prefix . 'Date' ] = $instant->format( 'Y-m-d' );
			$event[ $prefix . 'Time' ] = $instant->format( 'H:i' );
		}

		return $event;
	}

	/**
	 * Venue timezone for an extracted event.
	 *
	 * Order: the configured venue term's stored zone; an exact single-zone
	 * country/state rule from the event's venue fields; the offline location
	 * resolver; a valid IANA zone the source supplied.
	 *
	 * @param array $event  Extracted event (venue fields already merged).
	 * @param array $config Handler config.
	 * @return string IANA timezone or ''.
	 */
	public static function venueTimezone( array $event, array $config ): string {
		if ( ! empty( $config['venue'] ) && is_numeric( $config['venue'] ) ) {
			$stored = (string) get_term_meta( (int) $config['venue'], '_venue_timezone', true );
			if ( DateTimeParser::isValidTimezone( $stored ) ) {
				return $stored;
			}
		}

		$country     = (string) ( $event['venueCountry'] ?? '' );
		$state       = (string) ( $event['venueState'] ?? '' );
		$coordinates = (string) ( $event['venueCoordinates'] ?? '' );

		$resolved = VenueTimezoneResolver::resolveFromRegion( $country, $state )
			?? VenueTimezoneResolver::resolveOffline( $coordinates, $country, $state );
		if ( $resolved ) {
			return $resolved['timezone'];
		}

		$hint = (string) ( $event['venueTimezone'] ?? '' );
		return DateTimeParser::isValidTimezone( $hint ) ? $hint : '';
	}
}
