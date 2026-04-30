<?php
/**
 * Tests for ImageGeneration tool handle_tool_call method.
 *
 * Tests the tool layer's delegation to the ability and response handling.
 * Uses wp-ai-client test doubles to mock provider dispatch.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Engine\AI\Tools\Global\ImageGeneration;
use DataMachine\Tests\Unit\Support\WpAiClientTestDouble;
use WP_UnitTestCase;

require_once dirname( dirname( __DIR__ ) ) . '/Support/WpAiClientTestDoubles.php';

class ImageGenerationToolCallTest extends WP_UnitTestCase {

	private ImageGeneration $tool;

	public function set_up(): void {
		parent::set_up();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->tool = new ImageGeneration();
		WpAiClientTestDouble::reset();
		WpAiClientTestDouble::set_response_callback( array( $this, 'mock_image_response' ) );
	}

	public function tear_down(): void {
		delete_site_option( 'datamachine_image_generation_config' );
		WpAiClientTestDouble::reset();
		parent::tear_down();
	}

	public function mock_image_response( array $request ): array {
		return array(
			'success' => true,
			'data'    => array( 'image_url' => 'https://example.com/generated.png' ),
		);
	}

	/**
	 * Test handle_tool_call handles provider failure.
	 */
	public function test_handle_tool_call_wp_error(): void {
		WpAiClientTestDouble::set_response_callback( function (): array {
			return array( 'success' => false, 'error' => 'Provider connection failed' );
		} );

		update_site_option( 'datamachine_image_generation_config', array( 'default_provider' => 'openai', 'default_model' => 'gpt-image-1' ) );

		$result = $this->tool->handle_tool_call( array( 'prompt' => 'A beautiful sunset' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'failed', strtolower( $result['error'] ) );
		$this->assertSame( 'image_generation', $result['tool_name'] );
	}

	/**
	 * Test handle_tool_call handles error from ability result.
	 */
	public function test_handle_tool_call_ability_error(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'default_provider' => 'missing-provider', 'default_model' => 'gpt-image-1' ) );

		$result = $this->tool->handle_tool_call( array( 'prompt' => 'A beautiful sunset' ) );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['error'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );
	}

	/**
	 * Test handle_tool_call delegates to ability successfully.
	 */
	public function test_handle_tool_call_success_basic(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'default_provider' => 'openai', 'default_model' => 'gpt-image-1' ) );

		$result = $this->tool->handle_tool_call( array( 'prompt' => 'A beautiful sunset' ) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['pending'] );
		$this->assertIsInt( $result['job_id'] );
		$this->assertSame( 'https://example.com/generated.png', $result['image_url'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );
	}

	/**
	 * Test handle_tool_call passes parameters through.
	 */
	public function test_handle_tool_call_with_parameters(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'default_provider' => 'openai', 'default_model' => 'gpt-image-1' ) );

		$result = $this->tool->handle_tool_call( array(
			'prompt'       => 'A serene mountain landscape',
			'provider'     => 'openai',
			'model'        => 'gpt-image-1',
			'aspect_ratio' => '16:9',
			'job_id'       => 456,
		) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['pending'] );
		$this->assertIsInt( $result['job_id'] );
		$this->assertSame( 'https://example.com/generated.png', $result['image_url'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );
	}

	/**
	 * Test handle_tool_call always returns tool_name.
	 */
	public function test_handle_tool_call_returns_tool_name(): void {
		update_site_option( 'datamachine_image_generation_config', array( 'default_provider' => 'openai', 'default_model' => 'gpt-image-1' ) );

		$result = $this->tool->handle_tool_call( array( 'prompt' => 'Test image' ) );

		$this->assertSame( 'image_generation', $result['tool_name'] );
	}
}
