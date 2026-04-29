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
use DataMachine\Core\Database\Chat\ConversationReadStateInterface;
use DataMachine\Core\Database\Chat\ConversationReportingInterface;
use DataMachine\Core\Database\Chat\ConversationRetentionInterface;
use DataMachine\Core\Database\Chat\ConversationSessionIndexInterface;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\Database\Chat\ConversationStoreInterface;
use DataMachine\Core\Database\Chat\ConversationTranscriptStoreInterface;
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
		$this->assertInstanceOf( ConversationTranscriptStoreInterface::class, $store );
		$this->assertInstanceOf( ConversationSessionIndexInterface::class, $store );
		$this->assertInstanceOf( ConversationReadStateInterface::class, $store );
		$this->assertInstanceOf( ConversationRetentionInterface::class, $store );
		$this->assertInstanceOf( ConversationReportingInterface::class, $store );
		$this->assertInstanceOf( Chat::class, $store );
	}

	public function test_transcript_resolution_returns_narrow_contract(): void {
		$store = ConversationStoreFactory::get_transcript_store();

		$this->assertInstanceOf( ConversationTranscriptStoreInterface::class, $store );
		$this->assertInstanceOf( ConversationStoreInterface::class, ConversationStoreFactory::get() );
		$this->assertSame( ConversationStoreFactory::get(), $store );
	}

	public function test_conversation_store_interface_is_composed_from_narrow_contracts(): void {
		$reflection = new \ReflectionClass( ConversationStoreInterface::class );
		$expected   = array(
			ConversationTranscriptStoreInterface::class,
			ConversationSessionIndexInterface::class,
			ConversationReadStateInterface::class,
			ConversationRetentionInterface::class,
			ConversationReportingInterface::class,
		);
		$actual     = array_values( $reflection->getInterfaceNames() );

		sort( $expected );
		sort( $actual );

		$this->assertSame( $expected, $actual );
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
		$this->assertInstanceOf( ConversationTranscriptStoreInterface::class, $resolved );
		$this->assertInstanceOf( ConversationSessionIndexInterface::class, $resolved );
		$this->assertInstanceOf( ConversationReadStateInterface::class, $resolved );
		$this->assertInstanceOf( ConversationRetentionInterface::class, $resolved );
		$this->assertInstanceOf( ConversationReportingInterface::class, $resolved );
		$this->assertNotInstanceOf( Chat::class, $resolved );
	}

	public function test_transcript_resolution_uses_conversation_store_filter(): void {
		$memory_store = new InMemoryConversationStore();

		add_filter(
			'datamachine_conversation_store',
			static fn() => $memory_store,
			10,
			1
		);

		$resolved = ConversationStoreFactory::get_transcript_store();

		$this->assertSame( $memory_store, $resolved );
		$this->assertInstanceOf( ConversationTranscriptStoreInterface::class, $resolved );
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

	// -----------------------------------------------------------------
	// New methods that seal the raw-SQL leaks
	// -----------------------------------------------------------------

	public function test_list_sessions_for_day_returns_rows_in_chronological_order(): void {
		$store = new InMemoryConversationStore();
		$user  = 42;

		// Seed three sessions across two days with explicit created_at values
		// by updating the in-memory rows directly via reflection — we don't
		// want test timing to race the day boundary.
		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );

		$a = $store->create_session( $user );
		$b = $store->create_session( $user );
		$c = $store->create_session( $user );

		$ref      = new \ReflectionClass( $store );
		$sessions = $ref->getProperty( 'sessions' );
		$sessions->setAccessible( true );
		$raw = $sessions->getValue( $store );

		$raw[ $a ]['created_at'] = "{$today} 09:00:00";
		$raw[ $a ]['title']      = 'First of today';
		$raw[ $b ]['created_at'] = "{$today} 14:30:00";
		$raw[ $b ]['title']      = 'Later today';
		$raw[ $c ]['created_at'] = "{$yesterday} 23:00:00";
		$raw[ $c ]['title']      = 'From yesterday';

		$sessions->setValue( $store, $raw );

		$rows = $store->list_sessions_for_day( $today );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'First of today', $rows[0]['title'] );
		$this->assertSame( 'Later today', $rows[1]['title'] );
		// Full shape contract:
		$this->assertSame( array( 'session_id', 'title', 'context', 'created_at' ), array_keys( $rows[0] ) );
		$this->assertSame( 'chat', $rows[0]['context'] );
	}

	public function test_list_sessions_for_day_returns_empty_when_no_sessions(): void {
		$store = new InMemoryConversationStore();
		$this->assertSame( array(), $store->list_sessions_for_day( '1999-01-01' ) );
	}

	public function test_get_storage_metrics_returns_rows_shape(): void {
		$store = new InMemoryConversationStore();

		$metrics = $store->get_storage_metrics();

		$this->assertIsArray( $metrics );
		$this->assertArrayHasKey( 'rows', $metrics );
		$this->assertArrayHasKey( 'size_mb', $metrics );
		$this->assertSame( 0, $metrics['rows'] );

		$store->create_session( 1 );
		$store->create_session( 1 );
		$store->create_session( 2 );

		$metrics = $store->get_storage_metrics();
		$this->assertSame( 3, $metrics['rows'] );
	}

	public function test_transcript_only_contract_can_persist_messages(): void {
		$store      = new InMemoryConversationStore();
		$transcript = $this->persist_fixture_transcript( $store );

		$this->assertSame( 'openai', $transcript['provider'] );
		$this->assertSame( 'gpt-test', $transcript['model'] );
		$this->assertSame( 'pipeline', $transcript['context'] );
		$this->assertSame( 'assistant response', $transcript['messages'][1]['content'] );
		$this->assertSame( 99, $transcript['metadata']['job_id'] );
	}

	/**
	 * Persist a transcript through only the narrow runtime contract.
	 *
	 * @param ConversationTranscriptStoreInterface $store Transcript store.
	 * @return array<string, mixed>
	 */
	private function persist_fixture_transcript( ConversationTranscriptStoreInterface $store ): array {
		$session_id = $store->create_session(
			5,
			7,
			array(
				'source' => 'pipeline_transcript',
				'job_id' => 99,
			),
			'pipeline'
		);

		$this->assertNotSame( '', $session_id );

		$updated = $store->update_session(
			$session_id,
			array(
				array(
					'role'    => 'user',
					'content' => 'input',
				),
				array(
					'role'    => 'assistant',
					'content' => 'assistant response',
				),
			),
			array(
				'source' => 'pipeline_transcript',
				'job_id' => 99,
			),
			'openai',
			'gpt-test'
		);

		$this->assertTrue( $updated );

		$session = $store->get_session( $session_id );
		$this->assertNotNull( $session );

		return $session;
	}

	public function test_list_sessions_for_day_observed_by_swapped_store_through_factory(): void {
		$store = new InMemoryConversationStore();
		add_filter(
			'datamachine_conversation_store',
			static fn() => $store,
			10,
			1
		);
		ConversationStoreFactory::reset();

		$session_id = $store->create_session( 7, 0, array(), 'chat' );
		$ref        = new \ReflectionClass( $store );
		$sessions   = $ref->getProperty( 'sessions' );
		$sessions->setAccessible( true );
		$raw                           = $sessions->getValue( $store );
		$raw[ $session_id ]['title']   = 'Pinned day-scoped session';
		$raw[ $session_id ]['created_at'] = '2026-04-20 10:00:00';
		$sessions->setValue( $store, $raw );

		$rows = ConversationStoreFactory::get()->list_sessions_for_day( '2026-04-20' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Pinned day-scoped session', $rows[0]['title'] );
	}

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
