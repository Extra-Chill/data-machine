<?php
/**
 * Public API affiliate-helper export tests (issue #816, #824).
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
 * The global `datamachine_unwrap_affiliate_url()` is a DISPLAY-purpose
 * export (extrachill-seo's JSON-LD `offers.url`), so since issue #824 it
 * delegates to the internal FAITHFUL unwrapper
 * (`datamachine_unwrap_affiliate_url_faithful()`), NOT the
 * comparison-oriented internal function of the same name. Do not
 * reintroduce an assertion that the two same-named functions
 * (global vs. `Core\datamachine_unwrap_affiliate_url()`) always agree —
 * they are intentionally different decode-depth variants and diverge for
 * nested-encoded inner URLs.
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

	public function test_global_unwrap_delegates_to_internal_faithful_implementation(): void {
		$inner   = 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-';
		$wrapped = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( $inner ) . '&utm_medium=affiliate';

		$this->assertSame( $inner, \datamachine_unwrap_affiliate_url( $wrapped ) );

		// Delegation, not reimplementation: the global export must agree
		// with the internal FAITHFUL variant specifically (issue #824) —
		// it is a display-purpose export (extrachill-seo's offers.url), not
		// a comparison one.
		$this->assertSame(
			\DataMachineEvents\Core\datamachine_unwrap_affiliate_url_faithful( $wrapped ),
			\datamachine_unwrap_affiliate_url( $wrapped )
		);
	}

	/**
	 * The global export must diverge from the internal COMPARISON-oriented
	 * function of the same name for a nested-encoded inner URL — if it ever
	 * agrees again, the public API silently regressed to leaking a
	 * dedup-comparison decode into a display-purpose consumer (issue #824).
	 */
	public function test_global_unwrap_diverges_from_comparison_oriented_internal_function_for_nested_encoding(): void {
		$wrapped = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fseatgeek.com%2Fconcert%2F18082005%3Fdd_referrer%3Dhttps%253A%252F%252Famplify.seatgeek.com%252F&utm_medium=affiliate';

		$global     = \datamachine_unwrap_affiliate_url( $wrapped );
		$comparison = \DataMachineEvents\Core\datamachine_unwrap_affiliate_url( $wrapped );

		$this->assertNotSame(
			$comparison,
			$global,
			'The global (display-purpose) export must not silently reconverge with the internal comparison-oriented function for a nested-encoded inner URL.'
		);
	}

	public function test_global_unwrap_returns_input_unchanged_when_not_affiliate(): void {
		$direct = 'https://www.ticketmaster.com/event/123';

		// Callers treat an unchanged return as "could not unwrap" — must
		// never come back empty for a non-empty, non-affiliate input.
		$this->assertSame( $direct, \datamachine_unwrap_affiliate_url( $direct ) );
	}
}
