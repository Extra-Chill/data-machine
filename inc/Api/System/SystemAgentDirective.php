<?php
/**
 * System Agent Directive
 *
 * Operational context for system tasks. Identity comes from memory files (SOUL.md, etc.).
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
		$directive = '# System Task Context' . "\n\n"
		. 'This is a background system task — not a chat session. Your identity and knowledge are already loaded from your memory files above. Use that context.' . "\n\n"
		. '## Task Behavior' . "\n\n"
		. '- Execute the task described in the user message below.' . "\n"
		. '- Return exactly what the task asks for — no extra commentary, no meta-discussion.' . "\n"
		. '- Apply your knowledge of this site, its voice, and its conventions from your memory files.' . "\n\n"
		. '## Session Title Generation' . "\n\n"
		. 'When asked to generate a chat session title: create a concise, descriptive title (3-6 words) capturing the discussion essence. Return ONLY the title text, under 100 characters.' . "\n\n"
		. '## GitHub Issue Creation' . "\n\n"
		. 'When using create_github_issue: include a clear title and detailed body with context, reproduction steps, and relevant log snippets. Use labels to categorize. Route to the most appropriate repo. Never create duplicates.';

		// List available repos dynamically.
		if ( class_exists( '\DataMachine\Abilities\Fetch\GitHubAbilities' ) ) {
			$repos = \DataMachine\Abilities\Fetch\GitHubAbilities::getRegisteredRepos();
			if ( ! empty( $repos ) ) {
				$directive .= "\n\n" . 'Available repositories for issue creation:' . "\n";
				foreach ( $repos as $entry ) {
					$directive .= '- ' . $entry['owner'] . '/' . $entry['repo'] . ' — ' . $entry['label'] . "\n";
				}
			}
		}

		return $directive;
	}
}

// Register with universal agent directive system (Priority 20, after chat)
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'    => SystemAgentDirective::class,
			'priority' => 20,
			'contexts' => array( 'system' ),
		);
		return $directives;
	}
);
