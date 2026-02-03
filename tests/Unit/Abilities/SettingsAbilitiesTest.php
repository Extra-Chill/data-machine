<?php
/**
 * SettingsAbilities Tests
 *
 * Tests for settings-related abilities.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\SettingsAbilities;
use WP_UnitTestCase;

class SettingsAbilitiesTest extends WP_UnitTestCase {

	private SettingsAbilities $settings_abilities;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->settings_abilities = new SettingsAbilities();
	}

	public function tear_down(): void {
		parent::tear_down();
	}

	public function test_get_settings_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/get-settings' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/get-settings', $ability->get_name() );
	}

	public function test_update_settings_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/update-settings' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/update-settings', $ability->get_name() );
	}

	public function test_get_scheduling_intervals_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/get-scheduling-intervals' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/get-scheduling-intervals', $ability->get_name() );
	}

	public function test_get_tool_config_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/get-tool-config' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/get-tool-config', $ability->get_name() );
	}

	public function test_get_handler_defaults_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/get-handler-defaults' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/get-handler-defaults', $ability->get_name() );
	}

	public function test_update_handler_defaults_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/update-handler-defaults' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/update-handler-defaults', $ability->get_name() );
	}

	public function test_get_settings_returns_settings_and_tools(): void {
		$result = $this->settings_abilities->executeGetSettings( array() );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'settings', $result );
		$this->assertArrayHasKey( 'global_tools', $result );
		$this->assertIsArray( $result['settings'] );
		$this->assertIsArray( $result['global_tools'] );
	}

	public function test_get_settings_contains_expected_keys(): void {
		$result = $this->settings_abilities->executeGetSettings( array() );

		$this->assertTrue( $result['success'] );

		$settings = $result['settings'];
		$this->assertArrayHasKey( 'cleanup_job_data_on_failure', $settings );
		$this->assertArrayHasKey( 'file_retention_days', $settings );
		$this->assertArrayHasKey( 'chat_retention_days', $settings );
		$this->assertArrayHasKey( 'chat_ai_titles_enabled', $settings );
		$this->assertArrayHasKey( 'alt_text_auto_generate_enabled', $settings );
		$this->assertArrayHasKey( 'problem_flow_threshold', $settings );
		$this->assertArrayHasKey( 'flows_per_page', $settings );
		$this->assertArrayHasKey( 'jobs_per_page', $settings );
		$this->assertArrayHasKey( 'global_system_prompt', $settings );
		$this->assertArrayHasKey( 'site_context_enabled', $settings );
		$this->assertArrayHasKey( 'default_provider', $settings );
		$this->assertArrayHasKey( 'default_model', $settings );
		$this->assertArrayHasKey( 'max_turns', $settings );
		$this->assertArrayHasKey( 'enabled_tools', $settings );
		$this->assertArrayHasKey( 'ai_provider_keys', $settings );
	}

	public function test_update_settings_updates_boolean_setting(): void {
		$result = $this->settings_abilities->executeUpdateSettings(
			array( 'cleanup_job_data_on_failure' => false )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'message', $result );

		$updated_settings = get_option( 'datamachine_settings', array() );
		$this->assertFalse( $updated_settings['cleanup_job_data_on_failure'] );
	}

	public function test_update_settings_updates_alt_text_auto_generate_enabled(): void {
		$result = $this->settings_abilities->executeUpdateSettings(
			array( 'alt_text_auto_generate_enabled' => false )
		);

		$this->assertTrue( $result['success'] );

		$updated_settings = get_option( 'datamachine_settings', array() );
		$this->assertFalse( $updated_settings['alt_text_auto_generate_enabled'] );

		// Re-enable and verify.
		$result = $this->settings_abilities->executeUpdateSettings(
			array( 'alt_text_auto_generate_enabled' => true )
		);

		$this->assertTrue( $result['success'] );

		$updated_settings = get_option( 'datamachine_settings', array() );
		$this->assertTrue( $updated_settings['alt_text_auto_generate_enabled'] );
	}

	public function test_update_settings_updates_integer_setting(): void {
		$result = $this->settings_abilities->executeUpdateSettings(
			array( 'file_retention_days' => 14 )
		);

		$this->assertTrue( $result['success'] );

		$updated_settings = get_option( 'datamachine_settings', array() );
		$this->assertEquals( 14, $updated_settings['file_retention_days'] );
	}

	public function test_update_settings_clamps_integer_values(): void {
		$result = $this->settings_abilities->executeUpdateSettings(
			array( 'file_retention_days' => 999 )
		);

		$this->assertTrue( $result['success'] );

		$updated_settings = get_option( 'datamachine_settings', array() );
		$this->assertEquals( 90, $updated_settings['file_retention_days'] );
	}

	public function test_update_settings_updates_string_setting(): void {
		$result = $this->settings_abilities->executeUpdateSettings(
			array( 'global_system_prompt' => 'Test prompt content' )
		);

		$this->assertTrue( $result['success'] );

		$updated_settings = get_option( 'datamachine_settings', array() );
		$this->assertEquals( 'Test prompt content', $updated_settings['global_system_prompt'] );
	}

	public function test_get_scheduling_intervals_returns_intervals(): void {
		$result = $this->settings_abilities->executeGetSchedulingIntervals( array() );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'intervals', $result );
		$this->assertIsArray( $result['intervals'] );

		$intervals = $result['intervals'];
		$this->assertGreaterThan( 0, count( $intervals ) );

		$first_interval = $intervals[0];
		$this->assertArrayHasKey( 'value', $first_interval );
		$this->assertArrayHasKey( 'label', $first_interval );
		$this->assertEquals( 'manual', $first_interval['value'] );
	}

	public function test_get_tool_config_returns_error_for_missing_tool_id(): void {
		$result = $this->settings_abilities->executeGetToolConfig(
			array( 'tool_id' => '' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'required', $result['error'] );
	}

	public function test_get_tool_config_returns_error_for_unknown_tool(): void {
		$result = $this->settings_abilities->executeGetToolConfig(
			array( 'tool_id' => 'nonexistent_tool' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'Unknown tool', $result['error'] );
	}

	public function test_get_handler_defaults_returns_grouped_defaults(): void {
		$result = $this->settings_abilities->executeGetHandlerDefaults( array() );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'defaults', $result );
		$this->assertIsArray( $result['defaults'] );
	}

	public function test_update_handler_defaults_returns_error_for_missing_handler_slug(): void {
		$result = $this->settings_abilities->executeUpdateHandlerDefaults(
			array(
				'handler_slug' => '',
				'defaults'     => array( 'key' => 'value' ),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'required', $result['error'] );
	}

	public function test_update_handler_defaults_returns_error_for_unknown_handler(): void {
		$result = $this->settings_abilities->executeUpdateHandlerDefaults(
			array(
				'handler_slug' => 'nonexistent_handler',
				'defaults'     => array( 'key' => 'value' ),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_permission_callback(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'datamachine/get-settings' );
		$this->assertNotNull( $ability );

		$result = $ability->execute( array() );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] ?? true );
		$this->assertArrayHasKey( 'error', $result );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}
}
