<?php
/**
 * Calendar Cache Manager
 *
 * Centralizes all caching for calendar queries.
 * Handles cache key generation, TTLs, and get/set operations.
 *
 * Two cache layers:
 * 1. Bucket caches (dates, counts) — keyed without geo params, used by
 *    EventQueryBuilder/Pagination internals. Stored as transients (which
 *    Redis object cache backs anyway on persistent-cache hosts).
 * 2. Full-response cache (this is the calendar REST envelope itself,
 *    pre-rendered HTML included). Keyed on the COMPLETE CalendarRequest
 *    envelope INCLUDING geo params. Stored in wp_cache (dedicated group
 *    `data-machine-calendar`) with transient fallback for non-persistent
 *    cache environments.
 *
 * The full-response cache is the DOS mitigation: bot crawlers hammering
 * `?past=1&lat=...&lng=...&archive_taxonomy=venue&archive_term_id=...`
 * variants now hit one expensive query per cache window instead of one
 * per request. See Extra-Chill/data-machine-events#246.
 *
 * @package DataMachineEvents\Blocks\Calendar\Cache
 * @since   0.14.0
 */

namespace DataMachineEvents\Blocks\Calendar\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CalendarCache {

	const PREFIX            = 'data-machine_cal_';
	const FULL_PREFIX       = 'data-machine_cal_full_';
	const GROUP             = 'data-machine-calendar';
	const TTL_DATES         = 30 * MINUTE_IN_SECONDS;
	const TTL_COUNTS        = 30 * MINUTE_IN_SECONDS;
	const TTL_FULL_UPCOMING = HOUR_IN_SECONDS;
	const TTL_FULL_PAST     = 24 * HOUR_IN_SECONDS;

	/**
	 * Get a cached value (transient-backed bucket cache).
	 *
	 * @param string $key Full cache key.
	 * @return mixed Cached value or false if not found.
	 */
	public static function get( string $key ) {
		return get_transient( $key );
	}

	/**
	 * Set a cached value (transient-backed bucket cache).
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
	 * Generate a cache key from query parameters (bucket caches).
	 *
	 * Does NOT include geo params — bucket caches operate on the broader
	 * date/count slice and geo filtering happens downstream.
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
			// Bucketing depends on the cutoff hour; fold it into the key so
			// switching the filter at runtime invalidates stale buckets.
			'cutoff_hour'  => \DataMachineEvents\Blocks\Calendar\Grouping\LateNightCutoff::cutoff_hour(),
		);

		return self::PREFIX . $prefix . '_' . md5( wp_json_encode( $key_data ) );
	}

	/**
	 * Generate a cache key for the full calendar REST response.
	 *
	 * Includes the COMPLETE CalendarRequest envelope so distinct geo
	 * searches, scopes, paged windows, and archive contexts all get
	 * isolated cache buckets. This is the key surface that issue #246
	 * was missing — bot variants over `lat`/`lng`/`radius`/`archive_term_id`
	 * collapsed onto one bucket and re-ran the query every time.
	 *
	 * @param array $envelope CalendarRequest::toAbilitiesArgs() output.
	 * @return string Full cache key.
	 */
	public static function generate_full_response_key( array $envelope ): string {
		$key_data = array(
			'paged'            => (int) ( $envelope['paged'] ?? 1 ),
			'past'             => (bool) ( $envelope['past'] ?? false ),
			'event_search'     => (string) ( $envelope['event_search'] ?? '' ),
			'date_start'       => (string) ( $envelope['date_start'] ?? '' ),
			'date_end'         => (string) ( $envelope['date_end'] ?? '' ),
			'scope'            => (string) ( $envelope['scope'] ?? '' ),
			'tax_filter'       => $envelope['tax_filter'] ?? array(),
			'archive_taxonomy' => (string) ( $envelope['archive_taxonomy'] ?? '' ),
			'archive_term_id'  => (int) ( $envelope['archive_term_id'] ?? 0 ),
			'geo_lat'          => (string) ( $envelope['geo_lat'] ?? '' ),
			'geo_lng'          => (string) ( $envelope['geo_lng'] ?? '' ),
			'geo_radius'       => (int) ( $envelope['geo_radius'] ?? 0 ),
			'geo_radius_unit'  => (string) ( $envelope['geo_radius_unit'] ?? '' ),
			'cutoff_hour'      => \DataMachineEvents\Blocks\Calendar\Grouping\LateNightCutoff::cutoff_hour(),
			// Phase 1 of refactor #298: HTML and data-only responses
			// have different shapes and MUST live in separate cache
			// buckets — otherwise the first response shape served
			// for a given envelope sticks for the cache TTL.
			'format'           => (string) ( $envelope['format'] ?? '' ),
			// #318: month-grid mode scopes events to a specific YYYY-MM
			// window (regardless of past/upcoming). Fold the month into
			// the key so grid and list responses for the same archive
			// never share a cache bucket, and so distinct grid months
			// each get their own bucket.
			'month'            => (string) ( $envelope['month'] ?? '' ),
		);

		return self::FULL_PREFIX . md5( wp_json_encode( $key_data ) );
	}

	/**
	 * Get a cached full calendar REST response.
	 *
	 * Tries the object cache first (Redis/Memcached on persistent-cache
	 * hosts), falls back to a transient. Returns false on miss so callers
	 * can use the standard `false === $cached` check.
	 *
	 * @param string $key Full cache key from generate_full_response_key().
	 * @return mixed Cached envelope array or false on miss.
	 */
	public static function get_full_response( string $key ) {
		$found  = false;
		$cached = wp_cache_get( $key, self::GROUP, false, $found );
		if ( $found && false !== $cached ) {
			return $cached;
		}

		// Transient fallback for non-persistent cache environments. On
		// Redis-backed hosts get_transient also routes through the object
		// cache, so this is functionally a no-op there but harmless.
		$transient = get_transient( $key );
		if ( false !== $transient ) {
			// Promote into the object cache so subsequent hits in this
			// process / cache window skip the transient SQL lookup.
			wp_cache_set( $key, $transient, self::GROUP, self::ttl_for_envelope_default() );
			return $transient;
		}

		return false;
	}

	/**
	 * Set a cached full calendar REST response.
	 *
	 * Writes to BOTH the object cache and the transient store. The
	 * transient store survives a `wp_cache_flush()` and acts as the
	 * source of truth for non-persistent cache hosts; the object cache
	 * is the fast path for persistent-cache hosts.
	 *
	 * @param string $key   Full cache key from generate_full_response_key().
	 * @param mixed  $value Response envelope to cache.
	 * @param int    $ttl   Time-to-live in seconds.
	 * @return bool True on success.
	 */
	public static function set_full_response( string $key, $value, int $ttl ): bool {
		wp_cache_set( $key, $value, self::GROUP, $ttl );
		return set_transient( $key, $value, $ttl );
	}

	/**
	 * Resolve the appropriate full-response TTL for a request envelope.
	 *
	 * Past events are immutable — once a show happened, it happened.
	 * Cache them aggressively (24h). Upcoming events change as new ones
	 * are published, but `CacheInvalidator` busts the entire group on
	 * any event save / taxonomy edit, so a 1h ceiling is just a safety
	 * net for missed invalidation paths.
	 *
	 * @param array $envelope CalendarRequest::toAbilitiesArgs() output.
	 * @return int TTL seconds.
	 */
	public static function ttl_for_envelope( array $envelope ): int {
		$past = ! empty( $envelope['past'] );
		return $past ? self::TTL_FULL_PAST : self::TTL_FULL_UPCOMING;
	}

	/**
	 * Default TTL used when promoting a transient hit back into the
	 * object cache. We don't know if the entry was past or upcoming at
	 * promotion time, so we use the shorter (upcoming) TTL — better to
	 * recompute one extra time than to extend an already-stale window.
	 *
	 * @return int TTL seconds.
	 */
	private static function ttl_for_envelope_default(): int {
		return self::TTL_FULL_UPCOMING;
	}
}
