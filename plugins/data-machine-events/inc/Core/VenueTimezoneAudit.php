<?php
/**
 * Venue timezone consistency classifier.
 *
 * Compares a venue's stored timezone with what its location implies and
 * decides whether the stored value is safe to repair automatically. Pure:
 * no reads, no writes, no network. See #898.
 *
 * Statuses:
 *  - ok                    Stored zone agrees with location (same January and
 *                          July UTC offsets as the expected zone).
 *  - invalid               Stored value is not a usable IANA zone. Repairable
 *                          only when an exact region rule applies; otherwise
 *                          reported with the best estimate.
 *  - mismatch              Stored zone disagrees with an exact region rule
 *                          (single-zone country or single-zone US state).
 *                          Repairable.
 *  - review                Stored zone disagrees only with an estimated rule
 *                          (split state, longitude band, nearest zone).
 *                          Report only.
 *  - coordinates_conflict  Coordinates fall outside the venue's US region,
 *                          so coordinate-based rules are untrustworthy.
 *                          Report only unless an exact region rule applies.
 *  - missing               No stored zone.
 *  - unresolvable          Location cannot resolve any zone.
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

use DateTime;
use DateTimeZone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueTimezoneAudit {

	public const OK                   = 'ok';
	public const INVALID              = 'invalid';
	public const MISMATCH             = 'mismatch';
	public const REVIEW               = 'review';
	public const COORDINATES_CONFLICT = 'coordinates_conflict';
	public const MISSING              = 'missing';
	public const UNRESOLVABLE         = 'unresolvable';

	/**
	 * Classify one venue.
	 *
	 * @param string $stored      Stored `_venue_timezone`.
	 * @param string $coordinates Stored "lat,lng" or empty.
	 * @param string $country     Stored country.
	 * @param string $state       Stored state.
	 * @return array{status: string, expected: string, source: string, repairable: bool}
	 */
	public static function classify( string $stored, string $coordinates, string $country, string $state ): array {
		$stored         = trim( $stored );
		$region         = VenueTimezoneResolver::resolveFromRegion( $country, $state );
		$coords_suspect = VenueTimezoneResolver::coordinatesContradictRegion( $coordinates, $country, $state );

		if ( $region ) {
			$expected = $region;
		} elseif ( $coords_suspect ) {
			$expected = VenueTimezoneResolver::resolveOffline( '', $country, $state );
		} else {
			$expected = VenueTimezoneResolver::resolveOffline( $coordinates, $country, $state );
		}

		$expected_zone   = $expected['timezone'] ?? '';
		$expected_source = $expected['source'] ?? '';
		$exact           = null !== $region;

		if ( '' === $stored ) {
			return self::result( '' === $expected_zone ? self::UNRESOLVABLE : self::MISSING, $expected_zone, $expected_source, false );
		}

		if ( ! DateTimeParser::isValidTimezone( $stored ) ) {
			return self::result( self::INVALID, $expected_zone, $expected_source, $exact );
		}

		if ( '' === $expected_zone ) {
			return self::result( $coords_suspect ? self::COORDINATES_CONFLICT : self::UNRESOLVABLE, '', '', false );
		}

		if ( self::equivalent( $stored, $expected_zone ) ) {
			return self::result( $coords_suspect ? self::COORDINATES_CONFLICT : self::OK, $expected_zone, $expected_source, false );
		}

		if ( $exact ) {
			return self::result( self::MISMATCH, $expected_zone, $expected_source, true );
		}

		return self::result( $coords_suspect ? self::COORDINATES_CONFLICT : self::REVIEW, $expected_zone, $expected_source, false );
	}

	/**
	 * Whether two zones observe the same UTC offsets in winter and summer.
	 *
	 * Treats aliases and same-rule zones (America/Detroit, America/New_York)
	 * as equal so the repair does not churn equivalent values.
	 *
	 * @param string $a Valid IANA zone.
	 * @param string $b Valid IANA zone.
	 * @return bool
	 */
	public static function equivalent( string $a, string $b ): bool {
		if ( $a === $b ) {
			return true;
		}

		$zone_a = new DateTimeZone( $a );
		$zone_b = new DateTimeZone( $b );
		$year   = (int) gmdate( 'Y' );

		foreach ( array( '-01-15 12:00:00', '-07-15 12:00:00' ) as $suffix ) {
			$moment = new DateTime( $year . $suffix, new DateTimeZone( 'UTC' ) );
			if ( $zone_a->getOffset( $moment ) !== $zone_b->getOffset( $moment ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array{status: string, expected: string, source: string, repairable: bool}
	 */
	private static function result( string $status, string $expected, string $source, bool $repairable ): array {
		return array(
			'status'     => $status,
			'expected'   => $expected,
			'source'     => $source,
			'repairable' => $repairable,
		);
	}
}
