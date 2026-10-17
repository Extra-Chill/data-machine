<?php
/**
 * Chat identity links + chat person resolver tests.
 *
 * @package DataMachine\Tests\Unit\Core\Agents
 */

namespace DataMachine\Tests\Unit\Core\Agents;

use DataMachine\Core\Agents\ChatIdentityLinks;
use DataMachine\Core\Agents\ChatPersonResolver;
use DataMachine\Core\Database\Agents\AgentAccess;
use DataMachine\Core\Database\Agents\Agents as AgentsRepository;
use DataMachine\Core\FilesRepository\AgentMemory;
use DataMachine\Engine\AI\MemoryFileRegistry;
use WP_UnitTestCase;

class ChatPersonResolverTest extends WP_UnitTestCase {

	private const PLATFORM = 'test-platform';

	private const CONVENTION_FILE = 'CONVENTION-TEST.md';
	private const CONVENTION_PATH = 'wp-content/uploads/datamachine-test/CONVENTION-TEST.md';

	private int $owner_id;
	private int $viewer_id;
	private int $other_id;
	private int $agent_id;
	private string $slug = 'chat-person-agent';

	public function set_up(): void {
		parent::set_up();

		// This suite depends on the core memory file and agent mode
		// registrations (SOUL.md, USER.md, USER_MEMORY.md, chat mode). They
		// register once per process, so restore them if an earlier test in the
		// same process reset the registries.
		if ( ! MemoryFileRegistry::is_registered( 'SOUL.md' ) ) {
			datamachine_register_default_memory_files();
		}
		if ( ! \DataMachine\Engine\AI\AgentModeRegistry::is_registered( 'chat' ) ) {
			\DataMachine\Core\Bootstrap\HostIntegrationServiceProvider::register_agent_modes();
		}

		$this->owner_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->viewer_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->agent_id = ( new AgentsRepository() )->create_if_missing( $this->slug, 'Chat Person Agent', $this->owner_id );

		// Agent-scoped memory.
		( new AgentMemory( $this->owner_id, $this->agent_id, 'SOUL.md', MemoryFileRegistry::LAYER_AGENT ) )->replace_all( "# Soul\n\nagent-soul-sentinel\n" );

		// Personal memory for two different people.
		foreach ( array(
			$this->viewer_id => 'viewer',
			$this->other_id  => 'other',
		) as $user_id => $tag ) {
			( new AgentMemory( $user_id, $this->agent_id, 'USER.md', MemoryFileRegistry::LAYER_USER ) )->replace_all( "# User\n\n{$tag}-profile-sentinel\n" );
			( new AgentMemory( $user_id, $this->agent_id, 'USER_MEMORY.md', MemoryFileRegistry::LAYER_PRINCIPAL ) )->replace_all( "# UM\n\n{$tag}-principal-sentinel\n" );
		}
	}

	public function tear_down(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agents" );
		remove_all_filters( 'datamachine_can_access_agent' );

		MemoryFileRegistry::deregister( self::CONVENTION_FILE );
		$convention_file = ABSPATH . self::CONVENTION_PATH;
		if ( file_exists( $convention_file ) ) {
			wp_delete_file( $convention_file );
		}

		parent::tear_down();
	}

	private function resolve( string $actor_id, string $event = '', string $platform = self::PLATFORM ): array {
		return ( new ChatPersonResolver() )->resolve( $this->slug, $platform, $actor_id, $event );
	}

	private function grant( int $user_id, string $role ): void {
		( new AgentAccess() )->grant_access( new \WP_Agent_Access_Grant( (string) $this->agent_id, $user_id, $role ) );
	}

	private function joined( array $result ): string {
		return implode( "\n", array_column( $result['sections'], 'content' ) );
	}

	// -- Links ---------------------------------------------------------------

	public function test_link_unlink_round_trip(): void {
		$link = ChatIdentityLinks::link( $this->viewer_id, self::PLATFORM, 'actor-1' );
		$this->assertSame( array( 'user_id' => $this->viewer_id, 'platform' => self::PLATFORM, 'actor_id' => 'actor-1' ), $link );
		$this->assertSame( $this->viewer_id, ChatIdentityLinks::resolve_user_id( self::PLATFORM, 'actor-1' ) );
		$this->assertSame( 'actor-1', get_user_meta( $this->viewer_id, ChatIdentityLinks::meta_key( self::PLATFORM ), true ) );

		$listed = ChatIdentityLinks::list_links( $this->viewer_id );
		$this->assertSame( array( array( 'user_id' => $this->viewer_id, 'platform' => self::PLATFORM, 'actor_id' => 'actor-1' ) ), $listed );

		$this->assertTrue( ChatIdentityLinks::unlink( $this->viewer_id, self::PLATFORM ) );
		$this->assertSame( 0, ChatIdentityLinks::resolve_user_id( self::PLATFORM, 'actor-1' ) );
		$this->assertSame( array(), ChatIdentityLinks::list_links( $this->viewer_id ) );
		$this->assertFalse( ChatIdentityLinks::unlink( $this->viewer_id, self::PLATFORM ) );
	}

	public function test_links_are_platform_scoped_and_unique_per_platform(): void {
		ChatIdentityLinks::link( $this->viewer_id, 'platform-a', 'same-id' );

		$this->assertSame( $this->viewer_id, ChatIdentityLinks::resolve_user_id( 'platform-a', 'same-id' ) );
		$this->assertSame( 0, ChatIdentityLinks::resolve_user_id( 'platform-b', 'same-id' ) );

		$conflict = ChatIdentityLinks::link( $this->other_id, 'platform-a', 'same-id' );
		$this->assertWPError( $conflict );
		$this->assertSame( 'chat_identity_conflict', $conflict->get_error_code() );

		// Same actor ID on a different platform is a different identity.
		$this->assertIsArray( ChatIdentityLinks::link( $this->other_id, 'platform-b', 'same-id' ) );
		$this->assertSame( $this->other_id, ChatIdentityLinks::resolve_user_id( 'platform-b', 'same-id' ) );
	}

	public function test_link_rejects_invalid_input(): void {
		$this->assertWPError( ChatIdentityLinks::link( $this->viewer_id, '', 'x' ) );
		$this->assertWPError( ChatIdentityLinks::link( $this->viewer_id, self::PLATFORM, '  ' ) );
		$this->assertWPError( ChatIdentityLinks::link( 99999999, self::PLATFORM, 'x' ) );
	}

	// -- Resolution ----------------------------------------------------------

	public function test_unlinked_actor_is_denied_with_no_sections(): void {
		$result = $this->resolve( 'nobody' );

		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 0, $result['user_id'] );
		$this->assertSame( '', $result['role'] );
		$this->assertSame( array(), $result['sections'] );
	}

	public function test_linked_user_without_access_is_denied(): void {
		ChatIdentityLinks::link( $this->other_id, self::PLATFORM, 'stranger' );

		foreach ( array( '', 'turn' ) as $event ) {
			$result = $this->resolve( 'stranger', $event );
			$this->assertFalse( $result['allowed'] );
			$this->assertSame( '', $result['role'] );
			$this->assertSame( array(), $result['sections'] );
		}
	}

	public function test_unknown_agent_is_denied(): void {
		ChatIdentityLinks::link( $this->owner_id, self::PLATFORM, 'owner-actor' );

		$result = ( new ChatPersonResolver() )->resolve( 'no-such-agent', self::PLATFORM, 'owner-actor', 'turn' );
		$this->assertFalse( $result['allowed'] );
		$this->assertSame( array(), $result['sections'] );
	}

	public function test_owner_resolves_to_admin(): void {
		ChatIdentityLinks::link( $this->owner_id, self::PLATFORM, 'owner-actor' );

		$result = $this->resolve( 'owner-actor' );
		$this->assertTrue( $result['allowed'] );
		$this->assertSame( $this->owner_id, $result['user_id'] );
		$this->assertSame( 'admin', $result['role'] );
	}

	public function test_manage_options_resolves_to_admin_without_grant(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		ChatIdentityLinks::link( $admin_id, self::PLATFORM, 'admin-actor' );

		$result = $this->resolve( 'admin-actor' );
		$this->assertTrue( $result['allowed'] );
		$this->assertSame( 'admin', $result['role'] );
	}

	public function test_grant_roles_are_reported(): void {
		$this->grant( $this->viewer_id, 'viewer' );
		$this->grant( $this->other_id, 'operator' );
		ChatIdentityLinks::link( $this->viewer_id, self::PLATFORM, 'v' );
		ChatIdentityLinks::link( $this->other_id, self::PLATFORM, 'o' );

		$this->assertSame( 'viewer', $this->resolve( 'v' )['role'] );
		$this->assertSame( 'operator', $this->resolve( 'o' )['role'] );
	}

	public function test_access_filter_can_deny_and_grant(): void {
		ChatIdentityLinks::link( $this->owner_id, self::PLATFORM, 'owner-actor' );
		ChatIdentityLinks::link( $this->other_id, self::PLATFORM, 'stranger' );

		add_filter( 'datamachine_can_access_agent', static fn( $allowed, $agent_id, $user_id ) => $allowed, 10, 3 );
		$this->assertTrue( $this->resolve( 'owner-actor' )['allowed'] );
		remove_all_filters( 'datamachine_can_access_agent' );

		add_filter( 'datamachine_can_access_agent', '__return_false' );
		$this->assertFalse( $this->resolve( 'owner-actor' )['allowed'] );
		remove_all_filters( 'datamachine_can_access_agent' );

		add_filter( 'datamachine_can_access_agent', '__return_true' );
		$result = $this->resolve( 'stranger' );
		$this->assertTrue( $result['allowed'] );
		$this->assertSame( 'viewer', $result['role'] );
	}

	// -- Sections ------------------------------------------------------------

	public function test_non_turn_event_returns_agent_memory_and_no_personal_memory(): void {
		$this->grant( $this->viewer_id, 'viewer' );
		ChatIdentityLinks::link( $this->viewer_id, self::PLATFORM, 'v' );

		$result = $this->resolve( 'v', 'session_start' );
		$text   = $this->joined( $result );

		$this->assertTrue( $result['allowed'] );
		$this->assertStringContainsString( 'agent-soul-sentinel', $text );
		$this->assertStringNotContainsString( 'viewer-profile-sentinel', $text );
		$this->assertStringNotContainsString( 'viewer-principal-sentinel', $text );
		$this->assertNotContains( ChatPersonResolver::CURRENT_USER_SECTION, array_column( $result['sections'], 'name' ) );
	}

	public function test_viewer_turn_gets_only_own_personal_memory(): void {
		$this->grant( $this->viewer_id, 'viewer' );
		ChatIdentityLinks::link( $this->viewer_id, self::PLATFORM, 'v' );

		$result = $this->resolve( 'v', 'turn' );
		$text   = $this->joined( $result );

		$this->assertSame( 'viewer', $result['role'] );
		$this->assertStringContainsString( 'viewer-profile-sentinel', $text );
		$this->assertStringContainsString( 'viewer-principal-sentinel', $text );
		$this->assertStringNotContainsString( 'other-profile-sentinel', $text );
		$this->assertStringNotContainsString( 'other-principal-sentinel', $text );
		$this->assertStringNotContainsString( 'agent-soul-sentinel', $text );

		$first = $result['sections'][0];
		$this->assertSame( ChatPersonResolver::CURRENT_USER_SECTION, $first['name'] );
		$this->assertStringContainsString( (string) $this->viewer_id, $first['content'] );
	}

	public function test_turn_never_includes_another_users_memory(): void {
		$this->grant( $this->viewer_id, 'viewer' );
		$this->grant( $this->other_id, 'viewer' );
		ChatIdentityLinks::link( $this->viewer_id, self::PLATFORM, 'v' );
		ChatIdentityLinks::link( $this->other_id, self::PLATFORM, 'o' );

		$viewer_text = $this->joined( $this->resolve( 'v', 'turn' ) );
		$other_text  = $this->joined( $this->resolve( 'o', 'turn' ) );

		$this->assertStringNotContainsString( 'other-', $viewer_text );
		$this->assertStringNotContainsString( 'viewer-', $other_text );
	}

	public function test_turn_for_user_without_personal_files_does_not_fall_back_to_owner(): void {
		( new AgentMemory( $this->owner_id, $this->agent_id, 'USER.md', MemoryFileRegistry::LAYER_USER ) )->replace_all( "owner-profile-sentinel\n" );

		$fresh_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->grant( $fresh_id, 'viewer' );
		ChatIdentityLinks::link( $fresh_id, self::PLATFORM, 'fresh' );

		$text = $this->joined( $this->resolve( 'fresh', 'turn' ) );
		$this->assertStringNotContainsString( 'owner-profile-sentinel', $text );
	}

	public function test_file_size_cap(): void {
		( new AgentMemory( $this->owner_id, $this->agent_id, 'SOUL.md', MemoryFileRegistry::LAYER_AGENT ) )->replace_all( str_repeat( 'a', ChatPersonResolver::MAX_FILE_BYTES + 5000 ) );
		ChatIdentityLinks::link( $this->owner_id, self::PLATFORM, 'owner-actor' );

		$sections = $this->resolve( 'owner-actor', 'session_start' )['sections'];
		$soul     = array_values( array_filter( $sections, static fn( $s ) => 'SOUL.md' === $s['name'] ) );

		$this->assertCount( 1, $soul );
		$this->assertSame( ChatPersonResolver::MAX_FILE_BYTES, strlen( $soul[0]['content'] ) );
	}

	public function test_sections_are_sorted_by_priority_and_bounded(): void {
		ChatIdentityLinks::link( $this->owner_id, self::PLATFORM, 'owner-actor' );

		$sections   = $this->resolve( 'owner-actor', 'session_start' )['sections'];
		$priorities = array_column( $sections, 'priority' );
		$sorted     = $priorities;
		sort( $sorted );

		$this->assertSame( $sorted, $priorities );
		$this->assertLessThanOrEqual( ChatPersonResolver::MAX_SECTIONS, count( $sections ) );
		$this->assertLessThanOrEqual( ChatPersonResolver::MAX_TOTAL_BYTES, strlen( $this->joined( array( 'sections' => $sections ) ) ) );
	}

	// -- No actor ------------------------------------------------------------

	public function test_non_turn_event_without_actor_returns_agent_layers_only(): void {
		foreach ( array( 'session_start', '' ) as $event ) {
			$result = $this->resolve( '', $event );
			$text   = $this->joined( $result );

			$this->assertFalse( $result['allowed'], $event );
			$this->assertSame( 0, $result['user_id'], $event );
			$this->assertSame( '', $result['role'], $event );
			$this->assertSame( ChatPersonResolver::REASON_NO_ACTOR, $result['reason'], $event );
			$this->assertStringContainsString( 'agent-soul-sentinel', $text, $event );

			foreach ( $result['sections'] as $section ) {
				$this->assertContains( $section['layer'], ChatPersonResolver::AGENT_LAYERS, $event );
			}
			$this->assertStringNotContainsString( 'profile-sentinel', $text, $event );
			$this->assertStringNotContainsString( 'principal-sentinel', $text, $event );
			$this->assertNotContains( ChatPersonResolver::CURRENT_USER_SECTION, array_column( $result['sections'], 'name' ), $event );
		}
	}

	public function test_whitespace_actor_counts_as_no_actor(): void {
		$result = $this->resolve( '   ', 'session_start' );

		$this->assertSame( ChatPersonResolver::REASON_NO_ACTOR, $result['reason'] );
		$this->assertStringContainsString( 'agent-soul-sentinel', $this->joined( $result ) );
	}

	public function test_non_turn_event_without_actor_does_not_read_owner_personal_layers(): void {
		( new AgentMemory( $this->owner_id, $this->agent_id, 'USER.md', MemoryFileRegistry::LAYER_USER ) )->replace_all( "owner-profile-sentinel\n" );

		$text = $this->joined( $this->resolve( '', 'session_start' ) );
		$this->assertStringNotContainsString( 'owner-profile-sentinel', $text );
	}

	public function test_turn_event_without_actor_is_denied(): void {
		$result = $this->resolve( '', 'turn' );

		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 0, $result['user_id'] );
		$this->assertSame( '', $result['role'] );
		$this->assertSame( array(), $result['sections'] );
		$this->assertSame( ChatPersonResolver::REASON_NO_ACTOR, $result['reason'] );
	}

	public function test_turn_and_non_turn_with_unlinked_actor_stay_denied(): void {
		foreach ( array( 'turn', 'session_start' ) as $event ) {
			$result = $this->resolve( 'nobody', $event );

			$this->assertFalse( $result['allowed'], $event );
			$this->assertSame( array(), $result['sections'], $event );
			$this->assertSame( 'actor_not_linked', $result['reason'], $event );
		}
	}

	public function test_no_actor_for_unknown_agent_is_denied(): void {
		$result = ( new ChatPersonResolver() )->resolve( 'no-such-agent', self::PLATFORM, '', 'session_start' );

		$this->assertFalse( $result['allowed'] );
		$this->assertSame( array(), $result['sections'] );
		$this->assertSame( 'agent_not_found', $result['reason'] );
	}

	// -- Convention path -----------------------------------------------------

	public function test_section_carries_convention_path_when_registry_declares_one(): void {
		MemoryFileRegistry::register(
			self::CONVENTION_FILE,
			15,
			array(
				'layer'           => MemoryFileRegistry::LAYER_SHARED,
				'convention_path' => self::CONVENTION_PATH,
				'modes'           => array( MemoryFileRegistry::MODE_ALL ),
			)
		);
		( new AgentMemory( $this->owner_id, $this->agent_id, self::CONVENTION_FILE, MemoryFileRegistry::LAYER_SHARED ) )->replace_all( "convention-sentinel\n" );

		$sections = $this->resolve( '', 'session_start' )['sections'];
		$by_name  = array_column( $sections, null, 'name' );

		$this->assertArrayHasKey( self::CONVENTION_FILE, $by_name );
		$this->assertStringContainsString( 'convention-sentinel', $by_name[ self::CONVENTION_FILE ]['content'] );
		$this->assertSame( self::CONVENTION_PATH, $by_name[ self::CONVENTION_FILE ]['convention_path'] );

		$this->assertArrayHasKey( 'SOUL.md', $by_name );
		$this->assertArrayNotHasKey( 'convention_path', $by_name['SOUL.md'] );
	}

	public function test_turn_sections_omit_convention_path_when_not_declared(): void {
		$this->grant( $this->viewer_id, 'viewer' );
		ChatIdentityLinks::link( $this->viewer_id, self::PLATFORM, 'v' );

		foreach ( $this->resolve( 'v', 'turn' )['sections'] as $section ) {
			$this->assertArrayNotHasKey( 'convention_path', $section, $section['name'] );
		}
	}

	// -- Abilities -----------------------------------------------------------

	public function test_abilities_are_admin_only_and_not_in_rest(): void {
		foreach ( array( 'link-chat-identity', 'unlink-chat-identity', 'list-chat-identities', 'resolve-chat-person' ) as $name ) {
			$ability = wp_get_ability( "datamachine/{$name}" );
			$this->assertNotNull( $ability, $name );
			$this->assertFalse( (bool) ( $ability->get_meta()['show_in_rest'] ?? false ), $name );
		}

		wp_set_current_user( $this->viewer_id );
		$denied = wp_get_ability( 'datamachine/resolve-chat-person' )->execute(
			array( 'agent_slug' => $this->slug, 'platform' => self::PLATFORM, 'actor_id' => 'x' )
		);
		$this->assertWPError( $denied );
	}

	public function test_ability_accepts_missing_actor_for_agent_context(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = wp_get_ability( 'datamachine/resolve-chat-person' )->execute(
			array(
				'agent_slug' => $this->slug,
				'platform'   => self::PLATFORM,
				'event'      => 'session_start',
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['allowed'] );
		$this->assertSame( ChatPersonResolver::REASON_NO_ACTOR, $result['reason'] );
		$this->assertStringContainsString( 'agent-soul-sentinel', $this->joined( $result ) );

		$turn = wp_get_ability( 'datamachine/resolve-chat-person' )->execute(
			array(
				'agent_slug' => $this->slug,
				'platform'   => self::PLATFORM,
				'event'      => 'turn',
			)
		);
		$this->assertSame( array(), $turn['sections'] );
	}

	public function test_ability_link_resolve_unlink_round_trip(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$linked = wp_get_ability( 'datamachine/link-chat-identity' )->execute(
			array( 'user_id' => $this->owner_id, 'platform' => self::PLATFORM, 'actor_id' => 'owner-actor' )
		);
		$this->assertTrue( $linked['success'] );

		$listed = wp_get_ability( 'datamachine/list-chat-identities' )->execute( array( 'platform' => self::PLATFORM ) );
		$this->assertSame( 'owner-actor', $listed['identities'][0]['actor_id'] );

		$resolved = wp_get_ability( 'datamachine/resolve-chat-person' )->execute(
			array( 'agent_slug' => $this->slug, 'platform' => self::PLATFORM, 'actor_id' => 'owner-actor', 'event' => 'turn' )
		);
		$this->assertTrue( $resolved['allowed'] );
		$this->assertSame( 'admin', $resolved['role'] );
		$this->assertSame( $this->owner_id, $resolved['user_id'] );

		$unlinked = wp_get_ability( 'datamachine/unlink-chat-identity' )->execute(
			array( 'user_id' => $this->owner_id, 'platform' => self::PLATFORM )
		);
		$this->assertTrue( $unlinked['removed'] );

		$after = wp_get_ability( 'datamachine/resolve-chat-person' )->execute(
			array( 'agent_slug' => $this->slug, 'platform' => self::PLATFORM, 'actor_id' => 'owner-actor' )
		);
		$this->assertFalse( $after['allowed'] );
	}
}
