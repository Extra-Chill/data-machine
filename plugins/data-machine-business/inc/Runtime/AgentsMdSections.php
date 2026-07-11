<?php
/**
 * AGENTS.md section registration for Data Machine Business.
 *
 * Data Machine core owns the AGENTS.md substrate (SectionRegistry). Each plugin
 * that adds WP-CLI surface registers its own section so the generated AGENTS.md
 * describes the real, registered command surface instead of a hand-typed list
 * that silently drifts.
 *
 * The command surface is read from {@see \DataMachineBusiness\Cli\CommandRegistry},
 * the single source of truth shared with the WP-CLI bootstrap. Each command is
 * reflected via the shared `\DataMachine\Engine\AI\CliCommandIntrospector`
 * (the shared CLI-introspection pattern a consumer CLI plugin also uses) when
 * present, with a minimal, self-contained fallback so this plugin never
 * hard-depends on an unreleased core class. Every command this plugin owns is a flat `__invoke` command — the
 * action is a positional argument, not a WP-CLI subcommand — so each reflects
 * to a single `__default` entry and renders as a headline carrying the
 * command's own short description.
 *
 * Context safety: callbacks run on `plugins_loaded` in web/cron compose
 * contexts, NOT only under WP-CLI. Reflection over autoloadable command classes
 * never touches the live WP_CLI runner and never instantiates the command
 * classes. The flat-`__invoke` action list is read from a static `actions()`
 * method (when a command declares one) via a static call — also instantiation-
 * free — so action enumeration stays context-safe.
 *
 * @package DataMachineBusiness\Runtime
 */

namespace DataMachineBusiness\Runtime;

use DataMachineBusiness\Cli\CommandRegistry;
use ReflectionClass;
use ReflectionMethod;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Data Machine Business section in the composable AGENTS.md.
 */
final class AgentsMdSections {

	/**
	 * Fully-qualified shared introspector class.
	 *
	 * @var string
	 */
	private const INTROSPECTOR = '\\DataMachine\\Engine\\AI\\CliCommandIntrospector';

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
	 * Render the section body from reflection over the registered commands.
	 *
	 * @return string Markdown section, or '' when no commands are available.
	 */
	public static function render(): string {
		$wp    = self::resolve_wp_cli_cmd();
		$lines = array();

		foreach ( CommandRegistry::map() as $command => $class ) {
			$subcommands = self::describe_class( $class );
			if ( empty( $subcommands ) ) {
				continue;
			}

			$default = self::default_subcommand( $subcommands );

			if ( null !== $default ) {
				// Flat `__invoke` command: render the command itself as the
				// headline, described by its own short description.
				$summary = $default['description'];
				$lines[] = '' !== $summary
					? sprintf( '- `%s %s <action>` — %s', $wp, $command, $summary )
					: sprintf( '- `%s %s <action>`', $wp, $command );

				// Flat commands carry their verbs as a positional <action>
				// argument dispatched inside __invoke, which reflection cannot
				// see. When the command exposes a static actions() accessor,
				// enumerate each action as a bullet so AGENTS.md shows the real
				// surface (mirrors the extrachill-cli subcommand-bullet depth).
				foreach ( self::flat_invoke_actions( $class ) as $name => $description ) {
					$lines[] = '' !== $description
						? sprintf( '  - `%s` — %s', $name, $description )
						: sprintf( '  - `%s`', $name );
				}
				continue;
			}

			// Command with real sub-verbs: headline + one bullet per subcommand.
			$names   = array();
			$details = array();
			foreach ( $subcommands as $subcommand ) {
				$names[]   = $subcommand['name'];
				$desc      = $subcommand['description'];
				$details[] = '' !== $desc
					? sprintf( '  - `%s` — %s', $subcommand['name'], $desc )
					: sprintf( '  - `%s`', $subcommand['name'] );
			}

			$lines[] = sprintf( '- `%s %s` — %s', $wp, $command, implode( ', ', $names ) );
			$lines   = array_merge( $lines, $details );
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
	 * Describe a command class's subcommands.
	 *
	 * Prefers the shared `CliCommandIntrospector` (which is `__invoke`-aware:
	 * a flat command surfaces as a single `__default` entry). Falls back to a
	 * minimal local reflection path so this plugin never hard-depends on an
	 * unreleased — or not-yet-deployed — core class. An empty result from the
	 * shared helper (e.g. a pre-`__invoke`-aware build that skips `__invoke`
	 * methods) also falls through to the local reflection.
	 *
	 * @param class-string $command_class Fully-qualified command class name.
	 * @return array<int, array{name: string, description: string}>
	 */
	private static function describe_class( string $command_class ): array {
		if ( class_exists( self::INTROSPECTOR )
			&& method_exists( self::INTROSPECTOR, 'describe_class' )
		) {
			$described = call_user_func( array( self::INTROSPECTOR, 'describe_class' ), $command_class );
			if ( is_array( $described ) && ! empty( $described ) ) {
				return $described;
			}
		}

		return self::reflect_class( $command_class );
	}

	/**
	 * Return the `__default` (flat `__invoke`) subcommand when the command is
	 * a single directly-invokable command with no sub-verbs.
	 *
	 * @param array<int, array{name: string, description: string}> $subcommands Subcommands.
	 * @return array{name: string, description: string}|null
	 */
	private static function default_subcommand( array $subcommands ): ?array {
		if ( 1 !== count( $subcommands ) ) {
			return null;
		}

		$only = $subcommands[0];

		return ( isset( $only['name'] ) && '__default' === $only['name'] ) ? $only : null;
	}

	/**
	 * Read the action list for a flat `__invoke` command, when available.
	 *
	 * Flat `__invoke` commands dispatch their verbs from a positional
	 * `<action>` argument inside `__invoke` (a switch/match), so the valid
	 * action names are invisible to method reflection. A command may declare
	 * them as a public static `actions()` accessor returning either:
	 *
	 *   - An associative `array<string,string>` of `action => short description`.
	 *   - An indexed `array<int,string>` of bare action names.
	 *
	 * Both shapes are normalized to `array<string,string>` (missing
	 * descriptions become ''). This is context-safe: a static method call
	 * never instantiates the command class, so it is safe to run from
	 * `plugins_loaded` in web/cron compose contexts. Returns an empty array
	 * when the class is absent or declares no `actions()` accessor, leaving
	 * the bare headline (no action bullets) as the graceful fallback.
	 *
	 * @param class-string $command_class Fully-qualified command class name.
	 * @return array<string,string> Action name => short description.
	 */
	private static function flat_invoke_actions( string $command_class ): array {
		if ( ! class_exists( $command_class ) || ! method_exists( $command_class, 'actions' ) ) {
			return array();
		}

		try {
			$actions = call_user_func( array( $command_class, 'actions' ) );
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( ! is_array( $actions ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $actions as $key => $value ) {
			if ( is_int( $key ) ) {
				// Indexed array: the value is the action name.
				if ( is_string( $value ) && '' !== $value ) {
					$normalized[ $value ] = '';
				}
				continue;
			}

			// Associative array: key is the action, value is the description.
			if ( is_string( $key ) && '' !== $key ) {
				$normalized[ $key ] = is_string( $value ) ? $value : '';
			}
		}

		return $normalized;
	}

	/**
	 * Self-contained reflection fallback over a command class.
	 *
	 * Mirrors the shared introspector's `__invoke`-aware contract: `__invoke`
	 * (the directly-invokable command handler) maps to a single `__default`
	 * entry carrying its short description; other public, non-static, non-magic
	 * methods map to subcommands (underscores converted to hyphens). Returns ''
	 * descriptions for undocumented methods, and skips them entirely.
	 *
	 * @param class-string $command_class Fully-qualified command class name.
	 * @return array<int, array{name: string, description: string}>
	 */
	private static function reflect_class( string $command_class ): array {
		if ( ! class_exists( $command_class ) ) {
			return array();
		}

		try {
			$reflection = new ReflectionClass( $command_class );
		} catch ( \ReflectionException $e ) {
			return array();
		}

		$subcommands = array();

		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( $method->isStatic() ) {
				continue;
			}

			$name = $method->getName();
			$doc  = $method->getDocComment();
			$doc  = is_string( $doc ) ? $doc : '';

			if ( '__invoke' === $name ) {
				$subcommands['__default'] = array(
					'name'        => '__default',
					'description' => self::short_description( $doc ),
				);
				continue;
			}

			if ( 0 === strpos( $name, '__' ) ) {
				continue;
			}

			if ( '' === $doc ) {
				continue;
			}

			$verb                 = str_replace( '_', '-', $name );
			$subcommands[ $verb ] = array(
				'name'        => $verb,
				'description' => self::short_description( $doc ),
			);
		}

		// A `__default` entry is the whole command — never mixed with verbs.
		if ( isset( $subcommands['__default'] ) && count( $subcommands ) > 1 ) {
			unset( $subcommands['__default'] );
		}

		ksort( $subcommands, SORT_STRING );

		return array_values( $subcommands );
	}

	/**
	 * Extract a docblock's short description (first prose line).
	 *
	 * Mirrors WP-CLI's short-description parsing: the first non-empty content
	 * line of the docblock, before any `## SECTION` heading or `@tag`.
	 *
	 * @param string $doc Raw docblock.
	 * @return string Short description, or '' when none.
	 */
	private static function short_description( string $doc ): string {
		if ( '' === $doc ) {
			return '';
		}

		$lines = preg_split( '/\r\n|\r|\n/', $doc );
		if ( false === $lines ) {
			return '';
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );
			$line = (string) preg_replace( '#^/?\*+#', '', $line );
			$line = (string) preg_replace( '#\*+/$#', '', $line );
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( '@' === $line[0] || '#' === $line[0] ) {
				return '';
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
