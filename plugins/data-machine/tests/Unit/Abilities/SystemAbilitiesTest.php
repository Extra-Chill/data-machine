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
use DataMachine\Core\Workspace\WordPressWorkspaceScope;
use WP_UnitTestCase;

class SystemAbilitiesTest extends WP_UnitTestCase {

	private SystemAbilities $system_abilities;
	private ChatDatabase $chat_db;
	private int $test_user_id;

	public function set_up(): void {
		parent::set_up();

		$this->test_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->test_user_id );

		// Ensure the chat_sessions table exists
		ChatDatabase::create_table();

		$this->system_abilities = new SystemAbilities();
		$this->chat_db          = new ChatDatabase();
	}

	public function tear_down(): void {
		PluginSettings::clearCache();
		parent::tear_down();
	}

	public function test_health_check_preserves_extension_wp_error_details(): void {
		add_filter(
			'datamachine_system_health_checks',
			static function ( array $checks ): array {
				$checks['extension-test'] = array(
					'label'    => 'Extension Test',
					'callback' => static fn(): \WP_Error => new \WP_Error(
						'extension_unavailable',
						'Extension service is unavailable.',
						array(
							'status'      => 503,
							'diagnostics' => array( 'provider' => 'test-provider' ),
						)
					),
					'default'  => false,
				);
				return $checks;
			}
		);

		$result = $this->system_abilities->executeHealthCheck( array( 'types' => array( 'extension-test' ) ) );
		$health = $result['results']['extension-test']['result'];

		$this->assertFalse( $health['success'] );
		$this->assertSame( 'extension-test', $health['check_type'] );
		$this->assertSame( 'extension_unavailable', $health['error_code'] );
		$this->assertSame( 503, $health['status'] );
		$this->assertSame( array( 'provider' => 'test-provider' ), $health['diagnostics'] );
	}

	public function test_health_check_catches_extension_throwable(): void {
		add_filter(
			'datamachine_system_health_checks',
			static function ( array $checks ): array {
				$checks['throwing-extension'] = array(
					'label'    => 'Throwing Extension',
					'callback' => static fn() => throw new \RuntimeException( 'Extension callback exploded.' ),
					'default'  => false,
				);
				return $checks;
			}
		);

		$result = $this->system_abilities->executeHealthCheck( array( 'types' => array( 'throwing-extension' ) ) );
		$health = $result['results']['throwing-extension']['result'];

		$this->assertFalse( $health['success'] );
		$this->assertSame( 'throwing-extension', $health['check_type'] );
		$this->assertSame( 'health_check_callback_exception', $health['error_code'] );
		$this->assertSame( 500, $health['status'] );
		$this->assertSame( \RuntimeException::class, $health['diagnostics']['exception'] );
		$this->assertStringContainsString( 'Throwing Extension: error', $result['summary'] );
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
			WordPressWorkspaceScope::current(),
			$this->test_user_id,
			0,
			array( 'status' => 'completed' ),
			'chat'
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

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'session_not_found', $result->get_error_code() );
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

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'session_messages_missing', $result->get_error_code() );
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

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'session_user_message_missing', $result->get_error_code() );
	}

	/**
	 * Test truncated title generation when AI is disabled.
	 */
	public function test_generate_truncated_title_when_ai_disabled(): void {
		// Disable AI titles.
		$settings = get_option( 'datamachine_settings', array() );
		$settings['chat_ai_titles_enabled'] = false;
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();

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
		$settings = get_option( 'datamachine_settings', array() );
		unset( $settings['chat_ai_titles_enabled'] );
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();
	}

	/**
	 * Test that truncated title respects max length.
	 */
	public function test_truncated_title_respects_max_length(): void {
		// Disable AI titles.
		$settings = get_option( 'datamachine_settings', array() );
		$settings['chat_ai_titles_enabled'] = false;
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();

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
		$settings = get_option( 'datamachine_settings', array() );
		unset( $settings['chat_ai_titles_enabled'] );
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();
	}

	/**
	 * Test that truncated title normalizes whitespace.
	 */
	public function test_truncated_title_normalizes_whitespace(): void {
		// Disable AI titles.
		$settings = get_option( 'datamachine_settings', array() );
		$settings['chat_ai_titles_enabled'] = false;
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();

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
		$settings = get_option( 'datamachine_settings', array() );
		unset( $settings['chat_ai_titles_enabled'] );
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();
	}

	/**
	 * Test that title is persisted to database.
	 */
	public function test_title_persisted_to_database(): void {
		// Disable AI titles for deterministic test.
		$settings = get_option( 'datamachine_settings', array() );
		$settings['chat_ai_titles_enabled'] = false;
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();

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
		$settings = get_option( 'datamachine_settings', array() );
		unset( $settings['chat_ai_titles_enabled'] );
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();
	}

	/**
	 * Test that force flag regenerates existing title.
	 */
	public function test_force_flag_regenerates_existing_title(): void {
		// Disable AI titles for deterministic test.
		$settings = get_option( 'datamachine_settings', array() );
		$settings['chat_ai_titles_enabled'] = false;
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();

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
		$settings = get_option( 'datamachine_settings', array() );
		unset( $settings['chat_ai_titles_enabled'] );
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();
	}
}
