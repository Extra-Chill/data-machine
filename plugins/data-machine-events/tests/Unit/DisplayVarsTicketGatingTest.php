<?php
/**
 * DisplayVars ticket-gating tests (issue #816).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Blocks\Calendar\Display\DisplayVars;
use WP_UnitTestCase;

class DisplayVarsTicketGatingTest extends WP_UnitTestCase {

	public function test_direct_ticket_url_passes_through_and_is_not_flagged_affiliate(): void {
		$vars = DisplayVars::build( array( 'ticketUrl' => 'https://www.ticketmaster.com/event/123' ) );

		$this->assertSame( 'https://www.ticketmaster.com/event/123', $vars['ticket_url'] );
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
}
