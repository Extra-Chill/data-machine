<?php
/**
 * Core Memory Files Directive - Priority 20
 *
 * Loads memory files from the MemoryFileRegistry and injects them into
 * every AI call. Files are resolved to their layer directories:
 *   shared → agents/{slug} → users/{id} → network/
 *
 * The registry is the single source of truth for which files exist,
 * what layer they belong to, and what order they load in.
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
 * @since   0.42.0 Driven entirely by MemoryFileRegistry with layer resolution.
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

		// Resolve layer directories once.
		$layer_dirs = array(
			MemoryFileRegistry::LAYER_SHARED  => $directory_manager->get_shared_directory(),
			MemoryFileRegistry::LAYER_AGENT   => $directory_manager->resolve_agent_directory( array(
				'agent_id' => (int) ( $payload['agent_id'] ?? 0 ),
				'user_id'  => $user_id,
			) ),
			MemoryFileRegistry::LAYER_USER    => $directory_manager->get_user_directory( $user_id ),
			MemoryFileRegistry::LAYER_NETWORK => $directory_manager->get_network_directory(),
		);

		// Auto-scaffold missing user-layer files (e.g. USER.md) on first chat.
		$scaffold_ability = \DataMachine\Abilities\File\ScaffoldAbilities::get_ability();
		if ( $user_id > 0 && $scaffold_ability ) {
			$scaffold_ability->execute( array(
				'layer'   => MemoryFileRegistry::LAYER_USER,
				'user_id' => $user_id,
			) );
		}

		$outputs = array();

		// Load all registered files, resolved to their layer directory.
		foreach ( MemoryFileRegistry::get_all() as $filename => $meta ) {
			$layer = $meta['layer'] ?? MemoryFileRegistry::LAYER_AGENT;
			$dir   = $layer_dirs[ $layer ] ?? $layer_dirs[ MemoryFileRegistry::LAYER_AGENT ];

			$filepath = trailingslashit( $dir ) . $filename;

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
		global $wp_filesystem;

		// Ensure WP_Filesystem is initialized (not available by default in REST API context).
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

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

		$content = $wp_filesystem->get_contents( $filepath );

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
			'class'    => CoreMemoryFilesDirective::class,
			'priority' => 20,
			'contexts' => array( 'all' ),
		);
		return $directives;
	}
);
