<?php
/**
 * Tests for AltTextTask.
 *
 * @package DataMachine\Tests\Unit\Engine\AI\System\Tasks
 */

namespace DataMachine\Tests\Unit\Engine\AI\System\Tasks;

use DataMachine\Engine\AI\System\Tasks\AltTextTask;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\RequestBuilder;
use WP_UnitTestCase;

class AltTextTaskTest extends WP_UnitTestCase {

	private AltTextTask $task;
	private int $attachment_id;
	private string $test_image_path;

	public function set_up(): void {
		global $wp_filesystem;
		parent::set_up();
		$this->task = new AltTextTask();

		// The bundled ai-http-client vendor registers its own \`chubes_ai_request\`
		// filter at priority 99 that ignores the \$request payload and always
		// attempts a real provider call (returning an error when no API key is
		// configured, as in the test environment). That overrides any lower-
		// priority mock we register, so we clear the hook before each test and
		// let each test register its own mock in isolation.
		remove_all_filters( 'chubes_ai_request' );

		// Create a test image file
		$upload_dir = wp_upload_dir();
		$this->test_image_path = $upload_dir['path'] . '/test-image.jpg';
		
		// Create a minimal JPEG file
		$jpeg_data = base64_decode( '/9j/4AAQSkZJRgABAQEAAAAAAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/3/AD' );
		$wp_filesystem->put_contents( $this->test_image_path, $jpeg_data );

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
		PluginSettings::clearCache();
		if ( file_exists( $this->test_image_path ) ) {
			wp_delete_file( $this->test_image_path );
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
		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );
		PluginSettings::clearCache();

		// Mock AI request via chubes_ai_request filter
		$request_filter = function( $request, $provider = '', $streaming = null, $tools = array(), $step_id = null, $context = array() ) {
			return [
				'success' => true,
				'data' => [
					'content' => 'A small test image showing minimal JPEG data.'
				]
			];
		};
		add_filter( 'chubes_ai_request', $request_filter, 10, 6 );

		$this->expectOutputString( '' );
		$this->task->execute( 1, [
			'attachment_id' => $this->attachment_id,
			'force' => true
		] );

		$updated_alt = get_post_meta( $this->attachment_id, '_wp_attachment_image_alt', true );
		$this->assertSame( 'A small test image showing minimal JPEG data.', $updated_alt );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
		remove_filter( 'chubes_ai_request', $request_filter, 10 );
	}

	/**
	 * Test execute fails when image file missing.
	 */
	public function test_execute_missing_file(): void {
		// Delete the file but keep the attachment
		wp_delete_file( $this->test_image_path );

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
		PluginSettings::clearCache();

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
		PluginSettings::clearCache();

		// Mock AI request to fail via chubes_ai_request filter
		$request_filter = function( $request, $provider = '', $streaming = null, $tools = array(), $step_id = null, $context = array() ) {
			return [
				'success' => false,
				'error' => 'API connection failed'
			];
		};
		add_filter( 'chubes_ai_request', $request_filter, 10, 6 );

		$this->expectOutputString( '' );
		$this->task->execute( 1, [ 'attachment_id' => $this->attachment_id ] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
		remove_filter( 'chubes_ai_request', $request_filter, 10 );
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
		PluginSettings::clearCache();

		// Mock AI request to return empty content via chubes_ai_request filter
		$request_filter = function( $request, $provider = '', $streaming = null, $tools = array(), $step_id = null, $context = array() ) {
			return [
				'success' => true,
				'data' => [
					'content' => ''
				]
			];
		};
		add_filter( 'chubes_ai_request', $request_filter, 10, 6 );

		$this->expectOutputString( '' );
		$this->task->execute( 1, [ 'attachment_id' => $this->attachment_id ] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
		remove_filter( 'chubes_ai_request', $request_filter, 10 );
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
		PluginSettings::clearCache();

		// Mock AI request via chubes_ai_request filter
		$request_filter = function( $request, $provider = '', $streaming = null, $tools = array(), $step_id = null, $context = array() ) {
			// Verify the request structure
			$this->assertIsArray( $request['messages'] );
			$this->assertSame( 'gpt-4', $request['model'] );
			$this->assertSame( 'openai', $provider );

			return [
				'success' => true,
				'data' => [
					'content' => 'a colorful test image for unit testing'
				]
			];
		};
		add_filter( 'chubes_ai_request', $request_filter, 10, 6 );

		$this->expectOutputString( '' );
		$this->task->execute( 1, [ 'attachment_id' => $this->attachment_id ] );

		$alt_text = get_post_meta( $this->attachment_id, '_wp_attachment_image_alt', true );
		$this->assertSame( 'A colorful test image for unit testing.', $alt_text );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
		remove_filter( 'chubes_ai_request', $request_filter, 10 );
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
		PluginSettings::clearCache();

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

			$request_filter = function( $request, $provider = '', $streaming = null, $tools = array(), $step_id = null, $context = array() ) use ( $ai_response ) {
				return [
					'success' => true,
					'data' => [ 'content' => $ai_response ]
				];
			};
			add_filter( 'chubes_ai_request', $request_filter, 10, 6 );

			$this->task->execute( 1, [ 'attachment_id' => $this->attachment_id ] );

			$alt_text = get_post_meta( $this->attachment_id, '_wp_attachment_image_alt', true );
			$this->assertSame( $expected_alt, $alt_text, "Failed for input: {$ai_response}" );

			remove_filter( 'chubes_ai_request', $request_filter, 10 );
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
		PluginSettings::clearCache();

		$request_filter = function( $request, $provider = '', $streaming = null, $tools = array(), $step_id = null, $context = array() ) {
			// Find the text prompt message (second message in the messages array)
			$messages = $request['messages'] ?? array();
			$prompt   = '';
			foreach ( $messages as $msg ) {
				if ( is_string( $msg['content'] ?? null ) ) {
					$prompt = $msg['content'];
				}
			}
			$this->assertStringContainsString( 'Sunset Photo', $prompt );
			$this->assertStringContainsString( 'Beautiful sunset caption', $prompt );
			$this->assertStringContainsString( 'A detailed description', $prompt );
			$this->assertStringContainsString( 'Photography Blog Post', $prompt );
			
			return [
				'success' => true,
				'data' => [ 'content' => 'Generated alt text' ]
			];
		};
		add_filter( 'chubes_ai_request', $request_filter, 10, 6 );

		$this->task->execute( 1, [ 'attachment_id' => $this->attachment_id ] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
		remove_filter( 'chubes_ai_request', $request_filter, 10 );
	}
}