<?php
/**
 * EventUpsertAbilities tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\AbilityPermissions;
use DataMachineEvents\Abilities\EventUpsertAbilities;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;
use WP_UnitTestCase;

class EventUpsertAbilitiesTest extends WP_UnitTestCase {

	private EventUpsertAbilities $ability;
	private \Closure $lock_query_filter;

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->lock_query_filter = static function ( string $query ): string {
			if ( str_contains( $query, 'GET_LOCK' ) || str_contains( $query, 'RELEASE_LOCK' ) ) {
				return 'SELECT 1';
			}

			return $query;
		};
		add_filter( 'query', $this->lock_query_filter );

		$this->ability = new EventUpsertAbilities();
	}

	public function tearDown(): void {
		remove_filter( 'query', $this->lock_query_filter );
		remove_all_filters( 'datamachine_events_upsert_event_permission' );
		remove_all_filters( 'datamachine_events_before_event_upsert_persistence' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_upsert_permission_uses_shared_write_gate_by_default(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$this->assertTrue( $this->ability->canUpsertEvent( $this->validInput() ) );
	}

	public function test_upsert_permission_denies_callers_without_write_access(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->assertFalse( $this->ability->canUpsertEvent( $this->validInput() ) );
	}

	public function test_upsert_permission_can_narrowly_grant_from_input(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$input = $this->validInput();

		add_filter(
			'datamachine_events_upsert_event_permission',
			static function ( bool $allowed, array $candidate ) use ( $input ): bool {
				return $allowed || $input['event']['venue'] === ( $candidate['event']['venue'] ?? '' );
			},
			10,
			2
		);

		$this->assertTrue( $this->ability->canUpsertEvent( $input ) );
	}

	public function test_upsert_permission_filter_does_not_widen_other_write_abilities(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		add_filter( 'datamachine_events_upsert_event_permission', '__return_true' );

		$can_write = AbilityPermissions::canWrite();

		$this->assertTrue( $this->ability->canUpsertEvent( $this->validInput() ) );
		$this->assertFalse( $can_write() );
	}

	public function test_creates_canonical_event_and_returns_normalized_metadata(): void {
		$result = $this->ability->executeUpsertEvent( $this->validInput() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'created', $result['action'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['fingerprint'] );
		$this->assertGreaterThan( 0, $result['event_id'] );
		$this->assertSame( 'publish', $result['normalized']['post_status'] );
		$this->assertSame( '2027-02-20 20:00:00', $result['normalized']['start_datetime'] );
		$this->assertGreaterThan( 0, $result['normalized']['venue_id'] );
		$this->assertSame(
			hash( 'sha256', $result['source']['name'] . "\0" . $result['source']['id'] ),
			get_post_meta( $result['event_id'], EventUpsert::SOURCE_IDENTITY_META_KEY, true )
		);
		$this->assertStringContainsString( 'wp:data-machine-events/event-details', get_post_field( 'post_content', $result['event_id'] ) );
	}

	public function test_event_candidate_is_optional_positive_integer_in_public_schema(): void {
		$method = new \ReflectionMethod( $this->ability, 'getInputSchema' );
		$method->setAccessible( true );
		$schema = $method->invoke( $this->ability );

		$this->assertArrayNotHasKey( 'event_id', array_flip( $schema['required'] ) );
		$this->assertSame( 'integer', $schema['properties']['event_id']['type'] );
		$this->assertSame( 1, $schema['properties']['event_id']['minimum'] );

		$input             = $this->validInput();
		$input['event_id'] = '12';
		$result            = $this->ability->executeUpsertEvent( $input );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_event_candidate', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_adopts_existing_event_candidate_and_replay_is_idempotent(): void {
		$candidate_id      = $this->createEventCandidate( 'Legacy Candidate ' . uniqid() );
		$input             = $this->validInput();
		$input['event_id'] = $candidate_id;

		$first  = $this->ability->executeUpsertEvent( $input );
		$second = $this->ability->executeUpsertEvent( $input );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertSame( $candidate_id, $first['event_id'] );
		$this->assertSame( $candidate_id, $second['event_id'] );
		$this->assertContains( $first['action'], array( 'updated', 'no_change' ) );
		$this->assertSame( 'no_change', $second['action'] );
		$this->assertSame( $input['source'], get_post_meta( $candidate_id, EventUpsert::SOURCE_NAME_META_KEY, true ) );
		$this->assertSame( $input['source_id'], get_post_meta( $candidate_id, EventUpsert::SOURCE_ID_META_KEY, true ) );
		$this->assertSame( hash( 'sha256', $input['source'] . "\0" . $input['source_id'] ), get_post_meta( $candidate_id, EventUpsert::SOURCE_IDENTITY_META_KEY, true ) );
		$this->assertReservationComplete( $candidate_id, $input );
	}

	public function test_missing_and_wrong_type_event_candidates_fail_without_mutation(): void {
		$input             = $this->validInput();
		$input['event_id'] = 999999999;
		$missing           = $this->ability->executeUpsertEvent( $input );

		$this->assertWPError( $missing );
		$this->assertSame( 'event_candidate_not_found', $missing->get_error_code() );
		$this->assertSame( 404, $missing->get_error_data()['status'] );
		$this->assertSame( 0, $this->countEventsWithTitle( $input['event']['title'] ) );

		$post_id           = self::factory()->post->create( array( 'post_title' => 'Wrong Candidate Type' ) );
		$input             = $this->validInput();
		$input['event_id'] = $post_id;
		$wrong_type        = $this->ability->executeUpsertEvent( $input );

		$this->assertWPError( $wrong_type );
		$this->assertSame( 'event_candidate_wrong_post_type', $wrong_type->get_error_code() );
		$this->assertSame( 409, $wrong_type->get_error_data()['status'] );
		$this->assertSame( 'Wrong Candidate Type', get_post_field( 'post_title', $post_id ) );
		$this->assertSame( '', get_post_meta( $post_id, EventUpsert::SOURCE_IDENTITY_META_KEY, true ) );
		$this->assertSame( 0, $this->countEventsWithTitle( $input['event']['title'] ) );
	}

	public function test_different_source_claimant_rejects_event_candidate_without_mutation(): void {
		$input             = $this->validInput();
		$claimed           = $this->ability->executeUpsertEvent( $input );
		$candidate_id      = $this->createEventCandidate( 'Unclaimed Candidate ' . uniqid() );
		$original          = get_post_field( 'post_title', $candidate_id );
		$input['event_id'] = $candidate_id;

		$result = $this->ability->executeUpsertEvent( $input );

		$this->assertWPError( $result );
		$this->assertSame( 'event_source_claimant_conflict', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( $claimed['event_id'], $result->get_error_data()['claimant_id'] );
		$this->assertSame( $original, get_post_field( 'post_title', $candidate_id ) );
		$this->assertSame( '', get_post_meta( $candidate_id, EventUpsert::SOURCE_IDENTITY_META_KEY, true ) );
		$this->assertReservationComplete( $claimed['event_id'], $input );
	}

	public function test_ambiguous_legacy_source_claimants_fail_without_mutation(): void {
		$input           = $this->validInput();
		$identity        = hash( 'sha256', $input['source'] . "\0" . $input['source_id'] );
		$first_id        = $this->createEventCandidate( 'First Legacy Claimant ' . uniqid() );
		$second_id       = $this->createEventCandidate( 'Second Legacy Claimant ' . uniqid() );
		$original_titles = array(
			$first_id  => get_post_field( 'post_title', $first_id ),
			$second_id => get_post_field( 'post_title', $second_id ),
		);
		add_post_meta( $first_id, EventUpsert::SOURCE_IDENTITY_META_KEY, $identity );
		add_post_meta( $second_id, EventUpsert::SOURCE_IDENTITY_META_KEY, $identity );

		$result            = $this->ability->executeUpsertEvent( $input );
		$input['event_id'] = $first_id;
		$candidate_result  = $this->ability->executeUpsertEvent( $input );

		$this->assertWPError( $result );
		$this->assertSame( 'event_source_claimant_ambiguous', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertWPError( $candidate_result );
		$this->assertSame( 'event_source_claimant_ambiguous', $candidate_result->get_error_code() );
		$this->assertSame( 409, $candidate_result->get_error_data()['status'] );
		$claimant_ids = array( $first_id, $second_id );
		sort( $claimant_ids, SORT_NUMERIC );
		$this->assertSame( $claimant_ids, $result->get_error_data()['claimant_ids'] );
		foreach ( $original_titles as $event_id => $title ) {
			$this->assertSame( $title, get_post_field( 'post_title', $event_id ) );
			$this->assertSame( array( $identity ), get_post_meta( $event_id, EventUpsert::SOURCE_IDENTITY_META_KEY, false ) );
		}
		$this->assertSame( 0, $this->countEventsWithTitle( $input['event']['title'] ) );
		$this->assertReservationMissing( $input );
	}

	public function test_conflicting_candidate_source_metadata_fails_without_mutation(): void {
		foreach ( array( EventUpsert::SOURCE_IDENTITY_META_KEY, EventUpsert::SOURCE_NAME_META_KEY, EventUpsert::SOURCE_ID_META_KEY ) as $meta_key ) {
			$input             = $this->validInput();
			$candidate_id      = $this->createEventCandidate( 'Conflicting Candidate ' . uniqid() );
			$original          = get_post_field( 'post_title', $candidate_id );
			update_post_meta( $candidate_id, $meta_key, 'different-canonical-value' );
			$input['event_id'] = $candidate_id;

			$result = $this->ability->executeUpsertEvent( $input );

			$this->assertWPError( $result );
			$this->assertSame( 'event_candidate_source_metadata_conflict', $result->get_error_code() );
			$this->assertSame( 409, $result->get_error_data()['status'] );
			$this->assertSame( $meta_key, $result->get_error_data()['meta_key'] );
			$this->assertSame( $original, get_post_field( 'post_title', $candidate_id ) );
			$this->assertSame( 'different-canonical-value', get_post_meta( $candidate_id, $meta_key, true ) );
			$this->assertSame( 0, $this->countEventsWithTitle( $input['event']['title'] ) );
			$this->assertReservationMissing( $input );
		}
	}

	public function test_mixed_duplicate_candidate_metadata_cannot_hide_conflict(): void {
		foreach ( array( EventUpsert::SOURCE_IDENTITY_META_KEY, EventUpsert::SOURCE_NAME_META_KEY, EventUpsert::SOURCE_ID_META_KEY ) as $meta_key ) {
			$input           = $this->validInput();
			$candidate_id    = $this->createEventCandidate( 'Mixed Metadata Candidate ' . uniqid() );
			$original_title  = get_post_field( 'post_title', $candidate_id );
			$expected_values = array(
				EventUpsert::SOURCE_IDENTITY_META_KEY => hash( 'sha256', $input['source'] . "\0" . $input['source_id'] ),
				EventUpsert::SOURCE_NAME_META_KEY     => $input['source'],
				EventUpsert::SOURCE_ID_META_KEY       => $input['source_id'],
			);
			$original_values = array( $expected_values[ $meta_key ], 'conflicting-row', $expected_values[ $meta_key ] );
			foreach ( $original_values as $value ) {
				add_post_meta( $candidate_id, $meta_key, $value );
			}
			$input['event_id'] = $candidate_id;

			$result = $this->ability->executeUpsertEvent( $input );

			$this->assertWPError( $result );
			$this->assertSame( 'event_candidate_source_metadata_conflict', $result->get_error_code() );
			$this->assertSame( 409, $result->get_error_data()['status'] );
			$this->assertSame( $meta_key, $result->get_error_data()['meta_key'] );
			$this->assertSame( $original_title, get_post_field( 'post_title', $candidate_id ) );
			$this->assertSame( $original_values, get_post_meta( $candidate_id, $meta_key, false ) );
			$this->assertSame( 0, $this->countEventsWithTitle( $input['event']['title'] ) );
			$this->assertReservationMissing( $input );
		}
	}

	public function test_replay_of_source_identity_returns_same_event(): void {
		$input  = $this->validInput();
		$input['event']['price']             = '$25';
		$input['event']['priceCurrency']     = 'USD';
		$input['event']['offerAvailability'] = 'InStock';
		$input['event']['validFrom']         = '2027-01-20T10:30:00-05:00';
		$input['event']['eventType']         = 'MusicEvent';
		$first  = $this->ability->executeUpsertEvent( $input );
		$second = $this->ability->executeUpsertEvent( $input );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertSame( $first['event_id'], $second['event_id'] );
		$this->assertSame( 'no_change', $second['action'] );
		$this->assertSame( $first['fingerprint'], $second['fingerprint'] );
		$attrs = parse_blocks( get_post_field( 'post_content', $first['event_id'] ) )[0]['attrs'];
		$this->assertSame( '2027-01-20T10:30:00-05:00', $attrs['validFrom'] );
		$this->assertSame( 'MusicEvent', $attrs['eventType'] );
		$this->assertSame( '$25', $attrs['price'] );
		$this->assertSame( 'USD', $attrs['priceCurrency'] );
		$this->assertSame( 'InStock', $attrs['offerAvailability'] );
	}

	public function test_public_upsert_forwards_exact_source_hash_as_identity_meta(): void {
		$input      = $this->validInput();
		$expected   = hash( 'sha256', $input['source'] . "\0" . $input['source_id'] );
		$forwarded  = null;
		$ability    = wp_get_ability( 'datamachine/upsert-post' );
		$property   = new \ReflectionProperty( \WP_Ability::class, 'execute_callback' );
		$callback   = $property->getValue( $ability );
		$property->setValue(
			$ability,
			static function ( array $upsert_input ) use ( &$forwarded ): array {
				$forwarded = $upsert_input;
				$post_id   = wp_insert_post(
					array(
						'post_type'    => $upsert_input['post_type'],
						'post_title'   => $upsert_input['title'],
						'post_content' => $upsert_input['content'],
						'post_status'  => $upsert_input['post_status'],
						'meta_input'   => $upsert_input['meta_input'],
					),
					true
				);

				return array( 'success' => true, 'post_id' => (int) $post_id, 'action' => 'created' );
			}
		);

		try {
			$result = $this->ability->executeUpsertEvent( $input );
		} finally {
			$property->setValue( $ability, $callback );
		}

		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				'key'   => EventUpsert::SOURCE_IDENTITY_META_KEY,
				'value' => $expected,
			),
			$forwarded['identity_meta']
		);
	}

	public function test_schema_fields_update_same_source_event_without_changing_duplicate_identity(): void {
		$input = $this->validInput();
		$first = $this->ability->executeUpsertEvent( $input );

		$input['event']['validFrom'] = '2027-01-20T10:30:00Z';
		$input['event']['eventType'] = 'Festival';
		$second                      = $this->ability->executeUpsertEvent( $input );

		$this->assertSame( $first['event_id'], $second['event_id'] );
		$this->assertSame( 'updated', $second['action'] );
		$this->assertNotSame( $first['fingerprint'], $second['fingerprint'] );
		$this->assertSame( 1, $this->countEventsWithTitle( $input['event']['title'] ) );
	}

	public function test_omitted_legacy_fields_remain_omitted_and_idempotent(): void {
		$input  = $this->validInput();
		$first  = $this->ability->executeUpsertEvent( $input );
		$second = $this->ability->executeUpsertEvent( $input );
		$attrs  = parse_blocks( get_post_field( 'post_content', $first['event_id'] ) )[0]['attrs'];

		$this->assertSame( 'no_change', $second['action'] );
		$this->assertArrayNotHasKey( 'validFrom', $attrs );
		$this->assertArrayNotHasKey( 'eventType', $attrs );
	}

	public function test_malformed_canonical_schema_fields_fail_before_write(): void {
		foreach ( array(
			array( 'validFrom', '2027-02-30T10:30:00Z', 'invalid_valid_from' ),
			array( 'validFrom', 'next Tuesday', 'invalid_valid_from' ),
			array( 'validFrom', '2027-01-20T10:30:00+24:00', 'invalid_valid_from' ),
			array( 'validFrom', array( '2027-01-20T10:30:00Z' ), 'invalid_valid_from' ),
			array( 'eventType', 'Concert', 'invalid_event_type' ),
			array( 'eventType', array( 'MusicEvent' ), 'invalid_event_type' ),
		) as [$field, $value, $error_code] ) {
			$input                    = $this->validInput();
			$input['event'][ $field ] = $value;
			$result                   = $this->ability->executeUpsertEvent( $input );

			$this->assertWPError( $result );
			$this->assertSame( $error_code, $result->get_error_code() );
			$this->assertSame( 0, $this->countEventsWithTitle( $input['event']['title'] ) );
		}
	}

	public function test_validation_failure_returns_machine_readable_error_without_write(): void {
		$input = $this->validInput();
		unset( $input['event']['startDate'] );

		$result = $this->ability->executeUpsertEvent( $input );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_start_date', $result->get_error_code() );
		$this->assertSame( 0, $this->countEventsWithTitle( $input['event']['title'] ) );
	}

	public function test_ambiguous_venue_resolution_fails_before_event_write(): void {
		$input = $this->validInput();
		$name  = $input['event']['venue'];

		$this->assertNotWPError( wp_insert_term( $name . '!', 'venue' ) );
		$this->assertNotWPError( wp_insert_term( $name . '?', 'venue' ) );

		$result = $this->ability->executeUpsertEvent( $input );

		$this->assertWPError( $result );
		$this->assertSame( 'ambiguous_venue', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 0, $this->countEventsWithTitle( $input['event']['title'] ) );
	}

	public function test_lock_timeout_preserves_retryable_transient_contract(): void {
		$force_timeout = static function ( string $query ): string {
			return str_contains( $query, 'GET_LOCK' ) ? 'SELECT 0' : $query;
		};
		add_filter( 'query', $force_timeout, 9 );

		$result = $this->ability->executeUpsertEvent( $this->validInput() );

		remove_filter( 'query', $force_timeout, 9 );
		$this->assertWPError( $result );
		$this->assertSame( 'event_upsert_lock_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] ?? null );
		$this->assertTrue( $result->get_error_data()['retryable'] ?? false );
		$this->assertTrue( $result->get_error_data()['transient'] ?? false );
	}

	public function test_persistence_conflict_preserves_code_and_status(): void {
		add_filter( 'datamachine_events_before_event_upsert_persistence', static fn() => new \WP_Error( 'canonical_event_booking_conflict', 'Conflict.', array( 'status' => 409, 'conflict' => array( 'id' => 44 ), 'database_error' => 'conflict detail' ) ) );

		$result = $this->ability->executeUpsertEvent( $this->validInput() );

		$this->assertWPError( $result );
		$this->assertSame( 'canonical_event_booking_conflict', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertSame( array( 'id' => 44 ), $result->get_error_data()['conflict'] );
		$this->assertSame( 'conflict detail', $result->get_error_data()['database_error'] );
	}

	public function test_persistence_timeout_preserves_retryable_503(): void {
		add_filter( 'datamachine_events_before_event_upsert_persistence', static fn() => new \WP_Error( 'canonical_event_booking_lock_not_acquired', 'Busy.', array( 'status' => 503, 'retryable' => true ) ) );

		$result = $this->ability->executeUpsertEvent( $this->validInput() );

		$this->assertWPError( $result );
		$this->assertSame( 'canonical_event_booking_lock_not_acquired', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertTrue( $result->get_error_data()['retryable'] );
	}

	private function validInput(): array {
		$suffix = uniqid();

		return array(
			'source'    => 'unit-test-source',
			'source_id' => 'event-' . $suffix,
			'event'     => array(
				'title'        => 'Public Ability Event ' . $suffix,
				'description'  => '<p>Canonical event body.</p>',
				'startDate'    => '2027-02-20',
				'startTime'    => '20:00',
				'venue'        => 'Public Ability Venue ' . $suffix,
				'venueAddress' => '300 King Street',
				'venueCity'    => 'Charleston',
				'venueState'   => 'SC',
				'venueCountry' => 'US',
				'performer'    => 'Ability Performer',
				'ticketUrl'    => 'https://tickets.example/event-' . $suffix,
				'eventStatus'  => 'EventScheduled',
			),
		);
	}

	private function createEventCandidate( string $title ): int {
		return self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	private function assertReservationComplete( int $event_id, array $input ): void {
		$reservation = $this->reservationForInput( $input );
		$this->assertNotNull( $reservation, 'Data Machine must reserve an adopted event identity.' );
		$this->assertSame( 'complete', $reservation['state'] );
		$this->assertSame( $event_id, (int) $reservation['post_id'] );
	}

	private function assertReservationMissing( array $input ): void {
		$this->assertNull( $this->reservationForInput( $input ), 'A rejected event identity must not create a reservation.' );
	}

	private function reservationForInput( array $input ): ?array {
		$this->assertTrue(
			class_exists( '\\DataMachine\\Core\\Database\\PostIdentityReservations\\PostIdentityReservations' ),
			'Data Machine #3408 reservation infrastructure is required.'
		);
		$repository = new \DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations();
		$identity   = $repository::normalize_identity(
			Event_Post_Type::POST_TYPE,
			array(
				'key'   => EventUpsert::SOURCE_IDENTITY_META_KEY,
				'value' => hash( 'sha256', $input['source'] . "\0" . $input['source_id'] ),
			)
		);
		if ( is_wp_error( $identity ) ) {
			$this->fail( $identity->get_error_message() );
		}

		return $repository->get_reservation( $identity['identity_hash'] );
	}

	private function countEventsWithTitle( string $title ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => Event_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'title'          => $title,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		return count( $query->posts );
	}
}
