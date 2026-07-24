<?php
/**
 * EventUpsert persistence lifecycle tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex;
use DataMachine\Core\EngineData;
use DataMachineEvents\Blocks\Calendar\Cache\CacheInvalidator;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;
use WP_UnitTestCase;

class EventUpsertLifecycleTest extends WP_UnitTestCase {

	private EventUpsert $handler;
	private \Closure $lock_query_filter;

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( 'data_machine_events' ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
		if ( class_exists( PostIdentityIndex::class ) ) {
			( new PostIdentityIndex() )->create_table();
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$ability_registry = \WP_Abilities_Registry::get_instance();
		if ( ! $ability_registry->is_registered( 'datamachine/upsert-post' ) ) {
			$category_registry = \WP_Ability_Categories_Registry::get_instance();
			$register_category = static function () use ( $category_registry ): void {
				if ( ! $category_registry->is_registered( 'datamachine-content' ) ) {
					wp_register_ability_category(
						'datamachine-content',
						array(
							'label'       => 'Data Machine Content',
							'description' => 'Test content abilities.',
						)
					);
				}
			};
			add_action( 'wp_abilities_api_categories_init', $register_category );
			do_action( 'wp_abilities_api_categories_init' );
			remove_action( 'wp_abilities_api_categories_init', $register_category );

			$register_upsert = static function () use ( $ability_registry ): void {
				if ( $ability_registry->is_registered( 'datamachine/upsert-post' ) ) {
					return;
				}

				wp_register_ability(
					'datamachine/upsert-post',
					array(
						'label'               => 'Test Upsert Post',
						'description'         => 'Test-only post upsert boundary.',
						'category'            => 'datamachine-content',
						'input_schema'        => array( 'type' => 'object' ),
						'output_schema'       => array( 'type' => 'object' ),
						'permission_callback' => '__return_true',
						'execute_callback'    => static function ( array $input ): array {
							$post_id = (int) ( $input['post_id'] ?? 0 );
							$postarr  = array(
								'post_title'   => $input['title'],
								'post_content' => $input['content'],
								'post_type'    => $input['post_type'],
								'post_status'  => $input['post_status'] ?? 'publish',
								'meta_input'   => $input['meta_input'] ?? array(),
							);

							if ( $post_id > 0 ) {
								$postarr['ID'] = $post_id;
								$result        = wp_update_post( $postarr, true );
								$action        = 'updated';
							} else {
								$postarr['post_author'] = (int) ( $input['post_author'] ?? 0 );
								$result                 = wp_insert_post( $postarr, true );
								$action                 = 'created';
							}

							if ( is_wp_error( $result ) ) {
								return array( 'success' => false, 'error' => $result->get_error_message() );
							}

							return array( 'success' => true, 'post_id' => (int) $result, 'action' => $action );
						},
					)
				);
			};
			add_action( 'wp_abilities_api_init', $register_upsert );
			do_action( 'wp_abilities_api_init' );
			remove_action( 'wp_abilities_api_init', $register_upsert );
		}
		if ( ! $ability_registry->is_registered( 'datamachine/check-duplicate' ) ) {
			$register_duplicate_check = static function () use ( $ability_registry ): void {
				if ( $ability_registry->is_registered( 'datamachine/check-duplicate' ) ) {
					return;
				}

				wp_register_ability(
					'datamachine/check-duplicate',
					array(
						'label'               => 'Test Duplicate Check',
						'description'         => 'Test-only event duplicate boundary.',
						'category'            => 'datamachine-content',
						'input_schema'        => array( 'type' => 'object' ),
						'output_schema'       => array( 'type' => 'object' ),
						'permission_callback' => '__return_true',
						'execute_callback'    => static function ( array $input ): array {
							return \DataMachineEvents\Core\DuplicateDetection\EventDuplicateStrategy::check( $input )
								?? array( 'verdict' => 'clear' );
						},
					)
				);
			};
			add_action( 'wp_abilities_api_init', $register_duplicate_check );
			do_action( 'wp_abilities_api_init' );
			remove_action( 'wp_abilities_api_init', $register_duplicate_check );
		}
		CacheInvalidator::init();

		$this->lock_query_filter = static function ( string $query ): string {
			if ( str_contains( $query, 'GET_LOCK' ) || str_contains( $query, 'RELEASE_LOCK' ) ) {
				return 'SELECT 1';
			}

			return $query;
		};
		add_filter( 'query', $this->lock_query_filter );

		$this->handler = new EventUpsert();
	}

	public function tearDown(): void {
		remove_filter( 'query', $this->lock_query_filter );
		remove_all_filters( 'datamachine_events_before_event_upsert_persistence' );
		remove_all_actions( 'datamachine_events_after_event_upsert_persistence' );
		remove_all_filters( 'wp_insert_post_empty_content' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_upsert_persistence_lifecycle_success_payload_fires_once(): void {
		$title    = 'Lifecycle Success ' . uniqid();
		$observed = array();

		add_filter(
			'datamachine_events_before_event_upsert_persistence',
			static function ( $allowed, array $context ) use ( &$observed ) {
				$observed[] = array( 'before', $allowed, $context );
				return $allowed;
			},
			10,
			2
		);
		add_action(
			'datamachine_events_after_event_upsert_persistence',
			static function ( array $context, int $post_id, $result ) use ( &$observed ): void {
				$observed[] = array( 'after', $context, $post_id, $result );
			},
			10,
			3
		);

		$result = $this->handler->upsertCanonicalEvent(
			array(
				'title'     => $title,
				'venue'     => 'Lifecycle Venue ' . uniqid(),
				'startDate' => '2027-03-01',
				'startTime' => '20:00',
				'source'    => 'test-source',
				'source_id' => 'success-1',
			),
			array(
				'post_author' => self::factory()->user->create( array( 'role' => 'administrator' ) ),
				'post_status' => 'publish',
			)
		);

		$this->assertTrue( $result['success'] ?? false, wp_json_encode( $result ) );
		$this->assertCount( 2, $observed );
		$this->assertSame( 'before', $observed[0][0] );
		$this->assertTrue( $observed[0][1] );
		$this->assertSame( $title, $observed[0][2]['event']['title'] );
		$this->assertGreaterThan( 0, $observed[0][2]['venue_term_id'] );
		$this->assertSame( 'publish', $observed[0][2]['post_status'] );
		$this->assertSame( 0, $observed[0][2]['existing_post_id'] );
		$this->assertSame( 'test-source', $observed[0][2]['source'] );
		$this->assertSame( 'success-1', $observed[0][2]['source_id'] );
		$this->assertNotSame( '', $observed[0][2]['invocation_id'] );
		$this->assertSame( 'after', $observed[1][0] );
		$this->assertSame( $observed[0][2], $observed[1][1] );
		$this->assertSame( $result['data']['post_id'], $observed[1][2] );
		$this->assertSame( $result, $observed[1][3] );
	}

	public function test_upsert_persistence_denial_prevents_post_and_releases_once(): void {
		$denial      = new \WP_Error( 'event_upsert_denied', 'Event persistence denied.' );
		$after_calls = array();
		$before      = (int) wp_count_posts( Event_Post_Type::POST_TYPE )->publish;

		add_filter( 'datamachine_events_before_event_upsert_persistence', static fn() => $denial );
		add_action(
			'datamachine_events_after_event_upsert_persistence',
			static function ( array $context, int $post_id, $result ) use ( &$after_calls ): void {
				$after_calls[] = array( $post_id, $result );
			},
			10,
			3
		);

		$result = $this->invoke_upsert(
			array(
				'title'     => 'Denied Lifecycle ' . uniqid(),
				'venue'     => 'Denied Lifecycle Venue ' . uniqid(),
				'startDate' => '2027-03-02',
				'startTime' => '20:00',
			)
		);

		$this->assertFalse( $result['success'] ?? true );
		$this->assertSame( $before, (int) wp_count_posts( Event_Post_Type::POST_TYPE )->publish );
		$this->assertCount( 1, $after_calls );
		$this->assertSame( 0, $after_calls[0][0] );
		$this->assertSame( $denial, $after_calls[0][1] );
	}

	public function test_false_upsert_persistence_preflight_aborts_and_completes_once(): void {
		$after_calls = array();
		$before      = (int) wp_count_posts( Event_Post_Type::POST_TYPE )->publish;
		add_filter( 'datamachine_events_before_event_upsert_persistence', '__return_false' );
		add_action(
			'datamachine_events_after_event_upsert_persistence',
			static function ( array $context, int $post_id, $result ) use ( &$after_calls ): void {
				$after_calls[] = array( $context, $post_id, $result );
			},
			10,
			3
		);

		$result = $this->invoke_upsert(
			array(
				'title'     => 'False Lifecycle Denial ' . uniqid(),
				'venue'     => 'False Lifecycle Venue ' . uniqid(),
				'startDate' => '2027-03-02',
				'startTime' => '21:00',
			)
		);

		$this->assertFalse( $result['success'] ?? true );
		$this->assertSame( 'event_upsert_persistence_denied', $result['error_code'] );
		$this->assertSame( 403, $result['status'] );
		$this->assertSame( $before, (int) wp_count_posts( Event_Post_Type::POST_TYPE )->publish );
		$this->assertCount( 1, $after_calls );
		$this->assertSame( 0, $after_calls[0][1] );
		$this->assertWPError( $after_calls[0][2] );
		$this->assertSame( 'event_upsert_persistence_denied', $after_calls[0][2]->get_error_code() );
	}

	public function test_data_machine_write_abort_releases_upsert_lifecycle_once(): void {
		$after_calls = array();
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		add_action(
			'datamachine_events_after_event_upsert_persistence',
			static function ( array $context, int $post_id, $result ) use ( &$after_calls ): void {
				$after_calls[] = array( $post_id, $result );
			},
			10,
			3
		);

		$result = $this->invoke_upsert(
			array(
				'title'     => 'Write Abort Lifecycle ' . uniqid(),
				'venue'     => 'Write Abort Venue ' . uniqid(),
				'startDate' => '2027-03-03',
				'startTime' => '20:00',
			)
		);

		$this->assertFalse( $result['success'] ?? true );
		$this->assertCount( 1, $after_calls );
		$this->assertSame( 0, $after_calls[0][0] );
		$this->assertWPError( $after_calls[0][1] );
		$this->assertSame( 'event_upsert_persistence_failed', $after_calls[0][1]->get_error_code() );
	}

	public function test_upsert_wp_error_preserves_structured_failure_and_context_once(): void {
		$source_identity = 'wp-error-' . uniqid();
		$created         = $this->invoke_upsert(
			array(
				'title'           => 'WP Error Existing Event',
				'venue'           => 'WP Error Venue ' . uniqid(),
				'startDate'       => '2027-03-03',
				'startTime'       => '20:30',
				'source'          => 'wp-error-source',
				'source_id'       => 'wp-error-source-id',
				'source_identity' => $source_identity,
			)
		);
		$existing_post_id = (int) $created['data']['post_id'];
		$failure          = new \WP_Error(
			'upstream_write_throttled',
			'Upstream write was throttled.',
			array(
				'status'    => 429,
				'retryable' => true,
				'cause'     => 'upstream_rate_limit',
			)
		);
		$after_calls      = array();
		add_action(
			'datamachine_events_after_event_upsert_persistence',
			static function ( array $context, int $post_id, $result ) use ( &$after_calls ): void {
				$after_calls[] = array( $context, $post_id, $result );
			},
			10,
			3
		);

		$result = $this->invoke_upsert_with_ability_result(
			$failure,
			array(
				'title'           => 'WP Error Existing Event',
				'venue'           => 'WP Error Venue Updated',
				'startDate'       => '2027-03-03',
				'startTime'       => '21:00',
				'source'          => 'wp-error-source',
				'source_id'       => 'wp-error-source-id',
				'source_identity' => $source_identity,
			)
		);

		$this->assertFalse( $result['success'] ?? true );
		$this->assertSame( 'upstream_write_throttled', $result['error_code'] );
		$this->assertSame( 'Upstream write was throttled.', $result['error'] );
		$this->assertSame( 429, $result['status'] );
		$this->assertTrue( $result['retryable'] );
		$this->assertSame( 'upstream_rate_limit', $result['error_data']['cause'] );
		$this->assertCount( 1, $after_calls );
		$this->assertSame( 0, $after_calls[0][1] );
		$this->assertSame( $existing_post_id, $after_calls[0][0]['existing_post_id'] );
		$this->assertSame( 'wp-error-source', $after_calls[0][0]['source'] );
		$this->assertSame( 'wp-error-source-id', $after_calls[0][0]['source_id'] );
		$this->assertSame( $source_identity, $after_calls[0][0]['source_identity'] );
		$this->assertWPError( $after_calls[0][2] );
		$this->assertSame( 'upstream_write_throttled', $after_calls[0][2]->get_error_code() );
		$this->assertSame( $failure->get_error_data(), $after_calls[0][2]->get_error_data() );
	}

	public function test_upsert_array_failure_preserves_structured_failure_once(): void {
		$after_calls = array();
		add_action(
			'datamachine_events_after_event_upsert_persistence',
			static function ( array $context, int $post_id, $result ) use ( &$after_calls ): void {
				$after_calls[] = array( $context, $post_id, $result );
			},
			10,
			3
		);

		$result = $this->invoke_upsert_with_ability_result(
			array(
				'success'    => false,
				'error'      => 'Legacy persistence failed.',
				'error_code' => 'legacy_persistence_failed',
				'error_data' => array(
					'status'    => 503,
					'retryable' => true,
					'cause'     => 'database_unavailable',
				),
			),
			array(
				'title'     => 'Array Failure Event ' . uniqid(),
				'venue'     => 'Array Failure Venue ' . uniqid(),
				'startDate' => '2027-03-03',
				'startTime' => '22:00',
			)
		);

		$this->assertFalse( $result['success'] ?? true );
		$this->assertSame( 'legacy_persistence_failed', $result['error_code'] );
		$this->assertSame( 'Legacy persistence failed.', $result['error'] );
		$this->assertSame( 503, $result['status'] );
		$this->assertTrue( $result['retryable'] );
		$this->assertSame( 'database_unavailable', $result['error_data']['cause'] );
		$this->assertCount( 1, $after_calls );
		$this->assertSame( 0, $after_calls[0][1] );
		$this->assertWPError( $after_calls[0][2] );
		$this->assertSame( 'legacy_persistence_failed', $after_calls[0][2]->get_error_code() );
	}

	public function test_upsert_array_success_completes_lifecycle_once(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Array Success Event',
			)
		);
		$after_calls = array();
		add_action(
			'datamachine_events_after_event_upsert_persistence',
			static function ( array $context, int $observed_post_id, $result ) use ( &$after_calls ): void {
				$after_calls[] = array( $context, $observed_post_id, $result );
			},
			10,
			3
		);

		$result = $this->invoke_upsert_with_ability_result(
			array(
				'success' => true,
				'post_id' => $post_id,
				'action'  => 'created',
			),
			array(
				'title'     => 'Array Success Event',
				'venue'     => 'Array Success Venue ' . uniqid(),
				'startDate' => '2027-03-04',
				'startTime' => '20:00',
			)
		);

		$this->assertTrue( $result['success'] ?? false, wp_json_encode( $result ) );
		$this->assertSame( $post_id, $result['data']['post_id'] );
		$this->assertSame( 'created', $result['data']['action'] );
		$this->assertCount( 1, $after_calls );
		$this->assertSame( $post_id, $after_calls[0][1] );
		$this->assertSame( $result, $after_calls[0][2] );
	}

	public function test_venue_assignment_failure_remains_successful_and_warns(): void {
		$after = array();
		add_filter(
			'datamachine_events_before_event_upsert_persistence',
			static function ( $allowed ) {
				unregister_taxonomy( 'venue' );
				return $allowed;
			}
		);
		add_action(
			'datamachine_events_after_event_upsert_persistence',
			static function ( array $context, int $post_id, $result ) use ( &$after ): void {
				$after[] = array( $post_id, $result );
			},
			10,
			3
		);

		try {
			$result = $this->invoke_upsert(
				array(
					'title'     => 'Venue Warning Event ' . uniqid(),
					'venue'     => 'Venue Warning ' . uniqid(),
					'startDate' => '2027-03-03',
					'startTime' => '21:00',
				)
			);
		} finally {
			Venue_Taxonomy::register();
		}

		$this->assertTrue( $result['success'] ?? false, wp_json_encode( $result ) );
		$this->assertNotEmpty( $result['warnings'] ?? array() );
		$this->assertCount( 1, $after );
		$this->assertSame( $result, $after[0][1] );
		$this->assertSame( array(), wp_get_object_terms( $after[0][0], 'venue', array( 'fields' => 'ids' ) ) );
	}

	public function test_direct_execute_upsert_workflow_uses_persistence_lifecycle(): void {
		$before_calls = 0;
		$after_calls  = 0;
		add_filter(
			'datamachine_events_before_event_upsert_persistence',
			static function ( $allowed ) use ( &$before_calls ) {
				++$before_calls;
				return $allowed;
			}
		);
		add_action(
			'datamachine_events_after_event_upsert_persistence',
			static function () use ( &$after_calls ): void {
				++$after_calls;
			}
		);

		$result = $this->invoke_upsert(
			array(
				'title'     => 'Direct Workflow Lifecycle ' . uniqid(),
				'venue'     => 'Direct Workflow Venue ' . uniqid(),
				'startDate' => '2027-03-04',
				'startTime' => '20:00',
			)
		);

		$this->assertTrue( $result['success'] ?? false, wp_json_encode( $result ) );
		$this->assertSame( 1, $before_calls );
		$this->assertSame( 1, $after_calls );
	}

	public function test_existing_artist_skip_uses_retained_venue_in_lifecycle_context(): void {
		$source_identity = 'artist-skip-' . uniqid();
		$old_venue       = 'Retained Venue ' . uniqid();
		$created         = $this->invoke_upsert( array( 'title' => 'Retained Event', 'venue' => $old_venue, 'startDate' => '2027-03-05', 'startTime' => '20:00', 'source_identity' => $source_identity ) );
		$post_id         = (int) $created['data']['post_id'];
		$old_venue_id   = (int) reset( wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );
		$artist          = wp_insert_term( 'Matching Artist ' . uniqid(), 'artist' );
		$this->assertNotWPError( $artist );
		$observed = 0;
		add_filter( 'datamachine_events_before_event_upsert_persistence', static function ( $allowed, array $context ) use ( &$observed ) { $observed = $context['venue_term_id']; return $allowed; }, 10, 2 );

		$result = $this->invoke_upsert(
			array( 'title' => 'Retained Event', 'venue' => get_term( $artist['term_id'], 'artist' )->name, 'startDate' => '2027-03-05', 'startTime' => '21:00', 'source_identity' => $source_identity ),
			array( 'taxonomy_artist_selection' => (string) $artist['term_id'] )
		);

		$this->assertTrue( $result['success'] ?? false, wp_json_encode( $result ) );
		$this->assertSame( $old_venue_id, $observed );
		$this->assertSame( array( $old_venue_id ), wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );
	}

	public function test_existing_resolution_skip_uses_retained_venue_in_lifecycle_context(): void {
		$source_identity = 'resolution-skip-' . uniqid();
		$created         = $this->invoke_upsert( array( 'title' => 'Resolution Skip Event', 'venue' => 'Original Venue ' . uniqid(), 'startDate' => '2027-03-06', 'startTime' => '20:00', 'source_identity' => $source_identity ) );
		$post_id         = (int) $created['data']['post_id'];
		$old_venue_id   = (int) reset( wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );
		$candidate      = 'Ambiguous Candidate ' . uniqid();
		$this->assertNotWPError( wp_insert_term( $candidate . '!', 'venue' ) );
		$this->assertNotWPError( wp_insert_term( $candidate . '?', 'venue' ) );
		$observed = 0;
		add_filter( 'datamachine_events_before_event_upsert_persistence', static function ( $allowed, array $context ) use ( &$observed ) { $observed = $context['venue_term_id']; return $allowed; }, 10, 2 );

		$result = $this->invoke_upsert( array( 'title' => 'Resolution Skip Event', 'venue' => $candidate, 'startDate' => '2027-03-06', 'startTime' => '21:00', 'source_identity' => $source_identity ) );

		$this->assertTrue( $result['success'] ?? false, wp_json_encode( $result ) );
		$this->assertSame( $old_venue_id, $observed );
		$this->assertSame( array( $old_venue_id ), wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );
	}

	public function test_existing_skip_with_multiple_retained_venues_fails_before_persistence(): void {
		$source_identity = 'ambiguous-retained-' . uniqid();
		$created         = $this->invoke_upsert( array( 'title' => 'Ambiguous Retained Event', 'venue' => 'First Retained Venue ' . uniqid(), 'startDate' => '2027-03-07', 'startTime' => '20:00', 'source_identity' => $source_identity ) );
		$post_id         = (int) $created['data']['post_id'];
		$first_venue_ids = wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) );
		$second_venue    = wp_insert_term( 'Second Retained Venue ' . uniqid(), 'venue' );
		$artist          = wp_insert_term( 'Ambiguous Matching Artist ' . uniqid(), 'artist' );
		$this->assertNotWPError( $second_venue );
		$this->assertNotWPError( $artist );
		wp_set_post_terms( $post_id, array( (int) $second_venue['term_id'] ), 'venue', true );
		$before_content = get_post( $post_id )->post_content;

		$result = $this->invoke_upsert(
			array( 'title' => 'Ambiguous Retained Event', 'venue' => get_term( $artist['term_id'], 'artist' )->name, 'startDate' => '2027-03-07', 'startTime' => '22:00', 'source_identity' => $source_identity ),
			array( 'taxonomy_artist_selection' => (string) $artist['term_id'] )
		);

		$this->assertFalse( $result['success'] ?? true );
		$this->assertSame( 'event_retained_venue_ambiguous', $result['error_code'] );
		$this->assertSame( 409, $result['status'] );
		$this->assertSame( $before_content, get_post( $post_id )->post_content );
		$this->assertEqualsCanonicalizing( array_merge( $first_venue_ids, array( (int) $second_venue['term_id'] ) ), wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );
	}

	/**
	 * Invoke the protected upsert entry point with normal engine context.
	 *
	 * @param array $parameters Event parameters.
	 * @param array $config     Handler configuration.
	 * @return array Upsert result.
	 */
	private function invoke_upsert( array $parameters, array $config = array() ): array {
		$method = new \ReflectionMethod( $this->handler, 'executeUpsert' );
		$method->setAccessible( true );
		$parameters['engine'] = new EngineData( $parameters, 0 );
		$parameters['job_id'] = 0;

		if ( empty( $config['post_author'] ) ) {
			$config['post_author'] = self::factory()->user->create( array( 'role' => 'administrator' ) );
		}
		$config['post_status'] = $config['post_status'] ?? 'publish';

		return $method->invoke( $this->handler, $parameters, $config );
	}

	/** Execute an upsert with a controlled Data Machine ability result. */
	private function invoke_upsert_with_ability_result( array|\WP_Error $ability_result, array $parameters ): array {
		$ability  = wp_get_ability( 'datamachine/upsert-post' );
		$property = new \ReflectionProperty( \WP_Ability::class, 'execute_callback' );
		$callback = $property->getValue( $ability );
		$property->setValue( $ability, static fn() => $ability_result );

		try {
			return $this->invoke_upsert( $parameters );
		} finally {
			$property->setValue( $ability, $callback );
		}
	}
}
