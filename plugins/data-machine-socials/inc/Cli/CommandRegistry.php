<?php
/**
 * WP-CLI Command Registry
 *
 * Single source of truth mapping `datamachine-socials ...` command strings to
 * their implementing command classes. Both the WP-CLI bootstrap (which calls
 * WP_CLI::add_command for each entry) and the AGENTS.md section generator
 * (which reflects over each class to enumerate real subcommands) read from this
 * map, so the documented CLI surface can never drift from what is actually
 * registered.
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
	 * namespace, e.g. "datamachine-socials reddit"). Order here determines both
	 * registration order and documentation order.
	 *
	 * @return array<string, class-string>
	 */
	public static function map() {
		return array(
			'datamachine-socials comments'  => Commands\CommentsCommand::class,
			'datamachine-socials linkedin'  => Commands\LinkedInCommand::class,
			'datamachine-socials pinterest' => Commands\PinterestCommand::class,
			'datamachine-socials reddit'    => Commands\RedditCommand::class,
			'datamachine-socials instagram' => Commands\InstagramCommand::class,
			'datamachine-socials threads'   => Commands\ThreadsCommand::class,
			'datamachine-socials facebook'  => Commands\FacebookCommand::class,
			'datamachine-socials twitter'   => Commands\TwitterCommand::class,
			'datamachine-socials bluesky'   => Commands\BlueskyCommand::class,
			'datamachine-socials shares'    => Commands\SharesCommand::class,
		);
	}

	/**
	 * Resolve the absolute file path for a registered command class.
	 *
	 * The command classes live under the plugin's PSR-4 root
	 * (`DataMachineSocials\Cli\Commands\FooCommand` => `inc/Cli/Commands/FooCommand.php`).
	 * The section generator uses this to require the class file directly from
	 * disk in web/cron compose contexts where the WP_CLI-guarded `require_once`
	 * block has not run. The class files guard only on ABSPATH, never on WP_CLI,
	 * so loading them outside WP-CLI is safe.
	 *
	 * @param class-string $class Fully-qualified command class.
	 * @return string Absolute file path (may not exist).
	 */
	public static function file_for_class( $class ) {
		$relative = substr( $class, strlen( 'DataMachineSocials\\' ) );
		$relative = str_replace( '\\', '/', $relative );

		return DATAMACHINE_SOCIALS_PATH . 'inc/' . $relative . '.php';
	}
}
