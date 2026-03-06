<?php
/**
 * SystemAbilities Tests
 *
 * Tests for system infrastructure abilities including session title generation.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\SystemAbilities;
use DataMachine\Core\Database\Chat\Chat as ChatDatabase;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\AgentType;
use WP_UnitTestCase;

class SystemAbilitiesTest extends WP_UnitTestCase {

	private SystemAbilities $system_abilities;
	private ChatDatabase $chat_db;
	private int $test_user_id;

	public function set_up(): void {
		parent::set_up();

		$this->test_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->test_user_id );

		$this->system_abilities = new SystemAbilities();
		$this->chat_db          = new ChatDatabase();
	}

	public function tear_down(): void {
		parent::tear_down();
	}

	/**
	 * Helper to create a test session with messages.
	 *
	 * @param array       $messages Messages array.
	 * @param string|null $title    Optional title to set.
	 * @return string Session ID.
	 */
	private function create_test_session( array $messages, ?string $title = null ): string {
		$session_id = $this->chat_db->create_session(
			$this->test_user_id,
			0,
			array( 'status' => 'completed' ),
			AgentType::CHAT
		);

		$this->chat_db->update_session( $session_id, $messages, array() );

		if ( $title ) {
			$this->chat_db->update_title( $session_id, $title );
		}

		return $session_id;
	}

	/**
	 * Test ability registration.
	 */
	public function test_generate_session_title_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/generate-session-title' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/generate-session-title', $ability->get_name() );
	}

	/**
	 * Test ability schema validation.
	 */
	public function test_generate_session_title_ability_schema(): void {
		$ability = wp_get_ability( 'datamachine/generate-session-title' );

		$this->assertNotNull( $ability );

		$input_schema = $ability->get_input_schema();
		$this->assertArrayHasKey( 'properties', $input_schema );
		$this->assertArrayHasKey( 'session_id', $input_schema['properties'] );
		$this->assertArrayHasKey( 'force', $input_schema['properties'] );
		$this->assertContains( 'session_id', $input_schema['required'] );

		$output_schema = $ability->get_output_schema();
		$this->assertArrayHasKey( 'properties', $output_schema );
		$this->assertArrayHasKey( 'success', $output_schema['properties'] );
		$this->assertArrayHasKey( 'title', $output_schema['properties'] );
		$this->assertArrayHasKey( 'method', $output_schema['properties'] );
	}

	/**
	 * Test that missing session returns error.
	 */
	public function test_generate_title_returns_error_for_missing_session(): void {
		$ability = wp_get_ability( 'datamachine/generate-session-title' );
		$result  = $ability->execute( array( 'session_id' => 'nonexistent-session-id' ) );

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'Session not found', $result['error'] );
	}

	/**
	 * Test that existing title is returned without force flag.
	 */
	public function test_generate_title_returns_existing_title_without_force(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, how are you?',
			),
			array(
				'role'    => 'assistant',
				'content' => 'I am doing well, thank you!',
			),
		);

		$session_id = $this->create_test_session( $messages, 'Existing Title' );

		$ability = wp_get_ability( 'datamachine/generate-session-title' );
		$result  = $ability->execute( array( 'session_id' => $session_id ) );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'Existing Title', $result['title'] );
		$this->assertEquals( 'existing', $result['method'] );
	}

	/**
	 * Test that empty messages returns error.
	 */
	public function test_generate_title_returns_error_for_empty_messages(): void {
		$session_id = $this->create_test_session( array() );

		$ability = wp_get_ability( 'datamachine/generate-session-title' );
		$result  = $ability->execute( array( 'session_id' => $session_id ) );

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'No messages found', $result['error'] );
	}

	/**
	 * Test that no user messages returns error.
	 */
	public function test_generate_title_returns_error_for_no_user_messages(): void {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful assistant.',
			),
			array(
				'role'    => 'assistant',
				'content' => 'How can I help you today?',
			),
		);

		$session_id = $this->create_test_session( $messages );

		$ability = wp_get_ability( 'datamachine/generate-session-title' );
		$result  = $ability->execute( array( 'session_id' => $session_id ) );

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'No user message found', $result['error'] );
	}

	/**
	 * Test truncated title generation when AI is disabled.
	 */
	public function test_generate_truncated_title_when_ai_disabled(): void {
		// Disable AI titles.
		update_option( 'datamachine_chat_ai_titles_enabled', false );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Help me create a pipeline for RSS feeds',
			),
			array(
				'role'    => 'assistant',
				'content' => 'I can help you with that.',
			),
		);

		$session_id = $this->create_test_session( $messages );

		$ability = wp_get_ability( 'datamachine/generate-session-title' );
		$result  = $ability->execute( array( 'session_id' => $session_id ) );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'Help me create a pipeline for RSS feeds', $result['title'] );
		$this->assertEquals( 'fallback', $result['method'] );

		// Clean up.
		delete_option( 'datamachine_chat_ai_titles_enabled' );
	}

	/**
	 * Test that truncated title respects max length.
	 */
	public function test_truncated_title_respects_max_length(): void {
		// Disable AI titles.
		update_option( 'datamachine_chat_ai_titles_enabled', false );

		$long_message = str_repeat( 'a', 150 ); // 150 character message.
		$messages     = array(
			array(
				'role'    => 'user',
				'content' => $long_message,
			),
		);

		$session_id = $this->create_test_session( $messages );

		$ability = wp_get_ability( 'datamachine/generate-session-title' );
		$result  = $ability->execute( array( 'session_id' => $session_id ) );

		$this->assertTrue( $result['success'] );
		$this->assertLessThanOrEqual( 100, mb_strlen( $result['title'] ) );
		$this->assertStringEndsWith( '...', $result['title'] );

		// Clean up.
		delete_option( 'datamachine_chat_ai_titles_enabled' );
	}

	/**
	 * Test that truncated title normalizes whitespace.
	 */
	public function test_truncated_title_normalizes_whitespace(): void {
		// Disable AI titles.
		update_option( 'datamachine_chat_ai_titles_enabled', false );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => "Help me\n\twith   multiple\n\nspaces",
			),
		);

		$session_id = $this->create_test_session( $messages );

		$ability = wp_get_ability( 'datamachine/generate-session-title' );
		$result  = $ability->execute( array( 'session_id' => $session_id ) );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'Help me with multiple spaces', $result['title'] );

		// Clean up.
		delete_option( 'datamachine_chat_ai_titles_enabled' );
	}

	/**
	 * Test that title is persisted to database.
	 */
	public function test_title_persisted_to_database(): void {
		// Disable AI titles for deterministic test.
		update_option( 'datamachine_chat_ai_titles_enabled', false );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message for title persistence',
			),
		);

		$session_id = $this->create_test_session( $messages );

		$ability = wp_get_ability( 'datamachine/generate-session-title' );
		$result  = $ability->execute( array( 'session_id' => $session_id ) );

		$this->assertTrue( $result['success'] );

		// Query database directly to verify persistence.
		$session = $this->chat_db->get_session( $session_id );

		$this->assertNotNull( $session );
		$this->assertEquals( 'Test message for title persistence', $session['title'] );

		// Clean up.
		delete_option( 'datamachine_chat_ai_titles_enabled' );
	}

	/**
	 * Test that force flag regenerates existing title.
	 */
	public function test_force_flag_regenerates_existing_title(): void {
		// Disable AI titles for deterministic test.
		update_option( 'datamachine_chat_ai_titles_enabled', false );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'New message content',
			),
		);

		$session_id = $this->create_test_session( $messages, 'Old Title' );

		// Verify old title exists.
		$session = $this->chat_db->get_session( $session_id );
		$this->assertEquals( 'Old Title', $session['title'] );

		// Execute with force flag.
		$ability = wp_get_ability( 'datamachine/generate-session-title' );
		$result  = $ability->execute(
			array(
				'session_id' => $session_id,
				'force'      => true,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'New message content', $result['title'] );
		$this->assertNotEquals( 'Old Title', $result['title'] );

		// Verify database was updated.
		$session = $this->chat_db->get_session( $session_id );
		$this->assertEquals( 'New message content', $session['title'] );

		// Clean up.
		delete_option( 'datamachine_chat_ai_titles_enabled' );
	}
}
