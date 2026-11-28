<?php
/**
 * Reddit fetch failure tests.
 *
 * A Reddit fetch whose token is expired with no refresh path must fail with
 * the provider error code instead of returning "no new items".
 *
 * @package DataMachineSocials\Tests\Unit\Handlers\Reddit
 * @see https://github.com/Extra-Chill/data-machine/issues/4028
 */

namespace DataMachineSocials\Tests\Unit\Handlers\Reddit;

use DataMachine\Abilities\AuthAbilities;
use DataMachineSocials\Handlers\Reddit\Reddit;
use WP_UnitTestCase;

final class RedditFetchFailureTest extends WP_UnitTestCase {

	/** @var callable|null */
	private $provider_filter = null;

	public function tear_down(): void {
		if ( null !== $this->provider_filter ) {
			remove_filter( 'datamachine_auth_providers', $this->provider_filter, PHP_INT_MAX );
			$this->provider_filter = null;
		}
		AuthAbilities::clearCache();
		parent::tear_down();
	}

	public function test_unrefreshable_token_fails_fetch_with_missing_auth(): void {
		$provider = new class() {
			public function is_authenticated(): bool {
				return false;
			}

			public function get_valid_access_token(): ?string {
				return null;
			}

			public function get_config(): array {
				return array();
			}
		};

		$this->provider_filter = static function ( $providers ) use ( $provider ) {
			$providers['reddit'] = $provider;
			return $providers;
		};
		add_filter( 'datamachine_auth_providers', $this->provider_filter, PHP_INT_MAX );
		AuthAbilities::clearCache();

		$result = ( new Reddit() )->get_fetch_data( 'direct', array( 'subreddit' => 'music' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_auth', $result->get_error_code() );
	}
}
