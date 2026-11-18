<?php
/**
 * Coverage for #3993: datamachine/web-fetch is a read-only, SSRF-protected
 * public page fetch, so it is gated by core `edit_posts` (anyone who can
 * draft content) rather than `publish_posts`. Action capabilities are also
 * filterable through `datamachine_action_capability`.
 *
 * @package DataMachine\Tests\Unit\Abilities
 * @see https://github.com/Extra-Chill/data-machine/issues/3993
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Abilities\WebFetchAbilities;
use WP_UnitTestCase;

class WebFetchPermissionTest extends WP_UnitTestCase {

	/**
	 * Filter callbacks registered by a test, removed in tear_down().
	 *
	 * @var callable[]
	 */
	private array $filters = array();

	public function set_up(): void {
		parent::set_up();

		// The suite runs under WP-CLI-like conditions in some environments;
		// make sure capability checks are actually evaluated.
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		new WebFetchAbilities();
	}

	public function tear_down(): void {
		foreach ( $this->filters as $callback ) {
			remove_filter( 'datamachine_action_capability', $callback );
		}
		$this->filters = array();

		remove_filter( 'datamachine_cli_bypass_permissions', '__return_false' );
		PermissionHelper::clear_agent_context();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	private function login_as( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	private function add_capability_filter( callable $callback ): void {
		$this->filters[] = $callback;
		add_filter( 'datamachine_action_capability', $callback, 10, 2 );
	}

	public function test_contributor_without_publish_posts_can_web_fetch(): void {
		$user_id = $this->login_as( 'contributor' );

		$this->assertTrue( user_can( $user_id, 'edit_posts' ) );
		$this->assertFalse( user_can( $user_id, 'publish_posts' ) );

		$this->assertTrue( PermissionHelper::can( 'web_fetch' ) );

		$ability = wp_get_ability( 'datamachine/web-fetch' );
		$this->assertNotNull( $ability );
		$this->assertTrue( $ability->check_permissions( array( 'url' => 'https://example.com/' ) ) );
	}

	public function test_subscriber_cannot_web_fetch(): void {
		$this->login_as( 'subscriber' );

		$this->assertFalse( PermissionHelper::can( 'web_fetch' ) );

		$ability = wp_get_ability( 'datamachine/web-fetch' );
		$this->assertNotNull( $ability );
		$this->assertNotTrue( $ability->check_permissions( array( 'url' => 'https://example.com/' ) ) );
	}

	public function test_logged_out_visitor_cannot_web_fetch(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( PermissionHelper::can( 'web_fetch' ) );
	}

	public function test_agent_token_ceiling_bounds_web_fetch(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'contributor' ) );

		PermissionHelper::set_agent_context( 321, $owner_id, array( 'edit_posts' ), 654 );
		$this->assertTrue( PermissionHelper::can( 'web_fetch' ) );

		PermissionHelper::clear_agent_context();
		PermissionHelper::set_agent_context( 321, $owner_id, array( 'read' ), 654 );
		$this->assertFalse( PermissionHelper::can( 'web_fetch' ) );
	}

	public function test_filter_can_remap_an_action_capability(): void {
		$this->login_as( 'contributor' );
		$this->assertTrue( PermissionHelper::can( 'web_fetch' ) );

		$seen = array();
		$this->add_capability_filter(
			static function ( $capability, $action ) use ( &$seen ) {
				$seen[ $action ] = $capability;
				return 'web_fetch' === $action ? 'publish_posts' : $capability;
			}
		);

		$this->assertFalse( PermissionHelper::can( 'web_fetch' ) );
		$this->assertSame( 'edit_posts', $seen['web_fetch'] ?? null, 'Filter receives the built-in mapping.' );
	}

	public function test_filter_can_grant_an_action_to_a_lower_role(): void {
		$this->login_as( 'subscriber' );
		$this->assertFalse( PermissionHelper::can( 'web_fetch' ) );

		$this->add_capability_filter(
			static fn( $capability, $action ) => 'web_fetch' === $action ? 'read' : $capability
		);

		$this->assertTrue( PermissionHelper::can( 'web_fetch' ) );
	}

	public function test_filter_applies_to_agent_token_context(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'contributor' ) );
		PermissionHelper::set_agent_context( 321, $owner_id, null, 654 );
		$this->assertTrue( PermissionHelper::can( 'web_fetch' ) );

		$this->add_capability_filter(
			static fn( $capability, $action ) => 'web_fetch' === $action ? 'publish_posts' : $capability
		);

		$this->assertFalse( PermissionHelper::can( 'web_fetch' ) );
	}

	public function test_filter_returning_empty_denies_the_action(): void {
		$this->login_as( 'contributor' );

		$this->add_capability_filter(
			static fn( $capability, $action ) => 'web_fetch' === $action ? '' : $capability
		);

		$this->assertFalse( PermissionHelper::can( 'web_fetch' ) );
	}
}
