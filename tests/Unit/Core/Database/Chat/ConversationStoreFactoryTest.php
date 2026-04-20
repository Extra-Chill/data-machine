<?php
/**
 * Conversation Store Factory Tests
 *
 * Coverage for the `datamachine_conversation_store` filter seam:
 * - default resolution returns the built-in Chat store
 * - a filter can swap the store
 * - a misbehaving filter (non-interface return) falls back to the default
 * - the instance is cached per request
 * - abilities routed through ChatSessionHelpers observe the swap
 *
 * @package DataMachine\Tests\Unit\Core\Database\Chat
 */

namespace DataMachine\Tests\Unit\Core\Database\Chat;

use DataMachine\Abilities\Chat\ListChatSessionsAbility;
use DataMachine\Core\Database\Chat\Chat;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\Database\Chat\ConversationStoreInterface;
use WP_UnitTestCase;

class ConversationStoreFactoryTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		ConversationStoreFactory::reset();
	}

	public function tear_down(): void {
		ConversationStoreFactory::reset();
		remove_all_filters( 'datamachine_conversation_store' );
		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// Factory contract
	// -----------------------------------------------------------------

	public function test_default_resolution_returns_builtin_chat_store(): void {
		$store = ConversationStoreFactory::get();

		$this->assertInstanceOf( ConversationStoreInterface::class, $store );
		$this->assertInstanceOf( Chat::class, $store );
	}

	public function test_filter_swaps_the_store(): void {
		$memory_store = new InMemoryConversationStore();

		add_filter(
			'datamachine_conversation_store',
			static fn() => $memory_store,
			10,
			1
		);

		$resolved = ConversationStoreFactory::get();

		$this->assertSame( $memory_store, $resolved );
		$this->assertNotInstanceOf( Chat::class, $resolved );
	}

	public function test_misbehaving_filter_falls_back_to_default(): void {
		add_filter(
			'datamachine_conversation_store',
			static fn() => 'not-a-store',
			10,
			1
		);

		$resolved = ConversationStoreFactory::get();

		$this->assertInstanceOf( Chat::class, $resolved );
	}

	public function test_resolution_is_cached_per_request(): void {
		$calls = 0;
		add_filter(
			'datamachine_conversation_store',
			static function ( $store ) use ( &$calls ) {
				++$calls;
				return $store;
			},
			10,
			1
		);

		ConversationStoreFactory::get();
		ConversationStoreFactory::get();
		ConversationStoreFactory::get();

		$this->assertSame( 1, $calls, 'The filter should run exactly once per request.' );
	}

	public function test_reset_lets_tests_install_a_fresh_filter(): void {
		$first = ConversationStoreFactory::get();
		$this->assertInstanceOf( Chat::class, $first );

		$memory_store = new InMemoryConversationStore();
		add_filter(
			'datamachine_conversation_store',
			static fn() => $memory_store,
			10,
			1
		);

		// Without reset, we'd still see the cached default.
		$still_cached = ConversationStoreFactory::get();
		$this->assertInstanceOf( Chat::class, $still_cached );

		ConversationStoreFactory::reset();
		$this->assertSame( $memory_store, ConversationStoreFactory::get() );
	}

	// -----------------------------------------------------------------
	// End-to-end: abilities observe the swap through ChatSessionHelpers
	// -----------------------------------------------------------------

	public function test_list_chat_sessions_ability_routes_through_swapped_store(): void {
		$memory_store = new InMemoryConversationStore();
		$user_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Seed the in-memory store with a session under the target user.
		$session_id = $memory_store->create_session( $user_id, 0, array(), 'chat' );
		$memory_store->update_session(
			$session_id,
			array(
				array(
					'role'     => 'user',
					'content'  => 'hello',
					'metadata' => array( 'type' => 'text' ),
				),
			)
		);

		add_filter(
			'datamachine_conversation_store',
			static fn() => $memory_store,
			10,
			1
		);
		ConversationStoreFactory::reset();

		$ability = new ListChatSessionsAbility();
		$result  = $ability->execute(
			array(
				'user_id' => $user_id,
				'limit'   => 10,
				'offset'  => 0,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['sessions'] );
		$this->assertSame( $session_id, $result['sessions'][0]['session_id'] );
		$this->assertSame( 'hello', $result['sessions'][0]['first_message'] );
	}
}
