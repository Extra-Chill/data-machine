<?php
/**
 * Core Memory Files Directive - Priority 20
 *
 * Loads agent memory files from the files repository and injects them into
 * every AI call. Files are registered via the `datamachine_memory_files`
 * WordPress filter with an internal priority for ordering.
 *
 * Default files (SOUL.md, USER.md, MEMORY.md) are registered at this layer.
 * Plugins and themes can add, remove, or reorder files via the same filter.
 *
 * All memory files are equal — no special protection or core/custom distinction.
 * Default files are auto-generated on install as starting templates, but after
 * that they're just files like any other.
 *
 * Priority Order in Directive System:
 * 1. Priority 10 - Plugin Core Directive (agent identity)
 * 2. Priority 20 - Core Memory Files (THIS CLASS - SOUL, USER, MEMORY, etc.)
 * 3. Priority 40 - Pipeline Memory Files (per-pipeline selectable)
 * 4. Priority 50 - Pipeline System Prompt (pipeline instructions)
 * 5. Priority 60 - Pipeline Context Files
 * 6. Priority 70 - Tool Definitions (available tools and workflow)
 * 7. Priority 80 - Site Context (WordPress metadata)
 *
 * @package DataMachine\Engine\AI\Directives
 * @since   0.30.0
 */

namespace DataMachine\Engine\AI\Directives;

use DataMachine\Core\FilesRepository\DirectoryManager;

defined( 'ABSPATH' ) || exit;

class CoreMemoryFilesDirective implements DirectiveInterface {

	/**
	 * Get directive outputs for all registered core memory files.
	 *
	 * @param string      $provider_name AI provider name.
	 * @param array       $tools         Available tools.
	 * @param string|null $step_id       Pipeline step ID.
	 * @param array       $payload       Additional payload data.
	 * @return array Directive outputs.
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$files = self::get_registered_files();

		if ( empty( $files ) ) {
			return array();
		}

		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_directory();
		$outputs           = array();

		foreach ( $files as $entry ) {
			$filename = sanitize_file_name( $entry['file'] );
			$filepath = "{$agent_dir}/{$filename}";

			if ( ! file_exists( $filepath ) ) {
				continue;
			}

			$content = file_get_contents( $filepath );

			if ( empty( trim( $content ) ) ) {
				continue;
			}

			$outputs[] = array(
				'type'    => 'system_text',
				'content' => trim( $content ),
			);
		}

		return $outputs;
	}

	/**
	 * Get the registered core memory files, sorted by priority.
	 *
	 * @return array[] Array of ['file' => string, 'priority' => int].
	 */
	public static function get_registered_files(): array {
		/**
		 * Filter the core memory files injected into every AI call.
		 *
		 * Each entry is an associative array with:
		 * - 'file'     (string) Filename relative to the agent directory.
		 * - 'priority' (int)    Sort order. Lower numbers load first.
		 *
		 * @since 0.30.0
		 *
		 * @param array[] $files Registered memory files.
		 */
		$files = apply_filters( 'datamachine_memory_files', array() );

		// Sort by priority ascending.
		usort(
			$files,
			function ( $a, $b ) {
				return ( $a['priority'] ?? 50 ) <=> ( $b['priority'] ?? 50 );
			}
		);

		return $files;
	}
}

// Register default memory files.
add_filter(
	'datamachine_memory_files',
	function ( $files ) {
		$files[] = array(
			'file'     => 'SOUL.md',
			'priority' => 10,
		);
		$files[] = array(
			'file'     => 'USER.md',
			'priority' => 20,
		);
		$files[] = array(
			'file'     => 'MEMORY.md',
			'priority' => 30,
		);
		return $files;
	}
);

// Self-register in the directive system (Priority 20 = core memory files for all AI agents).
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'       => CoreMemoryFilesDirective::class,
			'priority'    => 20,
			'agent_types' => array( 'all' ),
		);
		return $directives;
	}
);
