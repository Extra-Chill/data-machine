<?php
/**
 * Pure-PHP smoke test for first-read composable memory regeneration.
 *
 * Run with: php tests/core-memory-composable-first-read-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace AgentsAPI\AI\Context {
	interface WP_Agent_Context_Conflict_Resolver {
		public function resolve( array $items, array $payload ): array;
	}

	class WP_Agent_Context_Conflict_Kind {
		public const AUTHORITATIVE_FACT = 'authoritative_fact';
	}

	class WP_Agent_Context_Authority_Tier {
		public const AGENT_MEMORY = 'agent_memory';
	}

	class WP_Agent_Context_Item {
		public string $content;

		public function __construct( string $content ) {
			$this->content = $content;
		}
	}

	class WP_Agent_Default_Context_Conflict_Resolver implements WP_Agent_Context_Conflict_Resolver {
		public function resolve( array $items, array $payload ): array {
			return array();
		}
	}
}

namespace DataMachine\Core\FilesRepository {
	class DirectoryManager {
		public static function ensure_agent_files(): void {}

		public function get_effective_user_id( int $user_id ): int {
			return $user_id;
		}
	}

	class AgentMemory {
		public const MAX_FILE_SIZE = 8192;

		private string $filename;

		public function __construct( int $user_id = 0, int $agent_id = 0, string $filename = 'MEMORY.md', ?string $layer = null ) {
			$this->filename = $filename;
		}

		public function read(): object {
			$content = $GLOBALS['__core_memory_composable_files'][ $this->filename ] ?? null;

			return (object) array(
				'exists'  => null !== $content,
				'content' => (string) $content,
				'bytes'   => strlen( (string) $content ),
			);
		}
	}
}

namespace DataMachine\Abilities\File {
	class ScaffoldAbilities {
		public static function get_ability() {
			return null;
		}
	}
}

namespace DataMachine\Engine\AI\Memory {
	class MemoryPolicyResolver {
		public function resolveRegistered( array $context ): array {
			$GLOBALS['__core_memory_composable_resolver_contexts'][] = $context;
			return $GLOBALS['__core_memory_composable_registry'];
		}
	}
}

namespace DataMachine\Engine\AI {
	class MemoryFileRegistry {
		public const LAYER_AGENT = 'agent';

		public static function get( string $filename ): ?array {
			return $GLOBALS['__core_memory_composable_registry'][ $filename ] ?? null;
		}
	}

	class ComposableFileGenerator {
		public static function regenerate( string $filename, array $context = array() ): array {
			$GLOBALS['__core_memory_composable_regenerated'][] = array(
				'filename' => $filename,
				'context'  => $context,
			);
			$GLOBALS['__core_memory_composable_files'][ $filename ] = '# Site Context\n\nGenerated on first read.';

			return array( 'success' => true );
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $_hook, callable $_callback, int $_priority = 10, int $_accepted_args = 1 ): void {}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $_hook, $value ) {
			return $value;
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $_hook, ...$_args ): void {}
	}

	require_once __DIR__ . '/../inc/Engine/AI/Directives/DirectiveInterface.php';
	require_once __DIR__ . '/../inc/Engine/AI/Directives/CoreMemoryFilesDirective.php';

	$failures   = 0;
	$assertions = 0;

	function core_memory_composable_assert( bool $condition, string $message ): void {
		global $failures, $assertions;
		++$assertions;
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}

		echo "  [FAIL] {$message}\n";
		++$failures;
	}

	$GLOBALS['__core_memory_composable_files']             = array();
	$GLOBALS['__core_memory_composable_regenerated']       = array();
	$GLOBALS['__core_memory_composable_resolver_contexts'] = array();
	$GLOBALS['__core_memory_composable_registry']          = array(
		'SITE.md' => array(
			'layer'      => 'shared',
			'priority'   => 10,
			'composable' => true,
		),
	);

	$outputs = \DataMachine\Engine\AI\Directives\CoreMemoryFilesDirective::get_outputs(
		'openai',
		array(),
		null,
		array(
			'agent_modes' => array( 'pipeline' ),
			'user_id'    => 123,
			'agent_id'   => 456,
		)
	);

	core_memory_composable_assert( 1 === count( $outputs ), 'missing composable file is injected after first-read regeneration' );
	core_memory_composable_assert( false !== strpos( $outputs[0]['content'] ?? '', 'Generated on first read.' ), 'injected content comes from regenerated file' );
	core_memory_composable_assert( 1 === count( $GLOBALS['__core_memory_composable_regenerated'] ), 'composable file is regenerated once' );
	core_memory_composable_assert(
		array( 'user_id' => 123, 'agent_id' => 456 ) === $GLOBALS['__core_memory_composable_regenerated'][0]['context'],
		'regeneration receives prompt scope'
	);

	\DataMachine\Engine\AI\Directives\CoreMemoryFilesDirective::get_outputs(
		'openai',
		array(),
		null,
		array(
			'agent_modes' => array( 'intelligence' ),
			'session_id'  => 'session-1',
			'user_id'     => 123,
			'agent_id'    => 456,
		)
	);

	$custom_context = end( $GLOBALS['__core_memory_composable_resolver_contexts'] );
	core_memory_composable_assert( in_array( 'intelligence', $custom_context['modes'] ?? array(), true ), 'custom mode is preserved for memory resolution' );
	core_memory_composable_assert( ! in_array( 'chat', $custom_context['modes'] ?? array(), true ), 'custom mode does not masquerade as chat' );

	\DataMachine\Engine\AI\Directives\CoreMemoryFilesDirective::get_outputs(
		'openai',
		array(),
		null,
		array(
			'agent_modes' => array( 'system' ),
			'session_id'  => 'session-2',
			'user_id'     => 123,
			'agent_id'    => 456,
		)
	);

	$system_context = end( $GLOBALS['__core_memory_composable_resolver_contexts'] );
	core_memory_composable_assert( ! in_array( 'chat', $system_context['modes'] ?? array(), true ), 'system mode does not inherit chat memory files' );

	echo "\n{$assertions} assertions, {$failures} failures\n";
	if ( $failures > 0 ) {
		exit( 1 );
	}
}
