<?php
/**
 * Agent User Directive - Priority 25
 *
 * Injects the user context from USER.md in the files repository. Defines
 * WHO the agent is serving — the human behind the site, their preferences,
 * goals, and working context.
 *
 * Sits between SOUL (who the agent is) and MEMORY (what the agent knows)
 * because understanding the user is foundational to applying knowledge.
 *
 * Priority Order in Directive System:
 * 1. Priority 10 - Plugin Core Directive
 * 2. Priority 20 - Agent SOUL.md (identity)
 * 3. Priority 25 - Agent USER.md (THIS CLASS - user context)
 * 4. Priority 30 - Agent MEMORY.md (knowledge)
 * 5. Priority 40 - Pipeline Memory Files (per-pipeline selectable)
 * 6. Priority 50 - Pipeline System Prompt
 * 7. Priority 60 - Pipeline Context Files
 * 8. Priority 70 - Tool Definitions and Workflow Context
 * 9. Priority 80 - WordPress Site Context
 */

namespace DataMachine\Engine\AI\Directives;

use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Engine\AI\Directives\DirectiveInterface;

defined( 'ABSPATH' ) || exit;

class AgentUserDirective implements DirectiveInterface {

	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_directory();
		$user_path         = "{$agent_dir}/USER.md";

		if ( ! file_exists( $user_path ) ) {
			return array();
		}

		$content = file_get_contents( $user_path );

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

// Self-register (Priority 25 = user context for all AI agents).
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'       => AgentUserDirective::class,
			'priority'    => 25,
			'agent_types' => array( 'all' ),
		);
		return $directives;
	}
);
