<?php
/**
 * Shared EventUpsert test scaffolding.
 *
 * Holds the handler bootstrap (post type/taxonomy registration, test-only
 * abilities, lock-query emulation) and invocation helpers reused by
 * EventUpsertTest and EventUpsertFallbackDedupeTest so each concrete test
 * class stays focused on its own scenarios instead of re-declaring setup.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.9.16
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Core\EngineData;
use WP_UnitTestCase;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Blocks\Calendar\Cache\CacheInvalidator;

abstract class EventUpsertTestCase extends WP_UnitTestCase {

	protected EventUpsert $handler;
	protected \Closure $lock_query_filter;

	public function setUp(): void {
		parent::setUp();

		// Ensure post type and taxonomies are registered
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

		// WordPress Playground's SQLite runtime does not implement MySQL named
		// locks. Unit tests emulate successful acquisition/release; contention
		// coverage overrides GET_LOCK at an earlier filter priority.
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

	/**
	 * Invoke the protected upsert entry point with normal engine context.
	 *
	 * @param array $parameters Event parameters.
	 * @param array $config     Handler configuration.
	 * @return array Upsert result.
	 */
	protected function invoke_upsert( array $parameters, array $config = array() ): array {
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

	protected function countEventsWithTitle( string $title ): int {
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
