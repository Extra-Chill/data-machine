<?php
/**
 * Venue timezone resolver.
 *
 * Resolves an IANA timezone for a venue from whatever evidence is available,
 * in descending order of confidence:
 *
 *  1. GeoNames (network, exact) when a username is configured and the venue
 *     has coordinates.
 *  2. Offline country rule: a country with exactly one IANA zone is exact.
 *  3. Offline US state rule: a US state that lies entirely in one zone is
 *     exact. Split states (FL, IN, KY, TN, MI, ND, SD, NE, KS, TX, ID, OR)
 *     resolve by an explicit coordinate boundary rule when coordinates are
 *     present. These boundaries are approximations of the real county lines.
 *  4. Offline US longitude band when only coordinates are known.
 *  5. Offline nearest-zone rule for non-US countries: the closest zone
 *     location (per {@see \DateTimeZone::getLocation()}) among the country's
 *     zones, or all zones when country is unknown. Approximate — logged as
 *     such. Not used for the US, where zone reference cities are too sparse
 *     for distance to be meaningful (Austin is closer to Denver than Chicago).
 *
 * Every strategy is pure PHP except GeoNames. This is what lets a venue
 * created on a site without GeoNames still publish instead of failing the
 * canonical publication guard with "venue timezone is missing or invalid".
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueTimezoneResolver {

	public const SOURCE_GEONAMES        = 'geonames';
	public const SOURCE_COUNTRY         = 'country_single_zone';
	public const SOURCE_US_STATE        = 'us_state';
	public const SOURCE_US_STATE_REGION = 'us_state_region';
	public const SOURCE_US_LONGITUDE    = 'us_longitude_band';
	public const SOURCE_NEAREST         = 'nearest_zone_in_country';
	public const SOURCE_NEAREST_GLOBAL  = 'nearest_zone_global';

	/**
	 * US states/territories whose entire area sits in a single IANA zone.
	 *
	 * Split states are intentionally omitted so they fall through to the
	 * coordinate-based strategy rather than being guessed from the dominant
	 * zone.
	 */
	private const US_STATE_ZONES = array(
		'AL' => 'America/Chicago',
		'AK' => 'America/Anchorage',
		'AR' => 'America/Chicago',
		'AZ' => 'America/Phoenix',
		'CA' => 'America/Los_Angeles',
		'CO' => 'America/Denver',
		'CT' => 'America/New_York',
		'DE' => 'America/New_York',
		'DC' => 'America/New_York',
		'GA' => 'America/New_York',
		'HI' => 'Pacific/Honolulu',
		'IL' => 'America/Chicago',
		'IA' => 'America/Chicago',
		'LA' => 'America/Chicago',
		'ME' => 'America/New_York',
		'MD' => 'America/New_York',
		'MA' => 'America/New_York',
		'MN' => 'America/Chicago',
		'MS' => 'America/Chicago',
		'MO' => 'America/Chicago',
		'MT' => 'America/Denver',
		'NV' => 'America/Los_Angeles',
		'NH' => 'America/New_York',
		'NJ' => 'America/New_York',
		'NM' => 'America/Denver',
		'NY' => 'America/New_York',
		'NC' => 'America/New_York',
		'OH' => 'America/New_York',
		'OK' => 'America/Chicago',
		'PA' => 'America/New_York',
		'RI' => 'America/New_York',
		'SC' => 'America/New_York',
		'UT' => 'America/Denver',
		'VT' => 'America/New_York',
		'VA' => 'America/New_York',
		'WA' => 'America/Los_Angeles',
		'WV' => 'America/New_York',
		'WI' => 'America/Chicago',
		'WY' => 'America/Denver',
		'PR' => 'America/Puerto_Rico',
	);

	/**
	 * Split US states: [default zone, [[zone, predicate-name], ...]].
	 *
	 * Predicates are evaluated by {@see self::splitStateZone()} against
	 * (lat, lng). Boundaries approximate the real county lines; each is
	 * chosen so the major cities on both sides resolve correctly.
	 */
	private const US_SPLIT_STATE_RULES = array(
		// El Paso / Hudspeth counties are Mountain.
		'TX' => array( 'America/Chicago', array( array( 'America/Denver', 'lng<-104.9' ) ) ),
		// Panhandle west of the Apalachicola River is Central.
		'FL' => array( 'America/New_York', array( array( 'America/Chicago', 'lng<-85.05&lat>29.5' ) ) ),
		// NW (Gary) and SW (Evansville) corners are Central.
		'IN' => array( 'America/Indiana/Indianapolis', array( array( 'America/Chicago', 'lng<-86.9&lat>40.7' ), array( 'America/Chicago', 'lng<-86.9&lat<38.5' ) ) ),
		// Western Kentucky (Bowling Green, Paducah) is Central; Louisville is Eastern.
		'KY' => array( 'America/New_York', array( array( 'America/Chicago', 'lng<-86.0' ) ) ),
		// Nashville Central; Knoxville and Chattanooga Eastern.
		'TN' => array( 'America/Chicago', array( array( 'America/New_York', 'lng>-85.5' ) ) ),
		// Four Upper Peninsula counties bordering Wisconsin are Central.
		'MI' => array( 'America/Detroit', array( array( 'America/Menominee', 'lng<-87.5&lat>45.0' ) ) ),
		// South-western North Dakota is Mountain.
		'ND' => array( 'America/Chicago', array( array( 'America/Denver', 'lng<-101.5&lat<47.4' ) ) ),
		// West of the Missouri River is Mountain.
		'SD' => array( 'America/Chicago', array( array( 'America/Denver', 'lng<-100.5' ) ) ),
		// Panhandle is Mountain.
		'NE' => array( 'America/Chicago', array( array( 'America/Denver', 'lng<-101.0' ) ) ),
		// Four far-western counties are Mountain.
		'KS' => array( 'America/Chicago', array( array( 'America/Denver', 'lng<-101.5' ) ) ),
		// Northern panhandle is Pacific.
		'ID' => array( 'America/Boise', array( array( 'America/Los_Angeles', 'lat>45.5' ) ) ),
		// Most of Malheur County is Mountain.
		'OR' => array( 'America/Los_Angeles', array( array( 'America/Boise', 'lng>-117.7&lat<44.4' ) ) ),
	);

	/**
	 * Full US state names → postal codes, so "South Carolina" resolves like "SC".
	 */
	private const US_STATE_NAMES = array(
		'alabama'              => 'AL',
		'alaska'               => 'AK',
		'arizona'              => 'AZ',
		'arkansas'             => 'AR',
		'california'           => 'CA',
		'colorado'             => 'CO',
		'connecticut'          => 'CT',
		'delaware'             => 'DE',
		'district of columbia' => 'DC',
		'florida'              => 'FL',
		'georgia'              => 'GA',
		'hawaii'               => 'HI',
		'idaho'                => 'ID',
		'illinois'             => 'IL',
		'indiana'              => 'IN',
		'iowa'                 => 'IA',
		'kansas'               => 'KS',
		'kentucky'             => 'KY',
		'louisiana'            => 'LA',
		'maine'                => 'ME',
		'maryland'             => 'MD',
		'massachusetts'        => 'MA',
		'michigan'             => 'MI',
		'minnesota'            => 'MN',
		'mississippi'          => 'MS',
		'missouri'             => 'MO',
		'montana'              => 'MT',
		'nebraska'             => 'NE',
		'nevada'               => 'NV',
		'new hampshire'        => 'NH',
		'new jersey'           => 'NJ',
		'new mexico'           => 'NM',
		'new york'             => 'NY',
		'north carolina'       => 'NC',
		'north dakota'         => 'ND',
		'ohio'                 => 'OH',
		'oklahoma'             => 'OK',
		'oregon'               => 'OR',
		'pennsylvania'         => 'PA',
		'puerto rico'          => 'PR',
		'rhode island'         => 'RI',
		'south carolina'       => 'SC',
		'south dakota'         => 'SD',
		'tennessee'            => 'TN',
		'texas'                => 'TX',
		'utah'                 => 'UT',
		'vermont'              => 'VT',
		'virginia'             => 'VA',
		'washington'           => 'WA',
		'west virginia'        => 'WV',
		'wisconsin'            => 'WI',
		'wyoming'              => 'WY',
	);

	/**
	 * Resolve a timezone for a venue from its stored profile.
	 *
	 * @param int $term_id Venue term ID.
	 * @return array{timezone: string, source: string}|null
	 */
	public static function resolveForVenue( int $term_id ): ?array {
		if ( $term_id <= 0 ) {
			return null;
		}

		return self::resolve(
			(string) get_term_meta( $term_id, '_venue_coordinates', true ),
			(string) get_term_meta( $term_id, '_venue_country', true ),
			(string) get_term_meta( $term_id, '_venue_state', true )
		);
	}

	/**
	 * Resolve a timezone from coordinates, country, and state.
	 *
	 * @param string $coordinates "lat,lng" or empty.
	 * @param string $country     ISO 3166-1 alpha-2 or a common country name, or empty.
	 * @param string $state       Region/state code or name, or empty.
	 * @return array{timezone: string, source: string}|null
	 */
	public static function resolve( string $coordinates, string $country = '', string $state = '' ): ?array {
		$coordinates  = trim( $coordinates );
		$country_code = self::normalizeCountry( $country );
		$state_code   = self::normalizeUsState( $state );

		// If we have a US-looking state but no country, it's the US.
		if ( '' === $country_code && '' !== $state_code ) {
			$country_code = 'US';
		}

		if ( '' !== $coordinates && GeoNamesService::isConfigured() ) {
			$timezone = GeoNamesService::getTimezoneFromCoordinates( $coordinates );
			if ( $timezone && self::isValid( $timezone ) ) {
				return self::result( $timezone, self::SOURCE_GEONAMES );
			}
		}

		$point = '' !== $coordinates ? self::parseCoordinates( $coordinates ) : null;

		// Unknown country but coordinates inside the continental US: treat as
		// US so we get a canonical zone (America/New_York) rather than whichever
		// obscure zone reference city happens to be nearest.
		if ( '' === $country_code && $point && self::isContinentalUs( $point[0], $point[1] ) ) {
			$country_code = 'US';
		}

		if ( '' !== $country_code ) {
			$zones = self::zonesForCountry( $country_code );

			if ( 1 === count( $zones ) ) {
				return self::result( $zones[0], self::SOURCE_COUNTRY );
			}

			if ( 'US' === $country_code ) {
				$us = self::resolveUnitedStates( $state_code, $point );
				if ( $us ) {
					return $us;
				}
			} elseif ( $point && ! empty( $zones ) ) {
				$nearest = self::nearestZone( $coordinates, $zones );
				if ( $nearest ) {
					return self::result( $nearest, self::SOURCE_NEAREST );
				}
			}
		}

		if ( $point ) {
			$nearest = self::nearestZone( $coordinates, \DateTimeZone::listIdentifiers() );
			if ( $nearest ) {
				return self::result( $nearest, self::SOURCE_NEAREST_GLOBAL );
			}
		}

		return null;
	}

	/**
	 * US resolution: single-zone state → split-state boundary → longitude band.
	 *
	 * @param string                       $state_code Postal code or empty.
	 * @param array{0: float, 1: float}|null $point      Parsed lat/lng or null.
	 * @return array{timezone: string, source: string}|null
	 */
	private static function resolveUnitedStates( string $state_code, ?array $point ): ?array {
		if ( isset( self::US_STATE_ZONES[ $state_code ] ) ) {
			return self::result( self::US_STATE_ZONES[ $state_code ], self::SOURCE_US_STATE );
		}

		if ( isset( self::US_SPLIT_STATE_RULES[ $state_code ] ) ) {
			list( $default_zone, $rules ) = self::US_SPLIT_STATE_RULES[ $state_code ];

			if ( ! $point ) {
				// No coordinates: the default zone covers the large majority of
				// every split state's population, but flag it as an estimate.
				return self::result( $default_zone, self::SOURCE_US_STATE_REGION );
			}

			foreach ( $rules as list( $zone, $predicate ) ) {
				if ( self::matchesPredicate( $predicate, $point[0], $point[1] ) ) {
					return self::result( $zone, self::SOURCE_US_STATE_REGION );
				}
			}

			return self::result( $default_zone, self::SOURCE_US_STATE_REGION );
		}

		if ( ! $point ) {
			return null;
		}

		return self::result( self::usLongitudeBand( $point[0], $point[1] ), self::SOURCE_US_LONGITUDE );
	}

	/**
	 * Rough continental-US bounding box (excludes AK/HI, which need a country).
	 */
	private static function isContinentalUs( float $lat, float $lng ): bool {
		return $lat >= 24.5 && $lat <= 49.5 && $lng >= -125.0 && $lng <= -66.9;
	}

	/**
	 * Crude continental-US band lookup for coordinates without a usable state.
	 *
	 * Band edges sit inside the real zone boundaries' fuzz; anything near an
	 * edge is an estimate and is reported as such by the caller.
	 */
	private static function usLongitudeBand( float $lat, float $lng ): string {
		if ( $lat < 23.0 && $lng < -154.0 ) {
			return 'Pacific/Honolulu';
		}
		if ( $lat > 51.0 && $lng < -130.0 ) {
			return 'America/Anchorage';
		}
		if ( $lng >= -85.0 ) {
			return 'America/New_York';
		}
		if ( $lng >= -102.0 ) {
			return 'America/Chicago';
		}
		if ( $lng >= -114.0 ) {
			return 'America/Denver';
		}
		return 'America/Los_Angeles';
	}

	/**
	 * Evaluate a tiny predicate DSL: "lng<-104.9", "lng<-85.05&lat>29.5".
	 */
	private static function matchesPredicate( string $predicate, float $lat, float $lng ): bool {
		foreach ( explode( '&', $predicate ) as $clause ) {
			if ( ! preg_match( '/^(lat|lng)([<>])(-?\d+(?:\.\d+)?)$/', $clause, $m ) ) {
				return false;
			}
			$value     = 'lat' === $m[1] ? $lat : $lng;
			$threshold = (float) $m[3];
			$ok        = '<' === $m[2] ? $value < $threshold : $value > $threshold;
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a resolution source is an exact match (as opposed to a nearest-zone estimate).
	 *
	 * @param string $source Source constant.
	 * @return bool
	 */
	public static function isExactSource( string $source ): bool {
		return in_array( $source, array( self::SOURCE_GEONAMES, self::SOURCE_COUNTRY, self::SOURCE_US_STATE ), true );
	}

	/**
	 * IANA zones for a country. Empty when the country is unknown to PHP.
	 *
	 * @param string $country_code ISO alpha-2.
	 * @return string[]
	 */
	public static function zonesForCountry( string $country_code ): array {
		if ( 2 !== strlen( $country_code ) ) {
			return array();
		}

		return \DateTimeZone::listIdentifiers( \DateTimeZone::PER_COUNTRY, strtoupper( $country_code ) );
	}

	/**
	 * Closest zone (by great-circle distance to the zone's reference location).
	 *
	 * @param string   $coordinates "lat,lng".
	 * @param string[] $candidates  IANA identifiers.
	 * @return string|null
	 */
	public static function nearestZone( string $coordinates, array $candidates ): ?string {
		$point = self::parseCoordinates( $coordinates );
		if ( ! $point ) {
			return null;
		}

		$best          = null;
		$best_distance = PHP_FLOAT_MAX;

		foreach ( $candidates as $identifier ) {
			try {
				$location = ( new \DateTimeZone( $identifier ) )->getLocation();
			} catch ( \Exception $e ) {
				continue;
			}

			// PHP reports 0,0 (or false) for zones without a known location (e.g. "UTC").
			if ( false === $location || ( 0.0 === (float) $location['latitude'] && 0.0 === (float) $location['longitude'] ) ) {
				continue;
			}

			$distance = self::haversine( $point[0], $point[1], (float) $location['latitude'], (float) $location['longitude'] );
			if ( $distance < $best_distance ) {
				$best_distance = $distance;
				$best          = $identifier;
			}
		}

		return $best;
	}

	/**
	 * Normalize a country string to ISO alpha-2 where recognisable.
	 *
	 * @param string $country Raw country value.
	 * @return string Alpha-2 code or empty.
	 */
	public static function normalizeCountry( string $country ): string {
		$country = trim( $country );
		if ( '' === $country ) {
			return '';
		}

		if ( 2 === strlen( $country ) && ctype_alpha( $country ) ) {
			return strtoupper( $country );
		}

		$aliases = array(
			'united states'            => 'US',
			'united states of america' => 'US',
			'usa'                      => 'US',
			'u.s.'                     => 'US',
			'u.s.a.'                   => 'US',
			'united kingdom'           => 'GB',
			'uk'                       => 'GB',
			'great britain'            => 'GB',
			'england'                  => 'GB',
			'scotland'                 => 'GB',
			'wales'                    => 'GB',
			'canada'                   => 'CA',
			'mexico'                   => 'MX',
			'australia'                => 'AU',
			'germany'                  => 'DE',
			'france'                   => 'FR',
			'netherlands'              => 'NL',
			'the netherlands'          => 'NL',
			'japan'                    => 'JP',
			'brazil'                   => 'BR',
			'brasil'                   => 'BR',
			'ireland'                  => 'IE',
			'spain'                    => 'ES',
			'italy'                    => 'IT',
			'portugal'                 => 'PT',
			'belgium'                  => 'BE',
			'sweden'                   => 'SE',
			'norway'                   => 'NO',
			'denmark'                  => 'DK',
			'new zealand'              => 'NZ',
		);

		$key = strtolower( $country );

		return $aliases[ $key ] ?? '';
	}

	/**
	 * Normalize a US state string to its postal code where recognisable.
	 *
	 * @param string $state Raw state value.
	 * @return string Postal code or empty.
	 */
	public static function normalizeUsState( string $state ): string {
		$state = trim( $state );
		if ( '' === $state ) {
			return '';
		}

		if ( 2 === strlen( $state ) && ctype_alpha( $state ) ) {
			$code = strtoupper( $state );
			return in_array( $code, self::US_STATE_NAMES, true ) ? $code : '';
		}

		return self::US_STATE_NAMES[ strtolower( $state ) ] ?? '';
	}

	/**
	 * @param string $identifier IANA identifier.
	 * @return bool
	 */
	private static function isValid( string $identifier ): bool {
		return in_array( $identifier, \DateTimeZone::listIdentifiers( \DateTimeZone::ALL_WITH_BC ), true );
	}

	/**
	 * @param string $coordinates "lat,lng".
	 * @return array{0: float, 1: float}|null
	 */
	private static function parseCoordinates( string $coordinates ): ?array {
		$parts = array_map( 'trim', explode( ',', $coordinates ) );
		if ( 2 !== count( $parts ) || ! is_numeric( $parts[0] ) || ! is_numeric( $parts[1] ) ) {
			return null;
		}

		$lat = (float) $parts[0];
		$lng = (float) $parts[1];

		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return null;
		}

		return array( $lat, $lng );
	}

	/**
	 * Great-circle distance in kilometres.
	 */
	private static function haversine( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$earth_radius_km = 6371.0;

		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lng = deg2rad( $lng2 - $lng1 );

		$a = sin( $d_lat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lng / 2 ) ** 2;

		return 2 * $earth_radius_km * asin( min( 1.0, sqrt( $a ) ) );
	}

	/**
	 * @param string $timezone IANA identifier.
	 * @param string $source   Source constant.
	 * @return array{timezone: string, source: string}
	 */
	private static function result( string $timezone, string $source ): array {
		return array(
			'timezone' => $timezone,
			'source'   => $source,
		);
	}
}
