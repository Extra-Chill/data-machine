<?php
/**
 * Event JSON-LD offers filter tests.
 *
 * `data_machine_events_offer_schema` lets a site that sells an event's
 * tickets itself replace the imported vendor offer, add one where none
 * exists, or remove it.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventSchemaProvider;

class EventSchemaOfferFilterTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
	}

	public function tearDown(): void {
		remove_all_filters( 'data_machine_events_offer_schema' );
		parent::tearDown();
	}

	private function make_event(): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	private function schema( array $event_data, int $post_id ): array {
		return EventSchemaProvider::generateSchemaOrg( $event_data, array(), array(), $post_id );
	}

	public function test_filter_overrides_url_price_and_availability(): void {
		$post_id = $this->make_event();
		$seen    = null;
		add_filter(
			'data_machine_events_offer_schema',
			function ( $offers, $id, $event_data ) use ( &$seen, $post_id ) {
				$seen = array( $offers, $id, $event_data );
				return array_merge(
					$offers,
					array(
						'url'          => get_permalink( $post_id ),
						'price'        => 15.0,
						'availability' => 'https://schema.org/SoldOut',
					)
				);
			},
			10,
			3
		);

		$event_data = array(
			'ticketUrl' => 'https://link.dice.fm/abc123',
			'price'     => '$25',
		);
		$schema     = $this->schema( $event_data, $post_id );

		$this->assertSame( 'https://link.dice.fm/abc123', $seen[0]['url'], 'The filter receives the built vendor offer.' );
		$this->assertSame( $post_id, $seen[1] );
		$this->assertSame( $event_data, $seen[2] );
		$this->assertSame( get_permalink( $post_id ), $schema['offers']['url'] );
		$this->assertSame( 15.0, $schema['offers']['price'] );
		$this->assertSame( 'https://schema.org/SoldOut', $schema['offers']['availability'] );
	}

	public function test_filter_can_add_offers_to_event_without_ticket_fields(): void {
		$post_id = $this->make_event();

		$this->assertArrayNotHasKey( 'offers', $this->schema( array(), $post_id ), 'No ticket fields and no filter means no offers.' );

		add_filter(
			'data_machine_events_offer_schema',
			function ( $offers ) {
				$this->assertSame( array(), $offers );
				return array(
					'@type'         => 'Offer',
					'price'         => 20.0,
					'priceCurrency' => 'USD',
				);
			}
		);

		$schema = $this->schema( array(), $post_id );
		$this->assertSame( 'Offer', $schema['offers']['@type'] );
		$this->assertSame( 20.0, $schema['offers']['price'] );
	}

	public function test_returning_empty_array_removes_offers(): void {
		$post_id = $this->make_event();
		add_filter( 'data_machine_events_offer_schema', '__return_empty_array' );

		$schema = $this->schema( array( 'ticketUrl' => 'https://link.dice.fm/abc123' ), $post_id );
		$this->assertArrayNotHasKey( 'offers', $schema );
	}
}
