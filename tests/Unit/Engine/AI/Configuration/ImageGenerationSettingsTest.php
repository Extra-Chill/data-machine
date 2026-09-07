<?php
/**
 * Tests for the image generation settings adapter.
 *
 * @package DataMachine\Tests\Unit\Engine\AI\Configuration
 */

namespace DataMachine\Tests\Unit\Engine\AI\Configuration;

use DataMachine\Abilities\Media\ImageGenerationAbilities;
use DataMachine\Engine\AI\Configuration\ImageGenerationSettings;
use DataMachine\Tests\Unit\Support\WpAiClientTestDouble;
use WP_UnitTestCase;

require_once dirname( __DIR__, 3 ) . '/Support/WpAiClientTestDoubles.php';

class ImageGenerationSettingsTest extends WP_UnitTestCase {

	private ImageGenerationSettings $settings;

	public function set_up(): void {
		parent::set_up();

		// Ability execute() requires manage_options capability.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->settings = new ImageGenerationSettings();
		WpAiClientTestDouble::reset();
		WpAiClientTestDouble::set_response_callback( fn(): array => array( 'success' => true ) );
	}

	public function tear_down(): void {
		delete_site_option( ImageGenerationAbilities::CONFIG_OPTION );
		WpAiClientTestDouble::reset();
		parent::tear_down();
	}

	public function test_is_configured_returns_false_when_no_config(): void {
		delete_site_option( ImageGenerationAbilities::CONFIG_OPTION );
		$this->assertFalse( ImageGenerationSettings::is_configured() );
	}

	public function test_is_configured_returns_true_when_provider_and_model_set(): void {
		update_site_option( ImageGenerationAbilities::CONFIG_OPTION, [ 'default_provider' => 'openai', 'default_model' => 'gpt-image-1' ] );
		$this->assertTrue( ImageGenerationSettings::is_configured() );
	}

	public function test_get_config_returns_empty_array_by_default(): void {
		delete_site_option( ImageGenerationAbilities::CONFIG_OPTION );
		$this->assertSame( [], ImageGenerationSettings::get_config() );
	}

	public function test_get_config_returns_stored_config(): void {
		$config = [ 'default_provider' => 'openai', 'default_model' => 'gpt-image-1' ];
		update_site_option( ImageGenerationAbilities::CONFIG_OPTION, $config );
		$this->assertSame( $config, ImageGenerationSettings::get_config() );
	}

	public function test_check_configuration_passthrough_for_wrong_tool_id(): void {
		$this->assertFalse( $this->settings->check_configuration( false, 'google_search' ) );
		$this->assertTrue( $this->settings->check_configuration( true, 'google_search' ) );
	}

	public function test_check_configuration_returns_status_for_image_generation(): void {
		delete_site_option( ImageGenerationAbilities::CONFIG_OPTION );
		$this->assertFalse( $this->settings->check_configuration( true, 'image_generation' ) );

		update_site_option( ImageGenerationAbilities::CONFIG_OPTION, [ 'default_provider' => 'openai', 'default_model' => 'gpt-image-1' ] );
		$this->assertTrue( $this->settings->check_configuration( false, 'image_generation' ) );
	}

	public function test_get_configuration_passthrough_for_wrong_tool_id(): void {
		$existing = [ 'some' => 'config' ];
		$this->assertSame( $existing, $this->settings->get_configuration( $existing, 'google_search' ) );
	}

	public function test_get_configuration_returns_config_for_image_generation(): void {
		$config = [ 'default_provider' => 'openai', 'default_model' => 'gpt-image-1' ];
		update_site_option( ImageGenerationAbilities::CONFIG_OPTION, $config );
		$this->assertSame( $config, $this->settings->get_configuration( [], 'image_generation' ) );
	}

	public function test_get_config_fields_returns_fields_for_image_generation(): void {
		$fields = $this->settings->get_config_fields( [], 'image_generation' );
		$this->assertArrayHasKey( 'default_provider', $fields );
		$this->assertArrayHasKey( 'default_model', $fields );
		$this->assertArrayHasKey( 'default_aspect_ratio', $fields );
	}

	public function test_get_config_fields_passthrough_for_wrong_tool_id(): void {
		$existing = [ 'foo' => 'bar' ];
		$this->assertSame( $existing, $this->settings->get_config_fields( $existing, 'google_search' ) );
	}

	public function test_get_config_fields_returns_fields_when_tool_id_empty(): void {
		$fields = $this->settings->get_config_fields( [], '' );
		$this->assertArrayHasKey( 'default_provider', $fields );
	}

	public function test_constructor_registers_all_settings_filters(): void {
		$this->assertSame( 10, has_filter( 'datamachine_tool_configured', array( $this->settings, 'check_configuration' ) ) );
		$this->assertSame( 10, has_filter( 'datamachine_get_tool_config', array( $this->settings, 'get_configuration' ) ) );
		$this->assertSame( 10, has_filter( 'datamachine_get_tool_config_fields', array( $this->settings, 'get_config_fields' ) ) );
		$this->assertSame( 10, has_filter( 'datamachine_save_tool_config', array( $this->settings, 'save_configuration' ) ) );
	}

	public function test_ability_projection_has_required_keys(): void {
		$tools = apply_filters( 'datamachine_ability_tool_projections', [] );
		$def   = $tools['image_generation'];
		$this->assertArrayHasKey( 'description', $def );
		$this->assertArrayHasKey( 'requires_config', $def );
		$this->assertArrayHasKey( 'parameters', $def );
		$this->assertTrue( $def['requires_config'] );
	}

	public function test_tool_registers_ability_projection(): void {
		$tools = apply_filters( 'datamachine_ability_tool_projections', [] );
		$this->assertArrayHasKey( 'image_generation', $tools );
		$this->assertSame( 'datamachine/generate-image', $tools['image_generation']['ability'] );
		$this->assertSame( array( 'chat', 'pipeline' ), $tools['image_generation']['modes'] );
	}

	public function test_config_option_key(): void {
		$method = new \ReflectionMethod( ImageGenerationSettings::class, 'get_config_option_name' );

		$this->assertSame( ImageGenerationAbilities::CONFIG_OPTION, $method->invoke( $this->settings ) );
	}
}
