<?php
/**
 * CLI Command Introspector
 *
 * Context-safe introspection of WP-CLI command classes for AGENTS.md section
 * generators. Given a WP-CLI command class (or a namespace => class map), it
 * reflects over the class's public methods and returns each subcommand's name
 * and short description — derived from the same `@subcommand` annotation and
 * PHPDoc summary that WP-CLI itself uses to build `--help`.
 *
 * Why reflection instead of the live WP-CLI runtime registry?
 *
 * `SectionRegistry` callbacks run on `plugins_loaded` in NON-CLI contexts
 * (web/cron compose, auto-regeneration). WP-CLI command classes are typically
 * only registered under `if ( defined( 'WP_CLI' ) && WP_CLI )`, so walking the
 * live `WP_CLI::get_runner()->find_command_to_run()` tree sees nothing when
 * composing from a web request. Reflecting over the command classes works
 * regardless of whether the WP-CLI runner is loaded — the docblocks live in the
 * class source and are always available. This makes generated AGENTS.md command
 * lists derive from registered truth in every execution context.
 *
 * This helper is pure read/derive: no side effects, no WP-CLI invocation, no
 * shelling out. It does not instantiate command classes — it only reflects.
 *
 * @package DataMachine\Engine\AI
 * @since   x.y.z
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

use ReflectionClass;
use ReflectionMethod;

class CliCommandIntrospector {

	/**
	 * Describe all subcommands exposed by one WP-CLI command class.
	 *
	 * Reflects over the class's public, non-static methods and returns each as a
	 * subcommand. The subcommand name is taken from the method's
	 * `@subcommand <name>` annotation when present, otherwise the method name
	 * with underscores converted to hyphens (matching WP-CLI's own
	 * `CommandFactory` fallback). The description is the PHPDoc short
	 * description — the first non-tag line of the method docblock — exactly as
	 * WP-CLI parses it for `--help`.
	 *
	 * A class whose only public command method is `__invoke` (the dominant
	 * "flat command" shape — `wp extrachill venues`, `wp intelligence wiki
	 * brain`, every `datamachine analytics *` command) is the namespace's
	 * directly-invokable command. It reflects to a single `__default`
	 * pseudo-subcommand carrying the `__invoke` docblock's short description,
	 * so consumers can render it as the namespace headline. Other magic methods
	 * (`__construct`, `__destruct`, …) are always skipped.
	 *
	 * @since x.y.z
	 *
	 * @param class-string $command_class Fully-qualified WP-CLI command class name.
	 * @param array        $args          {
	 *     Optional. Behavior overrides.
	 *
	 *     @type bool $include_undocumented Include public methods that have no
	 *                                      docblock. Default false (skip them,
	 *                                      since AGENTS.md should not advertise
	 *                                      undocumented surface).
	 * }
	 * @return array<int, array{name: string, description: string}> List of
	 *         subcommands sorted by name, each with `name` and `description`.
	 *         A single `__default` entry for an `__invoke`-only command. Empty
	 *         array when the class does not exist.
	 */
	public static function describe_class( string $command_class, array $args = array() ): array {
		if ( ! class_exists( $command_class ) ) {
			return array();
		}

		$include_undocumented = ! empty( $args['include_undocumented'] );

		try {
			$reflection = new ReflectionClass( $command_class );
		} catch ( \ReflectionException $e ) {
			return array();
		}

		$subcommands = array();

		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( ! self::is_subcommand_method( $method ) ) {
				continue;
			}

			$doc_comment = $method->getDocComment();
			$doc_comment = is_string( $doc_comment ) ? $doc_comment : '';

			if ( '' === $doc_comment && ! $include_undocumented ) {
				continue;
			}

			$name        = self::subcommand_name( $method, $doc_comment );
			$description = self::short_description( $doc_comment );

			// De-duplicate: multiple methods can never share a subcommand name,
			// but defensive against an aliased class being passed twice.
			$subcommands[ $name ] = array(
				'name'        => $name,
				'description' => $description,
			);
		}

		ksort( $subcommands, SORT_STRING );

		return array_values( $subcommands );
	}

	/**
	 * Describe a namespace by mapping it to one or more command classes.
	 *
	 * Section generators own the namespace => class mapping (since the canonical
	 * mapping lives in each plugin's WP-CLI bootstrap, which is not loaded in web
	 * context). Pass the top-level namespace label and the classes that compose
	 * it. When multiple classes are given, their subcommands are merged.
	 *
	 * For namespaces where each top-level command maps to a distinct class
	 * (e.g. `datamachine memory` => MemoryCommand, `datamachine flows` =>
	 * FlowsCommand), pass an associative map of `command label => class` and use
	 * {@see self::describe_namespace_map()} instead to preserve the command
	 * grouping.
	 *
	 * @since x.y.z
	 *
	 * @param string                      $cli_namespace WP-CLI namespace label (e.g. 'datamachine'). Informational; echoed back.
	 * @param class-string|class-string[] $classes       One class or a list of classes that compose the namespace.
	 * @param array                       $args          Optional. Passed through to {@see self::describe_class()}.
	 * @return array{namespace: string, subcommands: array<int, array{name: string, description: string}>}
	 */
	public static function describe( string $cli_namespace, $classes, array $args = array() ): array {
		$classes     = is_array( $classes ) ? $classes : array( $classes );
		$subcommands = array();

		foreach ( $classes as $class ) {
			if ( ! is_string( $class ) ) {
				continue;
			}

			foreach ( self::describe_class( $class, $args ) as $subcommand ) {
				$subcommands[ $subcommand['name'] ] = $subcommand;
			}
		}

		ksort( $subcommands, SORT_STRING );

		return array(
			'namespace'   => $cli_namespace,
			'subcommands' => array_values( $subcommands ),
		);
	}

	/**
	 * Describe a namespace from a `command label => class` map.
	 *
	 * Returns one entry per top-level command label, each with the command's own
	 * subcommands. This preserves the grouping a generator needs to render lists
	 * like:
	 *
	 *     wp datamachine memory paths|read|write|search|compose
	 *     wp datamachine flow create|run|list
	 *
	 * The map is supplied by the section generator (which owns the canonical
	 * mapping from its WP-CLI bootstrap) so this helper never needs the live
	 * WP-CLI runner.
	 *
	 * This is the canonical path for consumers that own a `command-string =>
	 * class` map (the extrachill-cli / intelligence shape). A flat `__invoke`
	 * command surfaces as a single `__default` subcommand entry; consumers
	 * render that as the command's own headline rather than a sub-verb.
	 *
	 * @since x.y.z
	 *
	 * @param string                      $cli_namespace WP-CLI namespace label (e.g. 'datamachine').
	 * @param array<string, class-string> $command_map   Map of command label => command class.
	 * @param array                       $args          Optional. Passed through to {@see self::describe_class()}.
	 * @return array{namespace: string, commands: array<int, array{command: string, subcommands: array<int, array{name: string, description: string}>}>}
	 */
	public static function describe_namespace_map( string $cli_namespace, array $command_map, array $args = array() ): array {
		$commands = array();

		foreach ( $command_map as $command => $class ) {
			if ( ! is_string( $class ) ) {
				continue;
			}

			$commands[] = array(
				'command'     => (string) $command,
				'subcommands' => self::describe_class( $class, $args ),
			);
		}

		return array(
			'namespace' => $cli_namespace,
			'commands'  => $commands,
		);
	}

	/**
	 * Determine whether a reflected method is a WP-CLI subcommand candidate.
	 *
	 * Mirrors WP-CLI's `CommandFactory::is_good_method()` with one deliberate
	 * addition: `__invoke` IS a subcommand candidate. WP-CLI registers a class
	 * whose handler is `__invoke` as a directly-invokable command (the flat
	 * "one command, no sub-verbs" shape). It is reflected here as the
	 * `__default` pseudo-subcommand (see {@see self::subcommand_name()}).
	 *
	 * All other magic methods (`__construct`, `__destruct`, `__get`, …) and
	 * static methods are not subcommands and are skipped.
	 *
	 * @param ReflectionMethod $method Reflected method.
	 * @return bool
	 */
	private static function is_subcommand_method( ReflectionMethod $method ): bool {
		if ( ! $method->isPublic() || $method->isStatic() ) {
			return false;
		}

		$name = $method->getName();

		// `__invoke` is the directly-invokable command handler — keep it.
		if ( '__invoke' === $name ) {
			return true;
		}

		// Skip every other magic method (`__construct`, `__destruct`, etc.).
		return 0 !== strpos( $name, '__' );
	}

	/**
	 * Resolve the subcommand name for a method.
	 *
	 * Resolution order mirrors WP-CLI's `CommandFactory`:
	 *
	 * 1. The `@subcommand <name>` annotation when present.
	 * 2. For `__invoke` (with no annotation), the `__default` pseudo-subcommand
	 *    — the namespace's directly-invokable command, which consumers render as
	 *    the namespace headline rather than a sub-verb.
	 * 3. Otherwise the method name with underscores converted to hyphens, which
	 *    is exactly how WP-CLI derives a subcommand name from a method name.
	 *
	 * @param ReflectionMethod $method      Reflected method.
	 * @param string           $doc_comment Raw doc comment.
	 * @return string
	 */
	private static function subcommand_name( ReflectionMethod $method, string $doc_comment ): string {
		$tag = self::get_tag( $doc_comment, 'subcommand' );

		if ( '' !== $tag ) {
			return $tag;
		}

		if ( '__invoke' === $method->getName() ) {
			return '__default';
		}

		return str_replace( '_', '-', $method->getName() );
	}

	/**
	 * Extract the short description from a doc comment.
	 *
	 * Replicates WP-CLI's `DocParser::get_shortdesc()`: strip the docblock
	 * decorations (`/**`, leading `*`, trailing `*​/`) then return the first
	 * line that does not begin with `@`.
	 *
	 * @param string $doc_comment Raw doc comment (including `/** ... *​/`).
	 * @return string Short description, or '' when none.
	 */
	private static function short_description( string $doc_comment ): string {
		$clean = self::remove_decorations( $doc_comment );

		if ( ! preg_match( '|^([^@][^\n]+)\n*|', $clean, $matches ) ) {
			return '';
		}

		return trim( $matches[1] );
	}

	/**
	 * Read a `@tag <value>` from a doc comment.
	 *
	 * Replicates WP-CLI's `DocParser::get_tag()` matching rules so values parse
	 * identically (lowercase, digits, dashes, underscores).
	 *
	 * @param string $doc_comment Raw doc comment.
	 * @param string $name        Tag name without the leading `@`.
	 * @return string Tag value, or '' when absent.
	 */
	private static function get_tag( string $doc_comment, string $name ): string {
		$clean = self::remove_decorations( $doc_comment );

		if ( preg_match( '|^@' . preg_quote( $name, '|' ) . '\s+([a-z-_0-9]+)|m', $clean, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Strip docblock decorations.
	 *
	 * Replicates WP-CLI's `DocParser::remove_decorations()` so short-description
	 * and tag parsing operate on the same normalized text WP-CLI sees.
	 *
	 * @param string $comment Raw doc comment.
	 * @return string
	 */
	private static function remove_decorations( string $comment ): string {
		$comment = str_replace( "\r\n", "\n", $comment );
		$comment = preg_replace( '|^/\*\*[\r\n]+|', '', $comment );
		$comment = preg_replace( '|\n[\t ]*\*/$|', '', $comment );
		$comment = preg_replace( '|^[\t ]*\* ?|m', '', $comment );

		return is_string( $comment ) ? $comment : '';
	}
}
