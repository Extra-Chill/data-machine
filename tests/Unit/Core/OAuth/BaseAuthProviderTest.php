<?php
/**
 * BaseAuthProvider Tests
 *
 * Tests for auth provider storage using site-level options.
 *
 * @package DataMachine\Tests\Unit\Core\OAuth
 */

namespace DataMachine\Tests\Unit\Core\OAuth;

use DataMachine\Core\OAuth\BaseAuthProvider;
use WP_UnitTestCase;

/**
 * Concrete implementation for testing the abstract BaseAuthProvider.
 */
class TestAuthProvider extends BaseAuthProvider {

	public function get_config_fields(): array {
		return array(
			'api_key' => array(
				'label'    => 'API Key',
				'type'     => 'text',
				'required' => true,
			),
		);
	}

	public function is_authenticated(): bool {
		$account = $this->get_account();
		return ! empty( $account['access_token'] );
	}
}

class BaseAuthProviderTest extends WP_UnitTestCase {

	private TestAuthProvider $provider;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->provider = new TestAuthProvider( 'test_provider' );
	}

	public function tear_down(): void {
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	public function test_get_account_returns_empty_array_when_no_data(): void {
		$result = $this->provider->get_account();

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function test_get_config_returns_empty_array_when_no_data(): void {
		$result = $this->provider->get_config();

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function test_save_account_stores_data(): void {
		$account_data = array(
			'access_token' => 'tok_123',
			'user_id'      => '456',
			'username'     => 'testuser',
		);

		$saved = $this->provider->save_account( $account_data );

		$this->assertTrue( $saved );
		$this->assertSame( $account_data, $this->provider->get_account() );
	}

	public function test_save_config_stores_data(): void {
		$config_data = array(
			'api_key'    => 'key_abc',
			'api_secret' => 'secret_xyz',
		);

		$saved = $this->provider->save_config( $config_data );

		$this->assertTrue( $saved );
		$this->assertSame( $config_data, $this->provider->get_config() );
	}

	public function test_save_account_and_config_are_independent(): void {
		$account = array( 'access_token' => 'tok_123' );
		$config  = array( 'api_key' => 'key_abc' );

		$this->provider->save_account( $account );
		$this->provider->save_config( $config );

		$this->assertSame( $account, $this->provider->get_account() );
		$this->assertSame( $config, $this->provider->get_config() );
	}

	public function test_clear_account_removes_account_data(): void {
		$this->provider->save_account( array( 'access_token' => 'tok_123' ) );

		$cleared = $this->provider->clear_account();

		$this->assertTrue( $cleared );
		$this->assertEmpty( $this->provider->get_account() );
	}

	public function test_clear_account_preserves_config(): void {
		$config = array( 'api_key' => 'key_abc' );
		$this->provider->save_account( array( 'access_token' => 'tok_123' ) );
		$this->provider->save_config( $config );

		$this->provider->clear_account();

		$this->assertSame( $config, $this->provider->get_config() );
	}

	public function test_clear_account_returns_true_when_no_data(): void {
		$this->assertTrue( $this->provider->clear_account() );
	}

	public function test_providers_are_isolated_by_slug(): void {
		$provider_a = new TestAuthProvider( 'twitter' );
		$provider_b = new TestAuthProvider( 'reddit' );

		$provider_a->save_account( array( 'user' => 'twitter_user' ) );
		$provider_b->save_account( array( 'user' => 'reddit_user' ) );

		$this->assertSame( 'twitter_user', $provider_a->get_account()['user'] );
		$this->assertSame( 'reddit_user', $provider_b->get_account()['user'] );
	}

	public function test_is_authenticated_returns_true_with_token(): void {
		$this->provider->save_account( array( 'access_token' => 'tok_123' ) );

		$this->assertTrue( $this->provider->is_authenticated() );
	}

	public function test_is_authenticated_returns_false_without_token(): void {
		$this->assertFalse( $this->provider->is_authenticated() );
	}

	public function test_is_configured_returns_true_with_config(): void {
		$this->provider->save_config( array( 'api_key' => 'key_abc' ) );

		$this->assertTrue( $this->provider->is_configured() );
	}

	public function test_is_configured_returns_false_without_config(): void {
		$this->assertFalse( $this->provider->is_configured() );
	}

	public function test_data_stored_via_site_option(): void {
		$this->provider->save_account( array( 'access_token' => 'tok_123' ) );

		$raw = get_site_option( 'datamachine_auth_data', array() );

		$this->assertArrayHasKey( 'test_provider', $raw );
		$this->assertArrayHasKey( 'account', $raw['test_provider'] );
		$this->assertSame( 'tok_123', $raw['test_provider']['account']['access_token'] );
	}

	public function test_get_account_details_returns_account(): void {
		$account = array( 'access_token' => 'tok_123', 'username' => 'testuser' );
		$this->provider->save_account( $account );

		$this->assertSame( $account, $this->provider->get_account_details() );
	}

	public function test_callback_url_uses_provider_slug(): void {
		$url = $this->provider->get_callback_url();

		$this->assertStringContainsString( '/datamachine-auth/test_provider/', $url );
	}
}
