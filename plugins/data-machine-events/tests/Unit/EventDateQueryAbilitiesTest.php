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

class EventDateQueryAbilitiesTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
	}

	public function test_matching_ids_sql_preserves_consumer_constraints_without_querying(): void {
		global $wpdb;

		$filter = static function ( array $query_args, array $input ): array {
			if ( 'sql-capture-scope' === ( $input['scope_token'] ?? '' ) ) {
				$query_args['post__in'] = array( 123, 456 );
			}
			return $query_args;
		};
		add_filter( 'data_machine_events_calendar_query_args', $filter, 10, 2 );
		$queries_before = $wpdb->num_queries;
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
		}

		$this->assertSame( $queries_before, $wpdb->num_queries );
		$this->assertStringContainsString( 'SELECT DISTINCT', $sql );
		$this->assertStringContainsString( '123,456', str_replace( ' ', '', $sql ) );
		$this->assertStringContainsString( 'needle', $sql );
		$this->assertStringNotContainsString( ' LIMIT ', strtoupper( $sql ) );
		$this->assertStringNotContainsString( ' ORDER BY ', strtoupper( $sql ) );
	}

	public function test_matching_ids_sql_handles_large_consumer_sets_without_materializing_results(): void {
		global $wpdb;

		$large_set = range( 1, 17000 );
		$filter    = static function ( array $query_args ) use ( $large_set ): array {
			$query_args['post__in'] = $large_set;
			return $query_args;
		};
		add_filter( 'data_machine_events_calendar_query_args', $filter );
		$queries_before = $wpdb->num_queries;
		try {
			$sql = ( new EventDateQueryAbilities() )->buildMatchingPostIdsSql( array( 'scope_token' => 'large-set' ) );
		} finally {
			remove_filter( 'data_machine_events_calendar_query_args', $filter );
		}

		$this->assertSame( $queries_before, $wpdb->num_queries );
		$this->assertStringContainsString( '17000', $sql );
		$this->assertStringNotContainsString( '%d', $sql );
		$this->assertStringNotContainsString( ' LIMIT ', strtoupper( $sql ) );
	}
}
