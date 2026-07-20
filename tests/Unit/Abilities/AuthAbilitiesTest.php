<?php
/**
 * AuthAbilities Tests
 *
 * Tests for authentication-related abilities.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Core\OAuth\BaseAuthProvider;
use WP_UnitTestCase;

class AuthAbilitiesAccountScopeProvider extends BaseAuthProvider {

	public function get_config_fields(): array {
		return array();
	}

	public function is_authenticated(): bool {
		return false;
	}
}

class AuthAbilitiesConfigProvider extends BaseAuthProvider {

	public function get_config_fields(): array {
		return array(
			'session_cookie' => array(
				'label'    => 'Session Cookie',
				'type'     => 'password',
				'required' => true,
			),
			'cookie_jar'     => array(
				'label' => 'Cookie Jar',
				'type'  => 'textarea',
			),
			'label'          => array(
				'label' => 'Label',
				'type'  => 'text',
			),
		);
	}

	public function is_authenticated(): bool {
		return false;
	}
}

class AuthAbilitiesTest extends WP_UnitTestCase {

	private AuthAbilities $auth_abilities;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		AuthAbilities::clearCache();
		HandlerAbilities::clearCache();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->auth_abilities = new AuthAbilities();
	}

	public function tear_down(): void {
		delete_site_option( 'datamachine_auth_data' );
		remove_all_filters( 'datamachine_auth_providers' );
		remove_all_filters( 'datamachine_handlers' );
		AuthAbilities::clearCache();
		HandlerAbilities::clearCache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_get_auth_status_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/get-auth-status' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/get-auth-status', $ability->get_name() );
	}

	public function test_disconnect_auth_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/disconnect-auth' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/disconnect-auth', $ability->get_name() );
	}

	public function test_save_auth_config_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/save-auth-config' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/save-auth-config', $ability->get_name() );
	}

	public function test_get_auth_status_returns_error_for_missing_handler_slug(): void {
		$result = $this->auth_abilities->executeGetAuthStatus(
			array( 'handler_slug' => '' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'required', $result['error'] );
	}

	public function test_get_auth_status_returns_error_for_unknown_handler(): void {
		$result = $this->auth_abilities->executeGetAuthStatus(
			array( 'handler_slug' => 'nonexistent_handler' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_disconnect_auth_returns_error_for_missing_handler_slug(): void {
		$result = $this->auth_abilities->executeDisconnectAuth(
			array( 'handler_slug' => '' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'required', $result['error'] );
	}

	public function test_disconnect_auth_returns_error_for_unknown_handler(): void {
		$result = $this->auth_abilities->executeDisconnectAuth(
			array( 'handler_slug' => 'nonexistent_handler' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_save_auth_config_returns_error_for_missing_handler_slug(): void {
		$result = $this->auth_abilities->executeSaveAuthConfig(
			array(
				'handler_slug' => '',
				'config'       => array( 'key' => 'value' ),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'required', $result['error'] );
	}

	public function test_save_auth_config_returns_error_for_unknown_handler(): void {
		$result = $this->auth_abilities->executeSaveAuthConfig(
			array(
				'handler_slug' => 'nonexistent_handler',
				'config'       => array( 'key' => 'value' ),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_save_auth_config_preserves_opaque_credential_fields(): void {
		$provider = new AuthAbilitiesConfigProvider( 'config_provider' );
		$this->registerConfigProvider( $provider );
		$cookie = 'tk_or=%22https%3A%2F%2Fwww.google.com%2F%22;wporg_sec=abc%7Cdef$o3$g0';

		$result = $this->auth_abilities->executeSaveAuthConfig(
			array(
				'handler_slug' => 'config_handler',
				'config'       => array(
					'session_cookie' => $cookie,
					'cookie_jar'     => $cookie,
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( $cookie, $provider->get_config()['session_cookie'] );
		$this->assertSame( $cookie, $provider->get_config()['cookie_jar'] );
	}

	public function test_save_auth_config_sanitizes_text_fields(): void {
		$provider = new AuthAbilitiesConfigProvider( 'config_provider' );
		$this->registerConfigProvider( $provider );
		$value = " <strong>Example</strong>\n";

		$result = $this->auth_abilities->executeSaveAuthConfig(
			array(
				'handler_slug' => 'config_handler',
				'config'       => array(
					'session_cookie' => 'required-cookie',
					'label'          => $value,
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( sanitize_text_field( $value ), $provider->get_config()['label'] );
	}

	public function test_set_auth_token_preserves_opaque_token_value(): void {
		$provider = new AuthAbilitiesAccountScopeProvider( 'scope_provider' );
		$this->registerAccountScopeProvider( $provider );
		$token = 'tk_or=%22https%3A%2F%2Fwww.google.com%2F%22%7C$o3$g0';

		$result = $this->auth_abilities->executeSetAuthToken(
			array(
				'handler_slug' => 'scope_handler',
				'account_data' => array( 'access_token' => $token ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( $token, $provider->get_account_for_user( get_current_user_id() )['access_token'] );
	}

	public function test_set_auth_token_saves_user_account_without_site_fallback(): void {
		$provider = new AuthAbilitiesAccountScopeProvider( 'scope_provider' );
		$this->registerAccountScopeProvider( $provider );

		$result = $this->auth_abilities->executeSetAuthToken(
			array(
				'handler_slug' => 'scope_handler',
				'user_id'      => 42,
				'account_data' => array( 'access_token' => 'tok_user_42' ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'tok_user_42', $provider->get_account_for_user( 42 )['access_token'] );
		$this->assertNull( $provider->get_site_account() );
		$this->assertNull( $provider->get_account_for_user( 99 ) );
	}

	public function test_set_auth_token_prefers_agent_account_when_user_and_agent_are_present(): void {
		$provider = new AuthAbilitiesAccountScopeProvider( 'scope_provider' );
		$this->registerAccountScopeProvider( $provider );

		$result = $this->auth_abilities->executeSetAuthToken(
			array(
				'handler_slug' => 'scope_handler',
				'user_id'      => 42,
				'agent_id'     => 303,
				'account_data' => array( 'access_token' => 'tok_agent_303' ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'tok_agent_303', $provider->get_account_for_agent( 303 )['access_token'] );
		$this->assertNull( $provider->get_account_for_user( 42 ) );
		$this->assertNull( $provider->get_site_account() );
	}

	private function registerAccountScopeProvider( AuthAbilitiesAccountScopeProvider $provider ): void {
		add_filter(
			'datamachine_auth_providers',
			function ( array $providers ) use ( $provider ): array {
				$providers['scope_provider'] = $provider;
				return $providers;
			}
		);
		add_filter(
			'datamachine_handlers',
			function ( array $handlers ): array {
				$handlers['scope_handler'] = array(
					'slug'              => 'scope_handler',
					'requires_auth'     => true,
					'auth_provider_key' => 'scope_provider',
				);
				return $handlers;
			}
		);

		AuthAbilities::clearCache();
		HandlerAbilities::clearCache();
	}

	private function registerConfigProvider( AuthAbilitiesConfigProvider $provider ): void {
		add_filter(
			'datamachine_auth_providers',
			function ( array $providers ) use ( $provider ): array {
				$providers['config_provider'] = $provider;
				return $providers;
			}
		);
		add_filter(
			'datamachine_handlers',
			function ( array $handlers ): array {
				$handlers['config_handler'] = array(
					'slug'              => 'config_handler',
					'requires_auth'     => true,
					'auth_provider_key' => 'config_provider',
				);
				return $handlers;
			}
		);

		AuthAbilities::clearCache();
		HandlerAbilities::clearCache();
	}

	public function test_permission_callback(): void {
		wp_set_current_user( 0 );
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$ability = wp_get_ability( 'datamachine/get-auth-status' );
		$this->assertNotNull( $ability );

		$result = $ability->execute(
			array( 'handler_slug' => 'test_handler' )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}
}
