<?php
/**
 * WP-CLI Command Registry for Data Machine Business.
 *
 * Single source of truth mapping `wp datamachine ...` command strings to the
 * command classes that implement them. Both the WP-CLI bootstrap (which calls
 * WP_CLI::add_command for each entry) and the AGENTS.md section generator
 * (which reflects over each class to describe the real command surface) read
 * from this map, so the documented CLI surface can never drift from what is
 * actually registered.
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
	 * namespace, e.g. "datamachine analytics ga"). Order here determines both
	 * registration order and documentation order.
	 *
	 * Every command this plugin owns is a flat `__invoke` command — the action
	 * is a positional argument, not a WP-CLI subcommand — so each reflects to a
	 * single `__default` entry carrying the command's own short description.
	 *
	 * @return array<string, class-string>
	 */
	public static function map(): array {
		return array(
			'datamachine analytics ga'        => GoogleAnalyticsCommand::class,
			'datamachine analytics gsc'       => GoogleSearchConsoleCommand::class,
			'datamachine analytics bing'      => Commands\BingWebmasterCommand::class,
			'datamachine analytics pagespeed' => PageSpeedCommand::class,
			'datamachine media'               => MediaHygieneCommand::class,
		);
	}
}
