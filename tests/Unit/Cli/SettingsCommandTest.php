<?php
/**
 * Settings Command Tests
 *
 * @package DataMachine\Tests\Unit\Cli
 */

namespace DataMachine\Tests\Unit\Cli;

use DataMachine\Abilities\SettingsAbilities;
use DataMachine\Cli\Commands\SettingsCommand;
use WP_UnitTestCase;

class SettingsCommandTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		new SettingsAbilities();
	}

	public function test_set_handles_wp_error_without_fatal(): void {
		ob_start();

		$command = new SettingsCommand();
		$command->set( [ 'max_turns', 'not-an-integer' ], [] );

		$output = ob_get_clean();

		$this->assertStringContainsString( 'Error:', $output );
	}

	public function test_set_parses_enabled_tools_comma_list(): void {
		ob_start();

		$command = new SettingsCommand();
		$command->set( [ 'enabled_tools', 'example-tool-a,example-tool-b' ], [] );

		$output = ob_get_clean();

		$this->assertStringContainsString( 'Success:', $output );

		$settings = get_option( 'datamachine_settings', [] );
		$this->assertArrayHasKey( 'enabled_tools', $settings );
		$this->assertSame(
			[ 'example-tool-a' => true, 'example-tool-b' => true ],
			$settings['enabled_tools']
		);
	}
}
