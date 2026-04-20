<?php
/**
 * Self-Service Agent Creation Tests
 *
 * Coverage for the non-admin self-service `createAgent` code path introduced
 * by #919 Phase 1. These tests lock in the behavior every downstream
 * subsystem depends on:
 *
 * - `datamachine_create_own_agent` gate: non-admins can create, but only
 *   for themselves (owner_id is force-rewritten to the acting user).
 * - Per-user limit via `datamachine_max_agents_per_user` filter (default 1).
 * - Owner access is bootstrapped on the access table.
 * - `datamachine_agent_created` action fires with the expected payload.
 *
 * @package DataMachine\Tests\Unit\Abilities
 * @since 0.70.x
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\AgentAbilities;
use DataMachine\Core\Database\Agents\AgentAccess;
use WP_UnitTestCase;

class SelfServiceAgentCreationTest extends WP_UnitTestCase {

	private int $admin_id;
	private int $subscriber_id;
	private int $author_id;
	private int $other_user_id;

	public function set_up(): void {
		parent::set_up();

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->author_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->other_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tear_down(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agents" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agent_access" );

		// Remove any filters a test may have added.
		remove_all_filters( 'datamachine_max_agents_per_user' );
		remove_all_actions( 'datamachine_agent_created' );

		parent::tear_down();
	}

	// ---------------------------------------------------------------
	// Self-only ownership enforcement
	// ---------------------------------------------------------------

	/**
	 * Non-admin explicit owner_id input must be ignored; acting user wins.
	 * Prevents a subscriber from provisioning an agent owned by someone else.
	 */
	public function test_non_admin_cannot_create_agent_for_another_user(): void {
		wp_set_current_user( $this->subscriber_id );

		$result = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'sneaky-bot',
				'owner_id'   => $this->other_user_id, // Attempt to impersonate.
			)
		);

		$this->assertTrue( $result['success'], 'Creation should succeed — just owner override.' );
		$this->assertSame(
			$this->subscriber_id,
			(int) $result['owner_id'],
			'owner_id must be force-rewritten to the acting user for non-admins'
		);
	}

	public function test_admin_can_create_agent_for_another_user(): void {
		wp_set_current_user( $this->admin_id );

		$result = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'admin-provisioned',
				'owner_id'   => $this->other_user_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame(
			$this->other_user_id,
			(int) $result['owner_id'],
			'Admin-provisioned agents retain the specified owner'
		);
	}

	// ---------------------------------------------------------------
	// Per-user agent limit
	// ---------------------------------------------------------------

	/**
	 * Default limit is 1 agent per non-admin user. The second creation
	 * attempt must be rejected with a descriptive error that surfaces the
	 * existing agent's name.
	 */
	public function test_non_admin_default_limit_is_one_agent(): void {
		wp_set_current_user( $this->subscriber_id );

		$first = AgentAbilities::createAgent( array( 'agent_slug' => 'first-bot' ) );
		$this->assertTrue( $first['success'] );

		$second = AgentAbilities::createAgent( array( 'agent_slug' => 'second-bot' ) );
		$this->assertFalse( $second['success'] );
		$this->assertStringContainsString( 'already have an agent', $second['error'] );
		$this->assertStringContainsString( 'first-bot', $second['error'] );
	}

	/**
	 * The `datamachine_max_agents_per_user` filter can raise the limit. This
	 * is the extension seam downstream plugins (e.g. artist platform) use to
	 * grant higher limits to specific roles/users.
	 */
	public function test_non_admin_limit_is_filterable(): void {
		add_filter( 'datamachine_max_agents_per_user', static fn() => 3 );

		wp_set_current_user( $this->subscriber_id );

		$a = AgentAbilities::createAgent( array( 'agent_slug' => 'bot-a' ) );
		$b = AgentAbilities::createAgent( array( 'agent_slug' => 'bot-b' ) );
		$c = AgentAbilities::createAgent( array( 'agent_slug' => 'bot-c' ) );
		$d = AgentAbilities::createAgent( array( 'agent_slug' => 'bot-d' ) );

		$this->assertTrue( $a['success'] );
		$this->assertTrue( $b['success'] );
		$this->assertTrue( $c['success'] );
		$this->assertFalse( $d['success'], 'The 4th creation must be rejected with limit=3' );
		$this->assertStringContainsString( '3', $d['error'] );
	}

	/**
	 * The filter receives the owner_id so per-user/per-role decisions are
	 * possible (e.g. grant higher limits to subscribers matching a condition).
	 */
	public function test_limit_filter_receives_owner_id(): void {
		$captured_owner_id = 0;

		add_filter(
			'datamachine_max_agents_per_user',
			function ( $limit, $owner_id ) use ( &$captured_owner_id ) {
				$captured_owner_id = $owner_id;
				return $limit;
			},
			10,
			2
		);

		wp_set_current_user( $this->subscriber_id );
		AgentAbilities::createAgent( array( 'agent_slug' => 'observed' ) );

		$this->assertSame( $this->subscriber_id, $captured_owner_id );
	}

	/**
	 * Admins are not subject to the per-user limit and can create
	 * unlimited agents.
	 */
	public function test_admin_not_subject_to_per_user_limit(): void {
		wp_set_current_user( $this->admin_id );

		for ( $i = 0; $i < 5; $i++ ) {
			$result = AgentAbilities::createAgent(
				array( 'agent_slug' => "admin-bot-{$i}" )
			);
			$this->assertTrue( $result['success'], "Admin creation #{$i} should succeed" );
		}
	}

	// ---------------------------------------------------------------
	// Owner access bootstrap
	// ---------------------------------------------------------------

	/**
	 * On successful self-service creation the owner receives an explicit
	 * 'admin' access grant. Downstream code that walks `agent_access` (e.g.
	 * multi-agent UI, permission helpers) can rely on this row existing
	 * without needing a fallback to `datamachine_agents.owner_id`.
	 */
	public function test_self_service_creation_bootstraps_owner_access(): void {
		wp_set_current_user( $this->subscriber_id );

		$result = AgentAbilities::createAgent( array( 'agent_slug' => 'my-bot' ) );
		$this->assertTrue( $result['success'] );

		$access_repo = new AgentAccess();
		$grant       = $access_repo->get_access( (int) $result['agent_id'], $this->subscriber_id );

		$this->assertNotNull( $grant, 'Owner access row must exist after self-service creation' );
		$this->assertSame( 'admin', (string) $grant['role'] );
	}

	// ---------------------------------------------------------------
	// Action hook contract
	// ---------------------------------------------------------------

	/**
	 * Phase 2 of #919 (artist platform agent provisioning) depends on this
	 * hook firing with `$agent_id, $slug, $name`. Changing the signature is
	 * a breaking change for every downstream listener.
	 */
	public function test_datamachine_agent_created_hook_fires_with_expected_args(): void {
		$captured = array();

		add_action(
			'datamachine_agent_created',
			function ( $agent_id, $slug, $name ) use ( &$captured ) {
				$captured = array(
					'agent_id' => $agent_id,
					'slug'     => $slug,
					'name'     => $name,
				);
			},
			10,
			3
		);

		wp_set_current_user( $this->subscriber_id );
		$result = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'hooked-bot',
				'agent_name' => 'Hooked Bot',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( (int) $result['agent_id'], $captured['agent_id'] );
		$this->assertSame( 'hooked-bot', $captured['slug'] );
		$this->assertSame( 'Hooked Bot', $captured['name'] );
	}

	public function test_hook_does_not_fire_on_failed_creation(): void {
		$fired = 0;
		add_action( 'datamachine_agent_created', function () use ( &$fired ) {
			$fired++;
		} );

		wp_set_current_user( $this->subscriber_id );

		// Missing slug → failure path.
		$result = AgentAbilities::createAgent( array( 'agent_slug' => '' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $fired, 'Hook must not fire when creation fails' );
	}

	// ---------------------------------------------------------------
	// Author role (has create_own_agent) — sanity check
	// ---------------------------------------------------------------

	public function test_author_can_create_own_agent(): void {
		wp_set_current_user( $this->author_id );

		$result = AgentAbilities::createAgent( array( 'agent_slug' => 'author-bot' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $this->author_id, (int) $result['owner_id'] );
	}
}
