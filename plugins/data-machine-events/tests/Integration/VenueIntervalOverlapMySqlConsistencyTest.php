<?php
/**
 * Real MySQL overlap visibility coverage.
 *
 * @package DataMachineEvents\Tests\Integration
 */

namespace DataMachineEvents\Tests\Integration;

use DataMachineEvents\Abilities\VenueIntervalOverlapAbilities;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use WP_UnitTestCase;

class VenueIntervalOverlapMySqlConsistencyTest extends WP_UnitTestCase {

	public function test_overlap_query_observes_only_committed_index_updates(): void {
		global $wpdb;
		if ( ! $wpdb->dbh instanceof \mysqli ) {
			$this->markTestSkipped( 'MySQL consistency test requires mysqli.' );
		}
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}

		$table = EventDatesTable::table_name();
		$had_table  = EventDatesTable::table_exists();
		$prior_rows = $had_table ? $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Snapshot the PHPUnit temporary table for restoration.
		$writer     = null;
		$event_id   = 0;
		$venue_id   = 0;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- PHPUnit creates connection-local temporary tables by default.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated disposable test database cleanup.
			EventDatesTable::create_table();

			$venue = wp_insert_term( 'Consistency Venue ' . uniqid(), 'venue' );
			$this->assertNotWPError( $venue );
			$venue_id = (int) $venue['term_id'];
			$event_id = self::factory()->post->create(
				array(
					'post_type'   => Event_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			wp_set_object_terms( $event_id, array( $venue_id ), 'venue' );
			EventDatesTable::upsert( $event_id, '2028-01-01 10:00:00', '2028-01-01 11:00:00', 'publish' );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Make the fixture visible to the independent connection.

			$reader = new VenueIntervalOverlapAbilities();
			$input  = array(
				'venue_id' => $venue_id,
				'start'    => '2028-01-01T10:00:00+00:00',
				'end'      => '2028-01-01T12:00:00+00:00',
			);
			$this->assertSame( array( $event_id ), array_column( $reader->execute( $input )['events'], 'event_id' ) );

			$writer = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$writer->query( 'START TRANSACTION' );
			$this->assertSame( 1, $writer->query( $writer->prepare( "UPDATE {$table} SET start_datetime = %s, end_datetime = %s WHERE post_id = %d", '2028-01-02 10:00:00', '2028-01-02 11:00:00', $event_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Independent transaction proves commit visibility.

			$this->assertSame( array( $event_id ), array_column( $reader->execute( $input )['events'], 'event_id' ) );
			$writer->query( 'COMMIT' );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- End the reader's repeatable-read snapshot after the writer commits.
			$this->assertSame( array(), $reader->execute( $input )['events'] );
		} finally {
			if ( $writer instanceof \wpdb ) {
				$writer->query( 'ROLLBACK' );
				$writer->close();
			}
			if ( $event_id > 0 ) {
				wp_delete_post( $event_id, true );
			}
			if ( $venue_id > 0 ) {
				wp_delete_term( $venue_id, 'venue' );
			}
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated disposable test database cleanup.
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			if ( $had_table ) {
				EventDatesTable::create_table();
				foreach ( $prior_rows as $row ) {
					$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore the test runner's prior temporary table contents.
				}
			}
		}
	}
}
