<?php
/**
 * EventUpsert entity-decode tests.
 *
 * Covers the ingestion half of issue #844: entity-bearing titles are stored
 * decoded; markup hidden behind entities is stripped by the sanitizer; real
 * markup never reaches storage; dedup resolves the encoded and decoded forms
 * to the same event; and the kses save filters cannot re-encode the stored
 * title.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.65.0
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Blocks\Calendar\Cache\CacheInvalidator;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;
use WP_UnitTestCase;

class EventUpsertEntityDecodeTest extends WP_UnitTestCase {

	private EventUpsert $handler;
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
							$postarr = array(
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
								return array(
									'success' => false,
									'error'   => $result->get_error_message(),
								);
							}

							return array(
								'success' => true,
								'post_id' => (int) $result,
								'action'  => $action,
							);
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
							$result = \DataMachineEvents\Core\DuplicateDetection\EventDuplicateStrategy::check( $input );
							if ( ! is_array( $result ) ) {
								return array( 'verdict' => 'clear' );
							}
							$result['strategy'] = 'event_identity_index';
							return $result;
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

	private function upsert( string $title ): array {
		$parameters = array(
			'title'       => $title,
			'venue'       => 'Test Decode Venue',
			'startDate'   => gmdate( 'Y-m-d', strtotime( '+5 days' ) ),
			'startTime'   => '20:00',
			'description' => '<p>Decode contract coverage.</p>',
			'engine'      => array(),
			'job_id'      => 0,
		);
		$config     = array(
			'post_status' => 'publish',
		);

		$execute = new \ReflectionMethod( $this->handler, 'executeUpsert' );
		$execute->setAccessible( true );

		return $execute->invoke( $this->handler, $parameters, $config );
	}

	/**
	 * Seed an entity-bearing stored title without the upsert path (simulates
	 * a legacy row). Uses explicit entities so the seed is deterministic
	 * regardless of whether kses filters are active in the test context.
	 */
	private function seed_entity_titled_event( string $title ): int {
		$post_id = \DataMachineEvents\Core\TextNormalization::with_kses_suspended(
			static fn(): int => (int) wp_insert_post(
				array(
					'post_title'   => $title,
					'post_type'    => Event_Post_Type::POST_TYPE,
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:data-machine-events/event-details ' . wp_json_encode(
						array(
							'startDate' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ),
							'startTime' => '20:00',
							'venue'     => 'Test Decode Venue',
						)
					) . ' /-->',
				)
			)
		);

		$this->assertGreaterThan( 0, $post_id );

		return $post_id;
	}

	public function test_named_entity_title_is_stored_decoded(): void {
		$result = $this->upsert( 'Power Pilates &amp; Matcha' );

		$this->assertTrue( (bool) ( $result['success'] ?? false ), 'Upsert should succeed: ' . (string) wp_json_encode( $result ) );
		$post_id = (int) $result['data']['post_id'];
		$this->assertSame( 'Power Pilates & Matcha', get_post( $post_id )->post_title );
	}

	public function test_numeric_and_named_reference_titles_are_stored_decoded(): void {
		$result = $this->upsert( 'An R&#038;B Night: Guitar&#8217;s &amp; Drums&#8211;Live' );

		$this->assertTrue( (bool) ( $result['success'] ?? false ) );
		$post_id = (int) $result['data']['post_id'];
		$this->assertSame( 'An R&B Night: Guitar’s & Drums–Live', get_post( $post_id )->post_title );
	}

	public function test_quoted_entity_title_is_stored_decoded(): void {
		$result = $this->upsert( 'The &quot;Quiet&quot; Show' );

		$this->assertTrue( (bool) ( $result['success'] ?? false ) );
		$post_id = (int) $result['data']['post_id'];
		$this->assertSame( 'The "Quiet" Show', get_post( $post_id )->post_title );
	}

	public function test_markup_hidden_behind_entities_is_stripped_by_sanitizer(): void {
		$result = $this->upsert( 'Foo &lt;b&gt;Bar&lt;/b&gt; Baz' );

		$this->assertTrue( (bool) ( $result['success'] ?? false ) );
		$post_id = (int) $result['data']['post_id'];
		$this->assertSame( 'Foo Bar Baz', get_post( $post_id )->post_title );
		$this->assertStringNotContainsString( '<b>', get_post( $post_id )->post_title );
	}

	public function test_real_markup_title_is_sanitized_safely(): void {
		$result = $this->upsert( '<b>Kanika &amp; Moore</b> Trio' );

		$this->assertTrue( (bool) ( $result['success'] ?? false ) );
		$post_id = (int) $result['data']['post_id'];
		$stored  = get_post( $post_id )->post_title;
		$this->assertStringNotContainsString( '<', $stored );
		$this->assertSame( 'Kanika & Moore Trio', $stored );
	}

	public function test_dedup_hashes_encoded_and_decoded_titles_identically(): void {
		// The legacy row stores the entity-bearing form.
		$existing_id = $this->seed_entity_titled_event( 'Jordan Igoe &amp; Friends' );

		// Re-ingestion sends the decoded form for the same event.
		$result = $this->upsert( 'Jordan Igoe & Friends' );

		$this->assertTrue( (bool) ( $result['success'] ?? false ) );
		$this->assertSame( $existing_id, (int) $result['data']['post_id'], 'The decoded title must resolve to the existing event, not create a duplicate.' );
	}

	public function test_active_kses_filter_cannot_reencode_stored_title(): void {
		// Simulate a writing context without unfiltered_html: wp_filter_kses
		// re-encodes bare "&" on title_save_pre. The ingestion write must
		// suspend it so the stored title stays decoded.
		add_filter( 'title_save_pre', 'wp_filter_kses' );

		try {
			$result = $this->upsert( 'Power Pilates & Matcha' );
		} finally {
			remove_filter( 'title_save_pre', 'wp_filter_kses' );
		}

		$this->assertTrue( (bool) ( $result['success'] ?? false ) );
		$post_id = (int) $result['data']['post_id'];
		$this->assertSame( 'Power Pilates & Matcha', get_post( $post_id )->post_title );
	}
}
