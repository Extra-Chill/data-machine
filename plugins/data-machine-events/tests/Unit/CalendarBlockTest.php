<?php
/**
 * Calendar Block Tests
 *
 * Tests for Calendar block rendering and attributes.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.9.16
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Blocks\Calendar\Calendar;
use DataMachineEvents\Blocks\Calendar\Pagination;

class CalendarBlockTest extends WP_UnitTestCase {

	public function test_calendar_block_registered() {
		$block_registry = \WP_Block_Type_Registry::get_instance();
		$block = $block_registry->get_registered( 'datamachine-events/calendar' );

		$this->assertNotNull( $block, 'Calendar block should be registered' );
	}

	public function test_calendar_block_has_render_callback() {
		$block_registry = \WP_Block_Type_Registry::get_instance();
		$block = $block_registry->get_registered( 'datamachine-events/calendar' );

		$this->assertNotNull( $block );
		$this->assertNotNull( $block->render_callback, 'Block should have render callback' );
	}

	public function test_pagination_builds_args_correctly() {
		$pagination = new Pagination();

		$args = $pagination->build_pagination_args( 1, 3 );

		$this->assertIsArray( $args );
		$this->assertEquals( 1, $args['current'] );
		$this->assertEquals( 3, $args['total'] );
	}

	public function test_pagination_sanitizes_query_params() {
		$pagination = new Pagination();

		$params = array(
			'page'    => '2',
			'search'  => '<script>alert("xss")</script>Test',
			'nested'  => array(
				'value' => 'test<tag>',
			),
		);

		$sanitized = $pagination->sanitize_query_params( $params );

		$this->assertIsArray( $sanitized );
		$this->assertStringNotContainsString( '<script>', $sanitized['search'] ?? '' );
	}

	public function test_calendar_query_args_filter_applied() {
		// Test that the filter can be applied
		$modified = false;

		add_filter(
			'datamachine_events_calendar_query_args',
			function ( $args ) use ( &$modified ) {
				$modified = true;
				return $args;
			}
		);

		$args = apply_filters( 'datamachine_events_calendar_query_args', array( 'post_type' => 'datamachine_events' ) );

		$this->assertTrue( $modified );
		$this->assertEquals( 'datamachine_events', $args['post_type'] );
	}

	public function test_calendar_renders_no_events_state() {
		// Create a mock render with no events
		$block_registry = \WP_Block_Type_Registry::get_instance();
		$block = $block_registry->get_registered( 'datamachine-events/calendar' );

		if ( $block && $block->render_callback ) {
			$output = call_user_func( $block->render_callback, array(), '', $block );

			// The output should be a string (HTML)
			$this->assertIsString( $output );
		} else {
			$this->markTestSkipped( 'Block not registered or no render callback' );
		}
	}

	public function test_event_item_template_exists() {
		$template_path = DATAMACHINE_EVENTS_PATH . 'inc/Blocks/Calendar/templates/event-item.php';

		$this->assertFileExists( $template_path, 'Event item template should exist' );
	}

	public function test_date_group_template_exists() {
		$template_path = DATAMACHINE_EVENTS_PATH . 'inc/Blocks/Calendar/templates/date-group.php';

		$this->assertFileExists( $template_path, 'Date group template should exist' );
	}

	public function test_pagination_template_exists() {
		$template_path = DATAMACHINE_EVENTS_PATH . 'inc/Blocks/Calendar/templates/pagination.php';

		$this->assertFileExists( $template_path, 'Pagination template should exist' );
	}

	public function test_no_events_template_exists() {
		$template_path = DATAMACHINE_EVENTS_PATH . 'inc/Blocks/Calendar/templates/no-events.php';

		$this->assertFileExists( $template_path, 'No events template should exist' );
	}
}
