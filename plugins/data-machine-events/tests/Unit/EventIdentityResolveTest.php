<?php
/**
 * Canonical EventIdentity::resolve seam (#4012).
 *
 * Every pre-write check — the pre-AI dedup gate, upsert advisory locks, and
 * the upsert itself — must get the same venue and duplicate answer for one
 * event. Before the seam each resolved the venue on its own: a pinned venue
 * was flattened to a name and re-resolved, so a same-named venue in another
 * city won or the name came back ambiguous (#3887), and re-imports with an
 * ambiguous venue name created duplicates (#3800, DME#883).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Core\EngineData;
use DataMachineEvents\Abilities\EventUpsertAbilities;
use DataMachineEvents\Core\DuplicateDetection\EventIdentity;
use DataMachineEvents\Core\DuplicateDetection\PreAIEventDedupGate;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachineEvents\Steps\Upsert\Events\EventTaxonomyAssigner;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;

class EventIdentityResolveTest extends EventUpsertTestCase {

	private int $columbus = 0;
	private int $nashville = 0;
	private string $venue_name = '';

	public function setUp(): void {
		parent::setUp();

		// One venue name, two cities: name-only resolution is ambiguous.
		$this->venue_name = 'The Basement ' . uniqid();
		$this->columbus   = $this->venue( $this->venue_name, 'Columbus', 'OH', 'the-basement-columbus-' . uniqid() );
		$this->nashville  = $this->venue( $this->venue_name, 'Nashville', 'TN', 'the-basement-nashville-' . uniqid() );
	}

	/**
	 * #3887: a pinned venue whose name matches venues in two cities. The
	 * gate, the lock keys, and the upsert all land on the pinned term.
	 */
	public function test_gate_locks_and_upsert_share_pinned_venue_term(): void {
		$this->assertSame(
			'ambiguous',
			Venue_Taxonomy::resolve_venue_identity( $this->venue_name, array() )['match_status'],
			'Precondition: the bare name is ambiguous across two cities.'
		);

		// The pinned flow venue now travels as a term ID, not just a name.
		$pinned = EventEngineData::buildConfiguredVenueEngineData( array( 'venue' => $this->columbus ) );
		$this->assertSame( $this->columbus, $pinned['venue_term_id'] ?? 0 );
		$this->assertSame( $this->venue_name, $pinned['venue'] ?? '' );

		$date      = self::future_year() . '-09-12';
		$title     = 'Night Moves ' . uniqid();
		$columbus  = $this->event( $title, $date . ' 20:00:00', $this->columbus );
		$nashville = $this->event( $title, $date . ' 20:00:00', $this->nashville );

		$engine = array_merge(
			$pinned,
			array(
				'title'       => $title,
				'startDate'   => $date,
				'startTime'   => '20:00',
				'flow_config' => array(
					'upsert' => array( 'handler_slugs' => array( 'upsert_event' ) ),
				),
			)
		);

		// Seam answer.
		$identity = EventIdentity::resolve(
			array(
				'title'     => $title,
				'startDate' => $date . ' 20:00',
			),
			new EngineData( $engine, 0 )
		);
		$this->assertSame( $this->columbus, $identity['venue_term_id'] );
		$this->assertSame( 'explicit', $identity['venue_match']['status'] );
		$this->assertSame( $columbus, $identity['post_id'] );

		// Pre-AI gate: skips against the Columbus event, never Nashville.
		$gate = PreAIEventDedupGate::check( null, new EngineData( $engine, null ), array(), 4012001 );
		$this->assertIsArray( $gate, 'The gate must recognise the existing event at the pinned venue.' );
		$this->assertTrue( $gate['skip'] );
		$this->assertStringContainsString( 'post ' . $columbus . ',', $gate['reason'] );

		// Lock keys acquired by the upsert are scoped to the pinned term.
		$acquired = array();
		$record   = static function ( string $query ) use ( &$acquired ): string {
			if ( str_contains( $query, 'GET_LOCK' ) && preg_match( "/'(dme:[0-9a-f]{32})'/", $query, $m ) ) {
				$acquired[] = $m[1];
			}
			return $query;
		};
		add_filter( 'query', $record, 5 );
		$result = $this->invoke_upsert(
			array_merge(
				$engine,
				array( 'venue' => $this->venue_name )
			)
		);
		remove_filter( 'query', $record, 5 );

		$build    = new \ReflectionMethod( $this->handler, 'buildUpsertLockKeys' );
		$build->setAccessible( true );
		$expected = $build->invoke( $this->handler, $title, $this->venue_name, $date . ' 20:00', '', array( 'address' => '', 'city' => '', 'state' => '', 'country' => '' ), 0, $this->columbus );
		$sorted   = $acquired;
		sort( $sorted, SORT_STRING );
		$this->assertSame( $expected, $sorted, 'Upsert locks must be keyed on the same venue term as the gate.' );
		$this->assertNotSame(
			$expected,
			$build->invoke( $this->handler, $title, $this->venue_name, $date . ' 20:00', '', array(), 0, $this->nashville )
		);

		// Upsert: updates the Columbus event and keeps its venue.
		$this->assertTrue( $result['success'], (string) ( $result['error'] ?? '' ) );
		$this->assertSame( $columbus, (int) $result['data']['post_id'] );
		$this->assertNotSame( $nashville, (int) $result['data']['post_id'] );
		$this->assertSame( array( $this->columbus ), wp_get_object_terms( $columbus, 'venue', array( 'fields' => 'ids' ) ) );
		$this->assertSame( 2, $this->countEventsWithTitle( $title ) );
	}

	/**
	 * An explicit venue_term_id is used as-is, even when the event's venue
	 * name and geography exactly match a different venue.
	 */
	public function test_explicit_venue_term_is_never_overridden_by_name_match(): void {
		$other = $this->venue( 'Exact Name Hall ' . uniqid(), 'Denver', 'CO' );
		$name  = get_term( $other, 'venue' )->name;
		$this->assertSame(
			$other,
			(int) Venue_Taxonomy::resolve_venue_identity( $name, array( 'city' => 'Denver', 'state' => 'CO' ) )['term_id'],
			'Precondition: the name and city uniquely match another venue.'
		);

		$fields = array(
			'title'         => 'Explicit Venue Show',
			'venue'         => $name,
			'venueCity'     => 'Denver',
			'venueState'    => 'CO',
			'venue_term_id' => $this->columbus,
		);
		$engine = new EngineData( $fields, 0 );

		$venue = EventIdentity::resolveVenue( $fields, $engine );
		$this->assertSame( $this->columbus, $venue['venue_term_id'] );
		$this->assertSame( 'explicit_term', $venue['venue_match']['tier'] );

		$this->assertSame( $this->columbus, EventIdentity::resolve( $fields, $engine, array( 'duplicates' => false ) )['venue_term_id'] );
		$this->assertSame( $this->columbus, ( new EventTaxonomyAssigner() )->resolveVenue( $fields, $engine )['term_id'] );

		// A caller-persisted term is equally authoritative.
		$this->assertSame( $this->nashville, EventIdentity::resolveVenue( $fields, new EngineData( array( 'venue' => $name ), 0 ), array( 'venue_term_id' => $this->nashville ) )['venue_term_id'] );

		// An ID that is not a venue term falls back to the name.
		$invalid = array_merge( $fields, array( 'venue_term_id' => 999999 ) );
		$this->assertSame( $other, EventIdentity::resolveVenue( $invalid, new EngineData( $invalid, 0 ) )['venue_term_id'] );
	}

	/**
	 * #3800 / DME#883: re-importing an event this source already owns, with
	 * a venue name that is ambiguous, resolves to the existing post — at the
	 * seam, the gate, the ability's venue validation, and the upsert.
	 */
	public function test_reimport_with_ambiguous_venue_resolves_to_existing_post(): void {
		$date      = self::future_year() . '-10-03';
		$title     = 'Ambiguous Return ' . uniqid();
		$source    = 'venue_feed';
		$source_id = 'evt-' . uniqid();
		$identity  = hash( 'sha256', $source . "\0" . $source_id );
		$existing  = $this->event( $title, $date . ' 21:00:00', $this->columbus );
		update_post_meta( $existing, EventUpsert::SOURCE_IDENTITY_META_KEY, $identity );
		update_post_meta( $existing, EventUpsert::SOURCE_NAME_META_KEY, $source );
		update_post_meta( $existing, EventUpsert::SOURCE_ID_META_KEY, $source_id );

		$engine = array(
			'title'           => $title,
			'venue'           => $this->venue_name,
			'startDate'       => $date,
			'startTime'       => '21:00',
			'source_type'     => $source,
			'item_identifier' => $source_id,
			'flow_config'     => array(
				'upsert' => array( 'handler_slugs' => array( 'upsert_event' ) ),
			),
		);

		$resolved = EventIdentity::resolve(
			array(
				'title'     => $title,
				'startDate' => $date . ' 21:00',
			),
			new EngineData( $engine, 0 )
		);
		$this->assertSame( $existing, $resolved['post_id'] );
		$this->assertSame( 'source_identity', $resolved['strategy'] );
		$this->assertSame( 'ambiguous', $resolved['venue_match']['status'] );
		$this->assertSame( $this->columbus, $resolved['venue_term_id'], 'An ambiguous name keeps the claimed event venue.' );

		$gate = PreAIEventDedupGate::check( null, new EngineData( $engine, null ), array(), 4012003 );
		$this->assertIsArray( $gate );
		$this->assertStringContainsString( 'post ' . $existing . ',', $gate['reason'] );

		$validate = new \ReflectionMethod( EventUpsertAbilities::class, 'validateVenueIdentity' );
		$validate->setAccessible( true );
		$event    = array(
			'title'     => $title,
			'venue'     => $this->venue_name,
			'startDate' => $date,
		);
		$this->assertNull( $validate->invoke( new EventUpsertAbilities(), $event, $source, $source_id ) );
		$unclaimed = $validate->invoke( new EventUpsertAbilities(), $event, $source, 'never-imported-' . uniqid() );
		$this->assertWPError( $unclaimed, 'An unclaimed event with an ambiguous venue is still rejected.' );
		$this->assertSame( 'ambiguous_venue', $unclaimed->get_error_code() );

		$result = $this->invoke_upsert( $engine );
		$this->assertTrue( $result['success'], (string) ( $result['error'] ?? '' ) );
		$this->assertSame( $existing, (int) $result['data']['post_id'] );
		$this->assertSame( 1, $this->countEventsWithTitle( $title ) );
		$this->assertSame( array( $this->columbus ), wp_get_object_terms( $existing, 'venue', array( 'fields' => 'ids' ) ) );
	}

	private function venue( string $name, string $city, string $state, string $slug = '' ): int {
		$term = wp_insert_term( $name, 'venue', '' !== $slug ? array( 'slug' => $slug ) : array() );
		$this->assertNotWPError( $term );
		$term_id = (int) $term['term_id'];
		update_term_meta( $term_id, '_venue_city', $city );
		update_term_meta( $term_id, '_venue_state', $state );
		update_term_meta( $term_id, '_venue_country', 'US' );

		return $term_id;
	}

	private function event( string $title, string $start, int $venue_term_id ): int {
		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );
		wp_set_object_terms( $post_id, array( $venue_term_id ), 'venue' );
		EventDatesTable::upsert( $post_id, $start );

		return (int) $post_id;
	}
}
