<?php
/**
 * WP-CLI Command Registry for Data Machine Business.
 *
 * Single source of truth mapping `wp datamachine ...` command strings to the
 * command classes that implement them. The WP-CLI bootstrap calls
 * WP_CLI::add_command for each entry.
 *
 * @package DataMachineBusiness\Cli
 */

namespace DataMachineBusiness\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical map of Data Machine Business WP-CLI commands.
 */
final class CommandRegistry {

	/**
	 * Map of command string => fully-qualified command class.
	 *
	 * Keys are the exact strings passed to WP_CLI::add_command (the command
	 * namespace, e.g. "datamachine analytics ga"). Order here determines
	 * registration order.
	 *
	 * Most commands this plugin owns are flat `__invoke` commands. Provider
	 * integrations that preserve an established subcommand contract may expose
	 * public subcommand methods instead.
	 *
	 * @return array<string, class-string>
	 */
	public static function map(): array {
		return array(
			'datamachine indexnow'            => IndexNowCommand::class,
			'datamachine analytics ga'        => GoogleAnalyticsCommand::class,
			'datamachine analytics gsc'       => GoogleSearchConsoleCommand::class,
			'datamachine analytics mediavine' => MediavineCommand::class,
			'datamachine analytics bing'      => Commands\BingWebmasterCommand::class,
			'datamachine analytics pagespeed' => PageSpeedCommand::class,
			'datamachine media'               => MediaHygieneCommand::class,
		);
	}
}
