<?php
/**
 * Performance optimizations for the events post type at scale.
 *
 * Addresses known WordPress Core performance gaps when a site has high
 * post volume and frequent writes (e.g. automated event pipelines).
 *
 * @package DataMachineEvents
 * @subpackage Core
 * @since 0.23.0
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache get_lastpostmodified() and get_lastpostdate() results in a transient.
 *
 * WordPress Core's _get_last_post_time() queries `ORDER BY post_date_gmt DESC LIMIT 1`
 * across all public post types on every page load. The result is cached in the object
 * cache but invalidated on every post insert/update via clean_post_cache().
 *
 * On high-write sites (automated pipelines upserting thousands of events/day),
 * the cache invalidation rate exceeds the hit rate, causing the unindexed query
 * to run on nearly every request (~2-3s per execution on 100K+ row tables).
 *
 * This short-circuits both functions with a transient-cached value that refreshes
 * every 5 minutes instead of on every post change.
 *
 * @see https://core.trac.wordpress.org/ticket/15499 (open since 2010)
 */
function cache_last_post_time(): void {
	add_filter( 'pre_get_lastpostmodified', __NAMESPACE__ . '\\get_cached_lastpostmodified', 10, 3 );
	add_filter( 'get_lastpostdate', __NAMESPACE__ . '\\get_cached_lastpostdate', 10, 3 );
}

/**
 * Return a transient-cached last-modified time instead of running the slow query.
 *
 * @param string|false $lastpostmodified Pre-filtered value (false to run query).
 * @param string       $timezone         Timezone context.
 * @param string       $post_type        Post type filter.
 * @return string|false Cached timestamp or false on first call.
 */
function get_cached_lastpostmodified( $lastpostmodified, string $timezone, string $post_type ) {
	// Only cache the 'any' post type query (the expensive one with IN (...)).
	if ( 'any' !== $post_type ) {
		return $lastpostmodified;
	}

	$cache_key = 'dme_lastpostmodified_' . strtolower( $timezone );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return $cached;
	}

	// Let WordPress run the query this once, then cache the result.
	remove_filter( 'pre_get_lastpostmodified', __NAMESPACE__ . '\\get_cached_lastpostmodified', 10 );
	$value = get_lastpostmodified( $timezone, $post_type );
	add_filter( 'pre_get_lastpostmodified', __NAMESPACE__ . '\\get_cached_lastpostmodified', 10, 3 );

	if ( false !== $value ) {
		set_transient( $cache_key, $value, 5 * MINUTE_IN_SECONDS );
	}

	return $value;
}

/**
 * Cache get_lastpostdate() with a transient.
 *
 * get_lastpostmodified() internally calls get_lastpostdate(), triggering a
 * second slow query. This caches both together.
 *
 * @param string|false $lastpostdate Filtered value from the query.
 * @param string       $timezone     Timezone context.
 * @param string       $post_type    Post type filter.
 * @return string|false Cached or original timestamp.
 */
function get_cached_lastpostdate( $lastpostdate, string $timezone, string $post_type ) {
	if ( 'any' !== $post_type || false === $lastpostdate ) {
		return $lastpostdate;
	}

	$cache_key = 'dme_lastpostdate_' . strtolower( $timezone );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return $cached;
	}

	set_transient( $cache_key, $lastpostdate, 5 * MINUTE_IN_SECONDS );

	return $lastpostdate;
}
