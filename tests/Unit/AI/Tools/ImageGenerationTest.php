<?php
/**
 * Tests for the ImageGeneration global tool.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Engine\AI\Tools\Global\ImageGeneration;
use WP_UnitTestCase;

class ImageGenerationTest extends WP_UnitTestCase {

	private ImageGeneration $tool;

	public function set_up(): void {
		parent::set_up();
		$this->tool = new ImageGeneration();
	}

	public function tear_down(): void {
		delete_site_option( 'datamachine_image_generation_config' );
		parent::tear_down();
	}

	public function test_is_configured_returns_false_when_no_config(): void {
		delete_site_option( 'datamachine_image_generation_config' );
		$this->assertFalse( ImageGeneration::is_configured() );
	}

	public function test_is_configured_returns_true_when_api_key_set(): void {
		update_site_option( 'datamachine_image_generation_config', [ 'api_key' => 'test-key' ] );
		$this->assertTrue( ImageGeneration::is_configured() );
	}

	public function test_get_config_returns_empty_array_by_default(): void {
		delete_site_option( 'datamachine_image_generation_config' );
		$this->assertSame( [], ImageGeneration::get_config() );
	}

	public function test_get_config_returns_stored_config(): void {
		$config = [ 'api_key' => 'test-key', 'default_model' => 'flux' ];
		update_site_option( 'datamachine_image_generation_config', $config );
		$this->assertSame( $config, ImageGeneration::get_config() );
	}

	public function test_check_configuration_passthrough_for_wrong_tool_id(): void {
		$this->assertFalse( $this->tool->check_configuration( false, 'google_search' ) );
		$this->assertTrue( $this->tool->check_configuration( true, 'google_search' ) );
	}

	public function test_check_configuration_returns_status_for_image_generation(): void {
		delete_site_option( 'datamachine_image_generation_config' );
		$this->assertFalse( $this->tool->check_configuration( true, 'image_generation' ) );

		update_site_option( 'datamachine_image_generation_config', [ 'api_key' => 'test-key' ] );
		$this->assertTrue( $this->tool->check_configuration( false, 'image_generation' ) );
	}

	public function test_get_configuration_passthrough_for_wrong_tool_id(): void {
		$existing = [ 'some' => 'config' ];
		$this->assertSame( $existing, $this->tool->get_configuration( $existing, 'google_search' ) );
	}

	public function test_get_configuration_returns_config_for_image_generation(): void {
		$config = [ 'api_key' => 'test-key' ];
		update_site_option( 'datamachine_image_generation_config', $config );
		$this->assertSame( $config, $this->tool->get_configuration( [], 'image_generation' ) );
	}

	public function test_get_config_fields_returns_fields_for_image_generation(): void {
		$fields = $this->tool->get_config_fields( [], 'image_generation' );
		$this->assertArrayHasKey( 'api_key', $fields );
		$this->assertArrayHasKey( 'default_model', $fields );
		$this->assertArrayHasKey( 'default_aspect_ratio', $fields );
	}

	public function test_get_config_fields_passthrough_for_wrong_tool_id(): void {
		$existing = [ 'foo' => 'bar' ];
		$this->assertSame( $existing, $this->tool->get_config_fields( $existing, 'google_search' ) );
	}

	public function test_get_config_fields_returns_fields_when_tool_id_empty(): void {
		$fields = $this->tool->get_config_fields( [], '' );
		$this->assertArrayHasKey( 'api_key', $fields );
	}

	public function test_get_tool_definition_has_required_keys(): void {
		$def = $this->tool->getToolDefinition();
		$this->assertArrayHasKey( 'class', $def );
		$this->assertArrayHasKey( 'method', $def );
		$this->assertArrayHasKey( 'description', $def );
		$this->assertArrayHasKey( 'requires_config', $def );
		$this->assertArrayHasKey( 'parameters', $def );
		$this->assertTrue( $def['requires_config'] );
		$this->assertSame( 'handle_tool_call', $def['method'] );
	}

	public function test_handle_tool_call_error_when_prompt_empty(): void {
		$result = $this->tool->handle_tool_call( [] );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'prompt', $result['error'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );
	}

	public function test_handle_tool_call_error_when_not_configured(): void {
		delete_site_option( 'datamachine_image_generation_config' );
		$result = $this->tool->handle_tool_call( [ 'prompt' => 'A sunset' ] );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not configured', $result['error'] );
	}

	public function test_tool_registers_as_global_tool(): void {
		$tools = apply_filters( 'datamachine_global_tools', [] );
		$this->assertArrayHasKey( 'image_generation', $tools );
	}

	public function test_config_option_key(): void {
		$this->assertSame( 'datamachine_image_generation_config', ImageGeneration::CONFIG_OPTION );
	}
}
