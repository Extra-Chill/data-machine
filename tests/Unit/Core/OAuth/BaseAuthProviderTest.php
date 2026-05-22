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

	public function test_save_account_replaces_existing_account_data(): void {
		$this->provider->save_account(
			array(
				'access_token'  => 'tok_original',
				'refresh_token' => 'refresh_original',
				'scope'         => 'read write',
			)
		);

		$this->assertTrue( $this->provider->save_account( array( 'access_token' => 'tok_reauth' ) ) );

		$this->assertSame( array( 'access_token' => 'tok_reauth' ), $this->provider->get_account() );
	}

	public function test_update_account_merges_existing_account_data(): void {
		$this->provider->save_account(
			array(
				'access_token'  => 'tok_original',
				'refresh_token' => 'refresh_original',
				'scope'         => 'read write',
			)
		);

		$this->assertTrue( $this->provider->update_account( array( 'access_token' => 'tok_refreshed' ) ) );

		$this->assertSame(
			array(
				'access_token'  => 'tok_refreshed',
				'refresh_token' => 'refresh_original',
				'scope'         => 'read write',
			),
			$this->provider->get_account()
		);
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
		// Sensitive fields are encrypted at rest — raw stored value carries the envelope prefix.
		$this->assertStringStartsWith( 'dm:enc:v1:', $raw['test_provider']['account']['access_token'] );
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

	public function test_callback_url_is_filterable(): void {
		$custom_url = 'https://example.com/oauth/callback';

		add_filter(
			'datamachine_oauth_callback_url',
			function ( $url, $slug ) use ( $custom_url ) {
				if ( 'test_provider' === $slug ) {
					return $custom_url;
				}
				return $url;
			},
			10,
			2
		);

		$this->assertSame( $custom_url, $this->provider->get_callback_url() );

		remove_all_filters( 'datamachine_oauth_callback_url' );
	}

	public function test_callback_url_filter_passes_provider_slug(): void {
		$received_slug = null;

		add_filter(
			'datamachine_oauth_callback_url',
			function ( $url, $slug ) use ( &$received_slug ) {
				$received_slug = $slug;
				return $url;
			},
			10,
			2
		);

		$this->provider->get_callback_url();

		$this->assertSame( 'test_provider', $received_slug );

		remove_all_filters( 'datamachine_oauth_callback_url' );
	}

	public function test_callback_url_filter_does_not_affect_other_providers(): void {
		add_filter(
			'datamachine_oauth_callback_url',
			function ( $url, $slug ) {
				if ( 'other_provider' === $slug ) {
					return 'https://example.com/other/callback';
				}
				return $url;
			},
			10,
			2
		);

		$url = $this->provider->get_callback_url();

		$this->assertStringContainsString( '/datamachine-auth/test_provider/', $url );

		remove_all_filters( 'datamachine_oauth_callback_url' );
	}

	// -------------------------------------------------------------------------
	// Site-wide account API
	// -------------------------------------------------------------------------

	public function test_get_site_account_returns_null_when_no_account(): void {
		$this->assertNull( $this->provider->get_site_account() );
	}

	public function test_save_site_account_round_trip(): void {
		$account = array(
			'access_token' => 'tok_site',
			'username'     => 'bot-account',
			'scope'        => 'read write',
		);

		$this->assertTrue( $this->provider->save_site_account( $account ) );
		$this->assertSame( $account, $this->provider->get_site_account() );
	}

	public function test_update_site_account_merges_existing_account_data(): void {
		$this->provider->save_site_account(
			array(
				'access_token'  => 'tok_site',
				'refresh_token' => 'refresh_site',
				'scope'         => 'read write',
			)
		);

		$this->assertTrue( $this->provider->update_site_account( array( 'access_token' => 'tok_site_refreshed' ) ) );

		$this->assertSame(
			array(
				'access_token'  => 'tok_site_refreshed',
				'refresh_token' => 'refresh_site',
				'scope'         => 'read write',
			),
			$this->provider->get_site_account()
		);
	}

	public function test_site_account_shares_legacy_site_slot(): void {
		$this->provider->save_site_account( array( 'access_token' => 'tok_site' ) );

		$this->assertSame( 'tok_site', $this->provider->get_account()['access_token'] );

		$this->provider->save_account( array( 'access_token' => 'tok_legacy' ) );

		$this->assertSame( 'tok_legacy', $this->provider->get_site_account()['access_token'] );
	}

	public function test_delete_site_account_removes_only_site_account(): void {
		$this->provider->save_site_account( array( 'access_token' => 'tok_site' ) );
		$this->provider->save_account_for_user( 42, array( 'access_token' => 'tok_user' ) );

		$this->assertTrue( $this->provider->delete_site_account() );

		$this->assertNull( $this->provider->get_site_account() );
		$this->assertSame( 'tok_user', $this->provider->get_account_for_user( 42 )['access_token'] );
	}

	public function test_delete_site_account_is_idempotent(): void {
		$this->assertTrue( $this->provider->delete_site_account() );
	}

	public function test_site_account_methods_do_not_consult_scope_policy(): void {
		add_filter(
			'datamachine_auth_scope_policy',
			function () {
				return BaseAuthProvider::AUTH_SCOPE_USER;
			}
		);

		wp_set_current_user( self::factory()->user->create() );

		$this->provider->save_site_account( array( 'access_token' => 'tok_site' ) );
		$this->provider->save_account_for_user( get_current_user_id(), array( 'access_token' => 'tok_user' ) );

		$this->assertSame( 'tok_site', $this->provider->get_site_account()['access_token'] );
		$this->assertSame( 'tok_user', $this->provider->get_account()['access_token'] );

		remove_all_filters( 'datamachine_auth_scope_policy' );
		wp_set_current_user( 0 );
	}

	// -------------------------------------------------------------------------
	// Policy-resolved account context API
	// -------------------------------------------------------------------------

	public function test_get_account_for_context_returns_null_when_no_account(): void {
		$this->assertNull( $this->provider->get_account_for_context() );
	}

	public function test_get_account_for_context_reads_site_account_by_default(): void {
		$this->provider->save_site_account( array( 'access_token' => 'tok_site' ) );

		$this->assertSame( 'tok_site', $this->provider->get_account_for_context()['access_token'] );
	}

	public function test_get_account_for_context_preserves_site_policy_for_user_context(): void {
		$this->provider->save_site_account( array( 'access_token' => 'tok_site' ) );
		$this->provider->save_account_for_user( 42, array( 'access_token' => 'tok_user_42' ) );

		$this->assertSame( 'tok_site', $this->provider->get_account_for_context( array( 'user_id' => 42 ) )['access_token'] );
	}

	public function test_get_account_for_context_resolves_user_policy(): void {
		add_filter(
			'datamachine_auth_scope_policy',
			function () {
				return BaseAuthProvider::AUTH_SCOPE_USER;
			}
		);

		$this->provider->save_site_account( array( 'access_token' => 'tok_site' ) );
		$this->provider->save_account_for_user( 42, array( 'access_token' => 'tok_user_42' ) );

		$this->assertSame( 'tok_user_42', $this->provider->get_account_for_context( array( 'user_id' => 42 ) )['access_token'] );

		remove_all_filters( 'datamachine_auth_scope_policy' );
	}

	public function test_get_account_for_context_resolves_agent_before_user_for_principal_policy(): void {
		add_filter(
			'datamachine_auth_scope_policy',
			function () {
				return BaseAuthProvider::AUTH_SCOPE_PRINCIPAL;
			}
		);

		$this->provider->save_site_account( array( 'access_token' => 'tok_site' ) );
		$this->provider->save_account_for_user( 42, array( 'access_token' => 'tok_user_42' ) );
		$this->provider->save_account_for_agent( 303, array( 'access_token' => 'tok_agent_303' ) );

		$account = $this->provider->get_account_for_context(
			array(
				'user_id'  => 42,
				'agent_id' => 303,
			)
		);

		$this->assertSame( 'tok_agent_303', $account['access_token'] );

		remove_all_filters( 'datamachine_auth_scope_policy' );
	}

	public function test_get_account_for_context_falls_back_to_site_account_when_scoped_account_missing(): void {
		add_filter(
			'datamachine_auth_scope_policy',
			function () {
				return BaseAuthProvider::AUTH_SCOPE_USER;
			}
		);

		$this->provider->save_site_account( array( 'access_token' => 'tok_site' ) );

		$this->assertSame( 'tok_site', $this->provider->get_account_for_context( array( 'user_id' => 42 ) )['access_token'] );

		remove_all_filters( 'datamachine_auth_scope_policy' );
	}

	public function test_get_account_for_context_returns_null_when_policy_scoped_and_no_fallback_exists(): void {
		add_filter(
			'datamachine_auth_scope_policy',
			function () {
				return BaseAuthProvider::AUTH_SCOPE_USER;
			}
		);

		$this->assertNull( $this->provider->get_account_for_context( array( 'user_id' => 42 ) ) );

		remove_all_filters( 'datamachine_auth_scope_policy' );
	}

	public function test_get_account_without_context_does_not_emit_deprecation(): void {
		$deprecated_calls = array();

		add_action(
			'deprecated_function_run',
			function ( $function_name, $replacement, $version ) use ( &$deprecated_calls ) {
				$deprecated_calls[] = array( $function_name, $replacement, $version );
			},
			10,
			3
		);

		$this->provider->get_account();

		$this->assertSame( array(), $deprecated_calls );

		remove_all_filters( 'deprecated_function_run' );
	}

	public function test_get_account_with_context_emits_deprecation_and_preserves_return_shape(): void {
		$deprecated_calls = array();
		$this->setExpectedDeprecated( 'DataMachine\Core\OAuth\BaseAuthProvider::get_account with a context argument' );

		add_filter(
			'deprecated_function_trigger_error',
			function () {
				return false;
			}
		);
		add_action(
			'deprecated_function_run',
			function ( $function_name, $replacement, $version ) use ( &$deprecated_calls ) {
				$deprecated_calls[] = array( $function_name, $replacement, $version );
			},
			10,
			3
		);

		$result = $this->provider->get_account( array( 'user_id' => 42 ) );

		$this->assertSame( array(), $result );
		$this->assertSame(
			array(
				array(
					'DataMachine\Core\OAuth\BaseAuthProvider::get_account with a context argument',
					'BaseAuthProvider::get_account_for_context()',
					'0.131.0',
				),
			),
			$deprecated_calls
		);

		remove_all_filters( 'deprecated_function_trigger_error' );
		remove_all_filters( 'deprecated_function_run' );
	}

	public function test_deprecated_get_account_context_uses_context_resolver_behavior(): void {
		$this->setExpectedDeprecated( 'DataMachine\Core\OAuth\BaseAuthProvider::get_account with a context argument' );

		add_filter(
			'deprecated_function_trigger_error',
			function () {
				return false;
			}
		);
		add_filter(
			'datamachine_auth_scope_policy',
			function () {
				return BaseAuthProvider::AUTH_SCOPE_PRINCIPAL;
			}
		);

		$this->provider->save_site_account( array( 'access_token' => 'tok_site' ) );
		$this->provider->save_account_for_user( 42, array( 'access_token' => 'tok_user_42' ) );
		$this->provider->save_account_for_agent( 303, array( 'access_token' => 'tok_agent_303' ) );

		$result = $this->provider->get_account(
			array(
				'user_id'  => 42,
				'agent_id' => 303,
			)
		);

		$this->assertSame( 'tok_agent_303', $result['access_token'] );

		remove_all_filters( 'deprecated_function_trigger_error' );
		remove_all_filters( 'datamachine_auth_scope_policy' );
	}

	public function test_save_account_without_context_does_not_emit_deprecation(): void {
		$deprecated_calls = array();

		add_action(
			'deprecated_function_run',
			function ( $function_name, $replacement, $version ) use ( &$deprecated_calls ) {
				$deprecated_calls[] = array( $function_name, $replacement, $version );
			},
			10,
			3
		);

		$this->provider->save_account( array( 'access_token' => 'tok_site' ) );

		$this->assertSame( array(), $deprecated_calls );

		remove_all_filters( 'deprecated_function_run' );
	}

	public function test_save_account_with_context_emits_deprecation_and_preserves_policy_write(): void {
		$deprecated_calls = array();
		$this->setExpectedDeprecated( 'DataMachine\Core\OAuth\BaseAuthProvider::save_account with a context argument' );

		add_filter(
			'deprecated_function_trigger_error',
			function () {
				return false;
			}
		);
		add_filter(
			'datamachine_auth_scope_policy',
			function () {
				return BaseAuthProvider::AUTH_SCOPE_PRINCIPAL;
			}
		);
		add_action(
			'deprecated_function_run',
			function ( $function_name, $replacement, $version ) use ( &$deprecated_calls ) {
				$deprecated_calls[] = array( $function_name, $replacement, $version );
			},
			10,
			3
		);

		$result = $this->provider->save_account(
			array( 'access_token' => 'tok_agent_scoped' ),
			array(
				'user_id'  => 42,
				'agent_id' => 303,
			)
		);

		$this->assertTrue( $result );
		$this->assertSame( 'tok_agent_scoped', $this->provider->get_account_for_agent( 303 )['access_token'] );
		$this->assertSame(
			array(
				array(
					'DataMachine\Core\OAuth\BaseAuthProvider::save_account with a context argument',
					'BaseAuthProvider::save_site_account(), BaseAuthProvider::save_account_for_user(), or BaseAuthProvider::save_account_for_agent()',
					'0.132.0',
				),
			),
			$deprecated_calls
		);

		remove_all_filters( 'deprecated_function_trigger_error' );
		remove_all_filters( 'datamachine_auth_scope_policy' );
		remove_all_filters( 'deprecated_function_run' );
	}

	public function test_update_account_with_context_merges_policy_scoped_slot(): void {
		add_filter(
			'datamachine_auth_scope_policy',
			function () {
				return BaseAuthProvider::AUTH_SCOPE_PRINCIPAL;
			}
		);

		$this->provider->save_account_for_agent(
			303,
			array(
				'access_token'  => 'tok_agent_original',
				'refresh_token' => 'refresh_agent',
				'scope'         => 'read write',
			)
		);

		$this->assertTrue(
			$this->provider->update_account(
				array( 'access_token' => 'tok_agent_refreshed' ),
				array(
					'user_id'  => 42,
					'agent_id' => 303,
				)
			)
		);

		$this->assertSame(
			array(
				'access_token'  => 'tok_agent_refreshed',
				'refresh_token' => 'refresh_agent',
				'scope'         => 'read write',
			),
			$this->provider->get_account_for_agent( 303 )
		);

		remove_all_filters( 'datamachine_auth_scope_policy' );
	}

	public function test_update_account_with_scoped_context_does_not_merge_site_fallback(): void {
		add_filter(
			'datamachine_auth_scope_policy',
			function () {
				return BaseAuthProvider::AUTH_SCOPE_USER;
			}
		);

		$this->provider->save_site_account(
			array(
				'access_token'  => 'tok_site',
				'refresh_token' => 'refresh_site',
				'scope'         => 'site_scope',
			)
		);

		$this->assertTrue(
			$this->provider->update_account(
				array( 'access_token' => 'tok_user_new' ),
				array( 'user_id' => 42 )
			)
		);

		$this->assertSame( array( 'access_token' => 'tok_user_new' ), $this->provider->get_account_for_user( 42 ) );
		$this->assertSame( 'refresh_site', $this->provider->get_site_account()['refresh_token'] );

		remove_all_filters( 'datamachine_auth_scope_policy' );
	}

	public function test_clear_account_without_context_does_not_emit_deprecation(): void {
		$deprecated_calls = array();

		add_action(
			'deprecated_function_run',
			function ( $function_name, $replacement, $version ) use ( &$deprecated_calls ) {
				$deprecated_calls[] = array( $function_name, $replacement, $version );
			},
			10,
			3
		);

		$this->provider->clear_account();

		$this->assertSame( array(), $deprecated_calls );

		remove_all_filters( 'deprecated_function_run' );
	}

	public function test_clear_account_with_context_emits_deprecation_and_preserves_policy_delete(): void {
		$deprecated_calls = array();
		$this->setExpectedDeprecated( 'DataMachine\Core\OAuth\BaseAuthProvider::clear_account with a context argument' );

		add_filter(
			'deprecated_function_trigger_error',
			function () {
				return false;
			}
		);
		add_filter(
			'datamachine_auth_scope_policy',
			function () {
				return BaseAuthProvider::AUTH_SCOPE_PRINCIPAL;
			}
		);
		add_action(
			'deprecated_function_run',
			function ( $function_name, $replacement, $version ) use ( &$deprecated_calls ) {
				$deprecated_calls[] = array( $function_name, $replacement, $version );
			},
			10,
			3
		);

		$this->provider->save_site_account( array( 'access_token' => 'tok_site' ) );
		$this->provider->save_account_for_agent( 303, array( 'access_token' => 'tok_agent_303' ) );

		$result = $this->provider->clear_account( array( 'agent_id' => 303 ) );

		$this->assertTrue( $result );
		$this->assertNull( $this->provider->get_account_for_agent( 303 ) );
		$this->assertSame( 'tok_site', $this->provider->get_site_account()['access_token'] );
		$this->assertSame(
			array(
				array(
					'DataMachine\Core\OAuth\BaseAuthProvider::clear_account with a context argument',
					'BaseAuthProvider::delete_site_account(), BaseAuthProvider::delete_account_for_user(), or BaseAuthProvider::delete_account_for_agent()',
					'0.132.0',
				),
			),
			$deprecated_calls
		);

		remove_all_filters( 'deprecated_function_trigger_error' );
		remove_all_filters( 'datamachine_auth_scope_policy' );
		remove_all_filters( 'deprecated_function_run' );
	}

	// -------------------------------------------------------------------------
	// Per-user account API
	// -------------------------------------------------------------------------

	public function test_get_account_for_user_returns_null_when_no_account(): void {
		$this->assertNull( $this->provider->get_account_for_user( 42 ) );
	}

	public function test_save_account_for_user_round_trip(): void {
		$account = array(
			'access_token' => 'tok_user_42',
			'username'     => 'alice',
			'scope'        => 'read write',
		);

		$saved = $this->provider->save_account_for_user( 42, $account );
		$this->assertTrue( $saved );

		$loaded = $this->provider->get_account_for_user( 42 );
		$this->assertIsArray( $loaded );
		$this->assertSame( 'tok_user_42', $loaded['access_token'] );
		$this->assertSame( 'alice', $loaded['username'] );
		$this->assertSame( 'read write', $loaded['scope'] );
	}

	public function test_update_account_for_user_merges_existing_account_data(): void {
		$this->provider->save_account_for_user(
			42,
			array(
				'access_token'  => 'tok_user_original',
				'refresh_token' => 'refresh_user',
				'scope'         => 'read write',
			)
		);

		$this->assertTrue( $this->provider->update_account_for_user( 42, array( 'access_token' => 'tok_user_refreshed' ) ) );

		$this->assertSame(
			array(
				'access_token'  => 'tok_user_refreshed',
				'refresh_token' => 'refresh_user',
				'scope'         => 'read write',
			),
			$this->provider->get_account_for_user( 42 )
		);
	}

	public function test_update_account_for_user_does_not_copy_filter_resolved_account_into_default_storage(): void {
		add_filter(
			'datamachine_resolve_oauth_account_for_user',
			function ( $account, $provider, $user_id ) {
				if ( 'test_provider' === $provider && 707 === $user_id ) {
					return array(
						'access_token'  => 'platform_supplied',
						'refresh_token' => 'platform_refresh',
						'scope'         => 'platform:read',
					);
				}

				return $account;
			},
			10,
			3
		);

		$this->assertTrue( $this->provider->update_account_for_user( 707, array( 'access_token' => 'default_storage_token' ) ) );

		remove_all_filters( 'datamachine_resolve_oauth_account_for_user' );

		$this->assertSame( array( 'access_token' => 'default_storage_token' ), $this->provider->get_account_for_user( 707 ) );
	}

	public function test_delete_account_for_user_removes_account(): void {
		$this->provider->save_account_for_user( 42, array( 'access_token' => 'tok_user_42' ) );
		$this->assertNotNull( $this->provider->get_account_for_user( 42 ) );

		$this->assertTrue( $this->provider->delete_account_for_user( 42 ) );
		$this->assertNull( $this->provider->get_account_for_user( 42 ) );
	}

	public function test_delete_account_for_user_is_idempotent(): void {
		$this->assertTrue( $this->provider->delete_account_for_user( 12345 ) );
	}

	public function test_per_user_accounts_are_isolated_between_users(): void {
		$this->provider->save_account_for_user( 101, array( 'access_token' => 'tok_alice' ) );
		$this->provider->save_account_for_user( 202, array( 'access_token' => 'tok_bob' ) );

		$alice = $this->provider->get_account_for_user( 101 );
		$bob   = $this->provider->get_account_for_user( 202 );

		$this->assertSame( 'tok_alice', $alice['access_token'] );
		$this->assertSame( 'tok_bob', $bob['access_token'] );
	}

	public function test_per_user_account_does_not_fall_back_to_site_account(): void {
		// Site-wide account stored.
		$this->provider->save_account( array( 'access_token' => 'site_token' ) );

		// Per-user read for a user without per-user storage MUST be null.
		$this->assertNull( $this->provider->get_account_for_user( 42 ) );

		// Site account is still readable via the site-scoped API.
		$this->assertSame( 'site_token', $this->provider->get_account()['access_token'] );
	}

	public function test_per_user_save_does_not_affect_site_account(): void {
		$this->provider->save_account( array( 'access_token' => 'site_token' ) );
		$this->provider->save_account_for_user( 42, array( 'access_token' => 'tok_user_42' ) );

		$this->assertSame( 'site_token', $this->provider->get_account()['access_token'] );
		$this->assertSame( 'tok_user_42', $this->provider->get_account_for_user( 42 )['access_token'] );
	}

	public function test_invalid_user_ids_are_rejected(): void {
		$this->assertFalse( $this->provider->save_account_for_user( 0, array( 'access_token' => 'x' ) ) );
		$this->assertFalse( $this->provider->update_account_for_user( 0, array( 'access_token' => 'x' ) ) );
		$this->assertNull( $this->provider->get_account_for_user( 0 ) );
		$this->assertFalse( $this->provider->delete_account_for_user( 0 ) );
	}

	public function test_resolve_filter_short_circuits_default_lookup(): void {
		$received = null;

		add_filter(
			'datamachine_resolve_oauth_account_for_user',
			function ( $account, $provider, $user_id ) use ( &$received ) {
				$received = array( $account, $provider, $user_id );
				if ( 'test_provider' === $provider && 707 === $user_id ) {
					return array(
						'access_token' => 'platform_supplied',
						'scope'        => 'platform:read',
					);
				}
				return $account;
			},
			10,
			3
		);

		$result = $this->provider->get_account_for_user( 707 );

		$this->assertIsArray( $result );
		$this->assertSame( 'platform_supplied', $result['access_token'] );
		$this->assertSame( array( null, 'test_provider', 707 ), $received );

		remove_all_filters( 'datamachine_resolve_oauth_account_for_user' );
	}

	public function test_resolve_filter_returning_null_falls_through_to_default(): void {
		add_filter(
			'datamachine_resolve_oauth_account_for_user',
			function ( $account ) {
				return $account; // null in, null out.
			}
		);

		$this->provider->save_account_for_user( 808, array( 'access_token' => 'tok_default' ) );

		$this->assertSame( 'tok_default', $this->provider->get_account_for_user( 808 )['access_token'] );

		remove_all_filters( 'datamachine_resolve_oauth_account_for_user' );
	}

	public function test_resolve_filter_returning_empty_array_falls_through(): void {
		// Empty array should not short-circuit (it's indistinguishable from "no account").
		add_filter(
			'datamachine_resolve_oauth_account_for_user',
			function () {
				return array();
			}
		);

		$this->provider->save_account_for_user( 909, array( 'access_token' => 'tok_default' ) );

		$this->assertSame( 'tok_default', $this->provider->get_account_for_user( 909 )['access_token'] );

		remove_all_filters( 'datamachine_resolve_oauth_account_for_user' );
	}

	// -------------------------------------------------------------------------
	// Per-agent account API
	// -------------------------------------------------------------------------

	public function test_get_account_for_agent_returns_null_when_no_account(): void {
		$this->assertNull( $this->provider->get_account_for_agent( 303 ) );
	}

	public function test_save_account_for_agent_round_trip(): void {
		$account = array(
			'access_token' => 'tok_agent_303',
			'username'     => 'agent-account',
			'scope'        => 'read write',
		);

		$saved = $this->provider->save_account_for_agent( 303, $account );
		$this->assertTrue( $saved );

		$loaded = $this->provider->get_account_for_agent( 303 );
		$this->assertIsArray( $loaded );
		$this->assertSame( 'tok_agent_303', $loaded['access_token'] );
		$this->assertSame( 'agent-account', $loaded['username'] );
		$this->assertSame( 'read write', $loaded['scope'] );
	}

	public function test_update_account_for_agent_merges_existing_account_data(): void {
		$this->provider->save_account_for_agent(
			303,
			array(
				'access_token'  => 'tok_agent_original',
				'refresh_token' => 'refresh_agent',
				'scope'         => 'read write',
			)
		);

		$this->assertTrue( $this->provider->update_account_for_agent( 303, array( 'access_token' => 'tok_agent_refreshed' ) ) );

		$this->assertSame(
			array(
				'access_token'  => 'tok_agent_refreshed',
				'refresh_token' => 'refresh_agent',
				'scope'         => 'read write',
			),
			$this->provider->get_account_for_agent( 303 )
		);
	}

	public function test_delete_account_for_agent_removes_account(): void {
		$this->provider->save_account_for_agent( 303, array( 'access_token' => 'tok_agent_303' ) );
		$this->assertNotNull( $this->provider->get_account_for_agent( 303 ) );

		$this->assertTrue( $this->provider->delete_account_for_agent( 303 ) );
		$this->assertNull( $this->provider->get_account_for_agent( 303 ) );
	}

	public function test_delete_account_for_agent_is_idempotent(): void {
		$this->assertTrue( $this->provider->delete_account_for_agent( 12345 ) );
	}

	public function test_per_agent_accounts_are_isolated_between_agents(): void {
		$this->provider->save_account_for_agent( 303, array( 'access_token' => 'tok_agent_a' ) );
		$this->provider->save_account_for_agent( 404, array( 'access_token' => 'tok_agent_b' ) );

		$agent_a = $this->provider->get_account_for_agent( 303 );
		$agent_b = $this->provider->get_account_for_agent( 404 );

		$this->assertSame( 'tok_agent_a', $agent_a['access_token'] );
		$this->assertSame( 'tok_agent_b', $agent_b['access_token'] );
	}

	public function test_per_agent_account_does_not_fall_back_to_site_account(): void {
		$this->provider->save_site_account( array( 'access_token' => 'site_token' ) );

		$this->assertNull( $this->provider->get_account_for_agent( 303 ) );
		$this->assertSame( 'site_token', $this->provider->get_site_account()['access_token'] );
	}

	public function test_per_agent_save_does_not_affect_site_or_user_account(): void {
		$this->provider->save_site_account( array( 'access_token' => 'site_token' ) );
		$this->provider->save_account_for_user( 42, array( 'access_token' => 'tok_user_42' ) );
		$this->provider->save_account_for_agent( 303, array( 'access_token' => 'tok_agent_303' ) );

		$this->assertSame( 'site_token', $this->provider->get_site_account()['access_token'] );
		$this->assertSame( 'tok_user_42', $this->provider->get_account_for_user( 42 )['access_token'] );
		$this->assertSame( 'tok_agent_303', $this->provider->get_account_for_agent( 303 )['access_token'] );
	}

	public function test_invalid_agent_ids_are_rejected(): void {
		$this->assertFalse( $this->provider->save_account_for_agent( 0, array( 'access_token' => 'x' ) ) );
		$this->assertFalse( $this->provider->update_account_for_agent( 0, array( 'access_token' => 'x' ) ) );
		$this->assertNull( $this->provider->get_account_for_agent( 0 ) );
		$this->assertFalse( $this->provider->delete_account_for_agent( 0 ) );
	}

	public function test_per_agent_account_shares_principal_scoped_agent_slot(): void {
		add_filter(
			'datamachine_auth_scope_policy',
			function () {
				return BaseAuthProvider::AUTH_SCOPE_AGENT;
			}
		);

		$this->provider->save_account_for_agent( 303, array( 'access_token' => 'tok_agent_303' ) );

		$this->assertSame(
			'tok_agent_303',
			$this->provider->get_account_for_context( array( 'agent_id' => 303 ) )['access_token']
		);

		$this->provider->save_account_for_agent( 404, array( 'access_token' => 'tok_agent_scoped' ) );

		$this->assertSame( 'tok_agent_scoped', $this->provider->get_account_for_agent( 404 )['access_token'] );

		remove_all_filters( 'datamachine_auth_scope_policy' );
	}
}
