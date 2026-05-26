<?php
/**
 * Browser navigation guard for HTML-in-JSON REST endpoints.
 *
 * The calendar / filters REST endpoints return server-rendered HTML
 * inside a JSON envelope. They are only meant to be hit by `fetch()`
 * from the calendar block. When a human browser hits the URL directly
 * (address bar paste, middle-click on an old anchor that should never
 * have existed, share-link from a developer console, etc.) the raw
 * JSON page is a terrible UX.
 *
 * This guard detects browser-direct navigations and either redirects
 * to the canonical archive URL (when `archive_taxonomy` +
 * `archive_term_id` are present and resolve) or returns a 404. JS
 * callers continue to get JSON because they identify themselves via
 * `X-Requested-With: XMLHttpRequest` (and/or an `Accept` header that
 * prefers `application/json`).
 *
 * See Extra-Chill/data-machine-events#297.
 *
 * @package DataMachineEvents\Api
 */

namespace DataMachineEvents\Api;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class BrowserNavigationGuard {

	/**
	 * Inspect the request and return a redirect/404 response when the
	 * caller looks like a browser navigation rather than a JS fetch.
	 *
	 * Returns null when the request looks like a legitimate JS caller
	 * and the controller should proceed normally.
	 *
	 * Detection rules (in priority order):
	 *   1. `X-Requested-With: XMLHttpRequest` → trust as JS. Pass.
	 *   2. `Accept` header that explicitly prefers JSON over HTML
	 *      (i.e. mentions `application/json` and does NOT mention
	 *      `text/html`, OR ranks `application/json` ahead of
	 *      `text/html` via q-values) → trust as JS. Pass.
	 *   3. Otherwise treat as a browser navigation. If
	 *      `archive_taxonomy` + `archive_term_id` resolve via
	 *      `get_term_link()`, return a 302 to that URL (with `past`
	 *      preserved as a query string). Otherwise return a 404.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error|null Response/error when the
	 *                                        guard fires; null when
	 *                                        the controller should
	 *                                        proceed.
	 */
	public static function guard( WP_REST_Request $request ) {
		if ( self::looks_like_js_caller( $request ) ) {
			return null;
		}

		$archive_taxonomy = (string) $request->get_param( 'archive_taxonomy' );
		$archive_term_id  = (int) $request->get_param( 'archive_term_id' );

		if ( $archive_taxonomy && $archive_term_id ) {
			$term_link = get_term_link( $archive_term_id, $archive_taxonomy );

			if ( ! is_wp_error( $term_link ) && is_string( $term_link ) && $term_link ) {
				$past = $request->get_param( 'past' );
				if ( $past ) {
					$term_link = add_query_arg( 'past', rawurlencode( (string) $past ), $term_link );
				}

				$response = new WP_REST_Response( null, 302 );
				$response->header( 'Location', $term_link );
				$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
				return $response;
			}
		}

		return new WP_Error(
			'rest_not_found',
			__( 'This URL is not meant to be opened directly.', 'data-machine-events' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Heuristic: does this request look like an XHR/fetch call from
	 * the calendar block, rather than a top-level browser navigation?
	 *
	 * @param WP_REST_Request $request
	 * @return bool
	 */
	protected static function looks_like_js_caller( WP_REST_Request $request ): bool {
		// 1. Explicit XHR header — the most reliable signal. JS
		//    callers in the calendar block set this; browsers do not
		//    add it on direct navigations.
		$requested_with = (string) $request->get_header( 'x_requested_with' );
		if ( '' !== $requested_with && 0 === strcasecmp( $requested_with, 'XMLHttpRequest' ) ) {
			return true;
		}

		// 2. Accept header analysis. Browser address-bar navigations
		//    send something like:
		//      text/html,application/xhtml+xml,application/xml;q=0.9,
		//      image/avif,image/webp,*/*;q=0.8
		//    JS `fetch()` without explicit Accept defaults to `*/*`.
		//    JS callers in this plugin that explicitly want JSON set
		//    `Accept: application/json`.
		$accept = (string) $request->get_header( 'accept' );

		// No Accept header at all → not a browser nav (browsers
		// always send one). Treat as JS caller.
		if ( '' === $accept ) {
			return true;
		}

		$prefers_html = self::accept_prefers_html( $accept );
		return ! $prefers_html;
	}

	/**
	 * Does the Accept header prefer `text/html` over
	 * `application/json`?
	 *
	 * Implements a small, dependency-free q-value comparison that
	 * handles the common cases:
	 *   - `text/html,application/xhtml+xml,application/xml;q=0.9,...`
	 *     → prefers HTML (text/html implicit q=1).
	 *   - `*\/*` (default fetch) → does NOT prefer HTML (no explicit
	 *     mention).
	 *   - `application/json` → does NOT prefer HTML.
	 *   - `text/html;q=0.5,application/json` → does NOT prefer HTML.
	 *
	 * @param string $accept Raw Accept header value.
	 * @return bool
	 */
	protected static function accept_prefers_html( string $accept ): bool {
		$html_q = self::accept_q_value( $accept, 'text/html' );
		$json_q = self::accept_q_value( $accept, 'application/json' );

		// If neither is mentioned by name, it's not a clear browser
		// nav — let the controller answer.
		if ( null === $html_q && null === $json_q ) {
			return false;
		}

		if ( null === $html_q ) {
			return false;
		}

		if ( null === $json_q ) {
			return true;
		}

		return $html_q > $json_q;
	}

	/**
	 * Return the q-value for a given media type in an Accept header,
	 * or null if the type is not mentioned.
	 *
	 * @param string $accept Raw Accept header value.
	 * @param string $type   Media type to look up (e.g. `text/html`).
	 * @return float|null
	 */
	protected static function accept_q_value( string $accept, string $type ): ?float {
		$parts = array_map( 'trim', explode( ',', $accept ) );

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			$segments  = array_map( 'trim', explode( ';', $part ) );
			$media     = strtolower( array_shift( $segments ) );
			$type_norm = strtolower( $type );

			if ( $media !== $type_norm ) {
				continue;
			}

			$q = 1.0;
			foreach ( $segments as $segment ) {
				if ( 0 === stripos( $segment, 'q=' ) ) {
					$candidate = (float) substr( $segment, 2 );
					if ( $candidate >= 0.0 && $candidate <= 1.0 ) {
						$q = $candidate;
					}
				}
			}

			return $q;
		}

		return null;
	}
}
