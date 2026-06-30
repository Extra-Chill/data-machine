<?php
/**
 * Web Fetch Guard Tests
 *
 * Covers the short-circuit that prevents the AI web_fetch tool from
 * burning billed model turns fetching bot-blocked ticketing domains that
 * always return HTTP 403.
 *
 * The guard hooks WordPress core's `pre_http_request` filter and refuses the
 * request only when BOTH the host is on the ticketing blocklist AND the
 * request carries the web_fetch tool's browser-mode fingerprint
 * (Sec-Fetch-Mode: navigate). Structured API handlers (Ticketmaster
 * Discovery, etc.) use a different header profile and must pass through.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.41.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;

use function DataMachineEvents\Core\block_ticketing_web_fetch;
use function DataMachineEvents\Core\host_matches_blocklist;
use function DataMachineEvents\Core\is_web_fetch_request;
use function DataMachineEvents\Core\blocked_web_fetch_hosts;
use function DataMachineEvents\Core\is_bot_blocked_host;
use function DataMachineEvents\Core\bot_blocked_host_message;

class WebFetchGuardTest extends WP_UnitTestCase {

	/**
	 * Browser-mode header set matching what HttpClient sends for web_fetch.
	 *
	 * @return array<string,string>
	 */
	private function browserHeaders(): array {
		return array(
			'User-Agent'      => 'Mozilla/5.0',
			'Sec-Fetch-Mode'  => 'navigate',
			'Sec-Fetch-Dest'  => 'document',
		);
	}

	/**
	 * Args for a web_fetch-style GET request.
	 */
	private function webFetchArgs(): array {
		return array(
			'method'  => 'GET',
			'headers' => $this->browserHeaders(),
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// host_matches_blocklist
	// ────────────────────────────────────────────────────────────────────

	public function test_exact_host_matches(): void {
		$this->assertTrue( host_matches_blocklist( 'ticketmaster.com', array( 'ticketmaster.com' ) ) );
	}

	public function test_subdomain_matches(): void {
		$this->assertTrue( host_matches_blocklist( 'www.ticketmaster.com', array( 'ticketmaster.com' ) ) );
		$this->assertTrue( host_matches_blocklist( 'ticketmaster.evyy.net', array( 'ticketmaster.evyy.net' ) ) );
	}

	public function test_lookalike_host_does_not_match(): void {
		$this->assertFalse( host_matches_blocklist( 'notticketmaster.com', array( 'ticketmaster.com' ) ) );
		$this->assertFalse( host_matches_blocklist( 'example.com', array( 'ticketmaster.com' ) ) );
	}

	// ────────────────────────────────────────────────────────────────────
	// is_web_fetch_request
	// ────────────────────────────────────────────────────────────────────

	public function test_navigate_get_is_web_fetch(): void {
		$this->assertTrue( is_web_fetch_request( $this->browserHeaders(), $this->webFetchArgs() ) );
	}

	public function test_non_get_is_not_web_fetch(): void {
		$args = $this->webFetchArgs();
		$args['method'] = 'POST';
		$this->assertFalse( is_web_fetch_request( $this->browserHeaders(), $args ) );
	}

	public function test_plain_api_request_is_not_web_fetch(): void {
		// Structured API handler profile: JSON accept, no Sec-Fetch headers.
		$headers = array(
			'User-Agent' => 'DataMachine/1.0',
			'Accept'     => 'application/json',
		);
		$args = array( 'method' => 'GET', 'headers' => $headers );
		$this->assertFalse( is_web_fetch_request( $headers, $args ) );
	}

	// ────────────────────────────────────────────────────────────────────
	// block_ticketing_web_fetch (the pre_http_request filter callback)
	// ────────────────────────────────────────────────────────────────────

	public function test_blocks_ticketmaster_web_fetch(): void {
		$result = block_ticketing_web_fetch(
			false,
			$this->webFetchArgs(),
			'https://www.ticketmaster.com/event/2399925'
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'web_fetch_blocked_ticketing_domain', $result->get_error_code() );
	}

	public function test_blocks_ticketweb_web_fetch(): void {
		$result = block_ticketing_web_fetch(
			false,
			$this->webFetchArgs(),
			'https://www.ticketweb.com/event/some-show/123'
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_allows_non_ticketing_web_fetch(): void {
		$result = block_ticketing_web_fetch(
			false,
			$this->webFetchArgs(),
			'https://www.someindievenue.com/shows'
		);
		$this->assertFalse( $result );
	}

	public function test_allows_ticketmaster_api_request_without_browser_fingerprint(): void {
		// The Ticketmaster Discovery API call uses app.ticketmaster.com with a
		// JSON Accept header and NO Sec-Fetch headers — must not be blocked.
		$args = array(
			'method'  => 'GET',
			'headers' => array(
				'User-Agent' => 'DataMachine/1.0',
				'Accept'     => 'application/json',
			),
		);
		$result = block_ticketing_web_fetch(
			false,
			$args,
			'https://app.ticketmaster.com/discovery/v2/events.json?apikey=x'
		);
		$this->assertFalse( $result );
	}

	public function test_respects_earlier_short_circuit(): void {
		$earlier = array( 'response' => array( 'code' => 200 ) );
		$result  = block_ticketing_web_fetch(
			$earlier,
			$this->webFetchArgs(),
			'https://www.ticketmaster.com/event/1'
		);
		$this->assertSame( $earlier, $result );
	}

	// ────────────────────────────────────────────────────────────────────
	// blocked_web_fetch_hosts filter
	// ────────────────────────────────────────────────────────────────────

	public function test_blocklist_is_filterable(): void {
		$added = function ( $hosts ) {
			$hosts[] = 'customblocked.example';
			return $hosts;
		};
		add_filter( 'data_machine_events_web_fetch_blocked_hosts', $added );

		$result = block_ticketing_web_fetch(
			false,
			$this->webFetchArgs(),
			'https://customblocked.example/page'
		);

		remove_filter( 'data_machine_events_web_fetch_blocked_hosts', $added );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_default_blocklist_contains_ticketmaster_family(): void {
		$hosts = blocked_web_fetch_hosts();
		$this->assertContains( 'ticketmaster.com', $hosts );
		$this->assertContains( 'ticketweb.com', $hosts );
		$this->assertContains( 'ticketmaster.evyy.net', $hosts );
	}

	// ────────────────────────────────────────────────────────────────────
	// is_bot_blocked_host / bot_blocked_host_message (reusable helpers)
	// ────────────────────────────────────────────────────────────────────

	public function test_is_bot_blocked_host_matches_full_url(): void {
		$this->assertTrue( is_bot_blocked_host( 'https://www.bandsintown.com/a/12345-some-band' ) );
		$this->assertTrue( is_bot_blocked_host( 'https://www.ticketmaster.com/event/1' ) );
	}

	public function test_is_bot_blocked_host_accepts_bare_host(): void {
		$this->assertTrue( is_bot_blocked_host( 'bandsintown.com' ) );
		$this->assertTrue( is_bot_blocked_host( 'www.ticketweb.com' ) );
	}

	public function test_is_bot_blocked_host_allows_unlisted_domains(): void {
		$this->assertFalse( is_bot_blocked_host( 'https://www.someindievenue.com/shows' ) );
		$this->assertFalse( is_bot_blocked_host( 'https://easyhoneymusic.com/tour/' ) );
		$this->assertFalse( is_bot_blocked_host( '' ) );
	}

	public function test_is_bot_blocked_host_respects_blocklist_filter(): void {
		$added = static function ( $hosts ) {
			$hosts[] = 'customblocked.example';
			return $hosts;
		};
		add_filter( 'data_machine_events_web_fetch_blocked_hosts', $added );

		$result = is_bot_blocked_host( 'https://customblocked.example/page' );

		remove_filter( 'data_machine_events_web_fetch_blocked_hosts', $added );

		$this->assertTrue( $result );
	}

	public function test_bot_blocked_host_message_is_generic_and_names_host(): void {
		// Use a neutral host so the assertion targets the message TEMPLATE
		// wording, not the interpolated host (e.g. "bandsintown" itself
		// contains the substring "band").
		$host    = 'example-blocked.test';
		$message = bot_blocked_host_message( $host );

		// Must name the host so the caller's surface can show what was rejected.
		$this->assertStringContainsString( $host, $message );

		// The template must carry zero domain-specific (artist/music/band/tour)
		// wording — this helper lives in the generic scraping layer. Strip the
		// host before checking so a host that happens to contain such a
		// substring can't trip the assertion.
		$template = strtolower( str_replace( $host, '', $message ) );
		$this->assertStringNotContainsString( 'artist', $template );
		$this->assertStringNotContainsString( 'music', $template );
		$this->assertStringNotContainsString( 'band', $template );
		$this->assertStringNotContainsString( 'tour', $template );
	}
}
