<?php
/**
 * Agent Memory Directive - Priority 30
 *
 * Injects the agent memory from MEMORY.md in the files repository as context
 * for every AI call. Defines WHAT the agent knows — accumulated state, lessons,
 * and evolving context.
 *
 * Priority Order in Directive System:
 * 1. Priority 10 - Plugin Core Directive
 * 2. Priority 20 - Agent SOUL.md (identity)
 * 3. Priority 30 - Agent MEMORY.md (THIS CLASS - knowledge)
 * 4. Priority 40 - Pipeline Memory Files (per-pipeline selectable)
 * 5. Priority 50 - Pipeline System Prompt
 * 6. Priority 60 - Pipeline Context Files
 * 7. Priority 70 - Tool Definitions and Workflow Context
 * 8. Priority 80 - WordPress Site Context
 */

namespace DataMachine\Engine\AI\Directives;

use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Engine\AI\Directives\DirectiveInterface;

defined( 'ABSPATH' ) || exit;

class AgentMemoryDirective implements DirectiveInterface {

	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_directory();
		$memory_path       = "{$agent_dir}/MEMORY.md";

		if ( ! file_exists( $memory_path ) ) {
			return array();
		}

		$content = file_get_contents( $memory_path );

		if ( empty( trim( $content ) ) ) {
			return array();
		}

		return array(
			array(
				'type'    => 'system_text',
				'content' => trim( $content ),
			),
		);
	}
}

// Self-register (Priority 30 = agent memory/knowledge for all AI agents).
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'       => AgentMemoryDirective::class,
			'priority'    => 30,
			'agent_types' => array( 'all' ),
		);
		return $directives;
	}
);
