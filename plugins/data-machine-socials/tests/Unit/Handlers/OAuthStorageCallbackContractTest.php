<?php
/**
 * OAuth storage callback contract (#278).
 *
 * OAuth2Handler::store_callback_account() is typed `: bool` and returns
 * the provider's storage callback result directly. A storage closure that
 * returns nothing made every callback for those providers fatal after the
 * account was already saved. This captures each provider's storage
 * callback from handle_oauth_callback() and asserts it returns a bool.
 *
 * @package DataMachineSocials\Tests\Unit\Handlers
 */

namespace DataMachineSocials\Tests\Unit\Handlers;

use DataMachine\Core\OAuth\OAuth2Handler;
use WP_UnitTestCase;

/**
 * OAuth2Handler double that records the storage callback instead of
 * performing a token exchange.
 */
class CapturingOAuth2Handler extends OAuth2Handler {

	/** @var callable|null */
	public $storage_fn = null;

	public function handle_callback(
		string $provider_key,
		string $token_url,
		array $token_params,
		callable $account_details_fn,
		?callable $token_transform_fn = null,
		?callable $storage_fn = null
	) {
		$this->storage_fn = $storage_fn;
		return true;
	}
}

class OAuthStorageCallbackContractTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	/**
	 * @return array<string, array{class-string}>
	 */
	public static function providers(): array {
		return array(
			'facebook'  => array( \DataMachineSocials\Handlers\Facebook\FacebookAuth::class ),
			'instagram' => array( \DataMachineSocials\Handlers\Instagram\InstagramAuth::class ),
			'linkedin'  => array( \DataMachineSocials\Handlers\LinkedIn\LinkedInAuth::class ),
			'pinterest' => array( \DataMachineSocials\Handlers\Pinterest\PinterestAuth::class ),
			'reddit'    => array( \DataMachineSocials\Handlers\Reddit\RedditAuth::class ),
			'threads'   => array( \DataMachineSocials\Handlers\Threads\ThreadsAuth::class ),
			'tiktok'    => array( \DataMachineSocials\Handlers\TikTok\TikTokAuth::class ),
			'tumblr'    => array( \DataMachineSocials\Handlers\Tumblr\TumblrAuth::class ),
			'youtube'   => array( \DataMachineSocials\Handlers\YouTube\YouTubeAuth::class ),
		);
	}

	/**
	 * @dataProvider providers
	 *
	 * @param class-string $auth_class Provider auth class.
	 */
	public function test_storage_callback_returns_bool( string $auth_class ): void {
		$auth    = new $auth_class();
		$capture = new CapturingOAuth2Handler();

		// Some providers redirect away before the token exchange when app
		// credentials are missing; configure every declared field.
		$config = array();
		foreach ( array_keys( $auth->get_config_fields() ) as $field ) {
			$config[ $field ] = 'test-' . $field;
		}
		$auth->save_config( $config );

		$property = new \ReflectionProperty( $auth, 'oauth2' );
		$property->setAccessible( true );
		$property->setValue( $auth, $capture );

		$auth->handle_oauth_callback();

		$this->assertIsCallable( $capture->storage_fn, "{$auth_class} must pass a storage callback" );

		$result = ( $capture->storage_fn )(
			array(
				'access_token'      => 'tok',
				'user_access_token' => 'user_tok',
				'page_access_token' => 'page_tok',
				'token_expires_at'  => time() + 3600,
			)
		);

		$this->assertIsBool( $result, "{$auth_class} storage callback must return bool for OAuth2Handler::store_callback_account()" );
		$this->assertTrue( $result );
	}
}
