<?php
/**
 * Tests for ImageGenerationAbilities execute method.
 *
 * @package DataMachine\Tests\Unit\Abilities\Media
 */

namespace DataMachine\Tests\Unit\Abilities\Media;

use DataMachine\Abilities\Media\ImageGenerationAbilities;
use WP_UnitTestCase;

class ImageGenerationAbilitiesTest extends WP_UnitTestCase {

	private ImageGenerationAbilities $abilities;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$this->abilities = new ImageGenerationAbilities();
	}

	public function tear_down(): void {
		delete_site_option( 'datamachine_image_generation_config' );
		parent::tear_down();
	}

	/**
	 * Test ability registration.
	 */
	public function test_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/generate-image' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/generate-image', $ability->get_name() );
	}

	/**
	 * Test generateImage with missing prompt.
	 */
	public function test_generate_image_missing_prompt(): void {
		$result = ImageGenerationAbilities::generateImage( [] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'requires a prompt', $result['error'] );
	}

	/**
	 * Test generateImage with empty prompt.
	 */
	public function test_generate_image_empty_prompt(): void {
		$result = ImageGenerationAbilities::generateImage( [ 'prompt' => '' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'requires a prompt', $result['error'] );
	}

	/**
	 * Test generateImage with missing config.
	 */
	public function test_generate_image_missing_config(): void {
		delete_site_option( 'datamachine_image_generation_config' );

		$result = ImageGenerationAbilities::generateImage( [ 'prompt' => 'Test prompt' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not configured', $result['error'] );
	}

	/**
	 * Test generateImage with missing API key in config.
	 */
	public function test_generate_image_missing_api_key(): void {
		update_site_option( 'datamachine_image_generation_config', [
			'default_model' => 'google/imagen-4-fast'
		] );

		$result = ImageGenerationAbilities::generateImage( [ 'prompt' => 'Test prompt' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not configured', $result['error'] );
	}

	/**
	 * Test generateImage with HTTP error.
	 */
	public function test_generate_image_http_error(): void {
		update_site_option( 'datamachine_image_generation_config', [
			'api_key' => 'test-key'
		] );

		$filter = function( $result, $parsed_args, $url ) {
			if ( str_contains( $url, 'replicate.com' ) ) {
				return new \WP_Error( 'http_request_failed', 'Network timeout' );
			}
			return $result;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = ImageGenerationAbilities::generateImage( [ 'prompt' => 'Test prompt' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Failed to start image generation', $result['error'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test generateImage with invalid JSON response.
	 */
	public function test_generate_image_invalid_json(): void {
		update_site_option( 'datamachine_image_generation_config', [
			'api_key' => 'test-key'
		] );

		$filter = function( $result, $parsed_args, $url ) {
			if ( str_contains( $url, 'replicate.com' ) ) {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => 'invalid json response',
					'headers'  => array(),
					'cookies'  => array(),
				);
			}
			return $result;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = ImageGenerationAbilities::generateImage( [ 'prompt' => 'Test prompt' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid response from Replicate API', $result['error'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test generateImage with missing prediction ID in response.
	 */
	public function test_generate_image_missing_prediction_id(): void {
		update_site_option( 'datamachine_image_generation_config', [
			'api_key' => 'test-key'
		] );

		$filter = function( $result, $parsed_args, $url ) {
			if ( str_contains( $url, 'replicate.com' ) ) {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => wp_json_encode( array( 'status' => 'starting' ) ),
					'headers'  => array(),
					'cookies'  => array(),
				);
			}
			return $result;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = ImageGenerationAbilities::generateImage( [ 'prompt' => 'Test prompt' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid response from Replicate API', $result['error'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test generateImage success — Replicate returns prediction, scheduler creates job.
	 */
	public function test_generate_image_success(): void {
		update_site_option( 'datamachine_image_generation_config', [
			'api_key' => 'test-key',
			'default_model' => 'google/imagen-4-fast',
			'default_aspect_ratio' => '3:4'
		] );

		$filter = function( $result, $parsed_args, $url ) {
			if ( str_contains( $url, 'replicate.com' ) ) {
				$body = json_decode( $parsed_args['body'], true );
				$this->assertSame( 'Test prompt', $body['input']['prompt'] );

				return array(
					'response' => array( 'code' => 201, 'message' => 'Created' ),
					'body'     => wp_json_encode( array( 'id' => 'pred_123' ) ),
					'headers'  => array(),
					'cookies'  => array(),
				);
			}
			return $result;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = ImageGenerationAbilities::generateImage( [ 'prompt' => 'Test prompt' ] );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['pending'] );
		$this->assertIsInt( $result['job_id'] );
		$this->assertSame( 'pred_123', $result['prediction_id'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test is_configured returns false when no config.
	 */
	public function test_is_configured_false(): void {
		delete_site_option( 'datamachine_image_generation_config' );
		$this->assertFalse( ImageGenerationAbilities::is_configured() );
	}

	/**
	 * Test is_configured returns true when api_key present.
	 */
	public function test_is_configured_true(): void {
		update_site_option( 'datamachine_image_generation_config', [ 'api_key' => 'test-key' ] );
		$this->assertTrue( ImageGenerationAbilities::is_configured() );
	}

	/**
	 * Test get_config returns empty array by default.
	 */
	public function test_get_config_empty(): void {
		delete_site_option( 'datamachine_image_generation_config' );
		$this->assertSame( [], ImageGenerationAbilities::get_config() );
	}

	/**
	 * Test get_config returns stored configuration.
	 */
	public function test_get_config_returns_stored(): void {
		$config = [ 'api_key' => 'test-key', 'default_model' => 'custom-model' ];
		update_site_option( 'datamachine_image_generation_config', $config );
		$this->assertSame( $config, ImageGenerationAbilities::get_config() );
	}
}
