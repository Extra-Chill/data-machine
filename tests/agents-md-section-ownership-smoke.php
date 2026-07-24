<?php

declare( strict_types=1 );

namespace DataMachine\Engine\AI {
	final class MemoryFileRegistry {
		public const LAYER_SHARED = 'shared';
		public const MODE_ALL = 'all';

		public static function register( string $file, int $priority, array $metadata ): void {}
	}

	final class SectionRegistry {
		public static array $sections = array();

		public static function register( string $file, string $section, int $priority, callable $callback, array $metadata ): void {
			self::$sections[ $section ] = compact( 'file', 'section', 'priority', 'callback', 'metadata' );
		}
	}
}

namespace DataMachine\Cli {
	final class CommandRegistry {
		public static function map(): array {
			return array();
		}
	}
}

namespace {
	define( 'ABSPATH', '/var/www/html/' );
	define( 'DATAMACHINE_COMPOSE_AGENTS_MD', true );

	function apply_filters( string $hook, mixed $value ): mixed {
		return $value;
	}

	function add_action( string $hook, callable $callback, int $priority = 10 ): void {}

	function is_multisite(): bool {
		return false;
	}

	require_once dirname( __DIR__ ) . '/inc/migrations/agents-md.php';

	datamachine_register_agents_md_sections();
	$sections = \DataMachine\Engine\AI\SectionRegistry::$sections;

	if ( ! isset( $sections['auto-generated-marker'], $sections['datamachine'] ) ) {
		throw new \RuntimeException( 'Data Machine-owned AGENTS.md sections were not registered.' );
	}
	if ( isset( $sections['abilities'] ) || isset( $sections['wordpress-source'] ) ) {
		throw new \RuntimeException( 'Generic WordPress guidance must be registered by wp-coding-agents.' );
	}

	fwrite( STDOUT, "agents-md section ownership smoke passed\n" );
}
