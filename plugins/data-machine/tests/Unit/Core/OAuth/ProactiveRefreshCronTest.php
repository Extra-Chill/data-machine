<?php
/**
 * ProactiveRefreshCron tests.
 *
 * @package DataMachine\Tests\Unit\Core\OAuth
 */

namespace DataMachine\Tests\Unit\Core\OAuth;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\OAuth\BaseOAuth2Provider;
use DataMachine\Core\OAuth\ProactiveRefreshCron;
use WP_UnitTestCase;

/**
 * Minimal OAuth2 provider built lazily through the provider filter.
 */
class LazyCronOAuth2Provider extends BaseOAuth2Provider {

	public function get_config_fields(): array {
		return array();
	}

	public function get_authorization_url(): string {
		return '';
	}

	public function handle_oauth_callback() {}
}

class ProactiveRefreshCronTest extends WP_UnitTestCase {

	private const SLUG = 'lazy_cron_oauth2';

	/**
	 * Provider filter callback.
	 *
	 * @var callable
	 */
	private $provider_filter;

	/**
	 * Number of times the provider filter built a provider.
	 */
	private int $built = 0;

	public function set_up(): void {
		parent::set_up();

		$this->provider_filter = function ( $providers ) {
			if ( ! isset( $providers[ self::SLUG ] ) ) {
				++$this->built;
				$providers[ self::SLUG ] = new LazyCronOAuth2Provider( self::SLUG );
			}
			return $providers;
		};
		add_filter( 'datamachine_auth_providers', $this->provider_filter, PHP_INT_MAX );
		AuthAbilities::clearCache();
		remove_all_actions( $this->hook() );
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_auth_providers', $this->provider_filter, PHP_INT_MAX );
		remove_all_filters( 'wp_doing_cron' );
		remove_all_actions( $this->hook() );
		wp_clear_scheduled_hook( $this->hook() );
		AuthAbilities::clearCache();
		parent::tear_down();
	}

	private function hook(): string {
		return ProactiveRefreshCron::HOOK_PREFIX . self::SLUG;
	}

	public function test_hook_prefix_matches_provider_cron_hook(): void {
		$provider = new LazyCronOAuth2Provider( self::SLUG );

		$this->assertSame( $this->hook(), $provider->get_cron_hook_name() );
	}

	public function test_has_scheduled_refresh_detects_refresh_hooks_only(): void {
		$this->assertTrue(
			ProactiveRefreshCron::has_scheduled_refresh(
				array(
					1700000000 => array( 'some_other_hook' => array() ),
					1700000100 => array( 'datamachine_refresh_token_reddit' => array() ),
					'version'  => 2,
				)
			)
		);

		$this->assertFalse(
			ProactiveRefreshCron::has_scheduled_refresh(
				array(
					1700000000 => array( 'some_other_hook' => array() ),
					'version'  => 2,
				)
			)
		);
	}

	public function test_cron_run_attaches_listener_for_lazily_built_provider(): void {
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, $this->hook() );
		add_filter( 'wp_doing_cron', '__return_true' );

		// Nothing has built providers yet, so a due event would fire with no callback.
		$this->assertFalse( has_action( $this->hook() ) );

		ProactiveRefreshCron::ensure_listeners();

		$this->assertSame( 1, $this->built );
		$this->assertNotFalse( has_action( $this->hook() ) );
	}

	public function test_skips_provider_build_outside_cron(): void {
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, $this->hook() );
		add_filter( 'wp_doing_cron', '__return_false' );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->markTestSkipped( 'WP-CLI requests are cron-capable.' );
		}

		ProactiveRefreshCron::ensure_listeners();

		$this->assertSame( 0, $this->built );
	}

	public function test_skips_provider_build_without_scheduled_refresh(): void {
		add_filter( 'wp_doing_cron', '__return_true' );

		ProactiveRefreshCron::ensure_listeners();

		$this->assertSame( 0, $this->built );
	}

	public function test_registers_on_wp_loaded(): void {
		$this->assertNotFalse( has_action( 'wp_loaded', array( ProactiveRefreshCron::class, 'ensure_listeners' ) ) );
	}
}
