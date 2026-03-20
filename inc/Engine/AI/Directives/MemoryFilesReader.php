<?php
/**
 * Memory Files Reader
 *
 * Shared helper for reading agent memory files and producing directive outputs.
 * Used by both PipelineMemoryFilesDirective and FlowMemoryFilesDirective
 * to avoid duplicating the file-reading logic.
 *
 * Resolves each file to the correct layer directory using the
 * MemoryFileRegistry. Falls back to the agent directory for
 * unregistered files (backward compatibility).
 *
 * @package DataMachine\Engine\AI\Directives
 * @since   0.37.0
 * @since   0.42.0 Layer-aware resolution via MemoryFileRegistry.
 */

namespace DataMachine\Engine\AI\Directives;

use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

class MemoryFilesReader {

	/**
	 * Read memory files and produce directive outputs.
	 *
	 * Each file is resolved to the directory matching its registered layer.
	 * Unregistered files fall back to the agent directory.
	 *
	 * @since 0.37.0 Added $user_id parameter for multi-agent partitioning.
	 * @since 0.41.0 Added $agent_id parameter for agent-first resolution.
	 * @since 0.42.0 Layer-aware resolution via MemoryFileRegistry.
	 *
	 * @param array  $memory_files Array of memory filenames.
	 * @param string $scope_label  Label for logging (e.g. 'Pipeline', 'Flow').
	 * @param int    $scope_id     Entity ID for logging (e.g. pipeline_id, flow_id).
	 * @param int    $user_id      WordPress user ID. 0 = legacy shared directory.
	 * @param int    $agent_id     Agent ID. 0 = resolve from user_id.
	 * @return array Array of directive outputs (type => system_text, content => ...).
	 */
	public static function read( array $memory_files, string $scope_label, int $scope_id, int $user_id = 0, int $agent_id = 0 ): array {
		global $wp_filesystem;
		if ( empty( $memory_files ) ) {
			return array();
		}

		// Ensure WP_Filesystem is initialized (not available by default in REST API context).
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$directory_manager = new DirectoryManager();
		$user_id           = $directory_manager->get_effective_user_id( $user_id );

		// Resolve all layer directories once.
		$layer_dirs = array(
			MemoryFileRegistry::LAYER_SHARED  => $directory_manager->get_shared_directory(),
			MemoryFileRegistry::LAYER_AGENT   => $directory_manager->resolve_agent_directory( array(
				'agent_id' => $agent_id,
				'user_id'  => $user_id,
			) ),
			MemoryFileRegistry::LAYER_USER    => $directory_manager->get_user_directory( $user_id ),
			MemoryFileRegistry::LAYER_NETWORK => $directory_manager->get_network_directory(),
		);

		$outputs = array();

		foreach ( $memory_files as $filename ) {
			$safe_filename = sanitize_file_name( $filename );

			// Resolve directory from registry layer, fall back to agent dir.
			$layer = MemoryFileRegistry::get_layer( $safe_filename );
			$dir   = $layer_dirs[ $layer ?? MemoryFileRegistry::LAYER_AGENT ];

			$filepath = "{$dir}/{$safe_filename}";

			if ( ! file_exists( $filepath ) ) {
				do_action(
					'datamachine_log',
					'warning',
					"{$scope_label} Memory Files: File not found",
					array(
						'filename' => $safe_filename,
						'scope_id' => $scope_id,
						'layer'    => $layer ?? 'agent (fallback)',
					)
				);
				continue;
			}

			$content = $wp_filesystem->get_contents( $filepath );
			if ( empty( trim( $content ) ) ) {
				continue;
			}

			$outputs[] = array(
				'type'    => 'system_text',
				'content' => "## Memory File: {$safe_filename}\n{$content}",
			);
		}

		if ( ! empty( $outputs ) ) {
			do_action(
				'datamachine_log',
				'debug',
				"{$scope_label} Memory Files: Injected memory files",
				array(
					'scope_id'   => $scope_id,
					'file_count' => count( $outputs ),
					'files'      => $memory_files,
				)
			);
		}

		return $outputs;
	}
}
