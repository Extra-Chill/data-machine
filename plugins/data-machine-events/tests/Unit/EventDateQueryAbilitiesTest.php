<?php
/**
 * Event date query ability tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Abilities\EventDateQueryAbilities;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Venue_Taxonomy;

class EventDateQueryAbilitiesTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
	}

	private function seed_event( string $title, string $status, string $start, ?string $end = null, int $venue_id = 0 ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => $status,
			)
		);
		EventDatesTable::upsert( $post_id, $start, $end, $status );

		if ( $venue_id > 0 ) {
			wp_set_object_terms( $post_id, array( $venue_id ), 'venue' );
		}

		return $post_id;
	}

	public function test_public_query_is_publish_only_for_anonymous_callers_and_returns_structured_events(): void {
		$now       = current_datetime();
		$published = $this->seed_event( 'Published event', 'publish', $now->modify( '+1 day' )->format( 'Y-m-d H:i:s' ) );
		$this->seed_event( 'Draft event', 'draft', $now->modify( '+2 days' )->format( 'Y-m-d H:i:s' ) );
		$this->seed_event( 'Private event', 'private', $now->modify( '+3 days' )->format( 'Y-m-d H:i:s' ) );
		wp_set_current_user( 0 );

		$result = ( new EventDateQueryAbilities() )->executePublicQueryEvents(
			array(
				'scope'      => 'upcoming',
				'status'     => 'any',
				'meta_query' => array( array( 'key' => '_private_control' ) ),
			)
		);

		$this->assertSame( 1, $result['post_count'] );
		$this->assertSame( $published, $result['posts'][0]['event_id'] );
		$this->assertSame(
			array( 'event_id', 'title', 'permalink', 'start_datetime', 'end_datetime' ),
			array_keys( $result['posts'][0] )
		);
		$this->assertNotInstanceOf( \WP_Post::class, $result['posts'][0] );
	}

	public function test_internal_query_contract_preserves_privileged_callers(): void {
		$draft = $this->seed_event( 'Operational draft', 'draft', current_datetime()->modify( '+1 day' )->format( 'Y-m-d H:i:s' ) );
		update_post_meta( $draft, '_operational_scope', 'yes' );

		$result = ( new EventDateQueryAbilities() )->executeQueryEvents(
			array(
				'scope'      => 'upcoming',
				'status'     => 'any',
				'meta_query' => array(
					array(
						'key'   => '_operational_scope',
						'value' => 'yes',
					),
				),
			)
		);

		$this->assertSame( 1, $result['post_count'] );
		$this->assertInstanceOf( \WP_Post::class, $result['posts'][0] );
		$this->assertSame( $draft, $result['posts'][0]->ID );
	}

	public function test_empty_geo_match_returns_zero_unless_fallback_is_explicit(): void {
		$venue = wp_insert_term( 'Far venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );
		$venue_id = (int) $venue['term_id'];
		add_term_meta( $venue_id, '_venue_coordinates', '40.7128,-74.0060', true );
		$event_id = $this->seed_event(
			'Far event',
			'publish',
			current_datetime()->modify( '+1 day' )->format( 'Y-m-d H:i:s' ),
			null,
			$venue_id
		);
		$ability = new EventDateQueryAbilities();
		$geo     = array(
			'lat'    => 0,
			'lng'    => 0,
			'radius' => 1,
			'unit'   => 'mi',
		);

		$empty = $ability->executePublicQueryEvents( array( 'geo' => $geo ) );
		$this->assertSame( 0, $empty['post_count'] );

		$geo['empty_result_behavior'] = 'ignore_geo';
		$fallback                     = $ability->executePublicQueryEvents( array( 'geo' => $geo ) );
		$this->assertSame( array( $event_id ), array_column( $fallback['posts'], 'event_id' ) );
	}

	/**
	 * @dataProvider invalid_geo_inputs
	 */
	public function test_invalid_geo_never_falls_back_to_unrestricted_results( array $geo ): void {
		$this->seed_event( 'Unrelated published event', 'publish', current_datetime()->modify( '+1 day' )->format( 'Y-m-d H:i:s' ) );

		$result = ( new EventDateQueryAbilities() )->executePublicQueryEvents( array( 'geo' => $geo ) );

		$this->assertSame( 0, $result['post_count'] );
		$this->assertSame( array(), $result['posts'] );
	}

	public static function invalid_geo_inputs(): array {
		return array(
			'empty geo object'       => array( array() ),
			'latitude only'          => array( array( 'lat' => 32.7765 ) ),
			'longitude only'         => array( array( 'lng' => -79.9311 ) ),
			'nonnumeric latitude'    => array( array( 'lat' => 'north', 'lng' => -79.9311 ) ),
			'nonnumeric longitude'   => array( array( 'lat' => 32.7765, 'lng' => 'west' ) ),
			'NaN-like latitude'      => array( array( 'lat' => 'NaN', 'lng' => -79.9311 ) ),
			'actual NaN latitude'    => array( array( 'lat' => NAN, 'lng' => -79.9311 ) ),
			'latitude out of range'  => array( array( 'lat' => 90.1, 'lng' => -79.9311 ) ),
			'longitude out of range' => array( array( 'lat' => 32.7765, 'lng' => -180.1 ) ),
		);
	}

	public function test_invalid_geo_can_only_fall_back_when_explicitly_requested(): void {
		$event_id = $this->seed_event( 'Explicit fallback event', 'publish', current_datetime()->modify( '+1 day' )->format( 'Y-m-d H:i:s' ) );

		$result = ( new EventDateQueryAbilities() )->executePublicQueryEvents(
			array(
				'geo' => array(
					'empty_result_behavior' => 'ignore_geo',
				),
			)
		);

		$this->assertSame( array( $event_id ), array_column( $result['posts'], 'event_id' ) );
	}

	public function test_upcoming_preserves_ongoing_and_excludes_events_ended_earlier_today(): void {
		$old_timezone = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );

		try {
			$now       = current_datetime();
			$ongoing   = $this->seed_event(
				'Ongoing event',
				'publish',
				$now->modify( '-1 day' )->format( 'Y-m-d H:i:s' ),
				$now->modify( '+1 hour' )->format( 'Y-m-d H:i:s' )
			);
			$completed = $this->seed_event(
				'Ended today',
				'publish',
				$now->setTime( 0, 0 )->format( 'Y-m-d H:i:s' ),
				$now->modify( '-1 minute' )->format( 'Y-m-d H:i:s' )
			);

			$ability  = new EventDateQueryAbilities();
			$upcoming = $ability->executeQueryEvents( array( 'scope' => 'upcoming', 'fields' => 'ids' ) );
			$past     = $ability->executeQueryEvents( array( 'scope' => 'past', 'fields' => 'ids' ) );

			$this->assertContains( $ongoing, $upcoming['posts'] );
			$this->assertNotContains( $completed, $upcoming['posts'] );
			$this->assertContains( $completed, $past['posts'] );
			$this->assertNotContains( $ongoing, $past['posts'] );
		} finally {
			update_option( 'timezone_string', $old_timezone );
		}
	}

	public function test_days_ahead_uses_site_timezone_calendar_boundary(): void {
		$old_timezone = get_option( 'timezone_string' );
		$utc_hour     = (int) gmdate( 'G' );
		$timezone     = $utc_hour < 10 ? 'Pacific/Pago_Pago' : 'Pacific/Kiritimati';
		update_option( 'timezone_string', $timezone );

		try {
			$site_now = current_datetime();
			$inside   = $this->seed_event( 'Inside site boundary', 'publish', $site_now->modify( '+1 day' )->setTime( 23, 0 )->format( 'Y-m-d H:i:s' ) );
			$outside  = $this->seed_event( 'Outside site boundary', 'publish', $site_now->modify( '+2 days' )->setTime( 1, 0 )->format( 'Y-m-d H:i:s' ) );

			$result = ( new EventDateQueryAbilities() )->executeQueryEvents(
				array(
					'scope'      => 'upcoming',
					'days_ahead' => 1,
					'fields'     => 'ids',
				)
			);

			$this->assertContains( $inside, $result['posts'] );
			$this->assertNotContains( $outside, $result['posts'] );
		} finally {
			update_option( 'timezone_string', $old_timezone );
		}
	}

	public function test_exact_date_pages_are_indexable_bounded_and_deterministic(): void {
		$date     = '2026-05-24';
		$post_ids = array();
		for ( $index = 0; $index < 3; ++$index ) {
			$post_ids[] = $this->seed_event( 'Paged event ' . $index, 'publish', $date . ' 20:00:00' );
		}

		$queries  = array();
		$observer = static function ( string $sql ) use ( &$queries ): string {
			if ( false !== strpos( $sql, EventDatesTable::table_name() ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $observer );
		try {
			$result = ( new EventDateQueryAbilities() )->executeQueryEvents(
				array(
					'date_match' => $date,
					'per_page'   => 2,
					'page'       => 2,
					'fields'     => 'ids',
				)
			);
		} finally {
			remove_filter( 'query', $observer );
		}

		$this->assertSame( array( $post_ids[2] ), array_map( 'intval', $result['posts'] ) );
		$this->assertCount( 1, $queries );
		$this->assertMatchesRegularExpression( '/LIMIT\s+2\s*,\s*2\b/i', $queries[0] );
		$this->assertStringContainsString( 'start_datetime >=', $queries[0] );
		$this->assertStringContainsString( 'start_datetime <', $queries[0] );
		$this->assertStringNotContainsString( 'DATE(', $queries[0] );
		$this->assertMatchesRegularExpression( '/ORDER BY\s+ed\.start_datetime ASC,\s*[^\s]+\.ID ASC/i', $queries[0] );
	}

	public function test_upcoming_taxonomy_query_bounds_candidates_and_preserves_related_event_semantics(): void {
		$venue = wp_insert_term( 'Related venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );
		$venue_id = (int) $venue['term_id'];
		$now      = current_datetime();
		$current  = $this->seed_event( 'Current related event', 'publish', $now->modify( '+1 hour' )->format( 'Y-m-d H:i:s' ), null, $venue_id );
		$ongoing  = $this->seed_event( 'Ongoing related event', 'publish', $now->modify( '-1 day' )->format( 'Y-m-d H:i:s' ), $now->modify( '+1 hour' )->format( 'Y-m-d H:i:s' ), $venue_id );
		$future   = $this->seed_event( 'Future related event', 'publish', $now->modify( '+2 days' )->format( 'Y-m-d H:i:s' ), null, $venue_id );
		$this->seed_event( 'Past related event', 'publish', $now->modify( '-2 days' )->format( 'Y-m-d H:i:s' ), $now->modify( '-1 day' )->format( 'Y-m-d H:i:s' ), $venue_id );
		$this->seed_event( 'Draft related event', 'draft', $now->modify( '+3 days' )->format( 'Y-m-d H:i:s' ), null, $venue_id );
		$this->seed_event( 'Unrelated event', 'publish', $now->modify( '+4 days' )->format( 'Y-m-d H:i:s' ) );

		$queries  = array();
		$observer = static function ( string $sql ) use ( &$queries ): string {
			if ( false !== strpos( $sql, EventDatesTable::table_name() ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $observer );
		try {
			$result = ( new EventDateQueryAbilities() )->executeQueryEvents(
				array(
					'scope'       => 'upcoming',
					'tax_filters' => array( 'venue' => array( $venue_id ) ),
					'exclude'     => array( $current ),
					'per_page'    => 20,
					'fields'      => 'ids',
					'order'       => 'ASC',
				)
			);
		} finally {
			remove_filter( 'query', $observer );
		}

		$this->assertSame( array( $ongoing, $future ), $result['posts'] );
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'FORCE INDEX (term_taxonomy_id)', $queries[0] );
		$this->assertStringContainsString( "ed.post_status = 'publish'", $queries[0] );
		$this->assertStringContainsString( 'ed.end_datetime >=', $queries[0] );
		$this->assertStringContainsString( 'GROUP BY ed.post_id, ed.start_datetime', $queries[0] );
		$this->assertMatchesRegularExpression( '/ORDER BY ed\.start_datetime ASC, ed\.post_id ASC\s+LIMIT 100 OFFSET 0/i', $queries[0] );
		$this->assertStringNotContainsString( $GLOBALS['wpdb']->posts, $queries[0] );
	}

	public function test_upcoming_multi_term_taxonomy_candidates_are_deduplicated(): void {
		$first_term  = wp_insert_term( 'Related multi-term A ' . uniqid(), 'venue' );
		$second_term = wp_insert_term( 'Related multi-term B ' . uniqid(), 'venue' );
		$this->assertNotWPError( $first_term );
		$this->assertNotWPError( $second_term );

		$now       = current_datetime();
		$first_id  = $this->seed_event( 'First multi-term related event', 'publish', $now->modify( '+1 day' )->format( 'Y-m-d H:i:s' ) );
		$second_id = $this->seed_event( 'Second multi-term related event', 'publish', $now->modify( '+2 days' )->format( 'Y-m-d H:i:s' ) );
		wp_set_object_terms( $first_id, array( (int) $first_term['term_id'], (int) $second_term['term_id'] ), 'venue' );
		wp_set_object_terms( $second_id, array( (int) $second_term['term_id'] ), 'venue' );

		$queries  = array();
		$observer = static function ( string $sql ) use ( &$queries ): string {
			if ( false !== strpos( $sql, EventDatesTable::table_name() ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $observer );
		try {
			$result = ( new EventDateQueryAbilities() )->executeQueryEvents(
				array(
					'scope'       => 'upcoming',
					'tax_filters' => array(
						'venue' => array( (int) $first_term['term_id'], (int) $second_term['term_id'], (int) $second_term['term_id'] ),
					),
					'per_page'    => 20,
					'fields'      => 'ids',
				)
			);
		} finally {
			remove_filter( 'query', $observer );
		}

		$this->assertSame( array( $first_id, $second_id ), $result['posts'] );
		$this->assertCount( 1, $queries );
		$matched = preg_match( '/term_taxonomy_id IN \((\d+(?:,\d+)*)\)/', $queries[0], $matches );
		$this->assertSame( 1, $matched );
		$taxonomy_ids = isset( $matches[1] ) ? array_map( 'intval', explode( ',', $matches[1] ) ) : array();
		$this->assertEqualsCanonicalizing(
			array( (int) $first_term['term_taxonomy_id'], (int) $second_term['term_taxonomy_id'] ),
			$taxonomy_ids
		);
	}

	public function test_paginated_upcoming_taxonomy_query_uses_canonical_wp_query(): void {
		$venue = wp_insert_term( 'Paginated related venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );
		$venue_id = (int) $venue['term_id'];
		$now      = current_datetime();
		$this->seed_event( 'First paginated related event', 'publish', $now->modify( '+1 day' )->format( 'Y-m-d H:i:s' ), null, $venue_id );
		$second = $this->seed_event( 'Second paginated related event', 'publish', $now->modify( '+2 days' )->format( 'Y-m-d H:i:s' ), null, $venue_id );

		$queries  = array();
		$observer = static function ( string $sql ) use ( &$queries ): string {
			if ( false !== strpos( $sql, EventDatesTable::table_name() ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $observer );
		try {
			$result = ( new EventDateQueryAbilities() )->executeQueryEvents(
				array(
					'scope'       => 'upcoming',
					'tax_filters' => array( 'venue' => array( $venue_id ) ),
					'per_page'    => 1,
					'page'        => 2,
					'fields'      => 'ids',
				)
			);
		} finally {
			remove_filter( 'query', $observer );
		}

		$this->assertSame( array( $second ), array_map( 'intval', $result['posts'] ) );
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( $GLOBALS['wpdb']->posts, $queries[0] );
		$this->assertStringContainsString( $GLOBALS['wpdb']->term_relationships, $queries[0] );
		$this->assertStringNotContainsString( 'FORCE INDEX (term_taxonomy_id)', $queries[0] );
		$this->assertMatchesRegularExpression( '/LIMIT\s+1\s*,\s*1\b/i', $queries[0] );
	}

	public function test_count_query_skips_found_rows_and_uses_event_date_status_index(): void {
		$now = current_datetime();
		$this->seed_event( 'Published past event', 'publish', $now->modify( '-2 days' )->format( 'Y-m-d H:i:s' ) );
		$this->seed_event( 'Published future event', 'publish', $now->modify( '+2 days' )->format( 'Y-m-d H:i:s' ) );
		$this->seed_event( 'Draft past event', 'draft', $now->modify( '-3 days' )->format( 'Y-m-d H:i:s' ) );

		$query_args = array();
		$args_filter = static function ( array $args ) use ( &$query_args ): array {
			$query_args[] = $args;
			return $args;
		};
		add_filter( 'data_machine_events_calendar_query_args', $args_filter );

		$queries  = array();
		$observer = static function ( string $sql ) use ( &$queries ): string {
			if ( false !== strpos( $sql, EventDatesTable::table_name() ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $observer );
		try {
			$result = ( new EventDateQueryAbilities() )->executeQueryEvents(
				array(
					'scope'  => 'past',
					'fields' => 'count',
				)
			);
		} finally {
			remove_filter( 'data_machine_events_calendar_query_args', $args_filter );
			remove_filter( 'query', $observer );
		}

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( array(), $result['posts'] );
		$this->assertSame( 0, $result['post_count'] );
		$this->assertNotEmpty( $query_args );
		$this->assertTrue( $query_args[0]['no_found_rows'] );
		$this->assertCount( 1, $queries );
		$this->assertMatchesRegularExpression( '/SELECT\s+COUNT\(\*\)/i', $queries[0] );
		$this->assertStringContainsString( 'STRAIGHT_JOIN', $queries[0] );
		$this->assertStringContainsString( "ed.post_status = 'publish'", $queries[0] );
		$this->assertStringNotContainsString( 'SQL_CALC_FOUND_ROWS', $queries[0] );
		$this->assertStringNotContainsString( 'FOUND_ROWS()', $queries[0] );
		$this->assertStringNotContainsString( ' LIMIT ', strtoupper( $queries[0] ) );
		$this->assertStringNotContainsString( ' ORDER BY ', strtoupper( $queries[0] ) );
	}

	public function test_matching_ids_sql_preserves_consumer_constraints_without_querying(): void {
		wp_load_alloptions();
		$filter = static function ( array $query_args, array $input ): array {
			if ( 'sql-capture-scope' === ( $input['scope_token'] ?? '' ) ) {
				$query_args['post__in'] = array( 123, 456 );
			}
			return $query_args;
		};
		add_filter( 'data_machine_events_calendar_query_args', $filter, 10, 2 );
		$executed_queries = array();
		$query_observer   = static function ( string $query ) use ( &$executed_queries ): string {
			$executed_queries[] = $query;
			return $query;
		};
		add_filter( 'query', $query_observer );
		try {
			$sql = ( new EventDateQueryAbilities() )->buildMatchingPostIdsSql(
				array(
					'scope' => 'upcoming',
					'scope_token' => 'sql-capture-scope',
					'search' => 'needle',
				)
			);
		} finally {
			remove_filter( 'data_machine_events_calendar_query_args', $filter, 10 );
			remove_filter( 'query', $query_observer );
		}

		$this->assertSame( array(), $executed_queries );
		$this->assertMatchesRegularExpression( '/SELECT\s+DISTINCT/', $sql );
		$this->assertStringContainsString( '123,456', str_replace( ' ', '', $sql ) );
		$this->assertStringContainsString( 'needle', $sql );
		$this->assertStringNotContainsString( ' LIMIT ', strtoupper( $sql ) );
		$this->assertStringNotContainsString( ' ORDER BY ', strtoupper( $sql ) );
	}

	public function test_matching_ids_sql_handles_large_consumer_sets_without_materializing_results(): void {
		wp_load_alloptions();
		$large_set = range( 1, 17000 );
		$filter    = static function ( array $query_args ) use ( $large_set ): array {
			$query_args['post__in'] = $large_set;
			return $query_args;
		};
		add_filter( 'data_machine_events_calendar_query_args', $filter );
		$executed_queries = array();
		$query_observer   = static function ( string $query ) use ( &$executed_queries ): string {
			$executed_queries[] = $query;
			return $query;
		};
		add_filter( 'query', $query_observer );
		try {
			$sql = ( new EventDateQueryAbilities() )->buildMatchingPostIdsSql( array( 'scope_token' => 'large-set' ) );
		} finally {
			remove_filter( 'data_machine_events_calendar_query_args', $filter );
			remove_filter( 'query', $query_observer );
		}

		$this->assertSame( array(), $executed_queries );
		$this->assertStringContainsString( '17000', $sql );
		$this->assertStringNotContainsString( '%d', $sql );
		$this->assertStringNotContainsString( ' LIMIT ', strtoupper( $sql ) );
	}
}
