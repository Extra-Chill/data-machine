<?php
/**
 * Artist billing normalization at the event taxonomy write boundary.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\Upsert\Events\EventTaxonomyAssigner;

require_once __DIR__ . '/EventUpsertTestCase.php';

class EventArtistBillingTest extends EventUpsertTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! taxonomy_exists( 'artist' ) ) {
			register_taxonomy( 'artist', 'data_machine_events' );
		}
		register_taxonomy_for_object_type( 'artist', 'data_machine_events' );
	}

	public function test_suffixes_resolve_to_existing_base_even_if_billing_term_exists(): void {
		$name = 'Gavin Hamilton ' . uniqid();
		$base = wp_insert_term( $name, 'artist' );
		$this->assertNotWPError( $base );
		wp_insert_term( $name . ' & Friends', 'artist' );
		$assigner = new EventTaxonomyAssigner();
		foreach ( array( '& Friends', 'and Friends', '& His Band', '& Her Band', '& the Band', '&amp; Friends', 'AND hIs BaNd', '&amp; HER BAND', 'and the band' ) as $suffix ) {
			$this->assertSame( array( $name ), $assigner->normalizeArtists( array( $name . ' ' . $suffix ) ), $suffix );
		}
	}

	public function test_suffix_without_base_keeps_full_billing(): void {
		$name = 'New Artist ' . uniqid() . ' & Friends';
		$this->assert_artist_upsert( $name, $name );
	}

	public function test_encoded_performer_is_assigned_to_existing_base(): void {
		$name = 'Gavin Hamilton ' . uniqid();
		$base = wp_insert_term( $name, 'artist' );
		$this->assertNotWPError( $base );
		$this->assert_artist_upsert( $name . ' &amp; Friends', $name );
		$this->assertFalse( term_exists( $name . ' & Friends', 'artist' ) );
	}

	public function test_only_trailing_billing_suffixes_are_removed(): void {
		$name = 'Artist ' . uniqid();
		wp_insert_term( $name, 'artist' );
		$values = array( $name . ' & Friends Reunion', $name . ' & Other Band', 42 );
		$this->assertSame( $values, ( new EventTaxonomyAssigner() )->normalizeArtists( $values ) );
	}

	private function assert_artist_upsert( string $input, string $expected, string $field = 'performer' ): void {
		$result = $this->invoke_upsert(
			array(
				'title'     => 'Artist Billing Test ' . uniqid(),
				'venue'     => 'Billing Test Venue ' . uniqid(),
				'startDate' => '2027-08-20',
				'startTime' => '19:00',
				$field      => $input,
			),
			array( 'taxonomy_artist_selection' => 'ai_decides' )
		);
		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$terms = wp_get_post_terms( (int) $result['data']['post_id'], 'artist' );
		$this->assertSame( array( $expected ), array_map( 'html_entity_decode', wp_list_pluck( $terms, 'name' ) ) );
	}
}
