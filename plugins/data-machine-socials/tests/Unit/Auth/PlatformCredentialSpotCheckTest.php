<?php
/**
 * Per-platform spot checks that every social ability resolves credentials
 * through CredentialResolver.
 *
 * With the platform's provider unregistered, one ability per platform must
 * fail with the resolver's standard missing_auth error and never reach HTTP.
 *
 * @package DataMachineSocials\Tests\Unit\Auth
 */

namespace DataMachineSocials\Tests\Unit\Auth;

use DataMachine\Abilities\AuthAbilities;
use DataMachineSocials\Abilities\Bluesky\BlueskyPublishAbility;
use DataMachineSocials\Abilities\Facebook\FacebookReadAbility;
use DataMachineSocials\Abilities\Instagram\InstagramPublishAbility;
use DataMachineSocials\Abilities\LinkedIn\LinkedInPublishAbility;
use DataMachineSocials\Abilities\Mastodon\MastodonPublishAbility;
use DataMachineSocials\Abilities\Pinterest\PinterestBoardsAbility;
use DataMachineSocials\Abilities\Reddit\ReplyRedditAbility;
use DataMachineSocials\Abilities\Threads\ThreadsPublishAbility;
use DataMachineSocials\Abilities\TikTok\TikTokPublishAbility;
use DataMachineSocials\Abilities\Tumblr\TumblrPublishAbility;
use DataMachineSocials\Abilities\Twitter\TwitterPublishAbility;
use DataMachineSocials\Abilities\YouTube\YouTubeAccountAbility;
use WP_UnitTestCase;

final class PlatformCredentialSpotCheckTest extends WP_UnitTestCase {

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
		remove_all_filters( 'pre_http_request' );
		AuthAbilities::clearCache();
		parent::tear_down();
	}

	/**
	 * @dataProvider platformProvider
	 */
	public function test_missing_provider_returns_resolver_error_without_http( string $platform, string $label, callable $call ): void {
		$this->provider_filter = static function ( $providers ) use ( $platform ) {
			unset( $providers[ $platform ] );
			return $providers;
		};
		add_filter( 'datamachine_auth_providers', $this->provider_filter, PHP_INT_MAX );
		AuthAbilities::clearCache();

		add_filter(
			'pre_http_request',
			function () use ( $platform ) {
				$this->fail( "HTTP must not run without {$platform} credentials" );
			}
		);

		$result = $call();

		$this->assertWPError( $result );
		$this->assertSame( 'missing_auth', $result->get_error_code() );
		$this->assertSame( $label . ' not authenticated', $result->get_error_message() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function platformProvider(): array {
		return array(
			'bluesky'   => array( 'bluesky', 'Bluesky', static fn() => BlueskyPublishAbility::get_account( array() ) ),
			'facebook'  => array( 'facebook', 'Facebook', static fn() => ( new FacebookReadAbility() )->execute( array( 'action' => 'list' ) ) ),
			'instagram' => array( 'instagram', 'Instagram', static fn() => InstagramPublishAbility::get_account( array() ) ),
			'linkedin'  => array( 'linkedin', 'LinkedIn', static fn() => LinkedInPublishAbility::get_account( array() ) ),
			'mastodon'  => array( 'mastodon', 'Mastodon', static fn() => MastodonPublishAbility::get_account( array() ) ),
			'pinterest' => array( 'pinterest', 'Pinterest', static fn() => PinterestBoardsAbility::sync_boards() ),
			'reddit'    => array(
				'reddit',
				'Reddit',
				static fn() => ( new ReplyRedditAbility() )->execute(
					array(
						'thing_id' => 't3_abc123',
						'text'     => 'Nice set.',
					)
				),
			),
			'threads'   => array( 'threads', 'Threads', static fn() => ThreadsPublishAbility::get_account( array() ) ),
			'tiktok'    => array( 'tiktok', 'TikTok', static fn() => TikTokPublishAbility::get_account( array() ) ),
			'tumblr'    => array(
				'tumblr',
				'Tumblr',
				static fn() => TumblrPublishAbility::execute_publish(
					array(
						'body'            => 'Tour dates',
						'blog_identifier' => 'example',
					)
				),
			),
			'twitter'   => array( 'twitter', 'Twitter', static fn() => TwitterPublishAbility::get_account( array() ) ),
			'youtube'   => array( 'youtube', 'YouTube', static fn() => YouTubeAccountAbility::get_account( array() ) ),
		);
	}
}
