<?php
/**
 * AGENTS.md section registration for Data Machine Business.
 *
 * @package DataMachineBusiness\Runtime
 */

namespace DataMachineBusiness\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Data Machine Business section in the composable AGENTS.md.
 */
final class AgentsMdSections {

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
				'description' => 'Business-owned analytics, revenue, performance, and media-maintenance integrations.',
				'owner'       => 'data-machine-business',
				'freshness'   => 'snapshot',
				'conditions'  => 'Registered when Data Machine Business and composable memory section registration are available.',
			)
		);
	}

	/**
	 * Render concise intent-based routing for the live command surface.
	 *
	 * @return string Markdown section.
	 */
	public static function render(): string {
		$wp = self::resolve_wp_cli_cmd();

		return <<<MD
## Data Machine Business

Data Machine Business owns analytics, revenue, performance, and media-maintenance integrations on top of Data Machine.

**Default routing**
- Traffic and audience analytics: `{$wp} datamachine analytics ga <action>`
- Search performance and indexing: `{$wp} datamachine analytics gsc <action>` or `{$wp} datamachine analytics bing <action>`
- Publisher revenue reporting: `{$wp} datamachine analytics mediavine <action>`
- Web performance diagnostics: `{$wp} datamachine analytics pagespeed <action>`
- Media diagnostics and cleanup: `{$wp} datamachine media <action>`

**Safety**
Media deletion actions are dry runs unless explicitly passed `--apply`.

**Discovery**
Use `{$wp} datamachine --help` for the live command map, `{$wp} datamachine analytics --help` for analytics integrations, and the relevant nested `--help` for current actions. Use `{$wp} help <command>` for a command's complete positional arguments and options. Live help is authoritative.
MD;
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
