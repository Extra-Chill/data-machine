<?php
/**
 * Event JSON-LD offers.url affiliate-leak tests (issue #862).
 *
 * `EventSchemaProvider::buildOffersSchema()` is the second JSON-LD emitter
 * on an event page (the Event Details block's `application/ld+json`,
 * separate from extrachill-seo's `@graph`). It used to copy `ticketUrl`
 * straight into `offers.url`, leaking the raw affiliate wrapper into
 * static HTML — the last hit Ticketmaster flagged. This must now agree
 * with the extrachill-seo#58 resolver: never emit an affiliate wrapper,
 * prefer the de-affiliated vendor URL, fall back to the permalink.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventSchemaProvider;

class EventSchemaOffersUrlTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
	}

	private function make_event(): int {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Offers URL Test ' . uniqid(),
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );

		return (int) $post_id;
	}

	/**
	 * Affiliate wrapper that successfully unwraps must emit the vendor
	 * destination, never the wrapper itself.
	 */
	public function test_affiliate_url_resolves_to_unwrapped_vendor_url(): void {
		$post_id = $this->make_event();
		$vendor  = 'https://www.ticketmaster.com/event/Z7r9jZ1A7Q4qb';
		$wrapped = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( $vendor ) . '&utm_medium=affiliate';

		$schema = EventSchemaProvider::generateSchemaOrg(
			array( 'ticketUrl' => $wrapped ),
			array(),
			array(),
			$post_id
		);

		$this->assertSame( $vendor, $schema['offers']['url'] );
		$this->assertStringNotContainsString( 'evyy.net', $schema['offers']['url'] );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Affiliate wrapper that cannot be unwrapped must fall back to the
	 * event permalink, never the raw affiliate URL.
	 */
	public function test_unresolvable_affiliate_url_falls_back_to_permalink(): void {
		$post_id = $this->make_event();
		// A recognized affiliate host with no `u=`/redirect param to unwrap.
		$wrapped = 'https://ticketmaster.evyy.net/c/1191134/264167/4272';

		$schema = EventSchemaProvider::generateSchemaOrg(
			array( 'ticketUrl' => $wrapped ),
			array(),
			array(),
			$post_id
		);

		$this->assertSame( get_permalink( $post_id ), $schema['offers']['url'] );
		$this->assertStringNotContainsString( 'evyy.net', $schema['offers']['url'] );

		wp_delete_post( $post_id, true );
	}

	/**
	 * A direct, non-affiliate ticket URL passes through unchanged.
	 */
	public function test_non_affiliate_url_is_unchanged(): void {
		$post_id = $this->make_event();
		$direct  = 'https://www.ticketmaster.com/event/Z7r9jZ1A7Q4qb';

		$schema = EventSchemaProvider::generateSchemaOrg(
			array( 'ticketUrl' => $direct ),
			array(),
			array(),
			$post_id
		);

		$this->assertSame( $direct, $schema['offers']['url'] );

		wp_delete_post( $post_id, true );
	}

	/**
	 * A stored ticket URL carrying `&amp;`/`\u0026` storage artifacts must
	 * be normalized before the affiliate check and before it is emitted,
	 * so the redirect param parses correctly and the emitted URL is clean.
	 */
	public function test_stored_html_entity_form_is_normalized_before_unwrap(): void {
		$post_id = $this->make_event();
		$vendor  = 'https://www.ticketmaster.com/event/Z7r9jZ1A7Q4qb';
		$stored  = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( $vendor ) . '&amp;utm_medium=affiliate';

		$schema = EventSchemaProvider::generateSchemaOrg(
			array( 'ticketUrl' => $stored ),
			array(),
			array(),
			$post_id
		);

		$this->assertSame( $vendor, $schema['offers']['url'] );

		wp_delete_post( $post_id, true );
	}

	/**
	 * The rest of the offers node (type, availability, price fields) must
	 * survive untouched around the affiliate-safe url resolution.
	 */
	public function test_other_offers_fields_are_preserved(): void {
		$post_id = $this->make_event();
		$wrapped = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( 'https://www.ticketmaster.com/event/Z7r9jZ1A7Q4qb' ) . '&utm_medium=affiliate';

		$schema = EventSchemaProvider::generateSchemaOrg(
			array(
				'ticketUrl'         => $wrapped,
				'price'             => '25.00',
				'priceCurrency'     => 'USD',
				'offerAvailability' => 'InStock',
				'validFrom'         => '2026-09-01T00:00:00',
			),
			array(),
			array(),
			$post_id
		);

		$this->assertSame( 'Offer', $schema['offers']['@type'] );
		$this->assertSame( 'https://schema.org/InStock', $schema['offers']['availability'] );
		$this->assertSame( 25.0, $schema['offers']['price'] );
		$this->assertSame( 'USD', $schema['offers']['priceCurrency'] );
		$this->assertSame( '2026-09-01T00:00:00', $schema['offers']['validFrom'] );

		wp_delete_post( $post_id, true );
	}
}
