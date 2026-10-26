<?php
/**
 * Chat identity links tests: several actors per user per platform.
 *
 * @package DataMachine\Tests\Unit\Core\Agents
 */

namespace DataMachine\Tests\Unit\Core\Agents;

use DataMachine\Core\Agents\ChatIdentityLinks;
use WP_UnitTestCase;

class ChatIdentityLinksTest extends WP_UnitTestCase {

	private const PLATFORM = 'discord';

	private int $user_id;
	private int $other_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	private function stored_actors( int $user_id, string $platform = self::PLATFORM ): array {
		return array_map( 'strval', get_user_meta( $user_id, ChatIdentityLinks::meta_key( $platform ), false ) );
	}

	public function test_two_actors_on_one_platform_both_resolve_to_the_user(): void {
		$this->assertIsArray( ChatIdentityLinks::link( $this->user_id, self::PLATFORM, 'actor-a' ) );
		$this->assertIsArray( ChatIdentityLinks::link( $this->user_id, self::PLATFORM, 'actor-b' ) );

		$this->assertSame( $this->user_id, ChatIdentityLinks::resolve_user_id( self::PLATFORM, 'actor-a' ) );
		$this->assertSame( $this->user_id, ChatIdentityLinks::resolve_user_id( self::PLATFORM, 'actor-b' ) );
		$this->assertEqualsCanonicalizing( array( 'actor-a', 'actor-b' ), $this->stored_actors( $this->user_id ) );
	}

	public function test_relinking_the_same_actor_adds_no_duplicate_row(): void {
		ChatIdentityLinks::link( $this->user_id, self::PLATFORM, 'actor-a' );
		$again = ChatIdentityLinks::link( $this->user_id, self::PLATFORM, 'actor-a' );

		$this->assertSame(
			array(
				'user_id'  => $this->user_id,
				'platform' => self::PLATFORM,
				'actor_id' => 'actor-a',
			),
			$again
		);
		$this->assertSame( array( 'actor-a' ), $this->stored_actors( $this->user_id ) );
	}

	public function test_second_user_cannot_link_an_actor_the_first_holds(): void {
		ChatIdentityLinks::link( $this->user_id, self::PLATFORM, 'actor-a' );
		ChatIdentityLinks::link( $this->user_id, self::PLATFORM, 'actor-b' );

		$conflict = ChatIdentityLinks::link( $this->other_id, self::PLATFORM, 'actor-b' );
		$this->assertWPError( $conflict );
		$this->assertSame( 'chat_identity_conflict', $conflict->get_error_code() );
		$this->assertSame( array(), $this->stored_actors( $this->other_id ) );
		$this->assertSame( $this->user_id, ChatIdentityLinks::resolve_user_id( self::PLATFORM, 'actor-b' ) );
	}

	public function test_unlink_with_actor_removes_only_that_actor(): void {
		ChatIdentityLinks::link( $this->user_id, self::PLATFORM, 'actor-a' );
		ChatIdentityLinks::link( $this->user_id, self::PLATFORM, 'actor-b' );

		$this->assertTrue( ChatIdentityLinks::unlink( $this->user_id, self::PLATFORM, 'actor-a' ) );

		$this->assertSame( 0, ChatIdentityLinks::resolve_user_id( self::PLATFORM, 'actor-a' ) );
		$this->assertSame( $this->user_id, ChatIdentityLinks::resolve_user_id( self::PLATFORM, 'actor-b' ) );
		$this->assertSame( array( 'actor-b' ), $this->stored_actors( $this->user_id ) );

		// Unlinking an actor the user does not hold removes nothing.
		$this->assertFalse( ChatIdentityLinks::unlink( $this->user_id, self::PLATFORM, 'actor-a' ) );
		$this->assertSame( array( 'actor-b' ), $this->stored_actors( $this->user_id ) );

		// An actor ID that fails normalization never widens to removing all links.
		$this->assertFalse( ChatIdentityLinks::unlink( $this->user_id, self::PLATFORM, str_repeat( 'x', ChatIdentityLinks::MAX_ACTOR_ID_LENGTH + 1 ) ) );
		$this->assertSame( array( 'actor-b' ), $this->stored_actors( $this->user_id ) );
	}

	public function test_unlink_without_actor_removes_all_links_on_the_platform(): void {
		ChatIdentityLinks::link( $this->user_id, self::PLATFORM, 'actor-a' );
		ChatIdentityLinks::link( $this->user_id, self::PLATFORM, 'actor-b' );
		ChatIdentityLinks::link( $this->user_id, 'other-platform', 'actor-c' );

		$this->assertTrue( ChatIdentityLinks::unlink( $this->user_id, self::PLATFORM ) );

		$this->assertSame( 0, ChatIdentityLinks::resolve_user_id( self::PLATFORM, 'actor-a' ) );
		$this->assertSame( 0, ChatIdentityLinks::resolve_user_id( self::PLATFORM, 'actor-b' ) );
		$this->assertSame( array(), $this->stored_actors( $this->user_id ) );
		$this->assertSame( $this->user_id, ChatIdentityLinks::resolve_user_id( 'other-platform', 'actor-c' ) );

		$this->assertFalse( ChatIdentityLinks::unlink( $this->user_id, self::PLATFORM ) );
	}

	public function test_actions_only_fire_for_persisted_identity_changes(): void {
		$linked = array();
		$unlinked = array();
		$on_link = static function ( ...$args ) use ( &$linked ) { $linked[] = $args; };
		$on_unlink = static function ( ...$args ) use ( &$unlinked ) { $unlinked[] = $args; };
		add_action( 'datamachine_chat_identity_linked', $on_link, 10, 4 );
		add_action( 'datamachine_chat_identity_unlinked', $on_unlink, 10, 4 );
		try {
			ChatIdentityLinks::link( $this->user_id, ' Test-Chat ', ' actor-a ' );
			ChatIdentityLinks::link( $this->user_id, 'test-chat', 'actor-a' );
			$this->assertWPError( ChatIdentityLinks::link( $this->other_id, 'test-chat', 'actor-a' ) );
			$this->assertWPError( ChatIdentityLinks::link( 0, 'test-chat', 'actor-b' ) );
			$this->assertSame( array( array( $this->user_id, 'test-chat', 'actor-a', array() ) ), $linked );
			$this->assertTrue( ChatIdentityLinks::unlink( $this->user_id, 'test-chat', 'actor-a' ) );
			$this->assertFalse( ChatIdentityLinks::unlink( $this->user_id, 'test-chat', 'actor-a' ) );
			ChatIdentityLinks::link( $this->user_id, 'test-chat', 'actor-b' );
			ChatIdentityLinks::link( $this->user_id, 'test-chat', 'actor-c' );
			$this->assertTrue( ChatIdentityLinks::unlink( $this->user_id, 'test-chat' ) );
			$this->assertSame( array(
				array( $this->user_id, 'test-chat', 'actor-a', array() ),
				array( $this->user_id, 'test-chat', 'actor-b', array() ),
				array( $this->user_id, 'test-chat', 'actor-c', array() ),
			), $unlinked );
		} finally {
			remove_action( 'datamachine_chat_identity_linked', $on_link );
			remove_action( 'datamachine_chat_identity_unlinked', $on_unlink );
		}
	}

	public function test_failed_metadata_writes_do_not_fire_actions(): void {
		ChatIdentityLinks::link( $this->user_id, 'test-chat', 'existing' );
		$linked_before = did_action( 'datamachine_chat_identity_linked' );
		$unlinked_before = did_action( 'datamachine_chat_identity_unlinked' );
		$fail = static fn() => false;
		add_filter( 'add_user_metadata', $fail );
		add_filter( 'delete_user_metadata', $fail );
		try {
			$this->assertWPError( ChatIdentityLinks::link( $this->user_id, 'test-chat', 'new' ) );
			$this->assertFalse( ChatIdentityLinks::unlink( $this->user_id, 'test-chat', 'existing' ) );
			$this->assertFalse( ChatIdentityLinks::unlink( $this->user_id, 'test-chat' ) );
			$this->assertSame( $linked_before, did_action( 'datamachine_chat_identity_linked' ) );
			$this->assertSame( $unlinked_before, did_action( 'datamachine_chat_identity_unlinked' ) );
		} finally {
			remove_filter( 'add_user_metadata', $fail );
			remove_filter( 'delete_user_metadata', $fail );
		}
	}

	public function test_list_returns_one_row_per_actor(): void {
		ChatIdentityLinks::link( $this->user_id, self::PLATFORM, 'actor-a' );
		ChatIdentityLinks::link( $this->user_id, self::PLATFORM, 'actor-b' );

		$expected = array(
			array(
				'user_id'  => $this->user_id,
				'platform' => self::PLATFORM,
				'actor_id' => 'actor-a',
			),
			array(
				'user_id'  => $this->user_id,
				'platform' => self::PLATFORM,
				'actor_id' => 'actor-b',
			),
		);

		$this->assertEqualsCanonicalizing( $expected, ChatIdentityLinks::list_links( $this->user_id ) );
		$this->assertEqualsCanonicalizing( $expected, ChatIdentityLinks::list_links( $this->user_id, self::PLATFORM ) );
	}
}
