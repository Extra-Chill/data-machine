<?php
/**
 * Venue source alias tests.
 *
 * Issue #878: Ticketmaster venue Z7r9jZa7-j sends "Round Rock Amphitheater /
 * 301 W Bagdad Ave" for shows at Round Rock Amp, 3701 N IH-35. After an
 * editor corrects the venue term, re-imports must resolve to the corrected
 * term through a curated alias, must not merge the source's wrong data, and
 * must not put the wrong name or address back into event content.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\VenueAbilities;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Core\VenueSourceAliases;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\Ticketmaster;
use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterSourceIdentity;
use ReflectionClass;
use WP_UnitTestCase;

class VenueSourceAliasesTest extends WP_UnitTestCase {

	private const TM_ALIAS = 'ticketmaster:Z7r9jZa7-j';

	/** @var int[] */
	private array $existing_venue_ids = array();

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );

		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}

		$this->existing_venue_ids = $this->venue_ids();
	}

	public function tearDown(): void {
		global $wpdb;
		foreach ( array_diff( $this->venue_ids(), $this->existing_venue_ids ) as $term_id ) {
			wp_delete_term( $term_id, 'venue' );
		}
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'START TRANSACTION' );
		parent::tearDown();
	}

	/** @return int[] */
	private function venue_ids(): array {
		$ids = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$this->assertNotWPError( $ids );

		return array_map( 'intval', $ids );
	}

	/**
	 * The production correction: venue term renamed and re-addressed, phone cleared.
	 */
	private function corrected_round_rock_amp(): int {
		$result = wp_insert_term( 'Round Rock Amp', 'venue' );
		$this->assertNotWPError( $result );
		$term_id = (int) $result['term_id'];

		$meta = array(
			'address'     => '3701 N IH-35',
			'city'        => 'Round Rock',
			'state'       => 'TX',
			'zip'         => '78665',
			'country'     => 'US',
			'coordinates' => '30.5530268,-97.6861697',
			'timezone'    => 'America/Chicago',
		);
		foreach ( $meta as $field => $value ) {
			update_term_meta( $term_id, Venue_Taxonomy::$meta_fields[ $field ], $value );
		}

		return $term_id;
	}

	/** Ticketmaster's wrong venue payload, as find_or_create_venue() receives it. */
	private function wrong_source_venue_data( bool $with_identity = true ): array {
		$data = array(
			'address'     => '301 W Bagdad Ave',
			'city'        => 'Round Rock',
			'state'       => 'TX',
			'zip'         => '78664',
			'country'     => 'US',
			'phone'       => '512-777-0873',
			'coordinates' => '30.523001000,-97.642799000',
		);
		if ( $with_identity ) {
			$data['source_identity'] = self::TM_ALIAS;
		}

		return $data;
	}

	/** Ticketmaster Discovery API event at the wrong venue record. */
	private function ticketmaster_api_event(): array {
		return array(
			'name'      => 'Jon Pardi w/ Alexandra Kay',
			'id'        => 'Z7r9jZ1AAZd8O',
			'url'       => 'https://www.ticketmaster.com/event/Z7r9jZ1AAZd8O',
			'dates'     => array(
				'start'  => array(
					'localDate' => '2099-11-07',
					'localTime' => '17:30:00',
				),
				'status' => array( 'code' => 'onsale' ),
			),
			'_embedded' => array(
				'venues' => array(
					array(
						'id'             => 'Z7r9jZa7-j',
						'name'           => 'Round Rock Amphitheater',
						'address'        => array( 'line1' => '301 W Bagdad Ave' ),
						'city'           => array( 'name' => 'Round Rock' ),
						'state'          => array( 'stateCode' => 'TX' ),
						'postalCode'     => '78664',
						'country'        => array( 'countryCode' => 'US' ),
						'timezone'       => 'America/Chicago',
						'boxOfficeInfo'  => array( 'phoneNumberDetail' => '512-777-0873' ),
						'location'       => array(
							'latitude'  => '30.523001000',
							'longitude' => '-97.642799000',
						),
					),
				),
			),
		);
	}

	private function map_ticketmaster_event( array $api_event ): array {
		$handler = new Ticketmaster();
		$method  = ( new ReflectionClass( $handler ) )->getMethod( 'map_ticketmaster_event' );
		$method->setAccessible( true );

		return $method->invoke( $handler, $api_event );
	}

	public function test_source_identity_alias_resolves_without_creating_or_merging(): void {
		$term_id = $this->corrected_round_rock_amp();
		$this->assertSame( self::TM_ALIAS, VenueSourceAliases::add( $term_id, self::TM_ALIAS ) );

		$meta_before  = get_term_meta( $term_id );
		$venues_before = $this->venue_ids();

		$result = Venue_Taxonomy::find_or_create_venue( 'Round Rock Amphitheater', $this->wrong_source_venue_data() );

		$this->assertSame( $term_id, $result['term_id'] );
		$this->assertFalse( $result['was_created'] );
		$this->assertSame( 'matched', $result['match_status'] );
		$this->assertSame( 'source_alias', $result['matched_via'] );
		$this->assertSame( $venues_before, $this->venue_ids(), 'No venue term may be created.' );
		$this->assertSame( $meta_before, get_term_meta( $term_id ), 'Wrong source data must not be merged, including fill-empty fields like phone.' );
		$this->assertSame( 'Round Rock Amp', get_term( $term_id, 'venue' )->name );
	}

	public function test_without_alias_the_wrong_payload_still_creates_a_separate_venue(): void {
		$term_id = $this->corrected_round_rock_amp();

		$result = Venue_Taxonomy::find_or_create_venue( 'Round Rock Amphitheater', $this->wrong_source_venue_data() );

		$this->assertNotSame( $term_id, $result['term_id'] );
		$this->assertTrue( $result['was_created'] );
		$this->assertSame( 'created', $result['match_status'] );
	}

	public function test_fingerprint_alias_resolves_sources_without_venue_ids(): void {
		$term_id = $this->corrected_round_rock_amp();
		$alias   = VenueSourceAliases::add( $term_id, 'fingerprint:Round Rock Amphitheater|301 W. Bagdad Avenue' );
		$this->assertSame( VenueSourceAliases::fingerprint( 'Round Rock Amphitheater', '301 W Bagdad Ave' ), $alias );

		$meta_before = get_term_meta( $term_id );
		$result      = Venue_Taxonomy::find_or_create_venue( 'Round Rock Amphitheater', $this->wrong_source_venue_data( false ) );

		$this->assertSame( $term_id, $result['term_id'] );
		$this->assertSame( 'source_alias', $result['matched_via'] );
		$this->assertSame( $meta_before, get_term_meta( $term_id ) );
	}

	public function test_fingerprint_requires_a_street_address(): void {
		$this->assertSame( '', VenueSourceAliases::fingerprint( 'Round Rock Amphitheater', 'Round Rock, TX 78664' ) );
		$this->assertSame( '', VenueSourceAliases::fingerprint( 'Round Rock Amphitheater', '' ) );
	}

	public function test_alias_owned_by_another_venue_is_rejected(): void {
		$term_id = $this->corrected_round_rock_amp();
		$this->assertSame( self::TM_ALIAS, VenueSourceAliases::add( $term_id, self::TM_ALIAS ) );
		$this->assertSame( self::TM_ALIAS, VenueSourceAliases::add( $term_id, self::TM_ALIAS ), 'Re-adding to the owner is idempotent.' );

		$other = wp_insert_term( 'Centennial Plaza Amphitheater', 'venue' );
		$this->assertNotWPError( $other );

		$error = VenueSourceAliases::add( (int) $other['term_id'], self::TM_ALIAS );

		$this->assertWPError( $error );
		$this->assertSame( 'venue_alias_conflict', $error->get_error_code() );
		$this->assertSame( array( self::TM_ALIAS ), VenueSourceAliases::get( $term_id ) );
		$this->assertSame( array(), VenueSourceAliases::get( (int) $other['term_id'] ) );
	}

	public function test_malformed_alias_is_rejected(): void {
		$term_id = $this->corrected_round_rock_amp();

		$this->assertWPError( VenueSourceAliases::add( $term_id, 'Round Rock Amphitheater' ) );
		$this->assertWPError( VenueSourceAliases::add( $term_id, 'fingerprint:no street separator' ) );
		$this->assertSame( array(), VenueSourceAliases::get( $term_id ) );
	}

	public function test_remove_alias_restores_normal_matching(): void {
		$term_id = $this->corrected_round_rock_amp();
		VenueSourceAliases::add( $term_id, self::TM_ALIAS );

		$this->assertTrue( VenueSourceAliases::remove( $term_id, self::TM_ALIAS ) );
		$this->assertFalse( VenueSourceAliases::remove( $term_id, self::TM_ALIAS ) );
		$this->assertNull( VenueSourceAliases::resolve( 'Round Rock Amphitheater', $this->wrong_source_venue_data() ) );
	}

	public function test_ticketmaster_mapping_carries_source_venue_identity(): void {
		$event = $this->map_ticketmaster_event( $this->ticketmaster_api_event() );

		$this->assertSame( self::TM_ALIAS, $event[ VenueSourceAliases::EVENT_FIELD ] );

		$engine = EventEngineData::buildEngineData( $event, ( new Ticketmaster() )->extractVenueMetadata( $event ) );
		$this->assertSame( self::TM_ALIAS, $engine[ VenueSourceAliases::EVENT_FIELD ] );
	}

	public function test_source_venue_identity_does_not_change_ticketmaster_revision(): void {
		$event = $this->map_ticketmaster_event( $this->ticketmaster_api_event() );
		$without_identity = $event;
		unset( $without_identity[ VenueSourceAliases::EVENT_FIELD ] );

		$this->assertSame( TicketmasterSourceIdentity::revision( $without_identity ), TicketmasterSourceIdentity::revision( $event ) );
	}

	public function test_aliased_ticketmaster_event_is_canonicalized_before_the_ai_packet(): void {
		$term_id = $this->corrected_round_rock_amp();
		VenueSourceAliases::add( $term_id, self::TM_ALIAS );
		$handler = new Ticketmaster();

		$event = $handler->applyVenueSourceAliases( $this->map_ticketmaster_event( $this->ticketmaster_api_event() ) );

		$this->assertSame( 'Round Rock Amp', $event['venue'] );
		$this->assertSame( '3701 N IH-35', $event['venueAddress'] );
		$this->assertSame( '78665', $event['venueZip'] );
		$this->assertSame( '30.5530268,-97.6861697', $event['venueCoordinates'] );
		$this->assertSame( '', $event['venuePhone'], 'A field the editor cleared stays cleared.' );
		$this->assertSame( self::TM_ALIAS, $event[ VenueSourceAliases::EVENT_FIELD ] );

		$engine  = EventEngineData::buildEngineData( $event, $handler->extractVenueMetadata( $event ) );
		$encoded = (string) wp_json_encode( $engine );
		$this->assertStringNotContainsString( 'Round Rock Amphitheater', $encoded );
		$this->assertStringNotContainsString( 'Bagdad', $encoded );
		$this->assertStringNotContainsString( '512-777-0873', $encoded );
	}

	public function test_unaliased_ticketmaster_event_is_unchanged(): void {
		$this->corrected_round_rock_amp();
		$event = $this->map_ticketmaster_event( $this->ticketmaster_api_event() );

		$this->assertSame( $event, ( new Ticketmaster() )->applyVenueSourceAliases( $event ) );
	}

	public function test_update_venue_source_aliases_ability(): void {
		$term_id   = $this->corrected_round_rock_amp();
		$abilities = new VenueAbilities();

		$result = $abilities->executeUpdateVenueSourceAliases(
			array(
				'venue' => (string) $term_id,
				'add'   => array( self::TM_ALIAS ),
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( array( self::TM_ALIAS ), $result['added'] );
		$this->assertSame( array( self::TM_ALIAS ), $result['source_aliases'] );
		$this->assertSame( array( self::TM_ALIAS ), $abilities->executeGetVenue( array( 'id' => $term_id ) )['source_aliases'] );

		$other = wp_insert_term( 'Centennial Plaza Amphitheater', 'venue' );
		$this->assertNotWPError( $other );
		$conflict = $abilities->executeUpdateVenueSourceAliases(
			array(
				'venue' => (string) $other['term_id'],
				'add'   => array( 'ticketmaster:CENTENNIAL', self::TM_ALIAS ),
			)
		);
		$this->assertWPError( $conflict );
		$this->assertSame( array(), VenueSourceAliases::get( (int) $other['term_id'] ), 'A rejected batch writes nothing.' );

		$removed = $abilities->executeUpdateVenueSourceAliases(
			array(
				'venue'  => (string) $term_id,
				'remove' => array( self::TM_ALIAS ),
			)
		);
		$this->assertSame( array( self::TM_ALIAS ), $removed['removed'] );
		$this->assertSame( array(), $removed['source_aliases'] );
	}
}
