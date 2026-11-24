<?php
/**
 * MemoryCommand user-scope tests.
 *
 * WP-CLI consumes `--user` as a global parameter and sets the current user,
 * so the memory CLI must honor get_current_user_id() for USER.md and
 * principal (USER_MEMORY.md) memory instead of silently falling back to the
 * agent owner.
 *
 * @package DataMachine\Tests\Unit\Cli
 * @see https://github.com/Extra-Chill/data-machine/issues/3697
 */

namespace DataMachine\Tests\Unit\Cli;

use DataMachine\Abilities\AgentMemoryAbilities;
use DataMachine\Cli\Commands\MemoryCommand;
use DataMachine\Cli\UserResolver;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\FilesRepository\AgentMemory;
use DataMachine\Core\FilesRepository\DirectoryManager;
use ReflectionMethod;
use WP_UnitTestCase;

class MemoryCommandUserScopeTest extends WP_UnitTestCase {

	private int $owner_id;
	private int $member_id;
	private int $agent_id;
	private string $agent_slug;

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( 'WP_CLI_Command' ) || ! class_exists( MemoryCommand::class ) ) {
			$this->markTestSkipped( 'WP-CLI command classes are not loaded.' );
		}

		$this->owner_id   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->agent_slug = 'memory-user-scope-' . wp_generate_password( 6, false, false );
		$this->agent_id   = ( new Agents() )->create_if_missing( $this->agent_slug, 'Memory User Scope', $this->owner_id );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function scoping( array $assoc_args ): array {
		$method = new ReflectionMethod( MemoryCommand::class, 'resolveMemoryScoping' );
		$method->setAccessible( true );

		return $method->invoke( new MemoryCommand(), $assoc_args );
	}

	public function test_acting_user_falls_back_to_wp_cli_global_user(): void {
		$this->assertSame( 0, UserResolver::resolveActingUser( array() ) );

		wp_set_current_user( $this->member_id );
		$this->assertSame( $this->member_id, UserResolver::resolveActingUser( array() ) );
		$this->assertSame( $this->owner_id, UserResolver::resolveActingUser( array( 'user' => (string) $this->owner_id ) ) );
	}

	public function test_global_user_scopes_user_memory_with_explicit_agent(): void {
		wp_set_current_user( $this->member_id );

		$scoping = $this->scoping( array( 'agent' => $this->agent_slug ) );

		$this->assertSame( $this->agent_id, $scoping['agent_id'] ?? null );
		$this->assertSame( $this->member_id, $scoping['user_id'] ?? null, '--agent must not replace the WP-CLI --user with the agent owner.' );
	}

	public function test_agent_owner_is_fallback_without_user(): void {
		$scoping = $this->scoping( array( 'agent' => $this->agent_slug ) );

		$this->assertSame( $this->owner_id, $scoping['user_id'] ?? null );
	}

	public function test_user_md_write_and_read_touch_only_requested_user(): void {
		wp_set_current_user( $this->member_id );

		$scoping = $this->scoping( array( 'agent' => $this->agent_slug ) );
		$marker  = 'member-preference-' . wp_generate_password( 8, false, false );

		$result = AgentMemoryAbilities::updateMemory(
			array_merge(
				$scoping,
				array(
					'file'    => 'USER.md',
					'section' => 'Preferences',
					'content' => $marker,
					'mode'    => 'set',
				)
			)
		);
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$read = AgentMemoryAbilities::getMemory(
			array_merge(
				$scoping,
				array(
					'file'    => 'USER.md',
					'section' => 'Preferences',
				)
			)
		);
		$this->assertIsArray( $read );
		$this->assertStringContainsString( $marker, (string) ( $read['content'] ?? '' ) );

		$dm          = new DirectoryManager();
		$member_path = ( new AgentMemory( $this->member_id, $this->agent_id, 'USER.md' ) )->get_file_path();
		$owner_path  = ( new AgentMemory( $this->owner_id, $this->agent_id, 'USER.md' ) )->get_file_path();

		$this->assertStringStartsWith( $dm->get_user_directory( $this->member_id ) . '/', $member_path );

		$owner_memory = ( new AgentMemory( $this->owner_id, $this->agent_id, 'USER.md' ) )->get_all();
		$this->assertStringNotContainsString( $marker, (string) ( $owner_memory['content'] ?? '' ), "Owner USER.md ({$owner_path}) must be untouched." );
	}

	public function test_principal_memory_round_trips_for_non_owner_user(): void {
		wp_set_current_user( $this->member_id );

		$scoping = $this->scoping( array( 'agent' => $this->agent_slug ) );
		$marker  = 'principal-note-' . wp_generate_password( 8, false, false );

		$result = AgentMemoryAbilities::updateMemory(
			array_merge(
				$scoping,
				array(
					'file'    => 'USER_MEMORY.md',
					'section' => 'Notes',
					'content' => $marker,
					'mode'    => 'set',
				)
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['success'] );

		$read = AgentMemoryAbilities::getMemory(
			array_merge(
				$scoping,
				array(
					'file'    => 'USER_MEMORY.md',
					'section' => 'Notes',
				)
			)
		);
		$this->assertIsArray( $read );
		$this->assertStringContainsString( $marker, (string) ( $read['content'] ?? '' ) );

		$owner_memory = ( new AgentMemory( $this->owner_id, $this->agent_id, 'USER_MEMORY.md' ) )->get_all();
		$this->assertStringNotContainsString( $marker, (string) ( $owner_memory['content'] ?? '' ) );
	}
}
