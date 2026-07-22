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
					'id'            => 'profile-1',
					'label'         => 'Release profile',
					'mode'          => 'pat',
					'pat'           => 'SENTINEL_NESTED_PAT',
					'repositories'  => array( 'owner/repository' ),
					'credential_id' => 'credential-1',
					'profile_data'  => (object) array(
						'private_key'         => 'SENTINEL_PRIVATE_KEY',
						'client_secret'       => 'SENTINEL_CLIENT_SECRET',
						'access_token'        => 'SENTINEL_ACCESS_TOKEN',
						'refresh_token'       => 'SENTINEL_REFRESH_TOKEN',
						'password'            => 'SENTINEL_PASSWORD',
						'authorization_header' => 'SENTINEL_AUTHORIZATION_HEADER',
						'token_value'         => 'SENTINEL_TOKEN_VALUE',
						'secret_value'        => 'SENTINEL_SECRET_VALUE',
						'credential_data'     => 'SENTINEL_CREDENTIAL_DATA',
						'access_tokens'       => array( 'SENTINEL_ACCESS_TOKENS' ),
						'clientSecret'           => 'SENTINEL_CAMEL_CLIENT_SECRET',
						'apiKey'                 => 'SENTINEL_CAMEL_API_KEY',
						'apikey'                 => 'SENTINEL_APIKEY_ALIAS',
						'api_key_value'          => 'SENTINEL_API_KEY_VALUE',
						'passwd'                 => 'SENTINEL_PASSWD_ALIAS',
						'auth'                   => 'SENTINEL_AUTH_ALIAS',
						'authentication'         => 'SENTINEL_AUTHENTICATION_ALIAS',
						'private_key_passphrase' => 'SENTINEL_PRIVATE_KEY_PASSPHRASE',
						'signing_key_value'      => 'SENTINEL_SIGNING_KEY_VALUE',
						'secret_map'             => array( 'SENTINEL_SECRET_MAP_KEY' => 'value' ),
						'token_expires_at'       => 1234567890,
						'token_type'             => 'Bearer',
					),
					'credentials'        => (object) array( 'value' => 'SENTINEL_GENERIC_CREDENTIALS_OBJECT' ),
					'backup_credentials' => array( 'value' => 'SENTINEL_GENERIC_CREDENTIALS_MAP' ),
				),
			),
			'enabled'                    => true,
		);
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();

		$redacted    = PluginSettings::redactForDisplay( '', PluginSettings::all() );
		$profile     = $redacted['github_credential_profiles'][0];
		$credentials = $profile['profile_data'];

		$this->assertTrue( '[redacted]' === $redacted['github_pat'], 'Top-level PAT should be redacted.' );
		$this->assertTrue( '[redacted]' === $profile['pat'], 'Nested profile PAT should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->private_key, 'Nested private key should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->client_secret, 'Nested client secret should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->access_token, 'Nested access token should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->refresh_token, 'Nested refresh token should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->password, 'Nested password should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->authorization_header, 'Authorization variants should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->token_value, 'Token variants should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->secret_value, 'Secret variants should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->credential_data, 'Credential variants should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->access_tokens, 'Plural token variants should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->clientSecret, 'Camel-case client secrets should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->apiKey, 'Camel-case API keys should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->apikey, 'Compact API key aliases should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->api_key_value, 'API key value variants should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->passwd, 'Password aliases should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->auth, 'Auth aliases should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->authentication, 'Authentication aliases should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->private_key_passphrase, 'Private-key passphrases should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->signing_key_value, 'Signing-key variants should be redacted.' );
		$this->assertTrue( '[redacted]' === $credentials->secret_map, 'Secret containers should collapse before map keys serialize.' );
		$this->assertTrue( '[redacted]' === $profile['credentials'], 'Generic credential objects should collapse.' );
		$this->assertTrue( '[redacted]' === $profile['backup_credentials'], 'Generic credential maps should collapse.' );
		$this->assertSame( 'profile-1', $profile['id'] );
		$this->assertSame( 'Release profile', $profile['label'] );
		$this->assertSame( 'pat', $profile['mode'] );
		$this->assertSame( array( 'owner/repository' ), $profile['repositories'] );
		$this->assertSame( 'credential-1', $profile['credential_id'] );
		$this->assertSame( 1234567890, $credentials->token_expires_at );
		$this->assertSame( 'Bearer', $credentials->token_type );
		$this->assertTrue(
			$settings === get_option( 'datamachine_settings', array() ),
			'Display redaction must not mutate stored settings.'
		);

		$encoded   = (string) wp_json_encode( $redacted );
		$sentinels = array(
			'SENTINEL_TOP_LEVEL_PAT',
			'SENTINEL_NESTED_PAT',
			'SENTINEL_PRIVATE_KEY',
			'SENTINEL_CLIENT_SECRET',
			'SENTINEL_ACCESS_TOKEN',
			'SENTINEL_REFRESH_TOKEN',
			'SENTINEL_PASSWORD',
			'SENTINEL_AUTHORIZATION_HEADER',
			'SENTINEL_TOKEN_VALUE',
			'SENTINEL_SECRET_VALUE',
			'SENTINEL_CREDENTIAL_DATA',
			'SENTINEL_ACCESS_TOKENS',
			'SENTINEL_CAMEL_CLIENT_SECRET',
			'SENTINEL_CAMEL_API_KEY',
			'SENTINEL_APIKEY_ALIAS',
			'SENTINEL_API_KEY_VALUE',
			'SENTINEL_PASSWD_ALIAS',
			'SENTINEL_AUTH_ALIAS',
			'SENTINEL_AUTHENTICATION_ALIAS',
			'SENTINEL_PRIVATE_KEY_PASSPHRASE',
			'SENTINEL_SIGNING_KEY_VALUE',
			'SENTINEL_SECRET_MAP_KEY',
			'SENTINEL_GENERIC_CREDENTIALS_OBJECT',
			'SENTINEL_GENERIC_CREDENTIALS_MAP',
		);
		foreach ( $sentinels as $sentinel ) {
			$this->assertFalse(
				str_contains( $encoded, $sentinel ),
				'Serialized display settings must omit every synthetic secret.'
			);
		}
	}

	public function test_redact_for_display_bounds_cyclic_objects(): void {
		$profile        = new \stdClass();
		$profile->label = 'Cycle-safe profile';
		$profile->self  = $profile;

		$redacted = PluginSettings::redactForDisplay( 'profile', $profile );

		$this->assertSame( 'Cycle-safe profile', $redacted->label );
		$this->assertTrue( '[redacted]' === $redacted->self, 'Object cycles should terminate with a redacted marker.' );
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
