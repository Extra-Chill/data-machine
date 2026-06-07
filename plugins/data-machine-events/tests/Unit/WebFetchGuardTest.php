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
}
