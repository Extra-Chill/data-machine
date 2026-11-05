<?php
/**
 * Keep PHPUnit offline, including tests which remove all HTTP filters.
 *
 * WordPress checks pre_http_request before WP_HTTP_BLOCK_EXTERNAL, so explicit
 * HTTP fixtures still work. Unmocked geocoding, scraper and image requests fail
 * immediately instead of waiting for DNS/network timeouts.
 *
 * @package DataMachineEvents\Tests
 */

if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) {
	define( 'WP_HTTP_BLOCK_EXTERNAL', true );
}
