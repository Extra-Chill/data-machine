<?php
/**
 * Events By Term Abilities Tests
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\EventsByTermAbilities;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use WP_UnitTestCase;

class EventsByTermAbilitiesTest extends WP_UnitTestCase {
	private EventsByTermAbilities $abilities;

	public function setUp(): void {
		parent::setUp();

		EventDatesTable::create_table();
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'term_timing_test' ) ) {
			register_taxonomy( 'term_timing_test', Event_Post_Type::POST_TYPE );
		}
		$this->abilities = new EventsByTermAbilities();
	}

	private function create_term( string $taxonomy, string $slug ): int {
		$term = wp_insert_term(
			ucwords( str_replace( '-', ' ', $slug ) ),
			$taxonomy,
			array( 'slug' => $slug )
		);
		$this->assertNotWPError( $term );

		return (int) $term['term_id'];
	}

	private function execute( array $input ): array|\WP_Error {
		return $this->abilities->executeEventsByTerm( $input );
	}

	private function seed_event( string $title, int $term_id, string $start, string $end ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		EventDatesTable::upsert( $post_id, $start, $end, 'publish' );
		wp_set_object_terms( $post_id, array( $term_id ), 'term_timing_test' );

		return $post_id;
	}

	public function test_events_blog_defaults_to_current_site(): void {
		$method = new \ReflectionMethod( $this->abilities, 'resolveEventsBlogId' );
		$method->setAccessible( true );

		$this->assertSame( get_current_blog_id(), $method->invoke( $this->abilities ) );
	}

	public function test_events_blog_can_be_configured_by_consumer(): void {
		$callback = static function ( int $blog_id ): int {
			return $blog_id + 1;
		};
		add_filter( 'data_machine_events_events_blog_id', $callback );

		$method = new \ReflectionMethod( $this->abilities, 'resolveEventsBlogId' );
		$method->setAccessible( true );

		$this->assertSame( get_current_blog_id() + 1, $method->invoke( $this->abilities ) );
		remove_filter( 'data_machine_events_events_blog_id', $callback );
	}

	public function test_resolves_local_term_ids_for_multiple_taxonomies(): void {
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$slug    = 'local-' . $taxonomy . '-' . wp_generate_uuid4();
			$term_id = $this->create_term( $taxonomy, $slug );
			$result  = $this->execute(
				array(
					'taxonomy' => $taxonomy,
					'term_id'  => $term_id,
				)
			);

			$this->assertNotWPError( $result );
			$this->assertTrue( $result['found'] );
			$this->assertSame( $term_id, $result['term_id'] );
			$this->assertSame( $slug, $result['term_slug'] );
		}
	}

	public function test_term_id_returns_current_slug_after_rename(): void {
		$term_id = $this->create_term( 'category', 'before-rename' );
		$updated = wp_update_term( $term_id, 'category', array( 'slug' => 'after-rename' ) );
		$this->assertNotWPError( $updated );

		$result = $this->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( $term_id, $result['term_id'] );
		$this->assertSame( 'after-rename', $result['term_slug'] );
	}

	public function test_term_id_must_belong_to_requested_taxonomy(): void {
		$term_id = $this->create_term( 'category', 'taxonomy-owner' );
		$result  = $this->execute(
			array(
				'taxonomy' => 'post_tag',
				'term_id'  => $term_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_term_id', $result->get_error_code() );
	}

	public function test_deleted_term_id_is_rejected(): void {
		$term_id = $this->create_term( 'category', 'deleted-term' );
		$deleted = wp_delete_term( $term_id, 'category' );
		$this->assertNotWPError( $deleted );

		$result = $this->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_term_id', $result->get_error_code() );
	}

	public function test_missing_term_id_is_rejected(): void {
		$result = $this->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => 999999999,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_term_id', $result->get_error_code() );
	}

	/**
	 * @dataProvider invalid_non_positive_term_ids
	 */
	public function test_non_positive_term_id_is_rejected( int $term_id ): void {
		$result = $this->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_term_id', $result->get_error_code() );
	}

	public static function invalid_non_positive_term_ids(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -1 ),
		);
	}

	public function test_term_id_is_authoritative_over_disagreeing_slug(): void {
		$authoritative_id = $this->create_term( 'category', 'authoritative-term' );
		$this->create_term( 'category', 'different-term' );

		$result = $this->execute(
			array(
				'taxonomy'  => 'category',
				'term_id'   => $authoritative_id,
				'term_slug' => 'different-term',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( $authoritative_id, $result['term_id'] );
		$this->assertSame( 'authoritative-term', $result['term_slug'] );
	}

	public function test_legacy_term_slug_lookup_remains_supported(): void {
		$term_id = $this->create_term( 'post_tag', 'legacy-slug' );
		$result  = $this->execute(
			array(
				'taxonomy'  => 'post_tag',
				'term_slug' => 'legacy-slug',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['found'] );
		$this->assertSame( $term_id, $result['term_id'] );
		$this->assertSame( 'legacy-slug', $result['term_slug'] );
	}

	public function test_term_scopes_use_canonical_ongoing_and_completed_predicates(): void {
		$term_id   = $this->create_term( 'term_timing_test', 'timing-' . uniqid() );
		$now       = current_datetime();
		$ongoing   = $this->seed_event(
			'Ongoing term event',
			$term_id,
			$now->modify( '-1 day' )->format( 'Y-m-d H:i:s' ),
			$now->modify( '+1 hour' )->format( 'Y-m-d H:i:s' )
		);
		$completed = $this->seed_event(
			'Completed term event',
			$term_id,
			$now->modify( '-2 hours' )->format( 'Y-m-d H:i:s' ),
			$now->modify( '-1 hour' )->format( 'Y-m-d H:i:s' )
		);

		$result = $this->execute(
			array(
				'taxonomy' => 'term_timing_test',
				'term_id'  => $term_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( array( $ongoing ), array_column( $result['upcoming'], 'event_id' ) );
		$this->assertSame( array( $completed ), array_column( $result['past'], 'event_id' ) );
	}
}
