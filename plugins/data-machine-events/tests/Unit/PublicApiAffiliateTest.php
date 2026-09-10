<?php
/**
 * Public API affiliate-helper export tests (issue #816).
 *
 * `inc/public-api.php` exposes two GLOBAL-namespace wrapper functions so
 * downstream plugins (e.g. extrachill-seo#57) can consume the affiliate
 * detection/unwrap contract via `function_exists()` guards without
 * importing internal `DataMachineEvents\Core\…` namespaced functions,
 * which are not part of the stable public surface. These two functions
 * are always meant to be used together (check, then unwrap) — this test
 * covers both so neither one silently regresses to "not exported" while
 * the other stays exported, which is exactly the bug this test guards
 * against (see PR #820 review).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;

class PublicApiAffiliateTest extends WP_UnitTestCase {

	public function test_predicate_is_globally_exported(): void {
		$this->assertTrue(
			function_exists( 'data_machine_events_is_affiliate_ticket_url' ),
			'Downstream plugins gate on function_exists( "data_machine_events_is_affiliate_ticket_url" ) — it must be exported to the global namespace.'
		);
	}

	public function test_unwrap_is_globally_exported(): void {
		$this->assertTrue(
			function_exists( 'datamachine_unwrap_affiliate_url' ),
			'Downstream plugins gate on function_exists( "datamachine_unwrap_affiliate_url" ) — it must be exported to the global namespace alongside the predicate.'
		);
	}

	public function test_global_predicate_delegates_to_internal_implementation(): void {
		$affiliate = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( 'https://www.ticketmaster.com/event/123' );
		$direct    = 'https://www.ticketmaster.com/event/123';

		$this->assertTrue( \data_machine_events_is_affiliate_ticket_url( $affiliate ) );
		$this->assertFalse( \data_machine_events_is_affiliate_ticket_url( $direct ) );

		// Delegation, not reimplementation: both surfaces must agree.
		$this->assertSame(
			\DataMachineEvents\Core\data_machine_events_is_affiliate_ticket_url( $affiliate ),
			\data_machine_events_is_affiliate_ticket_url( $affiliate )
		);
	}

	public function test_global_unwrap_delegates_to_internal_implementation(): void {
		$inner   = 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-';
		$wrapped = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( $inner ) . '&utm_medium=affiliate';

		$this->assertSame( $inner, \datamachine_unwrap_affiliate_url( $wrapped ) );

		// Delegation, not reimplementation: both surfaces must agree.
		$this->assertSame(
			\DataMachineEvents\Core\datamachine_unwrap_affiliate_url( $wrapped ),
			\datamachine_unwrap_affiliate_url( $wrapped )
		);
	}

	public function test_global_unwrap_returns_input_unchanged_when_not_affiliate(): void {
		$direct = 'https://www.ticketmaster.com/event/123';

		// Callers treat an unchanged return as "could not unwrap" — must
		// never come back empty for a non-empty, non-affiliate input.
		$this->assertSame( $direct, \datamachine_unwrap_affiliate_url( $direct ) );
	}
}
