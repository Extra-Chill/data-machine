<?php
/**
 * EventUpdateAbilities tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\EventUpdateAbilities;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use WP_Error;
use WP_UnitTestCase;

class EventUpdateAbilitiesTest extends WP_UnitTestCase {

	private EventUpdateAbilities $ability;

	public function setUp(): void {
		parent::setUp();

		$this->registerEventObjects();
		$this->ability = new EventUpdateAbilities();
	}

	public function tearDown(): void {
		remove_all_filters( 'datamachine_events_before_event_venue_mutation' );
		remove_all_actions( 'datamachine_events_after_event_venue_mutation' );
		remove_all_filters( 'datamachine_events_before_event_update_persistence' );
		remove_all_actions( 'datamachine_events_after_event_update_persistence' );
		remove_all_filters( 'wp_insert_post_empty_content' );
		$this->registerEventObjects();
		parent::tearDown();
	}

	public function test_venue_mutation_success_assigns_term_and_returns_taxonomy_result(): void {
		$event_id = $this->makeEvent();
		$venue    = $this->makeVenue( 'Success Venue' );
		$payload  = null;

		add_action(
			'datamachine_events_after_event_venue_mutation',
			static function ( int $post_id, array $next_ids, array $previous_ids, string $context, $mutation_result ) use ( &$payload ): void {
				$payload = array( $post_id, $next_ids, $previous_ids, $context, $mutation_result );
			},
			10,
			5
		);

		$response = $this->ability->executeUpdateEvent(
			array(
				'event' => $event_id,
				'venue' => $venue,
			)
		);

		$this->assertSame( 'updated', $response['results'][0]['status'] );
		$this->assertSame( array( $venue ), wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) ) );
		$this->assertSame(
			array( $event_id, array( $venue ), array(), 'event_update_ability', array( (int) get_term( $venue, 'venue' )->term_taxonomy_id ) ),
			$payload
		);
	}

	public function test_venue_mutation_hooks_receive_exact_payload_in_order(): void {
		$event_id      = $this->makeEvent();
		$previous      = $this->makeVenue( 'Previous Venue' );
		$next          = $this->makeVenue( 'Next Venue' );
		$observed      = array();
		$before_result = null;
		wp_set_post_terms( $event_id, array( $previous ), 'venue' );

		add_filter(
			'datamachine_events_before_event_venue_mutation',
			static function ( $allowed, int $post_id, array $next_ids, array $previous_ids, string $context ) use ( &$observed, &$before_result ) {
				$before_result = $allowed;
				$observed[]    = array( 'before', $post_id, $next_ids, $previous_ids, $context );
				return $allowed;
			},
			10,
			5
		);
		add_action(
			'datamachine_events_after_event_venue_mutation',
			static function ( int $post_id, array $next_ids, array $previous_ids, string $context, $result ) use ( &$observed ): void {
				$observed[] = array( 'after', $post_id, $next_ids, $previous_ids, $context, $result );
			},
			10,
			5
		);

		$this->ability->executeUpdateEvent( array( 'event' => $event_id, 'venue' => $next ) );

		$this->assertTrue( $before_result );
		$this->assertSame(
			array(
				array( 'before', $event_id, array( $next ), array( $previous ), 'event_update_ability' ),
				array( 'after', $event_id, array( $next ), array( $previous ), 'event_update_ability', array( (int) get_term( $next, 'venue' )->term_taxonomy_id ) ),
			),
			$observed
		);
	}

	public function test_venue_mutation_hooks_normalize_string_and_invalid_previous_ids(): void {
		$event_id = $this->makeEvent();
		$previous = $this->makeVenue( 'String Previous Venue' );
		$next     = $this->makeVenue( 'Integer Next Venue' );
		$observed = array();
		wp_set_post_terms( $event_id, array( $previous ), 'venue' );

		$stringify_ids = static function ( array $terms, array $object_ids, array $taxonomies, array $args ) use ( $previous ): array {
			if ( array( 'venue' ) === $taxonomies && 'ids' === $args['fields'] ) {
				return array( (string) $previous, '0', 'invalid', (string) $previous );
			}

			return $terms;
		};
		add_filter( 'get_object_terms', $stringify_ids, 10, 4 );
		add_filter(
			'datamachine_events_before_event_venue_mutation',
			static function ( $allowed, int $post_id, array $next_ids, array $previous_ids ) use ( &$observed ) {
				$observed[] = array( 'before', $post_id, $next_ids, $previous_ids );
				return $allowed;
			},
			10,
			4
		);
		add_action(
			'datamachine_events_after_event_venue_mutation',
			static function ( int $post_id, array $next_ids, array $previous_ids, string $context, $result ) use ( &$observed ): void {
				$observed[] = array( 'after', $post_id, $next_ids, $previous_ids, $result );
			},
			10,
			5
		);

		try {
			$this->ability->executeUpdateEvent( array( 'event' => $event_id, 'venue' => $next ) );
		} finally {
			remove_filter( 'get_object_terms', $stringify_ids, 10 );
		}

		$this->assertSame(
			array(
				array( 'before', $event_id, array( $next ), array( $previous ) ),
				array( 'after', $event_id, array( $next ), array( $previous ), array( (int) get_term( $next, 'venue' )->term_taxonomy_id ) ),
			),
			$observed
		);
	}

	public function test_venue_mutation_preflight_error_is_surfaced(): void {
		$event_id = $this->makeEvent();
		$venue    = $this->makeVenue( 'Denied Venue' );
		$denial   = new WP_Error( 'venue_mutation_denied', 'Venue mutation denied.' );

		add_filter(
			'datamachine_events_before_event_venue_mutation',
			static fn() => $denial
		);

		$response = $this->ability->executeUpdateEvent( array( 'event' => $event_id, 'venue' => $venue ) );

		$this->assertSame( 'failed', $response['results'][0]['status'] );
		$this->assertSame( 'venue_mutation_denied', $response['results'][0]['error_code'] );
		$this->assertSame( 'Venue mutation denied.', $response['results'][0]['error'] );
	}

	public function test_false_venue_mutation_preflight_denies_without_assignment(): void {
		$event_id = $this->makeEvent();
		$previous = $this->makeVenue( 'False Denial Previous Venue' );
		$next     = $this->makeVenue( 'False Denial Next Venue' );
		$after    = array();
		wp_set_post_terms( $event_id, array( $previous ), 'venue' );
		add_filter( 'datamachine_events_before_event_venue_mutation', '__return_false' );
		add_action( 'datamachine_events_after_event_venue_mutation', static function ( int $post_id, array $next_ids, array $previous_ids, string $context, $result ) use ( &$after ): void { $after[] = $result; }, 10, 5 );

		$response = $this->ability->executeUpdateEvent( array( 'event' => $event_id, 'venue' => $next ) );

		$this->assertSame( 'failed', $response['results'][0]['status'] );
		$this->assertSame( 'event_venue_mutation_denied', $response['results'][0]['error_code'] );
		$this->assertSame( 403, $response['results'][0]['error_status'] );
		$this->assertSame( array( $previous ), wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) ) );
		$this->assertCount( 1, $after );
		$this->assertWPError( $after[0] );
	}

	public function test_venue_mutation_denial_does_not_persist_and_after_fires_once(): void {
		$event_id = $this->makeEvent();
		$previous = $this->makeVenue( 'Retained Venue' );
		$next     = $this->makeVenue( 'Rejected Venue' );
		$denial   = new WP_Error( 'venue_mutation_denied', 'Venue mutation denied.' );
		$results  = array();
		wp_set_post_terms( $event_id, array( $previous ), 'venue' );

		add_filter( 'datamachine_events_before_event_venue_mutation', static fn() => $denial );
		add_action(
			'datamachine_events_after_event_venue_mutation',
			static function ( int $post_id, array $next_ids, array $previous_ids, string $context, $result ) use ( &$results ): void {
				$results[] = $result;
			},
			10,
			5
		);

		$this->ability->executeUpdateEvent( array( 'event' => $event_id, 'venue' => $next ) );

		$this->assertSame( array( $previous ), wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) ) );
		$this->assertCount( 1, $results );
		$this->assertSame( $denial, $results[0] );
	}

	public function test_venue_assignment_error_is_surfaced_and_after_fires_once(): void {
		$event_id = $this->makeEvent();
		$venue    = $this->makeVenue( 'Assignment Failure Venue' );
		$results  = array();
		$update_completions = 0;

		add_filter(
			'datamachine_events_before_event_venue_mutation',
			static function ( $allowed ) {
				unregister_taxonomy( 'venue' );
				return $allowed;
			}
		);
		add_action(
			'datamachine_events_after_event_venue_mutation',
			static function ( int $post_id, array $next_ids, array $previous_ids, string $context, $result ) use ( &$results ): void {
				$results[] = $result;
			},
			10,
			5
		);
		add_action(
			'datamachine_events_after_event_update_persistence',
			static function () use ( &$update_completions ): void {
				++$update_completions;
			}
		);

		try {
			$response = $this->ability->executeUpdateEvent( array( 'event' => $event_id, 'venue' => $venue ) );
		} finally {
			$this->registerEventObjects();
		}

		$this->assertSame( 'failed', $response['results'][0]['status'] );
		$this->assertStringContainsString( 'Invalid taxonomy', $response['results'][0]['error'] );
		$this->assertCount( 1, $results );
		$this->assertWPError( $results[0] );
		$this->assertSame( 'invalid_taxonomy', $results[0]->get_error_code() );
		$this->assertSame( 1, $update_completions );
	}

	public function test_update_lifecycle_completes_once_on_success(): void {
		$completions = array();
		add_action(
			'datamachine_events_after_event_update_persistence',
			static function ( array $context, $result ) use ( &$completions ): void {
				$completions[] = array( $context, $result );
			},
			10,
			2
		);

		$response = $this->ability->executeUpdateEvent( array( 'event' => $this->makeEvent(), 'startTime' => '21:00' ) );

		$this->assertSame( 'updated', $response['results'][0]['status'] );
		$this->assertCount( 1, $completions );
		$this->assertSame( 'updated', $completions[0][1]['status'] );
	}

	public function test_update_lifecycle_completes_once_on_post_failure(): void {
		$completions = array();
		$event_id    = $this->makeEvent();
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		add_action(
			'datamachine_events_after_event_update_persistence',
			static function ( array $context, $result ) use ( &$completions ): void {
				$completions[] = array( $context, $result );
			},
			10,
			2
		);

		$response = $this->ability->executeUpdateEvent( array( 'event' => $event_id, 'startTime' => '22:00' ) );

		$this->assertSame( 'failed', $response['results'][0]['status'] );
		$this->assertCount( 1, $completions );
		$this->assertSame( 'failed', $completions[0][1]['status'] );
	}

	public function test_content_update_preflights_proposed_values_and_denial_persists_nothing(): void {
		$event_id = $this->makeEvent();
		$previous = $this->makeVenue( 'Combined Previous Venue' );
		$denial   = new WP_Error( 'canonical_event_booking_conflict', 'Combined update conflicts.', array( 'status' => 409, 'conflict' => array( 'id' => 44 ) ) );
		$before   = null;
		$after    = array();
		wp_set_post_terms( $event_id, array( $previous ), 'venue' );

		add_filter(
			'datamachine_events_before_event_update_persistence',
			static function ( $allowed, array $context ) use ( &$before, $denial ) {
				$before = $context;
				return $denial;
			},
			10,
			2
		);
		add_action(
			'datamachine_events_after_event_update_persistence',
			static function ( array $context, $result ) use ( &$after ): void {
				$after[] = array( $context, $result );
			},
			10,
			2
		);

		$response = $this->ability->executeUpdateEvent(
			array(
				'event'     => $event_id,
				'startDate' => '2027-01-02',
				'startTime' => '21:30',
			)
		);

		$item = $response['results'][0];
		$this->assertSame( 'failed', $item['status'] );
		$this->assertSame( 'canonical_event_booking_conflict', $item['error_code'] );
		$this->assertSame( 409, $item['error_status'] );
		$this->assertSame( array( 'id' => 44 ), $item['error_data']['conflict'] );
		$this->assertNotSame( '', $before['invocation_id'] );
		$this->assertSame( $event_id, $before['post_id'] );
		$this->assertSame( 'publish', $before['post_status'] );
		$this->assertSame( '2027-01-02', $before['event']['startDate'] );
		$this->assertSame( '21:30', $before['event']['startTime'] );
		$this->assertSame( $previous, $before['next_venue_id'] );
		$this->assertSame( array( $previous ), $before['previous_venue_ids'] );
		$this->assertSame( array( $previous ), wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) ) );
		$attrs = parse_blocks( get_post( $event_id )->post_content )[0]['attrs'];
		$this->assertSame( '2027-01-01', $attrs['startDate'] );
		$this->assertSame( '20:00', $attrs['startTime'] );
		$this->assertCount( 1, $after );
		$this->assertSame( $before, $after[0][0] );
		$this->assertSame( $item, $after[0][1] );
	}

	public function test_combined_venue_and_content_update_is_rejected_before_lifecycle(): void {
		$event_id       = $this->makeEvent();
		$previous       = $this->makeVenue( 'Mixed Previous Venue' );
		$next           = $this->makeVenue( 'Mixed Next Venue' );
		$before_content = get_post( $event_id )->post_content;
		$lifecycle      = 0;
		wp_set_post_terms( $event_id, array( $previous ), 'venue' );
		add_filter( 'datamachine_events_before_event_update_persistence', static function ( $allowed ) use ( &$lifecycle ) { ++$lifecycle; return $allowed; } );

		$response = $this->ability->executeUpdateEvent( array( 'event' => $event_id, 'venue' => $next, 'startTime' => '23:00' ) );

		$this->assertSame( 'failed', $response['results'][0]['status'] );
		$this->assertSame( 'event_update_mixed_venue_content_unsupported', $response['results'][0]['error_code'] );
		$this->assertSame( 409, $response['results'][0]['error_status'] );
		$this->assertSame( 0, $lifecycle );
		$this->assertSame( array( $previous ), wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) ) );
		$this->assertSame( $before_content, get_post( $event_id )->post_content );
	}

	public function test_venue_read_failure_aborts_before_lifecycle(): void {
		$event_id  = $this->makeEvent();
		$lifecycle = 0;
		unregister_taxonomy( 'venue' );
		add_filter( 'datamachine_events_before_event_update_persistence', static function ( $allowed ) use ( &$lifecycle ) { ++$lifecycle; return $allowed; } );

		try {
			$response = $this->ability->executeUpdateEvent( array( 'event' => $event_id, 'startTime' => '23:30' ) );
		} finally {
			$this->registerEventObjects();
		}

		$this->assertSame( 'failed', $response['results'][0]['status'] );
		$this->assertSame( 'event_venue_read_failed', $response['results'][0]['error_code'] );
		$this->assertSame( 503, $response['results'][0]['error_status'] );
		$this->assertSame( 'invalid_taxonomy', $response['results'][0]['error_data']['cause'] );
		$this->assertSame( 0, $lifecycle );
		$this->assertSame( '20:00', parse_blocks( get_post( $event_id )->post_content )[0]['attrs']['startTime'] );
	}

	private function makeEvent(): int {
		$event_id = self::factory()->post->create(
			array(
				'post_title'   => 'Venue Mutation Event ' . uniqid(),
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2027-01-01","startTime":"20:00"} --><div></div><!-- /wp:data-machine-events/event-details -->',
			)
		);
		if ( is_wp_error( $event_id ) ) {
			$this->fail( 'Event fixture creation failed: ' . $event_id->get_error_message() );
		}
		$this->assertIsInt( $event_id, 'Event fixture creation must return an integer post ID.' );
		$this->assertGreaterThan( 0, $event_id, 'Event fixture creation must return a positive post ID.' );

		return $event_id;
	}

	private function makeVenue( string $name ): int {
		$term = wp_insert_term( $name . ' ' . uniqid(), 'venue' );
		if ( is_wp_error( $term ) ) {
			$this->fail( 'Venue fixture creation failed: ' . $term->get_error_message() );
		}
		$this->assertArrayHasKey( 'term_id', $term, 'Venue fixture creation must return a term ID.' );
		$this->assertGreaterThan( 0, (int) $term['term_id'], 'Venue fixture creation must return a positive term ID.' );

		return (int) $term['term_id'];
	}

	private function registerEventObjects(): void {
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
	}
}
