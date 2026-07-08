<?php
/**
 * EventBlockContentBuilder Tests
 *
 * Direct unit tests for the event-details block content assembly
 * collaborator extracted from EventUpsert in #425.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\Upsert\Events\EventBlockContentBuilder;

class EventBlockContentBuilderTest extends WP_UnitTestCase {

	private EventBlockContentBuilder $builder;

	public function setUp(): void {
		parent::setUp();
		$this->builder = new EventBlockContentBuilder();
	}

	public function test_builder_instantiation() {
		$this->assertInstanceOf( EventBlockContentBuilder::class, $this->builder );
	}

	public function test_build_wraps_content_in_event_details_block() {
		$content = $this->builder->generate_event_block_content(
			array(
				'title'       => 'Eggy at Charleston Pour House',
				'startDate'   => '2026-08-01',
				'startTime'   => '20:00',
				'venue'       => 'Charleston Pour House',
				'ticketUrl'   => 'https://example.com/tickets',
				'description' => '',
			)
		);

		$this->assertStringStartsWith( '<!-- wp:data-machine-events/event-details ', $content );
		$this->assertStringEndsWith( '<!-- /wp:data-machine-events/event-details -->', $content );
		$this->assertStringContainsString( '<div class="wp-block-data-machine-events-event-details">', $content );
	}

	public function test_build_includes_block_attributes() {
		$content = $this->builder->generate_event_block_content(
			array(
				'startDate' => '2026-08-01',
				'startTime' => '20:00',
				'venue'     => 'Charleston Pour House',
			)
		);

		// The block JSON must carry the event fields as attributes.
		$this->assertStringContainsString( '"startDate":"2026-08-01"', $content );
		$this->assertStringContainsString( '"startTime":"20:00"', $content );
		$this->assertStringContainsString( '"venue":"Charleston Pour House"', $content );
		// Display flags are always forced true.
		$this->assertStringContainsString( '"showVenue":true', $content );
		$this->assertStringContainsString( '"showPrice":true', $content );
		$this->assertStringContainsString( '"showTicketLink":true', $content );
	}

	public function test_build_omits_empty_attributes() {
		$content = $this->builder->generate_event_block_content(
			array(
				'startDate' => '2026-08-01',
				'venue'     => '',
				'ticketUrl' => '',
			)
		);

		// Empty values are array_filter'd out before encoding.
		$this->assertStringNotContainsString( '"venue"', $content );
		$this->assertStringNotContainsString( '"ticketUrl"', $content );
	}

	public function test_build_renders_description_as_paragraph_blocks() {
		$content = $this->builder->generate_event_block_content(
			array(
				'startDate'   => '2026-08-01',
				'description' => '<p>Eggy returns to Charleston.</p><p>Doors at 8pm.</p>',
			)
		);

		$this->assertStringContainsString( '<!-- wp:paragraph -->', $content );
		$this->assertStringContainsString( '<!-- /wp:paragraph -->', $content );
		$this->assertStringContainsString( '<p>Eggy returns to Charleston.</p>', $content );
		$this->assertStringContainsString( '<p>Doors at 8pm.</p>', $content );
	}

	public function test_build_without_description_has_no_paragraph_blocks() {
		$content = $this->builder->generate_event_block_content(
			array(
				'startDate'   => '2026-08-01',
				'description' => '',
			)
		);

		$this->assertStringNotContainsString( '<!-- wp:paragraph -->', $content );
	}
}
