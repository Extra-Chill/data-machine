<?php
/**
 * Calendar cards honor `data_machine_events_show_ticket_button`.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Blocks\Calendar\Display\DisplayVars;
use WP_UnitTestCase;

class DisplayVarsTicketLinkFilterTest extends WP_UnitTestCase {

	public function tearDown(): void {
		remove_all_filters( 'data_machine_events_show_ticket_button' );
		parent::tearDown();
	}

	public function test_card_hides_ticket_link_when_filter_returns_false(): void {
		$seen = null;
		add_filter(
			'data_machine_events_show_ticket_button',
			function ( $show, $post_id, $timing ) use ( &$seen ) {
				$seen = array( $show, $post_id, $timing );
				return false;
			},
			10,
			3
		);

		$vars = DisplayVars::build( array( 'ticketUrl' => 'https://link.dice.fm/abc123' ), array(), 42 );

		$this->assertSame( true, $seen[0], 'Default is the card\'s own showTicketLink setting.' );
		$this->assertSame( 42, $seen[1] );
		$this->assertIsString( $seen[2] );
		$this->assertFalse( $vars['show_ticket_link'] );
		$this->assertSame( '', $vars['ticket_url'] );
		$this->assertFalse( $vars['is_affiliate_ticket'], 'No gated ref for a hidden link.' );
	}

	public function test_hidden_affiliate_link_carries_no_gated_ref(): void {
		add_filter( 'data_machine_events_show_ticket_button', '__return_false' );

		$vars = DisplayVars::build( array( 'ticketUrl' => 'https://www.ticketmaster.com/event/123' ), array(), 42 );

		$this->assertFalse( $vars['show_ticket_link'] );
		$this->assertFalse( $vars['is_affiliate_ticket'] );
	}

	public function test_card_keeps_ticket_link_without_post_id_or_when_filter_allows(): void {
		add_filter( 'data_machine_events_show_ticket_button', '__return_false' );
		$vars = DisplayVars::build( array( 'ticketUrl' => 'https://link.dice.fm/abc123' ) );
		$this->assertTrue( $vars['show_ticket_link'], 'Without a post ID the filter cannot apply.' );

		remove_all_filters( 'data_machine_events_show_ticket_button' );
		add_filter( 'data_machine_events_show_ticket_button', '__return_true' );
		$vars = DisplayVars::build( array( 'ticketUrl' => 'https://link.dice.fm/abc123' ), array(), 42 );
		$this->assertTrue( $vars['show_ticket_link'] );
		$this->assertSame( 'https://link.dice.fm/abc123', $vars['ticket_url'] );
	}
}
