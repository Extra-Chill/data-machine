<?php
/**
 * Calendar Cache Manager
 *
 * Centralizes all transient caching for calendar queries.
 * Handles cache key generation, TTLs, and get/set operations.
 *
 * @package DataMachineEvents\Blocks\Calendar\Cache
 * @since   0.14.0
 */

namespace DataMachineEvents\Blocks\Calendar\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CalendarCache {

	const PREFIX         = 'datamachine_cal_';
	const TTL_DATES      = 5 * MINUTE_IN_SECONDS;
	const TTL_COUNTS     = 10 * MINUTE_IN_SECONDS;

	/**
	 * Get a cached value.
	 *
	 * @param string $key Full cache key.
	 * @return mixed Cached value or false if not found.
	 */
	public static function get( string $key ) {
		return get_transient( $key );
	}

	/**
	 * Set a cached value.
	 *
	 * @param string $key   Full cache key.
	 * @param mixed  $value Value to cache.
	 * @param int    $ttl   Time-to-live in seconds.
	 * @return bool True on success.
	 */
	public static function set( string $key, $value, int $ttl ): bool {
		return set_transient( $key, $value, $ttl );
	}

	/**
	 * Generate a cache key from query parameters.
	 *
	 * @param array  $params Query parameters.
	 * @param string $prefix Key prefix (e.g. 'dates', 'counts').
	 * @return string Full cache key.
	 */
	public static function generate_key( array $params, string $prefix ): string {
		$key_data = array(
			'show_past'    => $params['show_past'] ?? false,
			'search_query' => $params['search_query'] ?? '',
			'date_start'   => $params['date_start'] ?? '',
			'date_end'     => $params['date_end'] ?? '',
			'tax_filters'  => $params['tax_filters'] ?? array(),
			'archive_tax'  => $params['archive_taxonomy'] ?? '',
			'archive_term' => $params['archive_term_id'] ?? 0,
		);

		return self::PREFIX . $prefix . '_' . md5( wp_json_encode( $key_data ) );
	}
}
