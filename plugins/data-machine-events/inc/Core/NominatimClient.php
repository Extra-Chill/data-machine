<?php
/**
 * Nominatim Client — single point of HTTP plumbing for OpenStreetMap Nominatim.
 *
 * Consolidates user-agent, rate-limit sleep, cache key prefix/TTL, and HTTP
 * surface across every Nominatim callsite in the plugin.
 *
 * Before this helper existed three callsites talked to Nominatim with three
 * different levels of cache/rate-limit/user-agent discipline:
 *
 *   - GeocodingAbilities::executeGeocodeAddress() — delegated to
 *     Venue_Taxonomy::query_nominatim(), cached 30d (dme_geocode_ prefix).
 *   - GeocodingAbilities::executeGeocodeSearch()  — inline wp_remote_get(),
 *     no cache, no rate-limit, own user-agent.
 *   - Venue_Taxonomy::query_nominatim()           — HttpClient::get(), no
 *     cache, single-result only.
 *   - CheckMissingVenueAddressesCommand           — inline reverse + forward
 *     HTTP via HttpClient::get(), own constants.
 *
 * All of those now route through one of three public methods on this class:
 *
 *   - searchAddress(query, limit, countrycodes) — multi-result with
 *     addressdetails, used for autocomplete UIs.
 *   - geocodeOne(query)                         — single-result, used by
 *     backend geocoding. Returns array with lat/lng/display_name keys.
 *   - reverseGeocode(lat, lng)                  — used by the address audit
 *     command.
 *
 * Cache prefix and TTL match the pre-existing transient contract
 * (`dme_geocode_` + 30 days) so warm cache entries are not orphaned.
 *
 * @package DataMachineEvents\Core
 * @since   0.40.0
 */

namespace DataMachineEvents\Core;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NominatimClient {

	/**
	 * Single canonical user-agent for every Nominatim request from this plugin.
	 *
	 * Matches the value previously hard-coded across three call sites; do not
	 * change without coordinating with OSM usage policy.
	 */
	public const USER_AGENT = 'DataMachineEvents/1.0 (https://extrachill.com)';

	/**
	 * Transient prefix for cached geocoding results.
	 *
	 * Kept identical to the pre-helper value so warm cache entries written
	 * by GeocodingAbilities::executeGeocodeAddress() remain readable.
	 */
	public const CACHE_PREFIX = 'dme_geocode_';

	/**
	 * Cache TTL for `geocodeOne()` results (30 days). Matches the previous
	 * GeocodingAbilities::CACHE_TTL exactly.
	 */
	public const CACHE_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Seconds to sleep between batched Nominatim requests to respect OSM's
	 * usage policy. Mirrors the value previously used by
	 * GeocodingAbilities::executeGeocodeVenues() and
	 * CheckMissingVenueAddressesCommand.
	 */
	public const RATE_LIMIT_SECONDS = 2;

	private const ENDPOINT_SEARCH  = 'https://nominatim.openstreetmap.org/search';
	private const ENDPOINT_REVERSE = 'https://nominatim.openstreetmap.org/reverse';

	private const HTTP_TIMEOUT = 10;

	/**
	 * Forward-search Nominatim and return multiple results with full address
	 * details. Used by autocomplete UIs (geocode-search ability).
	 *
	 * @param string $query        Search query (address, city, or place name).
	 * @param int    $limit        Max results to return (1-10).
	 * @param string $countrycodes Comma-separated country codes to restrict
	 *                              results (e.g. "us" or "us,ca").
	 * @return array<int,array<string,mixed>>|\WP_Error Array of Nominatim
	 *   result rows on success, or WP_Error on transport/decode failure.
	 */
	public static function searchAddress( string $query, int $limit = 5, string $countrycodes = '' ): array|\WP_Error {
		$query = trim( $query );
		if ( '' === $query ) {
			return new \WP_Error( 'invalid_query', 'Query is required.', array( 'status' => 400 ) );
		}

		$limit = min( max( 1, $limit ), 10 );

		$args = array(
			'format'         => 'json',
			'addressdetails' => '1',
			'limit'          => (string) $limit,
			'q'              => $query,
		);

		if ( '' !== $countrycodes ) {
			$args['countrycodes'] = $countrycodes;
		}

		$url = add_query_arg( $args, self::ENDPOINT_SEARCH );

		$data = self::request( $url, 'Nominatim Search' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'nominatim_invalid_response', 'Invalid response from Nominatim.', array( 'status' => 500 ) );
		}

		return $data;
	}

	/**
	 * Geocode a single address string to lat/lng with 30-day transient cache.
	 *
	 * Returns an associative array on hit:
	 *   - lat          (string) Latitude as returned by Nominatim.
	 *   - lng          (string) Longitude as returned by Nominatim.
	 *   - display_name (string) Display name from Nominatim (or the input
	 *                            query when Nominatim omits it).
	 *   - cached       (bool)   true when the result was served from the
	 *                            transient cache, false on a fresh lookup.
	 *
	 * @param string $query Address string (3-500 chars after sanitization).
	 * @return array{lat:string,lng:string,display_name:string,cached:bool}|\WP_Error
	 */
	public static function geocodeOne( string $query ): array|\WP_Error {
		$query = trim( $query );
		if ( '' === $query || strlen( $query ) < 3 ) {
			return new \WP_Error( 'invalid_query', 'Query must be at least 3 characters.', array( 'status' => 400 ) );
		}

		$query = substr( $query, 0, 500 );

		$cache_key = self::CACHE_PREFIX . md5( strtolower( $query ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			$cached['cached'] = true;
			return $cached;
		}

		$url = add_query_arg(
			array(
				'format' => 'json',
				'limit'  => '1',
				'q'      => $query,
			),
			self::ENDPOINT_SEARCH
		);

		$data = self::request( $url, 'Nominatim Geocode' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! is_array( $data ) || empty( $data[0] ) || ! isset( $data[0]['lat'], $data[0]['lon'] ) ) {
			return new \WP_Error( 'geocode_failed', 'Could not geocode address: no results from Nominatim.', array( 'status' => 404 ) );
		}

		$top = $data[0];

		$result = array(
			'lat'          => (string) $top['lat'],
			'lng'          => (string) $top['lon'],
			'display_name' => isset( $top['display_name'] ) ? (string) $top['display_name'] : $query,
			'cached'       => false,
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Reverse-geocode lat/lng coordinates to an address payload (raw
	 * Nominatim response). Used by the address-audit CLI to repair venues
	 * that already have coordinates but lack structured address fields.
	 *
	 * Returns the decoded JSON payload on success — callers parse the
	 * `address` block themselves because the granular component mapping
	 * (`house_number` + `road` → street, `city`/`town`/`village` cascade,
	 * etc.) is domain-specific and belongs at the callsite.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return array<string,mixed>|\WP_Error Decoded Nominatim payload, or
	 *   WP_Error on transport/decode failure.
	 */
	public static function reverseGeocode( float $lat, float $lng ): array|\WP_Error {
		$url = add_query_arg(
			array(
				'format'         => 'jsonv2',
				'lat'            => (string) $lat,
				'lon'            => (string) $lng,
				'addressdetails' => '1',
			),
			self::ENDPOINT_REVERSE
		);

		$data = self::request( $url, 'Nominatim Reverse' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'nominatim_invalid_response', 'Invalid response from Nominatim reverse endpoint.', array( 'status' => 500 ) );
		}

		return $data;
	}

	/**
	 * Sleep RATE_LIMIT_SECONDS — call between successive Nominatim requests
	 * in batch loops to respect OSM's usage policy.
	 *
	 * Centralized here so callers don't carry their own sleep constants.
	 */
	public static function sleepForRateLimit(): void {
		sleep( self::RATE_LIMIT_SECONDS );
	}

	/**
	 * Issue a single HTTP GET via the shared HttpClient wrapper and return
	 * the JSON-decoded payload (or WP_Error).
	 *
	 * @param string $url     Fully built Nominatim URL.
	 * @param string $context HttpClient context string for logs.
	 * @return mixed Decoded JSON value or WP_Error on transport/decode failure.
	 */
	private static function request( string $url, string $context ) {
		$result = HttpClient::get(
			$url,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array(
					'User-Agent' => self::USER_AGENT,
				),
				'context' => $context,
			)
		);

		if ( empty( $result['success'] ) ) {
			$error = isset( $result['error'] ) ? (string) $result['error'] : 'Unknown error';
			return new \WP_Error(
				'nominatim_request_failed',
				'Nominatim request failed: ' . $error,
				array( 'status' => 500 )
			);
		}

		$body = is_string( $result['data'] ?? null ) ? $result['data'] : '';
		$data = json_decode( $body, true );

		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error(
				'nominatim_invalid_response',
				'Invalid JSON from Nominatim: ' . json_last_error_msg(),
				array( 'status' => 500 )
			);
		}

		return $data;
	}
}
