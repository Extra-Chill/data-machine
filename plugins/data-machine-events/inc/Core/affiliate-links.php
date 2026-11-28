<?php
/**
 * Affiliate Ticket Link Detection
 *
 * Single source of truth for "is this ticket URL an affiliate/redirect
 * wrapper?" Every consumer that needs to gate, unwrap, or otherwise treat an
 * affiliate ticket URL differently from a direct one asks this helper —
 * nothing downstream re-implements the host list or the match logic.
 *
 * Seeded with the nine hosts `datamachine_unwrap_affiliate_url()` in
 * event-dates-sync.php already knew about (Impact Radius's evyy.net plus the
 * Commission Junction rotation domains). That function now delegates its own
 * affiliate check here instead of carrying a second, private copy of the
 * list. See https://github.com/Extra-Chill/data-machine-events/issues/816.
 *
 * @package DataMachineEvents\Core
 * @since   0.62.0
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bare registrable domains treated as affiliate/redirect ticket-link
 * wrappers (e.g. `evyy.net`, matched exact-or-subdomain).
 *
 * @since 0.62.0
 *
 * @return string[] Lower-case, bare registrable domains.
 */
function data_machine_events_affiliate_ticket_hosts(): array {
	$hosts = array(
		// Impact Radius (Ticketmaster's affiliate network).
		'evyy.net',
		// VigLink.
		'viglink.com',
		// Rakuten LinkSynergy.
		'linksynergy.com',
		'shareasale.com',
		// Commission Junction rotation domains.
		'anrdoezrs.net',
		'jdoqocy.com',
		'dpbolvw.net',
		'kqzyfj.com',
		'tkqlhce.com',
	);

	/**
	 * Filters the bare registrable domain list treated as affiliate/redirect
	 * ticket-link wrappers.
	 *
	 * The list is data, not branching logic — nothing downstream should ever
	 * special-case a specific host by name. Everything asks
	 * `data_machine_events_is_affiliate_ticket_url()` and nothing else.
	 *
	 * @since 0.62.0
	 *
	 * @param string[] $hosts Bare registrable domains, e.g. `"evyy.net"`.
	 */
	return apply_filters( 'data_machine_events_affiliate_ticket_hosts', $hosts );
}

/**
 * Whether a ticket URL's host matches a known affiliate/redirect wrapper.
 *
 * Matches the URL's host against `data_machine_events_affiliate_ticket_hosts()`
 * exact-or-subdomain, case-insensitively. `notevyy.net` does not match
 * `evyy.net` — the suffix comparison requires a leading dot.
 *
 * @since 0.62.0
 *
 * @param string $url Ticket URL to check.
 * @return bool True when the URL's host is a known affiliate/redirect wrapper.
 */
function data_machine_events_is_affiliate_ticket_url( string $url ): bool {
	if ( '' === $url ) {
		return false;
	}

	$parsed = wp_parse_url( $url );
	if ( ! $parsed || empty( $parsed['host'] ) ) {
		return false;
	}

	$host = strtolower( $parsed['host'] );

	foreach ( data_machine_events_affiliate_ticket_hosts() as $affiliate_host ) {
		$affiliate_host = strtolower( (string) $affiliate_host );
		if ( '' === $affiliate_host ) {
			continue;
		}
		if ( $host === $affiliate_host || str_ends_with( $host, '.' . $affiliate_host ) ) {
			return true;
		}
	}

	return false;
}
