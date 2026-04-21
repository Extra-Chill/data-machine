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
use DataMachine\Engine\AI\Memory\MemoryPolicyResolver;
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
		$agent_id          = (int) ( $payload['agent_id'] ?? 0 );

		// Auto-scaffold missing user-layer files (e.g. USER.md) on first chat.
		$scaffold_ability = \DataMachine\Abilities\File\ScaffoldAbilities::get_ability();
		if ( $user_id > 0 && $scaffold_ability ) {
			$scaffold_ability->execute(
				array(
					'layer'   => MemoryFileRegistry::LAYER_USER,
					'user_id' => $user_id,
				)
			);
		}

		$outputs = array();

		// Load registered files applicable to the current agent mode,
		// filtered through the per-agent MemoryPolicy.
		$mode       = $payload['agent_mode'] ?? '';
		$resolver   = new MemoryPolicyResolver();
		$mode_files = $resolver->resolveRegistered(
			array(
				'mode'     => $mode,
				'agent_id' => $agent_id,
			)
		);

		foreach ( $mode_files as $filename => $meta ) {
			$layer  = $meta['layer'] ?? MemoryFileRegistry::LAYER_AGENT;
			$memory = new AgentMemory( $user_id, $agent_id, $filename, $layer );
			$read   = $memory->read();

			if ( ! $read->exists ) {
				continue;
			}

			$content = self::normalize_for_injection( $read->content, $read->bytes, $filename );
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
	 * Normalize file content for context injection.
	 *
	 * Logs a size-budget warning, runs the `datamachine_memory_file_content`
	 * filter, and trims. Returns null when the content is effectively
	 * empty so callers can skip the directive entirely.
	 *
	 * @since next  Renamed from get_file_content_for_output and switched
	 *              to operate on already-read content from the store.
	 *
	 * @param string $content  Raw file content (already loaded by caller).
	 * @param int    $bytes    Content length in bytes (already known by caller).
	 * @param string $filename Filename for logs and the content filter.
	 * @return string|null
	 */
	private static function normalize_for_injection( string $content, int $bytes, string $filename ): ?string {
		if ( $bytes > AgentMemory::MAX_FILE_SIZE ) {
			do_action(
				'datamachine_log',
				'warning',
				sprintf(
					'Memory file %s exceeds recommended size for context injection: %s (threshold %s)',
					$filename,
					size_format( $bytes ),
					size_format( AgentMemory::MAX_FILE_SIZE )
				),
				array(
					'filename' => $filename,
					'size'     => $bytes,
					'max'      => AgentMemory::MAX_FILE_SIZE,
				)
			);
		}

		if ( empty( trim( $content ) ) ) {
			return null;
		}

		$content = trim( $content );

		/**
		 * Filter memory file content at read time.
		 *
		 * Allows plugins to contribute to or modify ANY memory file
		 * before it is injected into the AI context. Fires for every
		 * file read, not just composable ones.
		 *
		 * @since 0.66.0
		 *
		 * @param string     $content  File content.
		 * @param string     $filename Filename (e.g. 'SOUL.md', 'MEMORY.md').
		 * @param array|null $meta     Registry metadata, or null if unregistered.
		 */
		$content = apply_filters(
			'datamachine_memory_file_content',
			$content,
			$filename,
			MemoryFileRegistry::get( $filename )
		);

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
			'modes'    => array( 'all' ),
		);
		return $directives;
	}
);
