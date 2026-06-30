<?php
/**
 * Short-circuit AI web_fetch requests to bot-blocked ticketing domains.
 *
 * The Data Machine `web_fetch` AI tool lets event pipelines fetch arbitrary
 * URLs to enrich event data. In practice the AI repeatedly points it at
 * ticketing platforms (Ticketmaster, TicketWeb, AXS, SeatGeek, etc.) that
 * structurally block server-side bots and return HTTP 403 — regardless of
 * User-Agent. Each blocked fetch still happens *inside* a billed AI model
 * turn, so the failure is pure wasted OpenAI spend, and the job frequently
 * ends in `failed - tool_result_failed`.
 *
 * On events.extrachill.com this accounted for ~195 HTTP 403 Web Fetch Tool
 * failures over ~2.5 days, ~77% of them on the Ticketmaster domain family.
 *
 * Ticketmaster data is *already* imported through the structured Ticketmaster
 * Discovery API handler (inc/Steps/EventImport/Handlers/Ticketmaster/), so the
 * AI never needs to scrape ticketmaster.com at all.
 *
 * This guard hooks WordPress core's `pre_http_request` filter and refuses the
 * request before it leaves the server when the target host is a known
 * bot-blocked ticketing domain AND the request originates from the AI
 * web_fetch tool (identified by the browser-mode header fingerprint the tool
 * sends). It returns an instructive WP_Error so the AI is steered away from
 * re-fetching the same blocked source instead of burning more turns.
 *
 * @package DataMachineEvents
 * @subpackage Core
 * @since 0.41.0
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the web_fetch guard.
 */
function register_web_fetch_guard(): void {
	add_filter( 'pre_http_request', __NAMESPACE__ . '\\block_ticketing_web_fetch', 10, 3 );
}

/**
 * Default list of host suffixes the AI web_fetch tool should never scrape.
 *
 * These are ticketing/discovery platforms that structurally block server-side
 * bots (HTTP 403) and whose data is either already imported via a structured
 * API handler (Ticketmaster Discovery API) or otherwise not usefully scrapable.
 *
 * Matched as case-insensitive host suffixes, so `ticketmaster.com` also covers
 * `www.ticketmaster.com` and `ticketmaster.ca` is matched on its own entry.
 *
 * @return string[] Lowercase host suffixes.
 */
function blocked_web_fetch_hosts(): array {
	$hosts = array(
		// Ticketmaster family (already imported via the Discovery API handler).
		'ticketmaster.com',
		'ticketmaster.ca',
		'ticketmaster.evyy.net',
		'livenation.com',
		// TicketWeb (Ticketmaster-owned, same bot block).
		'ticketweb.com',
		'ticketweb.ca',
		// Other primary-ticketing platforms that reliably 403 server-side bots.
		'axs.com',
		'seatgeek.com',
		'bandsintown.com',
		'tixr.com',
	);

	/**
	 * Filter the host suffixes blocked for the AI web_fetch tool.
	 *
	 * Other sites or future maintainers can add/remove ticketing domains
	 * without a code change in this plugin.
	 *
	 * @param string[] $hosts Lowercase host suffixes.
	 */
	$hosts = apply_filters( 'data_machine_events_web_fetch_blocked_hosts', $hosts );

	return array_values( array_filter( array_map( 'strtolower', (array) $hosts ) ) );
}

/**
 * Short-circuit a web_fetch HTTP request to a bot-blocked ticketing domain.
 *
 * Hooked on `pre_http_request`. Returning a non-false value short-circuits
 * WordPress's HTTP API and prevents the outbound request entirely.
 *
 * We only intervene when BOTH conditions hold:
 *   1. The target host matches a blocked ticketing domain.
 *   2. The request looks like the Data Machine web_fetch tool (browser-mode
 *      GET with the navigate Sec-Fetch fingerprint), so we never interfere
 *      with the plugin's own structured API calls (Ticketmaster Discovery,
 *      DICE, etc.) which use a different header profile.
 *
 * @param false|array|\WP_Error $preempt Short-circuit return value. Default false.
 * @param array                 $args    HTTP request arguments.
 * @param string                $url     The request URL.
 * @return false|\WP_Error False to allow the request, WP_Error to block it.
 */
function block_ticketing_web_fetch( $preempt, $args, $url ) {
	// Respect any earlier short-circuit.
	if ( false !== $preempt ) {
		return $preempt;
	}

	if ( ! is_string( $url ) || '' === $url ) {
		return $preempt;
	}

	// Only target the AI web_fetch tool's request fingerprint. The tool calls
	// HttpClient::get() with browser_mode=true, which sends a navigate
	// Sec-Fetch-Mode header. Structured API handlers in this plugin do not.
	$headers = is_array( $args['headers'] ?? null ) ? $args['headers'] : array();
	if ( ! is_web_fetch_request( $headers, $args ) ) {
		return $preempt;
	}

	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( '' === $host ) {
		return $preempt;
	}

	if ( ! host_matches_blocklist( $host, blocked_web_fetch_hosts() ) ) {
		return $preempt;
	}

	if ( function_exists( 'do_action' ) ) {
		do_action(
			'datamachine_log',
			'info',
			'Web Fetch Tool: blocked bot-protected ticketing domain before dispatch',
			array(
				'context' => 'Web Fetch Guard',
				'url'     => $url,
				'host'    => $host,
			)
		);
	}

	return new \WP_Error(
		'web_fetch_blocked_ticketing_domain',
		sprintf(
			/* translators: %s: target host name. */
			__( 'Fetching "%s" is blocked: this ticketing platform structurally blocks automated requests (HTTP 403) and its event data is already imported through a structured API handler. Do not web_fetch ticketing pages — use the imported event data instead.', 'data-machine-events' ),
			$host
		),
		array( 'host' => $host )
	);
}

/**
 * Determine whether an HTTP request originates from the AI web_fetch tool.
 *
 * The web_fetch tool uses HttpClient browser_mode, which sets a distinctive
 * set of browser navigation headers. We require the Sec-Fetch-Mode: navigate
 * header (a fingerprint of browser_mode) on a GET request. This deliberately
 * avoids blocking the plugin's own structured API calls.
 *
 * @param array $headers Request headers (header name => value).
 * @param array $args    Full request args.
 * @return bool True when the request matches the web_fetch tool fingerprint.
 */
function is_web_fetch_request( array $headers, array $args ): bool {
	$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
	if ( 'GET' !== $method ) {
		return false;
	}

	foreach ( $headers as $name => $value ) {
		if ( 0 === strcasecmp( (string) $name, 'Sec-Fetch-Mode' ) && 'navigate' === strtolower( (string) $value ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Check whether a host matches any suffix in the blocklist.
 *
 * Matches exact host or any subdomain of a blocked suffix, e.g. `ticketmaster.com`
 * matches `www.ticketmaster.com` and `ticketmaster.com`, but not `notticketmaster.com`.
 *
 * @param string   $host      Lowercase host to test.
 * @param string[] $blocklist Lowercase host suffixes.
 * @return bool True when the host is blocked.
 */
function host_matches_blocklist( string $host, array $blocklist ): bool {
	foreach ( $blocklist as $blocked ) {
		if ( '' === $blocked ) {
			continue;
		}
		if ( $host === $blocked || str_ends_with( $host, '.' . $blocked ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether a URL points at a host that structurally blocks automated imports.
 *
 * Public, reusable predicate over the SAME blocklist the web_fetch guard
 * enforces (the single source of truth, filterable via
 * `data_machine_events_web_fetch_blocked_hosts`). Any caller that fetches or
 * scrapes a user-supplied URL can use this to reject a known bot-blocked
 * ticketing/aggregator domain up front — before spending a network round-trip
 * that will only ever return HTTP 403 — and surface a clear message instead of
 * a generic "couldn't read that page" failure.
 *
 * This helper is deliberately domain-agnostic: it knows nothing about what the
 * caller intends to extract, only that the host is on the bot-blocked list.
 *
 * @param string $url Absolute http/https URL (or bare host).
 * @return bool True when the URL's host is a known bot-blocked domain.
 */
function is_bot_blocked_host( string $url ): bool {
	if ( '' === $url ) {
		return false;
	}

	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( '' === $host ) {
		// Allow callers to pass a bare host instead of a full URL.
		$host = strtolower( trim( $url ) );
	}

	if ( '' === $host ) {
		return false;
	}

	return host_matches_blocklist( $host, blocked_web_fetch_hosts() );
}

/**
 * Generic, domain-agnostic message explaining why a bot-blocked host can't be
 * imported automatically, and what to do instead.
 *
 * Intentionally carries no assumption about what kind of content the caller is
 * importing — it only states that the source blocks automated requests and that
 * the user should provide the original source page instead. Callers can wrap or
 * localize this for their own surface.
 *
 * @param string $host The bot-blocked host (e.g. "www.bandsintown.com").
 * @return string Human-readable, generic guidance message.
 */
function bot_blocked_host_message( string $host ): string {
	return sprintf(
		/* translators: %s: the host name that was submitted, e.g. www.bandsintown.com */
		__( '%s blocks automated imports, so its listings can\'t be read automatically. Paste the original source page instead and we\'ll try again.', 'data-machine-events' ),
		$host
	);
}
