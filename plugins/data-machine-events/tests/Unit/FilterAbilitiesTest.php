<?php
/**
 * Filter option ability tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DateTimeImmutable;
use WP_UnitTestCase;
use DataMachineEvents\Abilities\FilterAbilities;
use DataMachineEvents\Blocks\Calendar\Query\ScopeResolver;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Venue_Taxonomy;

class FilterAbilitiesTest extends WP_UnitTestCase {

	private FilterAbilities $abilities;

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		foreach ( array( 'filter_group', 'filter_kind' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, Event_Post_Type::POST_TYPE, array( 'public' => true ) );
			}
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}

		$this->abilities = new FilterAbilities();
	}

	private function seed_event( string $title, string $start, array $terms ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		EventDatesTable::upsert( $post_id, $start, ( new DateTimeImmutable( $start ) )->modify( '+2 hours' )->format( 'Y-m-d H:i:s' ), 'publish' );
		foreach ( $terms as $taxonomy => $term_ids ) {
			wp_set_object_terms( $post_id, (array) $term_ids, $taxonomy );
		}
		return $post_id;
	}

	private function create_term( string $taxonomy ): int {
		$term = wp_insert_term( $taxonomy . ' ' . uniqid(), $taxonomy );
		$this->assertNotWPError( $term );
		return (int) $term['term_id'];
	}

	private function term_count( array $result, string $taxonomy, int $term_id ): int {
		$terms = FilterAbilities::flatten_hierarchy( $result['taxonomies'][ $taxonomy ]['terms'] ?? array() );
		foreach ( $terms as $term ) {
			if ( $term_id === (int) $term['term_id'] ) {
				return (int) $term['event_count'];
			}
		}
		return 0;
	}

	public function test_search_limits_offered_term_counts(): void {
		$term_id  = $this->create_term( 'filter_kind' );
		$tomorrow = ( new DateTimeImmutable( current_time( 'mysql' ) ) )->modify( '+1 day' );
		$this->seed_event( 'Needle performance', $tomorrow->format( 'Y-m-d 20:00:00' ), array( 'filter_kind' => $term_id ) );
		$this->seed_event( 'Different performance', $tomorrow->format( 'Y-m-d 21:00:00' ), array( 'filter_kind' => $term_id ) );

		$result = $this->abilities->executeGetFilterOptions( array( 'event_search' => 'Needle' ) );

		$this->assertSame( 1, $this->term_count( $result, 'filter_kind', $term_id ) );
	}

	/**
	 * @dataProvider named_scope_provider
	 */
	public function test_each_named_scope_limits_offered_terms( string $scope ): void {
		$resolved = ScopeResolver::resolve( $scope );
		$this->assertIsArray( $resolved );
		$term_id = $this->create_term( 'filter_kind' );
		$time    = $resolved['time_start'] ?? '12:00:00';
		$this->seed_event( "{$scope} match", $resolved['date_start'] . ' ' . $time, array( 'filter_kind' => $term_id ) );
		$this->seed_event( "{$scope} outside", '2035-01-01 12:00:00', array( 'filter_kind' => $term_id ) );

		$result = $this->abilities->executeGetFilterOptions( array( 'scope' => $scope ) );

		$this->assertSame( 1, $this->term_count( $result, 'filter_kind', $term_id ) );
	}

	public static function named_scope_provider(): array {
		return array(
			'today'        => array( 'today' ),
			'tonight'      => array( 'tonight' ),
			'this weekend' => array( 'this-weekend' ),
			'this week'    => array( 'this-week' ),
		);
	}

	public function test_combined_search_scope_date_archive_and_taxonomy_constraints(): void {
		$scope       = ScopeResolver::resolve( 'this-week' );
		$group_id    = $this->create_term( 'filter_group' );
		$other_group = $this->create_term( 'filter_group' );
		$kind_id     = $this->create_term( 'filter_kind' );
		$date        = $scope['date_start'];
		$this->seed_event( 'Combined needle', "{$date} 12:00:00", array( 'filter_group' => $group_id, 'filter_kind' => $kind_id ) );
		$this->seed_event( 'Combined needle wrong archive', "{$date} 13:00:00", array( 'filter_group' => $other_group, 'filter_kind' => $kind_id ) );
		$this->seed_event( 'Wrong search', "{$date} 14:00:00", array( 'filter_group' => $group_id, 'filter_kind' => $kind_id ) );

		$result = $this->abilities->executeGetFilterOptions(
			array(
				'event_search'     => 'Combined needle',
				'scope'            => 'this-week',
				'date_context'     => array( 'date_start' => $date, 'date_end' => $date ),
				'archive_taxonomy' => 'filter_group',
				'archive_term_id'  => $group_id,
			)
		);

		$this->assertSame( 1, $this->term_count( $result, 'filter_kind', $kind_id ) );
	}

	public function test_opaque_scope_token_reaches_authoritative_query_filter(): void {
		$term_id  = $this->create_term( 'filter_kind' );
		$tomorrow = ( new DateTimeImmutable( current_time( 'mysql' ) ) )->modify( '+1 day' );
		$allowed  = $this->seed_event( 'Allowed scoped event', $tomorrow->format( 'Y-m-d 20:00:00' ), array( 'filter_kind' => $term_id ) );
		$this->seed_event( 'Excluded scoped event', $tomorrow->format( 'Y-m-d 21:00:00' ), array( 'filter_kind' => $term_id ) );

		$filter = static function ( array $query_args, array $input ) use ( $allowed ): array {
			if ( 'signed-scope' === ( $input['scope_token'] ?? '' ) ) {
				$query_args['post__in'] = array( $allowed );
			}
			return $query_args;
		};
		add_filter( 'data_machine_events_calendar_query_args', $filter, 10, 2 );
		$result = $this->abilities->executeGetFilterOptions( array( 'scope_token' => 'signed-scope' ) );
		remove_filter( 'data_machine_events_calendar_query_args', $filter, 10 );

		$this->assertSame( 1, $this->term_count( $result, 'filter_kind', $term_id ) );
	}

	public function test_geo_intersects_active_venue_when_counting_other_taxonomies(): void {
		$selected_venue = $this->create_term( 'venue' );
		$nearby_venue   = $this->create_term( 'venue' );
		$kind_id        = $this->create_term( 'filter_kind' );
		add_term_meta( $selected_venue, '_venue_coordinates', '32.7765,-79.9311', true );
		add_term_meta( $nearby_venue, '_venue_coordinates', '32.7800,-79.9300', true );

		$tomorrow = ( new DateTimeImmutable( current_time( 'mysql' ) ) )->modify( '+1 day' );
		$this->seed_event(
			'Selected nearby venue',
			$tomorrow->format( 'Y-m-d 20:00:00' ),
			array( 'venue' => $selected_venue, 'filter_kind' => $kind_id )
		);
		$this->seed_event(
			'Unselected nearby venue',
			$tomorrow->format( 'Y-m-d 21:00:00' ),
			array( 'venue' => $nearby_venue, 'filter_kind' => $kind_id )
		);

		$result = $this->abilities->executeGetFilterOptions(
			array(
				'active_filters' => array( 'venue' => array( $selected_venue ) ),
				'geo_lat'        => '32.7765',
				'geo_lng'        => '-79.9311',
				'geo_radius'     => 10,
			)
		);

		$this->assertSame( 1, $this->term_count( $result, 'filter_kind', $kind_id ) );
	}
}
