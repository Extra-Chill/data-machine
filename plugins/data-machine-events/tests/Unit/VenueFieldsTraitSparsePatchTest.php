<?php
/**
 * VenueFieldsTrait Sparse Patch Tests
 *
 * Regression coverage for #769: sparse handler-config patches passed through
 * SettingsClass::sanitize() must not wipe stored venue term meta. Only venue
 * fields actually present in the raw input may be diffed and written.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.57.1
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Steps\EventImport\Handlers\SingleRecurring\SingleRecurringSettings;

class VenueFieldsTraitSparsePatchTest extends WP_UnitTestCase {
	/** @var int[] */
	private array $existing_venue_ids = array();

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );

		// Ensure venue taxonomy is registered
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		$existing_ids = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$this->assertNotWPError( $existing_ids );
		$this->existing_venue_ids = array_map( 'intval', $existing_ids );
	}

	public function tearDown(): void {
		global $wpdb;
		$current_ids = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$this->assertNotWPError( $current_ids );
		foreach ( array_diff( array_map( 'intval', $current_ids ), $this->existing_venue_ids ) as $term_id ) {
			wp_delete_term( $term_id, 'venue' );
		}
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'START TRANSACTION' );
		parent::tearDown();
	}

	/**
	 * Create a fully populated venue term with unique identity so
	 * find_or_create_venue() cannot match a leftover term from another test.
	 */
	private function create_populated_venue(): int {
		$uniq   = uniqid( 'sparse' );
		$result = Venue_Taxonomy::find_or_create_venue(
			'Sparse Patch Venue ' . $uniq,
			array(
				'address'       => $uniq . ' Main St',
				'city'          => 'Charleston',
				'state'         => 'SC',
				'zip'           => '29403',
				'country'       => 'US',
				'phone'         => '843-555-1234',
				'website'       => 'https://example.com/venue',
				'ticketing_url' => 'https://tickets.example.com/venue',
				'capacity'      => 500,
			)
		);

		$this->assertIsInt( $result['term_id'] ?? null );

		return (int) $result['term_id'];
	}

	/**
	 * Snapshot the address-related venue meta fields for a term.
	 *
	 * @return array<string,mixed>
	 */
	private function get_venue_meta( int $term_id ): array {
		$meta = array();
		foreach ( array( 'address', 'city', 'state', 'zip', 'country', 'phone', 'website', 'ticketing_url', 'capacity' ) as $field ) {
			$meta[ $field ] = get_term_meta( $term_id, '_venue_' . $field, true );
		}

		return $meta;
	}

	public function test_sparse_venue_patch_leaves_existing_venue_meta_untouched() {
		$term_id = $this->create_populated_venue();
		$before  = $this->get_venue_meta( $term_id );

		$sanitized = SingleRecurringSettings::sanitize(
			array(
				'event_title' => 'Test Event',
				'venue'       => (string) $term_id,
			)
		);

		$this->assertSame( $before, $this->get_venue_meta( $term_id ) );
		$this->assertEquals( (string) $term_id, $sanitized['venue'] ?? '' );
	}

	public function test_sparse_patch_with_single_venue_field_updates_only_that_field() {
		$term_id = $this->create_populated_venue();
		$before  = $this->get_venue_meta( $term_id );

		$sanitized = SingleRecurringSettings::sanitize(
			array(
				'venue'       => (string) $term_id,
				'venue_phone' => '843-555-9999',
			)
		);

		$after = $this->get_venue_meta( $term_id );
		$this->assertSame( '843-555-9999', $after['phone'] );
		$this->assertSame( $before['address'], $after['address'] );
		$this->assertSame( $before['city'], $after['city'] );
		$this->assertSame( $before['state'], $after['state'] );
		$this->assertSame( $before['zip'], $after['zip'] );
		$this->assertSame( $before['country'], $after['country'] );
		$this->assertSame( $before['website'], $after['website'] );
		$this->assertSame( $before['ticketing_url'], $after['ticketing_url'] );
		$this->assertSame( $before['capacity'], $after['capacity'] );
		$this->assertEquals( (string) $term_id, $sanitized['venue'] ?? '' );
	}

	public function test_full_object_with_changed_address_still_updates_address() {
		$term_id = $this->create_populated_venue();
		$before  = $this->get_venue_meta( $term_id );

		$full_settings = array(
			'venue'               => (string) $term_id,
			'venue_name'          => '',
			'venue_address'       => 'Changed Address 456 Ave',
			'venue_city'          => $before['city'],
			'venue_state'         => $before['state'],
			'venue_zip'           => $before['zip'],
			'venue_country'       => $before['country'],
			'venue_phone'         => $before['phone'],
			'venue_website'       => $before['website'],
			'venue_ticketing_url' => $before['ticketing_url'],
			'venue_capacity'      => '500',
		);

		SingleRecurringSettings::sanitize( $full_settings );

		$after = $this->get_venue_meta( $term_id );
		$this->assertSame( 'Changed Address 456 Ave', $after['address'] );
		$this->assertSame( $before['city'], $after['city'] );
		$this->assertSame( $before['phone'], $after['phone'] );
	}

	public function test_empty_input_sanitizes_to_no_venue_keys() {
		$sanitized = SingleRecurringSettings::sanitize( array() );

		$this->assertArrayNotHasKey( 'venue', $sanitized );
		$this->assertArrayNotHasKey( 'venue_address', $sanitized );
		$this->assertArrayNotHasKey( 'venue_phone', $sanitized );
		$this->assertArrayNotHasKey( 'venue_capacity', $sanitized );
	}
}
