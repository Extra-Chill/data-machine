<?php
/**
 * Agent Soul Directive - Priority 20
 *
 * Injects the agent soul from SOUL.md in the files repository as the second
 * directive in the 5-tier AI directive system. Defines WHO the agent is.
 *
 * Reads from the agent directory in the files repository. Migration from
 * database storage is handled by AgentMemoryMigration.
 *
 * Priority Order in 5-Tier System:
 * 1. Priority 10 - Plugin Core Directive
 * 2. Priority 20 - Agent Soul (THIS CLASS)
 * 3. Priority 30 - Pipeline System Prompt
 * 4. Priority 40 - Tool Definitions and Workflow Context
 * 5. Priority 50 - WordPress Site Context
 */

namespace DataMachine\Engine\AI\Directives;

use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Engine\AI\Directives\DirectiveInterface;

defined( 'ABSPATH' ) || exit;

class AgentSoulDirective implements DirectiveInterface {

	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_directory();
		$soul_path         = "{$agent_dir}/SOUL.md";

		if ( ! file_exists( $soul_path ) ) {
			return array();
		}

		$content = file_get_contents( $soul_path );

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

// Self-register (Priority 20 = agent soul for all AI agents).
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'       => AgentSoulDirective::class,
			'priority'    => 20,
			'agent_types' => array( 'all' ),
		);
		return $directives;
	}
);
