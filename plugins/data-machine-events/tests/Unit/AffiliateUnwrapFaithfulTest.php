<?php
/**
 * Faithful (byte-preserving) affiliate unwrap tests (issue #824).
 *
 * Covers `datamachine_unwrap_affiliate_url_faithful()` — the redirect/
 * display-purpose sibling of `datamachine_unwrap_affiliate_url()` — and the
 * `$single_decode` flag on the shared `datamachine_find_affiliate_redirect_param()`
 * scanner both variants delegate to.
 *
 * The core property under test: `rawurlencode()`-ing the faithful variant's
 * return value must reproduce the originally stored redirect-parameter bytes
 * exactly, even when the inner URL carries its own nested percent-encoding
 * (the Impact Radius-wrapped SeatGeek shape from the issue, a real
 * production example). The comparison variant does NOT have this property
 * — it is documented and tested elsewhere (`AffiliateWrapperAssemblyTest`)
 * to intentionally over-decode for dedup purposes — and must stay byte-for-
 * byte unchanged; this file pins that its output is untouched by the new
 * sibling's existence.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.65.1
 * @see     https://github.com/Extra-Chill/data-machine-events/issues/824
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;

use function DataMachineEvents\Core\datamachine_find_affiliate_redirect_param;
use function DataMachineEvents\Core\datamachine_unwrap_affiliate_url;
use function DataMachineEvents\Core\datamachine_unwrap_affiliate_url_faithful;

class AffiliateUnwrapFaithfulTest extends WP_UnitTestCase {

	/**
	 * Real production wrapper (issue #824): an Impact Radius wrapper whose
	 * `u=` inner destination is a SeatGeek URL that itself carries a
	 * `dd_referrer=` query param, nested-percent-encoded one level deeper
	 * than the outer wrapper.
	 */
	private const SEATGEEK_NESTED_STORED = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fseatgeek.com%2Fchris-brown-tickets%2Fsunrise-florida-amerant-bank-arena-2026-04-04-7-pm%2Fconcert%2F18082005%3Fdd_referrer%3Dhttps%253A%252F%252Famplify.seatgeek.com%252F&utm_medium=affiliate';

	private const SEATGEEK_NESTED_INNER_RAW_ENCODED = 'https%3A%2F%2Fseatgeek.com%2Fchris-brown-tickets%2Fsunrise-florida-amerant-bank-arena-2026-04-04-7-pm%2Fconcert%2F18082005%3Fdd_referrer%3Dhttps%253A%252F%252Famplify.seatgeek.com%252F';

	/**
	 * The faithful variant decodes the redirect parameter exactly once, so
	 * `rawurlencode()`-ing its return value reproduces the stored `u=`
	 * bytes exactly — including the nested `dd_referrer=` encoding, which
	 * the comparison variant loses (issue #824's core finding).
	 */
	public function test_faithful_round_trips_nested_encoded_inner_url(): void {
		$canonical = datamachine_unwrap_affiliate_url_faithful( self::SEATGEEK_NESTED_STORED );

		$this->assertNotSame(
			self::SEATGEEK_NESTED_STORED,
			$canonical,
			'Faithful unwrap must still unwrap the wrapper, not pass it through unchanged.'
		);
		$this->assertSame(
			self::SEATGEEK_NESTED_INNER_RAW_ENCODED,
			rawurlencode( $canonical ),
			'rawurlencode() of the faithfully-unwrapped inner URL must reproduce the originally stored u= bytes exactly, including the nested dd_referrer= encoding.'
		);
	}

	/**
	 * The comparison variant is UNCHANGED by the faithful sibling's
	 * introduction: it still over-decodes and therefore does NOT round-trip
	 * for this same nested-encoding wrapper. Pins the contrast directly
	 * against the faithful variant above so the two can never silently
	 * converge.
	 */
	public function test_comparison_variant_still_over_decodes_the_same_wrapper(): void {
		$comparison = datamachine_unwrap_affiliate_url( self::SEATGEEK_NESTED_STORED );
		$faithful   = datamachine_unwrap_affiliate_url_faithful( self::SEATGEEK_NESTED_STORED );

		$this->assertNotSame(
			$faithful,
			$comparison,
			'The comparison and faithful variants must diverge for a nested-encoded inner URL — that divergence is the entire point of issue #824.'
		);
		$this->assertNotSame(
			self::SEATGEEK_NESTED_INNER_RAW_ENCODED,
			rawurlencode( $comparison ),
			'The comparison variant must NOT round-trip for nested encoding — over-decoding for dedup purposes is intentional and must stay unchanged.'
		);
	}

	/**
	 * For the dominant, non-nested-encoding wrapper shape, the faithful and
	 * comparison variants agree exactly — decode depth only matters when
	 * there IS nested encoding to lose.
	 */
	public function test_faithful_matches_comparison_when_no_nested_encoding(): void {
		$inner   = 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-';
		$wrapped = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( $inner ) . '&utm_medium=affiliate';

		$this->assertSame( $inner, datamachine_unwrap_affiliate_url_faithful( $wrapped ) );
		$this->assertSame(
			datamachine_unwrap_affiliate_url( $wrapped ),
			datamachine_unwrap_affiliate_url_faithful( $wrapped )
		);
	}

	public function test_faithful_returns_original_for_non_affiliate_host(): void {
		$direct = 'https://www.ticketmaster.com/event/12345?u=https%3A%2F%2Fexample.com';

		$this->assertSame( $direct, datamachine_unwrap_affiliate_url_faithful( $direct ) );
	}

	public function test_faithful_returns_original_when_affiliate_host_has_no_query(): void {
		$wrapped = 'https://evyy.net/c/1191134/264167/4272';

		$this->assertSame( $wrapped, datamachine_unwrap_affiliate_url_faithful( $wrapped ) );
	}

	public function test_faithful_returns_original_when_redirect_param_is_not_a_valid_url(): void {
		$wrapped = 'https://evyy.net/c/1?u=httpswww.ticketmaster.comevent12345';

		$this->assertSame( $wrapped, datamachine_unwrap_affiliate_url_faithful( $wrapped ) );
	}

	/**
	 * The shared scanner's `$single_decode` flag directly: `true` returns
	 * `parse_str()`'s single decode pass untouched; `false` (default,
	 * unchanged) applies the additional `urldecode()` pass.
	 */
	public function test_find_affiliate_redirect_param_single_decode_flag(): void {
		$url = 'https://evyy.net/c/1?u=' . rawurlencode( 'https://example.com/a%3Fb' );

		$single = datamachine_find_affiliate_redirect_param( $url, true );
		$double = datamachine_find_affiliate_redirect_param( $url, false );
		$default_call = datamachine_find_affiliate_redirect_param( $url );

		$this->assertSame( 'https://example.com/a%3Fb', $single['value'] );
		$this->assertSame( 'https://example.com/a?b', $double['value'] );
		$this->assertSame(
			$double,
			$default_call,
			'Default (omitted) $single_decode must remain the pre-existing double-decode behavior — no default change.'
		);
	}
}
