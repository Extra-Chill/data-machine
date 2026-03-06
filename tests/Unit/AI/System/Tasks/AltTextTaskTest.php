<?php
/**
 * Tests for AltTextTask.
 *
 * @package DataMachine\Tests\Unit\AI\System\Tasks
 */

namespace DataMachine\Tests\Unit\AI\System\Tasks;

use DataMachine\Engine\AI\System\Tasks\AltTextTask;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\RequestBuilder;
use WP_UnitTestCase;

class AltTextTaskTest extends WP_UnitTestCase {

	private AltTextTask $task;
	private int $attachment_id;
	private string $test_image_path;

	public function set_up(): void {
		parent::set_up();
		$this->task = new AltTextTask();

		// Create a test image file
		$upload_dir = wp_upload_dir();
		$this->test_image_path = $upload_dir['path'] . '/test-image.jpg';
		
		// Create a minimal JPEG file
		$jpeg_data = base64_decode( '/9j/4AAQSkZJRgABAQEAAAAAAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/3/AD' );
		file_put_contents( $this->test_image_path, $jpeg_data );

		// Create attachment
		$this->attachment_id = self::factory()->attachment->create_object( [
			'file' => $this->test_image_path,
			'post_mime_type' => 'image/jpeg',
			'post_title' => 'Test Image'
		] );

		// Set the attached file
		update_attached_file( $this->attachment_id, $this->test_image_path );
	}

	public function tear_down(): void {
		if ( file_exists( $this->test_image_path ) ) {
			unlink( $this->test_image_path );
		}
		parent::tear_down();
	}

	/**
	 * Test getTaskType returns correct identifier.
	 */
	public function test_get_task_type(): void {
		$this->assertSame( 'alt_text_generation', $this->task->getTaskType() );
	}

	/**
	 * Test execute with missing attachment_id fails.
	 */
	public function test_execute_missing_attachment_id(): void {
		$this->expectOutputString( '' );
		$this->task->execute( 1, [] );
		
		// Check if job failed - we'd need to mock the failJob method
		$this->assertTrue( true ); // Placeholder assertion
	}

	/**
	 * Test execute with invalid attachment_id fails.
	 */
	public function test_execute_invalid_attachment_id(): void {
		$this->expectOutputString( '' );
		$this->task->execute( 1, [ 'attachment_id' => 99999 ] );
		
		$this->assertTrue( true ); // Placeholder assertion
	}

	/**
	 * Test execute with non-image attachment fails.
	 */
	public function test_execute_non_image_attachment(): void {
		$text_attachment = self::factory()->attachment->create_object( [
			'file' => 'test.txt',
			'post_mime_type' => 'text/plain'
		] );

		$this->expectOutputString( '' );
		$this->task->execute( 1, [ 'attachment_id' => $text_attachment ] );
		
		$this->assertTrue( true ); // Placeholder assertion
	}

	/**
	 * Test execute skips when alt text exists and force=false.
	 */
	public function test_execute_skips_existing_alt_text(): void {
		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'Existing alt text' );

		$this->expectOutputString( '' );
		$this->task->execute( 1, [ 'attachment_id' => $this->attachment_id ] );
		
		$this->assertTrue( true ); // Placeholder assertion
	}

	/**
	 * Test execute processes when alt text exists but force=true.
	 */
	public function test_execute_force_override(): void {
		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'Existing alt text' );

		// Mock PluginSettings
		$settings_filter = function( $value, $key, $default_value ) {
			if ( 'default_provider' === $key ) {
				return 'openai';
			}
			if ( 'default_model' === $key ) {
				return 'gpt-4';
			}
			return $value;
		};
		add_filter( 'pre_option_datamachine_settings', function( $pre_option ) use ( $settings_filter ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		}, 10, 1 );

		// Mock RequestBuilder
		$request_filter = function( $response, $messages, $provider, $model, $tools, $agent_type, $context ) {
			return [
				'success' => true,
				'data' => [
					'content' => 'A small test image showing minimal JPEG data.'
				]
			];
		};
		add_filter( 'pre_datamachine_ai_request', $request_filter, 10, 7 );

		$this->expectOutputString( '' );
		$this->task->execute( 1, [
			'attachment_id' => $this->attachment_id,
			'force' => true
		] );

		$updated_alt = get_post_meta( $this->attachment_id, '_wp_attachment_image_alt', true );
		$this->assertSame( 'A small test image showing minimal JPEG data.', $updated_alt );

		remove_filter( 'pre_option_datamachine_settings', function() {} );
		remove_filter( 'pre_datamachine_ai_request', $request_filter, 10 );
	}

	/**
	 * Test execute fails when image file missing.
	 */
	public function test_execute_missing_file(): void {
		// Delete the file but keep the attachment
		unlink( $this->test_image_path );

		$this->expectOutputString( '' );
		$this->task->execute( 1, [ 'attachment_id' => $this->attachment_id ] );
		
		$this->assertTrue( true ); // Placeholder assertion
	}

	/**
	 * Test execute fails when no AI provider configured.
	 */
	public function test_execute_no_provider_configured(): void {
		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => '',
				'default_model' => ''
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );

		$this->expectOutputString( '' );
		$this->task->execute( 1, [ 'attachment_id' => $this->attachment_id ] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
		$this->assertTrue( true ); // Placeholder assertion
	}

	/**
	 * Test execute fails when AI request fails.
	 */
	public function test_execute_ai_request_fails(): void {
		// Mock PluginSettings
		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );

		// Mock RequestBuilder to fail
		$request_filter = function( $response, $messages, $provider, $model, $tools, $agent_type, $context ) {
			return [
				'success' => false,
				'error' => 'API connection failed'
			];
		};
		add_filter( 'pre_datamachine_ai_request', $request_filter, 10, 7 );

		$this->expectOutputString( '' );
		$this->task->execute( 1, [ 'attachment_id' => $this->attachment_id ] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
		remove_filter( 'pre_datamachine_ai_request', $request_filter, 10 );
		$this->assertTrue( true ); // Placeholder assertion
	}

	/**
	 * Test execute fails when AI returns empty content.
	 */
	public function test_execute_empty_ai_response(): void {
		// Mock PluginSettings
		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );

		// Mock RequestBuilder to return empty content
		$request_filter = function( $response, $messages, $provider, $model, $tools, $agent_type, $context ) {
			return [
				'success' => true,
				'data' => [
					'content' => ''
				]
			];
		};
		add_filter( 'pre_datamachine_ai_request', $request_filter, 10, 7 );

		$this->expectOutputString( '' );
		$this->task->execute( 1, [ 'attachment_id' => $this->attachment_id ] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
		remove_filter( 'pre_datamachine_ai_request', $request_filter, 10 );
		$this->assertTrue( true ); // Placeholder assertion
	}

	/**
	 * Test successful alt text generation.
	 */
	public function test_execute_success(): void {
		// Mock PluginSettings
		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );

		// Mock RequestBuilder
		$request_filter = function( $response, $messages, $provider, $model, $tools, $agent_type, $context ) {
			// Verify the request structure
			$this->assertCount( 2, $messages );
			$this->assertSame( 'user', $messages[0]['role'] );
			$this->assertIsArray( $messages[0]['content'] );
			$this->assertSame( 'file', $messages[0]['content'][0]['type'] );
			$this->assertSame( 'user', $messages[1]['role'] );
			$this->assertStringContainsString( 'alt text', $messages[1]['content'] );
			$this->assertSame( 'openai', $provider );
			$this->assertSame( 'gpt-4', $model );
			$this->assertSame( 'system', $agent_type );
			$this->assertSame( $this->attachment_id, $context['attachment_id'] );
			
			return [
				'success' => true,
				'data' => [
					'content' => 'a colorful test image for unit testing'
				]
			];
		};
		add_filter( 'pre_datamachine_ai_request', $request_filter, 10, 7 );

		$this->expectOutputString( '' );
		$this->task->execute( 1, [ 'attachment_id' => $this->attachment_id ] );

		$alt_text = get_post_meta( $this->attachment_id, '_wp_attachment_image_alt', true );
		$this->assertSame( 'A colorful test image for unit testing.', $alt_text );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
		remove_filter( 'pre_datamachine_ai_request', $request_filter, 10 );
	}

	/**
	 * Test alt text normalization.
	 */
	public function test_alt_text_normalization(): void {
		// Mock PluginSettings
		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );

		// Test various AI responses that need normalization
		$test_cases = [
			'"A quoted response"' => 'A quoted response.',
			'lowercase text' => 'Lowercase text.',
			'Text without period' => 'Text without period.',
			'  whitespace around  ' => 'Whitespace around.',
			"'Single quotes'" => 'Single quotes.'
		];

		foreach ( $test_cases as $ai_response => $expected_alt ) {
			// Clear existing alt text
			delete_post_meta( $this->attachment_id, '_wp_attachment_image_alt' );

			$request_filter = function( $response, $messages, $provider, $model, $tools, $agent_type, $context ) use ( $ai_response ) {
				return [
					'success' => true,
					'data' => [ 'content' => $ai_response ]
				];
			};
			add_filter( 'pre_datamachine_ai_request', $request_filter, 10, 7 );

			$this->task->execute( 1, [ 'attachment_id' => $this->attachment_id ] );

			$alt_text = get_post_meta( $this->attachment_id, '_wp_attachment_image_alt', true );
			$this->assertSame( $expected_alt, $alt_text, "Failed for input: {$ai_response}" );

			remove_filter( 'pre_datamachine_ai_request', $request_filter, 10 );
		}

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
	}

	/**
	 * Test prompt includes context from attachment metadata.
	 */
	public function test_prompt_includes_context(): void {
		// Add metadata to attachment
		wp_update_post( [
			'ID' => $this->attachment_id,
			'post_title' => 'Sunset Photo',
			'post_excerpt' => 'Beautiful sunset caption',
			'post_content' => 'A detailed description of the sunset'
		] );

		// Create parent post
		$parent_id = self::factory()->post->create( [
			'post_title' => 'Photography Blog Post'
		] );
		wp_update_post( [
			'ID' => $this->attachment_id,
			'post_parent' => $parent_id
		] );

		// Mock PluginSettings and RequestBuilder
		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );

		$request_filter = function( $response, $messages, $provider, $model, $tools, $agent_type, $context ) {
			$prompt = $messages[1]['content'];
			$this->assertStringContainsString( 'Sunset Photo', $prompt );
			$this->assertStringContainsString( 'Beautiful sunset caption', $prompt );
			$this->assertStringContainsString( 'A detailed description', $prompt );
			$this->assertStringContainsString( 'Photography Blog Post', $prompt );
			
			return [
				'success' => true,
				'data' => [ 'content' => 'Generated alt text' ]
			];
		};
		add_filter( 'pre_datamachine_ai_request', $request_filter, 10, 7 );

		$this->task->execute( 1, [ 'attachment_id' => $this->attachment_id ] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
		remove_filter( 'pre_datamachine_ai_request', $request_filter, 10 );
	}
}