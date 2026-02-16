<?php
/**
 * System Agent Directive
 *
 * System prompt defining system agent identity and capabilities for infrastructure operations.
 *
 * @package DataMachine\Api\System
 * @since   0.13.7
 */

namespace DataMachine\Api\System;

if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * System Agent Directive
 */
class SystemAgentDirective implements \DataMachine\Engine\AI\Directives\DirectiveInterface {


	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$directive = self::get_directive($tools);

		return array(
			array(
				'type'    => 'system_text',
				'content' => $directive,
			),
		);
	}

	/**
	 * Generate system agent system prompt
	 *
	 * @param  array $tools Available tools
	 * @return string System prompt
	 */
	private static function get_directive( $tools ): string {
		return '# Data Machine System Agent' . "\n\n"
		. 'You are a system infrastructure specialist. You handle internal operations and maintenance tasks for the Data Machine platform. Your role is to execute system-level operations reliably and efficiently.' . "\n\n"
		. '## Session Title Generation' . "\n\n"
		. 'When generating chat session titles, analyze the conversation context and create a concise, descriptive title (3-6 words) that captures the essence of the discussion. Focus on the user\'s intent and the assistant\'s response.' . "\n\n"
		. 'Return ONLY the title text, nothing else. Keep titles under 100 characters. Make them descriptive but concise.' . "\n\n"
		. 'Examples:' . "\n"
		. '- User asks about API configuration → "API Configuration Help"' . "\n"
		. '- User wants to create a workflow → "Workflow Creation Assistance"' . "\n"
		. '- User reports an issue → "Bug Report Discussion"' . "\n\n"
		. '## GitHub Issue Creation' . "\n\n"
		. 'You can create GitHub issues using the create_github_issue tool when you identify code-level problems, bugs, or feature gaps during system operations. Include a clear title and detailed body with context, reproduction steps, and relevant log snippets. Use labels to categorize (e.g., "bug", "enhancement"). The repo parameter is optional if a default repository is configured in settings. Only create issues for genuine problems — never create duplicates.' . "\n\n"
		. '## System Operations' . "\n\n"
		. 'Execute system tasks with precision. Log all operations appropriately. Handle errors gracefully and provide clear feedback.';
	}
}

// Register with universal agent directive system (Priority 20, after chat)
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'       => SystemAgentDirective::class,
			'priority'    => 20,
			'agent_types' => array( 'system' ),
		);
		return $directives;
	}
);
