<?php
/**
 * Tests for ImageGenerationAbilities execute method.
 *
 * @package DataMachine\Tests\Unit\Abilities\Media
 */

namespace DataMachine\Tests\Unit\Abilities\Media;

use DataMachine\Abilities\Media\ImageGenerationAbilities;
use DataMachine\Tests\Unit\Support\WpAiClientTestDouble;
use WP_UnitTestCase;

require_once dirname( dirname( __DIR__ ) ) . '/Support/WpAiClientTestDoubles.php';

class ImageGenerationAbilitiesTest extends WP_UnitTestCase {

	private ImageGenerationAbilities $abilities;

	public function set_up(): void {
		parent::set_up();

		datamachine_register_capabilities();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		WpAiClientTestDouble::reset();
		WpAiClientTestDouble::set_response_callback( array( $this, 'mock_image_response' ) );

		$this->abilities = new ImageGenerationAbilities();
	}

	public function tear_down(): void {
		delete_site_option( 'datamachine_image_generation_config' );
		WpAiClientTestDouble::reset();
		parent::tear_down();
	}

	public function mock_image_response( array $request ): array {
		return array(
			'success' => true,
			'data'    => array(
				'image_url' => 'https://example.com/generated.png',
			),
		);
	}

	public function test_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/generate-image' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/generate-image', $ability->get_name() );
	}

	public function test_generate_image_missing_prompt(): void {
		$result = ImageGenerationAbilities::generateImage( array() );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'requires a prompt', $result['error'] );
	}

	public function test_generate_image_empty_prompt(): void {
		$result = ImageGenerationAbilities::generateImage( array( 'prompt' => '' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'requires a prompt', $result['error'] );
	}

	public function test_generate_image_missing_provider(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'default_provider' => '', 'default_model' => 'gpt-image-1' ) );

		$result = ImageGenerationAbilities::generateImage( array( 'prompt' => 'Test prompt' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not configured', $result['error'] );
	}

	public function test_generate_image_unregistered_provider(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'default_provider' => 'missing-provider', 'default_model' => 'image-model' ) );

		$result = ImageGenerationAbilities::generateImage( array( 'prompt' => 'Test prompt' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not registered', $result['error'] );
	}

	public function test_generate_image_support_check_failure(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'default_provider' => 'openai', 'default_model' => 'text-only-model' ) );
		WpAiClientTestDouble::set_response_callback( function (): array {
			return array( 'success' => true, 'supported' => false );
		} );

		$result = ImageGenerationAbilities::generateImage( array( 'prompt' => 'Test prompt' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'does not support image generation', $result['error'] );
	}

	public function test_generate_image_provider_error(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'default_provider' => 'openai', 'default_model' => 'gpt-image-1' ) );
		WpAiClientTestDouble::set_response_callback( function (): array {
			return array( 'success' => false, 'error' => 'Provider unavailable' );
		} );

		$result = ImageGenerationAbilities::generateImage( array( 'prompt' => 'Test prompt' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Failed to generate image', $result['error'] );
		$this->assertStringContainsString( 'Provider unavailable', $result['error'] );
	}

	public function test_generate_image_success_schedules_job(): void {
		update_site_option( 'datamachine_image_generation_config', array(
			'default_provider'     => 'openai',
			'default_model'        => 'gpt-image-1',
			'default_aspect_ratio' => '3:4',
		) );

		$captured_request = null;
		WpAiClientTestDouble::set_response_callback( function ( array $request ) use ( &$captured_request ): array {
			$captured_request = $request;
			return array(
				'success' => true,
				'data'    => array( 'image_url' => 'https://example.com/generated.png' ),
			);
		} );

		$result = ImageGenerationAbilities::generateImage( array( 'prompt' => 'Test prompt' ) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['pending'] );
		$this->assertIsInt( $result['job_id'] );
		$this->assertSame( 'https://example.com/generated.png', $result['image_url'] );
		$this->assertSame( 'Test prompt', $captured_request['prompt'] ?? '' );
		$this->assertSame( 'openai', $captured_request['provider'] ?? '' );
		$this->assertSame( 'gpt-image-1', $captured_request['model'] ?? '' );
	}

	public function test_is_configured_false_when_provider_unavailable(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'default_provider' => 'missing-provider', 'default_model' => 'gpt-image-1' ) );
		$this->assertFalse( ImageGenerationAbilities::is_configured() );
	}

	public function test_is_configured_true_when_provider_and_model_available(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'default_provider' => 'openai', 'default_model' => 'gpt-image-1' ) );
		$this->assertTrue( ImageGenerationAbilities::is_configured() );
	}

	public function test_get_config_empty(): void {
		delete_site_option( 'datamachine_image_generation_config' );
		$this->assertSame( array(), ImageGenerationAbilities::get_config() );
	}

	public function test_get_config_returns_stored(): void {
		$config = array( 'default_provider' => 'openai', 'default_model' => 'custom-model' );
		update_site_option( 'datamachine_image_generation_config', $config );
		$this->assertSame( $config, ImageGenerationAbilities::get_config() );
	}
}
