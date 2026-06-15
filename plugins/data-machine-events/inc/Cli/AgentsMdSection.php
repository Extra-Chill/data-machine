<?php
/**
 * AGENTS.md Section Generator
 *
 * Generates the "Data Machine Events CLI" section of the composed AGENTS.md
 * file by introspecting the real registered command tree instead of
 * hand-maintaining a heredoc. Walks every command registered in
 * CommandRegistry, reflects over each command class, and emits each command
 * with its real short description (and any named subcommands).
 *
 * Context-safety: the section callback runs on `datamachine_sections` /
 * `plugins_loaded` during composition, which can fire in non-CLI contexts
 * (web/cron auto-regeneration). The command classes are only wired into WP-CLI
 * under `if ( WP_CLI )`, and the plugin's `vendor/` PSR-4 autoloader is not
 * guaranteed to be present, so the live WP-CLI runner / autoloader are NOT a
 * reliable source. This generator therefore resolves each command class FILE
 * from disk (the files guard only on ABSPATH, never on WP_CLI) and reflects
 * over the class, working in any execution context.
 *
 * Shared-helper preference: the canonical introspection home is
 * `\DataMachine\Engine\AI\CliCommandIntrospector` (data-machine core). This
 * generator prefers it when present (class_exists / method_exists guard) and
 * falls back to a self-contained local reflection path otherwise. The local
 * fallback additionally handles `__invoke` leaf commands — most events CLI
 * commands are single-`__invoke` handlers, and the shared helper's `__invoke`
 * support (data-machine#2639) may not be merged yet, so the fallback never
 * blocks on it.
 *
 * @package DataMachineEvents\Cli
 */

namespace DataMachineEvents\Cli;

use ReflectionClass;
use ReflectionMethod;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reflects the registered events CLI command tree into an AGENTS.md section.
 */
class AgentsMdSection {

	/**
	 * Build the Markdown body for the Data Machine Events CLI AGENTS.md section.
	 *
	 * @param string $wp The `wp --allow-root --path=...` invocation prefix.
	 * @return string
	 */
	public static function render( $wp ) {
		self::register_autoloader();

		$lines   = array();
		$lines[] = '### Data Machine Events CLI';
		$lines[] = '';
		$lines[] = 'Event + venue data-quality, import-handler testing, and maintenance commands for the events site.';
		$lines[] = "Discover everything: `{$wp} data-machine-events --help`";
		$lines[] = '';

		foreach ( self::collect_commands() as $command => $info ) {
			if ( $info['leaf'] ) {
				// Single-__invoke leaf command: one bullet, class/method docblock summary.
				if ( '' !== $info['description'] ) {
					$lines[] = "- `{$wp} {$command}` — {$info['description']}";
				} else {
					$lines[] = "- `{$wp} {$command}`";
				}
				continue;
			}

			// Namespace command with named subcommands.
			$summary = self::summarize_subcommands( $info['subcommands'] );
			if ( '' !== $summary ) {
				$lines[] = "- `{$wp} {$command}` — {$summary}";
			} else {
				$lines[] = "- `{$wp} {$command}`";
			}

			foreach ( $info['subcommands'] as $sub ) {
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
	 * Register a minimal PSR-4 autoloader for the `DataMachineEvents\` namespace.
	 *
	 * The plugin's composer `vendor/` autoloader is not guaranteed in web/cron
	 * compose contexts, yet command classes `use` sibling traits (e.g. the
	 * Check commands share `EventQueryTrait`) and reference ability classes.
	 * This namespace-scoped autoloader resolves those dependencies from the
	 * `inc/` PSR-4 root so reflection never fatals on a missing trait. Idempotent.
	 *
	 * @return void
	 */
	private static function register_autoloader() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		// Ensure the WP-CLI base-class stub exists BEFORE the autoloader can
		// fire, so a command class that extends it (loaded via class_exists()
		// or `use` resolution) never fatals on the missing runtime base class.
		self::ensure_wp_cli_base_class();

		$inc = DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/';

		spl_autoload_register(
			function ( $class_name ) use ( $inc ) {
				$prefix = 'DataMachineEvents\\';
				$len    = strlen( $prefix );

				if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
					return;
				}

				$relative = substr( $class_name, $len );
				$file     = $inc . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}

	/**
	 * Walk the CommandRegistry and describe each command.
	 *
	 * @return array<string, array{leaf: bool, description: string, subcommands: array<int, array{name: string, description: string}>}>
	 *               command string => descriptor.
	 */
	private static function collect_commands() {
		$out = array();

		foreach ( CommandRegistry::map() as $command => $entry ) {
			$file  = isset( $entry['file'] ) ? (string) $entry['file'] : '';
			$class = isset( $entry['class'] ) ? (string) $entry['class'] : '';

			self::ensure_class_loaded( $class, $file );

			if ( '' === $class || ! class_exists( $class ) ) {
				continue;
			}

			$subcommands = self::describe_subcommands( $class );

			if ( ! empty( $subcommands ) ) {
				$out[ $command ] = array(
					'leaf'        => false,
					'description' => '',
					'subcommands' => $subcommands,
				);
				continue;
			}

			// No named subcommands: treat as a leaf __invoke command described
			// by its __invoke method docblock (falling back to the class docblock).
			$out[ $command ] = array(
				'leaf'        => true,
				'description' => self::leaf_description( $class ),
				'subcommands' => array(),
			);
		}

		return $out;
	}

	/**
	 * Describe a command class's named subcommands.
	 *
	 * Prefers the shared `CliCommandIntrospector` helper when present and it
	 * returns results; otherwise uses local reflection over the class's public,
	 * non-magic methods.
	 *
	 * @param class-string $class Command class.
	 * @return array<int, array{name: string, description: string}>
	 */
	private static function describe_subcommands( $class ) {
		if ( class_exists( '\DataMachine\Engine\AI\CliCommandIntrospector' )
			&& method_exists( '\DataMachine\Engine\AI\CliCommandIntrospector', 'describe_class' )
		) {
			$shared = \DataMachine\Engine\AI\CliCommandIntrospector::describe_class( $class );
			if ( is_array( $shared ) && ! empty( $shared ) ) {
				return $shared;
			}
			// Fall through to local reflection (e.g. __invoke leaf commands,
			// which the shared helper skips until data-machine#2639 lands).
		}

		return self::reflect_subcommands( $class );
	}

	/**
	 * Self-contained reflection over a command class's named subcommands.
	 *
	 * Public, non-static, non-magic methods are WP-CLI subcommands. The
	 * subcommand name is taken from the `@subcommand <name>` annotation when
	 * present, otherwise the method name with underscores converted to hyphens
	 * (WP-CLI's own convention). `__invoke` leaf commands intentionally yield no
	 * named subcommands here — they are rendered as a single bullet by the
	 * caller using {@see self::leaf_description()}.
	 *
	 * @param class-string $class Command class.
	 * @return array<int, array{name: string, description: string}>
	 */
	private static function reflect_subcommands( $class ) {
		try {
			$reflection = new ReflectionClass( $class );
		} catch ( \Throwable $e ) {
			return array();
		}

		$subcommands = array();

		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			// Only methods declared on the command class itself.
			if ( $method->getDeclaringClass()->getName() !== $reflection->getName() ) {
				continue;
			}

			if ( $method->isStatic() || $method->isConstructor() || $method->isDestructor() ) {
				continue;
			}

			$method_name = $method->getName();
			$raw_doc     = $method->getDocComment();
			$doc         = is_string( $raw_doc ) ? $raw_doc : '';
			$annotated   = self::parse_subcommand_annotation( $doc );

			if ( '' !== $annotated ) {
				$name = $annotated;
			} else {
				// Skip magic methods (incl. __invoke — handled as a leaf).
				if ( 0 === strpos( $method_name, '__' ) ) {
					continue;
				}
				$name = str_replace( '_', '-', $method_name );
			}

			$subcommands[] = array(
				'name'        => $name,
				'description' => self::parse_short_description( $doc ),
			);
		}

		return $subcommands;
	}

	/**
	 * Derive the short description for a leaf (`__invoke`) command.
	 *
	 * Prefers the `__invoke` method docblock (the summary WP-CLI shows for the
	 * command's `--help`), falling back to the class docblock.
	 *
	 * @param class-string $class Command class.
	 * @return string
	 */
	private static function leaf_description( $class ) {
		try {
			$reflection = new ReflectionClass( $class );
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( $reflection->hasMethod( '__invoke' ) ) {
			$invoke_doc = $reflection->getMethod( '__invoke' )->getDocComment();
			$desc       = self::parse_short_description( is_string( $invoke_doc ) ? $invoke_doc : '' );
			if ( '' !== $desc ) {
				return $desc;
			}
		}

		$class_doc = $reflection->getDocComment();
		return self::parse_short_description( is_string( $class_doc ) ? $class_doc : '' );
	}

	/**
	 * Build a comma-separated summary of subcommand names for the headline.
	 *
	 * @param array<int, array{name: string, description: string}> $subcommands Subcommands.
	 * @return string
	 */
	private static function summarize_subcommands( $subcommands ) {
		$names = array();
		foreach ( $subcommands as $sub ) {
			$names[] = $sub['name'];
		}

		return implode( ', ', $names );
	}

	/**
	 * Require a command class file from disk if the class is not yet loaded.
	 *
	 * The plugin's PSR-4 autoloader (vendor/) is not guaranteed in web/cron
	 * compose contexts, so resolve the file directly. The command files guard
	 * only on ABSPATH (never WP_CLI), so requiring them is safe in any context
	 * — except that one command class extends the WP-CLI base class
	 * `WP_CLI_Command`, which only exists when the WP-CLI runtime is loaded.
	 * Reflecting over such a class would fatally fail in web/cron context, so a
	 * minimal stub of the base class is defined first. The stub carries no
	 * behaviour and is only ever defined when the real WP-CLI runtime is
	 * absent, so it cannot interfere with actual command execution.
	 *
	 * @param string $class Fully-qualified class name.
	 * @param string $file  Absolute path to the class source file.
	 * @return void
	 */
	private static function ensure_class_loaded( $class, $file ) {
		if ( '' === $class || class_exists( $class ) ) {
			return;
		}

		if ( '' === $file || ! is_readable( $file ) ) {
			return;
		}

		// The WP-CLI base-class stub is already defined by render() via
		// register_autoloader(), so command classes that extend it can be
		// required safely here as a fallback when the PSR-4 autoloader did not
		// already resolve the class.
		require_once $file;
	}

	/**
	 * Define a minimal `\WP_CLI_Command` stub when the WP-CLI runtime is absent.
	 *
	 * Command classes that extend the WP-CLI base class cannot be loaded for
	 * reflection in non-CLI compose contexts without it. The stub carries no
	 * behaviour and is never defined when the real WP-CLI runtime is present,
	 * so it cannot affect command execution.
	 *
	 * @return void
	 */
	private static function ensure_wp_cli_base_class() {
		if ( class_exists( '\WP_CLI_Command' ) ) {
			return;
		}

		require_once __DIR__ . '/wp-cli-command-stub.php';
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
	 * non-empty content line of the docblock, before any `## SECTION` heading
	 * or `@tag`.
	 *
	 * @param string $doc Raw docblock.
	 * @return string
	 */
	private static function parse_short_description( $doc ) {
		if ( '' === $doc ) {
			return '';
		}

		// Strip the comment framing.
		$doc   = preg_replace( '#^/\*\*|\*/$#', '', $doc );
		$lines = preg_split( '/\r\n|\r|\n/', $doc );

		foreach ( $lines as $line ) {
			$line = preg_replace( '/^\s*\*\s?/', '', $line );
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			// Stop at structured sections / annotations.
			if ( 0 === strpos( $line, '##' ) || 0 === strpos( $line, '@' ) ) {
				return '';
			}

			// Drop a trailing period for a tighter inline summary.
			return rtrim( $line, '.' );
		}

		return '';
	}
}
