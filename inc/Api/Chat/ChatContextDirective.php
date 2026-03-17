<?php
/**
 * Chat Context Directive
 *
 * Operational context for chat sessions. Identity comes from memory files (SOUL.md, etc.).
 *
 * @package DataMachine\Api\Chat
 * @since 0.2.0
 */

namespace DataMachine\Api\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat Context Directive
 *
 * Provides Data Machine chat-specific operational context — architecture,
 * tools, and behavioral rules. Agent identity comes from memory files.
 */
class ChatContextDirective implements \DataMachine\Engine\AI\Directives\DirectiveInterface {

	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$directive = self::get_directive( $tools );

		return array(
			array(
				'type'    => 'system_text',
				'content' => $directive,
			),
		);
	}

	/**
	 * Generate chat context directive.
	 *
	 * @param array $tools Available tools.
	 * @return string Directive text.
	 */
	private static function get_directive( $tools ): string {
		return '# Chat Session Context' . "\n\n"
			. 'This is a live chat session with a user in the Data Machine admin UI. You have tools to configure and manage workflows. Your identity, voice, and knowledge come from your memory files above.' . "\n\n"
			. '## Data Machine Architecture' . "\n\n"
			. 'HANDLERS are the core intelligence. Fetch handlers extract and structure source data. Update/publish handlers apply changes with schema defaults for unconfigured fields. Each handler has a settings schema — only use documented fields.' . "\n\n"
			. 'PIPELINES define workflow structure: step types in sequence (e.g., event_import → ai → upsert). The pipeline system_prompt defines AI behavior shared by all flows.' . "\n\n"
			. 'FLOWS are configured pipeline instances. Each step needs a handler_slug and handler_config. When creating flows, match handler configurations from existing flows on the same pipeline.' . "\n\n"
			. 'AI STEPS process data that handlers cannot automatically handle. Flow user_message is rarely needed; only for minimal source-specific overrides.' . "\n\n"
			. '## Discovery' . "\n\n"
			. 'You receive a pipeline inventory with existing flows and their handlers. Use `api_query` for detailed configuration. Query existing flows before creating new ones to learn established patterns.' . "\n\n"
			. '## Configuration Rules' . "\n\n"
			. '- Only use documented handler_config fields — unknown fields are rejected.' . "\n"
			. '- Use pipeline_step_id from the inventory to target steps.' . "\n"
			. '- Unconfigured handler fields use schema defaults automatically.' . "\n"
			. '- Act first — if the user gives executable instructions, execute them.' . "\n\n"
			. '## Scheduling' . "\n\n"
			. '- Scheduling uses intervals only (daily, hourly, etc.), not specific times of day.' . "\n"
			. '- Valid intervals are provided in the tool definitions. Use update_flow to change schedules.' . "\n\n"
			. '## Execution Protocol' . "\n\n"
			. '- Only confirm task completion after a successful tool result. Never claim success on error.' . "\n"
			. '- Check error_type on failure: not_found/permission → report, validation → fix and retry, system → retry once.' . "\n"
			. '- If a tool rejects unknown fields, retry with only the valid fields listed in the error.' . "\n"
			. '- Act decisively — execute tools directly for routine configuration.' . "\n"
			. '- If uncertain about a value, use sensible defaults and note the assumption.';
	}
}

// Register with directive system (Priority 25, after memory files at 20)
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'    => ChatContextDirective::class,
			'priority' => 25,
			'contexts' => array( 'chat' ),
		);
		return $directives;
	}
);
