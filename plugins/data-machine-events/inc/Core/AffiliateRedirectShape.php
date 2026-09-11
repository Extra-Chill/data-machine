<?php
// phpcs:disable Generic.CodeAnalysis.AssignmentInCondition -- strpos() iteration via while-assign is the established loop pattern here.
/**
 * Affiliate Redirect Shape Detection and Repair
 *
 * Shape-based detector for affiliate/redirect ticket and organizer URLs whose
 * redirect parameter lost its URL punctuation (scheme `://` and path `/`
 * stripped — e.g. `?u=httpswww.ticketmaster.comeventZ7r9jZ1A7jv1d` instead of
 * `?u=https%3A%2F%2Fwww.ticketmaster.com%2Fevent%2FZ7r9jZ1A7jv1d`).
 *
 * Detection is deliberately SHAPE-based, not string-based: after extracting
 * the redirect parameter through the shared extractor
 * (`datamachine_find_affiliate_redirect_param()`), the value must parse as an
 * absolute URL with a scheme AND a host. Matching the literal `httpswww`
 * would catch exactly one corruption variant and nothing else; the
 * scheme/host assertion catches the whole class — colon-only stripping,
 * slash-only stripping, partial encodes — on every affiliate host the
 * filterable list knows about. See
 * https://github.com/Extra-Chill/data-machine-events/issues/823.
 *
 * Repair is confidence-gated: a corrupted destination is reconstructed only
 * when it maps unambiguously onto a known destination platform shape
 * (Ticketmaster `/event/{id}`, Ticketweb `/event/{slug}-tickets/{id}`). A
 * wrong affiliate destination is strictly worse than a broken one — it
 * silently sends a real user to the wrong event — so when reconstruction is
 * not unique the row is reported and skipped, never guessed.
 *
 * @package DataMachineEvents\Core
 * @since   0.63.0
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AffiliateRedirectShape {

	/**
	 * Detect a corrupted redirect parameter in a stored URL.
	 *
	 * Normalizes storage artifacts first (`\u0026` sequences, HTML-entity
	 * encoding — see `datamachine_decode_stored_url_artifacts()`), then asks
	 * the affiliate host list whether this is a wrapper at all, then asserts
	 * the first present redirect parameter parses as an absolute URL with a
	 * scheme and a host. Healthy wrappers and non-affiliate URLs return null;
	 * a wrapper with no redirect parameter at all also returns null (the
	 * destination may be encoded elsewhere in the wrapper — unknowable, so
	 * not flagged).
	 *
	 * @param string $stored_url Raw stored URL (artifacts allowed).
	 * @return array{param:string, value:string}|null Corrupted parameter name
	 *                                                and decoded value, or
	 *                                                null when not corrupted.
	 */
	public static function find_corrupted_redirect( string $stored_url ): ?array {
		if ( '' === $stored_url ) {
			return null;
		}

		$url = datamachine_decode_stored_url_artifacts( $stored_url );

		if ( ! data_machine_events_is_affiliate_ticket_url( $url ) ) {
			return null;
		}

		$redirect = datamachine_find_affiliate_redirect_param( $url );
		if ( null === $redirect ) {
			return null;
		}

		if ( ! self::is_absolute_url( $redirect['value'] ) ) {
			return $redirect;
		}

		return null;
	}

	/**
	 * Whether a value parses as an absolute URL with a scheme and a host.
	 *
	 * The whole detector rests on this assertion. It intentionally does NOT
	 * pattern-match any literal corruption signature.
	 *
	 * @param string $value Decoded redirect-parameter value.
	 * @return bool True when scheme and host are both present.
	 */
	public static function is_absolute_url( string $value ): bool {
		if ( '' === $value ) {
			return false;
		}

		$parsed = wp_parse_url( $value );

		return ! empty( $parsed['scheme'] ) && ! empty( $parsed['host'] );
	}

	/**
	 * Reconstruct the canonical destination from a punctuation-stripped value.
	 *
	 * The corruption concatenates `scheme + host + path` with `://` and `/`
	 * removed. Reconstruction requires the destination host to be a known
	 * platform AND the host/path split point to be UNIQUE under the
	 * platform's path shape. Multiple valid split points means the value
	 * cannot be reconstructed with confidence and this returns null — the
	 * caller must report and skip, never guess.
	 *
	 * Known destination shapes (evidence: healthy production rows):
	 *
	 * - Ticketmaster: `{slug}/event/{id}` → `https://www.ticketmaster.com/event/{id}`.
	 *   IDs are `[0-9A-Za-z_]{6,}` — observed IDs contain no hyphens; slugs
	 *   are `[a-z0-9-]*`. `event` is the path marker; if the marker position
	 *   is ambiguous (slug containing the word "event" followed by an
	 *   ID-shaped tail) the split must be unique or the value is skipped.
	 *
	 * Other destination platforms seen corrupted in production (Ticketweb)
	 * are intentionally NOT reconstructed yet — see the comment inside.
	 *
	 * @param string $corrupted_value Decoded redirect-parameter value.
	 * @return string Canonical absolute destination URL, or null when not
	 *                confidently reconstructable.
	 */
	public static function reconstruct_destination( string $corrupted_value ): ?string {
		if ( preg_match( '/^https((?:www\.)?ticketmaster\.com)(.*)$/', $corrupted_value, $m ) ) {
			return self::reconstruct_ticketmaster( $m[2] );
		}

		// Deliberately Ticketmaster-only. Other destination platforms (e.g.
		// Ticketweb, observed in production) are DETECTED by
		// find_corrupted_redirect() and reported as skipped-ambiguous by the
		// repair surface, but are not reconstructed until their canonical
		// path shape is confirmed against healthy stored rows. A wrong
		// affiliate destination silently sends a real user to the wrong
		// event; a broken-but-visible link is strictly safer.
		return null;
	}

	/**
	 * Reconstruct a Ticketmaster destination from the stripped remainder.
	 *
	 * Splits the remainder at every `event` marker occurrence where the left
	 * side is a valid slug and the right side a valid Ticketmaster event ID;
	 * exactly one valid split is required. The canonical destination is the
	 * slug-less `/event/{id}` form — the ID is the stable identity (see
	 * `datamachine_extract_ticket_identity()`) and healthy stored wrappers
	 * use the slug-less form.
	 *
	 * @param string $remainder Value remainder after `https` + host.
	 * @return string|null Canonical absolute URL, or null when ambiguous.
	 */
	private static function reconstruct_ticketmaster( string $remainder ): ?string {
		$candidates = array();
		$pos        = 0;

		while ( ( $pos = strpos( $remainder, 'event', $pos ) ) !== false ) {
			$slug = substr( $remainder, 0, $pos );
			$id   = substr( $remainder, $pos + strlen( 'event' ) );

			// IDs are `[0-9A-Za-z_]{6,40}` — observed IDs carry no hyphens;
			// slugs are lowercase alphanumeric + hyphens. Both bounds keep
			// the split-point search honest. Zero candidates = unknown
			// shape; multiple = genuinely ambiguous (e.g. a slug ending in
			// "event" before an ID-shaped tail). A wrong affiliate
			// destination silently sends a real user to the wrong event, so
			// ambiguity means skip, never guess.
			if ( preg_match( '/^[a-z0-9-]*$/', $slug ) && preg_match( '/^[0-9A-Za-z_]{6,40}$/', $id ) ) {
				$candidates[] = $id;
			}

			++$pos;
		}

		if ( 1 !== count( $candidates ) ) {
			return null;
		}

		return 'https://www.ticketmaster.com/event/' . $candidates[0];
	}

	/**
	 * Repair a stored affiliate wrapper whose redirect parameter is corrupted.
	 *
	 * Detects via `find_corrupted_redirect()`, reconstructs the canonical
	 * destination via `reconstruct_destination()`, and rebuilds the wrapper
	 * preserving its base URL and all other query parameters (only the
	 * corrupted redirect parameter's value is replaced, re-encoded in the
	 * same percent-encoded shape healthy stored wrappers use). Returns null
	 * when the URL is healthy, not an affiliate wrapper, or the destination
	 * cannot be reconstructed with confidence.
	 *
	 * @param string $stored_url Raw stored URL (artifacts allowed).
	 * @return array{param:string, before:string, after:string, destination:string}|null
	 */
	public static function repair_stored_url( string $stored_url ): ?array {
		$corrupted = self::find_corrupted_redirect( $stored_url );
		if ( null === $corrupted ) {
			return null;
		}

		$destination = self::reconstruct_destination( $corrupted['value'] );
		if ( null === $destination ) {
			return null;
		}

		$decoded = datamachine_decode_stored_url_artifacts( $stored_url );
		$parsed  = wp_parse_url( $decoded );
		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return null;
		}

		$base = ( $parsed['scheme'] ?? 'https' ) . '://' . $parsed['host'] . ( $parsed['path'] ?? '' );

		parse_str( $parsed['query'] ?? '', $query_params );
		$query_params[ $corrupted['param'] ] = $destination;

		return array(
			'param'       => $corrupted['param'],
			'before'      => $stored_url,
			'after'       => $base . '?' . http_build_query( $query_params ),
			'destination' => $destination,
		);
	}
}
