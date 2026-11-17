<?php
/**
 * AbstractSocialAbility helper tests.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities
 */

namespace DataMachineSocials\Tests\Unit\Abilities;

use DataMachine\Abilities\AuthAbilities;
use DataMachineSocials\Abilities\AbstractSocialAbility;
use WP_UnitTestCase;

final class AbstractSocialAbilityTest extends WP_UnitTestCase {

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

	public function test_resolve_access_token_returns_trimmed_provider_token(): void {
		$this->stubProvider( true, '  example-token  ' );

		$this->assertSame( 'example-token', $this->ability()->token( 'example', 'Example' ) );
	}

	public function test_resolve_access_token_returns_missing_auth_without_provider(): void {
		AuthAbilities::clearCache();

		$result = $this->ability()->token( 'not-a-registered-platform', 'Nowhere' );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_auth', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_resolve_access_token_refreshes_hard_expired_token(): void {
		// An expired token reports unauthenticated, but the provider can still
		// refresh it on demand. The ability must not bail before refreshing.
		$this->stubProvider( false, 'refreshed-token' );

		$this->assertSame( 'refreshed-token', $this->ability()->token( 'example', 'Example' ) );
	}

	public function test_resolve_access_token_returns_missing_auth_when_expired_refresh_fails(): void {
		$this->stubProvider( false, null );

		$result = $this->ability()->token( 'example', 'Example' );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_auth', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_resolve_access_token_returns_missing_auth_without_token_lifecycle(): void {
		$provider = new class() {
			public function is_authenticated(): bool {
				return true;
			}
		};

		$this->provider_filter = static function ( $providers ) use ( $provider ) {
			$providers['example'] = $provider;
			return $providers;
		};
		add_filter( 'datamachine_auth_providers', $this->provider_filter, PHP_INT_MAX );
		AuthAbilities::clearCache();

		$result = $this->ability()->token( 'example', 'Example' );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_auth', $result->get_error_code() );
	}

	/**
	 * @dataProvider emptyTokenProvider
	 */
	public function test_resolve_access_token_returns_missing_auth_when_token_unavailable( ?string $token ): void {
		$this->stubProvider( true, $token );

		$result = $this->ability()->token( 'example', 'Example' );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_auth', $result->get_error_code() );
		$this->assertStringContainsString( 'Example', $result->get_error_message() );
	}

	public function emptyTokenProvider(): array {
		return array(
			'null'       => array( null ),
			'empty'      => array( '' ),
			'whitespace' => array( '   ' ),
		);
	}

	private function ability(): object {
		return new class() extends AbstractSocialAbility {
			public function token( string $platform, string $label ): string|\WP_Error {
				return $this->resolveAccessToken( $platform, $label );
			}
		};
	}

	private function stubProvider( bool $authenticated, ?string $token ): void {
		$provider = new class( $authenticated, $token ) {
			public function __construct( private bool $authenticated, private ?string $token ) {}

			public function is_authenticated(): bool {
				return $this->authenticated;
			}

			public function get_valid_access_token(): ?string {
				return $this->token;
			}
		};

		$this->provider_filter = static function ( $providers ) use ( $provider ) {
			$providers['example'] = $provider;
			return $providers;
		};
		add_filter( 'datamachine_auth_providers', $this->provider_filter, PHP_INT_MAX );
		AuthAbilities::clearCache();
	}
}
