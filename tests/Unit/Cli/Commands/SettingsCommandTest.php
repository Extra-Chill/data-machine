<?php
/**
 * Settings Command Tests
 *
 * Tests the settings operations that the CLI wraps, using the Abilities API
 * directly. The CLI layer (SettingsCommand) calls WP_CLI::error() which
 * invokes exit() — that kills the PHPUnit process in the test environment.
 * These tests verify the underlying ability and type-coercion behavior instead.
 *
 * @package DataMachine\Tests\Unit\Cli\Commands
 */

namespace DataMachine\Tests\Unit\Cli\Commands;

use WP_UnitTestCase;

class SettingsCommandTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	public function test_settings_command_class_exists(): void {
		$this->assertTrue(
			class_exists( \DataMachine\Cli\Commands\SettingsCommand::class ),
			'SettingsCommand class should be autoloadable'
		);
	}

	public function test_update_setting_with_invalid_value_returns_error(): void {
		$ability = wp_get_ability( 'datamachine/update-settings' );
		$this->assertNotNull( $ability, 'update-settings ability should be registered' );

		// max_turns expects an integer; passing a non-numeric string triggers schema validation.
		$result = $ability->execute( array( 'max_turns' => 'not-an-integer' ) );

		// Schema validation returns WP_Error for type mismatches — verify it doesn't fatal.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'max_turns', $result->get_error_message() );
	}

	public function test_update_disabled_tools_with_map(): void {
		$ability = wp_get_ability( 'datamachine/update-settings' );
		$this->assertNotNull( $ability );

		// The CLI command converts "tool-a,tool-b" to this map format before calling the ability.
		// Test the ability accepts the final map format directly.
		$result = $ability->execute(
			array(
				'disabled_tools' => array(
					'example-tool-a' => true,
					'example-tool-b' => true,
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] ?? false );

		$settings = get_option( 'datamachine_settings', array() );
		$this->assertArrayHasKey( 'disabled_tools', $settings );
		$this->assertSame(
			array( 'example-tool-a' => true, 'example-tool-b' => true ),
			$settings['disabled_tools']
		);
	}

	public function test_get_settings_returns_settings(): void {
		$ability = wp_get_ability( 'datamachine/get-settings' );
		$this->assertNotNull( $ability );

		$result = $ability->execute( array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] ?? false );
		$this->assertArrayHasKey( 'settings', $result );
	}
}
