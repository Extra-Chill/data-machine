<?php
/**
 * PluginSettings Tests
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use DataMachine\Core\PluginSettings;
use WP_UnitTestCase;

class PluginSettingsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'datamachine_settings' );
		PluginSettings::clearCache();
	}

	public function tear_down(): void {
		delete_option( 'datamachine_settings' );
		PluginSettings::clearCache();
		parent::tear_down();
	}

	public function test_update_merges_patch_and_clears_cache(): void {
		update_option( 'datamachine_settings', array( 'default_provider' => 'openai' ) );
		$this->assertSame( 'openai', PluginSettings::get( 'default_provider' ) );

		$updated = PluginSettings::update( array( 'default_model' => 'gpt-4o' ) );

		$this->assertTrue( $updated );
		$this->assertSame( 'gpt-4o', PluginSettings::get( 'default_model' ) );
		$this->assertSame(
			array(
				'default_provider' => 'openai',
				'default_model'    => 'gpt-4o',
			),
			get_option( 'datamachine_settings', array() )
		);
	}

	public function test_redact_for_display_recurses_without_mutating_storage(): void {
		$settings = array(
			'github_pat'                 => 'SENTINEL_TOP_LEVEL_PAT',
			'github_credential_profiles' => array(
				array(
					'id'           => 'profile-1',
					'label'        => 'Release profile',
					'mode'         => 'pat',
					'pat'          => 'SENTINEL_NESTED_PAT',
					'repositories' => array( 'owner/repository' ),
					'credentials'  => (object) array(
						'private_key'      => 'SENTINEL_PRIVATE_KEY',
						'client_secret'    => 'SENTINEL_CLIENT_SECRET',
						'access_token'     => 'SENTINEL_ACCESS_TOKEN',
						'refresh_token'    => 'SENTINEL_REFRESH_TOKEN',
						'password'         => 'SENTINEL_PASSWORD',
						'token_expires_at' => 1234567890,
					),
				),
			),
			'enabled'                    => true,
		);
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();

		$redacted    = PluginSettings::redactForDisplay( '', PluginSettings::all() );
		$profile     = $redacted['github_credential_profiles'][0];
		$credentials = $profile['credentials'];

		$this->assertTrue( '[redacted]' === $redacted['github_pat'], 'Top-level PAT should be redacted.' );
		$this->assertTrue( '[redacted]' === $profile['pat'], 'Nested profile PAT should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->private_key, 'Nested private key should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->client_secret, 'Nested client secret should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->access_token, 'Nested access token should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->refresh_token, 'Nested refresh token should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->password, 'Nested password should be redacted.' );
		$this->assertSame( 'profile-1', $profile['id'] );
		$this->assertSame( 'Release profile', $profile['label'] );
		$this->assertSame( 'pat', $profile['mode'] );
		$this->assertSame( array( 'owner/repository' ), $profile['repositories'] );
		$this->assertSame( 1234567890, $credentials->token_expires_at );
		$this->assertTrue(
			$settings === get_option( 'datamachine_settings', array() ),
			'Display redaction must not mutate stored settings.'
		);

		$encoded   = wp_json_encode( $redacted );
		$sentinels = array(
			'SENTINEL_TOP_LEVEL_PAT',
			'SENTINEL_NESTED_PAT',
			'SENTINEL_PRIVATE_KEY',
			'SENTINEL_CLIENT_SECRET',
			'SENTINEL_ACCESS_TOKEN',
			'SENTINEL_REFRESH_TOKEN',
			'SENTINEL_PASSWORD',
		);
		foreach ( $sentinels as $sentinel ) {
			$this->assertFalse(
				str_contains( $encoded, $sentinel ),
				'Serialized display settings must omit every synthetic secret.'
			);
		}
	}

	public function test_redact_for_display_preserves_empty_secrets_and_safe_scalars(): void {
		$settings = array(
			'profiles' => array(
				array(
					'pat'       => '',
					'token'     => null,
					'enabled'   => false,
					'priority'  => 10,
					'allowlist' => array( 'owner/one', 'owner/two' ),
				),
			),
		);

		$this->assertSame( $settings, PluginSettings::redactForDisplay( '', $settings ) );
	}
}
