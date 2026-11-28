<?php
/**
 * Venue Tier Term Meta Tests
 *
 * Covers the closed-vocabulary `_venue_tier` term meta introduced in #786:
 * vocabulary normalization and filter, sanitize coercion, the admin-facing
 * human write path, and — the core guarantee — that no AI/import path can
 * write a tier value.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\VenueParameterProvider;
use DataMachineEvents\Core\Venue_Taxonomy;

class VenueTierMetaTest extends WP_UnitTestCase {

	/** @var int[] */
	private array $term_ids = array();

	public function setUp(): void {
		parent::setUp();

		// VenueProfileMutations refuses to run inside an open transaction and
		// WP_UnitTestCase wraps every test in one. Mirror the integration
		// suite: commit out, run the canonical write path for real, and
		// re-enter the transaction in tearDown so the framework can roll back.
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		Venue_Taxonomy::register();
	}

	public function tearDown(): void {
		global $wpdb;
		remove_all_filters( 'data_machine_events_venue_tier_vocabulary' );
		foreach ( array_reverse( $this->term_ids ) as $term_id ) {
			wp_delete_term( $term_id, 'venue' );
		}
		$this->term_ids = array();
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'START TRANSACTION' );
		parent::tearDown();
	}

	/**
	 * Create a venue term and return its term ID.
	 *
	 * @param string $name Venue name.
	 * @return int
	 */
	private function make_venue( string $name ): int {
		$created = wp_insert_term( $name, 'venue' );
		$this->assertNotWPError( $created );
		$this->term_ids[] = (int) $created['term_id'];

		return (int) $created['term_id'];
	}

	public function test_default_vocabulary_is_the_generic_closed_set(): void {
		$this->assertSame(
			array(
				'bar_gig',
				'listening_room',
				'club',
				'concert_hall',
				'amphitheater',
			),
			array_keys( Venue_Taxonomy::get_venue_tier_vocabulary() )
		);

		foreach ( Venue_Taxonomy::get_venue_tier_vocabulary() as $label ) {
			$this->assertNotSame( '', $label );
		}
	}

	public function test_vocabulary_filter_accepts_slug_label_pairs_and_array_entries(): void {
		add_filter(
			'data_machine_events_venue_tier_vocabulary',
			static function () {
				return array(
					'coffee_shop' => 'Coffee shop with occasional music',
					array(
						'slug'  => 'arena',
						'label' => 'Arena',
					),
				);
			}
		);

		$this->assertSame(
			array(
				'coffee_shop' => 'Coffee shop with occasional music',
				'arena'       => 'Arena',
			),
			Venue_Taxonomy::get_venue_tier_vocabulary()
		);
	}

	public function test_vocabulary_dedupes_by_slug_and_drops_malformed_entries(): void {
		add_filter(
			'data_machine_events_venue_tier_vocabulary',
			static function () {
				return array(
					'club'     => 'String shape wins',
					array(
						'slug'  => 'club',
						'label' => 'Duplicate slug ignored',
					),
					array( 'label' => 'Missing slug with numeric key' ),
					'missing'  => array( 'label' => '' ),
					99         => 'Not an array or string entry',
					'has_slug' => array( 'label' => 'Array entry label' ),
				);
			}
		);

		$vocabulary = Venue_Taxonomy::get_venue_tier_vocabulary();

		$this->assertSame( array( 'club', 'has_slug' ), array_keys( $vocabulary ) );
		$this->assertSame( 'String shape wins', $vocabulary['club'] );
		$this->assertSame( 'Array entry label', $vocabulary['has_slug'] );
	}

	public function test_empty_filtered_vocabulary_falls_back_to_defaults(): void {
		add_filter( 'data_machine_events_venue_tier_vocabulary', '__return_empty_array' );

		$this->assertSame(
			array_keys( Venue_Taxonomy::default_venue_tier_vocabulary() ),
			array_keys( Venue_Taxonomy::get_venue_tier_vocabulary() )
		);
	}

	public function test_sanitize_resolves_slugs_and_labels_and_rejects_unknown_values(): void {
		$this->assertSame( 'club', Venue_Taxonomy::sanitize_venue_tier_meta_value( 'club' ) );
		$this->assertSame( 'club', Venue_Taxonomy::sanitize_venue_tier_meta_value( 'Club' ) );
		$this->assertSame( 'concert_hall', Venue_Taxonomy::sanitize_venue_tier_meta_value( 'Large ticketed room' ) );
		$this->assertSame( '', Venue_Taxonomy::sanitize_venue_tier_meta_value( 'not-a-tier' ) );
		$this->assertSame( '', Venue_Taxonomy::sanitize_venue_tier_meta_value( '' ) );
		$this->assertSame( '', Venue_Taxonomy::sanitize_venue_tier_meta_value( null ) );
		$this->assertSame( '', Venue_Taxonomy::sanitize_venue_tier_meta_value( array( 'club', 'extra' ) ) );
	}

	public function test_is_valid_venue_tier_mirrors_resolve(): void {
		$this->assertTrue( Venue_Taxonomy::is_valid_venue_tier( 'bar_gig' ) );
		$this->assertFalse( Venue_Taxonomy::is_valid_venue_tier( 'stadium' ) );
		$this->assertFalse( Venue_Taxonomy::is_valid_venue_tier( '' ) );
	}

	public function test_term_meta_write_coerces_out_of_vocabulary_values(): void {
		$term_id = $this->make_venue( 'Coercion Test Venue' );

		update_term_meta( $term_id, Venue_Taxonomy::TIER_META_KEY, 'Club' );
		$this->assertSame( 'club', get_term_meta( $term_id, Venue_Taxonomy::TIER_META_KEY, true ) );

		update_term_meta( $term_id, Venue_Taxonomy::TIER_META_KEY, 'invented-tier' );
		$this->assertSame( '', get_term_meta( $term_id, Venue_Taxonomy::TIER_META_KEY, true ) );

		update_term_meta( $term_id, Venue_Taxonomy::TIER_META_KEY, '' );
		$this->assertSame( '', get_term_meta( $term_id, Venue_Taxonomy::TIER_META_KEY, true ) );
	}

	public function test_human_write_path_stores_valid_tier_and_get_venue_data_returns_it(): void {
		$term_id = $this->make_venue( 'Human Write Test Venue' );

		$this->assertTrue( Venue_Taxonomy::update_venue_meta( $term_id, array( 'tier' => 'listening_room' ) ) );

		$venue_data = Venue_Taxonomy::get_venue_data( $term_id );
		$this->assertSame( 'listening_room', $venue_data['tier'] );
		$this->assertSame( 'Small, music-first, seated/attentive', Venue_Taxonomy::get_venue_tier_label( 'listening_room' ) );

		// Out-of-vocabulary values sanitize to '' (unclassified) rather than persisting.
		$this->assertTrue( Venue_Taxonomy::update_venue_meta( $term_id, array( 'tier' => 'invented-tier' ) ) );
		$this->assertSame( '', Venue_Taxonomy::get_venue_data( $term_id )['tier'] );
	}

	public function test_find_or_create_venue_strips_tier_from_import_data(): void {
		$result = Venue_Taxonomy::find_or_create_venue(
			'Import Lockout Test Venue',
			array(
				'city' => 'Somewhere',
				'tier' => 'concert_hall',
			)
		);

		$this->assertSame( 'created', $result['match_status'] );
		$this->assertGreaterThan( 0, $result['term_id'] );
		$this->term_ids[] = (int) $result['term_id'];

		// The import path must never write tier, even when upstream data carries it.
		$this->assertSame( '', get_term_meta( $result['term_id'], Venue_Taxonomy::TIER_META_KEY, true ) );

		// And a second import pass must not merge one in.
		$second = Venue_Taxonomy::find_or_create_venue(
			'Import Lockout Test Venue',
			array( 'tier' => 'club' )
		);
		$this->assertSame( 'matched', $second['match_status'] );
		$this->assertSame( $result['term_id'], $second['term_id'] );
		$this->assertSame( '', get_term_meta( $result['term_id'], Venue_Taxonomy::TIER_META_KEY, true ) );
	}

	public function test_ai_tool_surface_has_no_tier_parameter(): void {
		$this->assertNotContains( 'venueTier', VenueParameterProvider::getParameterKeys() );
		$this->assertNotContains( 'venueTier', array_keys( VenueParameterProvider::getParameterToMetaKeyMap() ) );
		$this->assertNotContains( 'tier', VenueParameterProvider::getParameterToMetaKeyMap(), 'AI parameters must never map to the tier meta field.' );

		// extractFromParameters() is driven by the map, so AI output keyed
		// venueTier can never reach venue data either.
		$extracted = VenueParameterProvider::extractFromParameters(
			array(
				'venueTier' => 'club',
				'venueCity' => 'Somewhere',
			)
		);
		$this->assertArrayNotHasKey( 'tier', $extracted );
		$this->assertSame( array( 'city' => 'Somewhere' ), $extracted );
	}

	public function test_get_venue_term_ids_by_tier_returns_only_matching_venues(): void {
		$club_id  = $this->make_venue( 'Tier Filter Club Venue' );
		$bar_id   = $this->make_venue( 'Tier Filter Bar Venue' );
		$plain_id = $this->make_venue( 'Tier Filter Unclassified Venue' );

		$this->assertTrue( Venue_Taxonomy::update_venue_meta( $club_id, array( 'tier' => 'club' ) ) );
		$this->assertTrue( Venue_Taxonomy::update_venue_meta( $bar_id, array( 'tier' => 'bar_gig' ) ) );

		$matches = Venue_Taxonomy::get_venue_term_ids_by_tier( 'club' );
		$this->assertSame( array( $club_id ), $matches );
		$this->assertNotContains( $plain_id, $matches );

		$this->assertSame( array(), Venue_Taxonomy::get_venue_term_ids_by_tier( 'invented-tier' ) );
		$this->assertSame( array(), Venue_Taxonomy::get_venue_term_ids_by_tier( '' ) );
	}

	public function test_query_events_with_unknown_tier_fails_closed_to_zero_results(): void {
		$abilities = new \DataMachineEvents\Abilities\EventDateQueryAbilities();

		$result = $abilities->executeQueryEvents(
			array(
				'scope'      => 'all',
				'venue_tier' => 'invented-tier',
				'fields'     => 'ids',
			)
		);

		$this->assertSame( 0, $result['total'] );
		$this->assertSame( array(), $result['posts'] );
	}
}
