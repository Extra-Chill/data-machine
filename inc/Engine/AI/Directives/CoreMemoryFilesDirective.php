<?php
/**
 * Core Memory Files Directive - Priority 20
 *
 * Loads agent memory files from the MemoryFileRegistry and injects them
 * into every AI call. The registry is a pure container — this directive
 * just reads from it.
 *
 * Priority Order in Directive System:
 * 1. Priority 10 - Plugin Core Directive (agent identity)
 * 2. Priority 20 - Core Memory Files (THIS CLASS)
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
use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

class CoreMemoryFilesDirective implements DirectiveInterface {

	/**
	 * Get directive outputs for all registered memory files.
	 *
	 * @param string      $provider_name AI provider name.
	 * @param array       $tools         Available tools.
	 * @param string|null $step_id       Pipeline step ID.
	 * @param array       $payload       Additional payload data.
	 * @return array Directive outputs.
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$filenames = MemoryFileRegistry::get_filenames();

		if ( empty( $filenames ) ) {
			return array();
		}

		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_directory();
		$outputs           = array();

		foreach ( $filenames as $filename ) {
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
}

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
