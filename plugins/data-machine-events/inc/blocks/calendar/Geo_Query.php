<?php
/**
 * Geographic Query for venue proximity filtering
 *
 * Finds venue term IDs within a given radius of a coordinate point
 * using the haversine formula on _venue_coordinates term meta.
 *
 * @package DataMachineEvents\Blocks\Calendar
 */

namespace DataMachineEvents\Blocks\Calendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Geo_Query {

	/**
	 * Earth radius in miles (for haversine formula).
	 */
	private const EARTH_RADIUS_MI = 3959;

	/**
	 * Earth radius in kilometers.
	 */
	private const EARTH_RADIUS_KM = 6371;

	/**
	 * Default search radius in miles.
	 */
	private const DEFAULT_RADIUS = 25;

	/**
	 * Maximum search radius in miles.
	 */
	private const MAX_RADIUS = 500;

	/**
	 * Find venue term IDs within radius of a point
	 *
	 * Queries _venue_coordinates term meta (stored as "lat,lng" strings)
	 * using the haversine formula to calculate great-circle distance.
	 *
	 * @param float  $lat         Latitude of center point.
	 * @param float  $lng         Longitude of center point.
	 * @param float  $radius      Search radius.
	 * @param string $radius_unit Unit: 'mi' (miles) or 'km' (kilometers). Default 'mi'.
	 * @return array Array of [ 'term_id' => int, 'distance' => float ] sorted by distance.
	 */
	public static function find_venues_within_radius( float $lat, float $lng, float $radius = self::DEFAULT_RADIUS, string $radius_unit = 'mi' ): array {
		global $wpdb;

		$radius = max( 1, min( $radius, self::MAX_RADIUS ) );

		$earth_radius = 'km' === $radius_unit ? self::EARTH_RADIUS_KM : self::EARTH_RADIUS_MI;

		// Get the termmeta table — handles multisite table prefix.
		$termmeta_table = $wpdb->termmeta;

		// Haversine SQL: calculate distance from each venue's coordinates.
		// _venue_coordinates is stored as "lat,lng" string in termmeta.
		//
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					tm.term_id,
					( %f * ACOS(
						LEAST(1.0, GREATEST(-1.0,
							COS( RADIANS( %f ) )
							* COS( RADIANS( CAST( SUBSTRING_INDEX( tm.meta_value, ',', 1 ) AS DECIMAL(10,7) ) ) )
							* COS( RADIANS( CAST( SUBSTRING_INDEX( tm.meta_value, ',', -1 ) AS DECIMAL(10,7) ) ) - RADIANS( %f ) )
							+ SIN( RADIANS( %f ) )
							* SIN( RADIANS( CAST( SUBSTRING_INDEX( tm.meta_value, ',', 1 ) AS DECIMAL(10,7) ) ) )
						))
					) ) AS distance
				FROM {$termmeta_table} tm
				WHERE tm.meta_key = '_venue_coordinates'
					AND tm.meta_value != ''
					AND tm.meta_value LIKE '%%,%%'
				HAVING distance <= %f
				ORDER BY distance ASC",
				$earth_radius,
				$lat,
				$lng,
				$lat,
				$radius
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $results ) ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return array(
					'term_id'  => (int) $row->term_id,
					'distance' => round( (float) $row->distance, 1 ),
				);
			},
			$results
		);
	}

	/**
	 * Get venue term IDs within radius (just the IDs, for use in tax_query)
	 *
	 * @param float  $lat         Latitude.
	 * @param float  $lng         Longitude.
	 * @param float  $radius      Search radius.
	 * @param string $radius_unit Unit: 'mi' or 'km'.
	 * @return array Array of venue term IDs.
	 */
	public static function get_venue_ids_within_radius( float $lat, float $lng, float $radius = self::DEFAULT_RADIUS, string $radius_unit = 'mi' ): array {
		$venues = self::find_venues_within_radius( $lat, $lng, $radius, $radius_unit );

		return array_column( $venues, 'term_id' );
	}

	/**
	 * Build a venue distance lookup map
	 *
	 * @param float  $lat         Latitude.
	 * @param float  $lng         Longitude.
	 * @param float  $radius      Search radius.
	 * @param string $radius_unit Unit: 'mi' or 'km'.
	 * @return array Associative array [ term_id => distance ].
	 */
	public static function get_venue_distance_map( float $lat, float $lng, float $radius = self::DEFAULT_RADIUS, string $radius_unit = 'mi' ): array {
		$venues = self::find_venues_within_radius( $lat, $lng, $radius, $radius_unit );

		$map = array();
		foreach ( $venues as $venue ) {
			$map[ $venue['term_id'] ] = $venue['distance'];
		}

		return $map;
	}

	/**
	 * Validate geo parameters
	 *
	 * @param mixed $lat    Latitude value.
	 * @param mixed $lng    Longitude value.
	 * @param mixed $radius Radius value.
	 * @return bool True if valid.
	 */
	public static function validate_params( $lat, $lng, $radius = null ): bool {
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return false;
		}

		$lat = (float) $lat;
		$lng = (float) $lng;

		if ( $lat < -90 || $lat > 90 ) {
			return false;
		}

		if ( $lng < -180 || $lng > 180 ) {
			return false;
		}

		if ( null !== $radius ) {
			if ( ! is_numeric( $radius ) || (float) $radius <= 0 ) {
				return false;
			}
		}

		return true;
	}
}
