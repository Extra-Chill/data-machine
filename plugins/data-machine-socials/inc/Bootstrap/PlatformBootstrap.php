<?php
/**
 * Data Machine Socials platform bootstrap registry.
 *
 * @package DataMachineSocials\Bootstrap
 */

namespace DataMachineSocials\Bootstrap;

use DataMachineSocials\Cli\CommandRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Owns deterministic, failure-isolated registration of social integrations.
 */
final class PlatformBootstrap {

	private static ?self $instance = null;

	/** @var array<int, PlatformProvider> */
	private array $providers;

	/** @var array<int, string> */
	private array $duplicates = array();

	/** @param array<int, PlatformProvider> $providers Ordered platform providers. */
	public function __construct( array $providers ) {
		$this->providers = $providers;
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self( self::platform_providers() );
		}

		return self::$instance;
	}

	/**
	 * Register all platform abilities and handlers in catalog order.
	 */
	public function register(): void {
		$seen = array();

		foreach ( $this->providers as $provider ) {
			if ( isset( $seen[ $provider->id() ] ) ) {
				$provider->mark_duplicate();
				$this->duplicates[] = $provider->id();
				continue;
			}

			$seen[ $provider->id() ] = true;
			$provider->register();
		}
	}

	/**
	 * Register chat tools for every available platform.
	 */
	public function register_tools(): void {
		foreach ( $this->ordered_providers( array( 'reddit', 'instagram', 'tiktok', 'twitter', 'facebook', 'bluesky', 'threads', 'pinterest', 'youtube', 'linkedin', 'tumblr', 'mastodon' ) ) as $provider ) {
			$provider->register_tools();
		}
	}

	/**
	 * Register CLI commands for every available platform.
	 */
	public function register_cli(): void {
		foreach ( $this->ordered_providers( array( 'linkedin', 'pinterest', 'tumblr', 'reddit', 'instagram', 'tiktok', 'threads', 'facebook', 'twitter', 'bluesky', 'youtube', 'mastodon' ) ) as $provider ) {
			$provider->register_cli();
		}
	}

	/**
	 * Return platform availability keyed by stable platform ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function availability(): array {
		$availability = array();

		foreach ( $this->providers as $provider ) {
			if ( ! isset( $availability[ $provider->id() ] ) ) {
				$availability[ $provider->id() ] = $provider->availability();
			}
		}

		return $availability;
	}

	/** @return array<int, string> */
	public function duplicates(): array {
		return array_values( array_unique( $this->duplicates ) );
	}

	/**
	 * Return providers in a phase-specific stable order without losing unknown
	 * providers supplied to a test or future Socials-owned catalog extension.
	 *
	 * @param array<int, string> $ids Preferred platform order.
	 * @return array<int, PlatformProvider>
	 */
	private function ordered_providers( array $ids ): array {
		$order = array_flip( $ids );
		$next  = count( $order );

		$providers = $this->providers;
		usort(
			$providers,
			static function ( PlatformProvider $left, PlatformProvider $right ) use ( $order, $next ): int {
				return ( $order[ $left->id() ] ?? $next ) <=> ( $order[ $right->id() ] ?? $next );
			}
		);

		return $providers;
	}

	/**
	 * Build the ordered Socials-owned provider catalog.
	 *
	 * Handler order intentionally matches the pre-provider bootstrap so platform
	 * configuration discovery remains stable.
	 *
	 * @return array<int, PlatformProvider>
	 */
	private static function platform_providers(): array {
		return array(
			self::provider( 'twitter', 'Twitter', array( 'TwitterPublishAbility', 'TwitterReadAbility', 'TwitterUpdateAbility', 'TwitterDeleteAbility' ), array( 'datamachine/twitter-publish', 'datamachine/twitter-account', 'datamachine/twitter-read', 'datamachine/twitter-update', 'datamachine/twitter-delete' ), array( 'PublishTwitter', 'ReadTwitter', 'UpdateTwitter', 'DeleteTwitter' ) ),
			self::provider( 'facebook', 'Facebook', array( 'FacebookPublishAbility', 'FacebookReadAbility', 'FacebookUpdateAbility', 'FacebookDeleteAbility' ), array( 'datamachine/facebook-publish', 'datamachine/facebook-pages', 'datamachine/facebook-read', 'datamachine/facebook-update', 'datamachine/facebook-delete' ), array( 'PublishFacebook', 'ReadFacebook', 'UpdateFacebook', 'DeleteFacebook' ) ),
			self::provider( 'threads', 'Threads', array( 'ThreadsPublishAbility', 'ThreadsReadAbility', 'ThreadsUpdateAbility', 'ThreadsDeleteAbility' ), array( 'datamachine/threads-publish', 'datamachine/threads-account', 'datamachine/threads-read', 'datamachine/threads-update', 'datamachine/threads-delete' ), array( 'PublishThreads', 'ReadThreads', 'UpdateThreads', 'DeleteThreads' ) ),
			self::provider( 'bluesky', 'Bluesky', array( 'BlueskyPublishAbility', 'BlueskyReadAbility', 'BlueskyUpdateAbility', 'BlueskyDeleteAbility' ), array( 'datamachine/bluesky-publish', 'datamachine/bluesky-account', 'datamachine/bluesky-read', 'datamachine/bluesky-update', 'datamachine/bluesky-delete' ), array( 'PublishBluesky', 'ReadBluesky', 'UpdateBluesky', 'DeleteBluesky' ) ),
			self::provider( 'mastodon', 'Mastodon', array( 'MastodonPublishAbility', 'MastodonReadAbility', 'MastodonUpdateAbility', 'MastodonDeleteAbility' ), array( 'datamachine/mastodon-publish', 'datamachine/mastodon-account', 'datamachine/mastodon-read', 'datamachine/mastodon-update', 'datamachine/mastodon-delete' ), array() ),
			self::provider( 'pinterest', 'Pinterest', array( 'PinterestBoardsAbility', 'PinterestPublishAbility', 'PinterestReadAbility', 'PinterestUpdateAbility', 'PinterestDeleteAbility', 'PinterestAnalyticsAbility' ), array( 'datamachine/pinterest-sync-boards', 'datamachine/pinterest-list-boards', 'datamachine/pinterest-status', 'datamachine/pinterest-publish', 'datamachine/pinterest-read', 'datamachine/pinterest-update', 'datamachine/pinterest-delete', 'datamachine/pinterest-analytics' ), array( 'PublishPinterest', 'ReadPinterest', 'UpdatePinterest', 'DeletePinterest' ) ),
			self::provider( 'instagram', 'Instagram', array( 'InstagramPublishAbility', 'InstagramReadAbility', 'InstagramUpdateAbility', 'InstagramDeleteAbility', 'InstagramCommentReplyAbility' ), array( 'datamachine/instagram-publish', 'datamachine/instagram-account', 'datamachine/instagram-read', 'datamachine/instagram-update', 'datamachine/instagram-delete', 'datamachine/instagram-comment-reply' ), array( 'ReadInstagram', 'UpdateInstagram', 'ReplyInstagramComment', 'PublishInstagram', 'PublishReelInstagram', 'PublishStoryInstagram', 'DeleteInstagram' ) ),
			self::provider( 'tiktok', 'TikTok', array( 'TikTokPublishAbility', 'TikTokReadAbility' ), array( 'datamachine/tiktok-publish', 'datamachine/tiktok-account', 'datamachine/tiktok-read' ), array( 'PublishTikTok' ) ),
			self::provider( 'linkedin', 'LinkedIn', array( 'LinkedInPublishAbility', 'LinkedInReadAbility', 'LinkedInUpdateAbility', 'LinkedInDeleteAbility' ), array( 'datamachine/linkedin-publish', 'datamachine/linkedin-account', 'datamachine/linkedin-read', 'datamachine/linkedin-update', 'datamachine/linkedin-delete' ), array( 'PublishLinkedIn', 'ReadLinkedIn', 'UpdateLinkedIn', 'DeleteLinkedIn' ) ),
			self::provider( 'tumblr', 'Tumblr', array( 'TumblrPublishAbility', 'TumblrReadAbility', 'TumblrUpdateAbility', 'TumblrDeleteAbility', 'TumblrEngageAbility' ), array( 'datamachine/tumblr-publish', 'datamachine/tumblr-read', 'datamachine/tumblr-update', 'datamachine/tumblr-delete', 'datamachine/tumblr-engage' ), array( 'PublishTumblr', 'ReadTumblr' ) ),
			self::provider( 'reddit', 'Reddit', array( 'FetchRedditAbility', 'RedditDomainMentionsAbility', 'RedditCommentDomainMonitorAbility', 'ReplyRedditAbility', 'SubmitRedditAbility', 'VoteRedditAbility' ), array( 'datamachine/fetch-reddit', 'datamachine/reddit-domain-mentions', 'datamachine/reddit-comment-mentions-poll', 'datamachine/reddit-comment-mentions-report', 'datamachine/reddit-comment-mentions-cleanup', 'datamachine/reply-reddit', 'datamachine/submit-reddit', 'datamachine/vote-reddit' ), array( 'FetchReddit', 'ReplyReddit', 'SubmitReddit', 'VoteReddit' ) ),
			self::provider( 'youtube', 'YouTube', array( 'YouTubeUploadAbility', 'YouTubeSearchAbility', 'YouTubeAccountAbility' ), array( 'datamachine/youtube-upload', 'datamachine/youtube-search', 'datamachine/youtube-account' ), array( 'PublishYouTube', 'SearchYouTube' ) ),
		);
	}

	/**
	 * Create one declarative platform provider.
	 *
	 * @param string            $id            Platform ID and handler namespace.
	 * @param string            $namespace_part PSR-4 platform namespace segment.
	 * @param array<int,string> $ability_names Ability class basenames.
	 * @param array<int,string> $ability_slugs Public ability slugs.
	 * @param array<int,string> $tool_names    Chat tool class basenames.
	 */
	private static function provider( string $id, string $namespace_part, array $ability_names, array $ability_slugs, array $tool_names ): PlatformProvider {
		$ability_classes = array_map(
			static function ( string $class_name ) use ( $namespace_part ): string {
				return 'DataMachineSocials\\Abilities\\' . $namespace_part . '\\' . $class_name;
			},
			$ability_names
		);
		/** @var array<int, class-string> $ability_classes */
		$handler_class = 'DataMachineSocials\\Handlers\\' . $namespace_part . '\\' . $namespace_part;
		/** @var class-string $handler_class */
		$tool_classes = array_map(
			static function ( string $class_name ): string {
				return 'DataMachineSocials\\Chat\\Tools\\' . $class_name;
			},
			$tool_names
		);
		$command      = 'datamachine-socials ' . $id;

		return new PlatformProvider(
			$id,
			array_merge( array( 'DataMachine\\Core\\Steps\\Publish\\Handlers\\PublishHandler' ), $ability_classes, array( $handler_class ) ),
			array(
				'abilities'    => $ability_slugs,
				'handler'      => 'reddit' === $id ? 'reddit' : $id . '_publish',
				'tools'        => $tool_names,
				'cli'          => $command,
				'requirements' => array(
					'tools' => 'DataMachine\\Engine\\AI\\Tools\\BaseTool',
					'cli'   => 'WP_CLI',
				),
			),
			static function () use ( $ability_classes, $handler_class ): void {
				foreach ( $ability_classes as $ability_class ) {
					new $ability_class();
				}
				new $handler_class();
			},
			$tool_classes ? static function () use ( $tool_classes ): bool {
				if ( ! class_exists( 'DataMachine\\Engine\\AI\\Tools\\BaseTool' ) ) {
					return false;
				}

				foreach ( $tool_classes as $tool_class ) {
					new $tool_class();
				}

				return true;
			} : null,
			static function () use ( $command ): bool {
				if ( ! defined( 'WP_CLI' ) ) {
					return false;
				}

				$command_map   = CommandRegistry::map();
				$command_class = $command_map[ $command ];
				require_once CommandRegistry::file_for_class( $command_class );
				\WP_CLI::add_command( $command, $command_class );

				return true;
			}
		);
	}
}
