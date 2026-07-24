<?php
/**
 * Block Registration Tests
 *
 * Tests that blocks are properly registered using WordPress best practices.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.9.2
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;

class BlockRegistrationTest extends WP_UnitTestCase {

	public function test_blocks_registered_on_init_hook() {
		$this->assertTrue( class_exists( 'DATAMACHINE_Events' ), 'DATAMACHINE_Events class should exist' );

		$plugin = DATAMACHINE_Events::get_instance();

		$has_init_hook = false;

		foreach ( $GLOBALS['wp_filter'][ 'init' ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) ) {
					if ( $callback['function'][0] === $plugin && 'register_blocks' === $callback['function'][1] ) {
						$has_init_hook = true;
						break 2;
					}
				}
			}
		}

		$this->assertTrue( $has_init_hook, 'register_blocks should be registered as an init hook callback, not called directly' );
	}

	public function test_blocks_registered_after_user_initialization() {
		$original_user_id = get_current_user_id();
		$user_id          = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		try {
			$this->assertTrue( current_user_can( 'read' ), 'Current user should be initialized' );

			$block_registry      = \WP_Block_Type_Registry::get_instance();
			$calendar_block      = $block_registry->get_registered( 'data-machine-events/calendar' );
			$event_details_block = $block_registry->get_registered( 'data-machine-events/event-details' );

			$this->assertNotNull( $calendar_block, 'Calendar block should be registered' );
			$this->assertNotNull( $event_details_block, 'Event Details block should be registered' );
		} finally {
			wp_set_current_user( $original_user_id );
		}
	}

	public function test_calendar_block_has_required_metadata() {
		$block_registry = \WP_Block_Type_Registry::get_instance();
		$block          = $block_registry->get_registered( 'data-machine-events/calendar' );

		$this->assertNotNull( $block, 'Calendar block should be registered' );
		$this->assertSame( 'widgets', $block->category, 'Block should be in widgets category' );
	}

	public function test_event_details_block_has_required_metadata() {
		$block_registry = \WP_Block_Type_Registry::get_instance();
		$block          = $block_registry->get_registered( 'data-machine-events/event-details' );

		$this->assertNotNull( $block, 'Event Details block should be registered' );
		$this->assertSame( 'data-machine-events', $block->category, 'Block should use the plugin category' );
	}
}
