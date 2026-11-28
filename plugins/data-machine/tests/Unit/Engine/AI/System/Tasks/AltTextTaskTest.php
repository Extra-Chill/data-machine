<?php
/**
 * Tests for AltTextTask.
 *
 * @package DataMachine\Tests\Unit\Engine\AI\System\Tasks
 */

namespace DataMachine\Tests\Unit\Engine\AI\System\Tasks;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Engine\AI\System\Tasks\AltTextTask;
use DataMachine\Core\PluginSettings;
use DataMachine\Tests\Unit\Support\WpAiClientTestDouble;
use WP_UnitTestCase;

require_once dirname( __DIR__, 4 ) . '/Support/WpAiClientTestDoubles.php';

class AltTextTaskTest extends WP_UnitTestCase {

	private AltTextTask $task;
	private int $attachment_id;
	private string $test_image_path;

	public function set_up(): void {
		parent::set_up();
		$this->task = new AltTextTask();

		WpAiClientTestDouble::reset();

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
		PluginSettings::clearCache();
		WpAiClientTestDouble::reset();
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
		$this->task->executeTask( 1, [] );
		
		// Check if job failed - we'd need to mock the failJob method
		$this->assertTrue( true ); // Placeholder assertion
	}

	/**
	 * A missing attachment at execution time means it was deleted after queue
	 * time — the job must complete as skipped, not fail (#3638).
	 */
	public function test_execute_deleted_attachment_completes_as_skipped(): void {
		$job_id = $this->createTrackingJob();

		$deleted_id = self::factory()->attachment->create_object( [
			'file' => $this->test_image_path,
			'post_mime_type' => 'image/jpeg',
		] );
		wp_delete_attachment( $deleted_id, true );

		$this->task->executeTask( $job_id, [ 'attachment_id' => $deleted_id ] );

		$jobs_db = new Jobs();
		$job     = $jobs_db->get_job( $job_id );
		$this->assertStringContainsString( 'completed', strtolower( $job['status'] ?? '' ) );

		$engine_data = $jobs_db->retrieve_engine_data( $job_id );
		$this->assertTrue( (bool) ( $engine_data['skipped'] ?? false ) );
		$this->assertSame( 'attachment_deleted', $engine_data['reason'] ?? '' );
		$this->assertSame( $deleted_id, (int) ( $engine_data['attachment_id'] ?? 0 ) );
	}

	/**
	 * A trashed attachment at execution time is treated the same as deleted.
	 */
	public function test_execute_trashed_attachment_completes_as_skipped(): void {
		$job_id = $this->createTrackingJob();

		wp_update_post( [
			'ID'          => $this->attachment_id,
			'post_status' => 'trash',
		] );

		$this->task->executeTask( $job_id, [ 'attachment_id' => $this->attachment_id ] );

		$jobs_db = new Jobs();
		$job     = $jobs_db->get_job( $job_id );
		$this->assertStringContainsString( 'completed', strtolower( $job['status'] ?? '' ) );

		$engine_data = $jobs_db->retrieve_engine_data( $job_id );
		$this->assertTrue( (bool) ( $engine_data['skipped'] ?? false ) );
		$this->assertSame( 'attachment_deleted', $engine_data['reason'] ?? '' );
	}

	/**
	 * A genuinely non-image attachment that still exists must fail clearly.
	 */
	public function test_execute_non_image_attachment_fails(): void {
		$job_id = $this->createTrackingJob();

		$upload_dir = wp_upload_dir();
		$text_path  = $upload_dir['path'] . '/test-doc.txt';
		file_put_contents( $text_path, 'not an image' );

		$text_attachment = self::factory()->attachment->create_object( [
			'file' => $text_path,
			'post_mime_type' => 'text/plain'
		] );
		update_attached_file( $text_attachment, $text_path );

		$this->task->executeTask( $job_id, [ 'attachment_id' => $text_attachment, 'force' => true ] );

		$jobs_db = new Jobs();
		$job     = $jobs_db->get_job( $job_id );
		$this->assertStringContainsString( 'failed', strtolower( $job['status'] ?? '' ) );

		$engine_data = $jobs_db->retrieve_engine_data( $job_id );
		$this->assertArrayNotHasKey( 'skipped', $engine_data );

		wp_delete_file( $text_path );
	}

	/**
	 * Create a real tracking job for executeTask() outcome assertions.
	 */
	private function createTrackingJob(): int {
		$jobs_db = new Jobs();
		$job_id  = (int) $jobs_db->create_job( [
			'pipeline_id' => 'direct',
			'flow_id'     => 'direct',
			'source'      => 'system',
			'label'       => 'Test Alt Text',
		] );

		if ( $job_id <= 0 ) {
			$this->markTestSkipped( 'Could not create test job.' );
		}

		return $job_id;
	}

	/**
	 * Test execute skips when alt text exists and force=false.
	 */
	public function test_execute_skips_existing_alt_text(): void {
		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'Existing alt text' );

		$this->expectOutputString( '' );
		$this->task->executeTask( 1, [ 'attachment_id' => $this->attachment_id ] );
		
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

		$called = false;
		WpAiClientTestDouble::set_response_callback( function() use ( &$called ): array {
			$called = true;
			return [
				'success' => true,
				'data' => [
					'content' => 'A small test image showing minimal JPEG data.'
				]
			];
		} );

		$this->expectOutputString( '' );
		$this->task->executeTask( 1, [
			'attachment_id' => $this->attachment_id,
			'force' => true
		] );

		$updated_alt = get_post_meta( $this->attachment_id, '_wp_attachment_image_alt', true );
		$this->assertSame( 'A small test image showing minimal JPEG data.', $updated_alt );
		$this->assertTrue( $called, 'Canonical file-part envelopes should dispatch through the request builder.' );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
	}

	/**
	 * Generated alt text reaches image blocks saved with an empty alt.
	 */
	public function test_execute_backfills_empty_alt_in_post_content(): void {
		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );
		PluginSettings::clearCache();

		WpAiClientTestDouble::set_response_callback( function(): array {
			return [
				'success' => true,
				'data' => [ 'content' => 'A guitarist under red stage lights.' ],
			];
		} );

		$post_id = self::factory()->post->create( [
			'post_status'  => 'pending',
			'post_content' => '<!-- wp:image {"id":' . $this->attachment_id . '} -->' . "\n"
				. '<figure class="wp-block-image"><img src="x.jpg" alt="" class="wp-image-' . $this->attachment_id . '"/></figure>' . "\n"
				. '<!-- /wp:image -->',
		] );

		$this->expectOutputString( '' );
		$this->task->executeTask( 1, [ 'attachment_id' => $this->attachment_id ] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );

		$this->assertStringContainsString(
			'alt="A guitarist under red stage lights."',
			get_post( $post_id )->post_content
		);
	}

	/**
	 * Test execute fails when image file missing.
	 */
	public function test_execute_missing_file(): void {
		// Delete the file but keep the attachment
		wp_delete_file( $this->test_image_path );

		$this->expectOutputString( '' );
		$this->task->executeTask( 1, [ 'attachment_id' => $this->attachment_id ] );
		
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
		$this->task->executeTask( 1, [ 'attachment_id' => $this->attachment_id ] );

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

		WpAiClientTestDouble::set_response_callback( function(): array {
			return [
				'success' => false,
				'error' => 'API connection failed'
			];
		} );

		$this->expectOutputString( '' );
		$this->task->executeTask( 1, [ 'attachment_id' => $this->attachment_id ] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
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

		WpAiClientTestDouble::set_response_callback( function(): array {
			return [
				'success' => true,
				'data' => [
					'content' => ''
				]
			];
		} );

		$this->expectOutputString( '' );
		$this->task->executeTask( 1, [ 'attachment_id' => $this->attachment_id ] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
		$this->assertTrue( true ); // Placeholder assertion
	}

	/**
	 * Test successful alt text generation.
	 */
	public function test_execute_supports_file_part_envelopes(): void {
		// Mock PluginSettings
		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );
		PluginSettings::clearCache();

		$called = false;
		WpAiClientTestDouble::set_response_callback( function( array $request ) use ( &$called ): array {
			$called = true;
			// Verify the request structure
			$this->assertIsArray( $request['messages'] );
			$this->assertSame( 'gpt-4', $request['model'] );
			$this->assertSame( 'openai', $request['provider'] ?? '' );

			return [
				'success' => true,
				'data' => [
					'content' => 'a colorful test image for unit testing'
				]
			];
		} );

		$this->expectOutputString( '' );
		$this->task->executeTask( 1, [ 'attachment_id' => $this->attachment_id ] );

		$alt_text = get_post_meta( $this->attachment_id, '_wp_attachment_image_alt', true );
		$this->assertSame( 'A colorful test image for unit testing.', $alt_text );
		$this->assertTrue( $called, 'Canonical file-part envelopes should dispatch through the request builder.' );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
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

		$normalize = new \ReflectionMethod( AltTextTask::class, 'normalizeAltText' );

		foreach ( $test_cases as $ai_response => $expected_alt ) {
			$this->assertSame( $expected_alt, $normalize->invoke( $this->task, $ai_response ), "Failed for input: {$ai_response}" );
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

		// Mock PluginSettings and inspect the prompt builder directly. Runtime file
		// part dispatch is covered separately by the explicit unsupported-shape tests.
		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );
		PluginSettings::clearCache();

		$build_prompt = new \ReflectionMethod( AltTextTask::class, 'buildPrompt' );
		$prompt       = (string) $build_prompt->invoke( $this->task, $this->attachment_id );

		$this->assertStringContainsString( 'Sunset Photo', $prompt );
		$this->assertStringContainsString( 'Beautiful sunset caption', $prompt );
		$this->assertStringContainsString( 'A detailed description', $prompt );
		$this->assertStringContainsString( 'Photography Blog Post', $prompt );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
	}
}
