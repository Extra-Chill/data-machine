<?php
/**
 * EventImportHandler Tests
 *
 * Tests base functionality for event import handlers.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.9.16
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use ReflectionClass;

class EventImportHandlerTest extends WP_UnitTestCase {

	private $handler;

	public function setUp(): void {
		parent::setUp();

		// Create a concrete implementation for testing
		$this->handler = new class( 'test_handler' ) extends EventImportHandler {
			public function get_fetch_data( \DataMachine\Core\ExecutionContext $context ): array {
				return array();
			}
		};
	}

	public function test_should_skip_event_title_with_closed() {
		$this->assertTrue( $this->handler->shouldSkipEventTitle( 'Venue Closed Tonight' ) );
		$this->assertTrue( $this->handler->shouldSkipEventTitle( 'CLOSED for private event' ) );
		$this->assertTrue( $this->handler->shouldSkipEventTitle( 'We are closed' ) );
	}

	public function test_should_not_skip_valid_event_titles() {
		$this->assertFalse( $this->handler->shouldSkipEventTitle( 'Live Music Night' ) );
		$this->assertFalse( $this->handler->shouldSkipEventTitle( 'DJ Set at 9pm' ) );
		$this->assertFalse( $this->handler->shouldSkipEventTitle( 'Open Mic' ) );
	}

	public function test_should_not_skip_empty_title() {
		$this->assertFalse( $this->handler->shouldSkipEventTitle( '' ) );
	}

	public function test_sanitize_text_removes_whitespace() {
		$method = $this->getProtectedMethod( 'sanitizeText' );

		$this->assertEquals( 'Test Event', $method->invoke( $this->handler, '  Test Event  ' ) );
	}

	public function test_sanitize_url_adds_https_prefix() {
		$method = $this->getProtectedMethod( 'sanitizeUrl' );

		$this->assertEquals( 'https://example.com', $method->invoke( $this->handler, 'example.com' ) );
	}

	public function test_sanitize_url_preserves_valid_url() {
		$method = $this->getProtectedMethod( 'sanitizeUrl' );

		$this->assertEquals( 'https://example.com/events', $method->invoke( $this->handler, 'https://example.com/events' ) );
		$this->assertEquals( 'http://example.com/events', $method->invoke( $this->handler, 'http://example.com/events' ) );
	}

	public function test_sanitize_url_returns_empty_for_invalid() {
		$method = $this->getProtectedMethod( 'sanitizeUrl' );

		$this->assertEquals( '', $method->invoke( $this->handler, 'not a valid url' ) );
	}

	public function test_clean_html_strips_most_tags() {
		$method = $this->getProtectedMethod( 'cleanHtml' );

		$html = '<div><script>alert("x")</script><p>Hello</p><a href="#">Link</a></div>';
		$result = $method->invoke( $this->handler, $html );

		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringNotContainsString( '<div>', $result );
		$this->assertStringContainsString( '<p>', $result );
		$this->assertStringContainsString( '<a', $result );
	}

	public function test_is_past_event_returns_true_for_past_date() {
		$past_date = date( 'Y-m-d', strtotime( '-1 week' ) );
		$this->assertTrue( $this->handler->isPastEvent( $past_date ) );
	}

	public function test_is_past_event_returns_false_for_future_date() {
		$future_date = date( 'Y-m-d', strtotime( '+1 week' ) );
		$this->assertFalse( $this->handler->isPastEvent( $future_date ) );
	}

	public function test_is_past_event_returns_false_for_empty() {
		$this->assertFalse( $this->handler->isPastEvent( '' ) );
	}

	public function test_parse_coordinates_valid() {
		$method = $this->getProtectedMethod( 'parseCoordinates' );

		$result = $method->invoke( $this->handler, '39.7392, -104.9903' );

		$this->assertIsArray( $result );
		$this->assertEquals( 39.7392, $result['lat'] );
		$this->assertEquals( -104.9903, $result['lng'] );
	}

	public function test_parse_coordinates_invalid_format() {
		$method = $this->getProtectedMethod( 'parseCoordinates' );

		$this->assertFalse( $method->invoke( $this->handler, 'invalid' ) );
		$this->assertFalse( $method->invoke( $this->handler, '39.7392' ) );
		$this->assertFalse( $method->invoke( $this->handler, 'abc, def' ) );
	}

	public function test_parse_coordinates_out_of_range() {
		$method = $this->getProtectedMethod( 'parseCoordinates' );

		$this->assertFalse( $method->invoke( $this->handler, '95.0, -104.9903' ) );
		$this->assertFalse( $method->invoke( $this->handler, '39.7392, -185.0' ) );
	}

	private function getProtectedMethod( string $name ) {
		$reflection = new ReflectionClass( $this->handler );
		$method = $reflection->getMethod( $name );
		$method->setAccessible( true );
		return $method;
	}
}
