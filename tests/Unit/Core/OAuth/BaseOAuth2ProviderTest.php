<?php
/**
 * BaseOAuth2Provider Tests
 *
 * Tests for OAuth2 token lifecycle management: on-demand refresh via
 * get_valid_access_token(), proactive WP-Cron scheduling, is_authenticated()
 * expiry checks, and the do_refresh_token() override contract.
 *
 * @package DataMachine\Tests\Unit\Core\OAuth
 */

namespace DataMachine\Tests\Unit\Core\OAuth;

use DataMachine\Core\OAuth\BaseOAuth2Provider;
use WP_UnitTestCase;

/**
 * Concrete test provider that simulates a successful refresh.
 */
class TestOAuth2Provider extends BaseOAuth2Provider {

	/**
	 * Whether do_refresh_token should succeed, fail, or return null.
	 *
	 * @var string 'success'|'error'|'unsupported'
	 */
	public string $refresh_behavior = 'success';

	/**
	 * Tracks how many times do_refresh_token was called.
	 */
	public int $refresh_call_count = 0;

	/**
	 * The token value that do_refresh_token returns on success.
	 */
	public string $refreshed_token = 'refreshed_tok_999';

	/**
	 * Expiry for refreshed token (seconds from now).
	 */
	public int $refreshed_expires_in = 5184000; // 60 days

	public function get_config_fields(): array {
		return array(
			'client_id'     => array(
				'label'    => 'Client ID',
				'type'     => 'text',
				'required' => true,
			),
			'client_secret' => array(
				'label'    => 'Client Secret',
				'type'     => 'text',
				'required' => true,
			),
		);
	}

	public function get_authorization_url(): string {
		return 'https://example.com/oauth/authorize';
	}

	public function handle_oauth_callback() {
		// No-op for tests.
	}

	protected function do_refresh_token( string $current_token ): array|\WP_Error|null {
		$this->refresh_call_count++;

		if ( 'unsupported' === $this->refresh_behavior ) {
			return null;
		}

		if ( 'error' === $this->refresh_behavior ) {
			return new \WP_Error( 'test_refresh_failed', 'Simulated refresh failure' );
		}

		return array(
			'access_token' => $this->refreshed_token,
			'expires_at'   => time() + $this->refreshed_expires_in,
		);
	}
}

/**
 * Provider with custom refresh buffer (5 minutes like Google).
 */
class TestShortLivedProvider extends TestOAuth2Provider {

	protected function get_refresh_buffer_seconds(): int {
		return 300; // 5 minutes
	}
}

class BaseOAuth2ProviderTest extends WP_UnitTestCase {

	private TestOAuth2Provider $provider;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->provider = new TestOAuth2Provider( 'test_oauth2' );
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( $this->provider->get_cron_hook_name() );
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// is_authenticated()
	// -------------------------------------------------------------------------

	public function test_is_authenticated_returns_false_with_no_account(): void {
		$this->assertFalse( $this->provider->is_authenticated() );
	}

	public function test_is_authenticated_returns_false_with_empty_token(): void {
		$this->provider->save_account( array( 'access_token' => '' ) );
		$this->assertFalse( $this->provider->is_authenticated() );
	}

	public function test_is_authenticated_returns_true_with_valid_token(): void {
		$this->provider->save_account( array(
			'access_token'    => 'tok_123',
			'token_expires_at' => time() + 3600,
		) );
		$this->assertTrue( $this->provider->is_authenticated() );
	}

	public function test_is_authenticated_returns_true_when_no_expiry_set(): void {
		$this->provider->save_account( array( 'access_token' => 'tok_123' ) );
		$this->assertTrue( $this->provider->is_authenticated() );
	}

	public function test_is_authenticated_returns_false_when_token_expired(): void {
		$this->provider->save_account( array(
			'access_token'    => 'tok_123',
			'token_expires_at' => time() - 100,
		) );
		$this->assertFalse( $this->provider->is_authenticated() );
	}

	// -------------------------------------------------------------------------
	// get_valid_access_token() — no refresh needed
	// -------------------------------------------------------------------------

	public function test_get_valid_access_token_returns_null_with_no_account(): void {
		$this->assertNull( $this->provider->get_valid_access_token() );
	}

	public function test_get_valid_access_token_returns_token_when_not_near_expiry(): void {
		$this->provider->save_account( array(
			'access_token'    => 'tok_fresh',
			'token_expires_at' => time() + ( 30 * DAY_IN_SECONDS ), // 30 days out
		) );

		$token = $this->provider->get_valid_access_token();

		$this->assertSame( 'tok_fresh', $token );
		$this->assertSame( 0, $this->provider->refresh_call_count );
	}

	public function test_get_valid_access_token_returns_token_when_no_expiry(): void {
		$this->provider->save_account( array( 'access_token' => 'tok_no_expiry' ) );

		$token = $this->provider->get_valid_access_token();

		$this->assertSame( 'tok_no_expiry', $token );
		$this->assertSame( 0, $this->provider->refresh_call_count );
	}

	// -------------------------------------------------------------------------
	// get_valid_access_token() — refresh triggers
	// -------------------------------------------------------------------------

	public function test_refresh_triggers_when_within_buffer(): void {
		// Token expires in 3 days — within the 7-day buffer.
		$this->provider->save_account( array(
			'access_token'    => 'tok_old',
			'token_expires_at' => time() + ( 3 * DAY_IN_SECONDS ),
		) );

		$token = $this->provider->get_valid_access_token();

		$this->assertSame( 'refreshed_tok_999', $token );
		$this->assertSame( 1, $this->provider->refresh_call_count );
	}

	public function test_refresh_triggers_when_expired(): void {
		$this->provider->save_account( array(
			'access_token'    => 'tok_expired',
			'token_expires_at' => time() - 100,
		) );

		$token = $this->provider->get_valid_access_token();

		$this->assertSame( 'refreshed_tok_999', $token );
		$this->assertSame( 1, $this->provider->refresh_call_count );
	}

	public function test_refresh_saves_new_token_to_account(): void {
		$this->provider->save_account( array(
			'access_token'    => 'tok_old',
			'token_expires_at' => time() - 100,
		) );

		$this->provider->get_valid_access_token();

		$account = $this->provider->get_account();
		$this->assertSame( 'refreshed_tok_999', $account['access_token'] );
		$this->assertArrayHasKey( 'last_refreshed_at', $account );
		$this->assertGreaterThan( time() + 5000000, $account['token_expires_at'] );
	}

	public function test_refresh_does_not_trigger_outside_buffer(): void {
		// Token expires in 10 days — outside the 7-day buffer.
		$this->provider->save_account( array(
			'access_token'    => 'tok_fine',
			'token_expires_at' => time() + ( 10 * DAY_IN_SECONDS ),
		) );

		$token = $this->provider->get_valid_access_token();

		$this->assertSame( 'tok_fine', $token );
		$this->assertSame( 0, $this->provider->refresh_call_count );
	}

	// -------------------------------------------------------------------------
	// get_valid_access_token() — refresh failures
	// -------------------------------------------------------------------------

	public function test_refresh_failure_returns_current_token_if_not_expired(): void {
		$this->provider->refresh_behavior = 'error';
		// Token is within buffer (3 days) but not hard-expired.
		$this->provider->save_account( array(
			'access_token'    => 'tok_still_valid',
			'token_expires_at' => time() + ( 3 * DAY_IN_SECONDS ),
		) );

		$token = $this->provider->get_valid_access_token();

		$this->assertSame( 'tok_still_valid', $token );
		$this->assertSame( 1, $this->provider->refresh_call_count );
	}

	public function test_refresh_failure_returns_null_if_hard_expired(): void {
		$this->provider->refresh_behavior = 'error';
		$this->provider->save_account( array(
			'access_token'    => 'tok_dead',
			'token_expires_at' => time() - 100,
		) );

		$token = $this->provider->get_valid_access_token();

		$this->assertNull( $token );
		$this->assertSame( 1, $this->provider->refresh_call_count );
	}

	public function test_unsupported_refresh_returns_current_token_if_not_expired(): void {
		$this->provider->refresh_behavior = 'unsupported';
		$this->provider->save_account( array(
			'access_token'    => 'tok_no_refresh',
			'token_expires_at' => time() + ( 3 * DAY_IN_SECONDS ),
		) );

		$token = $this->provider->get_valid_access_token();

		$this->assertSame( 'tok_no_refresh', $token );
	}

	public function test_unsupported_refresh_returns_null_if_hard_expired(): void {
		$this->provider->refresh_behavior = 'unsupported';
		$this->provider->save_account( array(
			'access_token'    => 'tok_no_refresh_dead',
			'token_expires_at' => time() - 100,
		) );

		$token = $this->provider->get_valid_access_token();

		$this->assertNull( $token );
	}

	// -------------------------------------------------------------------------
	// Custom refresh buffer
	// -------------------------------------------------------------------------

	public function test_custom_buffer_triggers_refresh_appropriately(): void {
		$short_provider = new TestShortLivedProvider( 'test_short' );
		// Token expires in 4 minutes — within the 5-minute buffer.
		$short_provider->save_account( array(
			'access_token'    => 'tok_google',
			'token_expires_at' => time() + 240,
		) );

		$token = $short_provider->get_valid_access_token();

		$this->assertSame( 'refreshed_tok_999', $token );
		$this->assertSame( 1, $short_provider->refresh_call_count );

		wp_clear_scheduled_hook( $short_provider->get_cron_hook_name() );
		delete_site_option( 'datamachine_auth_data' );
	}

	public function test_custom_buffer_does_not_trigger_outside_buffer(): void {
		$short_provider = new TestShortLivedProvider( 'test_short2' );
		// Token expires in 10 minutes — outside the 5-minute buffer.
		$short_provider->save_account( array(
			'access_token'    => 'tok_google_ok',
			'token_expires_at' => time() + 600,
		) );

		$token = $short_provider->get_valid_access_token();

		$this->assertSame( 'tok_google_ok', $token );
		$this->assertSame( 0, $short_provider->refresh_call_count );

		delete_site_option( 'datamachine_auth_data' );
	}

	// -------------------------------------------------------------------------
	// Proactive cron scheduling
	// -------------------------------------------------------------------------

	public function test_cron_hook_name_includes_provider_slug(): void {
		$this->assertSame( 'datamachine_refresh_token_test_oauth2', $this->provider->get_cron_hook_name() );
	}

	public function test_schedule_proactive_refresh_schedules_event(): void {
		$expires_at = time() + ( 30 * DAY_IN_SECONDS );
		$this->provider->save_account( array(
			'access_token'    => 'tok_123',
			'token_expires_at' => $expires_at,
		) );

		$result = $this->provider->schedule_proactive_refresh();

		$this->assertTrue( $result );

		$next = wp_next_scheduled( $this->provider->get_cron_hook_name() );
		$this->assertNotFalse( $next );

		// Should be scheduled at (expires_at - 7 days).
		$expected = $expires_at - ( 7 * DAY_IN_SECONDS );
		$this->assertSame( $expected, $next );
	}

	public function test_schedule_proactive_refresh_returns_false_without_expiry(): void {
		$this->provider->save_account( array( 'access_token' => 'tok_123' ) );

		$result = $this->provider->schedule_proactive_refresh();

		$this->assertFalse( $result );
	}

	public function test_schedule_proactive_refresh_attempts_recovery_when_past(): void {
		// Token expires in 2 days — refresh time would be (2d - 7d) = -5d, which is in the past.
		// With recovery, the provider should attempt an immediate refresh.
		$this->provider->save_account( array(
			'access_token'    => 'tok_123',
			'token_expires_at' => time() + ( 2 * DAY_IN_SECONDS ),
		) );

		$result = $this->provider->schedule_proactive_refresh();

		// Recovery refresh succeeds — new token gets a future cron scheduled.
		$this->assertTrue( $result );
		$this->assertSame( 1, $this->provider->refresh_call_count );

		// New token should be saved.
		$account = $this->provider->get_account();
		$this->assertSame( 'refreshed_tok_999', $account['access_token'] );

		// A cron event should now be scheduled for the new token.
		$next = wp_next_scheduled( $this->provider->get_cron_hook_name() );
		$this->assertNotFalse( $next );
	}

	public function test_schedule_proactive_refresh_returns_false_when_recovery_fails(): void {
		$this->provider->refresh_behavior = 'error';
		// Token expires in 2 days — refresh time in the past, and recovery will fail.
		$this->provider->save_account( array(
			'access_token'    => 'tok_123',
			'token_expires_at' => time() + ( 2 * DAY_IN_SECONDS ),
		) );

		$result = $this->provider->schedule_proactive_refresh();

		$this->assertFalse( $result );
		$this->assertSame( 1, $this->provider->refresh_call_count );
	}

	public function test_schedule_proactive_refresh_returns_false_when_unsupported(): void {
		$this->provider->refresh_behavior = 'unsupported';
		// Token expires in 2 days — refresh time in the past, provider doesn't support refresh.
		$this->provider->save_account( array(
			'access_token'    => 'tok_123',
			'token_expires_at' => time() + ( 2 * DAY_IN_SECONDS ),
		) );

		$result = $this->provider->schedule_proactive_refresh();

		$this->assertFalse( $result );
	}

	public function test_schedule_proactive_refresh_clears_previous_event(): void {
		$this->provider->save_account( array(
			'access_token'    => 'tok_123',
			'token_expires_at' => time() + ( 30 * DAY_IN_SECONDS ),
		) );
		$this->provider->schedule_proactive_refresh();

		$first_scheduled = wp_next_scheduled( $this->provider->get_cron_hook_name() );

		// Schedule again with different expiry.
		$new_expires = time() + ( 60 * DAY_IN_SECONDS );
		$this->provider->save_account( array(
			'access_token'    => 'tok_456',
			'token_expires_at' => $new_expires,
		) );
		$this->provider->schedule_proactive_refresh();

		$second_scheduled = wp_next_scheduled( $this->provider->get_cron_hook_name() );

		$this->assertNotSame( $first_scheduled, $second_scheduled );
		$expected = $new_expires - ( 7 * DAY_IN_SECONDS );
		$this->assertSame( $expected, $second_scheduled );
	}

	public function test_successful_refresh_reschedules_cron(): void {
		$this->provider->save_account( array(
			'access_token'    => 'tok_old',
			'token_expires_at' => time() - 100,
		) );

		$this->provider->get_valid_access_token();

		$next = wp_next_scheduled( $this->provider->get_cron_hook_name() );
		$this->assertNotFalse( $next );
	}

	// -------------------------------------------------------------------------
	// clear_account() cleans up cron
	// -------------------------------------------------------------------------

	public function test_clear_account_removes_cron_event(): void {
		$this->provider->save_account( array(
			'access_token'    => 'tok_123',
			'token_expires_at' => time() + ( 30 * DAY_IN_SECONDS ),
		) );
		$this->provider->schedule_proactive_refresh();

		$this->assertNotFalse( wp_next_scheduled( $this->provider->get_cron_hook_name() ) );

		$this->provider->clear_account();

		$this->assertFalse( wp_next_scheduled( $this->provider->get_cron_hook_name() ) );
	}

	public function test_clear_account_removes_account_data(): void {
		$this->provider->save_account( array( 'access_token' => 'tok_123' ) );

		$this->provider->clear_account();

		$this->assertEmpty( $this->provider->get_account() );
	}

	// -------------------------------------------------------------------------
	// handle_cron_refresh()
	// -------------------------------------------------------------------------

	public function test_cron_refresh_calls_get_valid_access_token(): void {
		$this->provider->save_account( array(
			'access_token'    => 'tok_expiring',
			'token_expires_at' => time() + ( 3 * DAY_IN_SECONDS ),
		) );

		$this->provider->handle_cron_refresh();

		$this->assertSame( 1, $this->provider->refresh_call_count );
		$account = $this->provider->get_account();
		$this->assertSame( 'refreshed_tok_999', $account['access_token'] );
	}

	public function test_cron_refresh_skips_when_no_account_data(): void {
		// No account data at all — nothing to refresh.
		$this->provider->handle_cron_refresh();

		$this->assertSame( 0, $this->provider->refresh_call_count );
	}

	public function test_cron_refresh_recovers_expired_token(): void {
		// Token is expired — cron window was missed.
		$this->provider->save_account( array(
			'access_token'    => 'tok_expired',
			'token_expires_at' => time() - 3600,
		) );

		$this->provider->handle_cron_refresh();

		// Should have attempted refresh via get_valid_access_token().
		$this->assertSame( 1, $this->provider->refresh_call_count );
		$account = $this->provider->get_account();
		$this->assertSame( 'refreshed_tok_999', $account['access_token'] );
	}

	public function test_cron_refresh_fires_failure_hook_on_expired_refresh_failure(): void {
		$this->provider->refresh_behavior = 'error';
		$this->provider->save_account( array(
			'access_token'    => 'tok_dead',
			'token_expires_at' => time() - 3600,
		) );

		$hook_fired   = false;
		$hook_provider = '';
		add_action( 'datamachine_oauth_refresh_failed', function ( $provider_slug ) use ( &$hook_fired, &$hook_provider ) {
			$hook_fired    = true;
			$hook_provider = $provider_slug;
		}, 10, 2 );

		$this->provider->handle_cron_refresh();

		$this->assertTrue( $hook_fired );
		$this->assertSame( 'test_oauth2', $hook_provider );
	}

	// -------------------------------------------------------------------------
	// Legacy refresh_token() method
	// -------------------------------------------------------------------------

	public function test_legacy_refresh_token_delegates_to_get_valid_access_token(): void {
		$this->provider->save_account( array(
			'access_token'    => 'tok_expiring',
			'token_expires_at' => time() + ( 3 * DAY_IN_SECONDS ),
		) );

		$result = $this->provider->refresh_token();

		$this->assertTrue( $result );
		$this->assertSame( 1, $this->provider->refresh_call_count );
	}

	public function test_legacy_refresh_token_returns_false_when_no_token(): void {
		$result = $this->provider->refresh_token();

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// is_configured() default
	// -------------------------------------------------------------------------

	public function test_is_configured_returns_true_with_client_credentials(): void {
		$this->provider->save_config( array(
			'client_id'     => 'id_123',
			'client_secret' => 'secret_456',
		) );

		$this->assertTrue( $this->provider->is_configured() );
	}

	public function test_is_configured_returns_false_without_credentials(): void {
		$this->assertFalse( $this->provider->is_configured() );
	}

	public function test_is_configured_returns_false_without_secret_for_default_provider(): void {
		$this->provider->save_config( array( 'client_id' => 'id_123' ) );
		$this->assertFalse( $this->provider->is_configured() );
	}

	// -------------------------------------------------------------------------
	// Public client (no client_secret required)
	// -------------------------------------------------------------------------

	public function test_public_client_is_configured_without_secret(): void {
		$provider = new TestPublicOAuth2Provider( 'test_public' );
		$provider->save_config( array( 'client_id' => '128117' ) );

		$this->assertTrue( $provider->is_configured() );

		delete_site_option( 'datamachine_auth_data' );
	}

	public function test_public_client_is_not_configured_without_client_id(): void {
		$provider = new TestPublicOAuth2Provider( 'test_public2' );

		$this->assertFalse( $provider->is_configured() );

		delete_site_option( 'datamachine_auth_data' );
	}

	public function test_public_client_does_not_require_secret(): void {
		$provider = new TestPublicOAuth2Provider( 'test_public3' );

		// Access the protected method via reflection for testing.
		$reflection = new \ReflectionMethod( $provider, 'requires_client_secret' );
		$reflection->setAccessible( true );
		$this->assertFalse( $reflection->invoke( $provider ) );

		delete_site_option( 'datamachine_auth_data' );
	}

	// -------------------------------------------------------------------------
	// Flow type defaults
	// -------------------------------------------------------------------------

	public function test_default_response_type_is_code(): void {
		$this->assertSame( 'code', $this->provider->get_oauth_response_type() );
	}

	public function test_default_uses_pkce_is_false(): void {
		$this->assertFalse( $this->provider->uses_pkce() );
	}

	public function test_implicit_provider_returns_token_response_type(): void {
		$provider = new TestImplicitOAuth2Provider( 'test_implicit' );
		$this->assertSame( 'token', $provider->get_oauth_response_type() );
		delete_site_option( 'datamachine_auth_data' );
	}

	public function test_pkce_provider_uses_pkce(): void {
		$provider = new TestPkceOAuth2Provider( 'test_pkce' );
		$this->assertTrue( $provider->uses_pkce() );
		$this->assertSame( 'code', $provider->get_oauth_response_type() );
		delete_site_option( 'datamachine_auth_data' );
	}

	// -------------------------------------------------------------------------
	// OAuth2Handler PKCE
	// -------------------------------------------------------------------------

	public function test_pkce_create_returns_verifier_challenge_method(): void {
		$handler = new \DataMachine\Core\OAuth\OAuth2Handler();
		$pkce    = $handler->create_pkce( 'test_pkce_flow' );

		$this->assertArrayHasKey( 'verifier', $pkce );
		$this->assertArrayHasKey( 'challenge', $pkce );
		$this->assertArrayHasKey( 'method', $pkce );
		$this->assertSame( 'S256', $pkce['method'] );
		$this->assertNotEmpty( $pkce['verifier'] );
		$this->assertNotEmpty( $pkce['challenge'] );
		$this->assertNotSame( $pkce['verifier'], $pkce['challenge'] );

		// Clean up transient.
		delete_transient( 'datamachine_test_pkce_flow_pkce_verifier' );
	}

	public function test_pkce_verifier_stored_and_retrieved(): void {
		$handler = new \DataMachine\Core\OAuth\OAuth2Handler();
		$pkce    = $handler->create_pkce( 'test_pkce_store' );

		$verifier = $handler->get_pkce_verifier( 'test_pkce_store' );

		$this->assertSame( $pkce['verifier'], $verifier );
	}

	public function test_pkce_verifier_consumed_on_retrieval(): void {
		$handler = new \DataMachine\Core\OAuth\OAuth2Handler();
		$handler->create_pkce( 'test_pkce_consume' );

		// First retrieval succeeds.
		$this->assertNotNull( $handler->get_pkce_verifier( 'test_pkce_consume' ) );

		// Second retrieval returns null (consumed).
		$this->assertNull( $handler->get_pkce_verifier( 'test_pkce_consume' ) );
	}

	public function test_pkce_verifier_returns_null_when_not_created(): void {
		$handler = new \DataMachine\Core\OAuth\OAuth2Handler();
		$this->assertNull( $handler->get_pkce_verifier( 'nonexistent_provider' ) );
	}

	public function test_pkce_challenge_is_valid_s256(): void {
		$handler = new \DataMachine\Core\OAuth\OAuth2Handler();
		$pkce    = $handler->create_pkce( 'test_pkce_s256' );

		// Verify S256: challenge should be base64url(sha256(verifier)).
		$expected_hash = hash( 'sha256', $pkce['verifier'], true );
		$expected_challenge = rtrim( strtr( base64_encode( $expected_hash ), '+/', '-_' ), '=' );

		$this->assertSame( $expected_challenge, $pkce['challenge'] );

		delete_transient( 'datamachine_test_pkce_s256_pkce_verifier' );
	}
}

/**
 * Public client test provider (no client_secret, implicit flow).
 */
class TestPublicOAuth2Provider extends TestOAuth2Provider {

	protected function requires_client_secret(): bool {
		return false;
	}

	public function get_oauth_response_type(): string {
		return 'token';
	}

	public function get_config_fields(): array {
		return array(
			'client_id' => array(
				'label'    => 'Client ID',
				'type'     => 'text',
				'required' => true,
			),
		);
	}
}

/**
 * Implicit flow test provider.
 */
class TestImplicitOAuth2Provider extends TestOAuth2Provider {

	protected function requires_client_secret(): bool {
		return false;
	}

	public function get_oauth_response_type(): string {
		return 'token';
	}
}

/**
 * PKCE flow test provider.
 */
class TestPkceOAuth2Provider extends TestOAuth2Provider {

	protected function requires_client_secret(): bool {
		return false;
	}

	public function uses_pkce(): bool {
		return true;
	}
}
