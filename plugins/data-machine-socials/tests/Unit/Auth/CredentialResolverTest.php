<?php
/**
 * CredentialResolver tests.
 *
 * @package DataMachineSocials\Tests\Unit\Auth
 */

namespace DataMachineSocials\Tests\Unit\Auth;

use DataMachine\Abilities\AuthAbilities;
use DataMachineSocials\Auth\CredentialResolver;
use WP_UnitTestCase;

final class CredentialResolverTest extends WP_UnitTestCase {

	/**
	 * Provider filter callback.
	 *
	 * @var callable|null
	 */
	private $provider_filter = null;

	public function tear_down(): void {
		if ( null !== $this->provider_filter ) {
			remove_filter( 'datamachine_auth_providers', $this->provider_filter, PHP_INT_MAX );
		}
		AuthAbilities::clearCache();
		parent::tear_down();
	}

	public function test_provider_missing_returns_missing_auth(): void {
		AuthAbilities::clearCache();

		$result = CredentialResolver::provider( 'not-a-registered-platform', 'Nowhere' );

		$this->assertMissingAuth( $result, 'Nowhere not authenticated' );
	}

	public function test_provider_missing_errors_even_when_authentication_not_required(): void {
		AuthAbilities::clearCache();

		$result = CredentialResolver::provider( 'not-a-registered-platform', 'Nowhere', false );

		$this->assertMissingAuth( $result, 'Nowhere not authenticated' );
	}

	public function test_unauthenticated_provider_returns_missing_auth(): void {
		$provider = $this->stubProvider( false, 'example-token' );

		$result = CredentialResolver::provider( 'example', 'Example' );

		$this->assertMissingAuth( $result, 'Example not authenticated' );
		$this->assertSame( 0, $provider->token_calls, 'Token must not be requested for an unauthenticated provider.' );
	}

	public function test_unauthenticated_provider_allowed_when_not_required(): void {
		$provider = $this->stubProvider( false, 'example-token' );

		$this->assertSame( $provider, CredentialResolver::provider( 'example', 'Example', false ) );

		$resolved = CredentialResolver::token( 'example', 'Example', false );
		$this->assertIsArray( $resolved );
		$this->assertSame( $provider, $resolved['provider'] );
		$this->assertSame( 'example-token', $resolved['token'] );
	}

	/**
	 * @dataProvider emptyTokenProvider
	 */
	public function test_empty_token_returns_missing_auth( ?string $token ): void {
		$provider = $this->stubProvider( true, $token );

		$result = CredentialResolver::token( 'example', 'Example' );

		$this->assertMissingAuth( $result, 'Example access token is missing or expired. Re-authorize in WP Admin > Data Machine > Settings.' );
		$this->assertSame( 1, $provider->token_calls );
	}

	public function emptyTokenProvider(): array {
		return array(
			'null'       => array( null ),
			'empty'      => array( '' ),
			'whitespace' => array( '   ' ),
		);
	}

	public function test_token_ok_returns_provider_and_trimmed_token(): void {
		$provider = $this->stubProvider( true, '  example-token  ' );

		$resolved = CredentialResolver::token( 'example', 'Example' );

		$this->assertIsArray( $resolved );
		$this->assertSame( $provider, $resolved['provider'] );
		$this->assertSame( 'example-token', $resolved['token'] );
		$this->assertSame( 1, $provider->token_calls );
	}

	public function test_class_mismatch_returns_missing_auth(): void {
		$this->stubProvider( true, 'example-token' );

		$result = CredentialResolver::provider( 'example', 'Example', false, \DataMachineSocials\Handlers\Threads\ThreadsAuth::class );

		$this->assertMissingAuth( $result, 'Example not authenticated' );
		$this->assertMissingAuth(
			CredentialResolver::token( 'example', 'Example', false, \DataMachineSocials\Handlers\Threads\ThreadsAuth::class ),
			'Example not authenticated'
		);
	}

	public function test_class_match_returns_provider(): void {
		$provider = $this->stubProvider( true, 'example-token' );

		$this->assertSame( $provider, CredentialResolver::provider( 'example', 'Example', true, get_class( $provider ) ) );
	}

	public function test_credentials_include_provider_config(): void {
		$this->stubProvider( true, 'example-token', array( 'app_id' => 'abc' ) );

		$resolved = CredentialResolver::credentials( 'example', 'Example' );

		$this->assertIsArray( $resolved );
		$this->assertSame( 'example-token', $resolved['token'] );
		$this->assertSame( array( 'app_id' => 'abc' ), $resolved['config'] );
	}

	public function test_config_is_empty_for_provider_without_config(): void {
		$provider = new class() {};

		$this->assertSame( array(), CredentialResolver::config( $provider ) );
	}

	public function test_optional_never_errors(): void {
		AuthAbilities::clearCache();

		$missing = CredentialResolver::optional( 'not-a-registered-platform' );
		$this->assertSame(
			array(
				'provider' => null,
				'token'    => '',
				'config'   => array(),
			),
			$missing
		);

		$provider = $this->stubProvider( false, 'example-token', array( 'api_key' => 'k' ) );
		$resolved = CredentialResolver::optional( 'example' );
		$this->assertSame( $provider, $resolved['provider'] );
		$this->assertSame( '', $resolved['token'], 'Unauthenticated providers yield no token.' );
		$this->assertSame( array( 'api_key' => 'k' ), $resolved['config'] );
		$this->assertSame( 0, $provider->token_calls );
	}

	public function test_all_includes_registered_providers(): void {
		$provider = $this->stubProvider( true, 'example-token' );

		$this->assertSame( $provider, CredentialResolver::all()['example'] ?? null );
	}

	/**
	 * Assert a missing_auth 401 error with the given message.
	 *
	 * @param mixed  $result  Resolver result.
	 * @param string $message Expected message.
	 */
	private function assertMissingAuth( $result, string $message ): void {
		$this->assertWPError( $result );
		$this->assertSame( 'missing_auth', $result->get_error_code() );
		$this->assertSame( $message, $result->get_error_message() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Register a stub provider under the 'example' key.
	 *
	 * @param bool        $authenticated Whether the provider reports authenticated.
	 * @param string|null $token         Token returned by get_valid_access_token().
	 * @param array       $config        Provider config.
	 * @return object The stub provider.
	 */
	private function stubProvider( bool $authenticated, ?string $token, array $config = array() ): object {
		$provider = new class( $authenticated, $token, $config ) {
			public int $token_calls = 0;

			public function __construct( private bool $authenticated, private ?string $token, private array $config ) {}

			public function is_authenticated(): bool {
				return $this->authenticated;
			}

			public function get_valid_access_token(): ?string {
				++$this->token_calls;
				return $this->token;
			}

			public function get_config(): array {
				return $this->config;
			}
		};

		$this->provider_filter = static function ( $providers ) use ( $provider ) {
			$providers['example'] = $provider;
			return $providers;
		};
		add_filter( 'datamachine_auth_providers', $this->provider_filter, PHP_INT_MAX );
		AuthAbilities::clearCache();

		return $provider;
	}
}
