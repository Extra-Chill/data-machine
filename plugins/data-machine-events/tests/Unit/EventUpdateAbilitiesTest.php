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
		remove_all_filters( 'datamachine_events_update_source_event_permission' );
		remove_all_filters( 'datamachine_events_before_event_venue_mutation' );
		remove_all_actions( 'datamachine_events_after_event_venue_mutation' );
		remove_all_filters( 'datamachine_events_before_event_update_persistence' );
		remove_all_actions( 'datamachine_events_after_event_update_persistence' );
		remove_all_filters( 'wp_insert_post_empty_content' );
		$this->registerEventObjects();
		parent::tearDown();
	}

	public function test_source_update_requires_narrow_delegated_permission(): void {
		$event_id = $this->makeSourceEvent();
		$result   = $this->ability->executeUpdateSourceEvent( $this->sourceInput( $event_id, array( 'startTime' => '21:00' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'source_event_update_forbidden', $result->get_error_code() );
		$this->assertSame( '20:00', parse_blocks( get_post( $event_id )->post_content )[0]['attrs']['startTime'] );
	}

	public function test_registered_ability_execution_cannot_bypass_scoped_permission(): void {
		do_action( 'wp_abilities_api_init' );
		$registered = wp_get_ability( EventUpdateAbilities::SOURCE_ABILITY_NAME );
		$this->assertNotNull( $registered );
		$result = $registered->execute( $this->sourceInput( $this->makeSourceEvent(), array( 'startTime' => '21:00' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'source_event_update_forbidden', $result->get_error_code() );
	}

	public function test_source_update_rejects_wrong_identity_event_and_stale_fingerprint(): void {
		add_filter( 'datamachine_events_update_source_event_permission', '__return_true' );
		$event_id = $this->makeSourceEvent();

		$wrong_source           = $this->sourceInput( $event_id, array( 'startTime' => '21:00' ) );
		$wrong_source['source'] = 'other-source';
		$result                 = $this->ability->executeUpdateSourceEvent( $wrong_source );
		$this->assertWPError( $result );
		$this->assertSame( 'source_event_identity_mismatch', $result->get_error_code() );

		$wrong_source_id              = $this->sourceInput( $event_id, array( 'startTime' => '21:00' ) );
		$wrong_source_id['source_id'] = 'other-id';
		$result                       = $this->ability->executeUpdateSourceEvent( $wrong_source_id );
		$this->assertWPError( $result );
		$this->assertSame( 'source_event_identity_mismatch', $result->get_error_code() );

		$wrong_identity                    = $this->sourceInput( $event_id, array( 'startTime' => '21:00' ) );
		$wrong_identity['source_identity'] = str_repeat( 'b', 64 );
		$result                            = $this->ability->executeUpdateSourceEvent( $wrong_identity );
		$this->assertWPError( $result );
		$this->assertSame( 'source_event_identity_mismatch', $result->get_error_code() );

		$wrong_event          = $this->sourceInput( $event_id, array( 'startTime' => '21:00' ) );
		$wrong_event['event'] = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$result               = $this->ability->executeUpdateSourceEvent( $wrong_event );
		$this->assertWPError( $result );
		$this->assertSame( 'source_event_not_found', $result->get_error_code() );

		$stale                         = $this->sourceInput( $event_id, array( 'startTime' => '21:00' ) );
		$stale['expected_fingerprint'] = str_repeat( 'a', 64 );
		$result                        = $this->ability->executeUpdateSourceEvent( $stale );
		$this->assertWPError( $result );
		$this->assertSame( 'source_event_fingerprint_conflict', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result->get_error_data()['fingerprint'] );

		$result = $this->ability->executeUpdateSourceEvent( $this->sourceInput( $event_id, array( 'venue' => PHP_INT_MAX ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'event_venue_assignment_failed', $result->get_error_code() );
		$this->assertSame( array(), wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) ) );

		$result = $this->ability->executeUpdateSourceEvent( $this->sourceInput( $event_id, array( 'startDate' => '2027-99-99' ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'source_event_update_input_invalid', $result->get_error_code() );
		$this->assertSame( '2027-01-01', parse_blocks( get_post( $event_id )->post_content )[0]['attrs']['startDate'] );
	}

	public function test_source_update_atomically_changes_venue_and_time_through_lifecycle(): void {
		add_filter( 'datamachine_events_update_source_event_permission', '__return_true' );
		$event_id = $this->makeSourceEvent();
		$previous = $this->makeVenue( 'Source Previous Venue' );
		$next     = $this->makeVenue( 'Source Next Venue' );
		$observed = array();
		wp_set_post_terms( $event_id, array( $previous ), 'venue' );
		$fingerprint = EventUpdateAbilities::fingerprintForEvent( $event_id, 'booking', 'booking-123' );

		add_filter(
			'datamachine_events_before_event_update_persistence',
			static function ( $allowed, array $context ) use ( &$observed ) {
				$observed[] = array( 'before', $context['next_venue_id'], $context['event']['startTime'] );
				return $allowed;
			},
			10,
			2
		);
		add_action(
			'datamachine_events_after_event_update_persistence',
			static function ( array $context, array $result ) use ( &$observed ): void {
				$observed[] = array( 'after', $result['status'] );
			},
			10,
			2
		);

		$result = $this->ability->executeUpdateSourceEvent(
			array(
				'event'                => $event_id,
				'source'               => 'booking',
				'source_id'            => 'booking-123',
				'source_identity'      => hash( 'sha256', "booking\0booking-123" ),
				'expected_fingerprint' => $fingerprint,
				'venue'                => $next,
				'startTime'            => '21:30',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'updated', $result['action'] );
		$this->assertSame( array( 'startTime', 'venue' ), $result['updated_fields'] );
		$this->assertNotSame( $fingerprint, $result['fingerprint'] );
		$this->assertSame( array( $next ), wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) ) );
		$this->assertSame( '21:30', parse_blocks( get_post( $event_id )->post_content )[0]['attrs']['startTime'] );
		$this->assertSame( 'Manual title retained', get_post( $event_id )->post_title );
		$this->assertSame( array( array( 'before', $next, '21:30' ), array( 'after', 'updated' ) ), $observed );
	}

	public function test_source_update_rolls_back_venue_when_content_write_fails(): void {
		add_filter( 'datamachine_events_update_source_event_permission', '__return_true' );
		$event_id = $this->makeSourceEvent();
		$previous = $this->makeVenue( 'Rollback Previous Venue' );
		$next     = $this->makeVenue( 'Rollback Next Venue' );
		wp_set_post_terms( $event_id, array( $previous ), 'venue' );
		$input = $this->sourceInput( $event_id, array( 'venue' => $next, 'startTime' => '22:00' ) );
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$result = $this->ability->executeUpdateSourceEvent( $input );

		$this->assertWPError( $result );
		$this->assertSame( 'empty_content', $result->get_error_code() );
		$this->assertSame( array( $previous ), wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) ) );
		$this->assertSame( '20:00', parse_blocks( get_post( $event_id )->post_content )[0]['attrs']['startTime'] );
		$this->assertSame( $input['expected_fingerprint'], EventUpdateAbilities::fingerprintForEvent( $event_id, 'booking', 'booking-123' ) );
	}

	public function test_source_update_rolls_back_when_derived_date_write_fails(): void {
		add_filter( 'datamachine_events_update_source_event_permission', '__return_true' );
		$event_id = $this->makeSourceEvent();
		$input    = $this->sourceInput( $event_id, array( 'startTime' => '22:30' ) );
		$fail_date_replace = static function ( string $query ): string {
			return str_contains( $query, 'REPLACE INTO' ) && str_contains( $query, 'datamachine_event_dates' )
				? 'INVALID EVENT DATE WRITE'
				: $query;
		};
		add_filter( 'query', $fail_date_replace );

		try {
			$result = $this->ability->executeUpdateSourceEvent( $input );
		} finally {
			remove_filter( 'query', $fail_date_replace );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'event_dates_write_failed', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertSame( '20:00', parse_blocks( get_post( $event_id )->post_content )[0]['attrs']['startTime'] );
		$this->assertSame( $input['expected_fingerprint'], EventUpdateAbilities::fingerprintForEvent( $event_id, 'booking', 'booking-123' ) );
	}

	public function test_source_update_rolls_back_when_derived_date_delete_fails(): void {
		add_filter( 'datamachine_events_update_source_event_permission', '__return_true' );
		$event_id = $this->makeSourceEvent();
		$input    = $this->sourceInput( $event_id, array( 'startDate' => '' ) );
		$fail_date_delete = static function ( string $query ): string {
			return str_contains( $query, 'DELETE FROM' ) && str_contains( $query, 'datamachine_event_dates' )
				? 'INVALID EVENT DATE DELETE'
				: $query;
		};
		add_filter( 'query', $fail_date_delete );

		try {
			$result = $this->ability->executeUpdateSourceEvent( $input );
		} finally {
			remove_filter( 'query', $fail_date_delete );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'event_dates_delete_failed', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertSame( '2027-01-01', parse_blocks( get_post( $event_id )->post_content )[0]['attrs']['startDate'] );
		$this->assertSame( $input['expected_fingerprint'], EventUpdateAbilities::fingerprintForEvent( $event_id, 'booking', 'booking-123' ) );
	}

	public function test_source_update_surfaces_commit_uncertainty_as_retryable(): void {
		add_filter( 'datamachine_events_update_source_event_permission', '__return_true' );
		$event_id = $this->makeSourceEvent();
		$ability  = new class() extends EventUpdateAbilities {
			protected function transactionQuery( string $sql ) {
				$result = parent::transactionQuery( $sql );
				return 'COMMIT' === $sql ? false : $result;
			}
		};

		$result = $ability->executeUpdateSourceEvent( $this->sourceInput( $event_id, array( 'startTime' => '23:00' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'source_event_commit_uncertain', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertTrue( $result->get_error_data()['connection_closed'] );
		$this->assertTrue( $result->get_error_data()['connection_recovered'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result->get_error_data()['fingerprint'] );

		$retry = $this->sourceInput( $event_id, array( 'startTime' => '23:00' ) );
		$retry['expected_fingerprint'] = $result->get_error_data()['fingerprint'];
		$converged = $this->ability->executeUpdateSourceEvent( $retry );
		$this->assertIsArray( $converged );
		$this->assertSame( 'no_change', $converged['action'] );
		$this->assertSame( $retry['expected_fingerprint'], $converged['fingerprint'] );
	}

	public function test_source_update_returns_stable_no_change_result(): void {
		add_filter( 'datamachine_events_update_source_event_permission', '__return_true' );
		$event_id = $this->makeSourceEvent();
		$input    = $this->sourceInput( $event_id, array( 'startTime' => '20:00' ) );

		$result = $this->ability->executeUpdateSourceEvent( $input );

		$this->assertIsArray( $result );
		$this->assertSame( 'no_change', $result['action'] );
		$this->assertSame( array(), $result['updated_fields'] );
		$this->assertSame( $input['expected_fingerprint'], $result['fingerprint'] );
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

	private function makeSourceEvent(): int {
		$event_id = $this->makeEvent();
		wp_update_post( array( 'ID' => $event_id, 'post_title' => 'Manual title retained' ) );
		update_post_meta( $event_id, '_datamachine_event_source', 'booking' );
		update_post_meta( $event_id, '_datamachine_event_source_id', 'booking-123' );
		update_post_meta( $event_id, '_datamachine_event_source_identity', hash( 'sha256', "booking\0booking-123" ) );
		return $event_id;
	}

	private function sourceInput( int $event_id, array $changes ): array {
		return array_merge(
			array(
				'event'                => $event_id,
				'source'               => 'booking',
				'source_id'            => 'booking-123',
				'source_identity'      => hash( 'sha256', "booking\0booking-123" ),
				'expected_fingerprint' => EventUpdateAbilities::fingerprintForEvent( $event_id, 'booking', 'booking-123' ),
			),
			$changes
		);
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
