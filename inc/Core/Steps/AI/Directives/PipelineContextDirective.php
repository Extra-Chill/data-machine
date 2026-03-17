<?php
/**
 * Pipeline Context Directive
 *
 * Operational context for pipeline AI steps. Identity comes from memory files (SOUL.md, etc.).
 *
 * @package DataMachine\Core\Steps\AI\Directives
 */

namespace DataMachine\Core\Steps\AI\Directives;

defined( 'ABSPATH' ) || exit;

/**
 * Pipeline Context Directive
 *
 * Provides pipeline execution context — workflow role, data packet structure,
 * and operational principles. Agent identity comes from memory files.
 */
class PipelineContextDirective implements \DataMachine\Engine\AI\Directives\DirectiveInterface {

	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$directive = self::generate_context_directive();

		return array(
			array(
				'type'    => 'system_text',
				'content' => $directive,
			),
		);
	}

	/**
	 * Generate pipeline context directive.
	 *
	 * @return string Directive text.
	 */
	private static function generate_context_directive(): string {
		$directive = "# Pipeline Execution Context\n\n";

		$directive .= "This is an automated pipeline step — not a chat session. You're processing data through a multi-step workflow. ";
		$directive .= "Your identity and knowledge come from your memory files above. Apply that context to the content you process.\n\n";

		$directive .= "## How Pipelines Work\n";
		$directive .= "- Each pipeline step has a specific purpose within the overall workflow\n";
		$directive .= "- Handler tools produce final results — execute once per workflow objective\n";
		$directive .= "- Analyze available data and context before taking action\n\n";

		$directive .= "## Data Packet Structure\n";
		$directive .= "You receive content as JSON data packets with these guaranteed fields:\n";
		$directive .= "- type: The step type that created this packet\n";
		$directive .= "- timestamp: When the packet was created\n";
		$directive .= "Additional fields may include data, metadata, content, and handler-specific information.\n";

		return trim( $directive );
	}
}

// Register with directive system (Priority 25, after memory files at 20)
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'    => PipelineContextDirective::class,
			'priority' => 25,
			'contexts' => array( 'pipeline' ),
		);
		return $directives;
	}
);
