<?php
/**
 * Flow Memory Files Directive - Priority 45
 *
 * Injects agent memory files referenced by flow configuration into AI context.
 * Flow memory files are additive — they inject alongside pipeline memory files.
 *
 * Priority Order in Directive System:
 * 1. Priority 10 - Plugin Core Directive (agent identity)
 * 2. Priority 20 - Core Memory Files (SOUL.md, USER.md, MEMORY.md, etc.)
 * 3. Priority 40 - Pipeline Memory Files (per-pipeline selectable)
 * 4. Priority 45 - Flow Memory Files (THIS CLASS - per-flow selectable)
 * 5. Priority 50 - Pipeline System Prompt (pipeline instructions)
 * 6. Priority 60 - Pipeline Context Files
 * 7. Priority 70 - Tool Definitions (available tools and workflow)
 * 8. Priority 80 - Site Context (WordPress metadata)
 *
 * @package DataMachine\Core\Steps\AI\Directives
 */

namespace DataMachine\Core\Steps\AI\Directives;

use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Engine\AI\Directives\MemoryFilesReader;

defined( 'ABSPATH' ) || exit;

class FlowMemoryFilesDirective implements \DataMachine\Engine\AI\Directives\DirectiveInterface {

	/**
	 * Get directive outputs for flow memory files.
	 *
	 * @param string      $provider_name AI provider name.
	 * @param array       $tools         Available tools.
	 * @param string|null $step_id       Pipeline step ID.
	 * @param array       $payload       Additional payload data (contains flow_step_id).
	 * @return array Directive outputs.
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$flow_step_id = $payload['flow_step_id'] ?? null;

		if ( empty( $flow_step_id ) ) {
			return array();
		}

		// Extract flow_id from flow_step_id via the DM filter.
		$parts = apply_filters( 'datamachine_split_flow_step_id', null, $flow_step_id );
		if ( ! $parts || empty( $parts['flow_id'] ) ) {
			return array();
		}

		$flow_id = (int) $parts['flow_id'];

		$db_flows     = new Flows();
		$memory_files = $db_flows->get_flow_memory_files( $flow_id );

		$user_id  = (int) ( $payload['user_id'] ?? 0 );
		$agent_id = (int) ( $payload['agent_id'] ?? 0 );

		return MemoryFilesReader::read( $memory_files, 'Flow', $flow_id, $user_id, $agent_id );
	}
}

// Register at Priority 45 — between pipeline memory files (40) and pipeline system prompt (50).
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'    => FlowMemoryFilesDirective::class,
			'priority' => 45,
			'contexts' => array( 'pipeline' ),
		);
		return $directives;
	}
);
