<?php
/**
 * Ticket Destination Config: Canonical Storage + Resolve-Time Wrapper Assembly
 *
 * The Ticketmaster Discovery API hands us an Impact Radius affiliate wrapper
 * (our API key is affiliate-linked). Historically that wrapper — affiliate ID,
 * campaign ID, ad ID and all — was persisted verbatim into the Event Details
 * block's `ticketUrl` attribute, freezing all three IDs into ~74,500 rows of
 * `post_content`. Rotating any of them was therefore a mass content migration.
 *
 * This file owns the other half of the fix (see issue #818):
 *
 * 1. `data_machine_events_ticket_wrapper_config()` — ONE filterable config
 *    array describing the wrapper (template + IDs). Rotating the affiliate ID
 *    is a single filter/`wp option`-style edit with zero content writes.
 * 2. `data_machine_events_assemble_affiliate_wrapper()` — assembles the
 *    wrapper from that config at resolve time. Byte-identity with what the
 *    Impact Radius wrapper has always carried is asserted by test against
 *    real stored production values.
 * 3. `data_machine_events_is_gated_ticket_url()` — whether a ticket URL must
 *    render through the first-party redirect gate instead of a direct
 *    `href`. True for stored wrappers AND for canonical URLs a monetized
 *    vendor would wrap at resolve time; this is what keeps the #816
 *    compliance gating working after rows are migrated to canonical storage.
 *
 * Entity/storage-artifact normalization (the `\u0026` + HTML-entity decode
 * pair) is NOT duplicated here — it lives in the shared
 * `datamachine_decode_stored_url_artifacts()` (event-dates-sync.php, issue
 * #823). This file's consumers use that one shared helper, not a private
 * copy; see `ResolveTicketDestinationAbilities::normalizeResolvedUrl()` and
 * `TicketUrlCanonicalBackfillAbilities`.
 *
 * Read-path contract (tolerant of both stored shapes, which is what makes
 * the backfill non-blocking and independently revertible):
 * - Wrapper still stored → returned as-is.
 * - Canonical stored     → wrapper assembled at resolve time.
 * See `ResolveTicketDestinationAbilities`.
 *
 * @package DataMachineEvents\Core
 * @since   0.62.1
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Affiliate wrapper assembly config.
 *
 * The single place the Impact Radius wrapper is described. Defaults mirror
 * exactly what the Ticketmaster Discovery API has been returning and what is
 * already frozen into production `post_content` — the template reproduces
 * today's wrapper byte-for-byte (proven by test), so resolve-time assembly is
 * indistinguishable from the previously stored values.
 *
 * `apply_to_hosts` controls which canonical URLs get wrapped: only vendors we
 * actually monetize. Bare registrable domains, matched exact-or-subdomain
 * case-insensitively (same semantics as
 * `data_machine_events_affiliate_ticket_hosts()`). `www.ticketmaster.com`
 * matches; `ticketmaster.co.uk` deliberately does not — those regional links
 * are unwrapped in the catalogue today and monetizing them is an open
 * revenue question (see issue #818 "Related observations"), not something to
 * silently change here.
 *
 * Verified against a random 800-row sample of the live wrapped corpus
 * (read-only query, no test-suite fixture): destinations wrapped via the
 * Discovery API are NOT limited to `ticketmaster.com` — `ticketweb.com`
 * (Ticketmaster's own sub-brand for smaller venues) is ~33% of the entire
 * wrapped corpus (24,620 of 74,643 published rows) and every sampled row
 * round-trips byte-identically once its host is included here. Both hosts
 * belong in `apply_to_hosts`. A long tail of one-off third-party box-office
 * hosts (axs.com, evenue.net, etix.com, universe.com, fgtix.com, and
 * arbitrary venue-run ticketing sites) also arrive wrapped from the same
 * API, but are NOT Ticketmaster-owned, are individually tiny (1-3 rows each
 * in the sample), and are deliberately left OUT of this list — they fail
 * the backfill's round-trip guard (`skipped_roundtrip_mismatch`) and stay
 * wrapper-stored, which is safe and correct: an unbounded, ever-growing
 * per-venue host allowlist here would not be "vendors we actually
 * monetize," it would be guessing at an open set.
 *
 * @since 0.62.1
 *
 * @return array<string, mixed> Config with keys `enabled` (bool), `template`
 *                             (string), `affiliate_id`/`campaign_id`/
 *                             `ad_id` (string), `apply_to_hosts` (string[]).
 */
function data_machine_events_ticket_wrapper_config(): array {
	$config = array(
		'enabled'        => true,
		'template'       => 'https://ticketmaster.evyy.net/c/{affiliate_id}/{campaign_id}/{ad_id}?u={encoded_destination}&utm_medium=affiliate',
		'affiliate_id'   => '1191134',
		'campaign_id'    => '264167',
		'ad_id'          => '4272',
		'apply_to_hosts' => array(
			// Ticketmaster's own two consumer-facing brands. Both destinations
			// arrive affiliate-wrapped via the same Discovery API response
			// (`ticketmaster.com` ~66%, `ticketweb.com` ~33% of the wrapped
			// corpus by row count) and both round-trip byte-identically.
			// Third-party box-office hosts (axs.com, etix.com, evenue.net,
			// etc.) are deliberately excluded — see the docblock above.
			'ticketmaster.com',
			'ticketweb.com',
		),
	);

	/**
	 * Filters the affiliate wrapper assembly config.
	 *
	 * Rotating the affiliate ID (or moving networks) is an edit here — a
	 * filter on this value, or on the underlying stored option it may be
	 * backed by — with zero content writes. `{encoded_destination}` in the
	 * template receives the RFC 3986 percent-encoded canonical URL;
	 * dropping that placeholder is the supported way to disable wrapping
	 * per-environment (assembly then passes URLs through unchanged, as does
	 * `enabled => false`).
	 *
	 * @since 0.62.1
	 *
	 * @param array $config See `data_machine_events_ticket_wrapper_config()`.
	 */
	return apply_filters( 'data_machine_events_ticket_wrapper_config', $config );
}
/**
 * Assemble the affiliate wrapper for a canonical ticket URL.
 *
 * Returns the URL unchanged when wrapping does not apply: empty input,
 * config disabled, a template without the `{encoded_destination}`
 * placeholder (defensive — never emit a wrapper without its destination),
 * or a host outside `apply_to_hosts`. When it does apply, the wrapper is
 * built from `data_machine_events_ticket_wrapper_config()` with the
 * destination RFC 3986 percent-encoded into the template — byte-identical
 * to what Impact Radius has always carried for the dominant Ticketmaster
 * event-URL class (asserted against real stored production values in the
 * test suite).
 *
 * The destination encoding uses `rawurlencode()` (RFC 3986, uppercase hex),
 * which is exactly the encoding observed in the stored wrappers; the
 * round-trip guard in the backfill (`TicketUrlCanonicalBackfillAbilities`)
 * proves byte-identity per row before converting anything, so any wrapper
 * whose inner URL carries nested percent-encoding and would not survive the
 * round-trip exactly is left wrapper-stored rather than silently rewritten.
 *
 * @since 0.62.1
 *
 * @param string $canonical_url Canonical vendor ticket URL.
 * @return string The assembled wrapper, or the input unchanged when wrapping
 *                does not apply.
 */
function data_machine_events_assemble_affiliate_wrapper( string $canonical_url ): string {
	if ( '' === $canonical_url ) {
		return $canonical_url;
	}

	$config = data_machine_events_ticket_wrapper_config();

	if ( empty( $config['enabled'] ) ) {
		return $canonical_url;
	}

	$template = (string) ( $config['template'] ?? '' );
	if ( '' === $template || ! str_contains( $template, '{encoded_destination}' ) ) {
		return $canonical_url;
	}

	if ( ! data_machine_events_url_host_matches( $canonical_url, (array) ( $config['apply_to_hosts'] ?? array() ) ) ) {
		return $canonical_url;
	}

	return str_replace(
		array(
			'{encoded_destination}',
			'{affiliate_id}',
			'{campaign_id}',
			'{ad_id}',
		),
		array(
			rawurlencode( $canonical_url ),
			(string) ( $config['affiliate_id'] ?? '' ),
			(string) ( $config['campaign_id'] ?? '' ),
			(string) ( $config['ad_id'] ?? '' ),
		),
		$template
	);
}

/**
 * Whether a ticket URL must render through the first-party redirect gate.
 *
 * True when the URL is already an affiliate wrapper (legacy stored shape —
 * its affiliate ID must never reach page source) AND when it is a canonical
 * URL a monetized vendor would have wrapped (post-#818 stored shape — the
 * wrapper now materializes only at resolve time, so the redirect gate is
 * also what preserves monetization through the 302 endpoint). False for
 * direct URLs to vendors we do not wrap, which keep rendering plain `href`s.
 *
 * This is the predicate the #816 compliance surfaces gate on — Event
 * Details block render, calendar display vars, and the calendar REST
 * payload — replacing direct `data_machine_events_is_affiliate_ticket_url()`
 * checks there. Without it, every row the backfill migrates to canonical
 * storage would silently demote from a monetized, gated button to an
 * unmonetized direct link.
 *
 * @since 0.62.1
 *
 * @param string $url Ticket URL as stored (either shape).
 * @return bool True when the URL routes through the first-party redirect.
 */
function data_machine_events_is_gated_ticket_url( string $url ): bool {
	if ( '' === $url ) {
		return false;
	}

	if ( data_machine_events_is_affiliate_ticket_url( $url ) ) {
		return true;
	}

	return data_machine_events_assemble_affiliate_wrapper( $url ) !== $url;
}

/**
 * Whether a URL's host matches any of the given bare registrable domains.
 *
 * Exact-or-subdomain, case-insensitive — the same match semantics as
 * `data_machine_events_is_affiliate_ticket_url()`. `notevyy.net` does not
 * match `evyy.net`: the suffix comparison requires a leading dot.
 *
 * @since 0.62.1
 *
 * @param string   $url   URL whose host is tested.
 * @param string[] $hosts Bare registrable domains.
 * @return bool True when the URL's host matches (or is a subdomain of) one
 *              of the hosts.
 */
function data_machine_events_url_host_matches( string $url, array $hosts ): bool {
	if ( '' === $url || array() === $hosts ) {
		return false;
	}

	$parsed = wp_parse_url( $url );
	if ( ! $parsed || empty( $parsed['host'] ) ) {
		return false;
	}

	$host = strtolower( $parsed['host'] );

	foreach ( $hosts as $candidate ) {
		$candidate = strtolower( (string) $candidate );
		if ( '' === $candidate ) {
			continue;
		}
		if ( $host === $candidate || str_ends_with( $host, '.' . $candidate ) ) {
			return true;
		}
	}

	return false;
}
