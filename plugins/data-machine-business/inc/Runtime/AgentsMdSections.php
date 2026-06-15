<?php
/**
 * AGENTS.md section registration for Data Machine Business.
 *
 * Data Machine core owns the AGENTS.md substrate (SectionRegistry). Each plugin
 * that adds WP-CLI surface registers its own section so the generated AGENTS.md
 * describes the real, registered command surface instead of a hand-typed list
 * that silently drifts.
 *
 * The analytics CLI commands this plugin owns (ga, gsc, bing, pagespeed) and its
 * media command are flat `__invoke` commands — the action is a positional arg,
 * not a WP-CLI subcommand — so their truthful reflection unit is the command's
 * own class-docblock summary (what `wp help <cmd>` shows). This mirrors how Data
 * Machine Code registers its AGENTS.md section from reflection over its command
 * classes, and stays self-contained pending the shared command-tree introspector
 * tracked in Extra-Chill/data-machine#2613.
 *
 * Context safety: callbacks run on `plugins_loaded` in web/cron compose contexts,
 * NOT only under WP-CLI. Reflection over autoloadable command classes never
 * touches the live WP_CLI runner and never instantiates the command classes.
 *
 * @package DataMachineBusiness\Runtime
 */

namespace DataMachineBusiness\Runtime;

defined( 'ABSPATH' ) || exit;

use ReflectionClass;

/**
 * Registers the Data Machine Business section in the composable AGENTS.md.
 */
final class AgentsMdSections {

	/**
	 * Command class => `wp ...` invocation, in display order.
	 *
	 * @var array<class-string, string>
	 */
	private const COMMANDS = array(
		'\\DataMachineBusiness\\Cli\\GoogleAnalyticsCommand' => 'datamachine analytics ga <action>',
		'\\DataMachineBusiness\\Cli\\GoogleSearchConsoleCommand' => 'datamachine analytics gsc <action>',
		'\\DataMachineBusiness\\Cli\\Commands\\BingWebmasterCommand' => 'datamachine analytics bing <action>',
		'\\DataMachineBusiness\\Cli\\PageSpeedCommand'    => 'datamachine analytics pagespeed <action>',
		'\\DataMachineBusiness\\Cli\\MediaHygieneCommand' => 'datamachine media <action>',
	);

	/**
	 * Register the Data Machine Business AGENTS.md section.
	 */
	public static function register(): void {
		if ( ! class_exists( '\\DataMachine\\Engine\\AI\\SectionRegistry' ) ) {
			return;
		}

		$registry = '\\DataMachine\\Engine\\AI\\SectionRegistry';
		if ( ! is_callable( array( $registry, 'register' ) ) ) {
			return;
		}

		call_user_func(
			array( $registry, 'register' ),
			'AGENTS.md',
			'data-machine-business',
			25,
			array( self::class, 'render' ),
			array(
				'label'       => 'Data Machine Business',
				'description' => 'Business-owned WP-CLI surface (Google Analytics, Search Console, Bing, PageSpeed, media hygiene).',
				'owner'       => 'data-machine-business',
				'freshness'   => 'snapshot',
				'conditions'  => 'Registered when Data Machine Business and composable memory section registration are available.',
			)
		);
	}

	/**
	 * Render the section body from reflection over the command classes.
	 *
	 * @return string Markdown section, or '' when no commands are available.
	 */
	public static function render(): string {
		$wp    = self::resolve_wp_cli_cmd();
		$lines = array();

		foreach ( self::COMMANDS as $class => $invocation ) {
			$summary = self::command_summary( $class );
			if ( '' === $summary ) {
				continue;
			}

			$lines[] = sprintf( '- `%s %s` — %s', $wp, $invocation, $summary );
		}

		if ( empty( $lines ) ) {
			return '';
		}

		$body = implode( "\n", $lines );

		return <<<MD
## Data Machine Business

Business-owned WP-CLI commands. The action is a positional argument — run `{$wp} help <command>` to discover the available actions and options for each.

{$body}
MD;
	}

	/**
	 * Extract a command class's short description from its class docblock.
	 *
	 * Flat `__invoke` commands carry their summary on the class docblock (the
	 * first non-tag line), which is what `wp help <command>` displays. Returns ''
	 * when the class is unavailable or undocumented, so the command is skipped
	 * rather than rendered with an empty description.
	 *
	 * @param class-string $command_class Fully-qualified command class name.
	 * @return string Short description, or '' when none.
	 */
	private static function command_summary( string $command_class ): string {
		if ( ! class_exists( $command_class ) ) {
			return '';
		}

		$doc = ( new ReflectionClass( $command_class ) )->getDocComment();
		if ( ! is_string( $doc ) || '' === $doc ) {
			return '';
		}

		$lines = preg_split( '/\r\n|\r|\n/', $doc );
		if ( false === $lines ) {
			return '';
		}

		foreach ( $lines as $line ) {
			// Strip docblock decorations: leading `/**` or `*`, trailing `*/`.
			$line = trim( $line );
			$line = (string) preg_replace( '#^/?\*+#', '', $line );
			$line = (string) preg_replace( '#\*+/$#', '', $line );
			$line = trim( $line );

			if ( '' === $line || '@' === $line[0] || '#' === $line[0] ) {
				continue;
			}

			return rtrim( $line, '.' );
		}

		return '';
	}

	/**
	 * Resolve the WP-CLI command prefix for the current environment.
	 *
	 * @return string
	 */
	private static function resolve_wp_cli_cmd(): string {
		$parts = array( 'wp' );

		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$parts[] = '--allow-root';
		}

		$abspath = rtrim( ABSPATH, '/' );
		if ( '/var/www/html' !== $abspath ) {
			$parts[] = '--path=' . $abspath;
		}

		return apply_filters( 'datamachine_wp_cli_cmd', implode( ' ', $parts ) );
	}
}
