<?php
/**
 * AGENTS.md Section Generator
 *
 * Generates the "Data Machine Socials CLI" section of the composed AGENTS.md
 * file by introspecting the real registered command tree instead of
 * hand-maintaining a heredoc. Walks every command registered in
 * CommandRegistry, reflects over each command class's subcommand methods, and
 * emits each namespace with its real subcommands and their PHPDoc short
 * descriptions.
 *
 * Context-safe: the section callback runs on `datamachine_sections` /
 * `plugins_loaded` in web/cron compose contexts where the WP_CLI-guarded
 * command `require_once` block (and the live WP-CLI runner) is NOT loaded. This
 * generator resolves command class files directly from disk via CommandRegistry
 * and reflects over them, never relying on the live WP-CLI runner.
 *
 * Reflection is delegated to the shared
 * `\DataMachine\Engine\AI\CliCommandIntrospector` when present (the canonical
 * home for this logic, Extra-Chill/data-machine#2613), with a self-contained
 * local fallback so this plugin never hard-depends on an unreleased core class.
 * The local fallback additionally handles `__invoke` leaf commands regardless
 * of whether the shared helper's `__invoke` support (data-machine#2639) has
 * landed.
 *
 * @package DataMachineSocials\Cli
 */

namespace DataMachineSocials\Cli;

use ReflectionClass;
use ReflectionMethod;

defined( 'ABSPATH' ) || exit;

class AgentsMdSection {

	/**
	 * Build the Markdown body for the Data Machine Socials CLI AGENTS.md section.
	 *
	 * @param string $wp The `wp --allow-root --path=...` invocation prefix.
	 * @return string
	 */
	public static function render( $wp ) {
		$lines   = array();
		$lines[] = '### Data Machine Socials CLI';
		$lines[] = '';
		$lines[] = 'Cross-platform social media commands — read, publish, and engage across Instagram, Twitter/X, Facebook, Bluesky, Threads, Pinterest, LinkedIn, and Reddit, plus unified comments and per-item share tracking.';
		$lines[] = "Discover everything: `{$wp} datamachine-socials --help`";
		$lines[] = '';

		foreach ( self::collect_commands() as $command => $subcommands ) {
			$summary = self::summarize_subcommands( $subcommands );

			if ( '' !== $summary ) {
				$lines[] = "- `{$wp} {$command}` — {$summary}";
			} else {
				$lines[] = "- `{$wp} {$command}`";
			}

			foreach ( $subcommands as $sub ) {
				if ( '__default' === $sub['name'] ) {
					continue;
				}
				$desc = $sub['description'];
				if ( '' !== $desc ) {
					$lines[] = "  - `{$sub['name']}` — {$desc}";
				} else {
					$lines[] = "  - `{$sub['name']}`";
				}
			}
		}

		$lines[] = '';
		$lines[] = 'All commands support `--help` for full options and subcommand discovery.';

		return implode( "\n", $lines );
	}

	/**
	 * Walk the CommandRegistry and reflect each class into its subcommands.
	 *
	 * @return array<string, array<int, array{name:string, description:string}>>
	 *               command string => ordered list of subcommands.
	 */
	private static function collect_commands() {
		$out = array();

		foreach ( CommandRegistry::map() as $command => $class ) {
			self::ensure_class_loaded( $class );
			$out[ $command ] = self::reflect_subcommands( $class );
		}

		return $out;
	}

	/**
	 * Ensure a command class is loaded for reflection.
	 *
	 * Triggers the composer autoloader first (the plugin requires
	 * vendor/autoload.php unconditionally); falls back to requiring the class
	 * file directly from disk via CommandRegistry when the autoloader is not
	 * available (e.g. a minimal compose context). The class files guard only on
	 * ABSPATH, never on WP_CLI, so loading them outside WP-CLI is safe.
	 *
	 * @param class-string $class Command class.
	 * @return void
	 */
	private static function ensure_class_loaded( $class ) {
		if ( class_exists( $class ) ) {
			return;
		}

		$file = CommandRegistry::file_for_class( $class );
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Reflect a command class into its list of subcommands.
	 *
	 * Prefers the shared core introspector when available; otherwise uses the
	 * self-contained local reflection fallback. Subcommand names are normalized
	 * to WP-CLI's runtime convention (trailing underscore stripped, remaining
	 * underscores converted to hyphens) so the documented names match what an
	 * operator actually types.
	 *
	 * @param class-string $class Command class.
	 * @return array<int, array{name:string, description:string}>
	 */
	private static function reflect_subcommands( $class ) {
		if ( ! class_exists( $class ) ) {
			return array();
		}

		$shared = self::reflect_via_shared_helper( $class );
		$subs   = null !== $shared ? $shared : self::reflect_locally( $class );

		foreach ( $subs as &$sub ) {
			if ( '__default' !== $sub['name'] ) {
				$sub['name'] = self::normalize_subcommand_name( $sub['name'] );
			}
		}
		unset( $sub );

		return $subs;
	}

	/**
	 * Reflect subcommands via the shared core CliCommandIntrospector.
	 *
	 * @param class-string $class Command class.
	 * @return array<int, array{name:string, description:string}>|null Subcommands,
	 *         or null when the shared helper is unavailable.
	 */
	private static function reflect_via_shared_helper( $class ) {
		$helper = '\DataMachine\Engine\AI\CliCommandIntrospector';

		if ( ! class_exists( $helper ) || ! method_exists( $helper, 'describe_class' ) ) {
			return null;
		}

		$subs = $helper::describe_class( $class );

		if ( ! is_array( $subs ) ) {
			return null;
		}

		// The shared helper (pre data-machine#2639) does not enumerate
		// __invoke leaf commands. Detect that case and append it locally so the
		// surface is complete regardless of which core version is installed.
		if ( self::class_has_invoke_subcommand( $class ) && ! self::list_has_default( $subs ) ) {
			$subs[] = array(
				'name'        => '__default',
				'description' => '',
			);
		}

		return $subs;
	}

	/**
	 * Self-contained reflection fallback over a command class.
	 *
	 * Public, non-static, non-magic methods are WP-CLI subcommands. The
	 * subcommand name is taken from `@subcommand <name>` when present, otherwise
	 * the method name. `__invoke` (or `@subcommand __default`) represents the
	 * directly-invokable namespace itself.
	 *
	 * @param class-string $class Command class.
	 * @return array<int, array{name:string, description:string}>
	 */
	private static function reflect_locally( $class ) {
		try {
			$reflection = new ReflectionClass( $class );
		} catch ( \Throwable $e ) {
			return array();
		}

		$subcommands = array();

		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( $method->getDeclaringClass()->getName() !== $reflection->getName() ) {
				continue;
			}

			if ( $method->isStatic() || $method->isConstructor() || $method->isDestructor() ) {
				continue;
			}

			$method_name = $method->getName();
			$doc         = $method->getDocComment() ?: '';
			$annotated   = self::parse_subcommand_annotation( $doc );

			if ( '__invoke' === $method_name && '' === $annotated ) {
				$annotated = '__default';
			}

			if ( '' !== $annotated ) {
				$name = $annotated;
			} else {
				if ( 0 === strpos( $method_name, '__' ) ) {
					continue;
				}
				$name = $method_name;
			}

			$subcommands[] = array(
				'name'        => $name,
				'description' => self::parse_short_description( $doc ),
			);
		}

		return $subcommands;
	}

	/**
	 * Whether a class exposes an `__invoke` (or `@subcommand __default`) command.
	 *
	 * @param class-string $class Command class.
	 * @return bool
	 */
	private static function class_has_invoke_subcommand( $class ) {
		try {
			$reflection = new ReflectionClass( $class );
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( $reflection->hasMethod( '__invoke' ) ) {
			return true;
		}

		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( $method->getDeclaringClass()->getName() !== $reflection->getName() ) {
				continue;
			}
			if ( '__default' === self::parse_subcommand_annotation( $method->getDocComment() ?: '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a subcommand list already contains the namespace default.
	 *
	 * @param array<int, array{name:string, description:string}> $subs Subcommands.
	 * @return bool
	 */
	private static function list_has_default( array $subs ) {
		foreach ( $subs as $sub ) {
			if ( isset( $sub['name'] ) && '__default' === $sub['name'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize a raw method/subcommand name to WP-CLI's runtime convention.
	 *
	 * WP-CLI strips a single trailing underscore (so `list_` is invoked as
	 * `list`) and converts remaining underscores to hyphens (so `list_boards`
	 * is invoked as `list-boards`).
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private static function normalize_subcommand_name( $name ) {
		if ( '' !== $name && '_' === substr( $name, -1 ) ) {
			$name = substr( $name, 0, -1 );
		}

		return str_replace( '_', '-', $name );
	}

	/**
	 * Build a comma-separated summary of subcommand names for the headline.
	 *
	 * @param array<int, array{name:string, description:string}> $subcommands Subcommands.
	 * @return string
	 */
	private static function summarize_subcommands( $subcommands ) {
		$names = array();
		foreach ( $subcommands as $sub ) {
			if ( '__default' === $sub['name'] ) {
				continue;
			}
			$names[] = $sub['name'];
		}

		return implode( ', ', $names );
	}

	/**
	 * Parse the `@subcommand <name>` annotation from a docblock.
	 *
	 * @param string $doc Raw docblock.
	 * @return string Subcommand name, or '' when not annotated.
	 */
	private static function parse_subcommand_annotation( $doc ) {
		if ( preg_match( '/@subcommand\s+(\S+)/', $doc, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Parse the short description (first prose line) from a docblock.
	 *
	 * Mirrors how WP-CLI derives a command's summary for `--help`: the first
	 * non-empty content line of the docblock, before any `## SECTION` heading or
	 * `@tag` annotation.
	 *
	 * @param string $doc Raw docblock.
	 * @return string
	 */
	private static function parse_short_description( $doc ) {
		if ( '' === $doc ) {
			return '';
		}

		$doc   = preg_replace( '#^/\*\*|\*/$#', '', $doc );
		$lines = preg_split( '/\r\n|\r|\n/', $doc );

		foreach ( $lines as $line ) {
			$line = preg_replace( '/^\s*\*\s?/', '', $line );
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( 0 === strpos( $line, '##' ) || 0 === strpos( $line, '@' ) ) {
				return '';
			}

			return rtrim( $line, '.' );
		}

		return '';
	}
}
