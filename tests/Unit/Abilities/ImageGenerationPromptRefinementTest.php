<?php
/**
 * Tests for ImageGenerationAbilities prompt refinement functionality.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Media\ImageGenerationAbilities;
use DataMachine\Core\PluginSettings;
use WP_UnitTestCase;

class ImageGenerationPromptRefinementTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		
		// Mock the RequestBuilder for testing AI requests
		add_filter( 'datamachine_ai_request_response', [ $this, 'mock_ai_response' ], 10, 2 );
	}

	public function tear_down(): void {
		delete_site_option( 'datamachine_image_generation_config' );
		PluginSettings::delete( 'default_provider' );
		PluginSettings::delete( 'default_model' );
		remove_filter( 'datamachine_ai_request_response', [ $this, 'mock_ai_response' ] );
		parent::tear_down();
	}

	/**
	 * Mock AI response for testing.
	 *
	 * @param array $response Original response.
	 * @param array $request Request parameters.
	 * @return array Mocked response.
	 */
	public function mock_ai_response( $response, $request ) {
		// Return a refined prompt for testing
		return [
			'success' => true,
			'data' => [
				'content' => 'A majestic crane standing gracefully in misty wetlands at golden hour, soft natural lighting, high detail photography style, serene atmosphere, shallow depth of field, professional nature photography'
			]
		];
	}

	public function test_is_refinement_enabled_returns_false_when_disabled(): void {
		$config = [
			'prompt_refinement_enabled' => false,
		];
		
		$this->assertFalse( ImageGenerationAbilities::is_refinement_enabled( $config ) );
	}

	public function test_is_refinement_enabled_returns_false_when_no_ai_provider(): void {
		$config = [
			'prompt_refinement_enabled' => true,
		];
		
		// No AI provider configured
		PluginSettings::delete( 'default_provider' );
		PluginSettings::delete( 'default_model' );
		
		$this->assertFalse( ImageGenerationAbilities::is_refinement_enabled( $config ) );
	}

	public function test_is_refinement_enabled_returns_true_when_properly_configured(): void {
		$config = [
			'prompt_refinement_enabled' => true,
		];
		
		PluginSettings::set( 'default_provider', 'openai' );
		PluginSettings::set( 'default_model', 'gpt-4' );
		
		$this->assertTrue( ImageGenerationAbilities::is_refinement_enabled( $config ) );
	}

	public function test_is_refinement_enabled_defaults_to_true(): void {
		$config = []; // No explicit setting
		
		PluginSettings::set( 'default_provider', 'openai' );
		PluginSettings::set( 'default_model', 'gpt-4' );
		
		$this->assertTrue( ImageGenerationAbilities::is_refinement_enabled( $config ) );
	}

	public function test_refine_prompt_returns_null_when_no_provider(): void {
		PluginSettings::delete( 'default_provider' );
		PluginSettings::delete( 'default_model' );
		
		$refined = ImageGenerationAbilities::refine_prompt( 'Test prompt' );
		
		$this->assertNull( $refined );
	}

	public function test_refine_prompt_returns_refined_text_when_successful(): void {
		PluginSettings::set( 'default_provider', 'openai' );
		PluginSettings::set( 'default_model', 'gpt-4' );
		
		$refined = ImageGenerationAbilities::refine_prompt( 'The Spiritual Meaning of Cranes' );
		
		$this->assertNotNull( $refined );
		$this->assertStringContainsString( 'crane', $refined );
		$this->assertStringContainsString( 'golden hour', $refined );
		$this->assertStringContainsString( 'photography', $refined );
	}

	public function test_refine_prompt_includes_post_context_when_provided(): void {
		PluginSettings::set( 'default_provider', 'openai' );
		PluginSettings::set( 'default_model', 'gpt-4' );
		
		// Track the AI request to verify context is included
		$captured_request = null;
		add_filter( 'datamachine_ai_request_capture', function( $request ) use ( &$captured_request ) {
			$captured_request = $request;
			return $request;
		} );
		
		// Mock the AI request filter to capture requests
		remove_filter( 'datamachine_ai_request_response', [ $this, 'mock_ai_response' ] );
		add_filter( 'chubes_ai_request', function( $request ) use ( &$captured_request ) {
			$captured_request = $request;
			return [
				'success' => true,
				'data' => [ 'content' => 'refined prompt with context' ]
			];
		}, 10, 6 );
		
		ImageGenerationAbilities::refine_prompt( 'Crane meaning', 'This article explores the spiritual symbolism of cranes in various cultures.' );
		
		$this->assertNotNull( $captured_request );
		$user_message = $captured_request['messages'][1]['content'] ?? '';
		$this->assertStringContainsString( 'Article context:', $user_message );
		$this->assertStringContainsString( 'spiritual symbolism', $user_message );
	}

	public function test_refine_prompt_uses_custom_style_guide(): void {
		PluginSettings::set( 'default_provider', 'openai' );
		PluginSettings::set( 'default_model', 'gpt-4' );
		
		$custom_style_guide = 'Create minimalist, modern image prompts with clean lines and bright colors.';
		$config = [
			'prompt_style_guide' => $custom_style_guide,
		];
		
		// Track the AI request to verify custom style guide is used
		$captured_request = null;
		remove_filter( 'datamachine_ai_request_response', [ $this, 'mock_ai_response' ] );
		add_filter( 'chubes_ai_request', function( $request ) use ( &$captured_request ) {
			$captured_request = $request;
			return [
				'success' => true,
				'data' => [ 'content' => 'refined prompt with custom style' ]
			];
		}, 10, 6 );
		
		ImageGenerationAbilities::refine_prompt( 'Test prompt', '', $config );
		
		$this->assertNotNull( $captured_request );
		$system_message = $captured_request['messages'][0]['content'] ?? '';
		$this->assertStringContainsString( 'minimalist, modern', $system_message );
	}

	public function test_get_default_style_guide_contains_key_instructions(): void {
		$style_guide = ImageGenerationAbilities::get_default_style_guide();
		
		$this->assertStringContainsString( 'Visual style', $style_guide );
		$this->assertStringContainsString( 'Composition', $style_guide );
		$this->assertStringContainsString( 'Lighting', $style_guide );
		$this->assertStringContainsString( 'NEVER include text', $style_guide );
		$this->assertStringContainsString( '200 words', $style_guide );
		$this->assertStringContainsString( 'ONLY the refined prompt', $style_guide );
	}

	public function test_refine_prompt_returns_null_on_ai_failure(): void {
		PluginSettings::set( 'default_provider', 'openai' );
		PluginSettings::set( 'default_model', 'gpt-4' );
		
		// Mock AI failure
		remove_filter( 'datamachine_ai_request_response', [ $this, 'mock_ai_response' ] );
		add_filter( 'chubes_ai_request', function() {
			return [
				'success' => false,
				'error' => 'API error'
			];
		}, 10, 6 );
		
		$refined = ImageGenerationAbilities::refine_prompt( 'Test prompt' );
		
		$this->assertNull( $refined );
	}

	public function test_refine_prompt_returns_null_on_empty_ai_response(): void {
		PluginSettings::set( 'default_provider', 'openai' );
		PluginSettings::set( 'default_model', 'gpt-4' );
		
		// Mock empty AI response
		remove_filter( 'datamachine_ai_request_response', [ $this, 'mock_ai_response' ] );
		add_filter( 'chubes_ai_request', function() {
			return [
				'success' => true,
				'data' => [ 'content' => '' ]
			];
		}, 10, 6 );
		
		$refined = ImageGenerationAbilities::refine_prompt( 'Test prompt' );
		
		$this->assertNull( $refined );
	}

	public function test_generate_image_applies_refinement_when_enabled(): void {
		// Configure image generation and AI provider
		update_site_option( 'datamachine_image_generation_config', [
			'api_key' => 'test-replicate-key',
			'prompt_refinement_enabled' => true,
		] );
		PluginSettings::set( 'default_provider', 'openai' );
		PluginSettings::set( 'default_model', 'gpt-4' );
		
		// Mock Replicate API
		add_filter( 'pre_http_request', function( $preempt, $args, $url ) {
			if ( strpos( $url, 'replicate.com/v1/predictions' ) !== false ) {
				return [
					'response' => [ 'code' => 200 ],
					'body' => wp_json_encode( [ 'id' => 'test-prediction-id' ] ),
				];
			}
			return $preempt;
		}, 10, 3 );
		
		// Mock SystemAgent
		add_filter( 'datamachine_system_agent_schedule_task', function() { return 123; }, 10, 4 );
		
		$result = ImageGenerationAbilities::generateImage( [ 'prompt' => 'The Spiritual Meaning of Cranes' ] );
		
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Prompt was refined', $result['message'] );
	}

	public function test_generate_image_skips_refinement_when_disabled(): void {
		// Configure image generation with refinement disabled
		update_site_option( 'datamachine_image_generation_config', [
			'api_key' => 'test-replicate-key',
			'prompt_refinement_enabled' => false,
		] );
		
		// Mock Replicate API
		add_filter( 'pre_http_request', function( $preempt, $args, $url ) {
			if ( strpos( $url, 'replicate.com/v1/predictions' ) !== false ) {
				return [
					'response' => [ 'code' => 200 ],
					'body' => wp_json_encode( [ 'id' => 'test-prediction-id' ] ),
				];
			}
			return $preempt;
		}, 10, 3 );
		
		// Mock SystemAgent
		add_filter( 'datamachine_system_agent_schedule_task', function() { return 123; }, 10, 4 );
		
		$result = ImageGenerationAbilities::generateImage( [ 'prompt' => 'The Spiritual Meaning of Cranes' ] );
		
		$this->assertTrue( $result['success'] );
		$this->assertStringNotContainsString( 'Prompt was refined', $result['message'] );
	}
}