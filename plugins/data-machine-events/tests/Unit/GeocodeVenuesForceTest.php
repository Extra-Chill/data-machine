<?php
/**
 * Forced re-geocode must not destroy existing location data (#903).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Abilities\GeocodingAbilities;
use DataMachineEvents\Core\Venue_Taxonomy;

class GeocodeVenuesForceTest extends WP_UnitTestCase {

	private int $term_id = 0;

	/** @var callable|null */
	private $http_filter = null;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );

		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}

		$created = wp_insert_term( 'Force Geocode Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $created );
		$this->term_id = (int) $created['term_id'];
		update_term_meta( $this->term_id, '_venue_address', '14th & Curtis Streets ' . uniqid() );
		update_term_meta( $this->term_id, '_venue_city', 'Denver' );
		update_term_meta( $this->term_id, '_venue_state', 'CO' );
		update_term_meta( $this->term_id, '_venue_country', 'US' );
		update_term_meta( $this->term_id, '_venue_coordinates', '48.8295585,2.3239740' );
		update_term_meta( $this->term_id, '_venue_timezone', 'America/Denver' );
	}

	public function tearDown(): void {
		global $wpdb;
		if ( $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
		}
		wp_delete_term( $this->term_id, 'venue' );
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'START TRANSACTION' );
		parent::tearDown();
	}

	public function test_failed_forced_regeocode_keeps_existing_location(): void {
		$this->respond( array() );

		$result = ( new GeocodingAbilities() )->executeGeocodeVenues(
			array(
				'venue_id' => $this->term_id,
				'force'    => true,
				'limit'    => 1,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( '48.8295585,2.3239740', get_term_meta( $this->term_id, '_venue_coordinates', true ) );
		$this->assertSame( 'America/Denver', get_term_meta( $this->term_id, '_venue_timezone', true ) );
	}

	public function test_successful_forced_regeocode_replaces_coordinates_and_rederives_timezone(): void {
		$this->respond(
			array(
				array(
					'lat'          => '39.7578278',
					'lon'          => '-104.9794522',
					'display_name' => 'Denver',
					'address'      => array(
						'country_code'   => 'us',
						'ISO3166-2-lvl4' => 'US-CO',
					),
				),
			)
		);

		( new GeocodingAbilities() )->executeGeocodeVenues(
			array(
				'venue_id' => $this->term_id,
				'force'    => true,
				'limit'    => 1,
			)
		);

		$this->assertSame( '39.7578278,-104.9794522', get_term_meta( $this->term_id, '_venue_coordinates', true ) );
		$this->assertSame( 'America/Denver', get_term_meta( $this->term_id, '_venue_timezone', true ) );
	}

	private function respond( array $body ): void {
		$this->http_filter = static function ( $preempt, $args, $url ) use ( $body ) {
			if ( ! str_contains( (string) $url, 'openstreetmap.org' ) && ! str_contains( (string) $url, 'geonames.org' ) ) {
				return $preempt;
			}
			if ( str_contains( (string) $url, 'geonames.org' ) ) {
				return new \WP_Error( 'blocked', 'No GeoNames in tests.' );
			}
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}
}
