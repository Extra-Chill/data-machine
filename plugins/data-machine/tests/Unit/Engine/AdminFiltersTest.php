<?php
/**
 * AdminFilters Tests
 *
 * Tests for admin filter functions in Engine/Filters/Admin.php.
 *
 * @package DataMachine\Tests\Unit\Engine
 */

namespace DataMachine\Tests\Unit\Engine;

use WP_UnitTestCase;

class AdminFiltersTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	public function tear_down(): void {
		parent::tear_down();

		// Reset scripts
		$wp_scripts = wp_scripts();
		$wp_scripts->registered = array();
		$wp_scripts->queue = array();
	}

	/**
	 * Test that inline script uses window. prefix for global config.
	 *
	 * Ensures localized data is attached to window object, not declared as const.
	 * React code expects window.dataMachineConfig to exist.
	 */
	public function test_inline_script_uses_window_prefix(): void {
		$assets = array(
			'js' => array(
				'test-handle' => array(
					'file'     => 'inc/Core/Admin/Pages/dist/pipelines.js',
					'deps'     => array(),
					'localize' => array(
						'object' => 'dataMachineConfig',
						'data'   => array(
							'restNamespace' => 'datamachine/v1',
							'restNonce'     => 'test-nonce',
						),
					),
				),
			),
		);

		datamachine_enqueue_page_assets( $assets);

		$wp_scripts = wp_scripts();
		$registered = $wp_scripts->registered;

		$this->assertArrayHasKey( 'test-handle', $registered );

		// Get the inline script data
		$script = $registered['test-handle'];
		$inline_data = $script->extra['before'] ?? array();

		$this->assertNotEmpty( $inline_data, 'Inline script should be registered' );

		$inline_script = implode( "\n", $inline_data );

		// Verify window. prefix is used (not const)
		$this->assertStringContainsString(
			'window.dataMachineConfig',
			$inline_script,
			'Inline script must use window. prefix for global config'
		);

		// Verify const is NOT used
		$this->assertStringNotContainsString(
			'const dataMachineConfig',
			$inline_script,
			'Inline script must NOT use const declaration (not accessible on window object)'
		);
	}

	/**
	 * Test that localized data is properly JSON encoded.
	 */
	public function test_inline_script_json_encodes_data(): void {
		$assets = array(
			'js' => array(
				'test-handle-2' => array(
					'file'     => 'inc/Core/Admin/Pages/dist/pipelines.js',
					'deps'     => array(),
					'localize' => array(
						'object' => 'testConfig',
						'data'   => array(
							'key'     => 'value',
							'nested'  => array( 'item' => 'data' ),
						),
					),
				),
			),
		);

		datamachine_enqueue_page_assets( $assets);

		$wp_scripts = wp_scripts();
		$script = $wp_scripts->registered['test-handle-2'];
		$inline_data = $script->extra['before'] ?? array();
		$inline_script = implode( "\n", $inline_data );

		// Verify JSON structure is present
		$this->assertStringContainsString( '"key":"value"', $inline_script );
		$this->assertStringContainsString( '"nested":', $inline_script );
	}

	/**
	 * Test that scripts without localize config don't get inline scripts.
	 */
	public function test_no_inline_script_without_localize_config(): void {
		$assets = array(
			'js' => array(
				'test-handle-3' => array(
					'file' => 'inc/Core/Admin/Pages/dist/pipelines.js',
					'deps' => array(),
				),
			),
		);

		datamachine_enqueue_page_assets( $assets);

		$wp_scripts = wp_scripts();
		$script = $wp_scripts->registered['test-handle-3'];
		$inline_data = $script->extra['before'] ?? array();

		$this->assertEmpty( $inline_data, 'No inline script should be added without localize config' );
	}
}
