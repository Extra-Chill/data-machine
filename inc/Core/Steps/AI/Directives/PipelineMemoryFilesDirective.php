<?php
/**
 * Pipeline Memory Files Directive - Priority 25
 *
 * Injects agent memory files referenced by pipeline configuration into AI context.
 * Memory files are stored in the shared agent/ directory and selected per-pipeline.
 *
 * Priority Order in Directive System:
 * 1. Priority 10 - Plugin Core Directive (agent identity)
 * 2. Priority 20 - Agent SOUL.md (global AI behavior)
 * 3. Priority 25 - Pipeline Memory Files (THIS CLASS - agent memory references)
 * 4. Priority 30 - Pipeline System Prompt (pipeline instructions)
 * 5. Priority 40 - Tool Definitions (available tools and workflow)
 * 6. Priority 50 - Site Context (WordPress metadata)
 *
 * @package DataMachine\Core\Steps\AI\Directives
 */

namespace DataMachine\Core\Steps\AI\Directives;

use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\FilesRepository\DirectoryManager;

defined( 'ABSPATH' ) || exit;

class PipelineMemoryFilesDirective implements \DataMachine\Engine\AI\Directives\DirectiveInterface {

	/**
	 * Get directive outputs for pipeline memory files.
	 *
	 * @param string      $provider_name AI provider name.
	 * @param array       $tools         Available tools.
	 * @param string|null $step_id       Pipeline step ID.
	 * @param array       $payload       Additional payload data.
	 * @return array Directive outputs.
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		if ( empty( $step_id ) ) {
			return array();
		}

		$db_pipelines = new Pipelines();
		$step_config  = $db_pipelines->get_pipeline_step_config( $step_id );
		$pipeline_id  = $step_config['pipeline_id'] ?? null;

		if ( empty( $pipeline_id ) ) {
			return array();
		}

		$memory_files = $db_pipelines->get_pipeline_memory_files( (int) $pipeline_id );

		if ( empty( $memory_files ) ) {
			return array();
		}

		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_directory();
		$outputs           = array();

		foreach ( $memory_files as $filename ) {
			$safe_filename = sanitize_file_name( $filename );
			$filepath      = "{$agent_dir}/{$safe_filename}";

			if ( ! file_exists( $filepath ) ) {
				do_action(
					'datamachine_log',
					'warning',
					'Pipeline Memory Files: File not found',
					array(
						'filename'    => $safe_filename,
						'pipeline_id' => $pipeline_id,
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
				'Pipeline Memory Files: Injected memory files',
				array(
					'pipeline_id' => $pipeline_id,
					'file_count'  => count( $outputs ),
					'files'       => $memory_files,
				)
			);
		}

		return $outputs;
	}
}

// Register at Priority 25 — between SOUL.md (20) and pipeline system prompt (30).
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'       => PipelineMemoryFilesDirective::class,
			'priority'    => 25,
			'agent_types' => array( 'pipeline' ),
		);
		return $directives;
	}
);
