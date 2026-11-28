<?php
/**
 * Affiliate ticket-link detection and unwrapping tests.
 *
 * Covers the single source of truth introduced in issue #816
 * (`data_machine_events_affiliate_ticket_hosts()` /
 * `data_machine_events_is_affiliate_ticket_url()`) and confirms
 * `datamachine_unwrap_affiliate_url()` — refactored to consume that helper
 * instead of carrying a private copy of the host list — is unchanged in
 * behavior for all nine seeded hosts.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;

use function DataMachineEvents\Core\data_machine_events_affiliate_ticket_hosts;
use function DataMachineEvents\Core\data_machine_events_is_affiliate_ticket_url;
use function DataMachineEvents\Core\datamachine_unwrap_affiliate_url;

class AffiliateTicketLinkTest extends WP_UnitTestCase {

	public function tearDown(): void {
		remove_all_filters( 'data_machine_events_affiliate_ticket_hosts' );
		parent::tearDown();
	}

	public function test_default_host_list_matches_the_nine_seeded_hosts(): void {
		$hosts = data_machine_events_affiliate_ticket_hosts();

		$this->assertSame(
			array(
				'evyy.net',
				'viglink.com',
				'linksynergy.com',
				'shareasale.com',
				'anrdoezrs.net',
				'jdoqocy.com',
				'dpbolvw.net',
				'kqzyfj.com',
				'tkqlhce.com',
			),
			$hosts
		);
	}

	public function test_host_list_is_filterable(): void {
		add_filter(
			'data_machine_events_affiliate_ticket_hosts',
			static function ( array $hosts ): array {
				$hosts[] = 'example-affiliate.test';
				return $hosts;
			}
		);

		$this->assertTrue( data_machine_events_is_affiliate_ticket_url( 'https://example-affiliate.test/click?u=https%3A%2F%2Fexample.com' ) );
	}

	/**
	 * @dataProvider affiliate_host_provider
	 */
	public function test_exact_host_match_is_affiliate( string $host ): void {
		$this->assertTrue( data_machine_events_is_affiliate_ticket_url( 'https://' . $host . '/c/123' ) );
	}

	/**
	 * @dataProvider affiliate_host_provider
	 */
	public function test_subdomain_of_affiliate_host_is_affiliate( string $host ): void {
		$this->assertTrue( data_machine_events_is_affiliate_ticket_url( 'https://ticketmaster.' . $host . '/c/123' ) );
	}

	public static function affiliate_host_provider(): array {
		return array(
			array( 'evyy.net' ),
			array( 'viglink.com' ),
			array( 'linksynergy.com' ),
			array( 'shareasale.com' ),
			array( 'anrdoezrs.net' ),
			array( 'jdoqocy.com' ),
			array( 'dpbolvw.net' ),
			array( 'kqzyfj.com' ),
			array( 'tkqlhce.com' ),
		);
	}

	public function test_case_insensitive_host_match(): void {
		$this->assertTrue( data_machine_events_is_affiliate_ticket_url( 'https://TICKETMASTER.EVYY.NET/c/123' ) );
	}

	public function test_lookalike_host_does_not_match_as_a_suffix(): void {
		// "notevyy.net" ends with "evyy.net" as a raw substring but is not a
		// subdomain of it — the suffix check requires a leading dot.
		$this->assertFalse( data_machine_events_is_affiliate_ticket_url( 'https://notevyy.net/c/123' ) );
	}

	public function test_direct_ticket_host_is_not_affiliate(): void {
		$this->assertFalse( data_machine_events_is_affiliate_ticket_url( 'https://www.ticketmaster.com/event/12345' ) );
	}

	public function test_empty_url_is_not_affiliate(): void {
		$this->assertFalse( data_machine_events_is_affiliate_ticket_url( '' ) );
	}

	public function test_unparseable_url_is_not_affiliate(): void {
		$this->assertFalse( data_machine_events_is_affiliate_ticket_url( 'not a url at all' ) );
	}

	/**
	 * Confirms the refactor preserves unwrap behavior for every one of the
	 * nine hosts `datamachine_unwrap_affiliate_url()` used to hardcode
	 * itself, using the ?u= redirect param shape.
	 *
	 * @dataProvider affiliate_host_provider
	 */
	public function test_unwrap_extracts_inner_url_for_every_seeded_host( string $host ): void {
		$inner   = 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-';
		$wrapped = 'https://ticketmaster.' . $host . '/c/1191134/264167/4272?u=' . rawurlencode( $inner ) . '&utm_medium=affiliate';

		$this->assertSame( $inner, datamachine_unwrap_affiliate_url( $wrapped ) );
	}

	public function test_unwrap_tries_alternate_redirect_param_names(): void {
		$inner = 'https://example.com/event/123';

		foreach ( array( 'u', 'url', 'murl', 'destination' ) as $param ) {
			$wrapped = 'https://evyy.net/c/1?' . $param . '=' . rawurlencode( $inner );
			$this->assertSame( $inner, datamachine_unwrap_affiliate_url( $wrapped ), "Failed for redirect param \"{$param}\"" );
		}
	}

	public function test_unwrap_returns_original_url_for_non_affiliate_host(): void {
		$direct = 'https://www.ticketmaster.com/event/12345?u=https%3A%2F%2Fexample.com';

		$this->assertSame( $direct, datamachine_unwrap_affiliate_url( $direct ) );
	}

	public function test_unwrap_returns_original_when_affiliate_host_has_no_query(): void {
		$wrapped = 'https://evyy.net/c/1191134/264167/4272';

		$this->assertSame( $wrapped, datamachine_unwrap_affiliate_url( $wrapped ) );
	}

	public function test_unwrap_returns_original_when_redirect_param_is_not_a_valid_url(): void {
		// Regression guard for the v0.8.39-era mangled `u=` bug (punctuation
		// stripped, e.g. "u=httpswww.ticketmaster.com..."): an unparseable
		// inner value must fall back to the original wrapped URL rather than
		// returning garbage.
		$wrapped = 'https://evyy.net/c/1?u=httpswww.ticketmaster.comevent12345';

		$this->assertSame( $wrapped, datamachine_unwrap_affiliate_url( $wrapped ) );
	}
}
