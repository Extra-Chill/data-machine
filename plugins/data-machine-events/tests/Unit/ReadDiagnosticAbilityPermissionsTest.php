<?php
/**
 * Read-only diagnostic abilities share AbilityPermissions::canRead().
 *
 * Issue #3992: diagnostics that persist nothing (scraper and handler tests,
 * venue stats) must honor the filterable `datamachine_events_read_capability`
 * instead of hardcoding manage_options, so platform consumers can grant them
 * to a custom role the same way they grant write abilities.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;

class ReadDiagnosticAbilityPermissionsTest extends WP_UnitTestCase {

	private const CUSTOM_CAPABILITY = 'dme_test_custom_events_read';

	public function tearDown(): void {
		remove_all_filters( 'datamachine_events_read_capability' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function read_diagnostic_ability_provider(): array {
		return array(
			'test-event-scraper' => array( 'data-machine-events/test-event-scraper', array( 'target_url' => 'https://example.com/events' ) ),
			'test-ticketmaster'  => array( 'data-machine-events/test-ticketmaster', array( 'classification_type' => 'music' ) ),
			'test-dice-fm'       => array( 'data-machine-events/test-dice-fm', array( 'city' => 'Test City' ) ),
			'venue-stats'        => array( 'data-machine-events/venue-stats', array() ),
		);
	}

	/**
	 * @dataProvider read_diagnostic_ability_provider
	 */
	public function test_default_read_gate_allows_edit_posts_and_denies_subscribers( string $name, array $input ): void {
		$ability = wp_get_ability( $name );
		$this->assertNotNull( $ability, "{$name} must be registered." );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $ability->check_permissions( $input ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertTrue( $ability->check_permissions( $input ) );
	}

	/**
	 * @dataProvider read_diagnostic_ability_provider
	 */
	public function test_filtered_read_capability_gates_ability( string $name, array $input ): void {
		add_filter( 'datamachine_events_read_capability', static fn(): string => self::CUSTOM_CAPABILITY );

		$ability = wp_get_ability( $name );
		$this->assertNotNull( $ability, "{$name} must be registered." );

		$holder_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$holder    = new \WP_User( $holder_id );
		$holder->set_role( '' );
		$holder->add_cap( self::CUSTOM_CAPABILITY );
		$this->assertFalse( user_can( $holder_id, 'manage_options' ) );

		wp_set_current_user( $holder_id );
		$this->assertTrue( $ability->check_permissions( $input ), "{$name} must allow the filtered capability." );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertFalse( $ability->check_permissions( $input ), "{$name} must deny callers without the filtered capability." );
	}
}
