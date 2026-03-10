<?php
/**
 * Pipeline Memory Files Directive - Priority 40
 *
 * Injects agent memory files referenced by pipeline configuration into AI context.
 * Memory files are stored in the shared agent/ directory and selected per-pipeline.
 *
 * Priority Order in Directive System:
 * 1. Priority 10 - Plugin Core Directive (agent identity)
 * 2. Priority 20 - Core Memory Files (SOUL.md, USER.md, MEMORY.md, etc.)
 * 3. Priority 40 - Pipeline Memory Files (THIS CLASS - per-pipeline selectable)
 * 4. Priority 45 - Flow Memory Files (per-flow selectable)
 * 5. Priority 50 - Pipeline System Prompt (pipeline instructions)
 * 6. Priority 60 - Pipeline Context Files
 * 7. Priority 70 - Tool Definitions (available tools and workflow)
 * 8. Priority 80 - Site Context (WordPress metadata)
 *
 * @package DataMachine\Core\Steps\AI\Directives
 */

namespace DataMachine\Core\Steps\AI\Directives;

use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Engine\AI\Directives\MemoryFilesReader;

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
		$user_id      = (int) ( $payload['user_id'] ?? 0 );
		$agent_id     = (int) ( $payload['agent_id'] ?? 0 );

		return MemoryFilesReader::read( $memory_files, 'Pipeline', (int) $pipeline_id, $user_id, $agent_id );
	}
}

// Register at Priority 40 — between core memory files (20) and flow memory files (45).
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'    => PipelineMemoryFilesDirective::class,
			'priority' => 40,
			'contexts' => array( 'pipeline' ),
		);
		return $directives;
	}
);
