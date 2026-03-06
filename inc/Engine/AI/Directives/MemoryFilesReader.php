<?php
/**
 * Memory Files Reader
 *
 * Shared helper for reading agent memory files and producing directive outputs.
 * Used by both PipelineMemoryFilesDirective and FlowMemoryFilesDirective
 * to avoid duplicating the file-reading logic.
 *
 * @package DataMachine\Engine\AI\Directives
 */

namespace DataMachine\Engine\AI\Directives;

use DataMachine\Core\FilesRepository\DirectoryManager;

defined( 'ABSPATH' ) || exit;

class MemoryFilesReader {

	/**
	 * Read memory files from the agent directory and produce directive outputs.
	 *
	 * @param array  $memory_files Array of memory filenames.
	 * @param string $scope_label  Label for logging (e.g. 'Pipeline', 'Flow').
	 * @param int    $scope_id     Entity ID for logging (e.g. pipeline_id, flow_id).
	 * @param int    $user_id      WordPress user ID. 0 = legacy shared directory.
	 * @return array Array of directive outputs (type => system_text, content => ...).
	 */
	/**
	 * @since 0.37.0 Added $user_id parameter for multi-agent partitioning.
	 */
	public static function read( array $memory_files, string $scope_label, int $scope_id, int $user_id = 0 ): array {
		if ( empty( $memory_files ) ) {
			return array();
		}

		$directory_manager = new DirectoryManager();
		$user_id           = $directory_manager->get_effective_user_id( $user_id );
		$agent_dir         = $directory_manager->get_agent_identity_directory_for_user( $user_id );
		$outputs           = array();

		foreach ( $memory_files as $filename ) {
			$safe_filename = sanitize_file_name( $filename );
			$filepath      = "{$agent_dir}/{$safe_filename}";

			if ( ! file_exists( $filepath ) ) {
				do_action(
					'datamachine_log',
					'warning',
					"{$scope_label} Memory Files: File not found",
					array(
						'filename' => $safe_filename,
						'scope_id' => $scope_id,
					)
				);
				continue;
			}

			$content = file_get_contents( $filepath );
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
