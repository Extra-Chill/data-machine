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
	/** @var int[] */
	private array $term_ids = array();

	/** @var int[] */
	private array $post_ids = array();

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}

		$this->ensure_flows_table();
	}

	public function tearDown(): void {
		global $wpdb;
		foreach ( array_reverse( $this->post_ids ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		foreach ( array_reverse( $this->term_ids ) as $term_id ) {
			if ( get_term( $term_id, 'venue' ) instanceof \WP_Term ) {
				wp_delete_term( $term_id, 'venue' );
			}
		}
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}datamachine_flows" );
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'START TRANSACTION' );
		parent::tearDown();
	}

	private function ensure_flows_table(): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'datamachine_flows';
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
		$term = wp_insert_term(
			$name,
			'venue',
			array( 'slug' => sanitize_title( $name ) . '-' . wp_generate_uuid4() )
		);
		$this->assertNotInstanceOf( \WP_Error::class, $term );

		$term_id = (int) $term['term_id'];
		$this->term_ids[] = $term_id;
		foreach ( $meta as $meta_key => $value ) {
			$this->assertStringStartsWith( '_venue_', $meta_key, 'Venue fixtures must use canonical metadata keys.' );
			$this->assertNotFalse( update_term_meta( $term_id, $meta_key, $value ) );
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
		$this->post_ids[] = $post_id;

		$assigned = wp_set_object_terms( $post_id, array( $venue_term_id ), 'venue', false );
		$this->assertNotWPError( $assigned, 'Venue fixture relationship must persist.' );
		return $post_id;
	}

	private function insert_flow( string $flow_config_json ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'datamachine_flows',
			array(
				'pipeline_id' => 1,
				'flow_name'   => 'test flow',
				'flow_config' => $flow_config_json,
			),
			array( '%d', '%s', '%s' )
		);
		$this->assertSame( 1, $inserted, 'Flow fixture must persist.' );

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
		$meta   = array(
			'_venue_address' => '3010 Minnehaha Ave',
			'_venue_city'    => 'Minneapolis',
		);
		$winner = $this->make_venue( 'Hook and Ladder Theater', $meta );
		$loser  = $this->make_venue( 'Hook & Ladder Theater', $meta );

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
			array(
				'_venue_address' => '3010 Minnehaha Ave',
				'_venue_city'    => 'Minneapolis',
			)
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
		$meta   = array(
			'_venue_address' => '3010 Minnehaha Ave',
			'_venue_city'    => 'Minneapolis',
		);
		$winner = $this->make_venue( 'Hook and Ladder Theater', $meta );
		$loser  = $this->make_venue( 'Hook & Ladder Theater', $meta );

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
			array(
				VenueMergeHelper::NO_MERGE_META_KEY => '1',
				'_venue_address'                     => '3010 Minnehaha Ave',
				'_venue_city'                        => 'Minneapolis',
			)
		);
		$loser  = $this->make_venue(
			'Hook & Ladder Theater',
			array(
				'_venue_address' => '3010 Minnehaha Ave',
				'_venue_city'    => 'Minneapolis',
			)
		);

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

	// ---------------------------------------------------------------------
	// names_are_similar() — conservative guard for address-cluster path
	// (issue #281)
	// ---------------------------------------------------------------------

	public function test_names_are_similar_exact_normalized_match(): void {
		// Different casing only → Rule 1 (exact match after normalization).
		$this->assertTrue(
			VenueMergeHelper::names_are_similar( 'Hi-Fi Indianapolis', 'HI-FI Indianapolis' )
		);
	}

	public function test_names_are_similar_qualified_city_suffix(): void {
		$orlando = array( 'city' => 'Orlando' );
		$this->assertTrue(
			VenueMergeHelper::names_are_similar( 'The Abbey', 'The Abbey-Orlando', $orlando, $orlando )
		);
	}

	public function test_names_are_similar_accepts_token_reordering(): void {
		// Token reordering keeps Rule 2 useful without accepting added tokens.
		$this->assertTrue(
			VenueMergeHelper::names_are_similar( 'Bowery Ballroom NYC', 'NYC Bowery Ballroom' )
		);
	}

	public function test_names_are_similar_rejects_different_token_sets(): void {
		// Distinct token sets must not match even with a shared location suffix.
		$this->assertFalse(
			VenueMergeHelper::names_are_similar(
				'V Theater at Planet Hollywood',
				'Saxe Theater at Planet Hollywood'
			)
		);
		$this->assertFalse(
			VenueMergeHelper::names_are_similar(
				'V Theater at Planet Hollywood Inside the Miracle Mile Mall',
				'Saxe Theater at Planet Hollywood Inside the Miracle Mile Mall'
			)
		);
	}

	public function test_names_are_similar_rejects_distinct_subvenues(): void {
		$this->assertFalse( VenueMergeHelper::names_are_similar( 'Georgia Theatre', 'Georgia Theatre Rooftop' ) );
		$this->assertFalse( VenueMergeHelper::names_are_similar( "Cat's Cradle", "Cat's Cradle Back Room" ) );
		$this->assertFalse( VenueMergeHelper::names_are_similar( 'Bar Freda', 'Bar Freda - Basement' ) );
		$this->assertFalse( VenueMergeHelper::names_are_similar( 'Cannery Hall', 'Mainstage at Cannery Hall' ) );
		$this->assertFalse( VenueMergeHelper::names_are_similar( 'The Lounge at Venue A', 'The Lounge at Venue B' ) );
	}

	public function test_names_are_similar_completely_different(): void {
		// Zero token overlap → 0/4 = 0.0 → false.
		$this->assertFalse(
			VenueMergeHelper::names_are_similar( 'Dolby Theatre', 'Lucky Strike Hollywood' )
		);
	}

	public function test_names_are_similar_taco_vs_art_space(): void {
		// "panchos tacos and tequila" vs "athica" → no token overlap → false.
		$this->assertFalse(
			VenueMergeHelper::names_are_similar( "Pancho's Tacos & Tequila", 'ATHICA' )
		);
	}

	public function test_names_are_similar_rejects_added_tokens(): void {
		$this->assertFalse(
			VenueMergeHelper::names_are_similar( 'Joes', "Joe's Bar and Grill" )
		);
	}

	public function test_names_are_similar_empty_or_whitespace(): void {
		$this->assertFalse( VenueMergeHelper::names_are_similar( '', 'Anything' ) );
		$this->assertFalse( VenueMergeHelper::names_are_similar( 'Anything', '' ) );
		$this->assertFalse( VenueMergeHelper::names_are_similar( '   ', 'Anything' ) );
		// Single-character "Z" normalizes to "z" and has a different token set.
		$this->assertFalse( VenueMergeHelper::names_are_similar( 'Z', 'Anything' ) );
	}

	public function test_names_are_similar_for_qualified_legacy_aliases(): void {
		$denver = array(
			'city'    => 'Denver',
			'state'   => 'CO',
			'country' => 'US',
		);

		$this->assertTrue( VenueMergeHelper::names_are_similar( 'Bluebird Theater', 'Bluebird Theatre', $denver, $denver ) );
		$this->assertTrue( VenueMergeHelper::names_are_similar( 'Oriental Theater', 'Oriental Theatre-CO', $denver, $denver ) );
		$this->assertTrue( VenueMergeHelper::names_are_similar( 'Oriental Theater', 'Oriental Theatre-Denver-CO', $denver, $denver ) );
		$this->assertTrue( VenueMergeHelper::names_are_similar( 'HQ', 'HQ Denver', $denver, $denver ) );
		$this->assertTrue( VenueMergeHelper::names_are_similar( 'Red Rocks Amphitheater', 'Red Rocks Amphitheatre', $denver, $denver ) );
		$this->assertTrue(
			VenueMergeHelper::names_are_similar(
				'Example Venue',
				'Example Venue-NY',
				array( 'state' => 'New York' ),
				array( 'state' => 'NY' )
			)
		);
		$this->assertFalse( VenueMergeHelper::names_are_similar( 'HQ', 'HQ Denver' ) );
		$this->assertFalse(
			VenueMergeHelper::names_are_similar(
				'HQ',
				'HQ Denver',
				$denver,
				array(
					'city'    => 'Denver',
					'state'   => 'TX',
					'country' => 'US',
				)
			)
		);
	}

	public function test_alias_address_normalization_uses_stored_geography(): void {
		$this->assertSame(
			VenueMergeHelper::normalize_address_for_alias_matching( '4335 W. 44th Ave.', 'Denver', 'CO' ),
			VenueMergeHelper::normalize_address_for_alias_matching( '4335 W. 44th Ave., Denver, Colorado', 'Denver', 'CO' )
		);
		$this->assertNotSame(
			VenueMergeHelper::normalize_address_for_alias_matching( '100 West Street', 'Denver', 'CO' ),
			VenueMergeHelper::normalize_address_for_alias_matching( '100 W Street', 'Denver', 'CO' )
		);
		$this->assertSame(
			VenueMergeHelper::normalize_address_for_alias_matching( '60 South Broadway', 'Denver', 'CO' ),
			VenueMergeHelper::normalize_address_for_alias_matching( '60 S Broadway', 'Denver', 'Colorado' )
		);
		$this->assertSame(
			VenueMergeHelper::normalize_address_for_alias_matching( '100 West 44th Street', 'Denver', 'CO' ),
			VenueMergeHelper::normalize_address_for_alias_matching( '100 W 44th Street', 'Denver', 'Colorado' )
		);
	}

	/**
	 * Regression fixture: every production false-positive pair from the
	 * issue #281 dry-run must be rejected by names_are_similar(). These
	 * are the actual term-pair names from extrachill.com (Nov 2025), and
	 * each one would have produced a destructive cross-venue merge if
	 * we'd run --apply against the unguarded clustering logic.
	 */
	public function test_production_false_positive_pairs_rejected(): void {
		$pairs = array(
			array( 'Dolby Theatre', 'Lucky Strike Hollywood' ),
			array( 'Come and Take It Live', "Emo's Austin" ),
			array( "Pancho's Tacos & Tequila", 'ATHICA' ),
			array( 'North Charleston Coliseum', 'North Charleston Performing Arts Center' ),
			array( 'V Theater at Planet Hollywood', 'Saxe Theater at Planet Hollywood' ),
			array( 'The Arrow Room', 'Haven City Market' ),
		);

		foreach ( $pairs as $pair ) {
			$this->assertFalse(
				VenueMergeHelper::names_are_similar( $pair[0], $pair[1] ),
				sprintf(
					'Pair MUST be rejected as dissimilar: "%s" vs "%s"',
					$pair[0],
					$pair[1]
				)
			);
		}
	}

	/**
	 * Regression fixture: legitimate venue-pair variants that the issue
	 * #281 fix MUST keep clustering together. If any of these flip to
	 * false the address-cluster path stops doing useful work and the
	 * migration becomes a no-op.
	 */
	public function test_production_true_positive_pairs_accepted(): void {
		$pairs = array(
			// Rule 1 (exact normalized) — case-only variant.
			array( 'Hi-Fi Indianapolis', 'HI-FI Indianapolis' ),
			// Rule 1 — ampersand collapses to "and" via the existing
			// normalize_venue_name_for_matching() pipeline so both sides
			// reduce to the identical normalized string.
			array( 'Hook & Ladder Theater', 'Hook and Ladder Theater' ),
			// Rule 1 — leading "The" is stripped and periods removed by
			// normalize_venue_name_for_matching(), so both sides reduce
			// to "st augustine amphitheatre".
			array( 'St Augustine Amphitheatre', 'The St. Augustine Amphitheatre' ),
		);

		foreach ( $pairs as $pair ) {
			$this->assertTrue(
				VenueMergeHelper::names_are_similar( $pair[0], $pair[1] ),
				sprintf(
					'Pair MUST be accepted as similar: "%s" vs "%s"',
					$pair[0],
					$pair[1]
				)
			);
		}
	}

	// ---------------------------------------------------------------------
	// Address-cluster integration: split_by_name_similarity() must keep
	// legitimate pairs and drop multi-tenant false positives.
	// ---------------------------------------------------------------------

	public function test_address_cluster_excludes_dissimilar_names(): void {
		// Two terms at the same address+city with completely different
		// names. The address-cluster path must NOT emit a multi-term
		// cluster for this address.
		$this->make_venue(
			'Dolby Theatre',
			array(
				'_venue_address' => '6801 Hollywood Blvd',
				'_venue_city'    => 'Hollywood',
			)
		);
		$this->make_venue(
			'Lucky Strike Hollywood',
			array(
				'_venue_address' => '6801 Hollywood Blvd',
				'_venue_city'    => 'Hollywood',
			)
		);

		$cmd        = new CheckMergeDuplicateVenuesCommand();
		$reflection = new \ReflectionClass( $cmd );
		$method     = $reflection->getMethod( 'find_clusters' );
		$method->setAccessible( true );
		$clusters = $method->invoke( $cmd );

		foreach ( $clusters as $cluster ) {
			$this->assertStringStartsNotWith(
				'addr:',
				$cluster['key'],
				sprintf(
					'Multi-tenant address must not produce an addr-cluster (got key %s with %d terms)',
					$cluster['key'],
					count( $cluster['term_ids'] )
				)
			);
		}
	}

	public function test_address_cluster_includes_similar_names(): void {
		// Two terms at the same address+city with case-only name variants.
		// Both pass Rule 1 → address-cluster must contain them.
		$winner = $this->make_venue(
			'Hi-Fi Indianapolis',
			array(
				'_venue_address' => '1043 Virginia Ave',
				'_venue_city'    => 'Indianapolis',
			)
		);
		$loser  = $this->make_venue(
			'HI-FI Indianapolis',
			array(
				'_venue_address' => '1043 Virginia Ave',
				'_venue_city'    => 'Indianapolis',
			)
		);

		$cmd        = new CheckMergeDuplicateVenuesCommand();
		$reflection = new \ReflectionClass( $cmd );
		$method     = $reflection->getMethod( 'find_clusters' );
		$method->setAccessible( true );
		$clusters = $method->invoke( $cmd );

		// Either bucket may catch the pair (name-cluster wins first), but
		// SOME cluster must contain both ids.
		$found = false;
		foreach ( $clusters as $cluster ) {
			if (
				in_array( $winner, $cluster['term_ids'], true )
				&& in_array( $loser, $cluster['term_ids'], true )
			) {
				$found = true;
				break;
			}
		}

		$this->assertTrue(
			$found,
			'Similar-named terms at the same address must be clustered together.'
		);
	}

	public function test_address_cluster_splits_multi_tenant_with_mixed_pair(): void {
		// Three terms at the same address: two are name-similar to each
		// other, the third is unrelated. The similar pair must cluster;
		// the odd one out must be excluded.
		$similar_a = $this->make_venue(
			'Hi-Fi Indianapolis',
			array(
				'_venue_address' => '1043 Virginia Ave',
				'_venue_city'    => 'Indianapolis',
			)
		);
		$similar_b = $this->make_venue(
			'HI-FI Indianapolis',
			array(
				'_venue_address' => '1043 Virginia Ave',
				'_venue_city'    => 'Indianapolis',
			)
		);
		$intruder  = $this->make_venue(
			'Completely Unrelated Bowling Alley',
			array(
				'_venue_address' => '1043 Virginia Ave',
				'_venue_city'    => 'Indianapolis',
			)
		);

		$cmd        = new CheckMergeDuplicateVenuesCommand();
		$reflection = new \ReflectionClass( $cmd );
		$method     = $reflection->getMethod( 'find_clusters' );
		$method->setAccessible( true );
		$clusters = $method->invoke( $cmd );

		$cluster_with_similar = null;
		foreach ( $clusters as $cluster ) {
			if (
				in_array( $similar_a, $cluster['term_ids'], true )
				&& in_array( $similar_b, $cluster['term_ids'], true )
			) {
				$cluster_with_similar = $cluster;
				break;
			}
		}

		$this->assertNotNull(
			$cluster_with_similar,
			'Similar pair must be clustered.'
		);
		$this->assertNotContains(
			$intruder,
			$cluster_with_similar['term_ids'],
			'Dissimilar third term must not join the cluster.'
		);
	}

	public function test_address_clusters_include_confirmed_denver_aliases(): void {
		$pairs = array(
			array( 'Bluebird Theater', '3317 E Colfax Ave', 'Bluebird Theatre', '3317 E. Colfax Avenue' ),
			array( 'Oriental Theater', '4335 W. 44th Ave.', 'Oriental Theatre-CO', '4335 W. 44th Ave., Denver, CO' ),
			array( 'HQ', '60 South Broadway', 'HQ Denver', '60 S Broadway' ),
		);
		$expected_pairs = array();

		foreach ( $pairs as $pair ) {
			$expected_pairs[] = array(
				$this->make_venue(
					$pair[0],
					array(
						'_venue_address' => $pair[1],
						'_venue_city'    => 'Denver',
						'_venue_state'   => 'Colorado',
						'_venue_country' => 'United States',
					)
				),
				$this->make_venue(
					$pair[2],
					array(
						'_venue_address' => $pair[3],
						'_venue_city'    => 'Denver',
						'_venue_state'   => 'CO',
						'_venue_country' => 'US',
					)
				),
			);
		}

		$cmd        = new CheckMergeDuplicateVenuesCommand();
		$reflection = new \ReflectionClass( $cmd );
		$method     = $reflection->getMethod( 'find_clusters' );
		$method->setAccessible( true );
		$clusters = $method->invoke( $cmd );

		foreach ( $expected_pairs as $expected ) {
			$found = false;
			foreach ( $clusters as $cluster ) {
				if ( in_array( $expected[0], $cluster['term_ids'], true ) && in_array( $expected[1], $cluster['term_ids'], true ) ) {
					$found = true;
					break;
				}
			}
			$this->assertTrue( $found, sprintf( 'Expected venue terms %d and %d to cluster.', $expected[0], $expected[1] ) );
		}
	}

	public function test_name_only_cluster_requires_physical_identity(): void {
		$winner = $this->make_venue( 'Hook and Ladder Theater' );
		$loser  = $this->make_venue( 'Hook & Ladder Theater' );

		$cmd        = new CheckMergeDuplicateVenuesCommand();
		$reflection = new \ReflectionClass( $cmd );
		$method     = $reflection->getMethod( 'find_clusters' );
		$method->setAccessible( true );
		$clusters = $method->invoke( $cmd );

		$found = false;
		foreach ( $clusters as $cluster ) {
			if (
				in_array( $winner, $cluster['term_ids'], true )
				&& in_array( $loser, $cluster['term_ids'], true )
			) {
				$found = true;
				break;
			}
		}

		$this->assertFalse(
			$found,
			'Name similarity alone must not authorize a destructive venue merge.'
		);
	}
}
