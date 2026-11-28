<?php
/**
 * DisplayVars ticket-gating tests (issues #816 and #818).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Blocks\Calendar\Display\DisplayVars;
use WP_UnitTestCase;

class DisplayVarsTicketGatingTest extends WP_UnitTestCase {

	public function tearDown(): void {
		remove_all_filters( 'data_machine_events_ticket_wrapper_config' );
		parent::tearDown();
	}

	/**
	 * Issue #818: a canonical-stored Ticketmaster URL routes through the
	 * first-party redirect (wrapper assembled at resolve time), so the gate
	 * flags it and empties ticket_url exactly as it does a stored wrapper —
	 * otherwise every backfilled row would silently demote from a monetized
	 * gated button to an unmonetized direct link.
	 */
	public function test_canonical_monetized_url_is_gated_like_a_wrapper(): void {
		$vars = DisplayVars::build( array( 'ticketUrl' => 'https://www.ticketmaster.com/event/123' ) );

		$this->assertSame( '', $vars['ticket_url'], 'Canonical monetized URL must be gated, not surfaced as a direct href.' );
		$this->assertTrue( $vars['is_affiliate_ticket'] );
	}

	/**
	 * Direct URLs to vendors we do not monetize keep rendering plain hrefs.
	 */
	public function test_direct_non_monetized_ticket_url_passes_through_and_is_not_flagged(): void {
		$vars = DisplayVars::build( array( 'ticketUrl' => 'https://link.dice.fm/abc123' ) );

		$this->assertSame( 'https://link.dice.fm/abc123', $vars['ticket_url'] );
		$this->assertFalse( $vars['is_affiliate_ticket'] );
	}

	public function test_affiliate_ticket_url_is_emptied_and_flagged(): void {
		$affiliate = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( 'https://www.ticketmaster.com/event/123' );
		$vars      = DisplayVars::build( array( 'ticketUrl' => $affiliate ) );

		$this->assertSame( '', $vars['ticket_url'], 'The affiliate URL must never surface in ticket_url.' );
		$this->assertTrue( $vars['is_affiliate_ticket'] );
	}

	public function test_missing_ticket_url_is_empty_and_not_affiliate(): void {
		$vars = DisplayVars::build( array() );

		$this->assertSame( '', $vars['ticket_url'] );
		$this->assertFalse( $vars['is_affiliate_ticket'] );
	}

	/**
	 * Disabling wrapper assembly de-gates canonical URLs — the escape hatch
	 * that makes the whole mechanism revertible via config alone.
	 */
	public function test_disabling_wrapper_config_de_gates_canonical_urls(): void {
		add_filter(
			'data_machine_events_ticket_wrapper_config',
			static function ( array $config ): array {
				$config['enabled'] = false;
				return $config;
			}
		);

		$vars = DisplayVars::build( array( 'ticketUrl' => 'https://www.ticketmaster.com/event/123' ) );

		$this->assertSame( 'https://www.ticketmaster.com/event/123', $vars['ticket_url'] );
		$this->assertFalse( $vars['is_affiliate_ticket'] );
	}
}
