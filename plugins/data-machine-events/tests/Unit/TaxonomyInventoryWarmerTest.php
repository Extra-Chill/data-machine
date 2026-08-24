<?php
/**
 * Taxonomy Inventory Warmer Tests
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\UpcomingCountAbilities;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Tasks\TaxonomyInventoryWarmer;
use WP_UnitTestCase;

class TaxonomyInventoryWarmerTest extends WP_UnitTestCase {

	private TaxonomyInventoryWarmer $warmer;

	public function setUp(): void {
		parent::setUp();
		TaxonomyInventoryWarmer::flushPending( static fn() => 1 );
		$this->registerEventObjects();
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
		$this->warmer = new TaxonomyInventoryWarmer();
	}

	public function test_finite_inventory_shapes_cover_defaults_and_location_root_variant(): void {
		$shapes = UpcomingCountAbilities::inventoryCacheShapes();

		$this->assertSame(
			array(
				array( 'taxonomy' => 'location', 'exclude_roots' => true ),
				array( 'taxonomy' => 'location', 'exclude_roots' => false ),
				array( 'taxonomy' => 'venue', 'exclude_roots' => false ),
				array( 'taxonomy' => 'artist', 'exclude_roots' => false ),
				array( 'taxonomy' => 'festival', 'exclude_roots' => false ),
			),
			$shapes
		);

		$keys = array_map(
			static fn( array $shape ): string => UpcomingCountAbilities::inventoryCacheKey( $shape['taxonomy'], $shape['exclude_roots'] ),
			$shapes
		);
		$this->assertCount( 5, array_unique( $keys ) );
	}

	public function test_repeated_invalidations_coalesce_to_latest_generation_for_site(): void {
		$site_id = get_current_blog_id();
		TaxonomyInventoryWarmer::queueGeneration( 'old', 'generation-one' );
		TaxonomyInventoryWarmer::queueGeneration( 'generation-one', 'generation-two' );
		$calls = array();

		TaxonomyInventoryWarmer::flushPending(
			static function ( $task_type, $params, $context, $parent_job_id, $operation_key ) use ( &$calls ) {
				$calls[] = compact( 'task_type', 'params', 'context', 'parent_job_id', 'operation_key' );
				return 123;
			}
		);

		$this->assertCount( 1, $calls );
		$this->assertSame( 'generation-two', $calls[0]['params']['generation'] );
		$this->assertSame( $site_id, $calls[0]['params']['site_id'] );
		$this->assertSame( array(), $calls[0]['context'] );
		$this->assertSame( 0, $calls[0]['parent_job_id'] );
		$this->assertSame( TaxonomyInventoryWarmer::operationKey( $site_id, 'generation-two' ), $calls[0]['operation_key'] );

		TaxonomyInventoryWarmer::flushPending( static function () use ( &$calls ): int {
			$calls[] = array( 'unexpected' => true );
			return 124;
		} );
		$this->assertCount( 1, $calls, 'A flushed generation must not schedule twice.' );
	}

	public function test_shutdown_dispatch_uses_zero_arguments_and_init_is_idempotent(): void {
		global $wp_filter;

		$callbacks = $wp_filter['shutdown']->callbacks;
		TaxonomyInventoryWarmer::init();
		TaxonomyInventoryWarmer::init();
		$this->assertSame( $callbacks, $wp_filter['shutdown']->callbacks );
		$callback_id = _wp_filter_build_unique_id( 'shutdown', array( TaxonomyInventoryWarmer::class, 'flushPending' ), 10 );
		$accepted_args = $wp_filter['shutdown']->callbacks[10][ $callback_id ]['accepted_args'];
		$this->assertSame( 0, $accepted_args );

		$shutdown_hook = new \WP_Hook();
		$shutdown_hook->add_filter( 'shutdown', array( TaxonomyInventoryWarmer::class, 'flushPending' ), 10, $accepted_args );
		$shutdown_hook->do_action( array( '' ) );
	}

	public function test_pending_generations_remain_separate_per_multisite_site(): void {
		$first_site = get_current_blog_id();
		$second_site = self::factory()->blog->create();
		TaxonomyInventoryWarmer::queueGeneration( 'old-first', 'first-generation' );

		switch_to_blog( $second_site );
		try {
			TaxonomyInventoryWarmer::queueGeneration( 'old-second', 'second-generation' );
		} finally {
			restore_current_blog();
		}

		$calls = array();
		TaxonomyInventoryWarmer::flushPending(
			static function ( $task_type, $params ) use ( &$calls ): int {
				$calls[ $params['site_id'] ] = array(
					'generation'   => $params['generation'],
					'current_site' => get_current_blog_id(),
				);
				return count( $calls );
			}
		);

		$this->assertSame( 'first-generation', $calls[ $first_site ]['generation'] );
		$this->assertSame( $first_site, $calls[ $first_site ]['current_site'] );
		$this->assertSame( 'second-generation', $calls[ $second_site ]['generation'] );
		$this->assertSame( $second_site, $calls[ $second_site ]['current_site'] );
		$this->assertSame( $first_site, get_current_blog_id() );
	}

	public function test_scheduler_exception_restores_site_and_does_not_replay_pending_generation(): void {
		$first_site  = get_current_blog_id();
		$second_site = self::factory()->blog->create();
		switch_to_blog( $second_site );
		try {
			TaxonomyInventoryWarmer::queueGeneration( 'old', 'exception-generation' );
		} finally {
			restore_current_blog();
		}

		$calls = 0;
		try {
			TaxonomyInventoryWarmer::flushPending(
				static function () use ( &$calls ): void {
					++$calls;
					throw new \RuntimeException( 'Scheduler failure' );
				}
			);
			$this->fail( 'Expected scheduler exception.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'Scheduler failure', $exception->getMessage() );
		}

		$this->assertSame( $first_site, get_current_blog_id() );
		TaxonomyInventoryWarmer::flushPending(
			static function () use ( &$calls ): int {
				++$calls;
				return 1;
			}
		);
		$this->assertSame( 1, $calls, 'Failed pending work must not be scheduled twice in one request.' );
	}

	public function test_task_retry_policy_is_bounded_and_does_not_affect_other_tasks(): void {
		$base = array(
			'retryable'    => false,
			'max_attempts' => 9,
		);
		$ours = TaxonomyInventoryWarmer::filterRetryPolicy(
			$base,
			1,
			'failed',
			array( 'task_type' => TaxonomyInventoryWarmer::TASK_TYPE ),
			array(),
			array()
		);

		$this->assertTrue( $ours['retryable'] );
		$this->assertSame( 3, $ours['max_attempts'] );
		$this->assertSame( 30, $ours['base_delay'] );
		$this->assertSame( $base, TaxonomyInventoryWarmer::filterRetryPolicy( $base, 2, 'failed', array( 'task_type' => 'other' ), array(), array() ) );
	}

	public function test_full_cache_lifecycle_warms_all_keys_without_recursive_invalidation(): void {
		$event_id = $this->seedEventWithAllTaxonomies();
		$this->assertGreaterThan( 0, $event_id );
		CalendarCache::invalidate();
		$generation = CalendarCache::get_generation();
		TaxonomyInventoryWarmer::flushPending( static fn() => 1 );

		$invalidations = 0;
		$observer = static function () use ( &$invalidations ): void {
			++$invalidations;
		};
		add_action( 'update_option_' . CalendarCache::GENERATION_OPTION, $observer );
		try {
			$result = $this->warmer->warmGeneration( get_current_blog_id(), $generation );
		} finally {
			remove_action( 'update_option_' . CalendarCache::GENERATION_OPTION, $observer );
		}

		$this->assertIsArray( $result );
		$this->assertCount( 5, $result['warmed'] );
		$this->assertSame( 0, $invalidations );
		foreach ( UpcomingCountAbilities::inventoryCacheShapes() as $shape ) {
			$key = UpcomingCountAbilities::inventoryCacheKey( $shape['taxonomy'], $shape['exclude_roots'] );
			$this->assertIsArray( CalendarCache::get( $key, $generation ) );
		}

		CalendarCache::invalidate();
		$new_generation = CalendarCache::get_generation();
		$this->assertNotSame( $generation, $new_generation );
		foreach ( UpcomingCountAbilities::inventoryCacheShapes() as $shape ) {
			$key = UpcomingCountAbilities::inventoryCacheKey( $shape['taxonomy'], $shape['exclude_roots'] );
			$this->assertFalse( CalendarCache::get( $key, $new_generation ) );
			$this->assertIsArray( CalendarCache::get( $key, $generation ) );
		}

		$warmed_again = $this->warmer->warmGeneration( get_current_blog_id(), $new_generation );
		$this->assertIsArray( $warmed_again );
		$this->assertCount( 5, $warmed_again['warmed'] );
	}

	public function test_superseded_task_skips_without_populating_current_generation(): void {
		CalendarCache::invalidate();
		$old_generation = CalendarCache::get_generation();
		CalendarCache::invalidate();
		$current_generation = CalendarCache::get_generation();

		$result = $this->warmer->warmGeneration( get_current_blog_id(), $old_generation );

		$this->assertSame( array( 'skipped' => 'superseded_generation' ), $result );
		foreach ( UpcomingCountAbilities::inventoryCacheShapes() as $shape ) {
			$key = UpcomingCountAbilities::inventoryCacheKey( $shape['taxonomy'], $shape['exclude_roots'] );
			$this->assertFalse( CalendarCache::get( $key, $current_generation ) );
		}
	}

	public function test_invalid_scope_returns_failure_for_retry_path(): void {
		$result = $this->warmer->warmGeneration( 0, 'generation' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_warm_scope', $result->get_error_code() );
	}

	private function registerEventObjects(): void {
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		foreach ( array( 'artist', 'festival' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, Event_Post_Type::POST_TYPE, array( 'public' => true ) );
			} else {
				register_taxonomy_for_object_type( $taxonomy, Event_Post_Type::POST_TYPE );
			}
		}
		if ( ! taxonomy_exists( 'location' ) ) {
			register_taxonomy( 'location', Event_Post_Type::POST_TYPE, array( 'public' => true, 'hierarchical' => true ) );
		} else {
			register_taxonomy_for_object_type( 'location', Event_Post_Type::POST_TYPE );
		}
	}

	private function seedEventWithAllTaxonomies(): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$location_root = wp_insert_term( 'Warmer Region ' . uniqid(), 'location' );
		$this->assertNotWPError( $location_root );
		$location = wp_insert_term( 'Warmer City ' . uniqid(), 'location', array( 'parent' => $location_root['term_id'] ) );
		$this->assertNotWPError( $location );

		foreach ( array( 'venue', 'artist', 'festival' ) as $taxonomy ) {
			$term = wp_insert_term( 'Warmer ' . $taxonomy . ' ' . uniqid(), $taxonomy );
			$this->assertNotWPError( $term );
			wp_set_object_terms( $event_id, array( $term['term_id'] ), $taxonomy );
		}
		wp_set_object_terms( $event_id, array( $location['term_id'] ), 'location' );
		EventDatesTable::upsert( $event_id, '2099-01-01 20:00:00', null, 'publish' );

		return $event_id;
	}
}
