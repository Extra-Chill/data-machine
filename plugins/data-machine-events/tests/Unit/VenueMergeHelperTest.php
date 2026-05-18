<?php
/**
 * VenueMergeHelper + CheckMergeDuplicateVenuesCommand Tests
 *
 * Covers the term-level merge primitive that backs the issue #276
 * migration: loser meta smart-merged into winner, posts reassigned via
 * wp_set_object_terms() (tt_count stays sane), flow handler_config
 * references rewritten, loser term deleted, and the no-merge opt-out
 * honored.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.35.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Core\DuplicateDetection\VenueMergeHelper;
use DataMachineEvents\Cli\Check\CheckMergeDuplicateVenuesCommand;

class VenueMergeHelperTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}

		$this->ensure_flows_table();
	}

	private function ensure_flows_table(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'datamachine_flows';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists === $table ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" );
			return;
		}

		// Minimal schema matching the production shape we care about.
		$wpdb->query(
			"CREATE TABLE {$table} (
				flow_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				pipeline_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				flow_name VARCHAR(255) NOT NULL DEFAULT '',
				flow_config LONGTEXT NOT NULL,
				PRIMARY KEY (flow_id)
			)"
		);
	}

	private function make_venue( string $name, array $meta = array() ): int {
		$term = wp_insert_term( $name, 'venue' );
		$this->assertNotInstanceOf( \WP_Error::class, $term );

		$term_id = (int) $term['term_id'];
		foreach ( $meta as $key => $value ) {
			update_term_meta( $term_id, $key, $value );
		}

		return $term_id;
	}

	private function make_event_with_venue( string $title, int $venue_term_id ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		wp_set_object_terms( $post_id, array( $venue_term_id ), 'venue', false );
		return $post_id;
	}

	private function insert_flow( string $flow_config_json ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'datamachine_flows',
			array(
				'pipeline_id' => 1,
				'flow_name'   => 'test flow',
				'flow_config' => $flow_config_json,
			),
			array( '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	private function get_flow_config( int $flow_id ): string {
		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT flow_config FROM {$wpdb->prefix}datamachine_flows WHERE flow_id = %d",
				$flow_id
			)
		);
	}

	// ---------------------------------------------------------------------
	// Migration command behavior
	// ---------------------------------------------------------------------

	public function test_merge_command_dry_run_lists_clusters_without_writes(): void {
		$winner = $this->make_venue( 'Hook and Ladder Theater' );
		$loser  = $this->make_venue( 'Hook & Ladder Theater' );

		$winner_post = $this->make_event_with_venue( 'Show A', $winner );
		$loser_post  = $this->make_event_with_venue( 'Show B', $loser );

		$cmd = new CheckMergeDuplicateVenuesCommand();
		ob_start();
		$cmd( array(), array( 'dry-run' => true ) );
		ob_end_clean();

		// Both terms still exist after a dry run.
		$this->assertNotNull( get_term( $winner, 'venue' ) );
		$this->assertNotNull( get_term( $loser, 'venue' ) );

		// Loser still tagged on its post.
		$terms = wp_get_object_terms( $loser_post, 'venue', array( 'fields' => 'ids' ) );
		$this->assertContains( $loser, array_map( 'intval', $terms ) );

		// Winner still tagged on its post.
		$terms = wp_get_object_terms( $winner_post, 'venue', array( 'fields' => 'ids' ) );
		$this->assertContains( $winner, array_map( 'intval', $terms ) );
	}

	public function test_merge_command_apply_reassigns_posts_and_deletes_loser(): void {
		$winner = $this->make_venue(
			'Hook and Ladder Theater',
			array( '_venue_city' => 'Minneapolis' )
		);
		$loser  = $this->make_venue(
			'Hook & Ladder Theater',
			array(
				'_venue_city'    => 'Minneapolis',
				'_venue_address' => '3010 Minnehaha Ave',
				'_venue_phone'   => '555-0100',
			)
		);

		$winner_post = $this->make_event_with_venue( 'Show A', $winner );
		$loser_post  = $this->make_event_with_venue( 'Show B', $loser );

		$cmd = new CheckMergeDuplicateVenuesCommand();
		ob_start();
		$cmd( array(), array( 'apply' => true ) );
		ob_end_clean();

		// Loser term is gone.
		$this->assertNull( get_term( $loser, 'venue' ) );

		// Winner remains.
		$this->assertNotNull( get_term( $winner, 'venue' ) );

		// Loser's post is now tagged with the winner.
		$loser_post_terms = wp_get_object_terms( $loser_post, 'venue', array( 'fields' => 'ids' ) );
		$this->assertContains( $winner, array_map( 'intval', $loser_post_terms ) );
		$this->assertNotContains( $loser, array_map( 'intval', $loser_post_terms ) );

		// Winner's post unchanged.
		$winner_post_terms = wp_get_object_terms( $winner_post, 'venue', array( 'fields' => 'ids' ) );
		$this->assertContains( $winner, array_map( 'intval', $winner_post_terms ) );

		// Smart-merge: winner inherited empty fields from loser.
		$this->assertSame( '3010 Minnehaha Ave', get_term_meta( $winner, '_venue_address', true ) );
		$this->assertSame( '555-0100', get_term_meta( $winner, '_venue_phone', true ) );

		// Winner's pre-existing city was not overwritten.
		$this->assertSame( 'Minneapolis', get_term_meta( $winner, '_venue_city', true ) );
	}

	public function test_merge_command_apply_reassigns_flow_handler_configs(): void {
		$winner = $this->make_venue( 'Hook and Ladder Theater' );
		$loser  = $this->make_venue( 'Hook & Ladder Theater' );

		// Two flow shapes from production: flat venue and nested
		// universal_web_scraper.venue. Both must be rewritten.
		$flat_flow_id = $this->insert_flow(
			wp_json_encode(
				array(
					'step_1' => array(
						'handler_config' => array(
							'venue' => (string) $loser,
						),
					),
				)
			)
		);

		$nested_flow_id = $this->insert_flow(
			wp_json_encode(
				array(
					'step_1' => array(
						'handler_config' => array(
							'universal_web_scraper' => array(
								'venue' => (string) $loser,
							),
						),
					),
				)
			)
		);

		$cmd = new CheckMergeDuplicateVenuesCommand();
		ob_start();
		$cmd( array(), array( 'apply' => true ) );
		ob_end_clean();

		$flat_after   = json_decode( $this->get_flow_config( $flat_flow_id ), true );
		$nested_after = json_decode( $this->get_flow_config( $nested_flow_id ), true );

		$this->assertSame(
			(string) $winner,
			$flat_after['step_1']['handler_config']['venue']
		);

		$this->assertSame(
			(string) $winner,
			$nested_after['step_1']['handler_config']['universal_web_scraper']['venue']
		);
	}

	public function test_merge_command_respects_no_merge_opt_out(): void {
		$winner = $this->make_venue(
			'Hook and Ladder Theater',
			array( VenueMergeHelper::NO_MERGE_META_KEY => '1' )
		);
		$loser = $this->make_venue( 'Hook & Ladder Theater' );

		$loser_post = $this->make_event_with_venue( 'Show B', $loser );

		$cmd = new CheckMergeDuplicateVenuesCommand();
		ob_start();
		$cmd( array(), array( 'apply' => true ) );
		ob_end_clean();

		// Both terms intact.
		$this->assertNotNull( get_term( $winner, 'venue' ) );
		$this->assertNotNull( get_term( $loser, 'venue' ) );

		// Loser's post still tagged with the loser.
		$terms = wp_get_object_terms( $loser_post, 'venue', array( 'fields' => 'ids' ) );
		$this->assertContains( $loser, array_map( 'intval', $terms ) );
	}

	public function test_merge_command_smart_merge_does_not_overwrite_winner(): void {
		// Winner created first (lower ID), with a clean canonical address.
		$winner = $this->make_venue(
			'Hook and Ladder Theater',
			array( '_venue_address' => '3010 Minnehaha Ave' )
		);

		// Loser has a different address string — smart-merge MUST NOT replace
		// the winner's existing non-empty address.
		$loser = $this->make_venue(
			'Hook & Ladder Theater',
			array( '_venue_address' => '3010 Minnehaha Ave Suite 420' )
		);

		$result = VenueMergeHelper::merge( $winner, $loser );

		$this->assertTrue( $result['success'] );
		$this->assertSame(
			'3010 Minnehaha Ave',
			get_term_meta( $winner, '_venue_address', true ),
			'Winner address must not be overwritten by loser address.'
		);
	}
}
