<?php
/**
 * Contract tests for the generic account-level messages ability.
 */

namespace DataMachineSocials\Tests\Unit\Abilities;

use DataMachineSocials\Abilities\SocialMessagesAbility;
use WP_UnitTestCase;

class SocialMessagesAbilityTest extends WP_UnitTestCase {

	private SocialMessagesAbility $ability;

	public function set_up(): void {
		parent::set_up();
		$this->ability = new SocialMessagesAbility();
	}

	public function test_unsupported_provider_is_explicit(): void {
		$result = $this->ability->execute( array( 'provider' => 'threads' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'threads', $result['data']['provider'] );
		$this->assertSame( 'unsupported', $result['data']['status'] );
	}

	public function test_missing_provider_is_explicitly_unsupported(): void {
		$result = $this->ability->execute( array() );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'unsupported', $result['data']['status'] );
	}

	public function test_unknown_action_is_rejected_by_the_contract(): void {
		$result = $this->ability->execute( array( 'action' => 'recent_messages', 'provider' => 'instagram' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'invalid_action', $result['data']['status'] );
	}

	public function test_send_without_recipient_or_message_is_invalid_input(): void {
		$result = $this->ability->execute( array( 'action' => 'send', 'provider' => 'instagram' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'invalid_input', $result['data']['status'] );
	}

	public function test_messages_action_requires_conversation_id(): void {
		$result = $this->ability->execute( array( 'action' => 'messages', 'provider' => 'instagram' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'invalid_input', $result['data']['status'] );
	}

	public function test_conversations_are_normalized_through_the_provider_contract(): void {
		$ability = new class() extends SocialMessagesAbility {
			protected function getProviderAbility( string $provider, string $kind = 'read' ) {
				return new class() {
					public function execute( array $input ): array {
						return array(
							'success' => true,
							'data'    => array(
								'conversations' => array( array(
									'id'           => 'thread-1',
									'participant'  => array( 'id' => 'igs-1', 'username' => 'listener' ),
									'updated_time' => '2026-09-01T10:00:00Z',
									'unread_count' => 2,
									'raw'          => array( 'id' => 'thread-1' ),
								) ),
								'cursors'       => array( 'after' => 'cursor-1' ),
								'has_next'      => true,
							),
						);
					}
				};
			}
		};

		$result = $ability->execute( array( 'provider' => 'instagram' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'thread-1', $result['data']['conversations'][0]['id'] );
		$this->assertSame( 'instagram', $result['data']['conversations'][0]['platform'] );
		$this->assertSame( 'listener', $result['data']['conversations'][0]['participant']['username'] );
		$this->assertSame( 2, $result['data']['conversations'][0]['unread_count'] );
		$this->assertSame( 'cursor-1', $result['data']['next_cursor'] );
		$this->assertSame( 1, $result['data']['count'] );
	}

	public function test_messages_are_normalized_with_echo_flag(): void {
		$ability = new class() extends SocialMessagesAbility {
			protected function getProviderAbility( string $provider, string $kind = 'read' ) {
				return new class() {
					public function execute( array $input ): array {
						return array(
							'success' => true,
							'data'    => array(
								'conversation_id' => 'thread-1',
								'messages'        => array( array(
									'id'           => 'msg-1',
									'from'         => array( 'id' => 'igs-1', 'username' => 'listener' ),
									'is_echo'      => false,
									'text'         => 'hey there',
									'created_time' => '2026-09-01T10:00:00Z',
									'raw'          => array( 'id' => 'msg-1' ),
								) ),
							),
						);
					}
				};
			}
		};

		$result = $ability->execute( array( 'action' => 'messages', 'provider' => 'instagram', 'conversation_id' => 'thread-1' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'msg-1', $result['data']['messages'][0]['id'] );
		$this->assertSame( 'instagram', $result['data']['messages'][0]['platform'] );
		$this->assertSame( 'thread-1', $result['data']['messages'][0]['conversation_id'] );
		$this->assertFalse( $result['data']['messages'][0]['is_echo'] );
		$this->assertSame( 'hey there', $result['data']['messages'][0]['text'] );
		$this->assertSame( 1, $result['data']['count'] );
	}
}
