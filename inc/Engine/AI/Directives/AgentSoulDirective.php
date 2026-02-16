<?php
/**
 * Agent Soul Directive - Priority 20
 *
 * Injects structured agent identity (soul) as the second directive
 * in the 5-tier AI directive system. Defines WHO the agent is —
 * identity, voice, rules, and context — consistently across all interactions.
 *
 * Replaces the former GlobalSystemPromptDirective. Falls back to the legacy
 * global_system_prompt setting if no structured soul sections are populated.
 *
 * Priority Order in 5-Tier System:
 * 1. Priority 10 - Plugin Core Directive
 * 2. Priority 20 - Agent Soul (THIS CLASS)
 * 3. Priority 30 - Pipeline System Prompt
 * 4. Priority 40 - Tool Definitions and Workflow Context
 * 5. Priority 50 - WordPress Site Context
 */

namespace DataMachine\Engine\AI\Directives;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\Directives\DirectiveInterface;

defined( 'ABSPATH' ) || exit;

class AgentSoulDirective implements DirectiveInterface {

	/**
	 * Section definitions: setting key => display header.
	 */
	private const SECTIONS = array(
		'identity' => 'Identity',
		'voice'    => 'Voice & Tone',
		'rules'    => 'Rules',
		'context'  => 'Context',
	);

	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$soul = PluginSettings::get( 'agent_soul', array() );

		// Compose structured soul sections.
		$parts = array();
		if ( is_array( $soul ) ) {
			foreach ( self::SECTIONS as $key => $header ) {
				$value = trim( $soul[ $key ] ?? '' );
				if ( '' !== $value ) {
					$parts[] = "## {$header}\n{$value}";
				}
			}
		}

		if ( ! empty( $parts ) ) {
			return array(
				array(
					'type'    => 'system_text',
					'content' => implode( "\n\n", $parts ),
				),
			);
		}

		// Backward compat: fall back to legacy global_system_prompt.
		$legacy = PluginSettings::get( 'global_system_prompt', '' );
		if ( ! empty( $legacy ) ) {
			return array(
				array(
					'type'    => 'system_text',
					'content' => trim( $legacy ),
				),
			);
		}

		return array();
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
