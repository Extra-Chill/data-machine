<?php
/**
 * CheckMissingVenueAddressesCommand Tests
 *
 * Covers Part A of issue #277: the reverse-geocode + places-lookup +
 * smart-merge repair path for venue terms with empty `_venue_address`.
 *
 * Tests stub the network surface by subclassing the command and
 * overriding the protected `reverse_geocode()` and `places_lookup()`
 * methods. The subclass also captures observed calls so we can assert
 * that the residue path never invokes either lookup.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.38.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Cli\Check\CheckMissingVenueAddressesCommand;

/**
 * Stubbed command — network calls are replaced by canned responses
 * keyed by the input coordinates / (name,city) pair. Unmapped inputs
 * return null (= the lookup failed).
 */
class StubbedMissingVenueAddressesCommand extends CheckMissingVenueAddressesCommand {

	/** @var array<string,array<string,string>|null> */
	public array $reverse_responses = array();

	/** @var array<string,array<string,string>|null> */
	public array $places_responses = array();

	/** @var array<int,string> */
	public array $reverse_calls = array();

	/** @var array<int,string> */
	public array $places_calls = array();

	protected function reverse_geocode( string $coordinates ): ?array {
		$this->reverse_calls[] = $coordinates;
		return $this->reverse_responses[ $coordinates ] ?? null;
	}

	protected function places_lookup( string $name, string $city ): ?array {
		$key                  = $name . '|' . $city;
		$this->places_calls[] = $key;
		return $this->places_responses[ $key ] ?? null;
	}
}

class CheckMissingVenueAddressesCommandTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
	}

	private function make_venue( string $name, array $meta = array() ): int {
		$term = wp_insert_term( $name, 'venue' );
		$this->assertNotInstanceOf( \WP_Error::class, $term );

		$term_id = (int) $term['term_id'];
		foreach ( $meta as $key => $value ) {
			update_term_meta( $term_id, $key, $value );
		}

		return $term_id;
	}

	private function run( StubbedMissingVenueAddressesCommand $cmd, array $assoc_args ): void {
		ob_start();
		$cmd( array(), $assoc_args );
		ob_end_clean();
	}

	// ---------------------------------------------------------------------

	public function test_dry_run_reports_count_without_writes(): void {
		// Three candidates (no address) — two with coords, one with neither.
		$with_coords_a = $this->make_venue(
			'Venue A',
			array( '_venue_coordinates' => '30.2672,-97.7431' )
		);
		$with_coords_b = $this->make_venue(
			'Venue B',
			array( '_venue_coordinates' => '40.7128,-74.0060' )
		);
		$bare = $this->make_venue( 'Venue C' );

		// Distractor: an already-addressed venue must not be processed.
		$already = $this->make_venue(
			'Already Addressed',
			array( '_venue_address' => '123 Main St' )
		);

		$cmd                      = new StubbedMissingVenueAddressesCommand();
		$cmd->reverse_responses['30.2672,-97.7431'] = array(
			'address' => '801 Red River St',
			'city'    => 'Austin',
			'state'   => 'Texas',
			'zip'     => '78701',
			'country' => 'United States',
		);
		// Note: we intentionally route through --dry-run; the stub will
		// be called but no meta writes must result.

		$this->run( $cmd, array( 'dry-run' => true ) );

		$this->assertSame( '', get_term_meta( $with_coords_a, '_venue_address', true ) );
		$this->assertSame( '', get_term_meta( $with_coords_b, '_venue_address', true ) );
		$this->assertSame( '', get_term_meta( $bare, '_venue_address', true ) );
		$this->assertSame( '123 Main St', get_term_meta( $already, '_venue_address', true ) );
	}

	public function test_apply_fills_from_coordinates(): void {
		$term_id = $this->make_venue(
			'Stubbs Bar-B-Q',
			array( '_venue_coordinates' => '30.2672,-97.7431' )
		);

		$cmd = new StubbedMissingVenueAddressesCommand();
		$cmd->reverse_responses['30.2672,-97.7431'] = array(
			'address' => '801 Red River St',
			'city'    => 'Austin',
			'state'   => 'Texas',
			'zip'     => '78701',
			'country' => 'United States',
		);

		$this->run( $cmd, array( 'apply' => true ) );

		$this->assertSame( '801 Red River St', get_term_meta( $term_id, '_venue_address', true ) );
		$this->assertSame( 'Austin', get_term_meta( $term_id, '_venue_city', true ) );
		$this->assertSame( 'Texas', get_term_meta( $term_id, '_venue_state', true ) );
		$this->assertSame( '78701', get_term_meta( $term_id, '_venue_zip', true ) );
		$this->assertSame( 'United States', get_term_meta( $term_id, '_venue_country', true ) );

		// Reverse-geocode was called once with the exact stored coords.
		$this->assertSame( array( '30.2672,-97.7431' ), $cmd->reverse_calls );
		// Places lookup was NOT called — coordinates path succeeded.
		$this->assertSame( array(), $cmd->places_calls );
	}

	public function test_apply_falls_back_to_places_search_when_no_coords(): void {
		$term_id = $this->make_venue(
			'Stubbs Bar-B-Q',
			array( '_venue_city' => 'Austin' )
		);

		$cmd = new StubbedMissingVenueAddressesCommand();
		// Places lookup returns a high-similarity match for the term name.
		$cmd->places_responses[ 'Stubbs Bar-B-Q|Austin' ] = array(
			'address'            => '801 Red River St',
			'city'               => 'Austin',
			'state'              => 'Texas',
			'zip'                => '78701',
			'country'            => 'United States',
			'display_name_short' => 'Stubbs Bar-B-Q',
		);

		$this->run( $cmd, array( 'apply' => true ) );

		$this->assertSame( '801 Red River St', get_term_meta( $term_id, '_venue_address', true ) );
		$this->assertSame( 'Austin', get_term_meta( $term_id, '_venue_city', true ) );
		$this->assertSame( 'Texas', get_term_meta( $term_id, '_venue_state', true ) );
		$this->assertSame( '78701', get_term_meta( $term_id, '_venue_zip', true ) );

		// Reverse-geocode was NOT called — no coordinates available.
		$this->assertSame( array(), $cmd->reverse_calls );
		// Places lookup was called with the (name,city) key.
		$this->assertSame( array( 'Stubbs Bar-B-Q|Austin' ), $cmd->places_calls );
	}

	public function test_smart_merge_does_not_overwrite_existing_fields(): void {
		$term_id = $this->make_venue(
			'Stubbs Bar-B-Q',
			array(
				// Pre-curated city the operator wants preserved.
				'_venue_city'        => 'Existing City',
				'_venue_coordinates' => '30.2672,-97.7431',
			)
		);

		$cmd = new StubbedMissingVenueAddressesCommand();
		// Reverse-geocode reports a DIFFERENT city. The pre-curated value
		// must survive.
		$cmd->reverse_responses['30.2672,-97.7431'] = array(
			'address' => '801 Red River St',
			'city'    => 'Austin',
			'state'   => 'Texas',
			'zip'     => '78701',
			'country' => 'United States',
		);

		$this->run( $cmd, array( 'apply' => true ) );

		// Address WAS filled (was empty).
		$this->assertSame( '801 Red River St', get_term_meta( $term_id, '_venue_address', true ) );
		// City was NOT overwritten.
		$this->assertSame( 'Existing City', get_term_meta( $term_id, '_venue_city', true ) );
		// Other empty fields were filled.
		$this->assertSame( 'Texas', get_term_meta( $term_id, '_venue_state', true ) );
		$this->assertSame( '78701', get_term_meta( $term_id, '_venue_zip', true ) );
	}

	public function test_residue_reports_no_repair_possible(): void {
		$term_id = $this->make_venue( 'Phantom Venue' );

		$cmd = new StubbedMissingVenueAddressesCommand();
		$this->run( $cmd, array( 'apply' => true ) );

		// No writes.
		$this->assertSame( '', get_term_meta( $term_id, '_venue_address', true ) );
		$this->assertSame( '', get_term_meta( $term_id, '_venue_city', true ) );

		// Neither lookup was attempted — no coords AND no city means
		// the command short-circuits to the residue path.
		$this->assertSame( array(), $cmd->reverse_calls );
		$this->assertSame( array(), $cmd->places_calls );
	}

	public function test_places_lookup_rejects_low_name_similarity(): void {
		// Term named "The Local Bar" in Austin. Places lookup returns
		// "Texas Music Theater" — zero meaningful token overlap, must
		// be rejected by VenueMergeHelper::names_are_similar guard.
		$term_id = $this->make_venue(
			'The Local Bar',
			array( '_venue_city' => 'Austin' )
		);

		$cmd = new StubbedMissingVenueAddressesCommand();
		$cmd->places_responses[ 'The Local Bar|Austin' ] = array(
			'address'            => '208 Nueces St',
			'city'               => 'Austin',
			'state'              => 'Texas',
			'zip'                => '78701',
			'country'            => 'United States',
			'display_name_short' => 'Texas Music Theater',
		);

		$this->run( $cmd, array( 'apply' => true ) );

		// Lookup was attempted but rejected — no writes.
		$this->assertSame( '', get_term_meta( $term_id, '_venue_address', true ) );
		$this->assertSame( '', get_term_meta( $term_id, '_venue_state', true ) );
		$this->assertSame( '', get_term_meta( $term_id, '_venue_zip', true ) );

		// Pre-existing city untouched (was the only non-empty field).
		$this->assertSame( 'Austin', get_term_meta( $term_id, '_venue_city', true ) );

		// Lookup WAS called — the rejection happened after the network round-trip.
		$this->assertSame( array( 'The Local Bar|Austin' ), $cmd->places_calls );
	}
}
