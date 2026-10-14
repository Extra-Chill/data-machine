<?php
/**
 * prevent_taxonomy_archive_404 guard tests (issue #927).
 *
 * The 404-prevention rewrite (post_type=events, fields=ids, paged=1) is for
 * real event taxonomy archives only. A singular request that also carries a
 * taxonomy query var (e.g. /submit/?venue=Foo) must keep resolving as the
 * page with a WP_Post, never as a fields=ids archive whose $wp_query->post
 * is an int.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\Event_Post_Type;
use WP_Post;
use WP_Query;
use WP_UnitTestCase;

class TaxonomyArchive404GuardTest extends WP_UnitTestCase {

	/**
	 * Run a main query for the given vars with the guard attached.
	 */
	private function main_query( array $query_vars ): WP_Query {
		global $wp_the_query, $wp_query;

		$wp_the_query = new WP_Query();
		$wp_query     = $wp_the_query;
		$wp_query->query( $query_vars );

		return $wp_query;
	}

	public function set_up(): void {
		parent::set_up();
		if ( ! has_action( 'pre_get_posts', array( Event_Post_Type::class, 'prevent_taxonomy_archive_404' ) ) ) {
			add_action( 'pre_get_posts', array( Event_Post_Type::class, 'prevent_taxonomy_archive_404' ) );
		}
	}

	public function test_page_request_with_venue_query_var_stays_a_page(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'submit',
				'post_status' => 'publish',
			)
		);
		$venue   = self::factory()->term->create_and_get( array( 'taxonomy' => 'venue', 'name' => 'The Pour House' ) );

		$query = $this->main_query(
			array(
				'pagename' => 'submit',
				'venue'    => $venue->slug,
			)
		);

		$this->assertTrue( $query->is_page() );
		$this->assertNotSame( 'ids', $query->get( 'fields' ) );
		$this->assertInstanceOf( WP_Post::class, $query->post );
		$this->assertSame( $page_id, $query->post->ID );
	}

	public function test_venue_archive_still_gets_minimal_events_query(): void {
		$venue = self::factory()->term->create_and_get( array( 'taxonomy' => 'venue', 'name' => 'Royal American' ) );

		$query = $this->main_query(
			array(
				'venue' => $venue->slug,
				'paged' => 3,
			)
		);

		$this->assertTrue( $query->is_tax( 'venue' ) );
		$this->assertSame( Event_Post_Type::POST_TYPE, $query->get( 'post_type' ) );
		$this->assertSame( 'ids', $query->get( 'fields' ) );
		$this->assertSame( 1, (int) $query->get( 'paged' ) );
	}
}
