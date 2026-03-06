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

use DataMachine\Core\FilesRepository\AgentMemory;
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
		// Self-heal: ensure agent files exist before reading.
		DirectoryManager::ensure_agent_files();

		$directory_manager = new DirectoryManager();
		$user_id           = $directory_manager->get_effective_user_id( (int) ( $payload['user_id'] ?? 0 ) );
		$shared_dir        = $directory_manager->get_shared_directory();
		$agent_dir         = $directory_manager->get_agent_identity_directory_for_user( $user_id );
		$user_dir          = $directory_manager->get_user_directory( $user_id );
		$outputs           = array();

		$core_layer_files = array(
			// Site layer.
			array(
				'directory' => $shared_dir,
				'filename'  => 'SITE.md',
			),
			array(
				'directory' => $shared_dir,
				'filename'  => 'RULES.md',
			),

			// Agent layer.
			array(
				'directory' => $agent_dir,
				'filename'  => 'SOUL.md',
			),
			array(
				'directory' => $agent_dir,
				'filename'  => 'MEMORY.md',
			),

			// User layer.
			array(
				'directory' => $user_dir,
				'filename'  => 'USER.md',
			),
			array(
				'directory' => $user_dir,
				'filename'  => 'MEMORY.md',
			),
		);

		foreach ( $core_layer_files as $entry ) {
			$filepath = trailingslashit( $entry['directory'] ) . $entry['filename'];

			if ( ! file_exists( $filepath ) ) {
				continue;
			}

			$content = self::get_file_content_for_output( $filepath, $entry['filename'] );
			if ( null === $content ) {
				continue;
			}

			$outputs[] = array(
				'type'    => 'system_text',
				'content' => $content,
			);
		}

		// Backward-compatible extension point: custom registered memory files
		// are loaded from the agent identity layer.
		$custom_files = array_diff(
			MemoryFileRegistry::get_filenames(),
			array( 'SOUL.md', 'USER.md', 'MEMORY.md' )
		);

		foreach ( $custom_files as $filename ) {
			$filepath = trailingslashit( $agent_dir ) . $filename;

			if ( ! file_exists( $filepath ) ) {
				continue;
			}

			$content = self::get_file_content_for_output( $filepath, $filename );
			if ( null === $content ) {
				continue;
			}

			$outputs[] = array(
				'type'    => 'system_text',
				'content' => $content,
			);
		}

		return $outputs;
	}

	/**
	 * Read a memory file and return normalized directive content.
	 *
	 * @param string $filepath Full file path.
	 * @param string $filename Filename for logs.
	 * @return string|null
	 */
	private static function get_file_content_for_output( string $filepath, string $filename ): ?string {
		$file_size = filesize( $filepath );

		if ( $file_size > AgentMemory::MAX_FILE_SIZE ) {
			do_action(
				'datamachine_log',
				'warning',
				sprintf(
					'Memory file %s exceeds recommended size for context injection: %s (threshold %s)',
					$filename,
					size_format( $file_size ),
					size_format( AgentMemory::MAX_FILE_SIZE )
				),
				array(
					'filename' => $filename,
					'size'     => $file_size,
					'max'      => AgentMemory::MAX_FILE_SIZE,
				)
			);
		}

		$content = file_get_contents( $filepath );

		if ( empty( trim( $content ) ) ) {
			return null;
		}

		return trim( $content );
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
