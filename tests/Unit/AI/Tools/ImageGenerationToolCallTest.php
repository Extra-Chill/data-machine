<?php
/**
 * Tests for ImageGeneration tool handle_tool_call method.
 *
 * Tests the tool layer's delegation to the ability and response handling.
 * Uses pre_http_request filter to mock Replicate API and reflection to
 * replace the SystemAgent singleton.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Engine\AI\Tools\Global\ImageGeneration;
use DataMachine\Engine\AI\System\SystemAgent;
use WP_UnitTestCase;
use WP_Error;

class ImageGenerationToolCallTest extends WP_UnitTestCase {

	private ImageGeneration $tool;
	private $original_system_agent;
	private \ReflectionProperty $instance_property;

	public function set_up(): void {
		parent::set_up();

		// Ability execute() requires manage_options capability.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->tool = new ImageGeneration();

		// Save original SystemAgent instance for restoration.
		$reflection = new \ReflectionClass( SystemAgent::class );
		$this->instance_property = $reflection->getProperty( 'instance' );
		$this->instance_property->setAccessible( true );
		$this->original_system_agent = $this->instance_property->getValue();
	}

	public function tear_down(): void {
		// Restore original SystemAgent singleton.
		$this->instance_property->setValue( $this->original_system_agent );
		delete_site_option( 'datamachine_image_generation_config' );
		parent::tear_down();
	}

	/**
	 * Helper: replace SystemAgent singleton with a mock that returns a job ID.
	 */
	private function mock_system_agent( int $job_id = 456 ): void {
		$mock = $this->createMock( SystemAgent::class );
		$mock->method( 'scheduleTask' )->willReturn( $job_id );
		$this->instance_property->setValue( $mock );
	}

	/**
	 * Helper: add pre_http_request filter that returns a successful Replicate prediction.
	 *
	 * @param string $prediction_id Prediction ID to return.
	 * @return callable The filter callback (for removal).
	 */
	private function mock_replicate_success( string $prediction_id = 'pred_abc123' ): callable {
		$filter = function ( $preempt, $parsed_args, $url ) use ( $prediction_id ) {
			if ( str_contains( $url, 'replicate.com' ) ) {
				return array(
					'response' => array( 'code' => 201, 'message' => 'Created' ),
					'body'     => wp_json_encode( array( 'id' => $prediction_id, 'status' => 'starting' ) ),
					'headers'  => array(),
					'cookies'  => array(),
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );
		return $filter;
	}

	/**
	 * Test handle_tool_call handles WP_Error from HTTP failure.
	 */
	public function test_handle_tool_call_wp_error(): void {
		$filter = function ( $preempt, $parsed_args, $url ) {
			if ( str_contains( $url, 'replicate.com' ) ) {
				return new WP_Error( 'http_request_failed', 'Replicate API connection failed' );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		update_site_option( 'datamachine_image_generation_config', array( 'api_key' => 'test-key' ) );
		$this->mock_system_agent();

		$result = $this->tool->handle_tool_call( array( 'prompt' => 'A beautiful sunset' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'failed', strtolower( $result['error'] ) );
		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test handle_tool_call handles error from ability result (e.g. invalid API key).
	 */
	public function test_handle_tool_call_ability_error(): void {
		$filter = function ( $preempt, $parsed_args, $url ) {
			if ( str_contains( $url, 'replicate.com' ) ) {
				return array(
					'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
					'body'     => wp_json_encode( array( 'detail' => 'Invalid API key' ) ),
					'headers'  => array(),
					'cookies'  => array(),
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		update_site_option( 'datamachine_image_generation_config', array( 'api_key' => 'bad-key' ) );
		$this->mock_system_agent();

		$result = $this->tool->handle_tool_call( array( 'prompt' => 'A beautiful sunset' ) );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['error'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test handle_tool_call delegates to ability successfully with basic parameters.
	 */
	public function test_handle_tool_call_success_basic(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'api_key' => 'test-key' ) );
		$this->mock_system_agent( 123 );
		$filter = $this->mock_replicate_success( 'pred_abc123' );

		$result = $this->tool->handle_tool_call( array( 'prompt' => 'A beautiful sunset' ) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['pending'] );
		$this->assertSame( 123, $result['job_id'] );
		$this->assertSame( 'pred_abc123', $result['prediction_id'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test handle_tool_call passes all parameters including job_id as pipeline_job_id.
	 */
	public function test_handle_tool_call_with_job_id_and_parameters(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'api_key' => 'test-key' ) );
		$this->mock_system_agent( 789 );
		$filter = $this->mock_replicate_success( 'pred_xyz789' );

		$result = $this->tool->handle_tool_call( array(
			'prompt'       => 'A serene mountain landscape',
			'model'        => 'google/imagen-4-fast',
			'aspect_ratio' => '16:9',
			'job_id'       => 456,
		) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['pending'] );
		$this->assertSame( 789, $result['job_id'] );
		$this->assertSame( 'pred_xyz789', $result['prediction_id'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test handle_tool_call works without job_id parameter.
	 */
	public function test_handle_tool_call_no_job_id(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'api_key' => 'test-key' ) );
		$this->mock_system_agent( 999 );
		$filter = $this->mock_replicate_success( 'pred_flux999' );

		$result = $this->tool->handle_tool_call( array(
			'prompt' => 'A peaceful forest scene',
			'model'  => 'black-forest-labs/flux-schnell',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 999, $result['job_id'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test handle_tool_call always returns tool_name in result.
	 */
	public function test_handle_tool_call_returns_tool_name(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'api_key' => 'test-key' ) );
		$this->mock_system_agent( 111 );
		$filter = $this->mock_replicate_success( 'pred_name111' );

		$result = $this->tool->handle_tool_call( array( 'prompt' => 'Test image' ) );

		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}
}
