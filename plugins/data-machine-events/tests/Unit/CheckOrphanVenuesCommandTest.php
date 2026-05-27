<?php
/**
 * CheckOrphanVenuesCommand Tests
 *
 * Covers Part B of issue #277: the orphan-venue audit + flag-not-delete
 * default + opt-in --delete-orphans path.
 *
 * The flow-protection path is exercised against a minimal
 * datamachine_flows table built at setUp time, mirroring the pattern
 * VenueMergeHelperTest already uses.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.38.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Core\DuplicateDetection\VenueMergeHelper;
use DataMachineEvents\Cli\Check\CheckOrphanVenuesCommand;

class CheckOrphanVenuesCommandTest extends WP_UnitTestCase {

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

		$table  = $wpdb->prefix . 'datamachine_flows';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists === $table ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" );
			return;
		}

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

	/**
	 * Make a venue term whose taxonomy.count cache value is wrong:
	 * count=0 but a real `wp_term_relationships` row exists.
	 *
	 * Returns array(term_id, post_id).
	 */
	private function make_stale_cached_orphan( string $name ): array {
		global $wpdb;

		$term_id = $this->make_venue( $name );
		$post_id = $this->make_event_with_venue( 'Real event', $term_id );

		// Real relationship now exists and count was correctly set to 1
		// by wp_set_object_terms. Force the cache to lie about it.
		$wpdb->update(
			$wpdb->term_taxonomy,
			array( 'count' => 0 ),
			array(
				'term_id'  => $term_id,
				'taxonomy' => 'venue',
			),
			array( '%d' ),
			array( '%d', '%s' )
		);

		clean_term_cache( array( $term_id ), 'venue' );

		return array( $term_id, $post_id );
	}

	private function run( CheckOrphanVenuesCommand $cmd, array $assoc_args ): void {
		ob_start();
		$cmd( array(), $assoc_args );
		ob_end_clean();
	}

	// ---------------------------------------------------------------------

	public function test_dry_run_lists_orphans(): void {
		$a = $this->make_venue( 'Orphan A' );
		$b = $this->make_venue( 'Orphan B' );
		$c = $this->make_venue( 'Orphan C' );

		// Distractor: a venue with real usage must not be touched.
		$active = $this->make_venue( 'Active Venue' );
		$this->make_event_with_venue( 'Real event', $active );

		$cmd = new CheckOrphanVenuesCommand();
		$this->run( $cmd, array( 'dry-run' => true ) );

		// No flag meta written on any candidate.
		foreach ( array( $a, $b, $c ) as $term_id ) {
			$this->assertSame(
				'',
				(string) get_term_meta( $term_id, CheckOrphanVenuesCommand::ORPHAN_FLAGGED_META_KEY, true )
			);
		}

		// Terms still exist.
		$this->assertNotNull( get_term( $a, 'venue' ) );
		$this->assertNotNull( get_term( $b, 'venue' ) );
		$this->assertNotNull( get_term( $c, 'venue' ) );
	}

	public function test_refreshes_stale_count_cache_before_deciding(): void {
		[ $term_id ] = $this->make_stale_cached_orphan( 'Stale Cache Venue' );

		$cmd = new CheckOrphanVenuesCommand();
		$this->run( $cmd, array( 'apply' => true ) );

		// Term must NOT be flagged as orphan.
		$this->assertSame(
			'',
			(string) get_term_meta( $term_id, CheckOrphanVenuesCommand::ORPHAN_FLAGGED_META_KEY, true )
		);

		// Term still exists.
		$this->assertNotNull( get_term( $term_id, 'venue' ) );

		// Cache should now report the correct count (>=1).
		$refreshed = get_term( $term_id, 'venue' );
		$this->assertGreaterThanOrEqual( 1, (int) $refreshed->count );
	}

	/**
	 * Regression for issue #284: the count_refreshed action must
	 * actually persist the refreshed value into `wp_term_taxonomy.count`.
	 *
	 * Asserts via a fresh `$wpdb->get_var` query rather than
	 * `get_term()` so the test cannot be satisfied by a cached value
	 * — the DB column itself must be updated.
	 */
	public function test_count_refreshed_actually_persists_in_term_taxonomy(): void {
		global $wpdb;

		$term_id = $this->make_venue( 'Persisted Refresh Venue' );

		// Two real relationships against publish-status event posts.
		$this->make_event_with_venue( 'Event A', $term_id );
		$this->make_event_with_venue( 'Event B', $term_id );

		// Force the cached count to lie about reality.
		$wpdb->update(
			$wpdb->term_taxonomy,
			array( 'count' => 0 ),
			array(
				'term_id'  => $term_id,
				'taxonomy' => 'venue',
			),
			array( '%d' ),
			array( '%d', '%s' )
		);
		clean_term_cache( array( $term_id ), 'venue' );

		// Sanity: cache is now stale (0) but real relationships = 2.
		$cached_before = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT count FROM {$wpdb->term_taxonomy}
				 WHERE term_id = %d AND taxonomy = 'venue'",
				$term_id
			)
		);
		$this->assertSame( 0, $cached_before, 'precondition: stale cache should read 0' );

		$cmd = new CheckOrphanVenuesCommand();
		$this->run( $cmd, array( 'apply' => true ) );

		// Fresh DB read — must reflect the real count (2), not the stale 0.
		$cached_after = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT count FROM {$wpdb->term_taxonomy}
				 WHERE term_id = %d AND taxonomy = 'venue'",
				$term_id
			)
		);
		$this->assertSame(
			2,
			$cached_after,
			'count_refreshed must persist real_count to wp_term_taxonomy.count'
		);
	}

	/**
	 * Regression for issue #284: after the refresh write, the WP
	 * object cache layer must be invalidated so the next get_term()
	 * call returns the fresh value rather than the previously cached
	 * stale value.
	 */
	public function test_count_refresh_invalidates_object_cache(): void {
		$term_id = $this->make_venue( 'Cache Invalidation Venue' );

		$this->make_event_with_venue( 'Event A', $term_id );
		$this->make_event_with_venue( 'Event B', $term_id );

		global $wpdb;
		$wpdb->update(
			$wpdb->term_taxonomy,
			array( 'count' => 0 ),
			array(
				'term_id'  => $term_id,
				'taxonomy' => 'venue',
			),
			array( '%d' ),
			array( '%d', '%s' )
		);

		// Prime caches with the stale value so we can prove invalidation.
		clean_term_cache( array( $term_id ), 'venue' );
		$primed = get_term( $term_id, 'venue' );
		$this->assertSame( 0, (int) $primed->count, 'precondition: primed cache reads 0' );

		$cmd = new CheckOrphanVenuesCommand();
		$this->run( $cmd, array( 'apply' => true ) );

		// get_term() must now return the fresh value (2) — proves both
		// the DB write AND the cache invalidation worked together.
		$refreshed = get_term( $term_id, 'venue' );
		$this->assertSame(
			2,
			(int) $refreshed->count,
			'get_term() must return the refreshed count, not the stale cached value'
		);
	}

	public function test_protects_orphan_referenced_by_active_flow(): void {
		$term_id = $this->make_venue( 'Flow-Referenced Orphan' );

		$flow_id = $this->insert_flow(
			wp_json_encode(
				array(
					'step_1' => array(
						'handler_config' => array(
							'venue' => (string) $term_id,
						),
					),
				)
			)
		);

		$cmd = new CheckOrphanVenuesCommand();
		$this->run( $cmd, array( 'apply' => true ) );

		// protected-by-flow meta written, flag-at meta NOT written.
		$this->assertSame(
			(string) $flow_id,
			(string) get_term_meta( $term_id, CheckOrphanVenuesCommand::ORPHAN_PROTECTED_BY_FLOW_META_KEY, true )
		);
		$this->assertSame(
			'',
			(string) get_term_meta( $term_id, CheckOrphanVenuesCommand::ORPHAN_FLAGGED_META_KEY, true )
		);

		// Term still exists.
		$this->assertNotNull( get_term( $term_id, 'venue' ) );
	}

	public function test_flags_real_orphan_without_delete_orphans(): void {
		$term_id = $this->make_venue( 'Plain Orphan' );

		$cmd = new CheckOrphanVenuesCommand();
		$this->run( $cmd, array( 'apply' => true ) );

		// Flag meta written with a current-ish timestamp.
		$flagged_at = (int) get_term_meta(
			$term_id,
			CheckOrphanVenuesCommand::ORPHAN_FLAGGED_META_KEY,
			true
		);
		$this->assertGreaterThan( time() - 60, $flagged_at );

		// Term still exists.
		$this->assertNotNull( get_term( $term_id, 'venue' ) );
	}

	public function test_deletes_real_orphan_with_delete_orphans(): void {
		$term_id = $this->make_venue( 'Deletable Orphan' );

		$cmd = new CheckOrphanVenuesCommand();
		$this->run(
			$cmd,
			array(
				'apply'          => true,
				'delete-orphans' => true,
			)
		);

		// Term deleted.
		$this->assertNull( get_term( $term_id, 'venue' ) );
	}

	public function test_does_not_delete_protected_orphans_even_with_delete_orphans(): void {
		// Two protections to assert: opt-out flag and flow-protected meta.
		$opt_out_term = $this->make_venue(
			'Opt-Out Orphan',
			array( VenueMergeHelper::NO_MERGE_META_KEY => '1' )
		);

		$flow_protected_term = $this->make_venue(
			'Pre-Flagged Flow-Protected Orphan',
			array( CheckOrphanVenuesCommand::ORPHAN_PROTECTED_BY_FLOW_META_KEY => '99' )
		);

		$cmd = new CheckOrphanVenuesCommand();
		$this->run(
			$cmd,
			array(
				'apply'          => true,
				'delete-orphans' => true,
			)
		);

		// Both terms must still exist.
		$this->assertNotNull(
			get_term( $opt_out_term, 'venue' ),
			'_venue_no_merge must protect from --delete-orphans'
		);
		$this->assertNotNull(
			get_term( $flow_protected_term, 'venue' ),
			'_venue_orphan_protected_by_flow must protect from --delete-orphans'
		);

		// Neither got the post-hoc flagged meta either — they were
		// handled by the protection branch which short-circuits before
		// the delete OR flag steps.
		$this->assertSame(
			'',
			(string) get_term_meta( $opt_out_term, CheckOrphanVenuesCommand::ORPHAN_FLAGGED_META_KEY, true )
		);
		$this->assertSame(
			'',
			(string) get_term_meta( $flow_protected_term, CheckOrphanVenuesCommand::ORPHAN_FLAGGED_META_KEY, true )
		);
	}
}
