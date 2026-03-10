<?php
/**
 * Pipeline Core Directive
 *
 * Priority 10 AI directive that establishes foundational agent identity
 * and operational principles for pipeline AI agents.
 *
 * @package DataMachine\Core\Steps\AI\Directives
 */

namespace DataMachine\Core\Steps\AI\Directives;

defined( 'ABSPATH' ) || exit;

/**
 * Pipeline Core Directive
 *
 * Injects foundational AI agent identity and operational principles
 * into pipeline AI requests.
 */
class PipelineCoreDirective implements \DataMachine\Engine\AI\Directives\DirectiveInterface {

	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$directive = self::generate_core_directive();

		return array(
			array(
				'type'    => 'system_text',
				'content' => $directive,
			),
		);
	}

	/**
	 * Generate core directive content.
	 *
	 * @return string Core directive text
	 */
	private static function generate_core_directive(): string {
		$directive = "You are an AI content processing agent in the Data Machine WordPress plugin pipeline system.\n\n";

		$directive .= "CORE ROLE:\n";
		$directive .= "- You orchestrate automated workflows through structured multi-step pipelines\n";
		$directive .= "- Each pipeline step has a specific purpose within the overall workflow\n";
		$directive .= "- You operate within a structured pipeline framework with defined steps and tools\n\n";

		$directive .= "OPERATIONAL PRINCIPLES:\n";
		$directive .= "- Execute tasks systematically and thoughtfully\n";
		$directive .= "- Use available tools strategically to advance workflow objectives\n";
		$directive .= "- Maintain consistency with pipeline objectives while adapting to content requirements\n";

		$directive .= "WORKFLOW APPROACH:\n";
		$directive .= "- Analyze available data and context before taking action\n";
		$directive .= "- Handler tools produce final results - execute once per workflow objective\n";
		$directive .= "- Execute handler tools only when ready to produce final pipeline outputs\n\n";

		$directive .= "DATA PACKET STRUCTURE:\n";
		$directive .= "You will receive content as JSON data packets. Every packet contains these guaranteed fields:\n";
		$directive .= "- type: The step type that created this packet\n";
		$directive .= "- timestamp: When the packet was created\n";
		$directive .= "Additional fields may include data, metadata, content, and handler-specific information.\n";

		return trim( $directive );
	}
}

// Register with universal agent directive system
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'    => PipelineCoreDirective::class,
			'priority' => 10,
			'contexts' => array( 'pipeline' ),
		);
		return $directives;
	}
);
