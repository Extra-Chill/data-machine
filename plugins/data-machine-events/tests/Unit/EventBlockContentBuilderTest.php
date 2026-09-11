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
use DataMachineEvents\Core\EventSchemaProvider;
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

	public function test_build_round_trips_canonical_offer_and_type_attributes() {
		$content = $this->builder->generate_event_block_content(
			array(
				'startDate'         => '2026-08-01',
				'price'             => '$25',
				'priceCurrency'     => 'USD',
				'ticketUrl'         => 'https://example.com/tickets',
				'offerAvailability' => 'InStock',
				'validFrom'         => '2026-07-01T10:30:00-04:00',
				'eventType'         => 'MusicEvent',
			)
		);

		$block = parse_blocks( $content )[0];
		$this->assertSame( '2026-07-01T10:30:00-04:00', $block['attrs']['validFrom'] );
		$this->assertSame( 'MusicEvent', $block['attrs']['eventType'] );
		$this->assertSame( '$25', $block['attrs']['price'] );
		$this->assertSame( 'USD', $block['attrs']['priceCurrency'] );
		$this->assertSame( 'https://example.com/tickets', $block['attrs']['ticketUrl'] );
		$this->assertSame( 'InStock', $block['attrs']['offerAvailability'] );

		$serialized = serialize_blocks( parse_blocks( $content ) );
		$round_trip = parse_blocks( $serialized )[0]['attrs'];
		$this->assertSame( $block['attrs'], $round_trip );

		$schema = EventSchemaProvider::generateSchemaOrg( $round_trip, array() );
		$this->assertSame( 'MusicEvent', $schema['@type'] );
		$this->assertSame( 'https://example.com/tickets', $schema['offers']['url'] );
		$this->assertSame( 25.0, $schema['offers']['price'] );
		$this->assertSame( 'USD', $schema['offers']['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $schema['offers']['availability'] );
		$this->assertSame( '2026-07-01T10:30:00-04:00', $schema['offers']['validFrom'] );
	}

	public function test_build_preserves_legacy_omission_of_optional_fields() {
		$content = $this->builder->generate_event_block_content( array( 'startDate' => '2026-08-01' ) );
		$attrs   = parse_blocks( $content )[0]['attrs'];

		$this->assertArrayNotHasKey( 'validFrom', $attrs );
		$this->assertArrayNotHasKey( 'eventType', $attrs );
	}

	public function test_valid_from_alone_is_exposed_in_canonical_schema_output() {
		$schema = EventSchemaProvider::generateSchemaOrg(
			array( 'validFrom' => '2026-07-01T10:30:00Z' ),
			array()
		);

		$this->assertSame( '2026-07-01T10:30:00Z', $schema['offers']['validFrom'] );
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

	// ---------------------------------------------------------------
	// Corrupted affiliate redirect write-path guard (issue #823)
	// ---------------------------------------------------------------

	public function test_guard_heals_corrupted_redirect_before_storage() {
		$content = $this->builder->generate_event_block_content(
			array(
				'startDate' => '2026-08-01',
				'ticketUrl' => 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=httpswww.ticketmaster.comeventZ7r9jZ1A7jv1d&utm_medium=affiliate',
			)
		);

		// The corrupted `u=` value is replaced with the reconstructed
		// destination in the healthy percent-encoded shape.
		$this->assertStringContainsString(
			'u=https%3A%2F%2Fwww.ticketmaster.com%2Fevent%2FZ7r9jZ1A7jv1d&utm_medium=affiliate',
			$content
		);
		$this->assertStringNotContainsString( 'httpswww.', $content );
	}

	public function test_guard_keeps_unreconstructable_corruption_visible() {
		// Ticketweb destination: detected but deliberately not reconstructed
		// yet. The guard must NOT blank the URL (silent data loss) — it
		// stores as-is and the quality-audit detector remains the safety net.
		$content = $this->builder->generate_event_block_content(
			array(
				'startDate'    => '2026-08-01',
				'organizerUrl' => 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=httpswww.ticketweb.comeventold-school-rb-crescent-ballroom-tickets14280894&utm_medium=affiliate',
			)
		);

		$this->assertStringContainsString( 'httpswww.ticketweb.comeventold-school', $content );
	}

	public function test_guard_leaves_healthy_wrappers_untouched() {
		$healthy = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fwww.ticketmaster.com%2Fevent%2FZ7r9jZ1A7jv1d&utm_medium=affiliate';

		$content = $this->builder->generate_event_block_content(
			array(
				'startDate' => '2026-08-01',
				'ticketUrl' => $healthy,
			)
		);

		// wp_json_encode() escapes forward slashes, so assert on the
		// distinctive percent-encoded `u=` value rather than the full URL.
		$this->assertStringContainsString(
			'u=https%3A%2F%2Fwww.ticketmaster.com%2Fevent%2FZ7r9jZ1A7jv1d&utm_medium=affiliate',
			$content
		);
		$this->assertStringNotContainsString( 'httpswww.', $content );
	}

	public function test_guard_keeps_ambiguous_corruption_visible() {
		// Query-stripped variant: reconstruction not confident. The guard
		// must NOT blank the URL (silent data loss) — it stores as-is and
		// the quality-audit detector remains the safety net.
		$ambiguous = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=httpswww.ticketweb.comeventvalgur-andy-loebs-zom6ii-nikki-lopez-philly-tickets14178484REFERRAL_IDtmfeed&utm_medium=affiliate';

		$content = $this->builder->generate_event_block_content(
			array(
				'startDate'    => '2026-08-01',
				'organizerUrl' => $ambiguous,
			)
		);

		$this->assertStringContainsString( 'REFERRAL_IDtmfeed', $content );
	}
}
