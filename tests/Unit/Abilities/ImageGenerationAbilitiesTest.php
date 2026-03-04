<?php
/**
 * Tests for ImageGenerationAbilities execute method.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Media\ImageGenerationAbilities;
use DataMachine\Engine\AI\System\SystemAgent;
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

		// Mock HttpClient to return error
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

		// Mock HttpClient to return invalid JSON
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

		// Mock HttpClient to return response without ID
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
	 * Test generateImage fails when SystemAgent scheduling fails.
	 */
	public function test_generate_image_scheduling_fails(): void {
		update_site_option( 'datamachine_image_generation_config', [
			'api_key' => 'test-key'
		] );

		// Mock HttpClient to return valid prediction response
		$filter = function( $result, $parsed_args, $url ) {
			if ( str_contains( $url, 'replicate.com' ) ) {
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

		// Mock SystemAgent to fail scheduling
		$system_agent_mock = $this->createMock( SystemAgent::class );
		$system_agent_mock->method( 'scheduleTask' )
			->willReturn( false );

		// Use reflection to replace the singleton instance
		$reflection = new \ReflectionClass( SystemAgent::class );
		$instance_property = $reflection->getProperty( 'instance' );
		$instance_property->setAccessible( true );
		$original_instance = $instance_property->getValue();
		$instance_property->setValue( $system_agent_mock );

		$result = ImageGenerationAbilities::generateImage( [ 'prompt' => 'Test prompt' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Failed to schedule', $result['error'] );

		// Restore original instance
		$instance_property->setValue( $original_instance );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test generateImage success with default parameters.
	 */
	public function test_generate_image_success_defaults(): void {
		update_site_option( 'datamachine_image_generation_config', [
			'api_key' => 'test-key',
			'default_model' => 'google/imagen-4-fast',
			'default_aspect_ratio' => '3:4'
		] );

		// Mock HttpClient to return valid prediction response
		$filter = function( $result, $parsed_args, $url ) {
			if ( str_contains( $url, 'replicate.com' ) ) {
				// Verify request structure
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

		// Mock SystemAgent to succeed
		$system_agent_mock = $this->createMock( SystemAgent::class );
		$system_agent_mock->method( 'scheduleTask' )
			->willReturn( 456 );

		// Use reflection to replace the singleton instance
		$reflection = new \ReflectionClass( SystemAgent::class );
		$instance_property = $reflection->getProperty( 'instance' );
		$instance_property->setAccessible( true );
		$original_instance = $instance_property->getValue();
		$instance_property->setValue( $system_agent_mock );

		$result = ImageGenerationAbilities::generateImage( [ 'prompt' => 'Test prompt' ] );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['pending'] );
		$this->assertSame( 456, $result['job_id'] );
		$this->assertSame( 'pred_123', $result['prediction_id'] );
		$this->assertStringContainsString( '3:4', $result['message'] );

		// Restore original instance
		$instance_property->setValue( $original_instance );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test generateImage success with custom parameters and pipeline_job_id.
	 */
	public function test_generate_image_success_custom_params(): void {
		update_site_option( 'datamachine_image_generation_config', [
			'api_key' => 'test-key'
		] );

		// Mock HttpClient to return valid prediction response
		$filter = function( $result, $parsed_args, $url ) {
			if ( str_contains( $url, 'replicate.com' ) ) {
				// Verify custom parameters
				$body = json_decode( $parsed_args['body'], true );
				$this->assertSame( 'Custom prompt', $body['input']['prompt'] );

				return array(
					'response' => array( 'code' => 201, 'message' => 'Created' ),
					'body'     => wp_json_encode( array( 'id' => 'pred_custom' ) ),
					'headers'  => array(),
					'cookies'  => array(),
				);
			}
			return $result;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		// Mock SystemAgent to succeed with context
		$system_agent_mock = $this->createMock( SystemAgent::class );
		$system_agent_mock->method( 'scheduleTask' )
			->willReturn( 999 );

		// Use reflection to replace the singleton instance
		$reflection = new \ReflectionClass( SystemAgent::class );
		$instance_property = $reflection->getProperty( 'instance' );
		$instance_property->setAccessible( true );
		$original_instance = $instance_property->getValue();
		$instance_property->setValue( $system_agent_mock );

		$result = ImageGenerationAbilities::generateImage( [
			'prompt' => 'Custom prompt',
			'model' => 'black-forest-labs/flux-schnell',
			'aspect_ratio' => '16:9',
			'pipeline_job_id' => 789
		] );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 999, $result['job_id'] );
		$this->assertSame( 'pred_custom', $result['prediction_id'] );

		// Restore original instance
		$instance_property->setValue( $original_instance );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test invalid aspect ratio falls back to default.
	 */
	public function test_generate_image_invalid_aspect_ratio(): void {
		update_site_option( 'datamachine_image_generation_config', [
			'api_key' => 'test-key'
		] );

		// Mock HttpClient
		$filter = function( $result, $parsed_args, $url ) {
			if ( str_contains( $url, 'replicate.com' ) ) {
				return array(
					'response' => array( 'code' => 201, 'message' => 'Created' ),
					'body'     => wp_json_encode( array( 'id' => 'pred_fallback' ) ),
					'headers'  => array(),
					'cookies'  => array(),
				);
			}
			return $result;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		// Mock SystemAgent
		$system_agent_mock = $this->createMock( SystemAgent::class );
		$system_agent_mock->method( 'scheduleTask' )->willReturn( 111 );

		$reflection = new \ReflectionClass( SystemAgent::class );
		$instance_property = $reflection->getProperty( 'instance' );
		$instance_property->setAccessible( true );
		$original_instance = $instance_property->getValue();
		$instance_property->setValue( $system_agent_mock );

		$result = ImageGenerationAbilities::generateImage( [
			'prompt' => 'Test',
			'aspect_ratio' => 'invalid:ratio'
		] );

		$this->assertTrue( $result['success'] );

		$instance_property->setValue( $original_instance );
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