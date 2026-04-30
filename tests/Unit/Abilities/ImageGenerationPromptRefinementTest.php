<?php
/**
 * Tests for ImageGenerationAbilities prompt refinement functionality.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Media\ImageGenerationAbilities;
use DataMachine\Core\PluginSettings;
use DataMachine\Tests\Unit\Support\WpAiClientTestDouble;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/WpAiClientTestDoubles.php';

class ImageGenerationPromptRefinementTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		datamachine_register_capabilities();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		WpAiClientTestDouble::reset();
		WpAiClientTestDouble::set_response_callback( [ $this, 'mock_ai_response' ] );
	}

	public function tear_down(): void {
		delete_site_option( 'datamachine_image_generation_config' );
		delete_option( 'datamachine_settings' );
		PluginSettings::clearCache();
		WpAiClientTestDouble::reset();
		parent::tear_down();
	}

	/**
	 * Helper: set plugin settings via the WordPress option.
	 */
	private function set_plugin_settings( array $settings ): void {
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();
	}

	/**
	 * Helper: clear all plugin settings.
	 */
	private function clear_plugin_settings(): void {
		delete_option( 'datamachine_settings' );
		PluginSettings::clearCache();
	}

	/**
	 * Mock AI response for testing.
	 *
	 * @param array $response Original response.
	 * @param array $request Request parameters.
	 * @return array Mocked response.
	 */
	public function mock_ai_response( array $request ): array {
		// Return a refined prompt for testing
		return array(
			'success' => true,
			'data'    => array(
				'content' => 'A majestic crane standing gracefully in misty wetlands at golden hour, soft natural lighting, high detail photography style, serene atmosphere, shallow depth of field, professional nature photography',
			),
		);
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
		$this->clear_plugin_settings();
		
		$this->assertFalse( ImageGenerationAbilities::is_refinement_enabled( $config ) );
	}

	public function test_is_refinement_enabled_returns_true_when_properly_configured(): void {
		$config = [
			'prompt_refinement_enabled' => true,
		];
		
		$this->set_plugin_settings( array(
			'default_provider' => 'openai',
			'default_model'    => 'gpt-4',
		) );
		
		$this->assertTrue( ImageGenerationAbilities::is_refinement_enabled( $config ) );
	}

	public function test_is_refinement_enabled_defaults_to_true(): void {
		$config = []; // No explicit setting
		
		$this->set_plugin_settings( array(
			'default_provider' => 'openai',
			'default_model'    => 'gpt-4',
		) );
		
		$this->assertTrue( ImageGenerationAbilities::is_refinement_enabled( $config ) );
	}

	public function test_refine_prompt_returns_null_when_no_provider(): void {
		$this->clear_plugin_settings();
		
		$refined = ImageGenerationAbilities::refine_prompt( 'Test prompt' );
		
		$this->assertNull( $refined );
	}

	public function test_refine_prompt_returns_refined_text_when_successful(): void {
		$this->set_plugin_settings( array(
			'default_provider' => 'openai',
			'default_model'    => 'gpt-4',
		) );
		
		$refined = ImageGenerationAbilities::refine_prompt( 'The Spiritual Meaning of Cranes' );
		
		$this->assertNotNull( $refined );
		$this->assertStringContainsString( 'crane', $refined );
		$this->assertStringContainsString( 'golden hour', $refined );
		$this->assertStringContainsString( 'photography', $refined );
	}

	public function test_refine_prompt_includes_post_context_when_provided(): void {
		$this->set_plugin_settings( array(
			'default_provider' => 'openai',
			'default_model'    => 'gpt-4',
		) );

		// Capture the AI request to verify context is included.
		$captured_request = null;
		WpAiClientTestDouble::set_response_callback( function ( array $request ) use ( &$captured_request ): array {
			$captured_request = $request;
			return array(
				'success' => true,
				'data'    => array( 'content' => 'refined prompt with context' ),
			);
		} );

		ImageGenerationAbilities::refine_prompt( 'Crane meaning', 'This article explores the spiritual symbolism of cranes in various cultures.' );

		$this->assertNotNull( $captured_request );
		// Directives prepend messages, so find the last user message (our refinement request).
		$user_message = '';
		foreach ( array_reverse( $captured_request['messages'] ) as $msg ) {
			if ( ( $msg['role'] ?? '' ) === 'user' ) {
				$user_message = $msg['content'] ?? '';
				break;
			}
		}
		$this->assertStringContainsString( 'Article context:', $user_message );
		$this->assertStringContainsString( 'spiritual symbolism', $user_message );
	}

	public function test_refine_prompt_uses_custom_style_guide(): void {
		$this->set_plugin_settings( array(
			'default_provider' => 'openai',
			'default_model'    => 'gpt-4',
		) );
		
		$custom_style_guide = 'Create minimalist, modern image prompts with clean lines and bright colors.';
		$config = [
			'prompt_style_guide' => $custom_style_guide,
		];
		
		// Track the AI request to verify custom style guide is used
		$captured_request = null;
		WpAiClientTestDouble::set_response_callback( function( array $request ) use ( &$captured_request ): array {
			$captured_request = $request;
			return [
				'success' => true,
				'data' => [ 'content' => 'refined prompt with custom style' ]
			];
		} );
		
		ImageGenerationAbilities::refine_prompt( 'Test prompt', '', $config );
		
		$this->assertNotNull( $captured_request );
		// Directives prepend messages (SOUL.md, USER.md), so search all system messages
		// for our custom style guide content.
		$found_style_guide = false;
		foreach ( $captured_request['messages'] as $msg ) {
			if ( ( $msg['role'] ?? '' ) === 'system' && str_contains( $msg['content'] ?? '', 'minimalist, modern' ) ) {
				$found_style_guide = true;
				break;
			}
		}
		$this->assertTrue( $found_style_guide, 'Custom style guide should appear in one of the system messages.' );
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
		$this->set_plugin_settings( array(
			'default_provider' => 'openai',
			'default_model'    => 'gpt-4',
		) );
		
		// Mock AI failure
		WpAiClientTestDouble::set_response_callback( function(): array {
			return [
				'success' => false,
				'error' => 'API error'
			];
		} );
		
		$refined = ImageGenerationAbilities::refine_prompt( 'Test prompt' );
		
		$this->assertNull( $refined );
	}

	public function test_refine_prompt_returns_null_on_empty_ai_response(): void {
		$this->set_plugin_settings( array(
			'default_provider' => 'openai',
			'default_model'    => 'gpt-4',
		) );
		
		// Mock empty AI response
		WpAiClientTestDouble::set_response_callback( function(): array {
			return [
				'success' => true,
				'data' => [ 'content' => '' ]
			];
		} );
		
		$refined = ImageGenerationAbilities::refine_prompt( 'Test prompt' );
		
		$this->assertNull( $refined );
	}

	public function test_generate_image_applies_refinement_when_enabled(): void {
		update_site_option( 'datamachine_image_generation_config', array(
			'default_provider'          => 'openai',
			'default_model'             => 'gpt-image-1',
			'prompt_refinement_enabled' => true,
		) );
		$this->set_plugin_settings( array(
			'default_provider' => 'openai',
			'default_model'    => 'gpt-4',
		) );

		WpAiClientTestDouble::set_response_callback( function ( array $request ): array {
			if ( ( $request['capability'] ?? '' ) === 'image_generation' ) {
				return array(
					'success' => true,
					'data'    => array( 'image_url' => 'https://example.com/refined.png' ),
				);
			}

			return $this->mock_ai_response( $request );
		} );

		$result = ImageGenerationAbilities::generateImage( array( 'prompt' => 'The Spiritual Meaning of Cranes' ) );

		$this->assertTrue( $result['success'], 'generateImage should succeed. Error: ' . ( $result['error'] ?? 'none' ) );
		$this->assertStringContainsString( 'Prompt was refined', $result['message'] );
	}

	public function test_generate_image_skips_refinement_when_disabled(): void {
		update_site_option( 'datamachine_image_generation_config', array(
			'default_provider'          => 'openai',
			'default_model'             => 'gpt-image-1',
			'prompt_refinement_enabled' => false,
		) );

		WpAiClientTestDouble::set_response_callback( function (): array {
			return array(
				'success' => true,
				'data'    => array( 'image_url' => 'https://example.com/unrefined.png' ),
			);
		} );

		$result = ImageGenerationAbilities::generateImage( array( 'prompt' => 'The Spiritual Meaning of Cranes' ) );

		$this->assertTrue( $result['success'] );
		$this->assertStringNotContainsString( 'Prompt was refined', $result['message'] );
	}
}
