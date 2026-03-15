<?php
/**
 * Tests for ImageGeneration tool handle_tool_call method.
 *
 * Tests the tool layer's delegation to the ability and response handling.
 * Uses pre_http_request filter to mock Replicate API.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Engine\AI\Tools\Global\ImageGeneration;
use WP_UnitTestCase;
use WP_Error;

class ImageGenerationToolCallTest extends WP_UnitTestCase {

	private ImageGeneration $tool;

	public function set_up(): void {
		parent::set_up();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->tool = new ImageGeneration();
	}

	public function tear_down(): void {
		delete_site_option( 'datamachine_image_generation_config' );
		parent::tear_down();
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

		$result = $this->tool->handle_tool_call( array( 'prompt' => 'A beautiful sunset' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'failed', strtolower( $result['error'] ) );
		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test handle_tool_call handles error from ability result.
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

		$result = $this->tool->handle_tool_call( array( 'prompt' => 'A beautiful sunset' ) );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['error'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test handle_tool_call delegates to ability successfully.
	 */
	public function test_handle_tool_call_success_basic(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'api_key' => 'test-key' ) );
		$filter = $this->mock_replicate_success( 'pred_abc123' );

		$result = $this->tool->handle_tool_call( array( 'prompt' => 'A beautiful sunset' ) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['pending'] );
		$this->assertIsInt( $result['job_id'] );
		$this->assertSame( 'pred_abc123', $result['prediction_id'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test handle_tool_call passes parameters through.
	 */
	public function test_handle_tool_call_with_parameters(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'api_key' => 'test-key' ) );
		$filter = $this->mock_replicate_success( 'pred_xyz789' );

		$result = $this->tool->handle_tool_call( array(
			'prompt'       => 'A serene mountain landscape',
			'model'        => 'google/imagen-4-fast',
			'aspect_ratio' => '16:9',
			'job_id'       => 456,
		) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['pending'] );
		$this->assertIsInt( $result['job_id'] );
		$this->assertSame( 'pred_xyz789', $result['prediction_id'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test handle_tool_call always returns tool_name.
	 */
	public function test_handle_tool_call_returns_tool_name(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'api_key' => 'test-key' ) );
		$filter = $this->mock_replicate_success( 'pred_name111' );

		$result = $this->tool->handle_tool_call( array( 'prompt' => 'Test image' ) );

		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}
}
