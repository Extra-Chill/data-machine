<?php
/**
 * WP-CLI Command Registry
 *
 * Single source of truth mapping `datamachine-socials ...` command strings to
 * their implementing command classes. The WP-CLI bootstrap registers every
 * entry in this map.
 *
 * @package DataMachineSocials\Cli
 */

namespace DataMachineSocials\Cli;

defined( 'ABSPATH' ) || exit;

class CommandRegistry {

	/**
	 * Map of command string => fully-qualified command class.
	 *
	 * Keys are the exact strings passed to WP_CLI::add_command (the command
	 * namespace, e.g. "datamachine-socials reddit"). Order here determines
	 * registration order.
	 *
	 * @return array<string, class-string>
	 */
	public static function map() {
		return array(
			'datamachine-socials comments'  => Commands\CommentsCommand::class,
			'datamachine-socials linkedin'  => Commands\LinkedInCommand::class,
			'datamachine-socials pinterest' => Commands\PinterestCommand::class,
			'datamachine-socials tumblr'    => Commands\TumblrCommand::class,
			'datamachine-socials reddit'    => Commands\RedditCommand::class,
			'datamachine-socials instagram' => Commands\InstagramCommand::class,
			'datamachine-socials tiktok'    => Commands\TikTokCommand::class,
			'datamachine-socials threads'   => Commands\ThreadsCommand::class,
			'datamachine-socials facebook'  => Commands\FacebookCommand::class,
			'datamachine-socials twitter'   => Commands\TwitterCommand::class,
			'datamachine-socials bluesky'   => Commands\BlueskyCommand::class,
			'datamachine-socials youtube'   => Commands\YouTubeCommand::class,
			'datamachine-socials mastodon'  => Commands\MastodonCommand::class,
			'datamachine-socials shares'    => Commands\SharesCommand::class,
		);
	}

	/**
	 * Resolve the absolute file path for a registered command class.
	 *
	 * The command classes live under the plugin's PSR-4 root
	 * (`DataMachineSocials\Cli\Commands\FooCommand` => `inc/Cli/Commands/FooCommand.php`).
	 * The WP-CLI bootstrap uses this to load each registered command class.
	 *
	 * @param class-string $command_class Fully-qualified command class.
	 * @return string Absolute file path (may not exist).
	 */
	public static function file_for_class( $command_class ) {
		$relative = substr( $command_class, strlen( 'DataMachineSocials\\' ) );
		$relative = str_replace( '\\', '/', $relative );

		return DATAMACHINE_SOCIALS_PATH . 'inc/' . $relative . '.php';
	}
}
