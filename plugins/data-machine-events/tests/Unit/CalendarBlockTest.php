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
use DataMachineEvents\Blocks\Calendar\Pagination\Renderer as Pagination;

class CalendarBlockTest extends WP_UnitTestCase {

	private function render_calendar( array $attributes = array() ): string {
		$block = \WP_Block_Type_Registry::get_instance()->get_registered( 'data-machine-events/calendar' );

		return (string) call_user_func( $block->render_callback, $attributes, '', $block );
	}

	public function test_calendar_block_registered() {
		$block_registry = \WP_Block_Type_Registry::get_instance();
		$block          = $block_registry->get_registered( 'data-machine-events/calendar' );

		$this->assertNotNull( $block, 'Calendar block should be registered' );
	}

	public function test_calendar_block_has_render_callback() {
		$block_registry = \WP_Block_Type_Registry::get_instance();
		$block          = $block_registry->get_registered( 'data-machine-events/calendar' );

		$this->assertNotNull( $block );
		$this->assertNotNull( $block->render_callback, 'Block should have render callback' );
	}

	public function test_pagination_exposes_sanitized_public_arguments(): void {
		$original_get = $_GET;
		$_GET         = array(
			'paged'  => '2',
			'search' => '<script>alert("xss")</script>Test',
			'nested' => array( 'value' => 'test<tag>' ),
		);
		$observed_args = null;
		$filter        = static function ( array $args ) use ( &$observed_args ): array {
			$observed_args = $args;
			return $args;
		};
		add_filter( 'data_machine_events_pagination_args', $filter );

		try {
			$html = Pagination::render_pagination( 1, 3 );
		} finally {
			remove_filter( 'data_machine_events_pagination_args', $filter );
			$_GET = $original_get;
		}

		$this->assertSame( 1, $observed_args['current'] );
		$this->assertSame( 3, $observed_args['total'] );
		$this->assertArrayNotHasKey( 'paged', $observed_args['add_args'] );
		$this->assertStringNotContainsString( '<script>', $observed_args['add_args']['search'] );
		$this->assertSame( 'test', $observed_args['add_args']['nested']['value'] );
		$this->assertStringContainsString( 'data-machine-events-pagination', $html );
	}

	public function test_calendar_query_args_filter_applied() {
		// Test that the filter can be applied
		$modified = false;

		add_filter(
			'data_machine_events_calendar_query_args',
			function ( $args ) use ( &$modified ) {
				$modified = true;
				return $args;
			}
		);

		$args = apply_filters( 'data_machine_events_calendar_query_args', array( 'post_type' => 'data_machine_events' ) );

		$this->assertTrue( $modified );
		$this->assertEquals( 'data_machine_events', $args['post_type'] );
	}

	public function test_calendar_request_args_filter_injects_sanitized_defaults_and_context() {
		$original_get = $_GET;
		$_GET        = array();
		$context      = null;
		$parsed_input = null;

		add_filter(
			'data_machine_events_calendar_request_args',
			function ( $args, $render_context ) use ( &$context ) {
				$context                  = $render_context;
				$args['lat']              = '32.7765<script>';
				$args['lng']              = '-79.9311';
				$args['radius']           = '25px';
				$args['radius_unit']      = 'MI<script>';
				$args['tax_filter']       = array(
					'Festival<script>' => array( '42', '-3', 'invalid' ),
				);

				return $args;
			},
			10,
			2
		);
		add_filter(
			'data_machine_events_calendar_query_args',
			function ( $args, $input ) use ( &$parsed_input ) {
				$parsed_input = $input;
				return $args;
			},
			10,
			2
		);

		$html = $this->render_calendar( array( 'displayMode' => 'date-groups', 'showSearch' => false ) );

		$this->assertSame( array(), $_GET, 'Consumers do not need to mutate request globals.' );
		$this->assertSame( 'date-groups', $context['display_mode'] );
		$this->assertSame( false, $context['attributes']['showSearch'] );
		$this->assertArrayHasKey( 'archive_term', $context );
		$this->assertNull( $context['archive_term'] );
		$this->assertSame( array( 'festivalscript' => array( 42, 3 ) ), $parsed_input['tax_filter'] );
		$this->assertSame( '32.7765', $parsed_input['geo_lat'] );
		$this->assertSame( 25, $parsed_input['geo_radius'] );
		$this->assertSame( 'miscript', $parsed_input['geo_radius_unit'] );
		$this->assertStringContainsString( 'data-geo-lat="32.7765"', $html );

		$_GET = $original_get;
	}

	public function test_calendar_request_defaults_preserve_explicit_values_and_reject_invalid_state() {
		$original_get = $_GET;
		$_GET        = array(
			'lat'        => '40.7128',
			'lng'        => '-74.0060',
			'tax_filter' => array( 'venue' => array( '9' ) ),
		);
		$parsed_input = null;

		add_filter(
			'data_machine_events_calendar_request_args',
			function ( $args ) {
				$args += array(
					'lat'        => 'invalid-default',
					'lng'        => 'invalid-default',
					'tax_filter' => array( 'festival' => array( 42 ) ),
					'month'     => 'not-a-month',
				);
				return $args;
			}
		);
		add_filter(
			'data_machine_events_calendar_query_args',
			function ( $args, $input ) use ( &$parsed_input ) {
				$parsed_input = $input;
				return $args;
			},
			10,
			2
		);

		$this->render_calendar();

		$this->assertSame( '40.7128', $parsed_input['geo_lat'] );
		$this->assertSame( '-74.0060', $parsed_input['geo_lng'] );
		$this->assertSame( array( 'venue' => array( 9 ) ), $parsed_input['tax_filter'] );
		$this->assertSame( '', $parsed_input['month'] );
		$this->assertSame( '40.7128', $_GET['lat'] );

		$_GET = $original_get;
	}

	public function test_filter_persistence_is_limited_to_interactive_unscoped_calendars() {
		$interactive_html = $this->render_calendar();
		$disabled_html    = $this->render_calendar( array( 'showFilters' => false ) );

		$scope_filter = static function () {
			return 'signed-consumer-scope';
		};
		add_filter( 'data_machine_events_calendar_scope_token', $scope_filter );
		$scoped_html = $this->render_calendar();
		remove_filter( 'data_machine_events_calendar_scope_token', $scope_filter );

		$this->assertStringContainsString( 'data-filter-persistence="1"', $interactive_html );
		$this->assertStringContainsString( 'data-filter-persistence="0"', $disabled_html );
		$this->assertStringContainsString( 'data-filter-persistence="0"', $scoped_html );
	}

	public function test_calendar_renders_no_events_state() {
		// Create a mock render with no events
		$block_registry = \WP_Block_Type_Registry::get_instance();
		$block          = $block_registry->get_registered( 'data-machine-events/calendar' );

		if ( $block && $block->render_callback ) {
			$output = call_user_func( $block->render_callback, array(), '', $block );

			// The output should be a string (HTML)
			$this->assertIsString( $output );
		} else {
			$this->markTestSkipped( 'Block not registered or no render callback' );
		}
	}

	public function test_event_item_template_exists() {
		$template_path = DATA_MACHINE_EVENTS_PATH . 'inc/Blocks/Calendar/templates/event-item.php';

		$this->assertFileExists( $template_path, 'Event item template should exist' );
	}

	public function test_date_group_template_exists() {
		$template_path = DATA_MACHINE_EVENTS_PATH . 'inc/Blocks/Calendar/templates/date-group.php';

		$this->assertFileExists( $template_path, 'Date group template should exist' );
	}

	public function test_no_events_template_exists() {
		$template_path = DATA_MACHINE_EVENTS_PATH . 'inc/Blocks/Calendar/templates/no-events.php';

		$this->assertFileExists( $template_path, 'No events template should exist' );
	}
}
