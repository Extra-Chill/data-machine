<?php
/**
 * Tests for AltTextAbilities.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Media\AltTextAbilities;
use DataMachine\Core\PluginSettings;
use WP_UnitTestCase;

class AltTextAbilitiesTest extends WP_UnitTestCase {

	private AltTextAbilities $abilities;
	private int $test_image_id;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$this->abilities = new AltTextAbilities();

		// Create test image attachment
		$this->test_image_id = self::factory()->attachment->create_object( [
			'file' => 'test-image.jpg',
			'post_mime_type' => 'image/jpeg',
			'post_title' => 'Test Image'
		] );
	}

	public function tear_down(): void {
		PluginSettings::clearCache();
		parent::tear_down();
	}

	/**
	 * Test generate-alt-text ability registration.
	 */
	public function test_generate_alt_text_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/generate-alt-text' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/generate-alt-text', $ability->get_name() );
	}

	/**
	 * Test diagnose-alt-text ability registration.
	 */
	public function test_diagnose_alt_text_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/diagnose-alt-text' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/diagnose-alt-text', $ability->get_name() );
	}

	/**
	 * Test generateAltText with missing provider/model config.
	 */
	public function test_generate_alt_text_missing_config(): void {
		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => '',
				'default_model' => ''
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );
		PluginSettings::clearCache();

		$result = AltTextAbilities::generateAltText( [
			'attachment_id' => $this->test_image_id
		] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['queued_count'] );
		$this->assertSame( [], $result['attachment_ids'] );
		$this->assertStringContainsString( 'No default AI provider', $result['message'] );
		$this->assertStringContainsString( 'Configure default_provider', $result['error'] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
	}

	/**
	 * Test generateAltText with missing attachment_id and post_id.
	 */
	public function test_generate_alt_text_missing_params(): void {
		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );
		PluginSettings::clearCache();

		$result = AltTextAbilities::generateAltText( [] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['queued_count'] );
		$this->assertStringContainsString( 'No attachment_id or post_id provided', $result['message'] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
	}

	/**
	 * Test generateAltText with single attachment_id schedules work.
	 */
	public function test_generate_alt_text_single_attachment(): void {
		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );
		PluginSettings::clearCache();

		$result = AltTextAbilities::generateAltText( [
			'attachment_id' => $this->test_image_id
		] );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['queued_count'] );
		$this->assertSame( [ $this->test_image_id ], $result['attachment_ids'] );
		$this->assertStringContainsString( '1 attachment', $result['message'] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
	}

	/**
	 * Test generateAltText with force=true includes images with existing alt text.
	 */
	public function test_generate_alt_text_force_regeneration(): void {
		update_post_meta( $this->test_image_id, '_wp_attachment_image_alt', 'Existing alt text' );

		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );
		PluginSettings::clearCache();

		$result = AltTextAbilities::generateAltText( [
			'attachment_id' => $this->test_image_id,
			'force' => true
		] );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['queued_count'] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
	}

	/**
	 * Test generateAltText with post_id finds attached images.
	 */
	public function test_generate_alt_text_with_post_id(): void {
		$post_id = self::factory()->post->create();
		wp_update_post( [
			'ID' => $this->test_image_id,
			'post_parent' => $post_id
		] );

		$featured_image_id = self::factory()->attachment->create_object( [
			'file' => 'featured.jpg',
			'post_mime_type' => 'image/jpeg'
		] );
		set_post_thumbnail( $post_id, $featured_image_id );

		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );
		PluginSettings::clearCache();

		$result = AltTextAbilities::generateAltText( [
			'post_id' => $post_id
		] );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['queued_count'] );
		$this->assertContains( $this->test_image_id, $result['attachment_ids'] );
		$this->assertContains( $featured_image_id, $result['attachment_ids'] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
	}

	/**
	 * Test generateAltText skips when alt text already exists.
	 */
	public function test_generate_alt_text_skips_existing(): void {
		update_post_meta( $this->test_image_id, '_wp_attachment_image_alt', 'Existing alt text' );

		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );
		PluginSettings::clearCache();

		$result = AltTextAbilities::generateAltText( [
			'attachment_id' => $this->test_image_id
		] );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['queued_count'] );
		$this->assertStringContainsString( 'No attachments queued', $result['message'] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
	}

	/**
	 * Test generateAltText with no eligible attachments.
	 */
	public function test_generate_alt_text_no_eligible_attachments(): void {
		$settings_filter = function( $pre_option ) {
			return [
				'default_provider' => 'openai',
				'default_model' => 'gpt-4'
			];
		};
		add_filter( 'pre_option_datamachine_settings', $settings_filter, 10, 1 );
		PluginSettings::clearCache();

		$result = AltTextAbilities::generateAltText( [
			'post_id' => 99999
		] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['queued_count'] );
		$this->assertStringContainsString( 'No attachments found', $result['message'] );

		remove_filter( 'pre_option_datamachine_settings', $settings_filter, 10 );
	}

	/**
	 * Test diagnoseAltText returns coverage statistics.
	 */
	public function test_diagnose_alt_text(): void {
		$image_with_alt = self::factory()->attachment->create_object( [
			'file' => 'image-with-alt.jpg',
			'post_mime_type' => 'image/jpeg'
		] );
		update_post_meta( $image_with_alt, '_wp_attachment_image_alt', 'Has alt text' );

		$image_without_alt = self::factory()->attachment->create_object( [
			'file' => 'image-without-alt.png',
			'post_mime_type' => 'image/png'
		] );

		$result = AltTextAbilities::diagnoseAltText( [] );

		$this->assertTrue( $result['success'] );
		$this->assertIsInt( $result['total_images'] );
		$this->assertIsInt( $result['missing_alt_count'] );
		$this->assertIsArray( $result['by_mime_type'] );
		$this->assertGreaterThanOrEqual( 3, $result['total_images'] );
		$this->assertGreaterThanOrEqual( 1, $result['missing_alt_count'] );

		$mime_types = array_column( $result['by_mime_type'], 'mime_type' );
		$this->assertContains( 'image/jpeg', $mime_types );
	}

	/**
	 * Test permission callback denies access for non-admin users.
	 */
	public function test_permission_callback(): void {
		wp_set_current_user( 0 );
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$ability = wp_get_ability( 'datamachine/generate-alt-text' );
		$this->assertNotNull( $ability );

		$result = $ability->execute( [
			'attachment_id' => $this->test_image_id
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
