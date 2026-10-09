<?php
/**
 * Venue import timezone ownership tests.
 *
 * Issue #897: venue timezone is derived from venue location. An import's
 * timezone must not be merged as venue data; it is only a last-resort hint
 * for a venue whose location cannot resolve a zone.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Admin\Settings_Page;
use DataMachineEvents\Core\Venue_Taxonomy;

class VenueImportTimezoneTest extends WP_UnitTestCase {
	/** @var int[] */
	private array $term_ids = array();

	private bool $had_settings = false;
	private mixed $settings_before = null;

	/** @var callable */
	private $block_http;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );

		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}

		$this->had_settings    = false !== get_option( Settings_Page::OPTION_KEY, false );
		$this->settings_before = get_option( Settings_Page::OPTION_KEY, null );
		delete_option( Settings_Page::OPTION_KEY );

		$this->block_http = static fn() => new \WP_Error( 'http_blocked', 'No network in tests.' );
		add_filter( 'pre_http_request', $this->block_http );
	}

	public function tearDown(): void {
		global $wpdb;
		remove_filter( 'pre_http_request', $this->block_http );
		foreach ( array_reverse( $this->term_ids ) as $term_id ) {
			wp_delete_term( $term_id, 'venue' );
		}
		if ( $this->had_settings ) {
			update_option( Settings_Page::OPTION_KEY, $this->settings_before );
		}
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'START TRANSACTION' );
		parent::tearDown();
	}

	public function test_created_venue_takes_location_zone_over_import_zone(): void {
		$result = $this->import(
			'Created Venue ' . uniqid(),
			array(
				'city'     => 'North Charleston',
				'state'    => 'SC',
				'country'  => 'US',
				'timezone' => 'America/Chicago',
			)
		);

		$this->assertSame( 'created', $result['match_status'] );
		$this->assertSame( 'America/New_York', $this->timezone( $result['term_id'] ) );
	}

	public function test_merge_into_existing_venue_does_not_store_import_zone(): void {
		$name    = 'Existing Venue ' . uniqid();
		$term_id = $this->existing( $name, array( '_venue_address' => '2301 Noisette Blvd', '_venue_city' => 'North Charleston', '_venue_state' => 'SC', '_venue_country' => 'US' ) );

		$result = $this->import(
			$name,
			array(
				'address'  => '2301 Noisette Blvd',
				'city'     => 'North Charleston',
				'timezone' => 'America/Chicago',
			)
		);

		$this->assertSame( 'matched', $result['match_status'] );
		$this->assertSame( $term_id, (int) $result['term_id'] );
		$this->assertSame( 'America/New_York', $this->timezone( $term_id ) );
	}

	public function test_merge_keeps_existing_valid_zone(): void {
		$name    = 'Zoned Venue ' . uniqid();
		$term_id = $this->existing( $name, array( '_venue_city' => 'Pittsburgh', '_venue_state' => 'PA', '_venue_timezone' => 'America/New_York' ) );

		$this->import( $name, array( 'city' => 'Pittsburgh', 'timezone' => 'America/Chicago' ) );

		$this->assertSame( 'America/New_York', $this->timezone( $term_id ) );
	}

	public function test_import_zone_is_fallback_when_location_cannot_resolve(): void {
		$result = $this->import( 'Unlocated Venue ' . uniqid(), array( 'timezone' => 'America/Denver' ) );

		$this->assertSame( 'created', $result['match_status'] );
		$this->assertSame( 'America/Denver', $this->timezone( $result['term_id'] ) );
	}

	public function test_fallback_rejects_non_iana_import_zone(): void {
		$result = $this->import( 'Offset Venue ' . uniqid(), array( 'timezone' => '-08:00' ) );

		$this->assertSame( 'created', $result['match_status'] );
		$this->assertSame( '', $this->timezone( $result['term_id'] ) );
	}

	private function import( string $name, array $venue_data ): array {
		$result = Venue_Taxonomy::find_or_create_venue( $name, $venue_data );
		$this->assertGreaterThan( 0, (int) $result['term_id'] );
		if ( 'created' === $result['match_status'] ) {
			$this->term_ids[] = (int) $result['term_id'];
		}
		return $result;
	}

	private function existing( string $name, array $meta ): int {
		$created = wp_insert_term( $name, 'venue' );
		$this->assertNotWPError( $created );
		$term_id          = (int) $created['term_id'];
		$this->term_ids[] = $term_id;
		foreach ( $meta as $key => $value ) {
			update_term_meta( $term_id, $key, $value );
		}
		return $term_id;
	}

	private function timezone( $term_id ): string {
		return (string) get_term_meta( (int) $term_id, '_venue_timezone', true );
	}
}
