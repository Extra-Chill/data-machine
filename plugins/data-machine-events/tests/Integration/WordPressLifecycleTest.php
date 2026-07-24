<?php
/**
 * WordPress runtime lifecycle integration coverage.
 *
 * @package DataMachineEvents\Tests\Integration
 */

namespace DataMachineEvents\Tests\Integration;

use WP_Block_Type_Registry;
use WP_REST_Server;
use WP_UnitTestCase;

class WordPressLifecycleTest extends WP_UnitTestCase {
	private ?WP_REST_Server $rest_server = null;

	public function tear_down(): void {
		global $wp_rest_server;

		while ( function_exists( 'ms_is_switched' ) && ms_is_switched() ) {
			restore_current_blog();
		}

		$wp_rest_server = null;
		$this->rest_server = null;
		parent::tear_down();
	}

	public function test_plugin_registrations_follow_wordpress_lifecycle(): void {
		$this->assertGreaterThan( 0, did_action( 'init' ) );
		$this->assertGreaterThan( 0, did_action( 'wp_abilities_api_init' ) );

		$blocks = WP_Block_Type_Registry::get_instance();
		$this->assertTrue( $blocks->is_registered( 'data-machine-events/calendar' ) );
		$this->assertTrue( $blocks->is_registered( 'data-machine-events/event-details' ) );
		$this->assertTrue( $blocks->is_registered( 'data-machine-events/events-map' ) );
		$this->assertNotFalse( has_action( 'wp_abilities_api_init' ) );

		$handlers = apply_filters( 'datamachine_handlers', array(), 'event_import' );
		$this->assertSame(
			array( 'dice_fm', 'event_flyer', 'single_recurring', 'ticketmaster', 'universal_web_scraper' ),
			array_values( array_intersect( array( 'dice_fm', 'event_flyer', 'single_recurring', 'ticketmaster', 'universal_web_scraper' ), array_keys( $handlers ) ) )
		);
	}

	public function test_rest_routes_register_through_rest_api_init(): void {
		global $wp_rest_server;

		$this->rest_server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $this->rest_server );

		$routes = $this->rest_server->get_routes();
		$this->assertArrayHasKey( '/datamachine/v1/events/calendar', $routes );
		$this->assertArrayHasKey( '/datamachine/v1/events/filters', $routes );
	}

	public function test_runtime_provides_multisite_blog_switching(): void {
		$this->assertTrue( is_multisite() );
		$original_blog_id = get_current_blog_id();
		$blog_id          = self::factory()->blog->create();

		switch_to_blog( $blog_id );
		$this->assertSame( $blog_id, get_current_blog_id() );
		$this->assertTrue( ms_is_switched() );

		restore_current_blog();
		$this->assertSame( $original_blog_id, get_current_blog_id() );
		$this->assertFalse( ms_is_switched() );
	}

	public function test_runtime_uses_mysql_json_semantics(): void {
		global $wpdb;

		$this->assertInstanceOf( \mysqli::class, $wpdb->dbh );
		$this->assertSame( '1', (string) $wpdb->get_var( "SELECT JSON_VALID('{\"valid\":true}')" ) );
		$this->assertSame( '0', (string) $wpdb->get_var( "SELECT JSON_VALID('{invalid}')" ) );
	}
}
